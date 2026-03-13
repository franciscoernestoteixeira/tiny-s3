<?php
declare(strict_types=1);

namespace TinyS3\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for index.php via the PHP built-in HTTP server.
 *
 * setUpBeforeClass() starts `php -S localhost:<port> index.php` as a child
 * process, waits for it to accept connections, then each test makes real HTTP
 * requests through Guzzle.  tearDownAfterClass() kills the child and removes
 * the temporary storage directory.
 *
 * Every request is signed with SigV4Signer — the same HMAC chain used by the
 * bash and PowerShell validators.
 *
 * Test order matters: later tests depend on state created by earlier ones
 * (e.g. the bucket must exist before an object can be uploaded).  PHPUnit runs
 * methods in declaration order within a class, so the flow is intentional.
 */
class S3ServerTest extends TestCase
{
    // Credentials injected into the child server process via its environment.
    private const ACCESS_KEY  = 'integration-test-key';
    private const SECRET_KEY  = 'integration-test-secret';
    private const REGION      = 'us-east-1';
    private const BUCKET      = 'integration-bucket';
    private const OBJECT_KEY  = 'hello/world.txt';
    private const OBJECT_BODY = 'Tiny S3 integration test payload';

    /** @var resource|false PHP built-in server process handle */
    private static mixed $serverProcess = false;

    /** @var int TCP port the server listens on */
    private static int $port;

    /** @var string Temporary directory used as STORAGE_ROOT */
    private static string $storageDir;

    /** @var Client Guzzle client pre-configured for the test server */
    private static Client $http;

    /** @var SigV4Signer */
    private static SigV4Signer $signer;

    /** @var string Absolute path to the .env file written for this test run */
    private static string $envFilePath;

    /**
     * @var string|null Original .env content before the test run.
     *                  null means no .env existed — teardown deletes rather than restores.
     */
    private static ?string $envFileBackup = null;

    // -------------------------------------------------------------------------
    // Server lifecycle
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        static::$port       = static::findFreePort();
        static::$storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tiny_s3_integration_' . uniqid();
        mkdir(static::$storageDir, 0755, true);

        $projectRoot = dirname(__DIR__, 2); // tests/Integration → project root
        $host        = '127.0.0.1:' . static::$port;

        // Inject credentials and paths via a real .env file rather than through the
        // proc_open environment array.
        //
        // Passing environment variables via proc_open's $env parameter is unreliable on
        // Windows: depending on the PHP build and Windows version, the child PHP process
        // may not expose them through either $_ENV or getenv() — even when they arrive
        // in the process environment.  Writing a .env file is completely portable: index.php
        // always calls loadEnv() first, which reads the file and populates $_ENV directly,
        // so the values are guaranteed to be present regardless of php.ini's variables_order
        // or any OS-specific environment-passing behaviour.
        //
        // The absolute STORAGE_ROOT and LOG_FILE paths work because index.php's bootstrap
        // detects absolute paths (Windows drive letters or Unix root) and uses them as-is,
        // instead of prepending __DIR__.
        static::$envFilePath  = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        static::$envFileBackup = file_exists(static::$envFilePath)
            ? file_get_contents(static::$envFilePath)
            : null;

        file_put_contents(static::$envFilePath, implode(PHP_EOL, [
            'ACCESS_KEY='   . self::ACCESS_KEY,
            'SECRET_KEY='   . self::SECRET_KEY,
            'REGION='       . self::REGION,
            'STORAGE_ROOT=' . static::$storageDir,
            'LOG_FILE='     . static::$storageDir . DIRECTORY_SEPARATOR . 'test.log',
            'DEBUG=false',
        ]));

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // No explicit $env argument — the child server process inherits the current
        // environment (PATH, TEMP, SYSTEMROOT, etc.) and reads its credentials from
        // the .env file written above.
        static::$serverProcess = proc_open(
            "php -S {$host} index.php",
            $descriptor,
            $pipes,
            $projectRoot
        );

        static::waitForServer($host);

        static::$signer = new SigV4Signer(self::ACCESS_KEY, self::SECRET_KEY, self::REGION);

        static::$http = new Client([
            'base_uri'    => 'http://' . $host,
            'http_errors' => false,   // Return response object for 4xx/5xx, don't throw
            'timeout'     => 5,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$serverProcess !== false) {
            $status = proc_get_status(static::$serverProcess);
            if ($status['running']) {
                // proc_terminate sends SIGTERM; on Windows it calls TerminateProcess
                proc_terminate(static::$serverProcess);
            }
            proc_close(static::$serverProcess);
        }

        // Pure-PHP recursive delete — exec('rm -rf') does not exist on Windows
        static::deleteDir(static::$storageDir);

        // Restore .env to its pre-test state so the developer's own credentials
        // (if any) are not left overwritten after the test suite finishes.
        if (isset(static::$envFilePath)) {
            if (static::$envFileBackup !== null) {
                file_put_contents(static::$envFilePath, static::$envFileBackup);
            } else {
                @unlink(static::$envFilePath);
            }
        }
    }

    /**
     * Bind a TCP socket to port 0, let the OS assign a free ephemeral port,
     * then close the socket and return the port number. The brief window
     * between close and proc_open is acceptable in test environments.
     */
    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("Could not bind to find a free port: $errstr ($errno)");
        }
        $name = stream_socket_get_name($sock, false); // e.g. "127.0.0.1:51234"
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Cross-platform recursive directory removal used exclusively for test cleanup.
     * Does not use exec() or shell commands so it works on Windows, macOS, and Linux.
     */
    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? static::deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Poll until the server responds to a TCP connection or the timeout expires.
     * Any response (including 403 Forbidden) means the server is ready.
     */
    private static function waitForServer(string $host): void
    {
        [$addr, $port] = explode(':', $host);
        $timeout  = 5.0;
        $interval = 50_000; // 50 ms
        $elapsed  = 0.0;

        while ($elapsed < $timeout) {
            $sock = @fsockopen($addr, (int) $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);
                return;
            }
            usleep($interval);
            $elapsed += $interval / 1_000_000;
        }

        static::fail("PHP built-in server did not start within {$timeout}s on {$host}");
    }

    // -------------------------------------------------------------------------
    // Signed request helper
    // -------------------------------------------------------------------------

    /**
     * Send a signed request and return the response.
     *
     * @param string $method   HTTP method
     * @param string $path     URI path, e.g. /bucket or /bucket/key
     * @param string $body     Request body (empty for bodyless methods)
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function request(string $method, string $path, string $body = '')
    {
        $host    = '127.0.0.1:' . static::$port;
        $headers = static::$signer->sign($method, $path, $body, $host);

        $options = ['headers' => $headers];
        if ($body !== '') {
            $options['body'] = $body;
        }

        return static::$http->request($method, $path, $options);
    }

    // -------------------------------------------------------------------------
    // Happy-path tests (ordered: create → upload → verify → download → delete)
    // -------------------------------------------------------------------------

    public function testCreateBucketReturns200(): void
    {
        $response = $this->request('PUT', '/' . self::BUCKET);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CreateBucketResult', (string) $response->getBody());
    }

    public function testCreateDuplicateBucketReturns409(): void
    {
        $response = $this->request('PUT', '/' . self::BUCKET);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('BucketAlreadyExists', (string) $response->getBody());
    }

    public function testUploadObjectReturns200(): void
    {
        $response = $this->request('PUT', '/' . self::BUCKET . '/' . self::OBJECT_KEY, self::OBJECT_BODY);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUploadObjectResponseIncludesETag(): void
    {
        // ETag header must be present so S3 clients can verify upload integrity.
        // Re-upload to check the header (idempotent operation).
        $response = $this->request('PUT', '/' . self::BUCKET . '/' . self::OBJECT_KEY, self::OBJECT_BODY);

        $this->assertTrue($response->hasHeader('ETag'), 'ETag header is missing from PUT response');
        $etag = $response->getHeaderLine('ETag');
        // ETag must be a quoted MD5 hex string: "d41d8cd98f00b204e9800998ecf8427e"
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
    }

    public function testHeadObjectReturns200(): void
    {
        $response = $this->request('HEAD', '/' . self::BUCKET . '/' . self::OBJECT_KEY);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testListBucketReturns200(): void
    {
        $response = $this->request('GET', '/' . self::BUCKET);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('ListBucketResult', (string) $response->getBody());
    }

    public function testListBucketBodyContainsUploadedKey(): void
    {
        $response = $this->request('GET', '/' . self::BUCKET);

        $this->assertStringContainsString(self::OBJECT_KEY, (string) $response->getBody());
    }

    public function testDownloadObjectReturns200(): void
    {
        $response = $this->request('GET', '/' . self::BUCKET . '/' . self::OBJECT_KEY);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDownloadObjectBodyMatchesUploadedContent(): void
    {
        $response = $this->request('GET', '/' . self::BUCKET . '/' . self::OBJECT_KEY);

        $this->assertSame(self::OBJECT_BODY, (string) $response->getBody());
    }

    public function testDeleteObjectReturns204(): void
    {
        $response = $this->request('DELETE', '/' . self::BUCKET . '/' . self::OBJECT_KEY);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testHeadDeletedObjectReturns404(): void
    {
        // Object was deleted in testDeleteObjectReturns204 — HEAD must now return 404.
        $response = $this->request('HEAD', '/' . self::BUCKET . '/' . self::OBJECT_KEY);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteBucketReturns204(): void
    {
        $response = $this->request('DELETE', '/' . self::BUCKET);

        $this->assertSame(204, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Error / edge-case tests (each creates its own isolated state)
    // -------------------------------------------------------------------------

    public function testGetNonexistentObjectReturns404(): void
    {
        $bucket = 'error-test-bucket-' . uniqid();
        $this->request('PUT', '/' . $bucket);

        $response = $this->request('GET', '/' . $bucket . '/no-such-key.txt');
        $this->assertSame(404, $response->getStatusCode());

        $this->request('DELETE', '/' . $bucket);
    }

    public function testGetNonexistentBucketReturns404(): void
    {
        $response = $this->request('GET', '/bucket-that-does-not-exist-' . uniqid());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('NoSuchBucket', (string) $response->getBody());
    }

    public function testDeleteNonexistentBucketReturns404(): void
    {
        $response = $this->request('DELETE', '/bucket-that-does-not-exist-' . uniqid());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('NoSuchBucket', (string) $response->getBody());
    }

    public function testHeadWithNoKeyReturns400(): void
    {
        $bucket = 'head-no-key-' . uniqid();
        $this->request('PUT', '/' . $bucket);

        $response = $this->request('HEAD', '/' . $bucket);
        $this->assertSame(400, $response->getStatusCode());

        $this->request('DELETE', '/' . $bucket);
    }

    public function testUnsupportedMethodReturns405(): void
    {
        $response = $this->request('PATCH', '/any-bucket');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('MethodNotAllowed', (string) $response->getBody());
    }

    public function testInvalidSignatureReturns403(): void
    {
        $response = static::$http->request('PUT', '/unsigned-bucket', [
            'http_errors' => false,
            'headers'     => [
                'Host'                 => '127.0.0.1:' . static::$port,
                'Authorization'        => 'AWS4-HMAC-SHA256 Credential=BADKEY/20240101/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=' . str_repeat('0', 64),
                'x-amz-date'           => gmdate('Ymd\THis\Z'),
                'x-amz-content-sha256' => hash('sha256', ''),
            ],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testErrorResponseBodyIsXml(): void
    {
        $response = $this->request('GET', '/does-not-exist-' . uniqid());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $response->getBody());
        $this->assertNotFalse($xml, 'Error response body is not valid XML');
    }
}

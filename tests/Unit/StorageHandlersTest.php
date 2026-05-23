<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Direct in-process tests for successful storage handlers.
 */
class StorageHandlersTest extends TestCase
{
    private string $root;
    private string $logFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tiny_s3_storage_' . uniqid();
        mkdir($this->root, 0755, true);
        $this->logFile = $this->root . '/activities.log';
        $GLOBALS['logFile'] = $this->logFile;
        $GLOBALS['debug'] = true;
        $GLOBALS['region'] = 'us-east-1';
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'QUERY_STRING' => '',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if (function_exists('http_response_code')) {
            http_response_code(200);
        }
    }

    protected function tearDown(): void
    {
        if (function_exists('header_remove')) {
            header_remove();
        }
        if (is_dir($this->root)) {
            $this->deleteDir($this->root);
        }
        unset($GLOBALS['logFile'], $GLOBALS['debug'], $GLOBALS['region']);
        $_SERVER = [];
    }

    private function deleteDir(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        try {
            $callback();
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    public function testResolveSafePathReturnsExistingObjectPath(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'content');

        $this->assertSame(realpath($bucketDir . '/file.txt'), resolveSafePath($bucketDir, 'file.txt'));
    }

    public function testValidateUploadKeyAllowsNormalKeys(): void
    {
        validateUploadKey('folder/file.txt', 'bucket');
        validateUploadKey('folder/sub-folder/file.txt', 'bucket');

        $this->assertTrue(true);
    }

    public function testEnsureObjectDirectoryReturnsBucketForEmptyKey(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $this->assertSame($bucketDir, ensureObjectDirectory($bucketDir, '', 'bucket'));
    }

    public function testEnsureObjectDirectoryCreatesParentDirectoriesForObject(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $dir = ensureObjectDirectory($bucketDir, 'photos/2026/image.png', 'bucket');

        $this->assertSame($bucketDir . '/photos/2026', $dir);
        $this->assertDirectoryExists($dir);
    }

    public function testEnsureObjectDirectoryConvertsZeroBytePrefixObjectToDirectory(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/photos', '');

        $dir = ensureObjectDirectory($bucketDir, 'photos/image.png', 'bucket');

        $this->assertSame($bucketDir . '/photos', $dir);
        $this->assertDirectoryExists($bucketDir . '/photos');
    }

    public function testEnsureObjectDirectoryReturnsFolderMarkerPath(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $path = ensureObjectDirectory($bucketDir, 'photos/2026/', 'bucket');

        $this->assertSame($bucketDir . '/photos/2026', $path);
        $this->assertDirectoryExists($path);
    }

    public function testCreateFolderMarkerCreatesDirectoryAndReturnsNoBody(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $body = $this->capture(fn() => createFolderMarker($bucketDir, 'photos/2026/', 'bucket'));

        $this->assertSame('', $body);
        $this->assertDirectoryExists($bucketDir . '/photos/2026');
        $this->assertSame(200, http_response_code());
    }

    public function testCreateBucketCreatesDirectoryAndXmlResponse(): void
    {
        $bucketDir = $this->root . '/new-bucket';

        $body = $this->capture(fn() => createBucket($bucketDir, 'new-bucket'));

        $this->assertDirectoryExists($bucketDir);
        $this->assertSame('<CreateBucketResult><Location>/new-bucket</Location></CreateBucketResult>', $body);
        $this->assertSame(200, http_response_code());
    }

    public function testHandlePutRoutesBucketCreation(): void
    {
        $bucketDir = $this->root . '/created-by-route';

        $this->capture(fn() => handlePut('created-by-route', '', $bucketDir));

        $this->assertDirectoryExists($bucketDir);
    }

    public function testGetBucketLocationReturnsEmptyLocationForUsEast1(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        $GLOBALS['region'] = 'us-east-1';

        $body = $this->capture(fn() => getBucketLocation($bucketDir, 'bucket'));

        $this->assertSame('<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></LocationConstraint>', $body);
    }

    public function testGetBucketLocationReturnsConfiguredNonDefaultRegion(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        $GLOBALS['region'] = 'sa-east-1';

        $body = $this->capture(fn() => getBucketLocation($bucketDir, 'bucket'));

        $this->assertSame('<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">sa-east-1</LocationConstraint>', $body);
    }

    public function testGetBucketVersioningReturnsEmptyConfiguration(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $body = $this->capture(fn() => getBucketVersioning($bucketDir, 'bucket'));

        $this->assertSame('<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></VersioningConfiguration>', $body);
    }

    public function testListMultipartUploadsReturnsEmptyResult(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);

        $body = $this->capture(fn() => listMultipartUploads($bucketDir, 'bucket'));

        $this->assertStringContainsString('<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">', $body);
        $this->assertStringContainsString('<Bucket>bucket</Bucket>', $body);
        $this->assertStringContainsString('<IsTruncated>false</IsTruncated>', $body);
    }

    public function testListBucketsIgnoresFilesAndListsDirectories(): void
    {
        mkdir($this->root . '/alpha');
        mkdir($this->root . '/beta');
        file_put_contents($this->root . '/not-a-bucket.txt', 'x');

        $body = $this->capture(fn() => listBuckets($this->root));

        $this->assertStringContainsString('<Name>alpha</Name>', $body);
        $this->assertStringContainsString('<Name>beta</Name>', $body);
        $this->assertStringNotContainsString('not-a-bucket.txt', $body);
    }

    public function testListBucketsCreatesStorageRootWhenMissing(): void
    {
        $missingRoot = $this->root . '/missing-root';

        $body = $this->capture(fn() => listBuckets($missingRoot));

        $this->assertDirectoryExists($missingRoot);
        $this->assertStringContainsString('<Buckets></Buckets>', $body);
    }

    public function testListBucketReturnsV1XmlWithContentsAndCommonPrefixes(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir . '/photos', 0755, true);
        file_put_contents($bucketDir . '/root.txt', 'root');
        file_put_contents($bucketDir . '/photos/a.jpg', 'a');
        $_SERVER['QUERY_STRING'] = 'delimiter=/&max-keys=10';

        $body = $this->capture(fn() => listBucket($bucketDir, 'bucket'));

        $this->assertStringContainsString('<Name>bucket</Name>', $body);
        $this->assertStringContainsString('<Key>root.txt</Key>', $body);
        $this->assertStringContainsString('<CommonPrefixes><Prefix>photos/</Prefix></CommonPrefixes>', $body);
        $this->assertStringContainsString('<IsTruncated>false</IsTruncated>', $body);
    }

    public function testListBucketReturnsTruncatedWhenMaxKeysIsExceeded(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/a.txt', 'a');
        file_put_contents($bucketDir . '/b.txt', 'b');
        $_SERVER['QUERY_STRING'] = 'max-keys=1';

        $body = $this->capture(fn() => listBucket($bucketDir, 'bucket'));

        $this->assertStringContainsString('<MaxKeys>1</MaxKeys>', $body);
        $this->assertStringContainsString('<IsTruncated>true</IsTruncated>', $body);
    }

    public function testListBucketRoutesToV2WhenRequested(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/a.txt', 'a');
        $_SERVER['QUERY_STRING'] = 'list-type=2';

        $body = $this->capture(fn() => listBucket($bucketDir, 'bucket'));

        $this->assertStringContainsString('<KeyCount>1</KeyCount>', $body);
        $this->assertStringContainsString('<Key>a.txt</Key>', $body);
    }

    public function testListBucketV2ReturnsContentsAndCommonPrefixes(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir . '/docs/private', 0755, true);
        file_put_contents($bucketDir . '/docs/readme.txt', 'readme');
        file_put_contents($bucketDir . '/docs/private/secret.txt', 'secret');

        $body = $this->capture(fn() => listBucketV2($bucketDir, 'bucket', [
            'prefix' => 'docs/',
            'delimiter' => '/',
            'max-keys' => '10',
        ]));

        // The current implementation exposes directory folder markers as Contents.
        $this->assertStringContainsString('<KeyCount>3</KeyCount>', $body);
        $this->assertStringContainsString('<Key>docs/</Key>', $body);
        $this->assertStringContainsString('<Key>docs/readme.txt</Key>', $body);
        $this->assertStringContainsString('<CommonPrefixes><Prefix>docs/private/</Prefix></CommonPrefixes>', $body);
    }

    public function testHandleGetRoutesLocationUploadsVersioningDownloadAndList(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'content');

        $_SERVER['QUERY_STRING'] = 'location=';
        $this->assertStringContainsString('LocationConstraint', $this->capture(fn() => handleGet('', $bucketDir, 'bucket')));

        $_SERVER['QUERY_STRING'] = 'uploads=';
        $this->assertStringContainsString('ListMultipartUploadsResult', $this->capture(fn() => handleGet('', $bucketDir, 'bucket')));

        $_SERVER['QUERY_STRING'] = 'versioning=';
        $this->assertStringContainsString('VersioningConfiguration', $this->capture(fn() => handleGet('', $bucketDir, 'bucket')));

        $_SERVER['QUERY_STRING'] = '';
        $this->assertSame('content', $this->capture(fn() => handleGet('file.txt', $bucketDir, 'bucket')));
        $this->assertStringContainsString('ListBucketResult', $this->capture(fn() => handleGet('', $bucketDir, 'bucket')));
    }

    public function testDownloadObjectPrintsFileContent(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'download me');

        $this->assertSame('download me', $this->capture(fn() => downloadObject($bucketDir, 'file.txt', 'bucket')));
    }

    public function testHandleHeadReturns200ForExistingFile(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'content');

        $body = $this->capture(fn() => handleHead('file.txt', $bucketDir, 'bucket'));

        $this->assertSame('', $body);
        $this->assertSame(200, http_response_code());
    }

    public function testDeleteObjectRemovesExistingFile(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'content');

        $this->capture(fn() => deleteObject($bucketDir, 'file.txt', 'bucket'));

        $this->assertFileDoesNotExist($bucketDir . '/file.txt');
        $this->assertSame(204, http_response_code());
    }

    public function testDeleteBucketRemovesExistingBucket(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir . '/nested', 0755, true);
        file_put_contents($bucketDir . '/nested/file.txt', 'content');

        $this->capture(fn() => deleteBucket($bucketDir, 'bucket'));

        $this->assertDirectoryDoesNotExist($bucketDir);
        $this->assertSame(204, http_response_code());
    }

    public function testHandleDeleteRoutesBucketAndObjectDeletion(): void
    {
        $bucketDir = $this->root . '/bucket';
        mkdir($bucketDir);
        file_put_contents($bucketDir . '/file.txt', 'content');

        $this->capture(fn() => handleDelete('bucket', 'file.txt', $bucketDir));
        $this->assertFileDoesNotExist($bucketDir . '/file.txt');

        file_put_contents($bucketDir . '/another.txt', 'content');
        $this->capture(fn() => handleDelete('bucket', '', $bucketDir));
        $this->assertDirectoryDoesNotExist($bucketDir);
    }
}

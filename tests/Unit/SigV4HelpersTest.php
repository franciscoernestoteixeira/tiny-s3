<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for SigV4 helper functions that can be executed in-process.
 */
class SigV4HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['secretKey'] = 'test-secret';
        $GLOBALS['accessKey'] = 'test-access';
        $GLOBALS['region'] = 'us-east-1';
        $GLOBALS['debug'] = false;
        $GLOBALS['logFile'] = tempnam(sys_get_temp_dir(), 'tiny_s3_log_');
        $_SERVER = [];
        $_ENV = [];
    }

    protected function tearDown(): void
    {
        if (!empty($GLOBALS['logFile']) && file_exists($GLOBALS['logFile'])) {
            unlink($GLOBALS['logFile']);
        }
        foreach (['secretKey', 'accessKey', 'region', 'debug', 'logFile'] as $key) {
            unset($GLOBALS[$key]);
        }
        $_SERVER = [];
        $_ENV = [];
    }

    public function testParsePresignedCredentialParsesUppercaseKeys(): void
    {
        $result = parsePresignedCredential([
            'X-Amz-Credential' => 'AK123/20260523/sa-east-1/s3/aws4_request',
            'X-Amz-SignedHeaders' => 'host;x-amz-date',
            'X-Amz-Signature' => 'abcdef',
        ]);

        $this->assertSame('AK123', $result['AK']);
        $this->assertSame('20260523', $result['Date']);
        $this->assertSame('sa-east-1', $result['Region']);
        $this->assertSame('host;x-amz-date', $result['Signed']);
        $this->assertSame('abcdef', $result['Sig']);
    }

    public function testParsePresignedCredentialParsesLowercaseKeys(): void
    {
        $result = parsePresignedCredential([
            'x-amz-credential' => 'AK456/20260524/eu-west-1/s3/aws4_request',
            'x-amz-signedheaders' => 'host',
            'x-amz-signature' => '123456',
        ]);

        $this->assertSame('AK456', $result['AK']);
        $this->assertSame('20260524', $result['Date']);
        $this->assertSame('eu-west-1', $result['Region']);
        $this->assertSame('host', $result['Signed']);
        $this->assertSame('123456', $result['Sig']);
    }

    public function testParsePresignedCredentialReturnsEmptyValuesForInvalidCredential(): void
    {
        $result = parsePresignedCredential(['X-Amz-Credential' => 'invalid']);

        $this->assertSame('', $result['AK']);
        $this->assertSame('', $result['Date']);
        $this->assertSame('', $result['Region']);
        $this->assertSame('', $result['Signed']);
        $this->assertSame('', $result['Sig']);
    }

    public function testAwsPercentEncodeUsesRfc3986Rules(): void
    {
        $this->assertSame('folder%2Ffile%20name%2Bplus.txt', awsPercentEncode('folder/file name+plus.txt'));
    }

    public function testBuildCanonicalQueryStringSortsAndEncodesPairs(): void
    {
        $query = 'z=last&a=hello world&a=again&empty=&encoded=a%2Fb&plus=a+b';

        $this->assertSame(
            'a=again&a=hello%20world&empty=&encoded=a%2Fb&plus=a%20b&z=last',
            buildCanonicalQueryString($query)
        );
    }

    public function testBuildCanonicalQueryStringCanExcludeSignatureCaseInsensitively(): void
    {
        $query = 'X-Amz-Date=20260523T120000Z&X-Amz-Signature=abc&bucket=test&x-amz-signature=def';

        $this->assertSame(
            'X-Amz-Date=20260523T120000Z&bucket=test',
            buildCanonicalQueryString($query, true)
        );
    }

    public function testBuildCanonicalQueryStringIgnoresEmptyParts(): void
    {
        $this->assertSame('a=1&b=', buildCanonicalQueryString('&&b&a=1&&'));
    }

    public function testRequestHeaderReadsCommonServerVariables(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SERVER_NAME'] = 'fallback.example.com';
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $_SERVER['CONTENT_LENGTH'] = '123';
        $_SERVER['HTTP_X_AMZ_DATE'] = '20260523T120000Z';

        $this->assertSame('example.com', requestHeader('host'));
        $this->assertSame('text/plain', requestHeader('content-type'));
        $this->assertSame('123', requestHeader('content-length'));
        $this->assertSame('20260523T120000Z', requestHeader('x-amz-date'));
    }

    public function testRequestHeaderFallsBackToServerNameForHost(): void
    {
        $_SERVER['SERVER_NAME'] = 'fallback.example.com';
        $this->assertSame('fallback.example.com', requestHeader('host'));
    }

    public function testBuildCanonicalHeadersSortsNormalizesAndUsesOverrides(): void
    {
        $_SERVER['HTTP_HOST'] = 'internal:8080';
        $_SERVER['HTTP_X_AMZ_DATE'] = '  20260523T120000Z  ';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = "a\t\n b   c";

        $this->assertSame(
            "host:public.example.com\nx-amz-date:20260523T120000Z\nx-custom-header:a b c\n",
            buildCanonicalHeadersWithOverrides('x-custom-header;host;x-amz-date', ['host' => 'public.example.com'])
        );
    }

    public function testBuildCanonicalHeadersDelegatesToOverrideBuilder(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $this->assertSame("host:example.com\n", buildCanonicalHeaders('host'));
    }

    public function testNormalizeSignedHeadersLowercasesTrimsAndSorts(): void
    {
        $this->assertSame('host;x-amz-content-sha256;x-amz-date', normalizeSignedHeaders(' X-Amz-Date ; host ;x-amz-content-sha256 '));
    }

    public function testPresignedHostCandidatesCollectsProxyEnvironmentAndLoopbackAliases(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1:9000';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'https://Public.Example.com:9443, proxy.local';
        $_SERVER['HTTP_X_ORIGINAL_HOST'] = 'original.example.com';
        $_SERVER['HTTP_X_HOST'] = 'xhost.example.com';
        $_SERVER['HTTP_FORWARDED'] = 'for=192.0.2.1;proto=https;host="forwarded.example.com:443"';
        $_ENV['TINY_S3_PUBLIC_URL'] = 'https://public-url.example.com';
        $_ENV['TINY_S3_PRESIGNED_HOSTS'] = 'extra.example.com extra2.example.com:8080';

        $hosts = presignedHostCandidates();

        $this->assertContains('127.0.0.1:9000', $hosts);
        $this->assertContains('127.0.0.1', $hosts);
        $this->assertContains('localhost', $hosts);
        $this->assertContains('localhost:9000', $hosts);
        $this->assertContains('public.example.com:9443', $hosts);
        $this->assertContains('original.example.com', $hosts);
        $this->assertContains('xhost.example.com', $hosts);
        $this->assertContains('forwarded.example.com:443', $hosts);
        $this->assertContains('public-url.example.com', $hosts);
        $this->assertContains('public-url.example.com:443', $hosts);
        $this->assertContains('extra.example.com', $hosts);
        $this->assertContains('extra2.example.com:8080', $hosts);
    }

    public function testPresignedCanonicalUriCandidatesIncludesProxyPrefixes(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/proxy/s3/';
        $_ENV['TINY_S3_PUBLIC_PATH_PREFIX'] = 'public-prefix';

        $this->assertSame(
            ['/bucket/key.txt', '/proxy/s3/bucket/key.txt', '/public-prefix/bucket/key.txt'],
            presignedCanonicalUriCandidates('/bucket/key.txt')
        );
    }

    public function testPresignedCanonicalUriCandidatesHandlesRootPath(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/proxy';

        $this->assertSame(['/', '/proxy'], presignedCanonicalUriCandidates('/'));
    }
}

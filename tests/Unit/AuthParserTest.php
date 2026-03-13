<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for parseAuthorization() — Section 4 of index.php.
 *
 * The AWS Signature V4 Authorization header format:
 *   AWS4-HMAC-SHA256
 *   Credential=<AK>/<YYYYMMDD>/<region>/s3/aws4_request,
 *   SignedHeaders=host;x-amz-content-sha256;x-amz-date,
 *   Signature=<64-hex-chars>
 */
class AuthParserTest extends TestCase
{
    private const SAMPLE_HEADER =
        'AWS4-HMAC-SHA256 ' .
        'Credential=AKIAIOSFODNN7EXAMPLE/20240115/us-east-1/s3/aws4_request, ' .
        'SignedHeaders=host;x-amz-content-sha256;x-amz-date, ' .
        'Signature=b94d27b9934d3e08a52e52d7da7dabfac484efe04294e576f9e7d26d576e1b';

    public function testParsesAccessKeyId(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $result['AK']);
    }

    public function testParsesShortDate(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertSame('20240115', $result['Date']);
    }

    public function testParsesRegion(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertSame('us-east-1', $result['Region']);
    }

    public function testParsesSignedHeaders(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertSame('host;x-amz-content-sha256;x-amz-date', $result['Signed']);
    }

    public function testParsesSignature(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertSame(
            'b94d27b9934d3e08a52e52d7da7dabfac484efe04294e576f9e7d26d576e1b',
            $result['Sig']
        );
    }

    public function testReturnsEmptyStringsOnInvalidHeader(): void
    {
        $result = parseAuthorization('not-a-valid-authorization-header');
        $this->assertSame('', $result['AK']);
        $this->assertSame('', $result['Date']);
        $this->assertSame('', $result['Region']);
        $this->assertSame('', $result['Signed']);
        $this->assertSame('', $result['Sig']);
    }

    public function testReturnsEmptyStringsOnEmptyHeader(): void
    {
        $result = parseAuthorization('');
        foreach (['AK', 'Date', 'Region', 'Signed', 'Sig'] as $key) {
            $this->assertSame('', $result[$key], "Expected empty string for key '$key'");
        }
    }

    public function testParsesRegionContainingHyphens(): void
    {
        $header =
            'AWS4-HMAC-SHA256 ' .
            'Credential=TESTKEY/20240101/eu-central-1/s3/aws4_request, ' .
            'SignedHeaders=host, ' .
            'Signature=aabbcc';

        $result = parseAuthorization($header);
        $this->assertSame('eu-central-1', $result['Region']);
    }

    public function testReturnedArrayHasExactlyFiveKeys(): void
    {
        $result = parseAuthorization(self::SAMPLE_HEADER);
        $this->assertCount(5, $result);
        $this->assertArrayHasKey('AK',     $result);
        $this->assertArrayHasKey('Date',   $result);
        $this->assertArrayHasKey('Region', $result);
        $this->assertArrayHasKey('Signed', $result);
        $this->assertArrayHasKey('Sig',    $result);
    }
}

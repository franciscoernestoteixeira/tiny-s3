<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for getSigningKey() — Section 4 of index.php.
 *
 * The signing key is derived by chaining four HMAC-SHA256 steps:
 *   kDate    = HMAC("AWS4" + secretKey, date)
 *   kRegion  = HMAC(kDate,    region)
 *   kService = HMAC(kRegion,  service)
 *   kSigning = HMAC(kService, "aws4_request")
 *
 * The test vectors are computed from known inputs and verified against the
 * same HMAC chain coded directly (not via getSigningKey), so that if the
 * function's implementation drifts, the test will catch it.
 */
class SigningKeyTest extends TestCase
{
    private const SECRET  = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const DATE    = '20240115';
    private const REGION  = 'us-east-1';
    private const SERVICE = 's3';

    protected function setUp(): void
    {
        // getSigningKey() reads $secretKey via the global keyword.
        $GLOBALS['secretKey'] = self::SECRET;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['secretKey']);
    }

    /**
     * Reference implementation of the HMAC key derivation chain.
     * Used to independently compute the expected value.
     */
    private function deriveSigningKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $date,          "AWS4{$secret}", true);
        $kRegion  = hash_hmac('sha256', $region,        $kDate,          true);
        $kService = hash_hmac('sha256', $service,       $kRegion,        true);
        return      hash_hmac('sha256', 'aws4_request', $kService,       true);
    }

    public function testSigningKeyMatchesReferenceDerivation(): void
    {
        $expected = $this->deriveSigningKey(self::SECRET, self::DATE, self::REGION, self::SERVICE);
        $actual   = getSigningKey(self::DATE, self::REGION, self::SERVICE);
        $this->assertSame($expected, $actual);
    }

    public function testSigningKeyIsRawBinaryNotHex(): void
    {
        // The function is documented to return raw binary (suitable for
        // a final hash_hmac() call). A raw SHA-256 key is always 32 bytes.
        $key = getSigningKey(self::DATE, self::REGION, self::SERVICE);
        $this->assertSame(32, strlen($key));
    }

    public function testDifferentDatesProduceDifferentKeys(): void
    {
        $key1 = getSigningKey('20240115', self::REGION, self::SERVICE);
        $key2 = getSigningKey('20240116', self::REGION, self::SERVICE);
        $this->assertNotSame($key1, $key2);
    }

    public function testDifferentRegionsProduceDifferentKeys(): void
    {
        $key1 = getSigningKey(self::DATE, 'us-east-1',   self::SERVICE);
        $key2 = getSigningKey(self::DATE, 'eu-central-1', self::SERVICE);
        $this->assertNotSame($key1, $key2);
    }

    public function testSigningKeyChangesWhenSecretChanges(): void
    {
        $GLOBALS['secretKey'] = self::SECRET;
        $key1 = getSigningKey(self::DATE, self::REGION, self::SERVICE);

        $GLOBALS['secretKey'] = 'completely-different-secret';
        $key2 = getSigningKey(self::DATE, self::REGION, self::SERVICE);

        $this->assertNotSame($key1, $key2);
    }

    public function testSigningKeyCanBeUsedDirectlyInHashHmac(): void
    {
        // Verify the binary key produces a valid final hex signature.
        $signingKey = getSigningKey(self::DATE, self::REGION, self::SERVICE);
        $signature  = hash_hmac('sha256', 'AWS4-HMAC-SHA256\ntest', $signingKey);

        // A valid HMAC-SHA256 hex string is exactly 64 lowercase hex characters.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }
}

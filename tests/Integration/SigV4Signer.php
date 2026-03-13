<?php
declare(strict_types=1);

namespace TinyS3\Tests\Integration;

/**
 * AWS Signature V4 signer for use in integration tests.
 *
 * Implements the same four-step HMAC chain as index.php's getSigningKey() and
 * the equivalent logic in test.sh / test.ps1, so integration tests produce
 * requests that the server will accept as correctly signed.
 *
 * Usage:
 *   $signer = new SigV4Signer('my-key', 'my-secret');
 *   $headers = $signer->sign('PUT', '/my-bucket', 'request body');
 *   // Pass $headers to Guzzle: ['headers' => $headers]
 */
class SigV4Signer
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region  = 'us-east-1',
        private readonly string $service = 's3',
    ) {}

    /**
     * Build and return the HTTP headers required for a signed AWS V4 request.
     *
     * @param string $method   HTTP method (GET, PUT, HEAD, DELETE)
     * @param string $uriPath  Request path, e.g. /bucket or /bucket/key
     * @param string $body     Raw request body (empty string for bodyless requests)
     * @param string $host     Host header value, e.g. "localhost:18083"
     * @return array<string,string>  Headers to add to the Guzzle request
     */
    public function sign(string $method, string $uriPath, string $body = '', string $host = ''): array
    {
        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // Hash the body — empty body always hashes to the well-known empty-string SHA-256
        $payloadHash = hash('sha256', $body);

        // --- Step 1: Canonical request ---
        // Headers are sorted alphabetically. We sign host, x-amz-content-sha256, x-amz-date.
        $canonicalHeaders =
            "host:{$host}\n" .
            "x-amz-content-sha256:{$payloadHash}\n" .
            "x-amz-date:{$amzDate}\n";   // trailing newline is part of the canonical form

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $uriPath,
            '',                 // empty canonical query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // --- Step 2: String to sign ---
        $credentialScope   = "{$dateStamp}/{$this->region}/{$this->service}/aws4_request";
        $canonicalReqHash  = hash('sha256', $canonicalRequest);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            $canonicalReqHash,
        ]);

        // --- Step 3: Signing key (four-step HMAC chain) ---
        $kSecret  = "AWS4{$this->secretKey}";
        $kDate    = hash_hmac('sha256', $dateStamp,       $kSecret,  true);
        $kRegion  = hash_hmac('sha256', $this->region,    $kDate,    true);
        $kService = hash_hmac('sha256', $this->service,   $kRegion,  true);
        $kSigning = hash_hmac('sha256', 'aws4_request',   $kService, true);

        // --- Step 4: Signature ---
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // --- Step 5: Authorization header ---
        $authHeader =
            "AWS4-HMAC-SHA256 " .
            "Credential={$this->accessKey}/{$credentialScope}, " .
            "SignedHeaders={$signedHeaders}, " .
            "Signature={$signature}";

        return [
            'Host'                  => $host,
            'x-amz-date'            => $amzDate,
            'x-amz-content-sha256'  => $payloadHash,
            'Authorization'         => $authHeader,
        ];
    }
}

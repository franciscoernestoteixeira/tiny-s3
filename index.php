<?php
declare(strict_types=1);

// ================================================================================================
//  Tiny S3 — A minimal AWS S3-compatible storage server written in pure PHP
//
//  Implements AWS Signature V4 authentication and handles the core S3 operations:
//    PUT    /bucket              → Create bucket
//    PUT    /bucket/key          → Upload object (normal or AWS chunked)
//    GET    /bucket              → List all objects in bucket
//    GET    /bucket/key          → Download object
//    HEAD   /bucket/key          → Check object existence
//    DELETE /bucket              → Delete bucket (recursive)
//    DELETE /bucket/key          → Delete object
//
//  All objects are stored as plain files on the local filesystem under STORAGE_ROOT.
// ================================================================================================


// ================================================================================================
// SECTION 1 — ENVIRONMENT
// Loads .env, reads config variables, and wires up the fatal exception handler.
// ================================================================================================

/**
 * Load environment variables from a .env file.
 * Supports flexible spacing around `=`, ignores blank lines and `#` comments.
 */
function loadEnv(string $envPath): void
{
    if (!file_exists($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue; // Skip comment lines and lines without an `=`
        }

        [$key, $value] = preg_split('/\s*=\s*/', $trimmed, 2);

        if ($key !== null && $value !== null) {
            $_ENV[trim($key)] = trim($value);
        }
    }
}

/**
 * Convert a .env string value ("true", "false", "1", "0", "yes", "no") to a boolean.
 * Using a string parameter avoids a TypeError under `declare(strict_types=1)`.
 */
function envToBool(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}


// ================================================================================================
// SECTION 2 — LOGGING
//
// Four severity levels with independent write rules:
//
//   ERROR  — always written. Any failure that caused a request to be rejected or an
//             operation to not complete: auth failure, filesystem error, path traversal,
//             unhandled exception, invalid input that aborts processing.
//
//   WARN   — always written. Abnormal conditions that did not hard-fail the request but
//             are worth investigating: unrecognised chunk header, unexpected EOF mid-upload.
//
//   INFO   — written only when DEBUG=true. Normal operation traces useful during
//             development: object saved, bucket created/deleted, request routed.
//
//   DEBUG  — written only when DEBUG=true. Verbose internal state for tracing the
//             signature pipeline: raw headers, canonical request, string-to-sign,
//             computed vs received signature.
//
// The key rule: ERROR and WARN are never gated by $debug — they always reach the log
// file so that production systems always have a record of failures and anomalies.
// ================================================================================================

/**
 * Return a compact request-context tag for use in log lines.
 *
 * Included automatically by writeLog() on ERROR and WARN entries so that every
 * failure record carries enough information to identify and reproduce the request
 * without needing DEBUG mode or a separate access log.
 *
 * Format: [<ip> <METHOD> <URI>]
 * Example: [203.0.113.42 PUT /my-bucket/uploads/photo.jpg]
 *
 * X-Forwarded-For is preferred over REMOTE_ADDR so that the real client IP is
 * recorded when the server sits behind a reverse proxy or load balancer.
 */
function requestContext(): string
{
    $ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $method = $_SERVER['REQUEST_METHOD']        ?? '-';
    $uri    = $_SERVER['REQUEST_URI']           ?? '-';

    return "[{$ip} {$method} {$uri}]";
}

/**
 * Write a timestamped log entry to the log file.
 *
 * ERROR and WARN are always persisted regardless of the DEBUG setting.
 * INFO  and DEBUG are only persisted when DEBUG=true.
 *
 * ERROR and WARN lines are automatically prefixed with requestContext() so that
 * every failure record carries the client IP, HTTP method, and URI — no call-site
 * changes are needed, and future error/warn calls get the context for free.
 *
 * The log directory is created automatically on first write if it does not yet exist,
 * so paths like `logs/2024/activities.log` work without any manual directory setup.
 *
 * @param 'ERROR'|'WARN'|'INFO'|'DEBUG' $level   Severity level
 * @param string                         $message Log message text
 */
function writeLog(string $level, string $message): void
{
    global $debug, $logFile;

    $alwaysLog = ($level === 'ERROR' || $level === 'WARN');

    if (!$alwaysLog && !$debug) {
        return;
    }

    $context = $alwaysLog ? ' ' . requestContext() : '';
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . ']' . $context . ' ' . $message . PHP_EOL;

    // Guard: $logFile may be empty if the globals were never initialised (e.g. a
    // function called before the bootstrap ran, or a silent loadEnv() failure).
    // Fall back to PHP's error_log() so the message is never silently discarded.
    if (!$logFile) {
        error_log('[tiny-s3] ' . trim($line));
        return;
    }

    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    if (file_put_contents($logFile, $line, FILE_APPEND) === false) {
        // Write to PHP's error log so the failure is visible in the server console
        // or web-server error log — never silently drop a log entry.
        error_log('[tiny-s3] Cannot write to log file: ' . $logFile);
        error_log('[tiny-s3] ' . trim($line));
    }
}


// ================================================================================================
// SECTION 3 — XML HELPERS & ERROR RESPONSE
// ================================================================================================

/**
 * Wrap content in a single XML element, properly escaping special characters.
 * Example: xmlElement('Key', 'my/path.txt') → <Key>my/path.txt</Key>
 */
function xmlElement(string $tag, string $content): string
{
    return "<{$tag}>" . htmlspecialchars($content, ENT_XML1) . "</{$tag}>";
}

/**
 * Emit an S3-compatible XML error response, log it as ERROR, and stop execution.
 *
 * All error responses use <e> as the root tag — AWS S3 clients always expect this.
 * Routing every error through this one function keeps the format consistent.
 *
 * HEAD responses must never include a body (RFC 9110 §9.3.2). Sending one causes
 * some HTTP clients (including the AWS SDK via Guzzle) to misparse the response
 * and throw an unexpected exception rather than handling the status code cleanly.
 * The body is therefore suppressed when the current method is HEAD.
 *
 * @param int    $httpCode  HTTP status code (e.g. 403, 404, 500)
 * @param string $code      S3 error code string (e.g. "NoSuchKey")
 * @param string $message   Human-readable description
 */
function sendError(int $httpCode, string $code, string $message): never
{
    http_response_code($httpCode);
    header('Content-Type: application/xml');

    // HEAD responses must not carry a body — suppress it for HTTP compliance.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo "<e>" . xmlElement('Code', $code) . xmlElement('Message', $message) . "</e>";
    }

    writeLog('ERROR', "HTTP $httpCode [$code] $message");
    exit;
}


/**
 * Emit an explicit NotImplemented response for S3 features outside Tiny S3's
 * intentionally small compatibility surface.
 *
 * This is the user-facing notification mechanism for unsupported behaviour: the
 * caller receives a 501 response, an S3-style XML error body, and response
 * headers indicating what was rejected and which operations are currently
 * supported.  It is more useful than silently ignoring an unsupported query
 * parameter such as ?uploads or returning a generic 404.
 */
function sendNotImplemented(string $feature): never
{
    header('X-Tiny-S3-Not-Implemented: ' . $feature);
    header('X-Tiny-S3-Supported-Operations: PUT bucket, PUT object, GET bucket, GET object, HEAD object, DELETE bucket, DELETE object, presigned GET/HEAD/PUT/DELETE');

    sendError(
        501,
        'NotImplemented',
        "Tiny S3 does not implement: {$feature}. Supported operations: create bucket, upload object, list bucket, download object, head object, delete object, delete bucket, and AWS Signature V4 presigned URLs."
    );
}


// ================================================================================================
// SECTION 4 — IP ALLOWLIST
//
// Optional network-level access control evaluated before any cryptographic check.
// Rejecting blocked IPs here avoids wasting CPU on signature computation.
//
// Rules are read from the ALLOWED_IPS environment variable as a comma- or
// space-separated list of entries. Each entry may be:
//
//   *                 — wildcard; allow any IP (same as leaving the variable empty)
//   203.0.113.42      — exact IPv4 address
//   2001:db8::1       — exact IPv6 address
//   203.0.113.0/24    — IPv4 CIDR block
//   2001:db8::/32     — IPv6 CIDR block
//
// Multiple entries are OR-ed: access is granted if any single entry matches.
// An empty or wildcard value disables the allowlist entirely.
//
// REMOTE_ADDR is used for the comparison — not X-Forwarded-For — because
// X-Forwarded-For is a client-supplied header and can be trivially spoofed
// without strict reverse-proxy trust configuration. If Tiny S3 sits behind a
// trusted proxy, configure the proxy to overwrite REMOTE_ADDR with the real
// client IP (both Nginx and Apache support this via realip / remoteip modules)
// rather than relying on X-Forwarded-For for security decisions.
// ================================================================================================

/**
 * Parse the ALLOWED_IPS env string into a clean array of rules.
 *
 * Splits on any combination of commas and whitespace.
 * Returns an empty array when the raw value is empty or the single-entry wildcard "*",
 * which the caller treats as "open to all".
 *
 * @return string[]
 */
function parseAllowedIps(string $raw): array
{
    $trimmed = trim($raw);

    if ($trimmed === '' || $trimmed === '*') {
        return [];
    }

    return array_values(
        array_filter(
            array_map('trim', preg_split('/[\s,]+/', $trimmed))
        )
    );
}

/**
 * Test whether $ip falls within the CIDR range $cidr.
 *
 * Works for both IPv4 (uses ip2long bitmask arithmetic) and
 * IPv6 (uses inet_pton byte-level comparison).
 * A plain IP with no prefix (no "/") is treated as an exact match.
 */
function cidrMatch(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }

    [$subnet, $prefixStr] = explode('/', $cidr, 2);
    $prefix = (int) $prefixStr;

    // IPv4
    if (str_contains($subnet, '.')) {
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $prefix < 0 || $prefix > 32) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // IPv6
    $ipBin     = inet_pton($ip);
    $subnetBin = inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false || $prefix < 0 || $prefix > 128) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainder = $prefix % 8;

    // Compare all fully-covered bytes
    if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }

    // Compare the partial byte (if prefix is not on a byte boundary)
    if ($remainder === 0) {
        return true;
    }

    $mask = 0xFF & (0xFF << (8 - $remainder));

    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
}

/**
 * Return true if $ip is a loopback address (IPv4 127.x.x.x or IPv6 ::1).
 *
 * Loopback addresses can only originate from the same server process — they are
 * always trusted regardless of the ALLOWED_IPS list.  This matters when the S3
 * client (e.g. a Laravel app) runs on the same machine as Tiny S3: the outbound
 * request will carry REMOTE_ADDR = 127.0.0.1 even if the configured ALLOWED_IPS
 * only lists the server's public IP.
 */
function isLoopback(string $ip): bool
{
    // IPv6 loopback
    if ($ip === '::1') {
        return true;
    }
    // Full IPv4 loopback range: 127.0.0.0/8
    $long = ip2long($ip);
    return $long !== false && ($long & 0xFF000000) === 0x7F000000;
}

/**
 * Return true if $clientIp is permitted by the allowlist rules.
 *
 * An empty $rules array means "open to all" (allowlist disabled).
 * Otherwise, access is granted when any single rule matches.
 *
 * @param string[] $rules Parsed allowlist rules from parseAllowedIps()
 */
function isIpAllowed(string $clientIp, array $rules): bool
{
    if (empty($rules)) {
        return true;
    }

    foreach ($rules as $rule) {
        if (cidrMatch($clientIp, $rule)) {
            return true;
        }
    }

    return false;
}

/**
 * Enforce the IP allowlist.
 *
 * Reads the parsed $allowedIps global, resolves the real client IP from REMOTE_ADDR,
 * and calls sendError(403) if the IP is not on the list.
 * Called at the very top of the request pipeline, before signature verification.
 *
 * Loopback addresses (127.x.x.x, ::1) are always allowed — they can only originate
 * from the same server.  This is essential when the S3 client and Tiny S3 share a
 * host: the internal HTTP request carries REMOTE_ADDR = 127.0.0.1, not the server's
 * public IP, so the allowlist would otherwise silently block legitimate local calls.
 */
function checkIpAllowlist(): void
{
    $rules    = parseAllowedIps($GLOBALS['allowedIps']);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (empty($rules)) {
        return; // Allowlist disabled — open to all
    }

    // Loopback is always trusted — it cannot arrive from outside the server.
    if (isLoopback($clientIp)) {
        writeLog('DEBUG', "IP allowlist — loopback passthrough: '$clientIp'");
        return;
    }

    if (!isIpAllowed($clientIp, $rules)) {
        writeLog('ERROR', "IP not in allowlist — client: '$clientIp'");
        sendError(403, 'AccessDenied', 'Your IP address is not allowed');
    }
}


// ================================================================================================
// SECTION 5 — AWS SIGNATURE V4
// ================================================================================================

/**
 * Parse the Authorization header from an AWS Signature V4 request.
 * Returns an array with keys: AK (access key), Date, Region, Signed (headers), Sig (signature).
 */
function parseAuthorization(string $header): array
{
    preg_match('/Credential=([^\/]+)\/([\d]{8})\/([^\/]+)\/s3\/aws4_request/', $header, $c);
    preg_match('/SignedHeaders=([^,]+)/', $header, $s);
    preg_match('/Signature=([0-9a-f]+)/', $header, $sig);

    return [
        'AK'     => $c[1]   ?? '',  // Access key ID
        'Date'   => $c[2]   ?? '',  // Short date (yyyymmdd)
        'Region' => $c[3]   ?? '',  // Region from the credential scope
        'Signed' => $s[1]   ?? '',  // Semicolon-separated signed header names
        'Sig'    => $sig[1] ?? '',  // Provided HMAC-SHA256 signature
    ];
}

/**
 * Parse the X-Amz-Credential query value used by AWS SigV4 presigned URLs.
 *
 * The value arrives URL-decoded from parse_str(), for example:
 *   access-key/20260523/us-east-1/s3/aws4_request
 *
 * @return array{AK:string,Date:string,Region:string,Signed:string,Sig:string}
 */
function parsePresignedCredential(array $query): array
{
    $credential = (string)($query['X-Amz-Credential'] ?? $query['x-amz-credential'] ?? '');
    preg_match('/^([^\/]+)\/([\d]{8})\/([^\/]+)\/s3\/aws4_request$/', $credential, $c);

    return [
        'AK'     => $c[1] ?? '',
        'Date'   => $c[2] ?? '',
        'Region' => $c[3] ?? '',
        'Signed' => (string)($query['X-Amz-SignedHeaders'] ?? $query['x-amz-signedheaders'] ?? ''),
        'Sig'    => (string)($query['X-Amz-Signature'] ?? $query['x-amz-signature'] ?? ''),
    ];
}

/**
 * Derive the AWS V4 signing key through the four-step HMAC chain:
 *   HMAC(HMAC(HMAC(HMAC("AWS4" + secret, date), region), service), "aws4_request")
 *
 * Returns a raw binary key suitable for a final hash_hmac() call.
 */
function getSigningKey(string $date, string $region, string $service): string
{
    global $secretKey;

    $kDate    = hash_hmac('sha256', $date,         "AWS4{$secretKey}", true);
    $kRegion  = hash_hmac('sha256', $region,       $kDate,             true);
    $kService = hash_hmac('sha256', $service,      $kRegion,           true);

    return    hash_hmac('sha256', 'aws4_request',  $kService,          true);
}

/**
 * Percent-encode according to AWS SigV4 query-string rules.
 * PHP's rawurlencode() already uses RFC 3986 rules, including %20 for spaces.
 */
function awsPercentEncode(string $value): string
{
    return rawurlencode($value);
}

/**
 * Build a canonical query string for AWS SigV4.
 *
 * This parser preserves repeated query parameters and excludes X-Amz-Signature
 * when validating presigned URLs. parse_str() cannot be used here because it
 * collapses repeated parameter names and changes their canonical representation.
 */
function buildCanonicalQueryString(string $rawQuery, bool $excludeSignature = false): string
{
    if ($rawQuery === '') {
        return '';
    }

    $pairs = [];

    foreach (explode('&', $rawQuery) as $part) {
        if ($part === '') {
            continue;
        }

        [$rawName, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
        $name  = rawurldecode(str_replace('+', ' ', $rawName));
        $value = rawurldecode(str_replace('+', ' ', $rawValue));

        if ($excludeSignature && strcasecmp($name, 'X-Amz-Signature') === 0) {
            continue;
        }

        $pairs[] = [awsPercentEncode($name), awsPercentEncode($value)];
    }

    usort($pairs, static function (array $a, array $b): int {
        return $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]);
    });

    return implode('&', array_map(static fn(array $p): string => $p[0] . '=' . $p[1], $pairs));
}

/**
 * Read a request header by its lowercase HTTP name, accounting for PHP's SAPI variables.
 */
function requestHeader(string $headerName): string
{
    $headerName = strtolower($headerName);

    return match ($headerName) {
        'host'           => $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '',
        'content-type'   => $_SERVER['CONTENT_TYPE'] ?? '',
        'content-length' => $_SERVER['CONTENT_LENGTH'] ?? '',
        default          => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $headerName))] ?? '',
    };
}

/**
 * Build the canonical headers block for a semicolon-separated SignedHeaders value.
 */
function buildCanonicalHeaders(string $signedHeaders): string
{
    $headers = array_filter(array_map('trim', explode(';', strtolower($signedHeaders))));
    sort($headers, SORT_STRING);

    $canonical = '';
    foreach ($headers as $headerName) {
        $value = preg_replace('/\s+/', ' ', trim(requestHeader($headerName))) ?? '';
        $canonical .= $headerName . ':' . $value . "
";
    }

    return $canonical;
}

/**
 * Return the normalized SignedHeaders string sorted in canonical order.
 */
function normalizeSignedHeaders(string $signedHeaders): string
{
    $headers = array_filter(array_map('trim', explode(';', strtolower($signedHeaders))));
    sort($headers, SORT_STRING);

    return implode(';', $headers);
}


/**
 * Detect S3 subresources that Tiny S3 intentionally does not implement.
 *
 * Signed request query parameters are ignored here because they are part of the
 * authentication mechanism, not requested S3 functionality. Response override
 * parameters are also allowed for presigned download URLs; Tiny S3 currently
 * ignores them but they do not change storage semantics.
 */
function notifyIfUnsupportedS3Feature(): void
{
    $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
    if ($rawQuery === '') {
        return;
    }

    parse_str($rawQuery, $query);

    $supportedPassThrough = [
        'prefix', 'delimiter', 'marker', 'max-keys', 'encoding-type', 'list-type', 'continuation-token', 'start-after',
        'uploads', 'location', 'versioning',
        'response-content-type', 'response-content-language', 'response-expires',
        'response-cache-control', 'response-content-disposition', 'response-content-encoding',
    ];

    $notImplemented = [
        'uploadid'      => 'Multipart Upload API (?uploadId=...)',
        'partnumber'    => 'Multipart Upload API (?partNumber=...)',
        'acl'           => 'ACL API (?acl)',
        'tagging'       => 'Object Tagging API (?tagging)',
        'versions'      => 'Object Versions API (?versions)',
        'policy'        => 'Bucket Policy API (?policy)',
        'cors'          => 'Bucket CORS API (?cors)',
        'lifecycle'     => 'Bucket Lifecycle API (?lifecycle)',
        'website'       => 'Bucket Website API (?website)',
        'notification'  => 'Bucket Notification API (?notification)',
        'encryption'    => 'Server-side Encryption API (?encryption)',
        'requestpayment'=> 'Requester Pays API (?requestPayment)',
        'replication'   => 'Bucket Replication API (?replication)',
        'accelerate'    => 'Transfer Acceleration API (?accelerate)',
        'inventory'     => 'Bucket Inventory API (?inventory)',
        'metrics'       => 'Bucket Metrics API (?metrics)',
        'analytics'     => 'Bucket Analytics API (?analytics)',
        'object-lock'   => 'Object Lock API (?object-lock)',
        'legal-hold'    => 'Object Legal Hold API (?legal-hold)',
        'retention'     => 'Object Retention API (?retention)',
        'restore'       => 'Object Restore API (?restore)',
        'select'        => 'S3 Select API (?select)',
        'torrent'       => 'Torrent API (?torrent)',
    ];

    foreach ($query as $name => $_) {
        $normalized = strtolower((string)$name);

        if (str_starts_with($normalized, 'x-amz-') || in_array($normalized, $supportedPassThrough, true)) {
            continue;
        }

        if (isset($notImplemented[$normalized])) {
            sendNotImplemented($notImplemented[$normalized]);
        }
    }
}


/**
 * Return true when the current query string contains a given S3 subresource.
 *
 * S3 clients commonly send subresources with an empty value, for example
 * `?location=` or `?uploads=`. array_key_exists() is therefore required;
 * isset() would fail for some parsed forms.
 */
function hasS3Subresource(array $query, string $name): bool
{
    foreach ($query as $key => $_) {
        if (strcasecmp((string)$key, $name) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Return the configured S3 region using the GetBucketLocation XML shape.
 *
 * AWS returns an empty LocationConstraint for us-east-1. Most S3-compatible
 * clients accept either an empty value or the configured region; Tiny S3 keeps
 * the AWS-compatible empty value only for us-east-1.
 */
function getBucketLocation(string $bucketDir, string $bucket): void
{
    global $region;

    if ($bucket === '' || !is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    $location = ($region === 'us-east-1') ? '' : $region;

    header('Content-Type: application/xml');
    echo '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        . '</LocationConstraint>';
    writeLog('INFO', "LOCATION $bucket — region: " . ($location === '' ? 'us-east-1(empty)' : $location));
}


/**
 * Return an empty bucket versioning configuration.
 *
 * Many S3 clients ask for ?versioning during navigation. Tiny S3 does not store
 * object versions, so the compatible response is an empty VersioningConfiguration
 * document, which means versioning is suspended/disabled.
 */
function getBucketVersioning(string $bucketDir, string $bucket): void
{
    if ($bucket === '' || !is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    header('Content-Type: application/xml');
    echo '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></VersioningConfiguration>';
    writeLog('INFO', "VERSIONING $bucket — disabled");
}

/**
 * Return an empty multipart upload listing.
 *
 * Tiny S3 does not implement multipart upload storage yet, but clients such as
 * Cyberduck probe `?uploads` while browsing. Returning the official empty XML
 * response is more compatible than failing navigation with 501.
 */
function listMultipartUploads(string $bucketDir, string $bucket): void
{
    if ($bucket !== '' && !is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    header('Content-Type: application/xml');
    echo '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . xmlElement('Bucket', $bucket)
        . xmlElement('KeyMarker', '')
        . xmlElement('UploadIdMarker', '')
        . xmlElement('NextKeyMarker', '')
        . xmlElement('NextUploadIdMarker', '')
        . xmlElement('MaxUploads', '1000')
        . xmlElement('IsTruncated', 'false')
        . '</ListMultipartUploadsResult>';
    writeLog('INFO', "MULTIPART LIST $bucket — 0 upload(s) returned");
}

/**
 * Validate freshness for a presigned URL.
 */
function validatePresignedExpiry(string $amzDate, int $expires): void
{
    if ($expires < 1 || $expires > 604800) {
        sendError(403, 'AccessDenied', 'X-Amz-Expires must be between 1 and 604800 seconds');
    }

    $issuedAt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
    if (!$issuedAt) {
        sendError(403, 'AccessDenied', 'Invalid X-Amz-Date value');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($now->getTimestamp() > ($issuedAt->getTimestamp() + $expires)) {
        sendError(403, 'AccessDenied', 'Presigned URL has expired');
    }
}

/**
 * Build and verify a canonical request for Authorization-header based SigV4.
 */
function checkAuthorizationHeaderSignature(): void
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    writeLog('DEBUG', "Authorization header: $authHeader");

    $auth = parseAuthorization($authHeader);
    writeLog('DEBUG', "Parsed auth: " . json_encode($auth));

    if ($auth['AK'] !== $GLOBALS['accessKey']) {
        writeLog('ERROR', "Access key mismatch — received '{$auth['AK']}'");
        sendError(403, 'AccessDenied', 'Invalid Access Key');
    }

    if ($auth['Region'] !== $GLOBALS['region']) {
        sendError(403, 'AuthorizationHeaderMalformed', 'The credential scope region does not match this server');
    }

    $amzDate = $_SERVER['HTTP_X_AMZ_DATE'] ?? '';
    if (!$amzDate) {
        writeLog('ERROR', "Missing x-amz-date header");
        sendError(403, 'MissingDate', 'x-amz-date header is required');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $qs     = $_SERVER['QUERY_STRING'] ?? '';

    $signedHeaders = normalizeSignedHeaders($auth['Signed']);
    $canonicalHeaders = buildCanonicalHeaders($signedHeaders);

    $hashedPayload = $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '';
    if (!$hashedPayload) {
        $hashedPayload = hash('sha256', file_get_contents('php://input'));
    }

    $canonicalRequest = implode("
", [
        $method,
        $path,
        buildCanonicalQueryString($qs, false),
        $canonicalHeaders,
        $signedHeaders,
        $hashedPayload,
    ]);

    writeLog('DEBUG', "Canonical request:
$canonicalRequest");

    $stringToSign = implode("
", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $auth['Date'] . '/' . $GLOBALS['region'] . '/s3/aws4_request',
        hash('sha256', $canonicalRequest),
    ]);

    writeLog('DEBUG', "String-to-sign:
$stringToSign");

    $signingKey    = getSigningKey($auth['Date'], $GLOBALS['region'], 's3');
    $calculatedSig = hash_hmac('sha256', $stringToSign, $signingKey);

    writeLog('DEBUG', "Signature — calculated: $calculatedSig | received: {$auth['Sig']}");

    if (!hash_equals($calculatedSig, $auth['Sig'])) {
        writeLog('ERROR', "Signature mismatch — calculated: $calculatedSig | received: {$auth['Sig']}");
        sendError(403, 'SignatureDoesNotMatch', 'The request signature does not match');
    }

    writeLog('DEBUG', 'Authorization header signature OK');
}

/**
 * Validate an AWS Signature V4 presigned URL.
 *
 * Supported presigned operations are the same minimal object/bucket operations
 * supported by the normal Authorization-header flow. The signature is carried in
 * query parameters instead of the Authorization header.
 */
function checkPresignedUrlSignature(): void
{
    $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($rawQuery, $query);

    $algorithm = (string)($query['X-Amz-Algorithm'] ?? $query['x-amz-algorithm'] ?? '');
    if ($algorithm !== 'AWS4-HMAC-SHA256') {
        sendError(403, 'AccessDenied', 'Unsupported or missing X-Amz-Algorithm');
    }

    foreach (['X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature'] as $required) {
        if (!array_key_exists($required, $query)) {
            sendError(403, 'AccessDenied', "Missing presigned URL parameter: {$required}");
        }
    }

    $auth = parsePresignedCredential($query);
    writeLog('DEBUG', "Parsed presigned auth: " . json_encode($auth));

    if ($auth['AK'] !== $GLOBALS['accessKey']) {
        writeLog('ERROR', "Presigned access key mismatch — received '{$auth['AK']}'");
        sendError(403, 'AccessDenied', 'Invalid Access Key');
    }

    if ($auth['Region'] !== $GLOBALS['region']) {
        sendError(403, 'AuthorizationHeaderMalformed', 'The credential scope region does not match this server');
    }

    $amzDate = (string)$query['X-Amz-Date'];
    validatePresignedExpiry($amzDate, (int)$query['X-Amz-Expires']);

    $method = $_SERVER['REQUEST_METHOD'];
    $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

    $signedHeaders    = normalizeSignedHeaders($auth['Signed']);
    $canonicalHeaders = buildCanonicalHeaders($signedHeaders);

    // S3 presigned URLs normally use UNSIGNED-PAYLOAD. If a client sends an
    // explicit X-Amz-Content-Sha256 query parameter, honour it for compatibility.
    $payloadHash = (string)($query['X-Amz-Content-Sha256'] ?? $query['x-amz-content-sha256'] ?? 'UNSIGNED-PAYLOAD');

    $canonicalRequest = implode("
", [
        $method,
        $path,
        buildCanonicalQueryString($rawQuery, true),
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    writeLog('DEBUG', "Presigned canonical request:
$canonicalRequest");

    $stringToSign = implode("
", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $auth['Date'] . '/' . $GLOBALS['region'] . '/s3/aws4_request',
        hash('sha256', $canonicalRequest),
    ]);

    writeLog('DEBUG', "Presigned string-to-sign:
$stringToSign");

    $signingKey    = getSigningKey($auth['Date'], $GLOBALS['region'], 's3');
    $calculatedSig = hash_hmac('sha256', $stringToSign, $signingKey);

    writeLog('DEBUG', "Presigned signature — calculated: $calculatedSig | received: {$auth['Sig']}");

    if (!hash_equals($calculatedSig, $auth['Sig'])) {
        writeLog('ERROR', "Presigned signature mismatch — calculated: $calculatedSig | received: {$auth['Sig']}");
        sendError(403, 'SignatureDoesNotMatch', 'The presigned URL signature does not match');
    }

    writeLog('DEBUG', 'Presigned URL signature OK');
}

/**
 * Validate the current request using either Authorization-header SigV4 or
 * query-string SigV4 presigned URL authentication.
 */
function checkSignature(): void
{
    $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($rawQuery, $query);

    if (isset($query['X-Amz-Algorithm']) || isset($query['x-amz-algorithm'])) {
        checkPresignedUrlSignature();
        return;
    }

    checkAuthorizationHeaderSignature();
}



// ================================================================================================
// SECTION 6 — PATH SAFETY HELPER
// ================================================================================================

/**
 * Resolve a bucket-relative key to an absolute filesystem path and verify it stays
 * inside the bucket directory, preventing path traversal attacks.
 *
 * Without this check a crafted key (e.g. ../../etc/passwd) can escape the storage root
 * by abusing `..` segments or symlinks. realpath() collapses the full path first,
 * then the prefix check ensures the result still lives inside the bucket directory.
 *
 * Returns the verified real path on success. Calls sendError() and exits on failure.
 *
 * @param string $bucketDir  Absolute path to the bucket root
 * @param string $key        Object key provided by the client
 * @return string            Verified absolute path to the object
 */
function resolveSafePath(string $bucketDir, string $key): string
{
    $candidatePath  = "$bucketDir/$key";
    $resolvedPath   = realpath($candidatePath);
    $resolvedBucket = realpath($bucketDir);

    if ($resolvedPath === false) {
        sendError(404, 'NoSuchKey', 'Object not found');
    }

    if ($resolvedBucket === false || !str_starts_with($resolvedPath, $resolvedBucket . DIRECTORY_SEPARATOR)) {
        writeLog('ERROR', "Path traversal attempt — key: '$key' | resolved: '$resolvedPath' | bucket: '$resolvedBucket'");
        sendError(403, 'AccessDenied', 'Path traversal is not permitted');
    }

    return $resolvedPath;
}

/**
 * Validate an object key supplied by the client for use in a write (PUT) operation.
 *
 * resolveSafePath() relies on realpath(), which requires the target file to already
 * exist on disk — it cannot be used for new uploads.  This function fills that gap:
 * it inspects the key's components *before* any file is created and rejects keys
 * that contain ".." path segments, which could otherwise escape the bucket directory.
 *
 * Logs the attempt as ERROR and exits with 400 on a bad key.
 *
 * @param string $key    Object key provided by the client
 * @param string $bucket Bucket name, used for logging only
 */
function validateUploadKey(string $key, string $bucket): void
{
    // Split on both Unix '/' and Windows '\' separators for robustness
    $parts = preg_split('#[/\\\\]#', $key);
    foreach ($parts as $part) {
        if ($part === '..') {
            writeLog('ERROR', "Path traversal attempt in upload key — key: '$key' | bucket: '$bucket'");
            sendError(400, 'InvalidKey', 'Object key may not contain ".." path components');
        }
    }
}



/**
 * Ensure the filesystem parent path for an object key can exist.
 *
 * S3 has a flat namespace: an object named "uploads" and another object named
 * "uploads/file.txt" may coexist. A plain filesystem cannot represent that
 * directly because "uploads" cannot be both a file and a directory.
 *
 * Tiny S3 keeps the implementation minimal and favors the directory/prefix use
 * case used by S3 GUI clients and SDK uploads. When a zero-byte placeholder file
 * blocks an intermediate prefix, it is converted into a directory. This fixes
 * uploads after clients create visual folders/prefix markers.
 */
function ensureObjectDirectory(string $bucketDir, string $key, string $bucket): string
{
    $trimmedKey = trim($key, '/');

    if ($trimmedKey === '') {
        return $bucketDir;
    }

    $isFolderMarker = str_ends_with($key, '/');
    $parts = array_values(array_filter(explode('/', $trimmedKey), static fn(string $part): bool => $part !== ''));

    // For normal objects, create all path components except the last file name.
    // For folder markers, every component represents a directory.
    $directoryParts = $isFolderMarker ? $parts : array_slice($parts, 0, -1);

    $current = $bucketDir;
    foreach ($directoryParts as $part) {
        $current .= '/' . $part;

        if (is_file($current)) {
            if (filesize($current) === 0) {
                if (!unlink($current)) {
                    writeLog('ERROR', "Could not replace zero-byte prefix object with directory: $current");
                    sendError(500, 'InternalError', 'Could not prepare object directory');
                }
                writeLog('INFO', "Converted zero-byte prefix object into directory: $current");
            } else {
                writeLog('ERROR', "Non-empty object blocks directory prefix: $current");
                sendError(409, 'OperationAborted', 'A non-empty object already exists where a directory prefix is required');
            }
        }

        if (!is_dir($current)) {
            if (!mkdir($current, 0755, true) && !is_dir($current)) {
                $error = error_get_last();
                $message = $error['message'] ?? 'unknown mkdir error';
                writeLog('ERROR', "mkdir failed for object directory: $current — $message");
                sendError(500, 'InternalError', 'Could not create object directory');
            }
        }
    }

    return $isFolderMarker ? ($bucketDir . '/' . $trimmedKey) : dirname($bucketDir . '/' . $trimmedKey);
}

/**
 * Create a zero-byte folder marker as a real directory.
 *
 * S3 itself has no real folders, but GUI clients such as Cyberduck create
 * zero-byte objects with trailing "/" to represent folders. Representing those
 * markers as directories keeps filesystem-backed listing and nested uploads
 * working predictably.
 */
function createFolderMarker(string $bucketDir, string $key, string $bucket): void
{
    $folderPath = ensureObjectDirectory($bucketDir, $key, $bucket);

    if (!is_dir($folderPath)) {
        if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown mkdir error';
            writeLog('ERROR', "mkdir failed for folder marker: $folderPath — $message");
            sendError(500, 'InternalError', 'Could not create folder marker');
        }
    }

    http_response_code(200);
    header('ETag: "' . md5('') . '"');
    header('Content-Type: application/xml');
    writeLog('INFO', "Folder marker created: $bucket/$key");
}


// ================================================================================================
// SECTION 7 — PUT: BUCKET CREATION & OBJECT UPLOAD
// ================================================================================================

/**
 * Route PUT: empty key → create bucket, non-empty key → upload object.
 */
function handlePut(string $bucket, string $key, string $bucketDir): void
{
    if ($key === '') {
        createBucket($bucketDir, $bucket);
    } else {
        uploadObject($bucketDir, $key, $bucket);
    }
}

/**
 * Create a new bucket by making a directory under STORAGE_ROOT.
 * Returns 200 on success, 409 if already exists, 500 on filesystem failure.
 */
function createBucket(string $bucketDir, string $bucket): void
{
    if (is_dir($bucketDir)) {
        sendError(409, 'BucketAlreadyExists', "Bucket '$bucket' already exists");
    }

    if (!mkdir($bucketDir, 0755, true)) {
        writeLog('ERROR', "mkdir failed for bucket directory: $bucketDir");
        sendError(500, 'InternalError', 'Could not create bucket directory');
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    echo "<CreateBucketResult>" . xmlElement('Location', "/$bucket") . "</CreateBucketResult>";
    writeLog('INFO', "Bucket created: $bucket");
}

/**
 * Write an uploaded object to disk, supporting two transfer modes:
 *
 *  Normal   — plain byte stream (standard HTTP PUT body).
 *  Chunked  — AWS chunked encoding (x-amz-content-sha256: STREAMING-UNSIGNED-PAYLOAD-TRAILER).
 *             Each chunk: hex size line → payload bytes → CRLF. Terminal chunk has size 0.
 *
 * Returns 200 + ETag (MD5 of the written file) on success.
 * The ETag is returned so S3 clients can verify upload integrity without a separate HEAD.
 */
function uploadObject(string $bucketDir, string $key, string $bucket): void
{
    validateUploadKey($key, $bucket);

    if (str_ends_with($key, '/')) {
        createFolderMarker($bucketDir, $key, $bucket);
        return;
    }

    $safeKey = trim($key, '/');
    $dirPath = ensureObjectDirectory($bucketDir, $safeKey, $bucket);
    $fullPath = "$bucketDir/$safeKey";

    $out = fopen($fullPath, 'w');
    if ($out === false) {
        writeLog('ERROR', "fopen failed for write — path: $fullPath");
        sendError(500, 'InternalError', 'Could not open object for writing');
    }

    $in = fopen('php://input', 'r');
    if ($in === false) {
        fclose($out);
        writeLog('ERROR', "fopen failed for request body — path: $fullPath");
        sendError(500, 'InternalError', 'Could not read request body');
    }

    $isChunked = ($_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '') === 'STREAMING-UNSIGNED-PAYLOAD-TRAILER';

    writeLog('INFO', "Upload started — mode: " . ($isChunked ? 'aws-chunked' : 'normal') . " | path: $fullPath");

    if ($isChunked) {
        while (true) {
            $chunkHeader = fgets($in);
            if ($chunkHeader === false) break;

            $chunkHeader = trim($chunkHeader);
            if ($chunkHeader === '') continue;

            // Strip chunk extensions (everything after `;`)
            $semiPos = strpos($chunkHeader, ';');
            $sizeHex = $semiPos !== false ? substr($chunkHeader, 0, $semiPos) : $chunkHeader;

            if (!ctype_xdigit($sizeHex)) {
                writeLog('WARN', "Unrecognised chunk header: '$chunkHeader' — aborting upload of $bucket/$key");
                break;
            }

            $chunkSize = hexdec($sizeHex);

            if ($chunkSize === 0) {
                // Terminal chunk — drain any trailing trailer headers
                while (($line = fgets($in)) !== false && trim($line) !== '') {
                    // consume trailer lines
                }
                writeLog('DEBUG', "Terminal chunk (size=0) received — $bucket/$key");
                break;
            }

            $remaining = $chunkSize;
            while ($remaining > 0) {
                $chunk = fread($in, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    writeLog('WARN', "Unexpected EOF in chunk data — $bucket/$key | bytes remaining: $remaining");
                    break 2;
                }
                fwrite($out, $chunk);
                $remaining -= strlen($chunk);
            }

            fgets($in); // Consume trailing CRLF after each chunk's data
        }
    } else {
        while (!feof($in)) {
            $chunk = fread($in, 8192);
            if ($chunk !== false) {
                fwrite($out, $chunk);
            }
        }
    }

    fclose($in);
    fclose($out);

    http_response_code(200);
    header('Content-Type: application/xml');
    header('ETag: "' . md5_file($fullPath) . '"');
    echo "<PutObjectResult/>";
    writeLog('INFO', "Object saved: $bucket/$key");
}


// ================================================================================================
// SECTION 8 — HEAD: OBJECT EXISTENCE CHECK
// ================================================================================================

/**
 * Route HEAD: key required; returns 200 if the object exists, 404 otherwise.
 * A HEAD on a bucket root (no key) is rejected with 400.
 */
function handleHead(string $key, string $bucketDir, string $bucket): void
{
    if ($key === '') {
        writeLog('ERROR', "HEAD request missing key — bucket: '$bucket'");
        sendError(400, 'InvalidRequest', 'HEAD request requires an object key');
    }

    // resolveSafePath() verifies the resolved path stays inside the bucket directory,
    // preventing crafted keys from probing arbitrary files on the filesystem.
    $realPath = resolveSafePath($bucketDir, $key);

    writeLog('DEBUG', "HEAD — key: '$key' | resolved: '$realPath'");

    if (!is_file($realPath)) {
        sendError(404, 'NoSuchKey', 'Object not found');
    }

    http_response_code(200);
    header('Content-Type: application/octet-stream');
    writeLog('INFO', "HEAD 200: $bucket/$key");
}


// ================================================================================================
// SECTION 9 — GET: OBJECT DOWNLOAD & BUCKET LISTING
// ================================================================================================

/**
 * Route GET: non-empty key → download object, empty key → list bucket contents.
 */
function handleGet(string $key, string $bucketDir, string $bucket): void
{
    $query = [];
    parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

    if (hasS3Subresource($query, 'location')) {
        getBucketLocation($bucketDir, $bucket);
        return;
    }

    if (hasS3Subresource($query, 'uploads')) {
        listMultipartUploads($bucketDir, $bucket);
        return;
    }

    if (hasS3Subresource($query, 'versioning')) {
        getBucketVersioning($bucketDir, $bucket);
        return;
    }

    if ($bucket === '' && $key === '') {
        listBuckets($bucketDir);
        return;
    }

    if ($key !== '') {
        downloadObject($bucketDir, $key, $bucket);
    } else {
        listBucket($bucketDir, $bucket);
    }
}

/**
 * Stream the requested object back to the client as application/octet-stream.
 * Path traversal is prevented by resolveSafePath() before any file access.
 */
function downloadObject(string $bucketDir, string $key, string $bucket): void
{
    $realPath = resolveSafePath($bucketDir, $key);

    if (is_dir($realPath)) {
        sendError(404, 'NoSuchKey', 'The key refers to a directory, not an object');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($realPath));
    readfile($realPath);
    writeLog('INFO', "GET 200: $bucket/$key");
}


/**
 * Return the service-level bucket list used by `GET /`.
 *
 * This follows the S3 ListBuckets response shape. Bucket directories are the
 * source of truth; regular files placed directly under STORAGE_ROOT are ignored.
 */
function listBuckets(string $storageRoot): void
{
    if (!is_dir($storageRoot)) {
        if (!mkdir($storageRoot, 0755, true)) {
            writeLog('ERROR', "mkdir failed for storage root: $storageRoot");
            sendError(500, 'InternalError', 'Could not create storage root directory');
        }
    }

    $bucketNames = [];
    foreach (array_diff(scandir($storageRoot), ['.', '..']) as $entry) {
        $path = $storageRoot . '/' . $entry;
        if (is_dir($path)) {
            $bucketNames[] = $entry;
        }
    }

    sort($bucketNames, SORT_STRING);

    header('Content-Type: application/xml');
    echo '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    echo '<Owner>' . xmlElement('ID', 'tiny-s3') . xmlElement('DisplayName', 'tiny-s3') . '</Owner>';
    echo '<Buckets>';

    foreach ($bucketNames as $bucketName) {
        $createdAt = gmdate('Y-m-d\TH:i:s.000\Z', filemtime($storageRoot . '/' . $bucketName) ?: time());
        echo '<Bucket>'
            . xmlElement('Name', $bucketName)
            . xmlElement('CreationDate', $createdAt)
            . '</Bucket>';
    }

    echo '</Buckets></ListAllMyBucketsResult>';
    writeLog('INFO', 'LIST buckets — ' . count($bucketNames) . ' bucket(s) returned');
}

/**
 * Return an S3-compatible XML listing of all objects inside the bucket.
 * Keys containing `/` represent virtual sub-directories, matching standard S3 behaviour.
 */
function listBucket(string $bucketDir, string $bucket): void
{
    writeLog('DEBUG', "LIST bucket='$bucket' dir='$bucketDir'");

    if (!is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    $query = [];
    parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

    if (($query['list-type'] ?? '') === '2') {
        listBucketV2($bucketDir, $bucket, $query);
        return;
    }

    $prefix = (string)($query['prefix'] ?? '');
    $delimiter = (string)($query['delimiter'] ?? '');
    $maxKeys = max(1, min(1000, (int)($query['max-keys'] ?? 1000)));

    $listing = buildDelimitedListing(listObjectsRecursively($bucketDir), $prefix, $delimiter);
    $combinedCount = count($listing['contents']) + count($listing['commonPrefixes']);
    $isTruncated = $combinedCount > $maxKeys;

    $remaining = $maxKeys;
    $contents = array_slice($listing['contents'], 0, $remaining);
    $remaining -= count($contents);
    $commonPrefixes = $remaining > 0 ? array_slice($listing['commonPrefixes'], 0, $remaining) : [];

    header('Content-Type: application/xml');
    echo '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . xmlElement('Name', $bucket)
        . xmlElement('Prefix', $prefix)
        . xmlElement('Marker', (string)($query['marker'] ?? ''))
        . xmlElement('MaxKeys', (string)$maxKeys)
        . xmlElement('Delimiter', $delimiter)
        . xmlElement('IsTruncated', $isTruncated ? 'true' : 'false');

    foreach ($contents as $objectKey) {
        $fullPath = $bucketDir . '/' . $objectKey;
        echo '<Contents>'
            . xmlElement('Key', $objectKey)
            . xmlElement('LastModified', is_file($fullPath) ? gmdate('Y-m-d\TH:i:s.000\Z', filemtime($fullPath) ?: time()) : gmdate('Y-m-d\TH:i:s.000\Z'))
            . xmlElement('ETag', is_file($fullPath) ? '"' . md5_file($fullPath) . '"' : '""')
            . xmlElement('Size', is_file($fullPath) ? (string)filesize($fullPath) : '0')
            . xmlElement('StorageClass', 'STANDARD')
            . '</Contents>';
    }

    foreach ($commonPrefixes as $commonPrefix) {
        echo '<CommonPrefixes>' . xmlElement('Prefix', $commonPrefix) . '</CommonPrefixes>';
    }

    echo '</ListBucketResult>';
    writeLog('INFO', "LIST $bucket — " . count($contents) . " object(s), " . count($commonPrefixes) . " common prefix(es) returned");
}

/**
 * Return a minimal ListObjectsV2-compatible XML response.
 *
 * This keeps common AWS SDK calls such as list_objects_v2() working while still
 * avoiding the complexity of full continuation-token pagination. The response is
 * deterministic, prefix-aware, and capped by max-keys.
 */
function listBucketV2(string $bucketDir, string $bucket, array $query): void
{
    $prefix  = (string)($query['prefix'] ?? '');
    $delimiter = (string)($query['delimiter'] ?? '');
    $maxKeys = max(1, min(1000, (int)($query['max-keys'] ?? 1000)));

    $listing = buildDelimitedListing(listObjectsRecursively($bucketDir), $prefix, $delimiter);
    $combinedCount = count($listing['contents']) + count($listing['commonPrefixes']);
    $isTruncated = $combinedCount > $maxKeys;

    $remaining = $maxKeys;
    $contents = array_slice($listing['contents'], 0, $remaining);
    $remaining -= count($contents);
    $commonPrefixes = $remaining > 0 ? array_slice($listing['commonPrefixes'], 0, $remaining) : [];
    $keyCount = count($contents) + count($commonPrefixes);

    header('Content-Type: application/xml');
    echo '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    echo xmlElement('Name', $bucket);
    echo xmlElement('Prefix', $prefix);
    echo xmlElement('KeyCount', (string)$keyCount);
    echo xmlElement('MaxKeys', (string)$maxKeys);
    echo xmlElement('Delimiter', $delimiter);
    echo xmlElement('IsTruncated', $isTruncated ? 'true' : 'false');

    foreach ($contents as $objectKey) {
        $fullPath = $bucketDir . '/' . $objectKey;
        echo '<Contents>'
            . xmlElement('Key', $objectKey)
            . xmlElement('LastModified', is_file($fullPath) ? gmdate('Y-m-d\TH:i:s.000\Z', filemtime($fullPath) ?: time()) : gmdate('Y-m-d\TH:i:s.000\Z'))
            . xmlElement('ETag', is_file($fullPath) ? '"' . md5_file($fullPath) . '"' : '""')
            . xmlElement('Size', is_file($fullPath) ? (string)filesize($fullPath) : '0')
            . xmlElement('StorageClass', 'STANDARD')
            . '</Contents>';
    }

    foreach ($commonPrefixes as $commonPrefix) {
        echo '<CommonPrefixes>' . xmlElement('Prefix', $commonPrefix) . '</CommonPrefixes>';
    }

    echo '</ListBucketResult>';
    writeLog('INFO', "LISTV2 $bucket — " . count($contents) . " object(s), " . count($commonPrefixes) . " common prefix(es) returned");
}


/**
 * Build an S3 delimiter-aware object listing.
 *
 * Without a delimiter, all matching keys are returned as Contents. With a
 * delimiter, keys containing the delimiter after the requested prefix are grouped
 * into CommonPrefixes, which is what GUI clients use to render folders.
 *
 * @param string[] $objects Ordered bucket-relative object keys
 * @return array{contents:string[], commonPrefixes:string[]}
 */
function buildDelimitedListing(array $objects, string $prefix, string $delimiter): array
{
    $contents = [];
    $commonPrefixes = [];

    foreach (filterListedObjects($objects, $prefix) as $objectKey) {
        if ($delimiter !== '') {
            $remaining = substr($objectKey, strlen($prefix));
            $delimiterPosition = strpos($remaining, $delimiter);

            if ($delimiterPosition !== false) {
                $commonPrefix = $prefix . substr($remaining, 0, $delimiterPosition + strlen($delimiter));
                $commonPrefixes[$commonPrefix] = true;
                continue;
            }
        }

        $contents[] = $objectKey;
    }

    $prefixes = array_keys($commonPrefixes);
    sort($prefixes, SORT_STRING);

    return [
        'contents' => $contents,
        'commonPrefixes' => $prefixes,
    ];
}

/**
 * Apply a simple S3 prefix filter to an ordered list of keys.
 *
 * @param string[] $objects
 * @return string[]
 */
function filterListedObjects(array $objects, string $prefix): array
{
    sort($objects, SORT_STRING);

    if ($prefix === '') {
        return array_values($objects);
    }

    return array_values(array_filter(
        $objects,
        static fn(string $key): bool => str_starts_with($key, $prefix)
    ));
}

/**
 * Walk a directory tree and return all file paths as bucket-relative keys.
 *
 * @param string $dir    Absolute path to scan
 * @param string $prefix Accumulated relative prefix for recursive calls
 * @return string[]      List of relative object keys
 */
function listObjectsRecursively(string $dir, string $prefix = ''): array
{
    $result = [];
    $items  = array_diff(scandir($dir), ['.', '..']);

    foreach ($items as $item) {
        $fullPath  = "$dir/$item";
        $objectKey = $prefix . $item;

        if (is_dir($fullPath)) {
            // Include the directory itself as a folder-marker key so empty
            // folders created by GUI clients appear in delimiter listings.
            $result[] = $objectKey . '/';

            // Recurse with trailing `/` to reflect S3's virtual directory convention.
            $result = array_merge($result, listObjectsRecursively($fullPath, $objectKey . '/'));
        } else {
            $result[] = $objectKey;
        }
    }

    return $result;
}


// ================================================================================================
// SECTION 10 — DELETE: OBJECT & BUCKET REMOVAL
// ================================================================================================

/**
 * Route DELETE: empty key → delete entire bucket, non-empty key → delete single object.
 */
function handleDelete(string $bucket, string $key, string $bucketDir): void
{
    if ($key === '') {
        deleteBucket($bucketDir, $bucket);
    } else {
        deleteObject($bucketDir, $key, $bucket);
    }
}

/**
 * Recursively delete every file and subdirectory inside a bucket, then the bucket
 * directory itself. Responds 204 No Content on success.
 */
function deleteBucket(string $bucketDir, string $bucket): void
{
    if (!is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    if (deleteDirectoryRecursive($bucketDir)) {
        http_response_code(204);
        writeLog('INFO', "Bucket deleted: $bucket");
    } else {
        writeLog('ERROR', "rmdir failed for bucket directory: $bucketDir");
        sendError(500, 'InternalError', 'Failed to delete bucket directory');
    }
}

/**
 * Recursively delete all contents of a directory, then remove the directory itself.
 *
 * Defined at file scope — a nested function declaration causes a fatal "Cannot redeclare"
 * error if the enclosing function is ever called more than once in the same process.
 */
function deleteDirectoryRecursive(string $dir): bool
{
    $items = array_diff(scandir($dir), ['.', '..']);

    foreach ($items as $item) {
        $path = "$dir/$item";
        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

/**
 * Delete a single object from the bucket.
 *
 * resolveSafePath() resolves and validates the key before unlink() is called — a raw
 * concatenated path without realpath() would allow path traversal attacks.
 *
 * Responds 204 No Content on success.
 */
function deleteObject(string $bucketDir, string $key, string $bucket): void
{
    $realPath = resolveSafePath($bucketDir, $key);

    if (!is_file($realPath)) {
        sendError(404, 'NoSuchKey', 'Object not found');
    }

    unlink($realPath);
    http_response_code(204);
    writeLog('INFO', "Object deleted: $bucket/$key");
}


// ================================================================================================
// SECTION 11 — ENTRY POINT
// Bootstrap, exception handler, IP allowlist check, signature check, URL parsing, method dispatch.
//
// Guarded by TINY_S3_TEST so that PHPUnit can require this file to register all
// function definitions (and measure coverage) without triggering the HTTP bootstrap.
// ================================================================================================

if (!defined('TINY_S3_TEST')) {

ini_set('display_errors', '1');
error_reporting(E_ALL);

loadEnv(__DIR__ . '/.env');

// $_ENV is only populated when php.ini's variables_order contains 'E' (absent on many installs).
// getenv() reads the real process environment unconditionally, so it works whether the values
// came from a .env file (loaded above into $_ENV) or were injected directly by the parent
// process (e.g. the integration test suite via proc_open).  Checking $_ENV first preserves
// the .env-file path; getenv() is the fallback for the process-environment path.
$debug      = envToBool(getenv('DEBUG')       ?: ($_ENV['DEBUG']       ?? 'false'));  // string fallback required — see envToBool()
$accessKey  = getenv('ACCESS_KEY')           ?: ($_ENV['ACCESS_KEY']  ?? '');
$secretKey  = getenv('SECRET_KEY')           ?: ($_ENV['SECRET_KEY']  ?? '');
$region     = getenv('REGION')               ?: ($_ENV['REGION']      ?? 'us-east-1');  // must match the region string the client sends
$allowedIps = getenv('ALLOWED_IPS')          ?: ($_ENV['ALLOWED_IPS'] ?? '');          // empty / "*" = open to all

// STORAGE_ROOT and LOG_FILE may be absolute paths (e.g. an integration test injecting
// a temp directory) or relative paths anchored to the project root (the normal .env case).
// An absolute path starts with a Unix root ('/'), a Windows drive letter ('C:\' or 'C:/'),
// or a UNC path ('\\').  Everything else is treated as relative to __DIR__.
$storageRootRaw = getenv('STORAGE_ROOT') ?: ($_ENV['STORAGE_ROOT'] ?? '../data');
$storageRoot    = preg_match('/^([A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $storageRootRaw)
    ? rtrim($storageRootRaw, '/\\')
    : __DIR__ . '/' . $storageRootRaw;

$logFileRaw = getenv('LOG_FILE') ?: ($_ENV['LOG_FILE'] ?? 'activities.log');
$logFile    = preg_match('/^([A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $logFileRaw)
    ? $logFileRaw
    : __DIR__ . '/' . $logFileRaw;

// Ensure the log file and its parent directory exist at startup.
// Creating it eagerly — rather than lazily on first write — means:
//   • operators can verify file permissions immediately after deployment
//   • `tail -f activities.log` works before any request has arrived
//   • the exception handler can always write without a redundant mkdir guard
//
// On shared hosting (e.g. a2hosting) the PHP process often cannot write to the
// web-root directory.  When that happens we fall back to the system temp directory
// so that logging always works, even before the operator has set a writable path.
// The fallback file is printed at the start of every request when DEBUG=true.
//
// If even the temp-dir write fails, messages are sent to error_log() so they
// appear in the web-server error log (Apache: error.log, PHP built-in: stderr).
$_logBootDir = dirname($logFile);
if (!is_dir($_logBootDir)) {
    if (!mkdir($_logBootDir, 0755, true)) {
        error_log("[tiny-s3] Cannot create log directory: $_logBootDir — check write permissions");
    }
}
if (!file_exists($logFile)) {
    if (!touch($logFile)) {
        // Web-root not writable — fall back to the system temp directory.
        // The hashed suffix keeps files for different deployments separate.
        $logFile = sys_get_temp_dir() . '/tiny-s3-' . substr(md5(__DIR__), 0, 8) . '.log';
        error_log("[tiny-s3] Cannot write to configured log path — falling back to: $logFile");
        touch($logFile); // best-effort; failure handled per-write in writeLog()
    }
}
unset($_logBootDir);

// Uncaught exceptions bypass writeLog() but are still always written as ERROR.
// The handler is wired directly to avoid dependency on $debug state at throw time.
set_exception_handler(function (Throwable $e) use ($logFile): void {
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] [ERROR] [EXCEPTION] '
          . get_class($e) . ': ' . $e->getMessage()
          . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;

    file_put_contents($logFile, $line, FILE_APPEND);

    http_response_code(500);
    header('Content-Type: application/xml');
    echo "<e><Code>InternalError</Code><Message>Unhandled server exception</Message></e>";
    exit;
});

// Request start — INFO so it only appears when DEBUG=true
writeLog('INFO', str_repeat('-', 60));
writeLog('INFO', "Log: $logFile");
writeLog('INFO', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']);

// Individual headers are DEBUG — noisy but invaluable when tracing signature issues
foreach (getallheaders() as $name => $value) {
    writeLog('DEBUG', "Header: $name: $value");
}

// /healthz — Docker/Kubernetes-friendly health endpoint.
// It is intentionally public and returns only a minimal status, with no secrets or paths.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/healthz')
) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

// /__diag — diagnostic endpoint (must come before IP allowlist + signature checks)
// Responds to GET /__diag?token=<SECRET_KEY> with a plain-text report of every
// resolved config value, path, and write-permission check.
// Useful when SSH is unavailable (shared hosting) and logging is not yet working.
// Authentication: the SECRET_KEY itself is the token — only someone who already
// knows the key can read the diagnostics.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/__diag')
    && hash_equals($secretKey, $_GET['token'] ?? '')
    && $secretKey !== ''
) {
    header('Content-Type: text/plain; charset=utf-8');
    $w = fn(string $path) => (is_writable($path) ? 'writable' : 'NOT WRITABLE');
    $e = fn(string $path) => (file_exists($path)  ? 'exists'   : 'MISSING');
    echo "Tiny S3 — diagnostic report\n";
    echo str_repeat('=', 60) . "\n\n";
    echo "PHP version   : " . PHP_VERSION . "\n";
    echo "SAPI          : " . PHP_SAPI    . "\n";
    echo "REMOTE_ADDR   : " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";
    echo "HTTP_HOST     : " . ($_SERVER['HTTP_HOST']   ?? '-') . "\n";
    echo "REQUEST_URI   : " . ($_SERVER['REQUEST_URI'] ?? '-') . "\n\n";
    echo "--- Config ---\n";
    echo "__DIR__       : " . __DIR__       . "  [" . $w(__DIR__)       . "]\n";
    echo "STORAGE_ROOT  : " . $storageRoot  . "  [" . $e($storageRoot)  . "] [" . $w(dirname($storageRoot)) . "]\n";
    echo "LOG_FILE      : " . $logFile      . "  [" . $e($logFile)      . "] [" . $w(dirname($logFile))     . "]\n";
    echo "sys_get_temp_dir: " . sys_get_temp_dir() . "  [" . $w(sys_get_temp_dir()) . "]\n";
    echo "DEBUG         : " . ($debug ? 'true' : 'false') . "\n";
    echo "REGION        : " . $region      . "\n";
    echo "ALLOWED_IPS   : " . ($allowedIps ?: '(open to all)') . "\n\n";
    echo "--- .env ---\n";
    echo ".env path     : " . __DIR__ . "/.env  [" . $e(__DIR__ . '/.env') . "]\n\n";
    echo "--- Log write test ---\n";
    $testLine = '[' . date('Y-m-d H:i:s') . '] [DIAG] diagnostic write test' . PHP_EOL;
    $result   = file_put_contents($logFile, $testLine, FILE_APPEND);
    echo "Write result  : " . ($result !== false ? "$result bytes written OK" : "FAILED") . "\n";
    exit;
}

checkIpAllowlist();
checkSignature();
notifyIfUnsupportedS3Feature();

$uriPath   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uriParts  = explode('/', trim($uriPath, '/'), 2);
$bucket    = $uriParts[0] ?? '';
$key       = $uriParts[1] ?? '';
$bucketDir = "$storageRoot/$bucket";

writeLog('DEBUG', "Routed — bucket: '$bucket' | key: '$key' | dir: '$bucketDir'");

$method = $_SERVER['REQUEST_METHOD'];

match ($method) {
    'PUT'    => handlePut($bucket, $key, $bucketDir),
    'HEAD'   => handleHead($key, $bucketDir, $bucket),
    'GET'    => handleGet($key, $bucketDir, $bucket),
    'DELETE' => handleDelete($bucket, $key, $bucketDir),
    default  => sendNotImplemented("HTTP method '$method'"),
};

} // end if (!defined('TINY_S3_TEST'))

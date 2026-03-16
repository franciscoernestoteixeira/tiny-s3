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
 * Validate the AWS Signature V4 Authorization header on the current request.
 * Exits with 403 on any mismatch.
 *
 * The region is read from the REGION environment variable (not hardcoded) so the server
 * can match whatever region string the client sends in its credential scope.
 *
 * Security failures (bad key, missing date, signature mismatch) are logged as ERROR.
 * Verbose internals (canonical request, string-to-sign, raw header values) are DEBUG only.
 */
function checkSignature(): void
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    writeLog('DEBUG', "Authorization header: $authHeader");

    $auth = parseAuthorization($authHeader);
    writeLog('DEBUG', "Parsed auth: " . json_encode($auth));

    // --- 1. Access key check ---
    if ($auth['AK'] !== $GLOBALS['accessKey']) {
        writeLog('ERROR', "Access key mismatch — received '{$auth['AK']}'");
        sendError(403, 'AccessDenied', 'Invalid Access Key');
    }

    // --- 2. Require x-amz-date header ---
    $amzDate = $_SERVER['HTTP_X_AMZ_DATE'] ?? '';
    if (!$amzDate) {
        writeLog('ERROR', "Missing x-amz-date header");
        sendError(403, 'MissingDate', 'x-amz-date header is required');
    }

    // --- 3. Build canonical request ---
    $method = $_SERVER['REQUEST_METHOD'];
    $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $qs     = $_SERVER['QUERY_STRING'] ?? '';

    // Sort query parameters lexicographically, as required by SigV4
    $canonicalQueryString = '';
    if ($qs) {
        parse_str($qs, $queryParts);
        ksort($queryParts);
        $canonicalQueryString = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
    }

    // Build canonical headers block from the list declared in SignedHeaders
    $signedHeaders    = explode(';', $auth['Signed']);
    $canonicalHeaders = '';

    foreach ($signedHeaders as $headerName) {
        $headerName = strtolower($headerName);
        $val = match ($headerName) {
            'host'                 => $_SERVER['HTTP_HOST']                  ?? $_SERVER['SERVER_NAME'],
            'content-type'         => $_SERVER['CONTENT_TYPE']               ?? '',
            'x-amz-date'           => $_SERVER['HTTP_X_AMZ_DATE']            ?? '',
            'x-amz-content-sha256' => $_SERVER['HTTP_X_AMZ_CONTENT_SHA256']  ?? '',
            default                => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $headerName))] ?? '',
        };
        $canonicalHeaders .= $headerName . ':' . trim($val) . "\n";
    }

    // Use the pre-computed payload hash when provided, or hash the raw body
    $hashedPayload = $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '';
    if (!$hashedPayload) {
        $hashedPayload = hash('sha256', file_get_contents('php://input'));
    }

    $canonicalRequest =
        $method . "\n" .
        $path   . "\n" .
        $canonicalQueryString . "\n" .
        $canonicalHeaders     . "\n" .
        $auth['Signed']       . "\n" .
        $hashedPayload;

    writeLog('DEBUG', "Canonical request:\n$canonicalRequest");

    // --- 4. Build string-to-sign ---
    $region = $GLOBALS['region'];

    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $amzDate . "\n" .
        $auth['Date'] . "/$region/s3/aws4_request\n" .
        hash('sha256', $canonicalRequest);

    writeLog('DEBUG', "String-to-sign:\n$stringToSign");

    // --- 5. Derive signing key and compare using timing-safe comparison ---
    $signingKey    = getSigningKey($auth['Date'], $region, 's3');
    $calculatedSig = hash_hmac('sha256', $stringToSign, $signingKey);

    writeLog('DEBUG', "Signature — calculated: $calculatedSig | received: {$auth['Sig']}");

    if (!hash_equals($calculatedSig, $auth['Sig'])) {
        writeLog('ERROR', "Signature mismatch — calculated: $calculatedSig | received: {$auth['Sig']}");
        sendError(403, 'SignatureDoesNotMatch', 'The request signature does not match');
    }

    writeLog('DEBUG', "Signature OK");
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

    $fullPath = "$bucketDir/$key";
    $dirPath  = dirname($fullPath);

    // Auto-create intermediate directories for keys containing `/` path separators
    if (!is_dir($dirPath)) {
        if (!mkdir($dirPath, 0755, true)) {
            writeLog('ERROR', "mkdir failed for object directory: $dirPath");
            sendError(500, 'InternalError', 'Could not create object directory');
        }
    }

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
 * Return an S3-compatible XML listing of all objects inside the bucket.
 * Keys containing `/` represent virtual sub-directories, matching standard S3 behaviour.
 */
function listBucket(string $bucketDir, string $bucket): void
{
    writeLog('DEBUG', "LIST bucket='$bucket' dir='$bucketDir'");

    if (!is_dir($bucketDir)) {
        sendError(404, 'NoSuchBucket', "Bucket '$bucket' does not exist");
    }

    $objects = listObjectsRecursively($bucketDir);

    header('Content-Type: application/xml');
    echo "<ListBucketResult>" . xmlElement('Name', $bucket);

    foreach ($objects as $objectKey) {
        echo "<Contents>" . xmlElement('Key', $objectKey) . "</Contents>";
    }

    echo "</ListBucketResult>";
    writeLog('INFO', "LIST $bucket — " . count($objects) . " object(s) returned");
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
            // Recurse with trailing `/` to reflect S3's virtual directory convention
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
$debug      = envToBool($_ENV['DEBUG']       ?? getenv('DEBUG')       ?: 'false');  // string fallback required — see envToBool()
$accessKey  = $_ENV['ACCESS_KEY']            ?? getenv('ACCESS_KEY')  ?: '';
$secretKey  = $_ENV['SECRET_KEY']            ?? getenv('SECRET_KEY')  ?: '';
$region     = $_ENV['REGION']                ?? getenv('REGION')      ?: 'us-east-1';  // must match the region string the client sends
$allowedIps = $_ENV['ALLOWED_IPS']           ?? getenv('ALLOWED_IPS') ?: '';          // empty / "*" = open to all

// STORAGE_ROOT and LOG_FILE may be absolute paths (e.g. an integration test injecting
// a temp directory) or relative paths anchored to the project root (the normal .env case).
// An absolute path starts with a Unix root ('/'), a Windows drive letter ('C:\' or 'C:/'),
// or a UNC path ('\\').  Everything else is treated as relative to __DIR__.
$storageRootRaw = $_ENV['STORAGE_ROOT'] ?? getenv('STORAGE_ROOT') ?: '../data';
$storageRoot    = preg_match('/^([A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $storageRootRaw)
    ? rtrim($storageRootRaw, '/\\')
    : __DIR__ . '/' . $storageRootRaw;

$logFileRaw = $_ENV['LOG_FILE'] ?? getenv('LOG_FILE') ?: 'activities.log';
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
    default  => sendError(405, 'MethodNotAllowed', "HTTP method '$method' is not supported"),
};

} // end if (!defined('TINY_S3_TEST'))

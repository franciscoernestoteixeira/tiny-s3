<?php
declare(strict_types=1);

// =============================================================================
//  Unit-test helper stub — pure function definitions only.
//
//  This file extracts every function from index.php that:
//    • Has no side effects that touch the HTTP response (no header(), no exit)
//    • Can be exercised in isolation without a running web server
//
//  Functions that call sendError() — and therefore exit() — are intentionally
//  omitted here. They are covered by the integration test suite instead, which
//  starts a real PHP built-in server and makes full HTTP round-trips.
//
//  The unit tests include this file via tests/bootstrap.php.
// =============================================================================


// -----------------------------------------------------------------------------
// SECTION 1 — ENVIRONMENT
// -----------------------------------------------------------------------------

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


// -----------------------------------------------------------------------------
// SECTION 3 — XML HELPERS
// -----------------------------------------------------------------------------

/**
 * Wrap content in a single XML element, properly escaping special characters.
 * Example: xmlElement('Key', 'my/path.txt') → <Key>my/path.txt</Key>
 */
function xmlElement(string $tag, string $content): string
{
    return "<{$tag}>" . htmlspecialchars($content, ENT_XML1) . "</{$tag}>";
}


// -----------------------------------------------------------------------------
// SECTION 4 — AWS SIGNATURE V4 (pure helpers only)
// -----------------------------------------------------------------------------

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


// -----------------------------------------------------------------------------
// SECTION 8 — FILESYSTEM LISTING HELPER
// -----------------------------------------------------------------------------

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


// -----------------------------------------------------------------------------
// SECTION 9 — DIRECTORY REMOVAL HELPER
// -----------------------------------------------------------------------------

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

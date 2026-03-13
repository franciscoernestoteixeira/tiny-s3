# ==============================================================================
#  Tiny S3 — PowerShell test / validator
#
#  Exercises every supported operation in order:
#    1. PUT    /bucket              → create bucket
#    2. PUT    /bucket/key          → upload a small text object
#    3. HEAD   /bucket/key          → check the object exists
#    4. GET    /bucket              → list bucket contents
#    5. GET    /bucket/key          → download the object and verify content
#    6. DELETE /bucket/key          → delete the object
#    7. DELETE /bucket              → delete the bucket
#
#  Each step prints PASS or FAIL with the HTTP status code received.
#
#  Requirements: PowerShell 5.1+ (Windows) or PowerShell 7+ (cross-platform)
#
#  Usage:
#    .\test.ps1
#    .\test.ps1 -Endpoint http://myserver -AccessKey mykey -SecretKey mysecret
# ==============================================================================

param(
    [string] $Endpoint  = "http://localhost:8080",
    [string] $AccessKey = "your-access-key-here",
    [string] $SecretKey = "your-secret-key-here",
    [string] $Region    = "us-east-1",
    [string] $Bucket    = "test-bucket",
    [string] $ObjectKey = "hello/world.txt"
)

$ObjectBody = "Tiny S3 test payload — $([datetime]::UtcNow.ToString('u'))"

# ------------------------------------------------------------------------------
# Colour helpers
# ------------------------------------------------------------------------------
function Write-Pass([string]$msg) {
    Write-Host "  " -NoNewline
    Write-Host "PASS" -ForegroundColor Green -NoNewline
    Write-Host "  $msg"
    $script:PassCount++
}

function Write-Fail([string]$msg) {
    Write-Host "  " -NoNewline
    Write-Host "FAIL" -ForegroundColor Red -NoNewline
    Write-Host "  $msg"
    $script:FailCount++
}

function Write-Step([string]$msg) {
    Write-Host "`n▶ $msg" -ForegroundColor Cyan
}

$script:PassCount = 0
$script:FailCount = 0

# ------------------------------------------------------------------------------
# HMAC-SHA256 helpers using .NET System.Security.Cryptography
# ------------------------------------------------------------------------------

# Compute HMAC-SHA256 of $Data using a raw byte array $KeyBytes.
# Returns the result as a raw byte array.
function Get-HmacSha256Bytes([byte[]]$KeyBytes, [string]$Data) {
    $hmac = [System.Security.Cryptography.HMACSHA256]::new($KeyBytes)
    return $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($Data))
}

# Compute HMAC-SHA256 and return as lowercase hex string.
function Get-HmacSha256Hex([byte[]]$KeyBytes, [string]$Data) {
    return [BitConverter]::ToString((Get-HmacSha256Bytes $KeyBytes $Data)) -replace '-', '' | ForEach-Object { $_.ToLower() }
}

# Compute SHA-256 of a string and return as lowercase hex.
function Get-Sha256([string]$Data) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $bytes = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($Data))
    return [BitConverter]::ToString($bytes) -replace '-', '' | ForEach-Object { $_.ToLower() }
}

# ------------------------------------------------------------------------------
# Invoke-S3Request -Method <string> -UriPath <string> [-Body <string>]
#
# Builds a fully signed AWS Signature V4 request and sends it via Invoke-WebRequest.
# Returns a hashtable with keys: Status (int), Body (string).
#
# The signing flow follows the AWS documentation exactly:
#   1. Canonical request  (method, path, query, headers, signed-headers, payload-hash)
#   2. String to sign     (algorithm, datetime, credential-scope, canonical-request-hash)
#   3. Signing key        (HMAC chain: secret → date → region → service → "aws4_request")
#   4. Signature          (HMAC of string-to-sign with signing key)
#   5. Authorization header assembled and passed to the request
# ------------------------------------------------------------------------------
function Invoke-S3Request {
    param(
        [string] $Method,
        [string] $UriPath,
        [string] $Body = ""
    )

    $Service = "s3"
    $Host    = ([uri]$Endpoint).Authority   # e.g. "localhost:8080"

    # Timestamps — AWS requires both ISO 8601 long form and short date
    $AmzDate   = [datetime]::UtcNow.ToString("yyyyMMddTHHmmssZ")
    $DateStamp = [datetime]::UtcNow.ToString("yyyyMMdd")

    # Hash the request body (empty string hash is used for bodyless requests)
    $PayloadHash = Get-Sha256 $Body

    # --- Step 1: Canonical request ---
    # Headers must be sorted alphabetically, lowercased, and trimmed.
    # We sign: host, x-amz-content-sha256, x-amz-date
    $CanonicalHeaders = "host:$Host`n" +
                        "x-amz-content-sha256:$PayloadHash`n" +
                        "x-amz-date:$AmzDate`n"   # trailing newline is required

    $SignedHeaders = "host;x-amz-content-sha256;x-amz-date"

    $CanonicalRequest = "$Method`n" +
                        "$UriPath`n" +
                        "`n" +                     # empty canonical query string
                        "$CanonicalHeaders`n" +
                        "$SignedHeaders`n" +
                        "$PayloadHash"

    # --- Step 2: String to sign ---
    $CredentialScope       = "$DateStamp/$Region/$Service/aws4_request"
    $CanonicalRequestHash  = Get-Sha256 $CanonicalRequest

    $StringToSign = "AWS4-HMAC-SHA256`n" +
                    "$AmzDate`n" +
                    "$CredentialScope`n" +
                    "$CanonicalRequestHash"

    # --- Step 3: Signing key ---
    # Each step takes the previous step's output bytes as the HMAC key.
    $kSecret  = [System.Text.Encoding]::UTF8.GetBytes("AWS4$SecretKey")
    $kDate    = Get-HmacSha256Bytes $kSecret    $DateStamp
    $kRegion  = Get-HmacSha256Bytes $kDate      $Region
    $kService = Get-HmacSha256Bytes $kRegion    $Service
    $kSigning = Get-HmacSha256Bytes $kService   "aws4_request"

    # --- Step 4: Signature ---
    $Signature = Get-HmacSha256Hex $kSigning $StringToSign

    # --- Step 5: Authorization header ---
    $AuthHeader = "AWS4-HMAC-SHA256 " +
                  "Credential=$AccessKey/$CredentialScope, " +
                  "SignedHeaders=$SignedHeaders, " +
                  "Signature=$Signature"

    # --- Send the request ---
    $Headers = @{
        "Host"                  = $Host
        "x-amz-date"            = $AmzDate
        "x-amz-content-sha256"  = $PayloadHash
        "Authorization"         = $AuthHeader
    }

    $Uri = "$Endpoint$UriPath"

    try {
        $Params = @{
            Uri                  = $Uri
            Method               = $Method
            Headers              = $Headers
            # Return the raw response so we can read the status code on errors too
            SkipHttpErrorCheck   = $true   # PS 7+ — keeps non-2xx from throwing
        }

        if ($Body -ne "") {
            $Params["Body"] = [System.Text.Encoding]::UTF8.GetBytes($Body)
        }

        $Response = Invoke-WebRequest @Params
        return @{ Status = [int]$Response.StatusCode; Body = $Response.Content }
    }
    catch {
        # Fallback for PowerShell 5.1 which throws on non-2xx status codes
        if ($_.Exception.Response) {
            $StatusCode = [int]$_.Exception.Response.StatusCode
            try {
                $Stream = $_.Exception.Response.GetResponseStream()
                $Reader = [System.IO.StreamReader]::new($Stream)
                $Content = $Reader.ReadToEnd()
            } catch { $Content = "" }
            return @{ Status = $StatusCode; Body = $Content }
        }
        Write-Fail "Request failed: $($_.Exception.Message)"
        return @{ Status = 0; Body = "" }
    }
}

# ==============================================================================
# TEST SUITE
# ==============================================================================

Write-Host "`nTiny S3 — PowerShell validator" -ForegroundColor White
Write-Host "  Endpoint : $Endpoint"
Write-Host "  Bucket   : $Bucket"
Write-Host "  Object   : $ObjectKey"

# ------------------------------------------------------------------------------
Write-Step "1/7  PUT /$Bucket  →  create bucket"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "PUT" -UriPath "/$Bucket"
if ($r.Status -eq 200) {
    Write-Pass "HTTP $($r.Status) — bucket created"
} elseif ($r.Status -eq 409) {
    Write-Pass "HTTP $($r.Status) — bucket already exists (acceptable)"
} else {
    Write-Fail "HTTP $($r.Status) — expected 200 or 409"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
Write-Step "2/7  PUT /$Bucket/$ObjectKey  →  upload object"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "PUT" -UriPath "/$Bucket/$ObjectKey" -Body $ObjectBody
if ($r.Status -eq 200) {
    Write-Pass "HTTP $($r.Status) — object uploaded"
} else {
    Write-Fail "HTTP $($r.Status) — expected 200"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
Write-Step "3/7  HEAD /$Bucket/$ObjectKey  →  object exists"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "HEAD" -UriPath "/$Bucket/$ObjectKey"
if ($r.Status -eq 200) {
    Write-Pass "HTTP $($r.Status) — object found"
} else {
    Write-Fail "HTTP $($r.Status) — expected 200"
}

# ------------------------------------------------------------------------------
Write-Step "4/7  GET /$Bucket  →  list bucket"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "GET" -UriPath "/$Bucket"
if ($r.Status -eq 200) {
    if ($r.Body -like "*$ObjectKey*") {
        Write-Pass "HTTP $($r.Status) — object key found in listing"
    } else {
        Write-Fail "HTTP $($r.Status) — object key missing from listing"
        Write-Host "       Response: $($r.Body)"
    }
} else {
    Write-Fail "HTTP $($r.Status) — expected 200"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
Write-Step "5/7  GET /$Bucket/$ObjectKey  →  download and verify"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "GET" -UriPath "/$Bucket/$ObjectKey"
if ($r.Status -eq 200) {
    if ($r.Body -eq $ObjectBody) {
        Write-Pass "HTTP $($r.Status) — content matches"
    } else {
        Write-Fail "HTTP $($r.Status) — content mismatch"
        Write-Host "       Expected : $ObjectBody"
        Write-Host "       Got      : $($r.Body)"
    }
} else {
    Write-Fail "HTTP $($r.Status) — expected 200"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
Write-Step "6/7  DELETE /$Bucket/$ObjectKey  →  delete object"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "DELETE" -UriPath "/$Bucket/$ObjectKey"
if ($r.Status -eq 204) {
    Write-Pass "HTTP $($r.Status) — object deleted"
} else {
    Write-Fail "HTTP $($r.Status) — expected 204"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
Write-Step "7/7  DELETE /$Bucket  →  delete bucket"
# ------------------------------------------------------------------------------
$r = Invoke-S3Request -Method "DELETE" -UriPath "/$Bucket"
if ($r.Status -eq 204) {
    Write-Pass "HTTP $($r.Status) — bucket deleted"
} else {
    Write-Fail "HTTP $($r.Status) — expected 204"
    Write-Host "       Response: $($r.Body)"
}

# ------------------------------------------------------------------------------
# Summary
# ------------------------------------------------------------------------------
Write-Host ""
Write-Host "Results: " -NoNewline
Write-Host "$script:PassCount passed" -ForegroundColor Green -NoNewline
Write-Host "  " -NoNewline
Write-Host "$script:FailCount failed" -ForegroundColor Red
Write-Host ""

exit $(if ($script:FailCount -eq 0) { 0 } else { 1 })

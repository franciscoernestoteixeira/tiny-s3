#!/usr/bin/env bash
# ==============================================================================
#  Tiny S3 — Bash test / validator
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
#  Requirements: bash, curl, openssl (for HMAC-SHA256 signing)
#
#  Usage:
#    chmod +x test.sh
#    ./test.sh                        # uses defaults below
#    ENDPOINT=http://myserver ./test.sh
# ==============================================================================

# ------------------------------------------------------------------------------
# Configuration — override any of these via environment variables:
#   ENDPOINT=http://myserver ACCESS_KEY=mykey SECRET_KEY=mysecret ./test.sh
# ------------------------------------------------------------------------------
ENDPOINT="${ENDPOINT:-http://localhost:9000}"
ACCESS_KEY="${ACCESS_KEY:-your-access-key-here}"
SECRET_KEY="${SECRET_KEY:-your-secret-key-here}"
REGION="${REGION:-us-east-1}"
BUCKET="${BUCKET:-test-bucket}"
OBJECT_KEY="${OBJECT_KEY:-hello/world.txt}"
OBJECT_BODY="Tiny S3 test payload — $(date -u)"

# ------------------------------------------------------------------------------
# Colour helpers (disabled automatically when not writing to a terminal)
# ------------------------------------------------------------------------------
if [ -t 1 ]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    CYAN='\033[0;36m'
    BOLD='\033[1m'
    RESET='\033[0m'
else
    GREEN='' RED='' CYAN='' BOLD='' RESET=''
fi

PASS=0
FAIL=0

# ------------------------------------------------------------------------------
# pass / fail helpers
# ------------------------------------------------------------------------------
pass() { echo -e "  ${GREEN}PASS${RESET}  $1"; ((PASS++)); }
fail() { echo -e "  ${RED}FAIL${RESET}  $1"; ((FAIL++)); }

step() { echo -e "\n${BOLD}${CYAN}▶ $1${RESET}"; }

# ------------------------------------------------------------------------------
# hmac_sha256 <key-bytes-as-hex> <data>
# Returns the HMAC-SHA256 as a lowercase hex string.
# The key is passed as raw bytes via a process substitution — openssl dgst
# requires binary input for the key when using -mac HMAC.
# ------------------------------------------------------------------------------
hmac_sha256() {
    local key_hex="$1"
    local data="$2"
    echo -n "$data" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$key_hex" \
        | sed 's/.*= //'
}

# hmac_sha256_binary <key-bytes-as-hex> <data>
# Same as above but returns raw binary (needed for chaining signing keys).
hmac_sha256_binary() {
    local key_hex="$1"
    local data="$2"
    echo -n "$data" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$key_hex" -binary \
        | xxd -p -c 256
}

# sha256 <data>
# Returns the SHA-256 hash of the given string as lowercase hex.
sha256() {
    echo -n "$1" | openssl dgst -sha256 | sed 's/.*= //'
}

# str_to_hex <string>
# Convert a plain string to hex (used to seed the signing key chain).
str_to_hex() {
    echo -n "$1" | xxd -p -c 256
}

# ------------------------------------------------------------------------------
# sign_and_request <METHOD> <path> [body]
#
# Builds a fully signed AWS Signature V4 request and executes it with curl.
# Prints the HTTP response body to stdout and exports HTTP_STATUS.
#
# The signing flow follows the AWS documentation exactly:
#   1. Canonical request  (method, path, query, headers, signed-headers, payload-hash)
#   2. String to sign     (algorithm, datetime, credential-scope, canonical-request-hash)
#   3. Signing key        (HMAC chain: secret → date → region → service → "aws4_request")
#   4. Signature          (HMAC of string-to-sign with signing key)
#   5. Authorization header assembled and passed to curl
# ------------------------------------------------------------------------------
sign_and_request() {
    local method="$1"
    local uri_path="$2"
    local body="${3:-}"

    local service="s3"
    local host
    host=$(echo "$ENDPOINT" | sed 's|https\?://||' | sed 's|/.*||')

    # Timestamps — AWS requires both a long form (ISO 8601) and short form (yyyymmdd)
    local amz_date
    amz_date=$(date -u '+%Y%m%dT%H%M%SZ')
    local date_stamp
    date_stamp=$(date -u '+%Y%m%d')

    # Hash the request body (empty string hash for bodyless requests)
    local payload_hash
    payload_hash=$(sha256 "$body")

    # --- Step 1: Canonical request ---
    # Headers must be sorted alphabetically and lowercased.
    # We sign: host, x-amz-content-sha256, x-amz-date
    local canonical_headers
    canonical_headers="host:${host}
x-amz-content-sha256:${payload_hash}
x-amz-date:${amz_date}
"                              # trailing newline is part of the canonical form

    local signed_headers="host;x-amz-content-sha256;x-amz-date"

    local canonical_request
    canonical_request="${method}
${uri_path}

${canonical_headers}
${signed_headers}
${payload_hash}"

    # --- Step 2: String to sign ---
    local credential_scope="${date_stamp}/${REGION}/${service}/aws4_request"
    local canonical_request_hash
    canonical_request_hash=$(sha256 "$canonical_request")

    local string_to_sign
    string_to_sign="AWS4-HMAC-SHA256
${amz_date}
${credential_scope}
${canonical_request_hash}"

    # --- Step 3: Signing key ---
    # Each HMAC step feeds its output as the key into the next step.
    local k_secret
    k_secret=$(str_to_hex "AWS4${SECRET_KEY}")
    local k_date
    k_date=$(hmac_sha256_binary "$k_secret" "$date_stamp")
    local k_region
    k_region=$(hmac_sha256_binary "$k_date" "$REGION")
    local k_service
    k_service=$(hmac_sha256_binary "$k_region" "$service")
    local k_signing
    k_signing=$(hmac_sha256_binary "$k_service" "aws4_request")

    # --- Step 4: Signature ---
    local signature
    signature=$(hmac_sha256 "$k_signing" "$string_to_sign")

    # --- Step 5: Authorization header ---
    local auth_header
    auth_header="AWS4-HMAC-SHA256 Credential=${ACCESS_KEY}/${credential_scope}, SignedHeaders=${signed_headers}, Signature=${signature}"

    # --- Execute with curl ---
    # -s  silent (no progress bar)
    # -o  write body to a temp file so we can inspect it separately from the status
    # -w  write just the HTTP status code to stdout after the body
    local tmp_body
    tmp_body=$(mktemp)

    HTTP_STATUS=$(curl -s -o "$tmp_body" -w "%{http_code}" \
        -X "$method" \
        "${ENDPOINT}${uri_path}" \
        -H "Host: ${host}" \
        -H "x-amz-date: ${amz_date}" \
        -H "x-amz-content-sha256: ${payload_hash}" \
        -H "Authorization: ${auth_header}" \
        ${body:+--data-raw "$body"})

    RESPONSE_BODY=$(cat "$tmp_body")
    rm -f "$tmp_body"
}

# ==============================================================================
# TEST SUITE
# ==============================================================================

echo -e "\n${BOLD}Tiny S3 — Bash validator${RESET}"
echo    "  Endpoint : $ENDPOINT"
echo    "  Bucket   : $BUCKET"
echo    "  Object   : $OBJECT_KEY"

# ------------------------------------------------------------------------------
step "1/7  PUT /$BUCKET  →  create bucket"
# ------------------------------------------------------------------------------
sign_and_request "PUT" "/${BUCKET}"
if [[ "$HTTP_STATUS" == "200" ]]; then
    pass "HTTP $HTTP_STATUS — bucket created"
elif [[ "$HTTP_STATUS" == "409" ]]; then
    pass "HTTP $HTTP_STATUS — bucket already exists (acceptable)"
else
    fail "HTTP $HTTP_STATUS — expected 200 or 409"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
step "2/7  PUT /$BUCKET/$OBJECT_KEY  →  upload object"
# ------------------------------------------------------------------------------
sign_and_request "PUT" "/${BUCKET}/${OBJECT_KEY}" "$OBJECT_BODY"
if [[ "$HTTP_STATUS" == "200" ]]; then
    pass "HTTP $HTTP_STATUS — object uploaded"
else
    fail "HTTP $HTTP_STATUS — expected 200"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
step "3/7  HEAD /$BUCKET/$OBJECT_KEY  →  object exists"
# ------------------------------------------------------------------------------
sign_and_request "HEAD" "/${BUCKET}/${OBJECT_KEY}"
if [[ "$HTTP_STATUS" == "200" ]]; then
    pass "HTTP $HTTP_STATUS — object found"
else
    fail "HTTP $HTTP_STATUS — expected 200"
fi

# ------------------------------------------------------------------------------
step "4/7  GET /$BUCKET  →  list bucket"
# ------------------------------------------------------------------------------
sign_and_request "GET" "/${BUCKET}"
if [[ "$HTTP_STATUS" == "200" ]]; then
    # Verify the key we uploaded actually appears in the listing
    if echo "$RESPONSE_BODY" | grep -q "$OBJECT_KEY"; then
        pass "HTTP $HTTP_STATUS — object key found in listing"
    else
        fail "HTTP $HTTP_STATUS — object key missing from listing"
        echo "       Response: $RESPONSE_BODY"
    fi
else
    fail "HTTP $HTTP_STATUS — expected 200"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
step "5/7  GET /$BUCKET/$OBJECT_KEY  →  download and verify"
# ------------------------------------------------------------------------------
sign_and_request "GET" "/${BUCKET}/${OBJECT_KEY}"
if [[ "$HTTP_STATUS" == "200" ]]; then
    if [[ "$RESPONSE_BODY" == "$OBJECT_BODY" ]]; then
        pass "HTTP $HTTP_STATUS — content matches"
    else
        fail "HTTP $HTTP_STATUS — content mismatch"
        echo "       Expected : $OBJECT_BODY"
        echo "       Got      : $RESPONSE_BODY"
    fi
else
    fail "HTTP $HTTP_STATUS — expected 200"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
step "6/7  DELETE /$BUCKET/$OBJECT_KEY  →  delete object"
# ------------------------------------------------------------------------------
sign_and_request "DELETE" "/${BUCKET}/${OBJECT_KEY}"
if [[ "$HTTP_STATUS" == "204" ]]; then
    pass "HTTP $HTTP_STATUS — object deleted"
else
    fail "HTTP $HTTP_STATUS — expected 204"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
step "7/7  DELETE /$BUCKET  →  delete bucket"
# ------------------------------------------------------------------------------
sign_and_request "DELETE" "/${BUCKET}"
if [[ "$HTTP_STATUS" == "204" ]]; then
    pass "HTTP $HTTP_STATUS — bucket deleted"
else
    fail "HTTP $HTTP_STATUS — expected 204"
    echo "       Response: $RESPONSE_BODY"
fi

# ------------------------------------------------------------------------------
# Summary
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}Results: ${GREEN}${PASS} passed${RESET}  ${RED}${FAIL} failed${RESET}\n"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1

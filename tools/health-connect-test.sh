#!/usr/bin/env bash
#
# The whole Health Connect server flow, over HTTP, without an Android phone.
# ---------------------------------------------------------------------------
#
#   tools/health-connect-test.sh                       # against localhost
#   BASE_URL=https://your-domain.tld tools/health-connect-test.sh
#
# Every request here is one the phone app will make, in the order it will make
# them, through the same endpoints with the same authentication. Nothing is
# stubbed and nothing is bypassed: the account is created through the public
# registration endpoint, the pairing code is minted through the signed-in one,
# and the device token is whatever the server issues.
#
# WHAT IT LEAVES BEHIND
# ---------------------------------------------------------------------------
# A real account, with a name that starts with `hctest_`, holding one night of
# sleep, one workout, one meal and a few metrics — all dated today. It removes
# the account again at the end, including everything hanging off it. Run with
# KEEP=1 to keep it and look at it in phpMyAdmin.
#
# Nothing in here is a production secret. The password below is a test
# password for a throwaway account, and it is in the repository on purpose.
#
# OPTIONS (environment variables)
# ---------------------------------------------------------------------------
#   BASE_URL   where the site is            default http://127.0.0.1:8000
#   KEEP=1     do not remove the account afterwards
#   NO_DB=1    skip the table checks, test the HTTP contract only
#
# The table checks need this checkout's own database credentials, so they only
# work where the app itself works — on the server, or on a dev machine. Over
# the network to somebody else's server, use NO_DB=1.

set -u

BASE_URL="${BASE_URL:-${1:-http://127.0.0.1:8000}}"
BASE_URL="${BASE_URL%/}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JAR="$(mktemp)"
PASS=0
FAIL=0

# A name nobody will mistake for a real account, and a fresh one per run so
# repeating the test never collides with a previous one it could not clean up.
USER_NAME="hctest_$(date +%Y%m%d_%H%M%S)"
USER_MAIL="${USER_NAME}@ownify-test.invalid"          # .invalid can never be a real domain
USER_PASS="health-connect-test-password"            # a test password, not a secret

cleanup_files() { rm -f "$JAR"; }
trap cleanup_files EXIT

ok()   { PASS=$((PASS + 1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL  %s\n          %s\n' "$1" "$2"; }
same() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "got [$2] want [$3]"; }
has()  { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "no [$3] in: $2" ;; esac; }

# Reading JSON with grep is how a test starts passing for the wrong reason.
# PHP is already a requirement here, so use it.
field() {
    php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $k = $argv[1];
        if (!is_array($d) || !array_key_exists($k, $d)) { exit; }
        echo is_scalar($d[$k]) ? (string) $d[$k] : json_encode($d[$k]);
    ' "$1"
}

# The CSRF token the browser would read out of the page.
csrf() {
    curl -s -b "$JAR" -c "$JAR" "$BASE_URL/" \
        | grep -o 'data-csrf="[^"]*"' | head -1 | cut -d'"' -f2
}

status_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

verify() {
    [ -n "${NO_DB:-}" ] && { printf '  ----  database checks skipped (NO_DB=1)\n'; return; }
    if php "$ROOT/tools/hc-verify.php" --user="$USER_NAME" --phase="$1"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
    fi
}

command -v curl >/dev/null || { echo "curl is required."; exit 2; }
command -v php  >/dev/null || { echo "php is required (it builds the test batch)."; exit 2; }

echo
echo "Health Connect server flow"
echo "  against $BASE_URL"
echo "  as      $USER_NAME"
echo "------------------------------------------------------------------------"

# --------------------------------------------------------------- reachable

REACH="$(status_of "$BASE_URL/")"
if [ "$REACH" != "200" ]; then
    echo "  The site did not answer 200 at $BASE_URL (got $REACH). Nothing else can run."
    exit 2
fi

# ------------------------------------------------- somebody to pair with

echo "== a person signs up on the website =="
BODY="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/auth/register.php" \
    --data-urlencode "csrf=$(csrf)" \
    --data-urlencode "username=$USER_NAME" \
    --data-urlencode "email=$USER_MAIL" \
    --data-urlencode "password=$USER_PASS")"
has "the test account exists" "$BODY" '"ok":true'

if [ "$(echo "$BODY" | field ok)" != "1" ]; then
    echo "  Cannot continue without an account."
    exit 1
fi

# A new account starts with its setup (includes/setup.php): finished at once,
# so the pages below are the app's. Without migration 016 there is none (503).
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/setup/finish.php" \
    --data-urlencode "csrf=$(csrf)" > /dev/null

# --------------------------------------------------- 1. the pairing code

echo "== 1. the website mints a pairing code =="
BODY="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/integrations/pairing-code.php" \
    --data-urlencode "csrf=$(csrf)" \
    --data-urlencode "provider=google_health_connect")"
has "a code was issued" "$BODY" '"ok":true'

CODE="$(echo "$BODY" | field code)"
same "  it is 8 characters" "${#CODE}" "8"
same "  it expires in ten minutes" "$(echo "$BODY" | field expires_in)" "600"

echo "== a cloud source has nothing to pair with =="
has "google_health refuses a code" \
    "$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/integrations/pairing-code.php" \
        --data-urlencode "csrf=$(csrf)" --data-urlencode "provider=google_health")" \
    '"ok":false'


# ----------------------------------------------- 2,3. pair, get the token

echo "== 2. the phone exchanges it — no session, no CSRF =="
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/pair.php" \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE\",\"label\":\"Ownify test client\",\"platform\":\"curl\",\"app_version\":\"test\"}")"
has "paired" "$BODY" '"ok":true'

echo "== 3. it receives a device token, once =="
TOKEN="$(echo "$BODY" | field token)"
same "  64 hex characters" "${#TOKEN}" "64"
same "  for the right provider" "$(echo "$BODY" | field provider)" "google_health_connect"

echo "== the code cannot be used a second time =="
has "the replay is refused" \
    "$(curl -s -X POST "$BASE_URL/api/integrations/pair.php" -H 'Content-Type: application/json' \
        -d "{\"code\":\"$CODE\"}")" \
    '"ok":false'

echo "== a guessed code learns nothing =="
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/pair.php" -H 'Content-Type: application/json' \
    -d '{"code":"ZZZZZZZZ"}')"
has "refused" "$BODY" '"ok":false'
has "  with the same words as an expired one" "$BODY" 'niet geldig of verlopen'

# --------------------------------------------- 4,5. bearer token + batch

echo "== 4,5. the phone posts a batch with its token =="
BATCH="$(php "$ROOT/tools/hc-verify.php" --fixture)"
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/ingest.php" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    --data-binary "$BATCH")"
has "accepted" "$BODY" '"ok":true'
same "  7 of the 8 records written" "$(echo "$BODY" | field written)" "7"
same "  none skipped" "$(echo "$BODY" | field skipped)" "0"
has "  the 8th is reported, not dropped" "$BODY" 'MenstruationFlow'

# --------------------------------------------- the app checks its token

echo "== the app asks whether it is still paired, without sending anything =="
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/status.php" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}')"
has "it is" "$BODY" '"ok":true'
same "  for the right provider" "$(echo "$BODY" | field provider)" "google_health_connect"
same "  and it knows the phone" "$(echo "$BODY" | field label)" "Ownify test client"
has "  it says nothing about the account" "$(echo "$BODY" | grep -c 'email\|username\|user_id' || true)" "0"
same "no token -> 401" \
    "$(status_of -X POST "$BASE_URL/api/integrations/status.php" \
        -H 'Content-Type: application/json' -d '{}')" "401"

# ------------------------------------------------- 6. the right tables

echo "== 6. the records became Ownify rows =="
verify imported

# ----------------------------------------------- 7,8. the same batch again

echo "== 7,8. sending the identical batch again =="
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/ingest.php" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    --data-binary "$BATCH")"
has "accepted again" "$BODY" '"ok":true'
verify replayed

# ------------------------------------------------------ 9,10. empty batch

echo "== 9,10. a phone with nothing new to send =="
BODY="$(curl -s -X POST "$BASE_URL/api/integrations/ingest.php" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"records":[]}')"
has "is a success, not an error" "$BODY" '"ok":true'
same "  and wrote nothing" "$(echo "$BODY" | field written)" "0"

# --------------------------------------------------- credentials that fail

echo "== credentials that should get nowhere =="
same "no token -> 401" \
    "$(status_of -X POST "$BASE_URL/api/integrations/ingest.php" \
        -H 'Content-Type: application/json' -d '{"records":[]}')" "401"
same "an unknown token -> 401" \
    "$(status_of -X POST "$BASE_URL/api/integrations/ingest.php" \
        -H "Authorization: Bearer $(printf 'a%.0s' $(seq 64))" \
        -H 'Content-Type: application/json' -d '{"records":[]}')" "401"
same "a token that is not even the right shape -> 401" \
    "$(status_of -X POST "$BASE_URL/api/integrations/ingest.php" \
        -H 'Authorization: Bearer not-a-token' \
        -H 'Content-Type: application/json' -d '{"records":[]}')" "401"

# ------------------------------------------- one phone, not every phone

echo "== the owner can see the phone on the settings page =="
PAGE="$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/?page=settings")"
has "it is listed by name" "$PAGE" 'Ownify test client'
has "  with its own revoke button" "$PAGE" 'data-device-revoke'
has "  and never a token" "$(echo "$PAGE" | grep -c "$TOKEN" || true)" "0"

DEVICE_ID="$(echo "$PAGE" | grep -o 'data-device-revoke="[0-9]*"' | head -1 | tr -dc '0-9')"

echo "== somebody else's device id revokes nothing =="
same "an id that is not yours -> 404" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
        -X POST "$BASE_URL/api/integrations/device-revoke.php" \
        --data-urlencode "csrf=$(csrf)" --data-urlencode "device=999999")" "404"
same "  and the phone still works" \
    "$(status_of -X POST "$BASE_URL/api/integrations/status.php" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}')" "200"

echo "== a second phone is paired, then only that one is revoked =="
CODE2="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/integrations/pairing-code.php" \
    --data-urlencode "csrf=$(csrf)" --data-urlencode "provider=google_health_connect" | field code)"
TOKEN2="$(curl -s -X POST "$BASE_URL/api/integrations/pair.php" -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE2\",\"label\":\"Second test phone\",\"platform\":\"curl\"}" | field token)"
same "the second phone paired" "${#TOKEN2}" "64"

PAGE="$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/?page=settings")"
same "both phones are listed" "$(echo "$PAGE" | grep -c 'data-device-revoke')" "2"

ID2="$(echo "$PAGE" | grep -o 'data-device-revoke="[0-9]*"' | tail -1 | tr -dc '0-9')"
has "revoking the second one" \
    "$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/integrations/device-revoke.php" \
        --data-urlencode "csrf=$(csrf)" --data-urlencode "device=$ID2")" '"ok":true'

# The whole point of a token per device: losing a phone costs you that phone.
same "  the second phone is refused" \
    "$(status_of -X POST "$BASE_URL/api/integrations/status.php" \
        -H "Authorization: Bearer $TOKEN2" -H 'Content-Type: application/json' -d '{}')" "401"
same "  the FIRST phone still syncs" \
    "$(status_of -X POST "$BASE_URL/api/integrations/status.php" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}')" "200"
same "  and revoking it twice changes nothing" \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
        -X POST "$BASE_URL/api/integrations/device-revoke.php" \
        --data-urlencode "csrf=$(csrf)" --data-urlencode "device=$ID2")" "404"

# ---------------------------------------------------- 11,12,13. disconnect

echo "== 11. the owner disconnects in Settings =="
BODY="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/integrations/disconnect.php" \
    --data-urlencode "csrf=$(csrf)" \
    --data-urlencode "provider=google_health_connect")"
has "disconnected" "$BODY" '"ok":true'
same "  one phone was revoked" "$(echo "$BODY" | field devices_revoked)" "1"

echo "== 12. the phone's token is dead =="
same "the same token -> 401" \
    "$(status_of -X POST "$BASE_URL/api/integrations/ingest.php" \
        -H "Authorization: Bearer $TOKEN" \
        -H 'Content-Type: application/json' -d '{"records":[]}')" "401"

echo "== 13. but the health data it already sent is still there =="
verify disconnected

# ------------------------------------------------------------- tidying up

echo
if [ -n "${KEEP:-}" ]; then
    echo "  Kept $USER_NAME. Remove it with:"
    echo "    php tools/hc-verify.php --user=$USER_NAME --cleanup"
elif [ -n "${NO_DB:-}" ]; then
    echo "  NO_DB=1, so $USER_NAME is still there. Remove it on the server with:"
    echo "    php tools/hc-verify.php --user=$USER_NAME --cleanup"
else
    php "$ROOT/tools/hc-verify.php" --user="$USER_NAME" --cleanup
fi

echo "------------------------------------------------------------------------"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ] || exit 1

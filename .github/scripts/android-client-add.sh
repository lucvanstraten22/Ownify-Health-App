#!/usr/bin/env bash
# Adds one Android OAuth client id to google.android_client_ids in the live
# config/auth.local.php, over the deploy's SFTP login (it has no shell):
#
#   1. the file is fetched into a 700 temp folder on the runner — never printed;
#   2. android-client-edit.php adds the id as text and proves that nothing else
#      changed (both copies loaded and compared), and that it is valid PHP;
#   3. on the server: the edited copy goes up beside it, the current file is
#      kept as auth.local.backup-<time>.php (PHP, so it shows nothing over
#      HTTPS), the copy takes its place with the same permissions;
#   4. a one-time PHP file with a random name (answers only to a random token,
#      deletes itself first) reports what the site now loads: the Android
#      client ids (not secret), and only yes/no for the Web client and secret.
#
# Needs: SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH CLIENT_ID;
#        optional SFTP_PORT (22), PUBLIC_URL (https://ownify.acits.nl).
set -euo pipefail
umask 077

fail() { echo "::error title=Android client not added::$1"; exit 1; }

for v in SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH CLIENT_ID; do
    [ -n "${!v:-}" ] || fail "$v is empty."
done
public_url="${PUBLIC_URL:-https://ownify.acits.nl}"
here="$(cd "$(dirname "$0")" && pwd)"

work="$(mktemp -d)"
root="${SFTP_REMOTE_PATH%/}"
echo "::add-mask::$root"
printf '%s\n' "$SFTP_PRIVATE_KEY" > "$work/key"
sftp_run() {
    sftp -q -b "$1" -i "$work/key" -P "${SFTP_PORT:-22}" \
        -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$work/known_hosts" \
        -o LogLevel=ERROR "$SFTP_USERNAME@$SFTP_SERVER"
}

name="ownify-ac-probe-$(openssl rand -hex 16).php"
token="$(openssl rand -hex 32)"
echo "::add-mask::$token"
cleanup() {
    printf -- '-rm "%s/%s"\n-rm "%s/config/auth.local.next.php"\n' "$root" "$name" "$root" > "$work/b-rm"
    sftp_run "$work/b-rm" > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

live="$root/config/auth.local.php"

# --- 1. fetch, and its permissions --------------------------------------
printf 'get "%s" "%s"\n' "$live" "$work/current.php" > "$work/b-get"
sftp_run "$work/b-get" > /dev/null 2>&1 || fail "Could not fetch config/auth.local.php."
printf 'ls -l "%s"\n' "$live" > "$work/b-ls"
perm="$(sftp_run "$work/b-ls" 2>/dev/null | awk 'NF > 1 { print $1; exit }')"
mode=0
for i in 1 2 3 4 5 6 7 8 9; do
    c="${perm:$i:1}"; bit=$(( 1 << (9 - i) ))
    [ "$c" != "-" ] && [ -n "$c" ] && mode=$(( mode | bit ))
done
mode="$(printf '%o' "$mode")"
[ "$mode" != "0" ] || fail "Could not read the file's permissions."
echo "  fetched config/auth.local.php (mode $mode)"

# --- 2. edit and prove ----------------------------------------------------
set +e
php "$here/android-client-edit.php" "$work/current.php" "$work/next.php" "$CLIENT_ID"
edit=$?
set -e
case "$edit" in
    0) ;;
    3) echo "  nothing to upload" ;;
    *) fail "The edit was refused (above); the live file is untouched." ;;
esac

# --- 3. swap on the server, the old one kept -------------------------------
if [ "$edit" = 0 ]; then
    backup="auth.local.backup-$(date -u +%Y%m%dT%H%M%SZ).php"
    {
        printf 'put "%s" "%s/config/auth.local.next.php"\n' "$work/next.php" "$root"
        printf 'chmod %s "%s/config/auth.local.next.php"\n' "$mode" "$root"
        printf 'rename "%s" "%s/config/%s"\n' "$live" "$root" "$backup"
        printf 'rename "%s/config/auth.local.next.php" "%s"\n' "$root" "$live"
    } > "$work/b-swap"
    if ! sftp_run "$work/b-swap" > /dev/null 2>&1; then
        printf 'rename "%s/config/%s" "%s"\n' "$root" "$backup" "$live" > "$work/b-undo"
        sftp_run "$work/b-undo" > /dev/null 2>&1 || true
        fail "The swap on the server failed; the old file was put back if it had moved."
    fi
    echo "  live file replaced; the previous one kept as config/$backup"
fi

# --- 4. what the site loads now ---------------------------------------------
cat > "$work/probe.php" <<PHP
<?php
// One-time look at the Google sign-in configuration (.github/scripts/android-client-add.sh).
declare(strict_types=1);
if (!hash_equals('$token', (string) (\$_SERVER['HTTP_X_OWNIFY_PROBE'] ?? ''))) {
    http_response_code(404);
    exit;
}
@unlink(__FILE__);
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/config/auth.local.php', true);
    @opcache_invalidate(__DIR__ . '/config/auth.php', true);
}
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/google-signin.php';
\$c = google_signin_config();
\$project = static fn (string \$id): string => (string) strstr(\$id, '-', true);
echo json_encode([
    'web_client_set'      => \$c['client_id'] !== '',
    'web_client_project'  => \$c['client_id'] !== '' ? \$project(\$c['client_id']) : null,
    'client_secret_set'   => \$c['client_secret'] !== '',
    'redirect_uri_set'    => \$c['redirect_uri'] !== '',
    'android_client_ids'  => \$c['android_client_ids'],
    'env_override'        => getenv('GOOGLE_SIGNIN_ANDROID_CLIENT_IDS') !== false,
    'web_signin_configured' => google_signin_configured(),
    'app_signin_configured' => google_signin_native_configured(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
PHP
printf 'put "%s" "%s/%s"\n' "$work/probe.php" "$root" "$name" > "$work/b-put"
sftp_run "$work/b-put" > /dev/null 2>&1 || fail "Could not put the one-time probe in the web root."
code="$(curl -sS -o "$work/answer.json" -w '%{http_code}' --max-time 60 -H "X-Ownify-Probe: $token" "$public_url/$name")"
[ "$code" = 200 ] || fail "The probe answered $code."
echo "--- what the site loads ---"
cat "$work/answer.json"; echo
jq -e --arg id "$CLIENT_ID" '.android_client_ids | index($id) != null' "$work/answer.json" > /dev/null \
    || fail "The site does not load the new client id."
jq -e '.web_client_set and .client_secret_set' "$work/answer.json" > /dev/null \
    || fail "The Web client or its secret is not loaded."

# The app's own question: is Google sign-in offered? (status: no attempt used up)
status="$(curl -sS -o "$work/status.json" -w '%{http_code}' --max-time 30 -X POST \
    -H 'Content-Type: application/json' -d '{"action":"status"}' "$public_url/api/auth/app-google.php")"
echo "--- app-google.php status: HTTP $status ---"
jq '{available: .available}' "$work/status.json" || true
[ "$status" = 200 ] && jq -e '.available == true' "$work/status.json" > /dev/null \
    || fail "app-google.php does not offer Google sign-in to the app."
echo "OK: the new Android client id is active."

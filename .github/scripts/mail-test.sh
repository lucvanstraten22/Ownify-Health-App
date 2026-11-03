#!/usr/bin/env bash
# Sends the first real test message FROM THE WEBSITE ITSELF, after
# mail-provision.sh (docs/MAIL.md). The deploy's SFTP login has no shell, so
# tools/mail-test.php cannot be run there; instead:
#
#   1. a one-time PHP file with a random name is put in the web root. It
#      answers only to a random token sent in a header (anything else: 404),
#      deletes itself before it does anything, and can send only the test
#      message (mail_send_test()) to the one address written into it;
#   2. it is called once over HTTPS: it reads the real settings as the site
#      does (PHP-FPM, open_basedir and all), connects to Gmail, logs in and
#      sends;
#   3. it is removed over SFTP as well, whatever happened.
#
# It says where the password was read from, whether the login worked, and
# whether Gmail accepted the message — never the password, the address or
# the paths. Exit 0 only when Gmail accepted it.
#
# Needs: jq, and SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH
#        MAIL_TEST_TO; optional SFTP_PORT (22), PUBLIC_URL
#        (https://ownify.acits.nl), SFTP_KNOWN_HOSTS_FILE, CURL_ARGS.
set -euo pipefail
umask 077

fail() { echo "::error title=Test e-mail::$1"; exit 1; }

for v in SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH MAIL_TEST_TO; do
    [ -n "${!v:-}" ] || fail "$v is empty."
done
echo "::add-mask::$MAIL_TEST_TO"
[[ "$MAIL_TEST_TO" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || fail "The test address is not an e-mail address."
public_url="${PUBLIC_URL:-https://ownify.acits.nl}"

work="$(mktemp -d)"
root="${SFTP_REMOTE_PATH%/}"
echo "::add-mask::$root"
printf '%s\n' "$SFTP_PRIVATE_KEY" > "$work/key"
known="${SFTP_KNOWN_HOSTS_FILE:-$work/known_hosts}"
sftp_run() {
    sftp -q -b "$1" -i "$work/key" -P "${SFTP_PORT:-22}" \
        -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$known" \
        -o LogLevel=ERROR "$SFTP_USERNAME@$SFTP_SERVER"
}

name="ownify-mail-probe-$(openssl rand -hex 16).php"
token="$(openssl rand -hex 32)"
echo "::add-mask::$token"
to_b64="$(printf '%s' "$MAIL_TEST_TO" | base64 -w0)"

cleanup() {
    printf -- '-rm "%s/%s"\n' "$root" "$name" > "$work/b-rm"
    sftp_run "$work/b-rm" > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

cat > "$work/probe.php" <<PHP
<?php
// One-time test of Ownify's mail from the website itself (.github/scripts/mail-test.sh).
declare(strict_types=1);
if (!hash_equals('$token', (string) (\$_SERVER['HTTP_X_OWNIFY_PROBE'] ?? ''))) {
    http_response_code(404);
    exit;
}
@unlink(__FILE__);
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__ . '/includes/mailer.php';
\$s = mail_settings();
\$from = array_map(static fn (\$p) => str_starts_with(\$p, '\$') ? 'env' : (str_contains(\$p, '/private/') ? 'private' : 'config'), \$s['_sources']);
\$reason = mail_transport_reason();
\$check = \$reason === null ? mail_smtp_check() : ['ok' => false, 'stage' => 'config', 'code' => 'not-configured'];
\$sent = \$check['ok'] ? mail_send_test(base64_decode('$to_b64')) : null;
echo json_encode(['read_from' => \$from, 'configured' => \$reason === null, 'login' => \$check, 'send' => \$sent]);
PHP

printf 'put "%s" "%s/%s"\n' "$work/probe.php" "$root" "$name" > "$work/b-put"
sftp_run "$work/b-put" > /dev/null 2>&1 || fail "Could not put the one-time test file in the web root."

code="$(curl -sS ${CURL_ARGS:-} -o "$work/answer.json" -w '%{http_code}' --max-time 90 \
    -H "X-Ownify-Probe: $token" "$public_url/$name" || echo 000)"
[ "$code" = "200" ] || fail "The website answered $code to the one-time test (it was removed again)."

j() { jq -r "$1" "$work/answer.json" 2>/dev/null || echo "?"; }
read_from="$(j '(.read_from // []) | if length == 0 then "nowhere" else join(", ") end')"
configured="$(j 'if .configured then "yes" else "no" end')"
login="$(j 'if .login.ok then "ok" else "FAILED at \(.login.stage) (\(.login.code))" end')"
send="$(j 'if .send == null then "not tried" elif .send.ok then "accepted" else "FAILED at \(.send.stage) (\(.send.code))" end')"

echo "  the website read the settings from: $read_from"
echo "  configured: $configured"
echo "  connect, STARTTLS, login to Gmail: $login"
echo "  test message: $send"

[ "$configured" = "yes" ] || fail "The website found no App Password (read from: $read_from). If it was put in private/, PHP on this server may not be allowed there (open_basedir): see docs/MAIL.md."
[ "$send" = "accepted" ] || fail "Gmail did not accept the test message ($login / $send). See docs/MAIL.md."
echo "Gmail accepted the test message. Check the inbox (and the spam folder the first time)."

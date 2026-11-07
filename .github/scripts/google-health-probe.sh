#!/usr/bin/env bash
# Looks at Google Health on the live site, read-only, the way mail-test.sh
# sends its test message (the deploy's SFTP login has no shell):
#
#   1. a one-time PHP file with a random name is put in the web root. It
#      answers only to a random token sent in a header (anything else: 404)
#      and deletes itself before it does anything;
#   2. it is called once over HTTPS and reports, as the site sees them:
#      PHP's limits, every Google Health connection's state (never a token,
#      an e-mail address or a user id — only "has a refresh token", the
#      expiry and the granted scopes), the last [google-health] lines and
#      fatal errors of PHP's error log, and — with PROBE_API=1 — one real
#      request per data type to the Google Health API for the first
#      connection: the HTTP status, Google's error status, how many points
#      and bytes, and whether there are more pages. Never a value;
#   3. it is removed over SFTP as well, whatever happened.
#
# Writes nothing to the database except what any sync does when the access
# token has run out (a refreshed token, stored as always).
#
# Needs: jq, and SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH;
#        optional SFTP_PORT (22), PUBLIC_URL (https://ownify.acits.nl), PROBE_API.
set -euo pipefail
umask 077

fail() { echo "::error title=Google Health probe::$1"; exit 1; }

for v in SFTP_SERVER SFTP_USERNAME SFTP_PRIVATE_KEY SFTP_REMOTE_PATH; do
    [ -n "${!v:-}" ] || fail "$v is empty."
done
public_url="${PUBLIC_URL:-https://ownify.acits.nl}"
api="${PROBE_API:-0}"

work="$(mktemp -d)"
root="${SFTP_REMOTE_PATH%/}"
echo "::add-mask::$root"
printf '%s\n' "$SFTP_PRIVATE_KEY" > "$work/key"
known="$work/known_hosts"
sftp_run() {
    sftp -q -b "$1" -i "$work/key" -P "${SFTP_PORT:-22}" \
        -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$known" \
        -o LogLevel=ERROR "$SFTP_USERNAME@$SFTP_SERVER"
}

name="ownify-gh-probe-$(openssl rand -hex 16).php"
token="$(openssl rand -hex 32)"
echo "::add-mask::$token"

cleanup() {
    printf -- '-rm "%s/%s"\n' "$root" "$name" > "$work/b-rm"
    sftp_run "$work/b-rm" > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

cat > "$work/probe.php" <<PHP
<?php
// One-time, read-only look at Google Health (.github/scripts/google-health-probe.sh).
declare(strict_types=1);
if (!hash_equals('$token', (string) (\$_SERVER['HTTP_X_OWNIFY_PROBE'] ?? ''))) {
    http_response_code(404);
    exit;
}
@unlink(__FILE__);
header('Content-Type: application/json');
header('Cache-Control: no-store');
@set_time_limit(170);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/integrations.php';
require_once __DIR__ . '/includes/google-health.php';

\$out = ['php' => [
    'sapi' => PHP_SAPI, 'version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
    'fastcgi_finish_request' => function_exists('fastcgi_finish_request'), 'curl' => function_exists('curl_init'),
]];
\$out['problem'] = google_health_problem();
\$out['config'] = array_intersect_key(google_health_config(), array_flip(['initial_days', 'overlap_days', 'scopes']));

\$rows = db_all("SELECT user_id, provider, status, scopes, token_expires_at, connected_at, last_sync_at, sync_started_at,
                        last_sync_status, last_error, refresh_token IS NOT NULL AS has_refresh, updated_at
                   FROM user_integrations WHERE provider IN ('google_health', 'google_health_connect', 'polar') ORDER BY provider, id");
\$first = null;
\$out['connections'] = [];
foreach (\$rows as \$i => \$r) {
    if (\$r['provider'] === 'google_health' && \$first === null && in_array(\$r['status'], ['connected', 'error'], true)) {
        \$first = (int) \$r['user_id'];
    }
    \$same = db_value("SELECT COUNT(*) FROM user_integrations WHERE user_id = ? AND provider IN ('google_health', 'google_health_connect')", [\$r['user_id']]);
    unset(\$r['user_id']);
    \$r['gh_and_hc_rows_for_this_user'] = (int) \$same;
    \$out['connections'][] = \$r;
}
\$src = db_value("SELECT id FROM data_sources WHERE code = 'google_health'");
\$out['stored_from_google_health'] = \$src === null ? 'no source row' : [
    'metrics'  => (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE source_id = ?', [\$src]),
    'sleep'    => (int) db_value('SELECT COUNT(*) FROM sleep_sessions WHERE source_id = ?', [\$src]),
    'workouts' => (int) db_value('SELECT COUNT(*) FROM workouts WHERE source_id = ?', [\$src]),
];

/* PHP's error log: the last lines about Google Health, and every fatal error. */
\$log = (string) ini_get('error_log');
foreach (array_merge(\$log !== '' ? [\$log] : [], (array) @glob(dirname(__DIR__) . '/logs/*error*.log')) as \$candidate) {
    if (is_string(\$candidate) && @is_readable(\$candidate)) { \$log = \$candidate; break; }
}
\$out['error_log'] = ['set' => \$log !== '', 'readable' => \$log !== '' && is_readable(\$log)];
if (\$out['error_log']['readable']) {
    \$size = (int) @filesize(\$log);
    \$fh = fopen(\$log, 'rb');
    fseek(\$fh, max(0, \$size - 4000000));
    \$lines = explode("\n", (string) stream_get_contents(\$fh));
    fclose(\$fh);
    \$hits = array_values(array_filter(\$lines, static fn (\$l) =>
        stripos(\$l, 'google-health') !== false || stripos(\$l, 'google_health') !== false
        || stripos(\$l, 'Allowed memory') !== false || stripos(\$l, 'Maximum execution time') !== false
        || stripos(\$l, 'PHP Fatal') !== false));
    \$out['error_log']['lines'] = array_map(static fn (\$l) => mb_substr(preg_replace('~(ya29|1//)[A-Za-z0-9._-]+~', '…', \$l), 0, 400), array_slice(\$hits, -60));
}

/* One real request per data type, for the first connection. */
if ('$api' === '1' && \$first !== null) {
    \$ctx = ['user_id' => \$first, 'token' => null, 'requests' => 0, 'limited' => false, 'revoked' => false, 'budget' => 100];
    \$access = google_health_access_token(\$first);
    \$out['api'] = ['token' => \$access['token'] !== null ? 'ok' : \$access['problem']];
    \$from = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
    if (\$access['token'] !== null) {
        foreach (google_health_types() as \$type => [\$kind]) {
            \$size = in_array(\$type, ['exercise', 'sleep'], true) ? 25 : 10000;
            \$url = rtrim(google_health_config()['api_base'], '/') . '/users/me/dataTypes/' . rawurlencode(\$type) . '/dataPoints?'
                . http_build_query(['filter' => google_health_filter(\$type, \$kind, \$from), 'pageSize' => \$size], '', '&', PHP_QUERY_RFC3986);
            \$t = microtime(true);
            \$res = google_health_http('GET', \$url, ['Authorization: Bearer ' . \$access['token'], 'Accept: application/json'], null, 60);
            \$ms = (int) round((microtime(true) - \$t) * 1000);
            if (\$res === null) { \$out['api'][\$type] = ['http' => 0, 'ms' => \$ms]; continue; }
            \$data = json_decode(\$res['body'], true);
            \$entry = ['http' => \$res['status'], 'ms' => \$ms, 'bytes' => strlen(\$res['body'])];
            if (\$res['status'] === 200) {
                \$points = (array) (\$data['dataPoints'] ?? []);
                \$entry['points'] = count(\$points);
                \$entry['more_pages'] = !empty(\$data['nextPageToken']);
                \$entry['top_keys'] = array_keys((array) \$data);
                \$entry['point_keys'] = isset(\$points[0]) && is_array(\$points[0]) ? array_keys(\$points[0]) : [];
                \$entry['platforms'] = array_values(array_unique(array_map(static fn (\$p) => (string) (\$p['dataSource']['platform'] ?? '?'), \$points)));
                \$mapped = 0;
                foreach (array_slice(\$points, 0, 50) as \$p) { if (is_array(\$p) && google_health_map_point(\$type, \$p) !== null) { \$mapped++; } }
                \$entry['mapped_of_first_50'] = \$type === 'heart-rate' ? 'n/a' : \$mapped;
                /* The shape of one point, keys only, two levels deep. */
                if (isset(\$points[0]) && is_array(\$points[0])) {
                    \$shape = [];
                    foreach (\$points[0] as \$k => \$v) { \$shape[\$k] = is_array(\$v) ? array_keys(\$v) : gettype(\$v); }
                    \$entry['shape'] = \$shape;
                }
            } else {
                \$entry['error'] = [
                    'status' => \$data['error']['status'] ?? null,
                    'message' => mb_substr((string) (\$data['error']['message'] ?? ''), 0, 300),
                    'reason' => \$data['error']['details'][0]['reason'] ?? null,
                ];
            }
            \$out['api'][\$type] = \$entry;
        }
    }
}
echo json_encode(\$out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP

printf 'put "%s" "%s/%s"\n' "$work/probe.php" "$root" "$name" > "$work/b-put"
sftp_run "$work/b-put" > /dev/null 2>&1 || fail "Could not put the one-time probe in the web root."

code="$(curl -sS -o "$work/answer.json" -w '%{http_code}' --max-time 180 \
    -H "X-Ownify-Probe: $token" "$public_url/$name" || echo 000)"
echo "HTTP $code"
cat "$work/answer.json" || true
echo
[ "$code" = "200" ] || fail "The website answered $code to the probe (it was removed again)."

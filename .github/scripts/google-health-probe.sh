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
# token has run out (a refreshed token, stored as always) — and, with
# PROBE_SYNC=1, one real sync per connection, exactly as the scheduled run.
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
sync="${PROBE_SYNC:-0}"

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
@set_time_limit(900);
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

/* Stored nights of the last 30 days per source, averages only (no person): how
   much of each stage, and what was stored as awake. */
\$out['stored_sleep_last_30_days'] = [];
foreach (db_all("SELECT COALESCE(ds.code, 'none') AS source, COUNT(*) AS nights, ROUND(AVG(s.awake_minutes)) AS awake_stored,
                        ROUND(AVG(s.duration_minutes)) AS asleep, ROUND(AVG(s.time_in_bed_minutes)) AS in_bed, ROUND(AVG(s.efficiency_pct), 1) AS efficiency
                   FROM sleep_sessions s LEFT JOIN data_sources ds ON ds.id = s.source_id
                  WHERE s.night_of >= CURDATE() - INTERVAL 30 DAY GROUP BY ds.code") as \$r) {
    \$per = [];
    foreach (db_all("SELECT st.stage, ROUND(SUM(TIMESTAMPDIFF(SECOND, st.started_at, st.ended_at)) / 60 / COUNT(DISTINCT s.id)) AS minutes_per_night
                       FROM sleep_stages st JOIN sleep_sessions s ON s.id = st.sleep_session_id LEFT JOIN data_sources ds ON ds.id = s.source_id
                      WHERE s.night_of >= CURDATE() - INTERVAL 30 DAY AND COALESCE(ds.code, 'none') = ? GROUP BY st.stage", [\$r['source']]) as \$x) {
        \$per['stage_' . \$x['stage']] = (int) \$x['minutes_per_night'];
    }
    \$out['stored_sleep_last_30_days'][] = \$r + ['stage_minutes_per_night_with_stages' => \$per];
}

/* Health Connect nights (numbers only): do awake periods overlap the sleep
   stages (Fitbit's short awakenings), and are any periods reversed? */
\$hcSrc = db_value("SELECT id FROM data_sources WHERE code = 'google_health_connect'");
if (\$hcSrc !== null) {
    \$out['hc_stage_check_last_30_days'] = db_one(
        "SELECT COUNT(*) AS periods,
                SUM(st.ended_at <= st.started_at) AS reversed_or_empty,
                SUM(st.stage = 1) AS awake_periods,
                SUM(st.stage = 1 AND TIMESTAMPDIFF(SECOND, st.started_at, st.ended_at) <= 300) AS awake_periods_up_to_5_min,
                ROUND(SUM(CASE WHEN st.stage = 1 AND st.ended_at > st.started_at THEN TIMESTAMPDIFF(SECOND, st.started_at, st.ended_at) ELSE 0 END) / 60 / COUNT(DISTINCT s.id)) AS awake_minutes_per_night_forward,
                COUNT(DISTINCT s.id) AS nights
           FROM sleep_stages st JOIN sleep_sessions s ON s.id = st.sleep_session_id
          WHERE s.source_id = ? AND s.night_of >= CURDATE() - INTERVAL 30 DAY", [\$hcSrc]);
    \$out['hc_awake_overlapping_sleep'] = db_one(
        "SELECT COUNT(DISTINCT a.id) AS awake_periods_inside_a_sleep_stage,
                ROUND(SUM(TIMESTAMPDIFF(SECOND, GREATEST(a.started_at, b.started_at), LEAST(a.ended_at, b.ended_at))) / 60) AS overlapping_minutes,
                COUNT(DISTINCT a.sleep_session_id) AS nights_with_overlap
           FROM sleep_stages a
           JOIN sleep_stages b ON b.sleep_session_id = a.sleep_session_id AND b.stage IN (2, 4, 5, 6)
                              AND b.started_at < a.ended_at AND a.started_at < b.ended_at
           JOIN sleep_sessions s ON s.id = a.sleep_session_id
          WHERE a.stage = 1 AND a.ended_at > a.started_at AND s.source_id = ? AND s.night_of >= CURDATE() - INTERVAL 30 DAY", [\$hcSrc]);
    \$out['hc_nights_sample'] = db_all(
        "SELECT s.night_of, s.duration_minutes AS asleep, s.awake_minutes AS awake, s.time_in_bed_minutes AS in_bed,
                TIMESTAMPDIFF(MINUTE, s.started_at, s.ended_at) AS span, s.awakenings,
                (SELECT COUNT(*) FROM sleep_stages x WHERE x.sleep_session_id = s.id AND x.stage = 1) AS awake_periods,
                (SELECT ROUND(SUM(TIMESTAMPDIFF(SECOND, x.started_at, x.ended_at)) / 60) FROM sleep_stages x WHERE x.sleep_session_id = s.id AND x.stage = 1) AS awake_period_minutes,
                (SELECT COUNT(*) FROM sleep_stages x WHERE x.sleep_session_id = s.id AND x.ended_at <= x.started_at) AS reversed,
                (SELECT ROUND(SUM(TIMESTAMPDIFF(SECOND, x.started_at, x.ended_at)) / 60) FROM sleep_stages x WHERE x.sleep_session_id = s.id AND x.stage IN (4,5,6)) AS staged_sleep_minutes
           FROM sleep_sessions s WHERE s.source_id = ? AND s.night_of >= CURDATE() - INTERVAL 10 DAY ORDER BY s.night_of DESC, s.started_at", [\$hcSrc]);
    \$gSrc = db_value("SELECT id FROM data_sources WHERE code = 'google_health'");
    \$out['google_nights_sample'] = \$gSrc === null ? null : db_all(
        "SELECT night_of, duration_minutes AS asleep, awake_minutes AS awake, time_in_bed_minutes AS in_bed, TIMESTAMPDIFF(MINUTE, started_at, ended_at) AS span
           FROM sleep_sessions WHERE source_id = ? AND night_of >= CURDATE() - INTERVAL 10 DAY ORDER BY night_of DESC, started_at", [\$gSrc]);
}

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
                /* Sleep: Google's own sums per night, numbers only (restless vs awake). */
                if (\$type === 'sleep') {
                    \$entry['nights'] = [];
                    foreach (array_slice(\$points, 0, 14) as \$p) {
                        \$sl = (array) (\$p['sleep'] ?? []);
                        \$sum = (array) (\$sl['summary'] ?? []);
                        \$own = [];
                        foreach ((array) (\$sl['stages'] ?? []) as \$st) {
                            \$a = strtotime((string) (\$st['startTime'] ?? '')); \$b = strtotime((string) (\$st['endTime'] ?? ''));
                            if (\$a && \$b) { \$k = (string) (\$st['type'] ?? '?'); \$own[\$k] = (\$own[\$k] ?? 0) + (int) round((\$b - \$a) / 60); }
                        }
                        \$short = 0;
                        foreach ((array) (\$sl['shortAwakenings'] ?? []) as \$st) {
                            \$a = strtotime((string) (\$st['startTime'] ?? '')); \$b = strtotime((string) (\$st['endTime'] ?? ''));
                            if (\$a && \$b) { \$short += (int) round((\$b - \$a) / 60); }
                        }
                        \$entry['nights'][] = [
                            'type' => \$sl['type'] ?? null, 'stages_status' => \$sl['metadata']['stagesStatus'] ?? null,
                            'nap' => \$sl['metadata']['nap'] ?? null, 'main' => \$sl['metadata']['mainSleep'] ?? null,
                            'platform' => \$p['dataSource']['platform'] ?? null,
                            'google_minutes' => array_intersect_key(\$sum, array_flip(['minutesAsleep', 'minutesAwake', 'minutesInSleepPeriod', 'minutesToFallAsleep', 'minutesAfterWakeUp'])),
                            'google_stages' => array_map(static fn (\$x) => [\$x['type'] ?? '?', \$x['minutes'] ?? null, \$x['count'] ?? null], (array) (\$sum['stagesSummary'] ?? [])),
                            'stage_minutes_from_segments' => \$own,
                            'short_awakenings' => ['count' => count((array) (\$sl['shortAwakenings'] ?? [])), 'minutes' => \$short],
                            'out_of_bed_segments' => count((array) (\$sl['outOfBedSegments'] ?? [])),
                        ];
                    }
                }
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
        /* Types Ownify does not read yet: do they answer for this account and these scopes? Counts only. */
        \$out['api_not_read'] = [];
        foreach (['floors' => 'interval', 'active-minutes' => 'interval', 'active-zone-minutes' => 'interval', 'basal-energy-burned' => 'interval',
                  'altitude' => 'interval', 'time-in-heart-rate-zone' => 'interval', 'sedentary-period' => 'interval',
                  'heart-rate-variability' => 'sample', 'oxygen-saturation' => 'sample', 'respiratory-rate-sleep-summary' => 'sample',
                  'daily-sleep-temperature-derivations' => 'daily', 'daily-heart-rate-zones' => 'daily', 'activity-level' => 'daily'] as \$type => \$kind) {
            \$url = rtrim(google_health_config()['api_base'], '/') . '/users/me/dataTypes/' . rawurlencode(\$type) . '/dataPoints?'
                . http_build_query(['filter' => google_health_filter(\$type, \$kind, \$from), 'pageSize' => 1000], '', '&', PHP_QUERY_RFC3986);
            \$res = google_health_http('GET', \$url, ['Authorization: Bearer ' . \$access['token'], 'Accept: application/json'], null, 60);
            if (\$res === null) { \$out['api_not_read'][\$type] = ['http' => 0]; continue; }
            \$data = json_decode(\$res['body'], true);
            \$pts = (array) (\$data['dataPoints'] ?? []);
            \$e = ['http' => \$res['status'], 'points' => count(\$pts), 'more_pages' => !empty(\$data['nextPageToken'])];
            if (isset(\$pts[0]) && is_array(\$pts[0])) {
                \$shape = [];
                foreach (\$pts[0] as \$k => \$v) { \$shape[\$k] = is_array(\$v) ? array_keys(\$v) : gettype(\$v); }
                \$e['shape'] = \$shape;
            }
            if (\$res['status'] !== 200) { \$e['error'] = [\$data['error']['status'] ?? null, mb_substr((string) (\$data['error']['message'] ?? ''), 0, 200)]; }
            \$out['api_not_read'][\$type] = \$e;
        }
    }
}
/* One real sync per Google Health connection, as the scheduled run does
   (PROBE_SYNC=1): how it went, never what it fetched. */
if ('$sync' === '1') {
    \$out['sync'] = [];
    foreach (db_all("SELECT user_id FROM user_integrations WHERE provider = 'google_health' AND status IN ('connected', 'error')") as \$i => \$p) {
        \$t = microtime(true);
        \$r = google_health_sync((int) \$p['user_id'], 'schedule');
        \$row = integration_get((int) \$p['user_id'], 'google_health');
        \$out['sync'][] = ['connection' => \$i + 1, 'status' => \$r['status'], 'message' => \$r['message'], 'written' => \$r['written'],
            'requests' => \$r['requests'], 'seconds' => round(microtime(true) - \$t, 1), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576),
            'memory_limit' => ini_get('memory_limit'), 'after' => array_intersect_key((array) \$row, array_flip(['last_sync_at', 'sync_started_at', 'last_sync_status', 'last_error']))];
    }
    \$src = db_value("SELECT id FROM data_sources WHERE code = 'google_health'");
    \$out['stored_after_sync'] = [
        'metrics'  => (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE source_id = ?', [\$src]),
        'sleep'    => (int) db_value('SELECT COUNT(*) FROM sleep_sessions WHERE source_id = ?', [\$src]),
        'workouts' => (int) db_value('SELECT COUNT(*) FROM workouts WHERE source_id = ?', [\$src]),
    ];
}
echo json_encode(\$out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP

printf 'put "%s" "%s/%s"\n' "$work/probe.php" "$root" "$name" > "$work/b-put"
sftp_run "$work/b-put" > /dev/null 2>&1 || fail "Could not put the one-time probe in the web root."

code="$(curl -sS -o "$work/answer.json" -w '%{http_code}' --max-time 900 \
    -H "X-Ownify-Probe: $token" "$public_url/$name" || echo 000)"
echo "HTTP $code"
cat "$work/answer.json" || true
echo
[ "$code" = "200" ] || fail "The website answered $code to the probe (it was removed again)."

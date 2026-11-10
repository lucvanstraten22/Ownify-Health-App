<?php
/**
 * Google Health (44.0, docs/GOOGLE-HEALTH.md), end to end against a Google
 * that is stood in for in this process (google_health_http's stub) — the
 * real connect flow, token handling, sync, mapping and import:
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/google-health-test.php
 *
 *   - before it is set up: says so ("op de server nog niet ingesteld"),
 *     never "niet beschikbaar"; set up: it can be connected
 *   - connecting: Google's consent address (offline access, consent asked,
 *     the scopes, a one-time state); the state from the app's browser asks
 *     to confirm the account; confirming exchanges the code and seals the
 *     tokens; a state used twice, a strange state, refused at Google
 *   - the first sync: every data type read over 30 days with Google's own
 *     filters; a workout with Google's distance, calories, heart rate and
 *     speed; a night with its stages (RESTLESS as Rusteloosheid) and its
 *     sleeping heart rate; steps, distance, energy, resting heart rate, HRV,
 *     SpO2, breathing, weight, body fat, height, water and a meal — all on
 *     the clock they were recorded on; heart rate minute by minute
 *   - again: nothing doubled; data Google had from Health Connect left out
 *     for a person who syncs Health Connect with the app
 *   - a type Google refuses (403): the rest arrives, the owner told
 *   - an expired access token is refreshed; a refresh Google refuses
 *     (7 days in "Testing") marks the connection to be made again
 *   - disconnecting: withdrawn at Google, the tokens gone here
 *
 * A throwaway account `ghtest_…`, removed at the end. Never point it at a
 * live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 020 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

if ((string) getenv('OWNIFY_APP_KEY') === '') {
    putenv('OWNIFY_APP_KEY=' . base64_encode(random_bytes(32)));     // a key of this run only
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/integrations.php';
require_once $root . '/includes/health-connect-map.php';
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/i18n.php';
i18n_force('nl');

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

if (!db_available() || !google_health_stored() || !crypto_available()) {
    fwrite(STDERR, "The database is unreachable, has no migration 020 or the google_health source, or there is no key.\n");
    exit(2);
}

/* ------------------------------------------------------ not set up yet */
if (getenv('GH_TEST_CHILD') !== '1') {
echo "Before it is set up\n";
check('it says it is not set up on the server — not that it does not exist',
    integration_blocked_reason('google_health') === 'Deze koppeling is op de server nog niet ingesteld.',
    (string) integration_blocked_reason('google_health'));

putenv('GOOGLE_HEALTH_CLIENT_ID=test-client.apps.googleusercontent.com');
putenv('GOOGLE_HEALTH_CLIENT_SECRET=test-secret-not-real');
putenv('GOOGLE_HEALTH_REDIRECT_URI=https://ownify.test/api/integrations/google_health/callback.php');
/* integration_config() read the file once: read it again, as a new request would. */
$reload = (function () use ($root) { return require $root . '/config/integrations.php'; })();
(function () {})();
$GLOBALS['__gh_config'] = $reload['google_health'];

/* The configuration this process now has (the file plus the environment). */
$config = $reload['google_health'];
check('the environment fills it in', $config['client_id'] === 'test-client.apps.googleusercontent.com');
}

/* integration_config() keeps a static copy; a fresh process would read the
   environment: run the rest in one. */
if (getenv('GH_TEST_CHILD') !== '1') {
    $cmd = [PHP_BINARY, __FILE__];
    $env = getenv();
    $env['GH_TEST_CHILD'] = '1';
    $proc = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes, $root, $env);
    $code = proc_close($proc);
    echo "\n  (before set-up) $pass passed, $fail failed\n";
    exit($code === 0 && $fail === 0 ? 0 : 1);
}

/* ===================================================== the child process */
echo "Set up\n";
check('it can be connected', integration_blocked_reason('google_health') === null, (string) integration_blocked_reason('google_health'));
check('it can be asked for new data (pull to sync)', !empty(integration_providers()['google_health']['sync'])
    && is_file($root . '/api/integrations/google_health/sync.php'));

db_run('INSERT INTO users (username) VALUES (?)', ['ghtest_' . bin2hex(random_bytes(4))]);
$user = (int) db_value('SELECT MAX(id) FROM users');
db_run('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE id = ?', [$user]);
register_shutdown_function(static function () use ($user): void {
    db_run('DELETE FROM users WHERE id = ?', [$user]);
});

/* ------------------------------------------------------ the stand-in */
$calls   = [];
$tokens  = ['access' => 'access-1', 'refresh' => 'refresh-1', 'refreshOk' => true];
$refuse  = [];          // data types Google answers 403 for
$expireNextGet = false;
$day   = (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
$prev  = (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
$utc   = static fn (string $local): string => (new DateTimeImmutable($local, new DateTimeZone('+01:00')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$iv    = static fn (string $from, string $to): array => ['startTime' => $utc($from), 'startUtcOffset' => '3600s', 'endTime' => $utc($to), 'endUtcOffset' => '3600s'];
$st    = static fn (string $at): array => ['physicalTime' => $utc($at), 'utcOffset' => '3600s'];
$watch = ['platform' => 'FITBIT', 'device' => ['displayName' => 'Pixel Watch 3']];
$hcSrc = ['platform' => 'HEALTH_CONNECT', 'device' => ['displayName' => 'Phone']];
$date  = static fn (string $d): array => ['year' => (int) substr($d, 0, 4), 'month' => (int) substr($d, 5, 2), 'day' => (int) substr($d, 8, 2)];

$heart = [];
for ($m = 0; $m < 40; $m++) {
    $heart[] = ['name' => 'users/me/dataTypes/heart-rate/dataPoints/hr' . $m, 'dataSource' => $watch,
                'heartRate' => ['sampleTime' => $st(sprintf('%sT07:%02d:00', $day, $m)), 'beatsPerMinute' => (string) (150 + $m % 10)]];
}
foreach ([1, 3, 5] as $h) {
    $heart[] = ['name' => 'users/me/dataTypes/heart-rate/dataPoints/night' . $h, 'dataSource' => $watch,
                'heartRate' => ['sampleTime' => $st(sprintf('%sT0%d:00:00', $day, $h)), 'beatsPerMinute' => (string) (50 + $h)]];
}

$data = [
    'exercise' => [['name' => 'users/me/dataTypes/exercise/dataPoints/run1', 'dataSource' => $watch, 'exercise' => [
        'interval' => $iv("{$day}T07:00:00", "{$day}T07:40:00"), 'exerciseType' => 'RUNNING', 'activeDuration' => '2400s',
        'displayName' => 'Ochtendloop',
        'metricsSummary' => ['distanceMillimeters' => 8000000, 'caloriesKcal' => 520, 'averageHeartRateBeatsPerMinute' => '154',
                             'averageSpeedMillimetersPerSecond' => 3333.3, 'elevationGainMillimeters' => 42000]]]],
    'sleep' => [['name' => 'users/me/dataTypes/sleep/dataPoints/night1', 'dataSource' => $watch, 'sleep' => [
        'interval' => $iv("{$prev}T23:00:00", "{$day}T06:30:00"), 'type' => 'STAGES',
        'stages' => [
            ['type' => 'LIGHT', ...$iv("{$prev}T23:00:00", "{$day}T01:00:00")],
            ['type' => 'DEEP', ...$iv("{$day}T01:00:00", "{$day}T02:30:00")],
            ['type' => 'REM', ...$iv("{$day}T02:30:00", "{$day}T04:00:00")],
            ['type' => 'RESTLESS', ...$iv("{$day}T04:00:00", "{$day}T04:10:00")],
            ['type' => 'AWAKE', ...$iv("{$day}T04:10:00", "{$day}T04:25:00")],
            ['type' => 'LIGHT', ...$iv("{$day}T04:25:00", "{$day}T06:30:00")],
        ]]]],
    'steps' => [
        ['name' => 'users/me/dataTypes/steps/dataPoints/s1', 'dataSource' => $watch, 'steps' => ['interval' => $iv("{$day}T10:00:00", "{$day}T11:00:00"), 'count' => '3000']],
        ['name' => 'users/me/dataTypes/steps/dataPoints/s-hc', 'dataSource' => $hcSrc, 'steps' => ['interval' => $iv("{$day}T12:00:00", "{$day}T13:00:00"), 'count' => '999']],
    ],
    'distance' => [['name' => 'users/me/dataTypes/distance/dataPoints/d1', 'dataSource' => $watch, 'distance' => ['interval' => $iv("{$day}T10:00:00", "{$day}T11:00:00"), 'millimeters' => '2400000']]],
    'active-energy-burned' => [['name' => 'users/me/dataTypes/active-energy-burned/dataPoints/a1', 'dataSource' => $watch,
        'activeEnergyBurned' => ['interval' => $iv("{$day}T10:00:00", "{$day}T11:00:00"), 'kcal' => 210.5]]],
    'heart-rate' => $heart,
    'daily-resting-heart-rate' => [['dataSource' => $watch, 'dailyRestingHeartRate' => ['date' => $date($day), 'beatsPerMinute' => '54']]],
    'daily-heart-rate-variability' => [['dataSource' => $watch, 'dailyHeartRateVariability' => ['date' => $date($day), 'averageHeartRateVariabilityMilliseconds' => 41.5]]],
    'daily-oxygen-saturation' => [['dataSource' => $watch, 'dailyOxygenSaturation' => ['date' => $date($day), 'averagePercentage' => 96.2]]],
    'daily-respiratory-rate' => [['dataSource' => $watch, 'dailyRespiratoryRate' => ['date' => $date($day), 'breathsPerMinute' => 14.4]]],
    'weight' => [['name' => 'users/me/dataTypes/weight/dataPoints/w1', 'dataSource' => $watch, 'weight' => ['sampleTime' => $st("{$day}T07:30:00"), 'weightGrams' => 71400]]],
    'body-fat' => [['name' => 'users/me/dataTypes/body-fat/dataPoints/f1', 'dataSource' => $watch, 'bodyFat' => ['sampleTime' => $st("{$day}T07:30:00"), 'percentage' => 24.5]]],
    'height' => [['name' => 'users/me/dataTypes/height/dataPoints/h1', 'dataSource' => $watch, 'height' => ['sampleTime' => $st("{$day}T07:31:00"), 'heightMillimeters' => '1680']]],
    'hydration-log' => [['name' => 'users/me/dataTypes/hydration-log/dataPoints/water1', 'dataSource' => $watch, 'hydrationLog' => [
        'interval' => $iv("{$day}T09:00:00", "{$day}T09:05:00"), 'amountConsumed' => ['milliliters' => 250]]]],
    'nutrition-log' => [['name' => 'users/me/dataTypes/nutrition-log/dataPoints/lunch1', 'dataSource' => $watch, 'nutritionLog' => [
        'interval' => $iv("{$day}T12:30:00", "{$day}T12:45:00"), 'mealType' => 'LUNCH', 'foodDisplayName' => 'Broodje kaas',
        'energy' => ['kcal' => 450], 'totalCarbohydrate' => ['grams' => 50], 'totalFat' => ['grams' => 15],
        'nutrients' => [['nutrient' => 'PROTEIN', 'quantity' => ['grams' => 22]], ['nutrient' => 'CAFFEINE', 'quantity' => ['grams' => 0.08]]]]]],
];

$GLOBALS['google_health_http_stub'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$tokens, &$refuse, &$expireNextGet, $data): ?array {
    $calls[] = [$method, $url, $body];
    if (str_contains($url, 'oauth2.googleapis.com/token')) {
        parse_str((string) $body, $form);
        if (($form['client_secret'] ?? '') !== 'test-secret-not-real') {
            return ['status' => 401, 'body' => '{"error":"invalid_client"}'];
        }
        if (($form['grant_type'] ?? '') === 'authorization_code') {
            return $form['code'] === 'good-code'
                ? ['status' => 200, 'body' => json_encode(['access_token' => $tokens['access'], 'refresh_token' => $tokens['refresh'], 'expires_in' => 3599, 'scope' => 'x'])]
                : ['status' => 400, 'body' => '{"error":"invalid_grant"}'];
        }
        if (!$tokens['refreshOk'] || ($form['refresh_token'] ?? '') !== $tokens['refresh']) {
            return ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'];
        }
        $tokens['access'] = 'access-' . random_int(2, 999);
        return ['status' => 200, 'body' => json_encode(['access_token' => $tokens['access'], 'expires_in' => 3599])];
    }
    if (str_contains($url, '/revoke')) {
        return ['status' => 200, 'body' => '{}'];
    }
    if (preg_match('#/v4/users/me/dataTypes/([a-z-]+)/dataPoints#', $url, $m)) {
        $bearer = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Authorization: Bearer ')) {
                $bearer = substr($h, 22);
            }
        }
        if ($expireNextGet) {
            $expireNextGet = false;
            return ['status' => 401, 'body' => '{}'];
        }
        if ($bearer !== $tokens['access']) {
            return ['status' => 401, 'body' => '{}'];
        }
        if (in_array($m[1], $refuse, true)) {
            return ['status' => 403, 'body' => '{"error":{"status":"PERMISSION_DENIED"}}'];
        }
        return ['status' => 200, 'body' => json_encode(['dataPoints' => $data[$m[1]] ?? []])];
    }
    return ['status' => 404, 'body' => '{}'];
};

/* --------------------------------------------------------- connecting */
echo "Connecting\n";
$begin = google_health_begin($user, 'app');
parse_str((string) parse_url((string) $begin['url'], PHP_URL_QUERY), $q);
check('to Google\'s consent screen', $begin['ok'] && str_starts_with((string) $begin['url'], 'https://accounts.google.com/o/oauth2/v2/auth?'));
check('offline access with consent asked, so a refresh token comes back', ($q['access_type'] ?? '') === 'offline' && ($q['prompt'] ?? '') === 'consent');
check('read-only health scopes, the redirect, a state', str_contains($q['scope'] ?? '', 'googlehealth.sleep.readonly')
    && !str_contains($q['scope'] ?? '', 'writeonly') && ($q['redirect_uri'] ?? '') === 'https://ownify.test/api/integrations/google_health/callback.php'
    && strlen($q['state'] ?? '') >= 40);

$strange = google_health_callback(['state' => 'nope', 'code' => 'good-code'], null);
check('a strange state is refused', $strange['outcome'] === 'error');

$back = google_health_callback(['state' => $q['state'], 'code' => 'good-code'], null);
check('from the app\'s browser: confirm which account', $back['outcome'] === 'confirm' && ($back['confirm']['username'] ?? '') !== '');
check('the state cannot be used twice', google_health_callback(['state' => $q['state'], 'code' => 'good-code'], null)['outcome'] === 'error');

$done = google_health_confirm($back['confirm']['token'], 'confirm');
check('confirmed: connected', $done['outcome'] === 'connected', json_encode($done));
$row = integration_get($user, 'google_health');
check('the tokens are sealed, not stored as they are', $row !== null && $row['status'] === 'connected'
    && !str_contains((string) $row['access_token'], 'access-1') && integration_tokens($user, 'google_health')['refresh_token'] === 'refresh-1');

$begin2 = google_health_begin($user, 'app');
parse_str((string) parse_url((string) $begin2['url'], PHP_URL_QUERY), $q2);
check('refused at Google: nothing connected', google_health_callback(['state' => $q2['state'], 'error' => 'access_denied'], null)['outcome'] === 'cancelled');

/* ----------------------------------------------------------- the sync */
echo "The first sync\n";
$calls = [];
$r = google_health_sync($user, 'connect');
check('synced', $r['ok'] && $r['status'] === 'ok', json_encode($r));
$gets = array_filter($calls, static fn ($c) => $c[0] === 'GET');
check('every data type asked for', count($gets) === count(google_health_types()), (string) count($gets));
$filters = array_map(static function ($c) { parse_str((string) parse_url($c[1], PHP_URL_QUERY), $x); return $x['filter'] ?? ''; }, $gets);
$ago = static fn (int $n): string => (new DateTimeImmutable('today'))->modify('-' . $n . ' days')->format('Y-m-d');
check('30 days back (the minute-by-minute four a week), with Google\'s own filters',
    (bool) array_filter($filters, static fn ($f) => str_starts_with($f, 'steps.interval.start_time >= "' . $ago(7)))
    && (bool) array_filter($filters, static fn ($f) => str_starts_with($f, 'heart_rate.sample_time.physical_time >= "' . $ago(7)))
    && (bool) array_filter($filters, static fn ($f) => str_starts_with($f, 'sleep.interval.end_time >= "' . $ago(30)))
    && (bool) array_filter($filters, static fn ($f) => str_starts_with($f, 'daily_resting_heart_rate.date >= '))
    && (bool) array_filter($filters, static fn ($f) => str_starts_with($f, 'exercise.interval.civil_start_time >= ')), implode(' | ', $filters));

$w = db_one("SELECT * FROM workouts WHERE user_id = ?", [$user]);
check('the run, with Google\'s distance, calories, heart rate and pace', $w !== null && $w['activity_type'] === 'running'
    && (float) $w['distance_m'] === 8000.0 && (int) $w['total_kcal'] === 520 && (int) $w['avg_hr'] === 154
    && abs((float) $w['avg_speed_kmh'] - 12.0) < 0.01 && (int) $w['elevation_gain_m'] === 42 && (int) $w['duration_seconds'] === 2400, json_encode($w));
check('…on the clock it was run on', $w['started_at'] === $day . ' 07:00:00', (string) $w['started_at']);

$n = db_one("SELECT * FROM sleep_sessions WHERE user_id = ?", [$user]);
check('the night, filed under its morning, with its stages', $n !== null && $n['night_of'] === $day
    && (int) $n['deep_minutes'] === 90 && (int) $n['rem_minutes'] === 90, json_encode($n));
$restless = (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ? AND stage = 7', [(int) $n['id']]);
check('RESTLESS kept as Rusteloosheid (stage 7)', $restless === 1);

$metric = static fn (string $code): ?string => db_value(
    'SELECT m.value FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = ? ORDER BY m.recorded_at DESC LIMIT 1',
    [$user, $code]);
check('steps, distance, active energy', (float) db_value("SELECT value FROM health_metrics WHERE user_id = ? AND external_id = 'gh:steps:s1'", [$user]) === 3000.0 && abs((float) $metric('distance') - 2.4) < 0.001
    && abs((float) $metric('active_energy') - 210.5) < 0.001);
check('resting heart rate, HRV, SpO2, breathing', (float) $metric('resting_hr') === 54.0 && (float) $metric('hrv') === 41.5
    && abs((float) $metric('spo2') - 96.2) < 0.001 && abs((float) $metric('respiratory_rate') - 14.4) < 0.001);
check('the night\'s sleeping heart rate from the samples in it', abs((float) $metric('sleeping_hr') - 53.0) < 0.001, (string) $metric('sleeping_hr'));
$measure = static fn (string $type): ?string => db_value('SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = ?', [$user, $type]);
check('weight, body fat, height', (float) $measure('weight') === 71.4 && (float) $measure('body_fat_pct') === 24.5 && (float) $measure('height') === 168.0);
check('water and the meal with its nutrients', abs((float) $metric('water') - 0.25) < 0.001
    && db_value("SELECT label FROM nutrition_entries WHERE user_id = ?", [$user]) === 'Broodje kaas'
    && (float) $metric('protein') === 22.0);
check('heart rate minute by minute', (int) db_value('SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ?', [$user]) === 43);
check('the watch named on the connection', integration_get($user, 'google_health')['external_account_label'] === 'Pixel Watch 3');
check('the last sync noted', integration_get($user, 'google_health')['last_sync_status'] === 'ok');

/* ----------------------------------------------------- again, and HC */
echo "Again\n";
$counts = static fn (): string => implode(',', [
    db_value('SELECT COUNT(*) FROM workouts WHERE user_id = ?', [$user]),
    db_value('SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ?', [$user]),
    db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ?', [$user]),
    db_value('SELECT COUNT(*) FROM user_measurements WHERE user_id = ?', [$user]),
]);
$before = $counts();
google_health_sync($user, 'manual');
check('nothing doubled', $counts() === $before, $before . ' → ' . $counts());
check('Health Connect\'s own points from Google come along without the app', db_value("SELECT 1 FROM health_metrics WHERE user_id = ? AND external_id = 'gh:steps:s-hc'", [$user]) !== null);

db_run("DELETE FROM health_metrics WHERE user_id = ? AND external_id = 'gh:steps:s-hc'", [$user]);
db_run("INSERT INTO user_integrations (user_id, provider, status) VALUES (?, 'google_health_connect', 'connected')", [$user]);
google_health_sync($user, 'manual');
check('…left out for a person who syncs Health Connect with the app', db_value("SELECT 1 FROM health_metrics WHERE user_id = ? AND external_id = 'gh:steps:s-hc'", [$user]) === null);

/* ------------------------------------------------------- refused types */
echo "Something refused\n";
$refuse = ['nutrition-log', 'hydration-log'];
$r = google_health_sync($user, 'manual');
check('the rest arrives, and the owner is told', $r['status'] === 'partial' && str_contains((string) integration_get($user, 'google_health')['last_error'], 'voeding'), json_encode($r));
$refuse = [];

/* -------------------------------------------------------------- tokens */
echo "Tokens\n";
$expireNextGet = true;
$old = $tokens['access'];
$r = google_health_sync($user, 'manual');
check('an access token Google no longer takes is refreshed, and the sync goes on', $r['ok'] && $tokens['access'] !== $old, json_encode($r));

db_run("UPDATE user_integrations SET token_expires_at = NOW() - INTERVAL 1 HOUR WHERE user_id = ? AND provider = 'google_health'", [$user]);
$tokens['refreshOk'] = false;
$r = google_health_sync($user, 'manual');
$row = integration_get($user, 'google_health');
check('a refresh Google refuses (7 days in Testing): to be connected again', $r['status'] === 'revoked' && $row['status'] === 'revoked', json_encode($r) . ' ' . $row['status']);

/* ---------------------------------------------------------- disconnect */
echo "Disconnecting\n";
$tokens['refreshOk'] = true;
db_run("UPDATE user_integrations SET status = 'connected' WHERE user_id = ? AND provider = 'google_health'", [$user]);
integration_store_tokens($user, 'google_health', 'access-x', 'refresh-x', date('Y-m-d H:i:s', time() + 3600));
$calls = [];
google_health_disconnect($user);
$revoked = array_filter($calls, static fn ($c) => str_contains($c[1], '/revoke'));
check('withdrawn at Google too', count($revoked) === 1 && str_contains((string) array_values($revoked)[0][2], 'refresh-x'));
$row = integration_get($user, 'google_health');
check('the tokens are gone here', $row['access_token'] === null && $row['refresh_token'] === null && $row['status'] === 'disconnected');
check('what came in stays', (int) db_value('SELECT COUNT(*) FROM workouts WHERE user_id = ?', [$user]) === 1);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

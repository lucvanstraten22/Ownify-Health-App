<?php
/**
 * Je gegevens (Instellingen › Privacy): Gegevens downloaden and Gegevens
 * wissen, against a development database and a local server.
 *
 *     DB_NAME=ownify_dev DB_USER=root OWNIFY_TEST_EMAIL_REGISTRATION=1 php tools/data-export-test.php
 *
 * Covers:
 *   - api/profile/export.php: only for the signed-in account (419 without
 *     CSRF or token, 403 for a sync token); every section there; the rows
 *     that were stored, exactly once — the heart rate read in pages, one
 *     page ending inside a minute two origins share; nothing of anybody
 *     else's and no secret (password hash, token hash, Google subject)
 *   - api/profile/wipe.php: nothing without confirm=wissen; with it, the
 *     health data and what was worked out from it gone — and only the
 *     account's own; the account, its sign-in, profile, friends, phones and
 *     OwnifyAI conversations still there; the board re-ranked
 *
 * Accounts are named `dx_…` and removed at the end. Never point it at a
 * live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/user.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/data-export.php';

if (!db_available()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable.\n");
    exit(2);
}

i18n_force('nl');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  PASS  %s\n", $label);
        return;
    }
    $fail++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : "\n          " . $detail);
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return array{status: int, body: ?array, raw: string} */
function http(string $path, array $o = []): array
{
    global $base;
    $handle  = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => true]);
    if (isset($o['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($o['json']));
    } else {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form'] ?? []));
    }
    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 300);
}

$run  = substr(bin2hex(random_bytes(3)), 0, 6);
$made = [];

function account(string $who): array
{
    global $run, $made;
    $username = 'dx_' . $run . '_' . $who;
    $made[]   = $username;
    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => 'data-export-test-pw',
        'label' => 'Pixel', 'platform' => 'android', 'app_version' => 'test',
    ]]);
    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }
    $token = (string) $r['body']['token'];
    http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);
    $id = (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
    db_run('UPDATE user_profiles SET first_name = ?, last_name = ? WHERE user_id = ?', [ucfirst($who), 'Test', $id]);
    return ['username' => $username, 'token' => $token, 'id' => $id];
}

/** Rows in every table "Gegevens wissen" touches, and a few it must not. */
function seed(int $user, int $minutes, string $day): void
{
    $type   = static fn (string $code) => (int) db_value('SELECT id FROM health_metric_types WHERE code = ?', [$code]);
    $source = (int) db_value("SELECT id FROM data_sources WHERE code = 'manual'");

    db_run('INSERT INTO user_measurements (user_id, measurement_type, value, unit, measured_at) VALUES (?, ?, 180, ?, ?), (?, ?, 75.5, ?, ?)',
        [$user, 'height', 'cm', "$day 08:00:00", $user, 'weight', 'kg', "$day 08:00:00"]);
    db_run('INSERT INTO sleep_sessions (user_id, source_id, night_of, started_at, ended_at, duration_minutes) VALUES (?, ?, ?, ?, ?, 450)',
        [$user, $source, $day, "$day 00:00:00", "$day 07:30:00"]);
    $sleep = (int) db_insert_id();
    db_run('INSERT INTO sleep_stages (sleep_session_id, stage, started_at, ended_at) VALUES (?, 4, ?, ?)', [$sleep, "$day 01:00:00", "$day 02:00:00"]);
    db_run('INSERT INTO workouts (user_id, source_id, activity_type, started_at, duration_seconds) VALUES (?, ?, ?, ?, 1800)',
        [$user, $source, 'running', "$day 18:00:00"]);
    $workout = (int) db_insert_id();
    db_run('INSERT INTO workout_hr_zones (workout_id, zone, seconds) VALUES (?, 2, 600)', [$workout]);
    db_run('INSERT INTO nutrition_entries (user_id, source_id, consumed_at) VALUES (?, ?, ?)', [$user, $source, "$day 12:30:00"]);
    $meal = (int) db_insert_id();
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, value, recorded_at, nutrition_entry_id) VALUES (?, ?, ?, 650, ?, ?)',
        [$user, $type('energy'), $source, "$day 12:30:00", $meal]);
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, value, recorded_at) VALUES (?, ?, ?, 8000, ?)',
        [$user, $type('steps'), $source, "$day 20:00:00"]);
    db_run('INSERT INTO health_metric_day_totals (user_id, metric_type_id, day, total, readings_hash) VALUES (?, ?, ?, 8000, ?)',
        [$user, $type('steps'), $day, str_repeat('a', 32)]);

    /* The heart rate: $minutes minutes from one origin, and the last minute
       from a second one too — so a page can end inside a minute. */
    $start = strtotime("$day 00:00:00");
    for ($i = 0; $i < $minutes; $i += 500) {
        $values = [];
        $params = [];
        for ($j = $i; $j < min($minutes, $i + 500); $j++) {
            $values[] = '(?, ?, ?, ?, 1)';
            array_push($params, $user, date('Y-m-d H:i:s', $start + 60 * $j), 'watch', 60 + $j % 40);
        }
        db_run('INSERT INTO heart_rate_minutes (user_id, minute_at, data_origin, bpm, samples) VALUES ' . implode(',', $values), $params);
    }
    db_run('INSERT INTO heart_rate_minutes (user_id, minute_at, data_origin, bpm, samples) VALUES (?, ?, ?, 99, 1)',
        [$user, date('Y-m-d H:i:s', $start + 60 * ($minutes - 1)), 'phone']);

    db_run("INSERT INTO daily_scores (user_id, score_date, domain, score, algorithm_version) VALUES (?, ?, 'sleep', 72, 'test')", [$user, $day]);
    db_run("INSERT INTO point_events (user_id, rule_id, award_key, points, awarded_at) VALUES (?, 1, ?, 25, ?)",
        [$user, 'test-' . $user, "$day 09:00:00"]);
    db_run("INSERT INTO goals (user_id, name, category_code, goal_type, tracking, unit_code, target_value, baseline_value, start_date, end_date)
            VALUES (?, 'Naar 8.000 stappen', 'activity', 'milestone', 'manual', 'bpm', 8000, 5000, ?, ?)",
        [$user, $day, date('Y-m-d', strtotime("$day +30 days"))]);
    $goal = (int) db_insert_id();
    db_run('INSERT INTO goal_entries (goal_id, entry_date, value) VALUES (?, ?, 1)', [$goal, $day]);
    db_run('INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)', [$user, 'Slaap']);
    $conversation = (int) db_insert_id();
    db_run("INSERT INTO ai_messages (conversation_id, user_id, role, content) VALUES (?, ?, 'user', 'Hoe sliep ik?')", [$conversation, $user]);
}

function count_of(string $table, int $user): int
{
    return (int) db_value('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id = ?', [$user]);
}

$port   = free_port();
$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

try {
    $anna = account('anna');
    $bram = account('bram');
    $day  = date('Y-m-d', strtotime('-3 days'));
    $minutes = DATA_EXPORT_CHUNK + 1;          // a second page, and one ending inside a minute
    seed($anna['id'], $minutes, $day);
    seed($bram['id'], 10, $day);
    [$low, $high] = $anna['id'] < $bram['id'] ? [$anna['id'], $bram['id']] : [$bram['id'], $anna['id']];
    db_run("INSERT INTO friendships (user_low_id, user_high_id, requested_by, status) VALUES (?, ?, ?, 'accepted')", [$low, $high, $anna['id']]);
    db_run("UPDATE point_events SET points = 40 WHERE user_id = ?", [$bram['id']]);
    foreach (['month' => substr($day, 0, 7), 'year' => substr($day, 0, 4), 'alltime' => 'all'] as $type => $key) {
        leaderboard_rebuild($type, $key);
    }

    /* ================================================================ export */
    echo "\nGegevens downloaden\n";

    check('without CSRF or a token: 419', http('/api/profile/export.php')['status'] === 419);
    $code = device_create_pairing_code($anna['id'], 'google_health_connect')['code'] ?? '';
    $sync = device_pair((string) $code, ['label' => 'Sync', 'platform' => 'android'])['token'] ?? null;
    if (is_string($sync)) {
        check('a sync token: 403', http('/api/profile/export.php', ['bearer' => $sync])['status'] === 403);
    }

    $r      = http('/api/profile/export.php', ['bearer' => $anna['token']]);
    $export = $r['body']['export'] ?? null;
    check('200, ok, a filename and the export', $r['status'] === 200 && ($r['body']['ok'] ?? null) === true
        && preg_match('/^ownify-gegevens-\d{4}-\d{2}-\d{2}\.json$/', (string) ($r['body']['filename'] ?? '')) === 1 && is_array($export), summary($r));

    $sections = array_column(data_export_sections(), 0);
    check('every section there, in order', is_array($export) && array_slice(array_keys($export), 3) === $sections,
        json_encode(array_keys((array) $export)));
    check('the account and profile', ($export['account'][0]['username'] ?? null) === $anna['username']
        && ($export['profile'][0]['first_name'] ?? null) === 'Anna');
    check('measurements, sleep with its stage, a workout with its zone, a meal with its energy',
        count($export['measurements'] ?? []) === 2 && count($export['sleep_sessions'] ?? []) === 1 && count($export['sleep_stages'] ?? []) === 1
        && count($export['workouts'] ?? []) === 1 && count($export['workout_heart_rate_zones'] ?? []) === 1
        && count($export['nutrition_entries'] ?? []) === 1 && count($export['health_metrics'] ?? []) === 2);
    $hr   = $export['heart_rate_minutes'] ?? [];
    $keys = array_map(static fn ($row) => $row['minute_at'] . '|' . $row['data_origin'], $hr);
    check('the heart rate whole, across pages: every minute once, both origins of the shared one',
        count($hr) === $minutes + 1 && count(array_unique($keys)) === $minutes + 1, count($hr) . ' rows, ' . count(array_unique($keys)) . ' distinct');
    check('scores, points, goals with their entry, the friend, the conversation',
        count($export['daily_scores'] ?? []) === 1 && count($export['points'] ?? []) === 1 && count($export['goals'] ?? []) === 1
        && count($export['goal_entries'] ?? []) === 1 && ($export['friends'][0]['username'] ?? null) === $bram['username']
        && count($export['ownifyai_conversations'] ?? []) === 1 && count($export['ownifyai_messages'] ?? []) === 1);
    check('the sign-in without its password hash, the phones without their token hash',
        isset($export['sign_in'][0]['provider']) && !str_contains($r['raw'], 'password_hash') && !str_contains($r['raw'], '$2y$')
        && !str_contains($r['raw'], '$argon2') && !str_contains($r['raw'], 'token_hash') && !str_contains($r['raw'], 'provider_subject')
        && !str_contains($r['raw'], $anna['token']));
    check('nothing of Bram\'s but his name as a friend', substr_count($r['raw'], $bram['username']) === 1
        && !str_contains($r['raw'], 'Bram'));

    /* ================================================================== wipe */
    echo "\nGegevens wissen\n";

    $before = count_of('health_metrics', $anna['id']);
    $r = http('/api/profile/wipe.php', ['bearer' => $anna['token'], 'form' => []]);
    check('without confirm=wissen: 422, nothing deleted', $r['status'] === 422 && count_of('health_metrics', $anna['id']) === $before, summary($r));
    $r = http('/api/profile/wipe.php', ['bearer' => $anna['token'], 'form' => ['confirm' => 'ja']]);
    check('with anything else: 422 too', $r['status'] === 422);
    check('without CSRF or a token: 419', http('/api/profile/wipe.php', ['form' => ['confirm' => 'wissen']])['status'] === 419);

    $bramBefore = [];
    foreach (data_wipe_tables() as $table) {
        $bramBefore[$table] = count_of($table, $bram['id']);
    }

    $r = http('/api/profile/wipe.php', ['bearer' => $anna['token'], 'form' => ['confirm' => 'wissen']]);
    check('200, with the sentence that says the account stays', $r['status'] === 200
        && ($r['body']['message'] ?? null) === 'Je gegevens zijn gewist. Je account staat er nog.' && ($r['body']['deleted'] ?? 0) > $minutes, summary($r));

    $left = [];
    foreach (data_wipe_tables() as $table) {
        if (count_of($table, $anna['id']) !== 0) {
            $left[] = $table;
        }
    }
    check('every health table empty for her', $left === [], json_encode($left));
    check('…and what hung off them too (stages, zones, goal entries)',
        (int) db_value('SELECT COUNT(*) FROM sleep_stages g LEFT JOIN sleep_sessions s ON s.id = g.sleep_session_id WHERE s.id IS NULL') === 0
        && (int) db_value('SELECT COUNT(*) FROM workout_hr_zones z LEFT JOIN workouts w ON w.id = z.workout_id WHERE w.id IS NULL') === 0
        && (int) db_value('SELECT COUNT(*) FROM goal_entries e LEFT JOIN goals g ON g.id = e.goal_id WHERE g.id IS NULL') === 0);

    $bramAfter = [];
    foreach (data_wipe_tables() as $table) {
        $bramAfter[$table] = count_of($table, $bram['id']);
    }
    unset($bramBefore['user_period_points'], $bramAfter['user_period_points'], $bramBefore['leaderboard_best_positions'], $bramAfter['leaderboard_best_positions']);
    check('Bram\'s data untouched', $bramBefore === $bramAfter, json_encode([$bramBefore, $bramAfter]));
    check('the board re-ranked: Bram first, Anna off it',
        (int) db_value("SELECT position FROM user_period_points WHERE user_id = ? AND period_type = 'alltime'", [$bram['id']]) === 1
        && count_of('user_period_points', $anna['id']) === 0);

    check('her account, profile and sign-in stay', db_value('SELECT username FROM users WHERE id = ?', [$anna['id']]) === $anna['username']
        && db_value('SELECT first_name FROM user_profiles WHERE user_id = ?', [$anna['id']]) === 'Anna'
        && count_of('user_auth_identities', $anna['id']) > 0);
    check('her phone stays signed in', http('/api/app/state.php', ['bearer' => $anna['token']])['status'] === 200);
    check('her friend and her OwnifyAI conversation stay',
        (int) db_value('SELECT COUNT(*) FROM friendships WHERE ? IN (user_low_id, user_high_id)', [$anna['id']]) === 1
        && count_of('ai_conversations', $anna['id']) === 1 && count_of('ai_messages', $anna['id']) === 1);

    $r = http('/api/profile/wipe.php', ['bearer' => $anna['token'], 'form' => ['confirm' => 'wissen']]);
    check('again: nothing left to wipe, and it says so', $r['status'] === 200 && ($r['body']['deleted'] ?? null) === 0
        && ($r['body']['message'] ?? null) === 'Er waren geen gegevens om te wissen.', summary($r));

    $r = http('/api/profile/export.php', ['bearer' => $anna['token']]);
    check('her export afterwards: the account, no health data', ($r['body']['export']['account'][0]['username'] ?? null) === $anna['username']
        && ($r['body']['export']['heart_rate_minutes'] ?? null) === [] && ($r['body']['export']['measurements'] ?? null) === []);

} finally {
    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    proc_terminate($server);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);

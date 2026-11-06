<?php
/**
 * Health Connect, 43.0 (docs/HEALTH-CONNECT.md), on the server's side:
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/health-connect-sync-test.php
 *
 *   - the four types the app now reads: respiratory rate, lean body mass,
 *     skin temperature (a baseline and its deltas) and body temperature
 *     (only one taken on the skin counts as a skin temperature)
 *   - a workout filled in from the records around it: distance (for a
 *     sport that covers distance only), active and total calories, one app
 *     per value and a partly overlapping record for its share, heart rate
 *     when it covers half the workout — and nothing made up without them;
 *     then its intensity and a personal record, as the points read them
 *   - deletions: a record Health Connect no longer has, removed by its id
 *     from every table it lives in, with what hangs off it; a corrected
 *     night kept; another user's record with the same id untouched; the
 *     workout's points gone with it
 *   - a resend after enrichment: the same values, nothing doubled
 *
 * Accounts `hcsync_…`, removed at the end with everything hanging off them.
 * Never point it at a live database.
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
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/health-connect-map.php';
require_once $root . '/includes/i18n.php';
if (is_file($root . '/includes/insights.php')) { require_once $root . '/includes/insights.php'; }

if (!db_available() || !health_metric_intervals_available() || !health_heart_minutes_stored()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 012 or 019.\n");
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
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$users = [];
$newUser = static function () use (&$users): int {
    db_run('INSERT INTO users (username) VALUES (?)', ['hcsync_' . bin2hex(random_bytes(4))]);
    $id = (int) db_value('SELECT MAX(id) FROM users');
    db_run('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE id = ?', [$id]);
    $users[] = $id;
    return $id;
};
register_shutdown_function(static function () use (&$users): void {
    foreach ($users as $id) {
        db_run('DELETE FROM users WHERE id = ?', [$id]);
    }
});

$user  = $newUser();
$other = $newUser();
$send  = static fn (int $who, array $records, array $deleted = []): array =>
    health_import_records($who, 'google_health_connect', health_connect_map($records)['records'], $deleted);
$meta  = static fn (string $id, string $app = 'com.watch'): array => ['id' => $id, 'dataOrigin' => $app];
$day   = (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
$at    = static fn (string $clock): string => $day . 'T' . $clock . ':00+01:00';
$metric = static fn (int $who, string $code): ?string => db_value(
    'SELECT m.value FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
      WHERE m.user_id = ? AND t.code = ? ORDER BY m.recorded_at DESC LIMIT 1', [$who, $code]);

/* ------------------------------------------------------ the four types */
echo "The four types the app now reads\n";

$r = $send($user, [
    ['recordType' => 'RespiratoryRate', 'metadata' => $meta('rr1'), 'time' => $at('03:00'), 'rate' => 14.5],
    ['recordType' => 'LeanBodyMass', 'metadata' => $meta('lbm1'), 'time' => $at('08:00'), 'mass' => ['kilograms' => 58.2]],
    ['recordType' => 'SkinTemperature', 'metadata' => $meta('skin1'), 'startTime' => $at('00:00'), 'endTime' => $at('06:00'),
     'baseline' => ['celsius' => 33.4], 'deltas' => [
        ['time' => $at('01:00'), 'delta' => ['celsius' => 0.2]],
        ['time' => $at('03:00'), 'delta' => ['celsius' => -0.4]],
     ], 'measurementLocation' => 3],
]);
check('respiratory rate, per minute', (float) $metric($user, 'respiratory_rate') === 14.5, json_encode($r['problems']));
check('lean body mass, kilograms', (float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'lean_mass'", [$user]) === 58.2);
check('skin temperature: the baseline plus its deltas\' mean (33.4 − 0.1)', abs((float) $metric($user, 'skin_temp') - 33.3) < 0.001,
    (string) $metric($user, 'skin_temp'));
check('…filed at the end of the night', db_value("SELECT DATE_FORMAT(m.recorded_at, '%H:%i') FROM health_metrics m WHERE m.user_id = ? AND m.external_id = 'skin1'", [$user]) === '06:00');

$mapped = health_connect_map([
    ['recordType' => 'SkinTemperature', 'metadata' => $meta('skin2'), 'startTime' => $at('00:00'), 'endTime' => $at('06:00'),
     'deltas' => [['time' => $at('01:00'), 'delta' => ['celsius' => 0.2]]]],
    ['recordType' => 'BodyTemperature', 'metadata' => $meta('bt-wrist'), 'time' => $at('07:00'),
     'temperature' => ['celsius' => 34.1], 'measurementLocation' => 9],
    ['recordType' => 'BodyTemperature', 'metadata' => $meta('bt-mouth'), 'time' => $at('07:05'),
     'temperature' => ['celsius' => 36.9], 'measurementLocation' => 4],
    ['recordType' => 'BodyTemperature', 'metadata' => $meta('bt-unknown'), 'time' => $at('07:10'),
     'temperature' => ['celsius' => 37.2]],
]);
$ids = array_column($mapped['records'], 'external_id');
check('a skin temperature without a baseline is only a difference: nothing stored', !in_array('skin2', $ids, true));
check('a body temperature on the wrist is a skin temperature', in_array('bt-wrist', $ids, true));
check('in the mouth or somewhere unknown it is not, and is reported', !in_array('bt-mouth', $ids, true)
    && !in_array('bt-unknown', $ids, true) && ($mapped['unmapped']['BodyTemperature'] ?? 0) === 2, json_encode($mapped['unmapped']));

/* ------------------------------------------------- a workout filled in */
echo "A workout filled in from the records around it\n";

$run = ['recordType' => 'ExerciseSession', 'metadata' => $meta('run1'), 'startTime' => $at('07:00'), 'endTime' => $at('07:40'),
        'exerciseType' => 56, 'exerciseTypeName' => 'running'];
$records = [$run];
/* The watch: 4 × 10 minutes of distance and calories inside the run. */
foreach ([0, 10, 20, 30] as $i => $m) {
    $s = sprintf('07:%02d', $m);
    $e = sprintf('07:%02d', $m + 10);
    $records[] = ['recordType' => 'Distance', 'metadata' => $meta('d' . $i), 'startTime' => $at($s), 'endTime' => $at($e), 'distance' => ['meters' => 2000]];
    $records[] = ['recordType' => 'ActiveCaloriesBurned', 'metadata' => $meta('a' . $i), 'startTime' => $at($s), 'endTime' => $at($e), 'energy' => ['kilocalories' => 110]];
}
/* The phone, the same run, a little less: never added to the watch's. */
$records[] = ['recordType' => 'Distance', 'metadata' => $meta('pd', 'com.phone'), 'startTime' => $at('07:00'), 'endTime' => $at('07:40'), 'distance' => ['meters' => 7600]];
/* Half inside the run: half of it counts. */
$records[] = ['recordType' => 'TotalCaloriesBurned', 'metadata' => $meta('t1'), 'startTime' => $at('06:20'), 'endTime' => $at('07:40'), 'energy' => ['kilocalories' => 800]];
/* Heart rate through the run. */
$samples = [];
for ($m = 0; $m < 40; $m++) {
    $samples[] = ['time' => $at(sprintf('07:%02d', $m)), 'beatsPerMinute' => 150 + ($m % 10)];
}
$records[] = ['recordType' => 'HeartRate', 'metadata' => $meta('hr1'), 'startTime' => $at('07:00'), 'endTime' => $at('07:40'),
              'samples' => [], 'allSamples' => $samples];

$r = $send($user, $records);
$w = db_one("SELECT * FROM workouts WHERE user_id = ? AND external_id = 'run1'", [$user]);
check('distance: the watch\'s 8 km, not watch + phone', $w !== null && (float) $w['distance_m'] === 8000.0, json_encode($w));
check('active calories: 440', (int) $w['active_kcal'] === 440);
check('total calories: the half of 800 inside the run', (int) $w['total_kcal'] === 400);
check('heart rate: mean and highest minute', (int) $w['avg_hr'] === 155 && (int) $w['max_hr'] === 159, $w['avg_hr'] . '/' . $w['max_hr']);

$workout = null;
foreach (health_workout_records($user) as $one) {
    if ($one['id'] === (int) $w['id']) {
        $workout = $one;
    }
}
check('as the points read it: km and pace', $workout !== null && abs($workout['km'] - 8.0) < 0.001 && abs($workout['kmh'] - 12.0) < 0.001);
$effort = health_workout_effort($workout, 185.0);
check('its intensity from its heart rate (155 of 185: hard)', $effort['class'] === 'hard', json_encode($effort));
check('the batch awarded its intensity', (bool) db_value(
    "SELECT 1 FROM point_events WHERE user_id = ? AND award_key = ? AND points > 0", [$user, 'workout_intensity:' . $w['id']]
) || !points_available(), 'no intensity points');

/* Sent again: the same values, nothing doubled. */
$send($user, $records);
$again = db_one("SELECT distance_m, active_kcal, total_kcal, avg_hr, max_hr FROM workouts WHERE user_id = ? AND external_id = 'run1'", [$user]);
check('sent again: the same', $again == array_intersect_key($w, $again), json_encode($again));
check('…one workout, not two', (int) db_value("SELECT COUNT(*) FROM workouts WHERE user_id = ?", [$user]) === 1);

/* A strength session over the same kind of records: no distance. */
$gym = ['recordType' => 'ExerciseSession', 'metadata' => $meta('gym1'), 'startTime' => $at('18:00'), 'endTime' => $at('18:40'),
        'exerciseType' => 70, 'exerciseTypeName' => 'strength_training'];
$send($user, [$gym,
    ['recordType' => 'Distance', 'metadata' => $meta('gd'), 'startTime' => $at('18:00'), 'endTime' => $at('18:40'), 'distance' => ['meters' => 1500]],
    ['recordType' => 'HeartRate', 'metadata' => $meta('ghr'), 'startTime' => $at('18:00'), 'endTime' => $at('18:05'), 'samples' => [],
     'allSamples' => [['time' => $at('18:01'), 'beatsPerMinute' => 120], ['time' => $at('18:02'), 'beatsPerMinute' => 125]]],
]);
$g = db_one("SELECT * FROM workouts WHERE user_id = ? AND external_id = 'gym1'", [$user]);
check('a strength session gets no distance from the steps between sets', $g !== null && $g['distance_m'] === null);
check('…and two minutes of heart rate do not stand for forty', $g['avg_hr'] === null && $g['max_hr'] === null);

/* A personal record: the fourth and longest run. */
$base = new DateTimeImmutable($day);
foreach ([12, 9, 6] as $n => $ago) {
    $date = $base->modify('-' . $ago . ' days')->format('Y-m-d');
    $send($user, [
        ['recordType' => 'ExerciseSession', 'metadata' => $meta('old' . $n), 'startTime' => $date . 'T07:00:00+01:00', 'endTime' => $date . 'T07:30:00+01:00',
         'exerciseType' => 56, 'exerciseTypeName' => 'running'],
        ['recordType' => 'Distance', 'metadata' => $meta('olddist' . $n), 'startTime' => $date . 'T07:00:00+01:00', 'endTime' => $date . 'T07:30:00+01:00',
         'distance' => ['meters' => 5000]],
    ]);
}
$send($user, $records);    // the 8 km run again, now with three earlier ones to beat
check('the longest run of four is a personal record', !points_available() || (bool) db_value(
    "SELECT 1 FROM point_events WHERE user_id = ? AND award_key = ? AND points > 0", [$user, 'workout_record:' . $w['id']]
), 'no record points');

/* ----------------------------------------------------------- deletions */
echo "Deleted in Health Connect\n";

/* The other user has a record with the same id: it is theirs. */
$send($other, [['recordType' => 'Steps', 'metadata' => $meta('s1'), 'startTime' => $at('10:00'), 'endTime' => $at('10:30'), 'count' => 3000]]);
$send($user, [
    ['recordType' => 'Steps', 'metadata' => $meta('s1'), 'startTime' => $at('10:00'), 'endTime' => $at('10:30'), 'count' => 4000],
    ['recordType' => 'Nutrition', 'metadata' => $meta('meal1'), 'startTime' => $at('12:00'), 'endTime' => $at('12:20'),
     'name' => 'Lunch', 'mealType' => 2, 'energy' => ['kilocalories' => 600], 'protein' => ['grams' => 30]],
    ['recordType' => 'SleepSession', 'metadata' => $meta('night1'), 'startTime' => $base->modify('-1 day')->format('Y-m-d') . 'T23:00:00+01:00',
     'endTime' => $at('07:00'), 'stages' => [['startTime' => $base->modify('-1 day')->format('Y-m-d') . 'T23:00:00+01:00', 'endTime' => $at('07:00'), 'stage' => 4]]],
]);
$mealId = (int) db_value("SELECT id FROM nutrition_entries WHERE user_id = ? AND external_id = 'meal1'", [$user]);
$nightId = (int) db_value("SELECT id FROM sleep_sessions WHERE user_id = ? AND external_id = 'night1'", [$user]);
check('before: the meal with its nutrients, the night with its stages', $mealId > 0 && $nightId > 0
    && (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE nutrition_entry_id = ?', [$mealId]) >= 2);

$r = $send($user, [], ['s1', 'meal1', 'lbm1', 'run1', 'night1', 'no-such-record']);
check('the deletions are counted', $r['deleted'] >= 5, (string) $r['deleted']);
check('steps gone', db_value("SELECT 1 FROM health_metrics WHERE user_id = ? AND external_id = 's1'", [$user]) === null);
check('the other person\'s record with the same id stays', db_value("SELECT 1 FROM health_metrics WHERE user_id = ? AND external_id = 's1'", [$other]) !== null);
check('the meal and its nutrients gone', db_value('SELECT 1 FROM nutrition_entries WHERE id = ?', [$mealId]) === null
    && (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE nutrition_entry_id = ?', [$mealId]) === 0);
check('the measurement gone', db_value("SELECT 1 FROM user_measurements WHERE user_id = ? AND external_id = 'lbm1'", [$user]) === null);
check('the night and its stages gone', db_value('SELECT 1 FROM sleep_sessions WHERE id = ?', [$nightId]) === null
    && (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$nightId]) === 0);
check('the workout gone, and its points with it', db_value("SELECT 1 FROM workouts WHERE user_id = ? AND external_id = 'run1'", [$user]) === null
    && (!points_available() || (int) db_value("SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = ? AND award_key LIKE ?", [$user, 'workout%:' . $w['id']]) === 0));

if (health_import_corrected()) {
    $send($user, [['recordType' => 'SleepSession', 'metadata' => $meta('night2'), 'startTime' => $base->modify('-4 days')->format('Y-m-d') . 'T23:00:00+01:00',
                   'endTime' => $base->modify('-3 days')->format('Y-m-d') . 'T07:00:00+01:00', 'stages' => []]]);
    db_run("UPDATE sleep_sessions SET corrected_at = NOW() WHERE user_id = ? AND external_id = 'night2'", [$user]);
    $send($user, [], ['night2']);
    check('a night the person corrected stays', db_value("SELECT 1 FROM sleep_sessions WHERE user_id = ? AND external_id = 'night2'", [$user]) !== null);
}

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

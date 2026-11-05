<?php
/**
 * Sleep and training entered by hand, and a training removed (40.0,
 * docs/MANUAL-ENTRY.md), on the rules.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/manual-entry-test.php
 *
 * On the database named by DB_NAME (with migrations 019 and 030):
 *
 *   - a night: today's and an earlier one, across midnight, a bedtime after
 *     midnight, a nap; filed under the morning it ended; refused for the
 *     future, the same two times, nonsense
 *   - correcting a night that is there: the same row, its stages and stage
 *     minutes kept, its new times; then a sync of that night from Health
 *     Connect keeps the person's times and still brings the device's
 *     measurements; another recording of that sleep is left out
 *   - heart rate: the recorded minutes in the night as its 'sleeping_hr',
 *     none when nothing was recorded, gone when a correction leaves them out
 *   - a training: types, durations, calories optional; its mean and highest
 *     heart rate from the recorded minutes, nothing without them; refused
 *     when it overlaps any training, from anywhere
 *   - the night and the training as the rest of Ownify reads them: the night
 *     of the date, the workout records the score and points read, points
 *   - removing a training: its points gone, the weekly bonus worked out
 *     again; a synced one remembered, so the same sync leaves it out; only
 *     the person's own
 *
 * One account `me_…`, removed at the end with everything hanging off it.
 * Never point it at a live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 030 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/health-manual.php';
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/i18n.php';
if (is_file($root . '/includes/insights.php')) { require_once $root . '/includes/insights.php'; }

if (!db_available() || !health_manual_ready() || !health_heart_minutes_stored()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 019 or 030.\n");
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

db_run('INSERT INTO users (username) VALUES (?)', ['me_' . bin2hex(random_bytes(4))]);
$user = (int) db_value('SELECT MAX(id) FROM users');
/* An account of two months: points count from the day it was made. */
db_run('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY) WHERE id = ?', [$user]);
register_shutdown_function(static function () use ($user): void {
    db_run('DELETE FROM users WHERE id = ?', [$user]);
});

$today     = new DateTimeImmutable('today');
$d         = static fn (int $daysAgo): string => $today->modify('-' . $daysAgo . ' days')->format('Y-m-d');
$session   = static fn (int $id): ?array => db_one('SELECT * FROM sleep_sessions WHERE id = ?', [$id]);
$sleepingHr = static fn (int $id): ?string => db_value(
    "SELECT m.value FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
      WHERE t.code = 'sleeping_hr' AND m.sleep_session_id = ?", [$id]);
$heart = static function (string $from, int $minutes, float $bpm) use ($user): void {
    $at = new DateTimeImmutable($from);
    for ($i = 0; $i < $minutes; $i++) {
        db_run('INSERT INTO heart_rate_minutes (user_id, minute_at, data_origin, bpm, samples) VALUES (?, ?, ?, ?, 1)',
            [$user, $at->modify('+' . $i . ' minutes')->format('Y-m-d H:i:00'), 'test.watch', $bpm + ($i % 3)]);
    }
};

/* ------------------------------------------------------------- a night */
echo "A night\n";

$r = health_manual_sleep($user, $d(0), '23:30', '07:00', $today->setTime(9, 0));
$s = $session((int) ($r['session_id'] ?? 0));
check("today's: filed under this morning, from last evening", $r['ok'] && $s !== null && $s['night_of'] === $d(0)
    && $s['started_at'] === $d(1) . ' 23:30:00' && $s['ended_at'] === $d(0) . ' 07:00:00', json_encode($r));
check('…the time in bed and asleep: 7:30, by hand, marked as the person\'s', $s !== null && (int) $s['time_in_bed_minutes'] === 450
    && (int) $s['duration_minutes'] === 450 && $s['corrected_at'] !== null
    && db_value('SELECT code FROM data_sources WHERE id = ?', [$s['source_id']]) === 'manual');
check('…no heart rate recorded, none made up', $r['heart_minutes'] === 0 && $sleepingHr((int) $r['session_id']) === null);

$r = health_manual_sleep($user, $d(10), '01:15', '08:30', $today->setTime(9, 0));
$s = $session((int) ($r['session_id'] ?? 0));
check('an earlier night, to bed after midnight: the same day', $r['ok'] && $s['started_at'] === $d(10) . ' 01:15:00'
    && $s['ended_at'] === $d(10) . ' 08:30:00' && $s['night_of'] === $d(10));

$r = health_manual_sleep($user, $d(12), '22:00', '06:00', $today->setTime(9, 0));
check('across midnight on an earlier date: 8 hours from the evening before', $r['ok']
    && $session((int) $r['session_id'])['started_at'] === $d(13) . ' 22:00:00'
    && (int) $session((int) $r['session_id'])['duration_minutes'] === 480);

check('the future is refused', !health_manual_sleep($user, $d(0), '23:00', '10:00', $today->setTime(9, 0))['ok']);
check('a date that is no date is refused', !health_manual_sleep($user, '2026-02-30', '23:00', '07:00')['ok']);
check('a time that is no time is refused', !health_manual_sleep($user, $d(3), '25:00', '07:00')['ok']
    && !health_manual_sleep($user, $d(3), 'abc', '07:00')['ok']);
check('bedtime and wake-up the same is refused', !health_manual_sleep($user, $d(3), '07:00', '07:00')['ok']);
check('the night as the rest of Ownify reads it', (health_night_on($user, $d(12))['minutes'] ?? null) === 480.0);

/* --------------------------------------- correcting a synced night */
echo "Correcting a night from Health Connect\n";

$night = $d(5);
$res = health_import_records($user, 'google_health_connect', [[
    'type' => 'sleep', 'external_id' => 'hc-night-1', 'started_at' => $d(6) . ' 23:00:00', 'ended_at' => $night . ' 06:30:00',
    'duration_minutes' => 420, 'awake_minutes' => 30, 'light_minutes' => 240, 'deep_minutes' => 90, 'rem_minutes' => 90,
    'efficiency_pct' => 93.33,
    'stages' => [['stage' => 4, 'started_at' => $d(6) . ' 23:00:00', 'ended_at' => $night . ' 03:00:00'],
                 ['stage' => 5, 'started_at' => $night . ' 03:00:00', 'ended_at' => $night . ' 06:30:00']],
]]);
$hcId = (int) db_value("SELECT id FROM sleep_sessions WHERE user_id = ? AND external_id = 'hc-night-1'", [$user]);
check('the synced night is there', $res['written'] === 1 && $hcId > 0);

$heart($d(6) . ' 23:40:00', 60, 52.0);
$r = health_manual_sleep($user, $night, '23:30', '07:15', $today->setTime(9, 0));
$s = $session($hcId);
check('corrected: the same row, no second night', $r['ok'] && $r['updated'] && (int) $r['session_id'] === $hcId
    && (int) db_value('SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ? AND night_of = ?', [$user, $night]) === 1);
check('…its new times', $s['started_at'] === $d(6) . ' 23:30:00' && $s['ended_at'] === $night . ' 07:15:00'
    && (int) $s['time_in_bed_minutes'] === 465);
check('…asleep is the time in bed less the time awake the device measured', (int) $s['duration_minutes'] === 435);
check('…its other measurements kept: stage minutes, stages, still from Health Connect', (int) $s['deep_minutes'] === 90
    && (int) $s['rem_minutes'] === 90 && (int) $s['light_minutes'] === 240
    && (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$hcId]) === 2
    && db_value('SELECT code FROM data_sources WHERE id = ?', [$s['source_id']]) === 'google_health_connect');
check('…the heart rate recorded in it, on the night (60 minutes, mean 53)', $r['heart_minutes'] === 60
    && abs((float) $sleepingHr($hcId) - 53.0) < 0.05, (string) $sleepingHr($hcId));

/* The next sync sends the same night again, with the device's times. */
$res = health_import_records($user, 'google_health_connect', [[
    'type' => 'sleep', 'external_id' => 'hc-night-1', 'started_at' => $d(6) . ' 23:00:00', 'ended_at' => $night . ' 06:30:00',
    'duration_minutes' => 420, 'awake_minutes' => 30, 'light_minutes' => 245, 'deep_minutes' => 85, 'rem_minutes' => 90,
    'stages' => [['stage' => 4, 'started_at' => $d(6) . ' 23:00:00', 'ended_at' => $night . ' 02:00:00'],
                 ['stage' => 6, 'started_at' => $night . ' 02:00:00', 'ended_at' => $night . ' 03:00:00'],
                 ['stage' => 5, 'started_at' => $night . ' 03:00:00', 'ended_at' => $night . ' 06:30:00']],
]]);
$s = $session($hcId);
check('a sync keeps the person\'s times', $s['started_at'] === $d(6) . ' 23:30:00' && $s['ended_at'] === $night . ' 07:15:00'
    && (int) $s['time_in_bed_minutes'] === 465 && (int) $s['duration_minutes'] === 435 && $s['night_of'] === $night);
check('…and still brings the device\'s measurements', (int) $s['light_minutes'] === 245 && (int) $s['deep_minutes'] === 85
    && (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$hcId]) === 3);

/* Another app's recording of that same sleep. */
health_import_records($user, 'google_health_connect', [[
    'type' => 'sleep', 'external_id' => 'other-app-night', 'started_at' => $d(6) . ' 22:50:00', 'ended_at' => $night . ' 06:40:00',
]]);
check('another recording of a corrected sleep is left out', db_value(
    "SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ? AND external_id = 'other-app-night'", [$user]) == 0);

/* A night entered by hand where nothing was, and Health Connect's later. */
$r = health_manual_sleep($user, $d(3), '23:00', '07:00', $today->setTime(9, 0));
health_import_records($user, 'google_health_connect', [[
    'type' => 'sleep', 'external_id' => 'hc-late', 'started_at' => $d(4) . ' 23:10:00', 'ended_at' => $d(3) . ' 06:45:00',
    'duration_minutes' => 440, 'deep_minutes' => 80, 'rem_minutes' => 90,
]]);
check('a night entered by hand is not doubled by a later sync', (int) db_value(
    'SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ? AND night_of = ?', [$user, $d(3)]) === 1
    && (health_night_on($user, $d(3))['session_id'] ?? 0) === (int) $r['session_id']);

/* The correction moves away from the recorded heart rate. */
$r = health_manual_sleep($user, $night, '01:00', '07:15', $today->setTime(9, 0));
check('a correction that leaves the recorded minutes out drops the night\'s heart rate', $r['ok'] && $r['heart_minutes'] === 0
    && $sleepingHr($hcId) === null);

/* ------------------------------------------------------------ a training */
echo "A training\n";

$heart($d(2) . ' 18:00:00', 45, 140.0);
$w = health_manual_workout($user, ['date' => $d(2), 'type' => 'running', 'start' => '18:00', 'minutes' => '45', 'kcal' => '410'],
    $today->setTime(9, 0));
$row = db_one('SELECT w.*, s.code FROM workouts w JOIN data_sources s ON s.id = w.source_id WHERE w.id = ?', [$w['workout_id'] ?? 0]);
check('running, 45 minutes, 410 kcal: by hand', $w['ok'] && $row !== null && $row['activity_type'] === 'running'
    && (int) $row['duration_seconds'] === 2700 && (int) $row['active_kcal'] === 410 && $row['code'] === 'manual'
    && $row['ended_at'] === $d(2) . ' 18:45:00', json_encode($w));
check('…its heart rate from the 45 recorded minutes: mean 141, highest 142', $w['heart_minutes'] === 45
    && (int) $row['avg_hr'] === 141 && (int) $row['max_hr'] === 142);

$w2 = health_manual_workout($user, ['date' => $d(2), 'type' => 'yoga', 'start' => '07:00', 'minutes' => '20', 'kcal' => ''],
    $today->setTime(9, 0));
$row2 = db_one('SELECT * FROM workouts WHERE id = ?', [$w2['workout_id'] ?? 0]);
check('yoga, 20 minutes, no calories, no heart rate: saved all the same', $w2['ok'] && $row2['active_kcal'] === null
    && $row2['avg_hr'] === null && $row2['max_hr'] === null && $w2['heart_minutes'] === 0);

$dup = health_manual_workout($user, ['date' => $d(2), 'type' => 'biking', 'start' => '18:30', 'minutes' => '30'], $today->setTime(9, 0));
check('one overlapping a training is refused, with the message', !$dup['ok']
    && $dup['error'] === 'Er staat al een activiteit geregistreerd op dit tijdstip.' && ($dup['code'] ?? '') === 'overlap');
$same = health_manual_workout($user, ['date' => $d(2), 'type' => 'biking', 'start' => '18:00', 'minutes' => '10'], $today->setTime(9, 0));
check('…and one at the same start', !$same['ok'] && ($same['code'] ?? '') === 'overlap');
$after = health_manual_workout($user, ['date' => $d(2), 'type' => 'walking', 'start' => '18:45', 'minutes' => '15'], $today->setTime(9, 0));
check('right after it is fine', $after['ok']);

health_import_records($user, 'google_health_connect', [[
    'type' => 'workout', 'external_id' => 'hc-ride', 'activity_type' => 'biking',
    'started_at' => $d(1) . ' 12:00:00', 'ended_at' => $d(1) . ' 13:00:00',
]]);
$overSynced = health_manual_workout($user, ['date' => $d(1), 'type' => 'running', 'start' => '12:30', 'minutes' => '20'], $today->setTime(9, 0));
check('…a training overlapping a synced one is refused too', !$overSynced['ok'] && ($overSynced['code'] ?? '') === 'overlap');

check('an unknown activity type is refused', !health_manual_workout($user, ['date' => $d(4), 'type' => 'quidditch', 'start' => '10:00', 'minutes' => '30'])['ok']);
check('a duration of 0 or over a day is refused', !health_manual_workout($user, ['date' => $d(4), 'type' => 'running', 'start' => '10:00', 'minutes' => '0'])['ok']
    && !health_manual_workout($user, ['date' => $d(4), 'type' => 'running', 'start' => '10:00', 'minutes' => '1441'])['ok']);
check('calories that are no number are refused', !health_manual_workout($user, ['date' => $d(4), 'type' => 'running', 'start' => '10:00', 'minutes' => '30', 'kcal' => 'veel'])['ok']);
check('a training in the future is refused', !health_manual_workout($user, ['date' => $d(0), 'type' => 'running', 'start' => '23:59', 'minutes' => '10'], $today->setTime(9, 0))['ok']);

$records = array_column(health_workout_records($user), null, 'id');
check('the score and points read it as any training', isset($records[$w['workout_id']]) && $records[$w['workout_id']]['type'] === 'running'
    && (int) $records[$w['workout_id']]['minutes'] === 45 && (int) $records[$w['workout_id']]['avg_hr'] === 141);
$earned = (int) db_value("SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = ? AND award_key LIKE ?", [$user, 'workout:' . $w['workout_id']]);
check('…and it earned its points', $earned > 0, (string) $earned);

/* ---------------------------------------------------------- removing */
echo "Removing a training\n";

/* Three in one week: the weekly bonus. */
$weekStart = new DateTimeImmutable(points_week_start($user, $today->modify('-21 days')->getTimestamp()));
$ids = [];
foreach ([0, 1, 2] as $i) {
    $day  = $weekStart->modify('+' . $i . ' days')->format('Y-m-d');
    $ids[] = health_manual_workout($user, ['date' => $day, 'type' => 'running', 'start' => '18:00', 'minutes' => '40'], $today->setTime(9, 0))['workout_id'] ?? 0;
}
$bonus = static fn (): int => (int) db_value("SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = ? AND award_key = ?",
    [$user, 'weekly_workouts:' . $weekStart->format('Y-m-d')]);
check('three trainings in a week earn the weekly bonus', $bonus() > 0, (string) $bonus());

$gone = health_manual_remove_workout($user, $ids[1]);
check('removing one: gone, not a synced one', $gone['ok'] && !$gone['imported']
    && db_value('SELECT id FROM workouts WHERE id = ?', [$ids[1]]) === null);
check('…its own points gone', (int) db_value("SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = ? AND award_key = ?",
    [$user, 'workout:' . $ids[1]]) === 0);
check('…and the weekly bonus with it: two left', $bonus() === 0, (string) $bonus());

$ride = (int) db_value("SELECT id FROM workouts WHERE user_id = ? AND external_id = 'hc-ride'", [$user]);
$gone = health_manual_remove_workout($user, $ride);
check('removing a synced one: gone from Ownify, remembered as removed', $gone['ok'] && $gone['imported']
    && db_value('SELECT id FROM workouts WHERE id = ?', [$ride]) === null
    && db_value("SELECT 1 FROM health_import_removed WHERE user_id = ? AND external_id = 'hc-ride'", [$user]) !== null);
$res = health_import_records($user, 'google_health_connect', [[
    'type' => 'workout', 'external_id' => 'hc-ride', 'activity_type' => 'biking',
    'started_at' => $d(1) . ' 12:00:00', 'ended_at' => $d(1) . ' 13:00:00',
]]);
check('…the next sync sends it again and it stays away', db_value("SELECT COUNT(*) FROM workouts WHERE user_id = ? AND external_id = 'hc-ride'", [$user]) == 0);
health_import_records($user, 'google_health_connect', [[
    'type' => 'workout', 'external_id' => 'hc-swim', 'activity_type' => 'swimming_pool',
    'started_at' => $d(1) . ' 08:00:00', 'ended_at' => $d(1) . ' 08:30:00',
]]);
check('…other synced trainings still arrive', db_value("SELECT COUNT(*) FROM workouts WHERE user_id = ? AND external_id = 'hc-swim'", [$user]) == 1);

db_run('INSERT INTO users (username) VALUES (?)', ['me_' . bin2hex(random_bytes(4))]);
$other = (int) db_value('SELECT MAX(id) FROM users');
$theirs = health_manual_workout($other, ['date' => $d(2), 'type' => 'running', 'start' => '06:00', 'minutes' => '30'], $today->setTime(9, 0));
check('someone else\'s training cannot be removed', !health_manual_remove_workout($user, (int) $theirs['workout_id'])['ok']
    && db_value('SELECT id FROM workouts WHERE id = ?', [$theirs['workout_id']]) !== null);
check('nor one that is not there', !health_manual_remove_workout($user, 0)['ok']);
db_run('DELETE FROM users WHERE id = ?', [$other]);

/* -------------------------------------------- the history */
echo "A past day's recorded scores\n";

/* Sentinels: a score of 1 recorded for some days, as if worked out before
   the entry. */
$put = static function (string $date) use ($user): void {
    foreach (['sleep', 'training', 'overall'] as $domain) {
        db_run('INSERT INTO daily_scores (user_id, score_date, domain, score, algorithm_version) VALUES (?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE score = 1', [$user, $date, $domain, HEALTH_SCORE_VERSION]);
    }
};
$score = static fn (string $date, string $domain): ?int => ($v = db_value(
    'SELECT score FROM daily_scores WHERE user_id = ? AND score_date = ? AND domain = ?', [$user, $date, $domain])) === null ? null : (int) $v;
/* Three nights before it, so that week has a sleep score to change. */
foreach ([28, 27, 26] as $ago) {
    health_manual_sleep($user, $d($ago), '23:00', '07:00', $today->setTime(9, 0));
}
foreach ([25, 24, 22, 19, 18, 17, 30] as $ago) {
    $put($d($ago));
}

$r = health_manual_sleep($user, $d(25), '22:45', '07:15', $today->setTime(9, 0));
check('a night 25 days ago: saved', $r['ok']);
check('…that day\'s sleep and overall scores worked out again', !in_array($score($d(25), 'sleep'), [1, null], true) && !in_array($score($d(25), 'overall'), [1, null], true),
    $score($d(25), 'sleep') . ' / ' . $score($d(25), 'overall'));
check('…and the days whose 7 days reach back over it', $score($d(24), 'sleep') !== 1 && $score($d(19), 'sleep') !== 1);
check('…not the day after those, nor any day before', $score($d(18), 'sleep') === 1 && $score($d(30), 'sleep') === 1
    && $score($d(17), 'sleep') === 1);
$history = health_score_history($user, 30, $today->setTime(9, 0));
check('the history (Scorekompas, Verloop) shows the new day', ($history[$d(25)]['sleep']['score'] ?? 1) === $score($d(25), 'sleep')
    && $score($d(25), 'sleep') !== null);

$put($d(25));
$w = health_manual_workout($user, ['date' => $d(25), 'type' => 'running', 'start' => '17:00', 'minutes' => '50'], $today->setTime(9, 0));
check('a training 25 days ago: that day\'s training score worked out again', $w['ok'] && $score($d(25), 'training') !== 1,
    (string) $score($d(25), 'training'));
$put($d(25));
health_manual_remove_workout($user, (int) $w['workout_id']);
check('…and again when it is removed', $score($d(25), 'training') !== 1);

check('today is never written as a past day', health_score_restore_days($user, $today->format('Y-m-d')) === []);
$done = health_score_restore_days($user, $d(2));
check('a day close to today stops at yesterday', $done === [$d(2), $d(1)], json_encode($done));

/* ------------------------------------------- strength training */
echo "Krachttraining\n";

check('Health Connect\'s strength_training is strength', health_score_is_strength(['type' => 'strength_training']));
check('…as weightlifting and calisthenics still are', health_score_is_strength(['type' => 'weightlifting'])
    && health_score_is_strength(['type' => 'calisthenics']) && !health_score_is_strength(['type' => 'running']));
check('…for the insights too', in_array('strength_training', (array) insights_config()['data']['strength_types'], true));

$k = health_manual_workout($user, ['date' => $d(3), 'type' => 'strength_training', 'start' => '19:00', 'minutes' => '45'], $today->setTime(9, 0));
health_import_records($user, 'google_health_connect', [[
    'type' => 'workout', 'external_id' => 'hc-gym', 'activity_type' => 'strength_training',
    'started_at' => $d(4) . ' 19:00:00', 'ended_at' => $d(4) . ' 20:00:00',
]]);
$byId = array_column(health_workout_records($user), null, 'id');
$gym  = (int) db_value("SELECT id FROM workouts WHERE user_id = ? AND external_id = 'hc-gym'", [$user]);
check('entered by hand: counted as strength', $k['ok'] && health_score_is_strength($byId[$k['workout_id']] ?? []));
check('synced from Health Connect: counted as strength', health_score_is_strength($byId[$gym] ?? []));
$t = health_score_now($user, $today->setTime(9, 0));
$strength = (float) ($t['training']['facts']['strength_per_week'] ?? 0);
db_run("UPDATE workouts SET activity_type = 'running' WHERE id IN (?, ?)", [$k['workout_id'], $gym]);
$without = (float) (health_score_now($user, $today->setTime(9, 0))['training']['facts']['strength_per_week'] ?? 0);
db_run("UPDATE workouts SET activity_type = 'strength_training' WHERE id IN (?, ?)", [$k['workout_id'], $gym]);
check('the week\'s strength minutes count them (none without them)', $strength > 45 && $without === 0.0, $strength . ' / ' . $without);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

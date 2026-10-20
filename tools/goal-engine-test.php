<?php
/**
 * The goal engine, on a real database (docs/GOALS.md).
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/goal-engine-test.php
 *
 * Everything that decides what a goal says, checked against figures worked
 * out by hand:
 *
 *   - config/goals.php and migration 025's vocabulary tables agree
 *   - units: typed ↔ stored, imperial defaults, how a figure is written
 *   - dates: calendar months clamped to the month's end, leap years, the
 *     five looptijden, days between two dates across a clock change
 *   - a per-period streak's periods and the half-up share of a short last one
 *   - Mijlpaal: from the baseline by the latest value, down again, bounded,
 *     a target equal to its baseline, and where the baseline comes from
 *   - Streak, daily: met / missed / no data / today still open, the share of
 *     ALL the period's days, never finished before its end date
 *   - Streak, per period: met before the period is over, missed only after
 *   - Optellen: from zero, only inside the period, finished at 100%
 *   - a finished goal's final percentage is frozen and never overwritten
 *   - Afgerond's order, the first goal primary, the primary passed on
 *   - what the wizard (and the assistant) may not make, the one-time key
 *   - what may be logged on a goal's page
 *   - the goal tables only point at each other and at users
 *
 * One account named `ge_…`, removed at the end with everything it made.
 * Never point it at a live database. Exit code 0 when every check passes.
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
require_once $root . '/includes/goal-create.php';
require_once $root . '/lib/render.php';
require_once $root . '/lib/goals.php';
require_once $root . '/lib/hydrate-goals.php';

if (!db_available()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable.\n");
    exit(2);
}

if (!goal_model_available()) {
    fwrite(STDERR, "Migration 025 is not in this database yet.\n");
    exit(2);
}

i18n_force('nl', 'metric');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    echo "\n" . $title . "\n";
}

function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return $a !== null && $b !== null && abs($a - $b) < $eps;
}

function d(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

function ymd(?DateTimeInterface $date): string
{
    return $date === null ? 'null' : $date->format('Y-m-d');
}

/* ---------------------------------------------------------------- account */

db_run('INSERT INTO users (username) VALUES (?)', ['ge_' . bin2hex(random_bytes(4))]);
$userId = (int) db_insert_id();

register_shutdown_function(static function () use ($userId): void {
    db_run('DELETE FROM users WHERE id = ?', [$userId]);
});

$today = goal_today();
$day   = static fn (int $offset): DateTimeImmutable => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' day');
$on    = static fn (int $offset): string => $day($offset)->format('Y-m-d');

/** Makes a goal through the one way in, and fails loudly when it cannot. */
$make = static function (array $in) use ($userId, $today): int {
    $result = goal_create_from_input($userId, $in, false, $today);
    if (!$result['ok']) {
        throw new RuntimeException('Could not make "' . ($in['name'] ?? '?') . '": ' . $result['error'] . ' (' . ($result['field'] ?? '-') . ')');
    }
    return (int) $result['goal_id'];
};

$row = static fn (int $goalId): array => goal_get($userId, $goalId) ?? [];

/** Removes every goal of the account, so the board has room again. */
$clear = static function () use ($userId): void {
    db_run('DELETE FROM goals WHERE user_id = ?', [$userId]);
};

$metricId = static fn (string $code): int => (int) db_value('SELECT id FROM health_metric_types WHERE code = ?', [$code]);

$reading = static function (string $code, string $date, float $value, string $time = '12:00:00') use ($userId, $metricId): void {
    db_run(
        'INSERT INTO health_metrics (user_id, metric_type_id, value, recorded_at) VALUES (?, ?, ?, ?)',
        [$userId, $metricId($code), $value, $date . ' ' . $time]
    );
};

$weighIn = static function (string $date, float $kg, string $time = '08:00:00') use ($userId): void {
    db_run(
        'INSERT INTO user_measurements (user_id, measurement_type, value, unit, measured_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, 'weight', $kg, 'kg', $date . ' ' . $time]
    );
};

$workout = static function (string $date, int $minutes = 45, string $time = '18:00:00') use ($userId): void {
    db_run(
        'INSERT INTO workouts (user_id, activity_type, started_at, duration_seconds) VALUES (?, ?, ?, ?)',
        [$userId, 'strength', $date . ' ' . $time, $minutes * 60]
    );
};

try {

/* ======================================================================
   THE VOCABULARY — config/goals.php and migration 025 say the same
   ====================================================================== */

section('Vocabulary: config/goals.php against goal_categories / goal_units / goal_measurements');

$catalogue = goal_catalogue();

$dbCategories = array_column(db_all('SELECT code FROM goal_categories ORDER BY sort_order'), 'code');
check('six categories, in the same order',
    $dbCategories === array_keys($catalogue['categories']) && count($dbCategories) === 6,
    implode(',', $dbCategories) . ' vs ' . implode(',', array_keys($catalogue['categories'])));

check('the categories are named Slaap, Voeding, Training, Activiteit, Lichaam, Herstel & balans',
    array_column($catalogue['categories'], 'label') === ['Slaap', 'Voeding', 'Training', 'Activiteit', 'Lichaam', 'Herstel & balans'],
    implode(', ', array_column($catalogue['categories'], 'label')));

$dbUnits = [];
foreach (db_all('SELECT code, canonical, factor FROM goal_units') as $u) {
    $dbUnits[(string) $u['code']] = $u;
}
$unitTrouble = [];
foreach ($catalogue['goal_units'] as $code => $unit) {
    $db = $dbUnits[$code] ?? null;
    if ($db === null || $db['canonical'] !== $unit['canonical'] || abs((float) $db['factor'] - (float) $unit['factor']) > 1e-9) {
        $unitTrouble[] = $code;
    }
}
check('every unit: the same stored unit and factor', $unitTrouble === [] && count($dbUnits) === count($catalogue['goal_units']),
    'differs: ' . implode(', ', $unitTrouble) . ' (' . count($dbUnits) . ' in the database, ' . count($catalogue['goal_units']) . ' in config)');

$dbMeasurements = [];
foreach (db_all('SELECT * FROM goal_measurements ORDER BY sort_order') as $m) {
    $dbMeasurements[(string) $m['code']] = $m;
}
$measureTrouble = [];
foreach (goal_measurements() as $code => $m) {
    $db = $dbMeasurements[$code] ?? null;
    $same = $db !== null
        && $db['category_code'] === $m['category']
        && $db['source_kind'] === $m['source_kind']
        && ($db['source_key'] ?? null) === ($m['source_key'] ?? null)
        && $db['unit_code'] === $m['unit_code']
        && $db['direction'] === $m['direction']
        && (bool) $db['allows_milestone'] === in_array('milestone', $m['types'], true)
        && (bool) $db['allows_accumulate'] === in_array('accumulate', $m['types'], true)
        && (bool) $db['allows_daily'] === (in_array('streak', $m['types'], true) && in_array('daily', $m['variants'], true))
        && (bool) $db['allows_period'] === (in_array('streak', $m['types'], true) && in_array('period', $m['variants'], true));
    if (!$same) {
        $measureTrouble[] = $code;
    }
    if (!in_array($m['unit_code'], $m['unit_codes'], true)) {
        $measureTrouble[] = $code . ' (stored unit not offered)';
    }
    foreach ($m['unit_codes'] as $u) {
        if (($catalogue['goal_units'][$u]['canonical'] ?? null) !== ($catalogue['goal_units'][$m['unit_code']]['canonical'] ?? '?')) {
            $measureTrouble[] = $code . ' (' . $u . ' is not stored as ' . $m['unit_code'] . ')';
        }
    }
}
check('every measurement: category, source, stored unit, direction and types agree',
    $measureTrouble === [] && array_keys($dbMeasurements) === array_keys(goal_measurements()),
    implode(', ', $measureTrouble));

$auto = array_filter(goal_measurements(), 'goal_measurement_auto');
check('every measurement read from data has a source key', array_filter($auto, static fn ($m) => empty($m['source_key'])) === []);

check('the six categories each have at least one measurement',
    array_diff(array_keys($catalogue['categories']), array_unique(array_column(goal_measurements(), 'category'))) === []);

check('a Mijlpaal on weight: yes; Optellen on weight: no',
    goal_type_allowed(goal_measurement('weight'), 'milestone') && !goal_type_allowed(goal_measurement('weight'), 'accumulate'));
check('trainingen per week: yes; water per week: no',
    goal_type_allowed(goal_measurement('workouts'), 'streak', 'period') && !goal_type_allowed(goal_measurement('water'), 'streak', 'period'));
check('a streak needs its variant', !goal_type_allowed(goal_measurement('steps'), 'streak', null));
check('an Eigen gewoonte may be anything, kept by hand',
    goal_measurement('custom')['custom'] === true && !goal_measurement_auto(goal_measurement(null))
    && goal_type_allowed(goal_measurement(null), 'milestone') && goal_type_allowed(goal_measurement(null), 'streak', 'period'));
check('an unknown measurement is null', goal_measurement('vo2max') === null);
check('steps are a day\'s total that builds up; weight is not', goal_measurement('steps')['daily'] === true && goal_measurement('weight')['daily'] === false
    && goal_measurements()['steps']['daily'] === true);


/* ======================================================================
   UNITS
   ====================================================================== */

section('Units');

$kg = goal_unit('kg');
$lb = goal_unit('lb');
$h  = goal_unit('h');

check('176 lb is stored as 79,832 kg', near(goal_to_stored(176, $lb), 79.832, 0.001), (string) goal_to_stored(176, $lb));
check('… and reads back as 176 lb', near(goal_from_stored(goal_to_stored(176, $lb), $lb), 176.0, 1e-9));
check('7,5 uur is stored as 450 min', near(goal_to_stored(7.5, $h), 450.0, 1e-9));
check('2 liter is 2000 ml, stored as 2 l', near(goal_to_stored(2000, goal_unit('ml')), 2.0, 1e-9));
check('67,6 fl oz is stored as 2,0 l', near(goal_to_stored(67.6, goal_unit('floz')), 1.9992, 0.0005), (string) goal_to_stored(67.6, goal_unit('floz')));
check('5 mi is stored as 8,047 km', near(goal_to_stored(5, goal_unit('mi')), 8.04672, 1e-6));
check('"8 uur" for 480 min in hours', goal_value_text(480, $h) === '8 uur', goal_value_text(480, $h));
check('"7,5 uur" for 450 min in hours', goal_value_text(450, $h) === '7,5 uur', goal_value_text(450, $h));
check('"1 uur" — singular', goal_value_text(60, $h) === '1 uur', goal_value_text(60, $h));
check('"10.000 stappen"', goal_value_text(10000, goal_unit('steps')) === '10.000 stappen', goal_value_text(10000, goal_unit('steps')));
check('"1 training" — singular', goal_value_text(1, goal_unit('sessions')) === '1 training', goal_value_text(1, goal_unit('sessions')));
check('"85%" — attached', goal_value_text(85, goal_unit('pct')) === '85%', goal_value_text(85, goal_unit('pct')));
check('an Eigen gewoonte\'s own word: "12 push-ups"', goal_value_text(12, goal_unit_custom(' push-ups ')) === '12 push-ups');
check('weight starts in kg for metric', goal_default_unit(goal_measurement('weight'), false) === 'kg');
check('weight starts in lb for imperial', goal_default_unit(goal_measurement('weight'), true) === 'lb');
check('steps have no imperial unit: stappen either way', goal_default_unit(goal_measurement('steps'), true) === 'steps');
check('a goal row with a unit code reads that unit', goal_unit_of(['unit_code' => 'lb'])['code'] === 'lb');
check('a goal row with its own word reads that', goal_unit_of(['unit_code' => null, 'custom_unit' => 'pagina\'s'])['label'] === 'pagina\'s');

foreach ([['10.000', 10000.0], ['2.200', 2200.0], ['1.234,5', 1234.5], ['72,5', 72.5], ['72.5', 72.5], ['0.500', 0.5], ['15000', 15000.0]] as [$typed, $want]) {
    check(sprintf('typed in Dutch: "%s" is %s', $typed, $want), goal_parse_number($typed) === $want, var_export(goal_parse_number($typed), true));
}
check('typed in Dutch: "veel" is no number', goal_parse_number('veel') === null);

i18n_force('en', 'imperial');
foreach ([['10,000', 10000.0], ['1,234.5', 1234.5], ['72.5', 72.5], ['72,5', 72.5]] as [$typed, $want]) {
    check(sprintf('typed in English: "%s" is %s', $typed, $want), goal_parse_number($typed) === $want, var_export(goal_parse_number($typed), true));
}
check('English: "10,000 steps"', goal_value_text(10000, goal_unit('steps')) === '10,000 steps', goal_value_text(10000, goal_unit('steps')));
check('English: "176.4 lb" for 80 kg', goal_value_text(80, $lb) === '176.4 lb', goal_value_text(80, $lb));
check('imperial account: weight defaults to lb', goal_default_unit(goal_measurement('weight')) === 'lb');
i18n_force('nl', 'metric');

/* ======================================================================
   DATES
   ====================================================================== */

section('Dates: calendar months, leap years, looptijden');

$months = [
    ['2024-01-31', 1, '2024-02-29', '31 jan + 1 maand in a leap year'],
    ['2023-01-31', 1, '2023-02-28', '31 jan + 1 maand otherwise'],
    ['2024-02-29', 12, '2025-02-28', '29 feb + 1 jaar'],
    ['2024-02-29', 48, '2028-02-29', '29 feb + 4 jaar'],
    ['2024-03-31', -1, '2024-02-29', '31 mrt − 1 maand'],
    ['2024-01-15', -1, '2023-12-15', 'back across a year'],
    ['2024-12-15', 1, '2025-01-15', 'on across a year'],
    ['2024-08-31', 3, '2024-11-30', '31 aug + 3 maanden'],
    ['2024-01-31', 3, '2024-04-30', 'from the 31st, never drifting'],
    ['2024-01-31', 2, '2024-03-31', '… the 31st again where it exists'],
    ['2024-05-31', -15, '2023-02-28', 'far back, clamped'],
];
foreach ($months as [$from, $n, $want, $label]) {
    $got = ymd(goal_add_months(d($from), $n));
    check(sprintf('%s: %s %+d → %s', $label, $from, $n, $want), $got === $want, 'got ' . $got);
}

$ends = [
    ['2025-01-06', 'week', '2025-01-12', '1 week from Monday ends on Sunday'],
    ['2025-01-31', 'month', '2025-02-27', '1 maand from 31 jan (not leap)'],
    ['2024-01-31', 'month', '2024-02-28', '1 maand from 31 jan (leap)'],
    ['2024-01-30', 'month', '2024-02-28', '1 maand from 30 jan (leap): 29 feb starts the next'],
    ['2024-02-01', 'month', '2024-02-29', '1 maand from 1 feb (leap) is all of February'],
    ['2025-03-01', 'month', '2025-03-31', '1 maand from the 1st is the whole month'],
    ['2024-11-30', 'quarter', '2025-02-27', '3 maanden from 30 nov'],
    ['2025-08-31', 'halfyear', '2026-02-27', '6 maanden from 31 aug'],
    ['2024-02-29', 'year', '2025-02-27', '1 jaar from 29 feb'],
    ['2025-01-01', 'year', '2025-12-31', '1 jaar from 1 jan'],
    ['2025-12-28', 'week', '2026-01-03', '1 week across new year'],
];
foreach ($ends as [$from, $duration, $want, $label]) {
    $got = ymd(goal_duration_end(d($from), $duration));
    check(sprintf('%s → %s', $label, $want), $got === $want, 'got ' . $got);
    check(sprintf('… and %s → %s reads back as %s', $from, $want, $duration),
        goal_duration_of(d($from), d($want)) === $duration);
}
check('an unknown looptijd has no end', goal_duration_end(d('2025-01-01'), 'decade') === null);
check('a hand-picked end is no looptijd', goal_duration_of(d('2025-01-01'), d('2025-01-20')) === null);

check('days between, both included: 1–29 feb 2024 is 29', goal_days_between(d('2024-02-01'), d('2024-02-29')) === 29);
check('across the spring clock change: 29–31 mrt 2025 is 3', goal_days_between(d('2025-03-29'), d('2025-03-31')) === 3);
check('across the autumn clock change: 25–27 okt 2025 is 3', goal_days_between(d('2025-10-25'), d('2025-10-27')) === 3);
check('a whole leap year is 366 days', goal_days_between(d('2024-01-01'), goal_duration_end(d('2024-01-01'), 'year')) === 366);

/* ======================================================================
   PERIODS AND HALF-UP
   ====================================================================== */

section('Per-period streak: periods from the start date, a short last one');

foreach ([[2.5, 3], [2.49, 2], [0.5, 1], [0.4999, 0], [1.2857, 1], [3.5, 4], [0.0, 0], [7.0, 7]] as [$in, $want]) {
    check(sprintf('half up: %s → %d', $in, $want), goal_round_half_up($in) === $want, (string) goal_round_half_up($in));
}

$p = goal_periods(d('2025-01-01'), d('2025-01-17'), 'week', 3);
check('17 days in weeks: three periods', count($p) === 3);
check('week 1 is the goal\'s first seven days (Wednesday to Tuesday)',
    ymd($p[0]['start']) === '2025-01-01' && ymd($p[0]['end']) === '2025-01-07' && $p[0]['required'] === 3);
check('the short last one: 3 days, 3 × 3/7 = 1,29 → 1',
    ymd($p[2]['start']) === '2025-01-15' && ymd($p[2]['end']) === '2025-01-17' && $p[2]['days'] === 3 && $p[2]['full_days'] === 7 && $p[2]['required'] === 1,
    json_encode(['days' => $p[2]['days'], 'required' => $p[2]['required']]));

$p = goal_periods(d('2025-03-01'), d('2025-04-15'), 'month', 1);
check('half a 30-day month: 1 × 15/30 = 0,5 → 1 (half up)',
    count($p) === 2 && $p[1]['days'] === 15 && $p[1]['full_days'] === 30 && $p[1]['required'] === 1,
    json_encode(array_map(static fn ($x) => [$x['days'], $x['full_days'], $x['required']], $p)));

$p = goal_periods(d('2025-04-01'), d('2025-05-15'), 'month', 1);
check('15 days of a 31-day month: 0,48 → 0, asks nothing',
    count($p) === 2 && $p[1]['required'] === 0);

$p = goal_periods(d('2024-01-31'), d('2024-04-29'), 'month', 4);
check('months from 31 jan: 31 jan–28 feb, 29 feb–30 mrt, 31 mrt–29 apr',
    ymd($p[0]['end']) === '2024-02-28' && ymd($p[1]['start']) === '2024-02-29' && ymd($p[1]['end']) === '2024-03-30'
    && ymd($p[2]['start']) === '2024-03-31' && ymd($p[2]['end']) === '2024-04-29' && count($p) === 3 && $p[2]['required'] === 4,
    json_encode(array_map(static fn ($x) => [ymd($x['start']), ymd($x['end'])], $p)));

$p = goal_periods(d('2025-01-01'), d('2025-12-31'), 'quarter', 2);
check('a year in quarters: four whole ones', count($p) === 4 && array_sum(array_column($p, 'required')) === 8);
check('no periods for nonsense', goal_periods(d('2025-01-01'), d('2025-02-01'), 'fortnight', 2) === []
    && goal_periods(d('2025-01-01'), d('2025-02-01'), 'week', 0) === []);

/* ======================================================================
   MIJLPAAL, PURE
   ====================================================================== */

section('Mijlpaal: the percentage');

$cases = [
    [80, 100, 90, 50.0, 'up: 80 → 100 at 90 is 50%'],
    [80, 100, 85, 25.0, '… and back at 85 it is 25% (latest, not best)'],
    [80, 100, 70, 0.0, 'below the start: 0%, never negative'],
    [80, 100, 110, 100.0, 'past the target: 100%, never more'],
    [90, 80, 85, 50.0, 'down: 90 → 80 at 85 is 50%'],
    [90, 80, 95, 0.0, 'down, gone up instead: 0%'],
    [90, 80, 79, 100.0, 'down, past the target: 100%'],
    [0, 3, 1, 33.33, 'rounded to two decimals'],
    [80, 80, 80, 100.0, 'target equal to the baseline, reached: 100% (no division by zero)'],
    [80, 80, 79.5, 0.0, 'target equal to the baseline, not reached: 0%'],
];
foreach ($cases as [$base, $target, $current, $want, $label]) {
    $got = goal_milestone_percent((float) $base, (float) $target, (float) $current);
    check($label, near($got, $want, 0.001), (string) $got);
}
check('no value yet: 0%', goal_milestone_percent(80, 100, null) === 0.0);
check('a day meets "at least": 10.000 of 10.000', goal_day_meets(10000, 10000, 'increase'));
check('a day meets "at most": 58 under 60', goal_day_meets(58, 60, 'decrease') && !goal_day_meets(61, 60, 'decrease'));
check('a share never divides by zero', goal_share_percent(3, 0) === 0.0 && goal_share_percent(12, 10) === 100.0);

/* ======================================================================
   WHERE A BASELINE COMES FROM
   ====================================================================== */

section('Baseline: the start date, else the most recent before it, else typed');

$weight = goal_measurement('weight');
check('no weigh-ins: no baseline (the person types it)', goal_baseline_for($userId, $weight, $day(-5), $today) === null);

$weighIn($on(-400), 90.0);
check('a weigh-in more than a year before the start does not count', goal_baseline_for($userId, $weight, $day(-5), $today) === null);

$weighIn($on(-20), 82.0);
$weighIn($on(-8), 81.0, '07:00:00');
$weighIn($on(-8), 81.4, '21:00:00');
$b = goal_baseline_for($userId, $weight, $day(-5), $today);
check('before the start: the most recent day, its latest weigh-in',
    $b !== null && near($b['value'], 81.4, 1e-6) && $b['on'] === $on(-8) && $b['source'] === 'before_start', json_encode($b));

$weighIn($on(-5), 80.6);
$b = goal_baseline_for($userId, $weight, $day(-5), $today);
check('a weigh-in on the start date wins', $b !== null && near($b['value'], 80.6, 1e-6) && $b['source'] === 'start_day', json_encode($b));

$reading('steps', $on(-1), 9000);
$reading('steps', $on(0), 2000, '09:00:00');
$b = goal_baseline_for($userId, goal_measurement('steps'), $today, $today);
check('steps, starting today: yesterday\'s whole day, not this morning\'s 2.000',
    $b !== null && near($b['value'], 9000) && $b['on'] === $on(-1), json_encode($b));

check('Meditatie has no data to start from', goal_baseline_for($userId, goal_measurement('meditation'), $today, $today) === null);
check('weight is read automatically for this account now', goal_user_has_data($userId, $weight, $today));
check('Meditatie never is', !goal_user_has_data($userId, goal_measurement('meditation'), $today));

/* ======================================================================
   MIJLPAAL, END TO END
   ====================================================================== */

section('Mijlpaal: made, followed, finished');

$clear();
$lose = $make(['name' => 'Naar 78 kg', 'measurement' => 'weight', 'type' => 'milestone', 'unit' => 'kg',
               'target_value' => '78', 'direction' => 'decrease', 'start_date' => $on(-5), 'duration' => 'quarter']);
$g = $row($lose);
check('read automatically, baseline from the start date (80,6)',
    $g['tracking'] === 'auto' && near((float) $g['baseline_value'], 80.6, 1e-6) && $g['baseline_source'] === 'start_day' && $g['baseline_on'] === $on(-5));
check('the first goal is primary', $g['priority'] === 'primary');
check('the end date: 3 maanden from the start, the day before',
    $g['end_date'] === ymd(goal_duration_end($day(-5), 'quarter')));

$weighIn($on(-2), 79.3);
$progress = goal_refresh($userId, $lose, $today);
check('80,6 → 78 at 79,3 is 50%', near($progress['percent'], 50.0), (string) $progress['percent']);

$weighIn($on(-1), 79.95);
$progress = goal_refresh($userId, $lose, $today);
check('back up to 79,95: 25% — it follows the latest value', near($progress['percent'], 25.0), (string) $progress['percent']);
check('stored with the goal', near((float) $row($lose)['progress_pct'], 25.0) && near((float) $row($lose)['current_value'], 79.95, 1e-6));
check('… and in the day\'s snapshot', near((float) db_value('SELECT percent_complete FROM goal_progress WHERE goal_id = ? AND recorded_on = ?', [$lose, $on(0)]), 25.0));

$weighIn($on(0), 81.0);
$progress = goal_refresh($userId, $lose, $today);
check('above the start: 0%, not negative', near($progress['percent'], 0.0), (string) $progress['percent']);
check('… still running', $row($lose)['status'] === 'active');

$weighIn($on(0), 77.9, '20:00:00');
$progress = goal_refresh($userId, $lose, $today);
$g = $row($lose);
check('77,9 that evening: 100%, finished at once', near($progress['percent'], 100.0) && $g['status'] === 'completed');
check('final 100%, with the moment', near((float) $g['final_pct'], 100.0) && $g['completed_at'] !== null);

/* The final percentage stays what it was. */
$weighIn($on(0), 83.0, '22:00:00');
$progress = goal_refresh($userId, $lose, $today);
check('a later weigh-in does not change a finished goal', near($progress['percent'], 100.0) && near((float) $row($lose)['final_pct'], 100.0));
check('finishing it again is refused', goal_finish($userId, $lose, 40.0, 'ended', null) === false
    && $row($lose)['status'] === 'completed' && near((float) $row($lose)['final_pct'], 100.0));
check('a finished goal cannot be paused', goal_set_status($userId, $lose, 'paused') === false);

/* A Mijlpaal kept by hand, typed in lb. */
$clear();
$lift = $make(['name' => 'Bankdrukken', 'measurement' => 'custom', 'category' => 'training', 'type' => 'milestone',
               'unit' => 'kg', 'target_value' => '100', 'baseline_value' => '80', 'start_date' => $on(0), 'duration' => 'month']);
$g = $row($lift);
check('an Eigen gewoonte is kept by hand with a typed baseline',
    $g['tracking'] === 'manual' && $g['measurement_code'] === null && $g['category_code'] === 'training'
    && near((float) $g['baseline_value'], 80.0) && $g['baseline_source'] === 'manual');
check('… its direction is the default: higher', $g['direction'] === 'increase');

/* ======================================================================
   STREAK, DAILY
   ====================================================================== */

section('Streak, every day: met, missed, no data, today still open');

$clear();
$read = $make(['name' => 'Elke dag lezen', 'measurement' => 'custom', 'category' => 'recovery', 'type' => 'streak',
               'variant' => 'daily', 'unit' => 'times', 'target_value' => '1', 'start_date' => $on(-4), 'end_date' => $on(5)]);
$g = $row($read);
foreach ([-4, -2, -1] as $offset) {
    goal_record_entry($userId, $g, null, true, $day($offset), $today);
}
$progress = goal_refresh($userId, $read, $today);
$states = array_column($progress['days'], 'state', 'date');
check('ticked, not ticked, ticked, ticked, today open',
    [$states[$on(-4)], $states[$on(-3)], $states[$on(-2)], $states[$on(-1)], $states[$on(0)]] === ['met', 'missed', 'met', 'met', 'pending'],
    json_encode(array_values($states)));
check('3 of the period\'s 10 days: 30%', $progress['met'] === 3 && $progress['counted'] === 10 && near($progress['percent'], 30.0), (string) $progress['percent']);
check('the days are kept with the goal', (int) db_value('SELECT COUNT(*) FROM goal_day_results WHERE goal_id = ?', [$read]) === 5);

goal_record_entry($userId, $g, null, true, $today, $today);
goal_record_entry($userId, $g, null, true, $today, $today);
check('ticking today twice is one tick', (int) db_value('SELECT COUNT(*) FROM goal_entries WHERE goal_id = ? AND entry_date = ?', [$read, $on(0)]) === 1);
$progress = goal_refresh($userId, $read, $today);
check('today ticked: met, 40%', $progress['days'][4]['state'] === 'met' && near($progress['percent'], 40.0));
goal_record_entry($userId, $g, null, false, $today, $today);
$progress = goal_refresh($userId, $read, $today);
check('un-ticked: open again, 30%', $progress['days'][4]['state'] === 'pending' && near($progress['percent'], 30.0));

/* Every day so far met — still not finished. */
$clear();
$walk = $make(['name' => 'Elke dag wandelen', 'measurement' => 'custom', 'category' => 'activity', 'type' => 'streak',
               'variant' => 'daily', 'unit' => 'times', 'target_value' => '1', 'start_date' => $on(-2), 'end_date' => $on(2)]);
$g = $row($walk);
foreach ([-2, -1, 0] as $offset) {
    goal_record_entry($userId, $g, null, true, $day($offset), $today);
}
$progress = goal_refresh($userId, $walk, $today);
check('every day so far met: 60%, and still running', near($progress['percent'], 60.0) && $row($walk)['status'] === 'active');

foreach ([1, 2] as $offset) {
    $later = $day($offset);
    goal_record_entry($userId, $row($walk), null, true, $later, $later);
    goal_refresh($userId, $walk, $later);
}
check('the last day ticked: 100%, but not finished on its own last day', $row($walk)['status'] === 'active' && near((float) $row($walk)['progress_pct'], 100.0));
goal_refresh($userId, $walk, $day(3));
$g = $row($walk);
check('the day after the end: completed at 100%', $g['status'] === 'completed' && near((float) $g['final_pct'], 100.0));
check('… dated at the end of its last day', $g['completed_at'] === $on(2) . ' 23:59:59', (string) $g['completed_at']);

/* A streak that falls short ends with what it had. */
$clear();
$read = $make(['name' => 'Elke dag lezen', 'measurement' => 'custom', 'category' => 'recovery', 'type' => 'streak',
               'variant' => 'daily', 'unit' => 'times', 'target_value' => '1', 'start_date' => $on(-4), 'end_date' => $on(5)]);
foreach ([-4, -2, -1] as $offset) {
    goal_record_entry($userId, $row($read), null, true, $day($offset), $today);
}
goal_refresh($userId, $read, $day(6));
$g = $row($read);
check('after its end date: ended at 30%', $g['status'] === 'ended' && near((float) $g['final_pct'], 30.0), json_encode([$g['status'], $g['final_pct']]));
check('a finished goal takes no more entries',
    goal_record_entry($userId, $g, null, true, $today, $today)['ok'] === false);

/* A daily streak read from data: at most, with a day without data. */
$clear();
$reading('resting_hr', $on(-3), 58);
$reading('resting_hr', $on(-2), 63);
$reading('resting_hr', $on(0), 57);
$calm = $make(['name' => 'Rustig hart', 'measurement' => 'resting_hr', 'type' => 'streak', 'variant' => 'daily',
               'unit' => 'bpm', 'target_value' => '60', 'start_date' => $on(-3), 'end_date' => $on(3)]);
$g = $row($calm);
check('rusthartslag: read automatically, lower is better', $g['tracking'] === 'auto' && $g['direction'] === 'decrease');
$progress = goal_refresh($userId, $calm, $today);
$states = array_column($progress['days'], 'state', 'date');
check('58 met, 63 missed, nothing recorded: no data, 57 met',
    [$states[$on(-3)], $states[$on(-2)], $states[$on(-1)], $states[$on(0)]] === ['met', 'missed', 'unknown', 'met'],
    json_encode(array_values($states)));
check('2 of 7 days: 28,57%', near($progress['percent'], 28.57), (string) $progress['percent']);

/* ======================================================================
   STREAK, PER PERIOD
   ====================================================================== */

section('Streak, per period: met before the period is over, missed after');

$clear();
$workout($on(-9));
$workout($on(-8));
$workout($on(-1));
$gym = $make(['name' => '2 keer per week trainen', 'measurement' => 'workouts', 'type' => 'streak', 'variant' => 'period',
              'frequency' => '2', 'period' => 'week', 'start_date' => $on(-10), 'end_date' => $on(10)]);
$g = $row($gym);
check('read from workouts, counted in trainingen', $g['tracking'] === 'auto' && $g['unit_code'] === 'sessions' && (int) $g['frequency'] === 2);

$progress = goal_refresh($userId, $gym, $today);
$states = array_column($progress['periods'], 'state');
check('week 1 met (2 of 2), week 2 still open at 1 of 2, week 3 still to come',
    $states === ['met', 'pending', 'pending'] && $progress['periods'][1]['achieved'] === 1, json_encode($states));
check('1 of 3 weeks: 33,33%', near($progress['percent'], 33.33), (string) $progress['percent']);

$future = goal_compute($userId, $g, $day(4));
check('once week 2 is over at 1 of 2: missed', $future['periods'][1]['state'] === 'missed');

$workout($on(0));
$progress = goal_refresh($userId, $gym, $today);
check('a second training today: week 2 met before it is over, 66,67%',
    $progress['periods'][1]['state'] === 'met' && near($progress['percent'], 66.67), (string) $progress['percent']);
check('the weeks are kept with the goal', (int) db_value('SELECT COUNT(*) FROM goal_period_results WHERE goal_id = ?', [$gym]) === 3);
check('a training before the start does not count', $progress['periods'][0]['achieved'] === 2);

/* A short last period, and one too short to count. */
$clear();
$stretch = $make(['name' => '3 keer per week stretchen', 'measurement' => 'custom', 'category' => 'recovery', 'type' => 'streak',
                  'variant' => 'period', 'frequency' => '3', 'period' => 'week', 'start_date' => $on(0), 'end_date' => $on(9)]);
$progress = goal_compute($userId, $row($stretch), $today);
check('10 days: a whole week (3) and 3 days (3 × 3/7 → 1)',
    array_column($progress['periods'], 'required') === [3, 1] && $progress['periods'][1]['partial'] === true);

$clear();
$once = $make(['name' => 'Eén keer per week zwemmen', 'measurement' => 'custom', 'category' => 'training', 'type' => 'streak',
               'variant' => 'period', 'frequency' => '1', 'period' => 'week', 'start_date' => $on(0), 'end_date' => $on(9)]);
$g = $row($once);
goal_record_entry($userId, $g, null, true, $today, $today);
$progress = goal_compute($userId, $g, $day(10));
check('3 days of a week at 1 per week ask nothing: skipped, not counted',
    array_column($progress['periods'], 'state') === ['met', 'skipped'] && $progress['counted'] === 1 && near($progress['percent'], 100.0),
    json_encode(array_column($progress['periods'], 'state')));

/* ======================================================================
   OPTELLEN
   ====================================================================== */

section('Optellen: from zero, inside the period, finished at 100%');

$clear();
$pages = $make(['name' => '100 pagina\'s lezen', 'measurement' => 'custom', 'category' => 'recovery', 'type' => 'accumulate',
                'unit' => 'pages', 'target_value' => '100', 'start_date' => $on(-5), 'end_date' => $on(5)]);
$g = $row($pages);
$progress = goal_refresh($userId, $pages, $today);
check('starts at zero', near($progress['percent'], 0.0) && near($progress['total'], 0.0));

db_run('INSERT INTO goal_entries (goal_id, entry_date, value) VALUES (?, ?, ?)', [$pages, $on(-6), 500]);
goal_record_entry($userId, $g, 30, null, $day(-3), $today);
goal_record_entry($userId, $g, 40, null, $today, $today);
$progress = goal_refresh($userId, $pages, $today);
check('30 + 40 inside the period: 70% (the 500 before the start is not counted)', near($progress['percent'], 70.0) && near($progress['total'], 70.0),
    (string) $progress['percent']);
check('… still running', $row($pages)['status'] === 'active');

goal_record_entry($userId, $g, 45, null, $today, $today);
$progress = goal_refresh($userId, $pages, $today);
$g = $row($pages);
check('115 of 100: 100%, never more, finished at once', near($progress['percent'], 100.0) && $g['status'] === 'completed' && near((float) $g['final_pct'], 100.0));

/* Read from data: only what falls inside the period. */
$clear();
$workout($on(-30));
$total = $make(['name' => '10 trainingen', 'measurement' => 'workouts', 'type' => 'accumulate', 'unit' => 'sessions',
                'target_value' => '10', 'start_date' => $on(-5), 'end_date' => $on(20)]);
$progress = goal_refresh($userId, $total, $today);
check('trainingen since the start only: 4 of 10 (−1, 0 and two the same day count; −8, −9, −30 do not)',
    near($progress['total'], 2.0) || near($progress['total'], 3.0) || near($progress['total'], 4.0), (string) $progress['total']);
check('… exactly the ones from −5 on: 2 (yesterday and today)', near($progress['total'], 2.0), (string) $progress['total']);

/* ======================================================================
   AFGEROND'S ORDER, AND THE PRIMARY GOAL
   ====================================================================== */

section('Afgerond: highest final percentage first, then the newest');

$sorted = goals_sort_stable([
    ['id' => 5, 'final_percent' => 40.0, 'created_at' => '2025-01-02 10:00:00'],
    ['id' => 3, 'final_percent' => 100.0, 'created_at' => '2025-01-01 10:00:00'],
    ['id' => 4, 'final_percent' => 40.0, 'created_at' => '2025-01-03 10:00:00'],
    ['id' => 6, 'final_percent' => 100.0, 'created_at' => '2025-01-05 10:00:00'],
    ['id' => 7, 'final_percent' => 87.5, 'created_at' => '2024-12-01 10:00:00'],
    ['id' => 8, 'final_percent' => 0.0, 'created_at' => '2025-02-01 10:00:00'],
], 'goals_completed_compare');
check('100 (newer), 100, 87,5, 40 (newer), 40, 0', array_column($sorted, 'id') === [6, 3, 7, 4, 5, 8], implode(',', array_column($sorted, 'id')));

/* On the real board: three finished goals of the account. */
$clear();
$mk = static fn (string $name, string $created, float $pct, string $status) => db_run(
    "INSERT INTO goals (user_id, name, category_code, goal_type, streak_variant, tracking, unit_code, direction,
                        target_value, start_date, end_date, status, priority, final_pct, progress_pct, completed_at, created_at)
     VALUES (?, ?, 'recovery', 'accumulate', NULL, 'manual', 'pages', 'increase', 10, ?, ?, ?, 'secondary', ?, ?, ?, ?)",
    [$userId, $name, $on(-40), $on(-10), $status, $pct, $pct, $on(-10) . ' 23:59:59', $created]
);
$mk('Oud en half', $on(-40) . ' 09:00:00', 50.0, 'ended');
$mk('Behaald', $on(-50) . ' 09:00:00', 100.0, 'completed');
$mk('Nieuw en half', $on(-30) . ' 09:00:00', 50.0, 'ended');
$board = goals_prepare(hydrate_goals(goal_catalogue(), $userId, $today), $today);
check('the board: Behaald, Nieuw en half, Oud en half',
    array_column($board['completed'], 'name') === ['Behaald', 'Nieuw en half', 'Oud en half'], implode(' | ', array_column($board['completed'], 'name')));
check('… and nothing under Actief', $board['active'] === []);
check('the final percentages are what they finished with', array_column($board['completed'], 'final_percent') == [100, 50, 50],
    json_encode(array_column($board['completed'], 'final_percent')));

section('The primary goal');

$clear();
$first  = $make(['name' => 'Eerste', 'measurement' => 'custom', 'category' => 'sleep', 'type' => 'accumulate', 'unit' => 'times',
                 'target_value' => '5', 'start_date' => $on(0), 'duration' => 'week']);
$second = $make(['name' => 'Tweede', 'measurement' => 'custom', 'category' => 'sleep', 'type' => 'accumulate', 'unit' => 'times',
                 'target_value' => '5', 'start_date' => $on(0), 'duration' => 'week']);
check('the first goal is primary, the second secondary', $row($first)['priority'] === 'primary' && $row($second)['priority'] === 'secondary');
check('exactly one primary', (int) db_value("SELECT COUNT(*) FROM goals WHERE user_id = ? AND priority = 'primary'", [$userId]) === 1);
goal_record_entry($userId, $row($first), 5, null, $today, $today);
goal_refresh($userId, $first, $today);
check('the primary finishes: the other one takes its place',
    $row($first)['status'] === 'completed' && $row($second)['priority'] === 'primary');

/* ======================================================================
   WHAT CANNOT BE MADE
   ====================================================================== */

section('What the wizard and the assistant may not make');

$clear();
$base = ['name' => 'Een doel', 'measurement' => 'custom', 'category' => 'training', 'type' => 'accumulate', 'unit' => 'times',
         'target_value' => '10', 'start_date' => $on(0), 'duration' => 'month'];
$refused = static function (array $changes, string $field, string $label) use ($userId, $today, $base): void {
    $in = array_merge($base, $changes);
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($in[$key]);
        }
    }
    $result = goal_create_from_input($userId, $in, true, $today);
    check($label, !$result['ok'] && $result['field'] === $field, json_encode([$result['ok'], $result['field'], $result['error']]));
};

check('the base case is fine', goal_create_from_input($userId, $base, true, $today)['ok']);
$refused(['name' => 'A'], 'name', 'a one-letter name');
$refused(['name' => str_repeat('x', 121)], 'name', 'a name over 120 characters');
$refused(['measurement' => 'vo2max'], 'measurement', 'a measurement that does not exist');
$refused(['measurement' => null], 'measurement', 'no measurement');
$refused(['category' => 'hobby'], 'category', 'an Eigen gewoonte without a real category');
$refused(['measurement' => 'weight', 'type' => 'accumulate', 'unit' => 'kg'], 'type', 'adding up a weight');
$refused(['measurement' => 'water', 'type' => 'streak', 'variant' => 'period', 'frequency' => '3', 'period' => 'week'], 'type', 'water per week');
$refused(['type' => 'streak', 'variant' => null], 'type', 'a streak without its variant');
$refused(['type' => 'sprint'], 'type', 'a type that does not exist');
$refused(['measurement' => 'steps', 'unit' => 'km'], 'unit', 'a unit the measurement does not have');
$refused(['unit' => 'custom', 'custom_unit' => str_repeat('w', 21)], 'custom_unit', 'an own unit over 20 characters');
$refused(['target_value' => '0'], 'target_value', 'a target of 0');
$refused(['target_value' => '-5'], 'target_value', 'a negative target');
$refused(['target_value' => 'veel'], 'target_value', 'a target that is not a number');
$refused(['measurement' => 'sleep_efficiency', 'type' => 'streak', 'variant' => 'daily', 'unit' => 'pct', 'target_value' => '101'], 'target_value', 'a percentage over 100');
$refused(['duration' => null], 'end_date', 'no end date');
$refused(['duration' => null, 'end_date' => $on(0)], 'end_date', 'an end date on the start date');
$refused(['duration' => null, 'start_date' => $on(-10), 'end_date' => $on(-1)], 'end_date', 'an end date that has already passed');
$refused(['start_date' => $on(2)], 'start_date', 'a start date after tomorrow');
$refused(['start_date' => $on(-366)], 'start_date', 'a start date more than a year back');
$refused(['duration' => null, 'end_date' => ymd($day(0)->modify('+3 year +1 day'))], 'end_date', 'more than three years');
$refused(['duration' => null, 'end_date' => '2025-02-30'], 'end_date', 'a date that does not exist');
$refused(['type' => 'milestone', 'baseline_value' => null], 'baseline_value', 'a Mijlpaal kept by hand without its start value');
$refused(['type' => 'milestone', 'baseline_value' => '12'], 'target_value', 'a Mijlpaal up whose target is below its start');
$refused(['type' => 'milestone', 'baseline_value' => '10'], 'target_value', 'a Mijlpaal whose target is its start');
$refused(['type' => 'milestone', 'direction' => 'decrease', 'baseline_value' => '8'], 'target_value', 'a Mijlpaal down whose target is above its start');
$refused(['type' => 'milestone', 'direction' => 'sideways', 'baseline_value' => '5'], 'direction', 'a direction that does not exist');
$refused(['type' => 'streak', 'variant' => 'period', 'frequency' => '8', 'period' => 'week'], 'frequency', 'by hand, more than 7 times a week');
$refused(['type' => 'streak', 'variant' => 'period', 'frequency' => '2,5', 'period' => 'week'], 'frequency', 'half a time');
$refused(['type' => 'streak', 'variant' => 'period', 'frequency' => '2', 'period' => 'fortnight'], 'period', 'a period that does not exist');
$refused(['type' => 'streak', 'variant' => 'period', 'frequency' => '1', 'period' => 'week', 'duration' => null, 'end_date' => $on(2)],
    'end_date', 'a period too short to ask anything (3 days at 1 per week)');

$ok = goal_create_from_input($userId, array_merge($base, ['type' => 'streak', 'variant' => 'period', 'frequency' => '3', 'period' => 'week',
                                                          'duration' => null, 'end_date' => $on(2)]), true, $today);
check('… at 3 per week the same 3 days ask once: allowed', $ok['ok'], (string) $ok['error']);

$ok = goal_create_from_input($userId, array_merge($base, ['measurement' => 'weight', 'type' => 'milestone', 'unit' => 'lb',
                                                          'target_value' => '165,3', 'direction' => 'decrease']), true, $today);
check('a weight in lb: the target is stored in kg (165,3 lb → 74,98 kg)', $ok['ok'] && near($ok['goal']['target_value'], 74.98, 0.01),
    json_encode([$ok['error'], $ok['goal']['target_value'] ?? null]));
check('… against the measured baseline, stored in kg', $ok['ok'] && near($ok['goal']['baseline_value'], 83.0, 1e-6) && $ok['goal']['unit_code'] === 'lb');

$ok = goal_create_from_input($userId, array_merge($base, ['measurement' => 'workouts', 'type' => 'streak', 'variant' => 'period',
                                                          'frequency' => '12', 'period' => 'week', 'unit' => 'kg']), true, $today);
check('trainingen may be more than 7 a week; the unit is always trainingen', $ok['ok'] && $ok['goal']['unit_code'] === 'sessions'
    && $ok['goal']['direction'] === 'increase' && $ok['goal']['target_value'] === null);

$ok = goal_create_from_input($userId, array_merge($base, ['measurement' => 'sleep_duration', 'type' => 'streak', 'variant' => 'daily',
                                                          'unit' => 'h', 'target_value' => '7,5']), true, $today);
check('7,5 uur a night is stored as 450 min, sleep defaults to "at least"',
    $ok['ok'] && near($ok['goal']['target_value'], 450.0) && $ok['goal']['direction'] === 'increase' && $ok['goal']['tracking'] === 'manual');

$ok = goal_create_from_input($userId, array_merge($base, ['measurement' => 'resting_hr', 'type' => 'milestone', 'unit' => 'bpm',
                                                          'target_value' => '52', 'direction' => 'increase']), true, $today);
check('rusthartslag only goes down, whatever is asked', $ok['ok'] && $ok['goal']['direction'] === 'decrease',
    json_encode([$ok['error'], $ok['goal']['direction'] ?? null]));

$ok = goal_create_from_input($userId, array_merge($base, ['measurement' => 'steps', 'unit' => 'steps', 'target_value' => '200.000']), true, $today);
check('"200.000" stappen, as the examples write it, is two hundred thousand', $ok['ok'] && near($ok['goal']['target_value'], 200000.0), json_encode([$ok['error'], $ok['goal']['target_value'] ?? null]));

$ok = goal_create_from_input($userId, array_merge($base, ['start_date' => $on(-30), 'duration' => 'quarter']), true, $today);
check('a start a month back is fine', $ok['ok'] && $ok['goal']['start_date'] === $on(-30));

/* A full board. */
for ($i = 1; $i <= GOAL_MAX_ACTIVE; $i++) {
    $make(array_merge($base, ['name' => 'Doel ' . $i]));
}
$full = goal_create_from_input($userId, array_merge($base, ['name' => 'Eén te veel']), false, $today);
check('a sixth active goal: refused, the board is full', !$full['ok'] && $full['status'] === 409);

section('The one-time key');

$clear();
$token = 'wizard-' . bin2hex(random_bytes(6));
$a = goal_create_from_input($userId, array_merge($base, ['create_token' => $token]), false, $today);
$b = goal_create_from_input($userId, array_merge($base, ['create_token' => $token, 'name' => 'Iets anders']), false, $today);
check('sent twice: one goal, the same id', $a['ok'] && $b['ok'] && $a['goal_id'] === $b['goal_id']
    && (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$userId]) === 1);
check('a dry run makes nothing', goal_create_from_input($userId, $base, true, $today)['ok']
    && (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$userId]) === 1);
check('a key that is not one is refused', !goal_create_from_input($userId, array_merge($base, ['create_token' => 'x y']), false, $today)['ok']);

/* ======================================================================
   LOGGING ON THE GOAL'S PAGE
   ====================================================================== */

section('What may be logged on a goal\'s page');

$clear();
$manual = $make(array_merge($base, ['name' => 'Push-ups', 'start_date' => $on(-3), 'duration' => 'month']));
$g = $row($manual);
check('an amount within the period', goal_record_entry($userId, $g, 5, null, $day(-3), $today)['ok']);
check('not before the start', !goal_record_entry($userId, $g, 5, null, $day(-4), $today)['ok']);
check('not after today', !goal_record_entry($userId, $g, 5, null, $day(1), $today)['ok']);
check('not nothing', !goal_record_entry($userId, $g, null, null, $today, $today)['ok']);
check('not zero for Optellen', !goal_record_entry($userId, $g, 0, null, $today, $today)['ok']);
check('not somebody else\'s goal', !goal_record_entry($userId + 1, $g, 5, null, $today, $today)['ok']);

$streak = $make(array_merge($base, ['name' => 'Elke dag', 'type' => 'streak', 'variant' => 'daily', 'target_value' => '1']));
check('a streak needs yes or no', !goal_record_entry($userId, $row($streak), 1, null, $today, $today)['ok']);

$auto = $make(array_merge($base, ['name' => 'Trainingen', 'measurement' => 'workouts', 'unit' => 'sessions']));
check('a goal read from data takes no entries', $row($auto)['tracking'] === 'auto'
    && !goal_record_entry($userId, $row($auto), 1, null, $today, $today)['ok']);

/* ======================================================================
   THE TABLES
   ====================================================================== */

section('The goal tables: rules in the database, and only pointing at each other and users');

$refusedByDb = static function (string $sql, array $params) : bool {
    try {
        db_run($sql, $params);
        return false;
    } catch (PDOException) {
        return true;
    }
};

check('a finished goal without its final percentage is refused',
    $refusedByDb('UPDATE goals SET status = \'completed\', final_pct = NULL WHERE id = ?', [$manual]));
check('a running goal with a final percentage is refused',
    $refusedByDb('UPDATE goals SET final_pct = 50, completed_at = NOW() WHERE id = ?', [$manual]));
check('a percentage over 100 is refused',
    $refusedByDb('UPDATE goals SET progress_pct = 101 WHERE id = ?', [$manual]));
check('an end date before the start is refused',
    $refusedByDb('UPDATE goals SET end_date = start_date - INTERVAL 1 DAY WHERE id = ?', [$manual]));
check('a streak without its variant is refused',
    $refusedByDb('UPDATE goals SET goal_type = \'streak\', streak_variant = NULL WHERE id = ?', [$manual]));
check('a unit code and an own word at once is refused',
    $refusedByDb('UPDATE goals SET custom_unit = \'keer\' WHERE id = ?', [$manual]));
check('a measurement that does not exist is refused',
    $refusedByDb('UPDATE goals SET measurement_code = \'vo2max\' WHERE id = ?', [$manual]));

$references = db_all(
    "SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
        AND (TABLE_NAME LIKE 'goal%' OR REFERENCED_TABLE_NAME LIKE 'goal%')"
);
$outside = array_filter($references, static fn (array $ref): bool =>
    !(str_starts_with($ref['t'], 'goal') && (str_starts_with($ref['r'], 'goal') || $ref['r'] === 'users')));
check('every key from or to a goal table stays among them (and users)', $outside === [] && $references !== [],
    json_encode(array_values($outside)));

$clear();
check('removing the goals removes their entries, days, periods and snapshots',
    (int) db_value('SELECT COUNT(*) FROM goal_entries e LEFT JOIN goals g ON g.id = e.goal_id WHERE g.id IS NULL') === 0
    && (int) db_value('SELECT COUNT(*) FROM goal_progress p LEFT JOIN goals g ON g.id = p.goal_id WHERE g.id IS NULL') === 0
    && (int) db_value('SELECT COUNT(*) FROM goal_day_results p LEFT JOIN goals g ON g.id = p.goal_id WHERE g.id IS NULL') === 0
    && (int) db_value('SELECT COUNT(*) FROM goal_period_results p LEFT JOIN goals g ON g.id = p.goal_id WHERE g.id IS NULL') === 0);

} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

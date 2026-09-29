<?php
/**
 * The database half of the Health Connect test.
 *
 *     php tools/hc-verify.php --fixture
 *     php tools/hc-verify.php --user=hctest_1758… --phase=imported
 *     php tools/hc-verify.php --user=hctest_1758… --cleanup
 *
 * tools/health-connect-test.sh drives the flow over HTTP and checks what the
 * endpoints answer. That proves the contract. It does not prove the records
 * landed in the right *tables*, which is a different question and the one this
 * answers.
 *
 * ---------------------------------------------------------------------------
 * WHY THE TEST BATCH LIVES HERE AND NOT IN THE SHELL SCRIPT
 * ---------------------------------------------------------------------------
 * Because the expected results live here. A fixture in one file and the
 * assertions about it in another drift the first time somebody edits one of
 * them, and a test that quietly stops testing what it claims is worse than no
 * test. `--fixture` prints the batch; the shell script posts whatever it says.
 *
 * ---------------------------------------------------------------------------
 * IT READS ONE ACCOUNT
 * ---------------------------------------------------------------------------
 * Every statement is filtered to the test account named on the command line,
 * and the SQL is a fixed list in this file — nothing from the command line
 * reaches a query except as a bound parameter. This is a test tool, not a
 * console, and it is in the deploy because the deploy is the whole checkout.
 */

declare(strict_types=1);

/* Under the document root on a Hestia deploy. It must never answer a browser:
   it reads health rows and it can delete an account. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

/** Test accounts are named so they can be told apart from real ones. */
const HC_TEST_PREFIX = 'hctest';

$options = getopt('', ['fixture', 'user:', 'phase:', 'cleanup', 'help']);

if ($options === false || isset($options['help']) || $options === []) {
    fwrite(STDERR, <<<TEXT
    Usage:
      php tools/hc-verify.php --fixture
      php tools/hc-verify.php --user=<username> --phase=imported|replayed|disconnected
      php tools/hc-verify.php --user=<username> --cleanup

    TEXT);
    exit(1);
}

/* ------------------------------------------------------------- fixture */

/**
 * One realistic Health Connect batch, in Health Connect's own field names and
 * units, exactly as the phone app will send it.
 *
 * Dated relative to today so a night is always a night — a sleep session with
 * a fixed date eventually becomes a session from last year, and the parts of
 * the app that ask "what happened today" stop being exercised.
 *
 * The last record is deliberately a type Ownify does not map. It must come back
 * in `unmapped`, by name, rather than vanishing.
 */
function hc_fixture(): array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $yday  = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

    return [
        'records' => [
            [
                'recordType' => 'SleepSession',
                'metadata'   => ['id' => 'ownify-test-sleep-1', 'dataOrigin' => 'ownify.test'],
                'startTime'  => $yday . 'T23:10:00Z',
                'endTime'    => $today . 'T06:42:00Z',
                'stages'     => [
                    ['startTime' => $yday . 'T23:10:00Z',  'endTime' => $today . 'T00:50:00Z', 'stage' => 4],
                    ['startTime' => $today . 'T00:50:00Z', 'endTime' => $today . 'T02:30:00Z', 'stage' => 5],
                    ['startTime' => $today . 'T02:30:00Z', 'endTime' => $today . 'T04:10:00Z', 'stage' => 6],
                    ['startTime' => $today . 'T04:10:00Z', 'endTime' => $today . 'T04:25:00Z', 'stage' => 1],
                    ['startTime' => $today . 'T04:25:00Z', 'endTime' => $today . 'T06:42:00Z', 'stage' => 4],
                ],
            ],
            [
                'recordType' => 'Steps',
                'metadata'   => ['id' => 'ownify-test-steps-1'],
                'startTime'  => $today . 'T00:00:00Z',
                'endTime'    => $today . 'T23:59:00Z',
                'count'      => 9420,
            ],
            [
                'recordType'       => 'ExerciseSession',
                'metadata'         => ['id' => 'ownify-test-exercise-1'],
                'startTime'        => $today . 'T18:00:00Z',
                'endTime'          => $today . 'T18:45:00Z',
                'exerciseTypeName' => 'running',
                'title'            => 'Testrondje',
            ],
            [
                'recordType' => 'Weight',
                'metadata'   => ['id' => 'ownify-test-weight-1'],
                'time'       => $today . 'T07:10:00Z',
                'weight'     => ['kilograms' => 72.4],
            ],
            [
                'recordType' => 'Height',
                'metadata'   => ['id' => 'ownify-test-height-1'],
                'time'       => $today . 'T07:10:00Z',
                'height'     => ['meters' => 1.83],
            ],
            [
                'recordType' => 'Hydration',
                'metadata'   => ['id' => 'ownify-test-water-1'],
                'startTime'  => $today . 'T12:00:00Z',
                'endTime'    => $today . 'T12:00:00Z',
                'volume'     => ['liters' => 1.8],
            ],
            [
                'recordType' => 'Nutrition',
                'metadata'   => ['id' => 'ownify-test-meal-1'],
                'startTime'  => $today . 'T12:30:00Z',
                'mealType'   => 2,
                'name'       => 'Testlunch',
                'energy'     => ['kilocalories' => 640],
                'protein'    => ['grams' => 38],
                'sodium'     => ['grams' => 1.2],
            ],
            [
                /* Not mapped, on purpose. */
                'recordType' => 'MenstruationFlow',
                'metadata'   => ['id' => 'ownify-test-unmapped-1'],
                'time'       => $today . 'T08:00:00Z',
            ],
        ],
    ];
}

if (isset($options['fixture'])) {
    echo json_encode(hc_fixture(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

/* ------------------------------------------------------ the test account */

$username = (string) ($options['user'] ?? '');

if ($username === '') {
    fwrite(STDERR, "Name the test account with --user=<username>.\n");
    exit(1);
}

/* The guard on --cleanup, and a guard on reading too: this tool is only ever
   pointed at an account the test made. */
if (!str_starts_with($username, HC_TEST_PREFIX)) {
    fwrite(STDERR, sprintf(
        "Refusing: '%s' is not a test account. Names must start with '%s'.\n",
        $username,
        HC_TEST_PREFIX
    ));
    exit(1);
}

if (!db_available()) {
    fwrite(STDERR, 'No database connection: ' . (db_note_failure() ?? 'unknown') . "\n");
    exit(1);
}

$userId = db_value('SELECT id FROM users WHERE username = ?', [$username]);

if ($userId === null) {
    fwrite(STDERR, "No such account: {$username}\n");
    exit(1);
}

$userId = (int) $userId;

/* ------------------------------------------------------------- cleanup */

if (isset($options['cleanup'])) {
    /* Every user-owned table cascades from `users`, so this is one statement
       and it leaves nothing behind. */
    db_run('DELETE FROM users WHERE id = ? AND username = ?', [$userId, $username]);

    $gone = db_value('SELECT COUNT(*) FROM users WHERE id = ?', [$userId]);

    echo $gone === 0 || $gone === '0'
        ? "Removed the test account {$username} and everything belonging to it.\n"
        : "Could not remove {$username}.\n";

    exit($gone === 0 || $gone === '0' ? 0 : 1);
}

/* -------------------------------------------------------------- checks */

$phase = (string) ($options['phase'] ?? '');

if (!in_array($phase, ['imported', 'replayed', 'disconnected'], true)) {
    fwrite(STDERR, "--phase must be imported, replayed or disconnected.\n");
    exit(1);
}

/**
 * What the fixture must have become, table by table.
 *
 * The sleep arithmetic is worth writing down: the stages are 100 light,
 * 100 deep, 100 REM, 15 awake and 137 light again, so light is 237 and the
 * 452 minutes in bed hold 437 asleep — 96.7%, which rounds to 97.
 *
 * @return list<array{0: string, 1: string, 2: string}>  label, sql, expected
 */
function hc_mapping_checks(): array
{
    return [
        ['one sleep session',          'SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ?', '1'],
        ['  stages became minutes',    "SELECT CONCAT(deep_minutes,'/',rem_minutes,'/',light_minutes) FROM sleep_sessions WHERE user_id = ?", '100/100/237'],
        ['  efficiency was derived',   'SELECT ROUND(efficiency_pct) FROM sleep_sessions WHERE user_id = ?', '97'],
        ['one workout',                'SELECT COUNT(*) FROM workouts WHERE user_id = ?', '1'],
        ['  named from the record',    'SELECT activity_type FROM workouts WHERE user_id = ?', 'running'],
        ['steps as a metric',          "SELECT ROUND(m.value) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'steps'", '9420'],
        /* Counted, not just read: a value check reads the first row and would
           be just as happy with three of them, which is precisely the bug a
           re-sync would cause. Steps, water, sodium, energy, protein. */
        ['five metric rows exactly',   'SELECT COUNT(*) FROM health_metrics WHERE user_id = ?', '5'],
        ['water in litres',            "SELECT ROUND(m.value, 2) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'water'", '1.80'],
        ['sodium g -> mg',             "SELECT ROUND(m.value) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'sodium'", '1200'],
        ['one meal, as lunch',         'SELECT COUNT(*) FROM nutrition_entries WHERE user_id = ?', '1'],
        ['  meal type mapped',         'SELECT meal_type FROM nutrition_entries WHERE user_id = ?', 'lunch'],
        ['weight in kg',               "SELECT ROUND(value, 1) FROM user_measurements WHERE user_id = ? AND measurement_type = 'weight'", '72.4'],
        ['height m -> cm',             "SELECT ROUND(value, 1) FROM user_measurements WHERE user_id = ? AND measurement_type = 'height'", '183.0'],
        ['two measurements, no more',  'SELECT COUNT(*) FROM user_measurements WHERE user_id = ?', '2'],
        ['credited to Health Connect', 'SELECT s.code FROM sleep_sessions h JOIN data_sources s ON s.id = h.source_id WHERE h.user_id = ?', 'google_health_connect'],
    ];
}

/** After disconnecting: the phone is out, the history is not. */
function hc_disconnect_checks(): array
{
    return [
        ['every phone revoked',      'SELECT COUNT(*) FROM user_devices WHERE user_id = ? AND revoked_at IS NULL', '0'],
        /* Two phones are paired over the course of the test, one of them
           revoked on its own beforehand. Both rows must survive: a revoked
           device is kept so the account can still show that the phone was
           once paired, and so a token turning up later is a known revoked
           one rather than an unknown. */
        ['  both rows are kept, not deleted', 'SELECT COUNT(*) FROM user_devices WHERE user_id = ?', '2'],
        ['the source is disconnected', "SELECT status FROM user_integrations WHERE user_id = ? AND provider = 'google_health_connect'", 'disconnected'],
    ];
}

$heading = match ($phase) {
    'imported'     => 'the batch mapped into the right tables',
    'replayed'     => 'the same batch again changed nothing',
    'disconnected' => 'disconnecting kept the data and dropped the phone',
};

$checks = $phase === 'disconnected'
    ? array_merge(hc_mapping_checks(), hc_disconnect_checks())
    : hc_mapping_checks();

printf("  -- %s --\n", $heading);

$failed = 0;

foreach ($checks as [$label, $sql, $expected]) {
    $actual = db_value($sql, [$userId]);
    $actual = $actual === null ? '(none)' : (string) $actual;

    if ($actual === $expected) {
        printf("  PASS  %s\n", $label);
        continue;
    }

    $failed++;
    printf("  FAIL  %s\n          got [%s] want [%s]\n", $label, $actual, $expected);
}

printf("  %d of %d passed\n", count($checks) - $failed, count($checks));

exit($failed === 0 ? 0 : 1);

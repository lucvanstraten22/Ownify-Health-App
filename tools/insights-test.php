<?php
/**
 * Insights from a person's own data and approved research (docs/INSIGHTS.md),
 * tested on a THROWAWAY database this script makes and drops
 * (ownify_ins_<random>) — never an existing one — with synthetic people and
 * synthetic measurements only, and a stand-in for Gemini
 * (tools/ai-fake-gemini.php) that must never be asked: since 30.1 no AI
 * service writes or rewords an insight. No key, no Google, no real person.
 *
 *     DB_USER=root php tools/insights-test.php
 *
 * The rules, on synthetic facts:
 *   complete data · missing data says nothing · the source's own age bands ·
 *   no age, no age-bound advice · labels lowered for approximate data and
 *   never below Beperkt · Beperkt sources never become insights · ranking,
 *   the repeat penalty and the suggestion's cooldown
 *
 * The lifecycle, on the database:
 *   one evaluation a day, stored and reused · a repeated sync changes nothing
 *   and calls nobody · a real change reassesses, withdraws a suggestion that
 *   no longer holds and never makes a second · requests at the same moment ·
 *   a request that died · midnight · duplicate recordings · logged food never
 *   replaces the cijfer · the Health Score and points untouched · every
 *   insight traceable to an approved source · the website and the app read
 *   the same stored result
 *
 * No AI service (30.1):
 *   the stand-in is never asked · no insight code reaches an AI client · an
 *   old stored wording is never shown · no Inzichten switch on Privacy ·
 *   every template, in both languages, without causes, orders, diagnoses or
 *   comparisons with other people
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$name = 'ownify_ins_' . bin2hex(random_bytes(3));
$user = (string) (getenv('DB_USER') ?: 'root');
$pass = (string) (getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '');
$host = (string) (getenv('DB_HOST') ?: '127.0.0.1');

$pass_ = 0;
$fail_ = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass_, $fail_;
    $ok ? $pass_++ : $fail_++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "\n          " . $detail);
}
function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

/* ------------------------------------------------- the throwaway database */

$server = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
exec(sprintf('mysql -h%s -u%s %s %s < %s 2>&1', escapeshellarg($host), escapeshellarg($user),
    $pass === '' ? '' : '-p' . escapeshellarg($pass), escapeshellarg($name), escapeshellarg($root . '/database/schema.sql')), $out, $code);
if ($code !== 0) {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    fwrite(STDERR, "schema.sql did not import:\n" . implode("\n", $out) . "\n");
    exit(2);
}

/* The stand-in for Gemini. */
$fakeDir = sys_get_temp_dir() . '/ownify-ins-' . bin2hex(random_bytes(3));
mkdir($fakeDir);
$socket   = stream_socket_server('tcp://127.0.0.1:0');
$fakePort = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);
$fake = proc_open(['php', '-S', '127.0.0.1:' . $fakePort, $root . '/tools/ai-fake-gemini.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    ['FAKE_GEMINI_DIR' => $fakeDir] + getenv());
for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $fakePort) === false; $i++) {
    usleep(50_000);
}

register_shutdown_function(static function () use ($server, $name, $fake, $fakeDir): void {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    proc_terminate($fake);
    array_map('unlink', glob($fakeDir . '/*') ?: []);
    @rmdir($fakeDir);
});

foreach ([
    'DB_HOST' => $host, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASSWORD' => $pass,
    'GEMINI_API_KEY' => 'test-key-not-real', 'GEMINI_API_BASE' => 'http://127.0.0.1:' . $fakePort,
    'GEMINI_MODEL' => 'gemini-3.8-flash', 'GEMINI_TIMEOUT' => '2', 'AI_GLOBAL_DAILY_LIMIT' => '1000', 'AI_MINUTE_LIMIT' => '8',
] as $k => $v) {
    putenv("$k=$v");
}

date_default_timezone_set('Europe/Amsterdam');
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/insights.php';
require_once $root . '/lib/app-data.php';

echo "\nInsights, on $name\n" . str_repeat('-', 72) . "\n";

function requests(): array
{
    global $fakeDir;
    $file = $fakeDir . '/requests.jsonl';

    return is_file($file) ? array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($file)))) : [];
}

try {

/* ======================================================================
   THE RULES, ON SYNTHETIC FACTS
   ====================================================================== */
section('Rules');

$claims = research_claims();
check('the registry is there: 25 active claims', count($claims) === 25);

$cfg  = insights_config();
$base = [
    'date'      => '2026-10-27',
    'profile'   => ['age' => 35, 'activity_level' => 'moderate', 'height_cm' => 180.0, 'weight_kg' => 80.0, 'weight_days' => 3, 'energy_target' => 2600.0],
    'sleep'     => ['nights' => 7, 'avg_minutes' => 390.0, 'bedtime_sd' => 30.0, 'wake_sd' => 20.0, 'last_night_days' => 0,
                    'prev_nights' => 18, 'prev_bedtime_sd' => 25.0, 'prev_wake_sd' => 20.0],
    'activity'  => ['fresh' => true, 'step_days' => 7, 'steps_avg' => 6200.0, 'workouts_7' => 3, 'minutes_7' => 110.0,
                    'strength_days_7' => 1, 'workouts_28' => 9],
    'nutrition' => ['target_known' => true, 'logged_days' => 6, 'complete_days' => 5, 'fat_share' => 33.0, 'sat_share' => 12.5,
                    'sat_days' => 5, 'fibre_per_1000' => 10.2, 'fibre_days' => 5, 'protein_g' => 60.0, 'sodium_mg' => 2800.0, 'sodium_days' => 5],
];
$with = static fn (array $over) => array_replace_recursive($base, $over);
$by   = static function (array $facts) use ($claims, $cfg): array {
    $out = [];
    foreach (insights_candidates($facts, $claims, $cfg) as $c) {
        $out[$c['rule']] = $c;
    }
    return $out;
};

$c = $by($base);
check('1 · complete data: sleep below the adult range, from SLP-02, Betrouwbaar',
    ($c['sleep.duration']['outcome'] ?? null) === 'below' && $c['sleep.duration']['claim_key'] === 'sleep.duration.adults'
    && $c['sleep.duration']['strength'] === 'betrouwbaar' && $c['sleep.duration']['facts']['min_hours'] === 7);
check('… recorded workouts against 150 minutes, one step lower (proxy): Betrouwbaar',
    ($c['activity.minutes']['outcome'] ?? null) === 'below' && $c['activity.minutes']['strength'] === 'betrouwbaar'
    && $c['activity.minutes']['facts']['target'] === 150 && $c['activity.minutes']['applicability'] === 'proxy');
check('… strength training on 1 of 2 days, steps under 7.000 (Ding)', ($c['activity.strength']['outcome'] ?? null) === 'below'
    && ($c['activity.steps']['outcome'] ?? null) === 'below' && $c['activity.steps']['claim_key'] === 'activity.steps.ding');
check('… fat within 20–40 %, saturated fat above 10 %, fibre below 12,6 g per 1.000 kcal',
    ($c['nutrition.fat']['outcome'] ?? null) === 'within' && ($c['nutrition.saturated_fat']['outcome'] ?? null) === 'above'
    && ($c['nutrition.fibre']['outcome'] ?? null) === 'below');
check('… protein 0,75 g/kg under the 0,83 norm; salt 7 g above 6 g', ($c['nutrition.protein']['outcome'] ?? null) === 'below'
    && abs($c['nutrition.protein']['facts']['g_per_kg'] - 0.75) < 0.001 && ($c['nutrition.salt']['outcome'] ?? null) === 'above'
    && abs($c['nutrition.salt']['facts']['salt_g'] - 7.0) < 0.001);
check('12 · weak fits are never shown as reliable: salt and BMI Beperkt onderbouwd',
    $c['nutrition.salt']['strength'] === 'beperkt' && ($c['body.bmi']['strength'] ?? null) === 'beperkt');
check('… a steady sleep rhythm on its own is not an insight', !isset($c['sleep.regularity']));

section('Missing data says nothing (2)');
$c = $by($with(['sleep' => ['nights' => 2]]));
check('2 nights: no sleep insight at all', !isset($c['sleep.duration']) && !isset($c['sleep.regularity']));
$c = $by($with(['sleep' => ['last_night_days' => 4]]));
check('the last night four days ago: stale, nothing', !isset($c['sleep.duration']));
$c = $by($with(['activity' => ['fresh' => false]]));
check('no recent steps (sync not alive): nothing about activity',
    !isset($c['activity.minutes']) && !isset($c['activity.strength']) && !isset($c['activity.steps']));
$c = $by($with(['activity' => ['workouts_28' => 1]]));
check('somebody who hardly records workouts: no workout insight, steps still', !isset($c['activity.minutes'])
    && !isset($c['activity.strength']) && isset($c['activity.steps']));
$c = $by($with(['nutrition' => ['complete_days' => 2, 'sat_days' => 2, 'fibre_days' => 2, 'sodium_days' => 2]]));
check('two complete days of food: nothing about food', array_filter(array_keys($c), static fn ($k) => str_starts_with($k, 'nutrition.')) === []);
$c = $by($with(['nutrition' => ['target_known' => false]]));
check('no energy target (no profile): no day can look complete, nothing about food',
    array_filter(array_keys($c), static fn ($k) => str_starts_with($k, 'nutrition.')) === []);
$c = $by($with(['profile' => ['weight_days' => 400]]));
check('a weight older than half a year: no protein per kg, no BMI', !isset($c['nutrition.protein']) && !isset($c['body.bmi']));

section('Age, in the source\'s own bands (3, 4)');
$c = $by($with(['profile' => ['age' => 15], 'sleep' => ['avg_minutes' => 450.0]]));
check('15 years: the youth claim (SLP-04), 8–10 hours, 7:30 is below',
    ($c['sleep.duration']['claim_key'] ?? null) === 'sleep.duration.youth' && $c['sleep.duration']['outcome'] === 'below'
    && $c['sleep.duration']['facts']['min_hours'] === 8 && $c['sleep.duration']['facts']['max_hours'] === 10);
check('… the Beweegrichtlijn for 4–17: minutes a day, three strength days',
    ($c['activity.minutes']['facts']['per'] ?? null) === 'day' && $c['activity.minutes']['facts']['target'] === 60
    && ($c['activity.strength']['facts']['target'] ?? null) === 3);
check('… no adult claims: no steps, protein, salt or BMI', !isset($c['activity.steps']) && !isset($c['nutrition.protein'])
    && !isset($c['nutrition.salt']) && !isset($c['body.bmi']));
$c = $by($with(['profile' => ['age' => 62]]));
check('62 years: the CDC band 61–64, 7–9 hours', ($c['sleep.duration']['claim_key'] ?? null) === 'sleep.duration.cdc'
    && $c['sleep.duration']['facts']['max_hours'] === 9 && $c['sleep.duration']['facts']['age_band'] === [61, 64]);
$c = $by($with(['profile' => ['age' => 70], 'sleep' => ['avg_minutes' => 510.0]]));
check('70 years: the NSF band 65+, 7–8 hours, 8:30 is above', ($c['sleep.duration']['claim_key'] ?? null) === 'sleep.duration.nsf'
    && $c['sleep.duration']['outcome'] === 'above');
check('… 6.200 steps at 70: on the plateau for 60+ (Paluch, 6.000–8.000)', ($c['activity.steps']['outcome'] ?? null) === 'plateau'
    && $c['activity.steps']['claim_key'] === 'activity.steps.paluch' && $c['activity.steps']['facts']['plateau'] === [6000, 8000]);
$c = $by($with(['activity' => ['steps_avg' => 7500.0]]));
check('7.500 steps at 35: past 7.000, towards the plateau for under 60 (8.000–10.000)',
    ($c['activity.steps']['outcome'] ?? null) === 'toward' && $c['activity.steps']['facts']['plateau'] === [8000, 10000]);
$noAge = $by($with(['profile' => ['age' => null], 'sleep' => ['bedtime_sd' => 70.0, 'wake_sd' => 50.0]]));
check('4 · no known age: no sleep duration, nothing about activity, protein, fat, fibre, salt or BMI',
    !isset($noAge['sleep.duration']) && !isset($noAge['activity.minutes']) && !isset($noAge['activity.strength'])
    && !isset($noAge['activity.steps']) && !isset($noAge['nutrition.protein']) && !isset($noAge['nutrition.fat'])
    && !isset($noAge['nutrition.fibre']) && !isset($noAge['nutrition.salt']) && !isset($noAge['body.bmi']));
check('… only what holds at any age: the sleep rhythm and saturated fat',
    isset($noAge['sleep.regularity']) && isset($noAge['nutrition.saturated_fat']));

section('Evidence labels (12)');
check('a Beperkt claim on approximate data is no insight at all', research_strength_for('beperkt', 'proxy') === null);
check('zeer betrouwbaar on a weak fit shows as Beperkt onderbouwd', research_strength_for('zeer_betrouwbaar', 'weak') === 'beperkt');
$all = [];
foreach ([null, 10, 15, 35, 62, 70, 90] as $age) {
    foreach (insights_candidates($with(['profile' => ['age' => $age]]), $claims, $cfg) as $cand) {
        $all[] = $cand['claim_key'];
    }
}
check('the sports-nutrition position stand (NUT-05, Beperkt) and the sleep-tracker study never become insights',
    !in_array('nutrition.protein.exercise', $all, true) && !in_array('sleep.tracking', $all, true));
check('no insight ever claims more than its source: every label at or below its claim\'s',
    array_filter(insights_candidates($base, $claims, $cfg), static fn ($cand) =>
        insights_evidence_rank($cand['strength']) > insights_evidence_rank($claims[$cand['claim_key']]['strength'])) === []);

section('Sleep rhythm against the person\'s own weeks');
$c = $by($with(['sleep' => ['bedtime_sd' => 80.0, 'wake_sd' => 60.0, 'prev_bedtime_sd' => 40.0, 'prev_wake_sd' => 40.0]]));
check('70 minutes now against 40 before: less steady', ($c['sleep.regularity']['outcome'] ?? null) === 'less_steady'
    && $c['sleep.regularity']['facts']['spread'] === 70 && $c['sleep.regularity']['facts']['spread_prev'] === 40);
$c = $by($with(['sleep' => ['bedtime_sd' => 40.0, 'wake_sd' => 30.0, 'prev_bedtime_sd' => 80.0, 'prev_wake_sd' => 70.0]]));
check('35 against 75: steadier', ($c['sleep.regularity']['outcome'] ?? null) === 'steadier');
$c = $by($with(['sleep' => ['bedtime_sd' => 40.0, 'wake_sd' => 35.0, 'prev_bedtime_sd' => 45.0, 'prev_wake_sd' => 40.0]]));
check('a change smaller than 15 minutes is no change: level, and only said when it is large', !isset($c['sleep.regularity']));

section('Ranking and the suggestion');
$cands = insights_candidates($base, $claims, $cfg);
$best  = insights_choose($cands, [], $cfg);
check('one per slot: sleep, nutrition, training, body', array_keys($best) == ['sleep', 'training', 'nutrition', 'body']
    || count(array_intersect(array_keys($best), ['sleep', 'nutrition', 'training', 'body'])) === 4);
check('training: minutes and steps tie on relevance and evidence; the rule listed first wins', $best['training']['rule'] === 'activity.minutes');
$best = insights_choose($cands, ['training' => ['rule' => 'activity.minutes', 'outcome' => 'below']], $cfg);
check('the same as yesterday counts a little less: steps first today', $best['training']['rule'] === 'activity.steps');
$s = insights_choose_suggestion($cands, [], $claims, [], $cfg);
check('the suggestion: the most relevant, here sleep', ($s['rule'] ?? null) === 'sleep.duration' && $s['slot'] === 'suggestion');
$s = insights_choose_suggestion($cands, ['sleep.duration.below'], $claims, [], $cfg);
check('suggested in the last days: another one instead', ($s['rule'] ?? null) !== 'sleep.duration' && $s !== null);
$s = insights_choose_suggestion($cands, ['sleep.duration.below'], $claims, [], $cfg);
check('the activity suggestion leans on "a little is better than nothing" (ACT-01)',
    ($s['rule'] ?? null) !== 'activity.minutes' || $s['claim_key'] === 'activity.who.some');

/* ======================================================================
   THE LIFECYCLE, ON THE DATABASE
   ====================================================================== */

/** A synthetic person: profile, height, weight and, by default, a week of everything. */
function person(string $username, ?string $born, array $opts = []): int
{
    db_run('INSERT INTO users (username) VALUES (?)', [$username]);
    $id = (int) db_insert_id();
    db_run("INSERT INTO user_profiles (user_id, date_of_birth, gender, activity_level) VALUES (?, ?, 'female', 'moderate')", [$id, $born]);
    user_record_measurement($id, 'height', (float) ($opts['height'] ?? 170), 'cm');
    user_record_measurement($id, 'weight', (float) ($opts['weight'] ?? 66), 'kg');

    return $id;
}

/** A week of synthetic records relative to $now. */
function week(DateTimeImmutable $now, array $o = []): array
{
    $recs  = [];
    $sleep = (float) ($o['sleep_hours'] ?? 6.4);
    for ($i = 0; $i < 7; $i++) {
        $end = $now->setTime(7, 0)->modify("-$i days");
        if ($end > $now) {
            $end = $end->modify('-1 day');
        }
        $start = $end->modify('-' . (int) round($sleep * 60 + ($i % 3) * 6) . ' minutes');
        $recs[] = ['type' => 'sleep', 'external_id' => 'n' . $end->format('Ymd'), 'started_at' => $start->format('Y-m-d\TH:i:s'),
                   'ended_at' => $end->format('Y-m-d\TH:i:s')];
    }
    for ($i = 1; $i <= 7; $i++) {
        $recs[] = ['type' => 'metric', 'external_id' => 's' . $i, 'metric_code' => 'steps', 'value' => (int) ($o['steps'] ?? 5400) + $i * 10,
                   'recorded_at' => $now->modify("-$i days")->setTime(21, 0)->format('Y-m-d\TH:i:s')];
    }
    $recs[] = ['type' => 'metric', 'external_id' => 's0', 'metric_code' => 'steps', 'value' => 800, 'recorded_at' => $now->modify('-1 minute')->format('Y-m-d\TH:i:s')];
    foreach ([1, 3, 5, 12, 20] as $k => $ago) {
        $recs[] = ['type' => 'workout', 'external_id' => 'w' . $k, 'activity_type' => $k === 0 ? 'strength_training' : 'running',
                   'started_at' => $now->modify("-$ago days")->setTime(18, 0)->format('Y-m-d\TH:i:s'), 'duration_seconds' => 40 * 60];
    }
    $kcalShare = (float) ($o['food'] ?? 1.0);
    for ($i = 1; $i <= 5; $i++) {
        $recs[] = ['type' => 'nutrition', 'external_id' => 'm' . $i, 'consumed_at' => $now->modify("-$i days")->setTime(12, 0)->format('Y-m-d\TH:i:s'),
                   'meal_type' => 'lunch', 'label' => 'dag', 'carbs' => 250 * $kcalShare, 'protein' => 60 * $kcalShare, 'fat' => 75 * $kcalShare,
                   'saturated_fat' => 26 * $kcalShare, 'fibre' => 18 * $kcalShare, 'sodium' => 2600 * $kcalShare];
    }

    return $recs;
}

$now = new DateTimeImmutable('now');
if ((int) $now->format('G') < 9) {
    /* The week's nights end at 07:00; keep the test's "now" after that. */
    $now = $now->setTime(12, 0);
}

section('Once a day, stored and reused (9)');
$ann = person('ann', $now->modify('-34 years')->format('Y-m-d'));
$r   = health_import_records($ann, 'google_health_connect', week($now));
check('a synthetic week imports', $r['ok'] && $r['skipped'] === 0, json_encode($r['problems']));

$scoreBefore  = health_score_now($ann, $now);
$pointsBefore = db_all('SELECT award_key, points FROM point_events WHERE user_id = ? ORDER BY award_key', [$ann]);

$t0    = microtime(true);
$first = insights_today($ann, $now);
$ms    = (microtime(true) - $t0) * 1000;
check('the first load of the day works it out (full)', $first['mode'] === 'full');
check('… sleep, nutrition, training and body each have an insight', count(array_intersect(array_keys($first['slots']), ['sleep', 'nutrition', 'training', 'body'])) === 4,
    implode(', ', array_keys($first['slots'])));
check('… and at most one suggestion', $first['suggestion'] !== null && (int) db_value("SELECT COUNT(*) FROM user_insights WHERE user_id = ? AND slot = 'suggestion'", [$ann]) === 1);
$ids = array_map(static fn ($row) => $row['id'] . '/' . $row['updated_at'], $first['slots']);
$t0     = microtime(true);
$second = insights_today($ann, $now->modify('+2 minutes'));
$msRead = (microtime(true) - $t0) * 1000;
check('the next load reads what is stored: nothing worked out again', $second['mode'] === null
    && array_map(static fn ($row) => $row['id'] . '/' . $row['updated_at'], $second['slots']) === $ids);
printf("          (first evaluation %.0f ms, a stored read %.0f ms)\n", $ms, $msRead);

section('Traceable (13) and unchanged (16)');
$orphans = (int) db_value("SELECT COUNT(*) FROM user_insights i
    JOIN research_claims c ON c.id = i.claim_id JOIN research_sources s ON s.code = c.source_code
    WHERE i.user_id = ? AND (s.status <> 'approved' OR c.status <> 'active')", [$ann]);
$total = (int) db_value('SELECT COUNT(*) FROM user_insights WHERE user_id = ?', [$ann]);
$linked = (int) db_value('SELECT COUNT(*) FROM user_insights i JOIN research_claims c ON c.id = i.claim_id
    JOIN research_sources s ON s.code = c.source_code WHERE i.user_id = ?', [$ann]);
check('every stored insight names an active claim version of an approved, verified source', $total > 0 && $linked === $total && $orphans === 0);
$facts = array_map(static fn ($row) => json_decode($row['facts_json'], true), $first['slots']);
check('… with the derived facts it was written from — numbers, never a birth date or a raw record',
    !str_contains(json_encode($facts), (string) db_value('SELECT date_of_birth FROM user_profiles WHERE user_id = ?', [$ann]))
    && isset($facts['sleep']['avg_minutes']) && !isset($facts['sleep']['age']));
check('the Health Score is exactly what it was', health_score_now($ann, $now) == $scoreBefore);
check('the points are exactly what they were', db_all('SELECT award_key, points FROM point_events WHERE user_id = ? ORDER BY award_key', [$ann]) === $pointsBefore);

section('A repeated sync (10)');
$requestsBefore = count(requests());
$r = health_import_records($ann, 'google_health_connect', week($now));
check('the same week again is written (upserted) and marks the day', $r['ok']
    && db_value('SELECT data_changed_at FROM insight_days WHERE user_id = ? AND local_date = ?', [$ann, $now->format('Y-m-d')]) !== null);
/* The import marks the change on the real clock; this test runs its own. */
insights_note_change($ann, $now->modify('+1 minute'));
$re = insights_today($ann, $now->modify('+11 minutes'));
check('the next load reassesses — and finds the same facts', $re['mode'] === 'reassess');
check('… nothing stored changes', array_map(static fn ($row) => $row['id'] . '/' . $row['updated_at'], $re['slots']) === $ids);
check('… and nobody was asked anything', count(requests()) === $requestsBefore);

section('A real change (11)');
$sugBefore = $first['suggestion'];
health_import_records($ann, 'google_health_connect', week($now, ['sleep_hours' => 8.2]));
/* The import marks the change on the real clock; this test's clock is
   already 11 minutes ahead (the reassessment above), so mark it there. */
insights_note_change($ann, $now->modify('+20 minutes'));
$ch = insights_today($ann, $now->modify('+25 minutes'));
check('longer nights: reassessed, the sleep insight now says within', $ch['mode'] === 'reassess'
    && ($ch['slots']['sleep']['outcome'] ?? null) === 'within');
check('… the suggestion to go to bed earlier is withdrawn, not replaced', $sugBefore !== null && $sugBefore['rule_key'] === 'sleep.duration'
    && $ch['suggestion'] !== null && $ch['suggestion']['withdrawn_at'] !== null
    && (int) db_value("SELECT COUNT(*) FROM user_insights WHERE user_id = ? AND slot = 'suggestion'", [$ann]) === 1);
insights_note_change($ann, $now->modify('+26 minutes'));
$soon = insights_today($ann, $now->modify('+28 minutes'));
check('another change three minutes later waits: at most one reassessment per 10 minutes', $soon['mode'] === null);

section('At the same moment, and a request that died');
$bob = person('bob', $now->modify('-41 years')->format('Y-m-d'));
health_import_records($bob, 'google_health_connect', week($now));
$date = $now->format('Y-m-d');
$a = insights_claim_day($bob, $date, $now);
$b = insights_claim_day($bob, $date, $now);
check('two requests at once: one works the day out, the other reads', $a === 'full' && $b === null);
db_run("UPDATE insight_days SET started_at = ? WHERE user_id = ? AND local_date = ?", [$now->modify('-5 minutes')->format('Y-m-d H:i:s'), $bob, $date]);
$c1 = insights_claim_day($bob, $date, $now);
$c2 = insights_claim_day($bob, $date, $now);
check('a request that died five minutes ago is taken over — once', $c1 === 'full' && $c2 === null);
insights_evaluate($bob, $date, 'full', $now);
check('… and the day is done', db_value('SELECT state FROM insight_days WHERE user_id = ? AND local_date = ?', [$bob, $date]) === 'done');

section('Midnight');
$tomorrow = $now->modify('+1 day')->setTime(0, 5);
$next     = insights_today($ann, $tomorrow);
check('the first load after midnight starts a new day', $next['mode'] === 'full' && $next['date'] === $tomorrow->format('Y-m-d'));
check('… yesterday\'s insights stay as they were', (int) db_value('SELECT COUNT(*) FROM user_insights WHERE user_id = ? AND local_date = ?', [$ann, $date]) >= 4);
check('… and yesterday\'s withdrawn suggestion is not repeated today',
    ($next['suggestion']['rule_key'] ?? '') . '.' . ($next['suggestion']['outcome'] ?? '') !== 'sleep.duration.below');

section('Duplicates (8)');
$dup = person('dup', $now->modify('-30 years')->format('Y-m-d'));
$week = week($now);
health_import_records($dup, 'google_health_connect', $week);
$single = insights_facts($dup, $now)['sleep'];
$copies = array_values(array_filter($week, static fn ($r) => $r['type'] === 'sleep'));
foreach ($copies as $i => $copy) {
    $copies[$i]['external_id'] = 'polar-' . $copy['external_id'];
}
health_import_records($dup, 'polar', $copies);
$twice = insights_facts($dup, $now)['sleep'];
check('the same nights from a second device: still 7 nights, the same average', $twice['nights'] === $single['nights']
    && abs((float) $twice['avg_minutes'] - (float) $single['avg_minutes']) < 0.001);

section('The cijfer stays (5, 6, 7)');
$cleo = person('cleo', $now->modify('-28 years')->format('Y-m-d'));
health_import_records($cleo, 'google_health_connect', week($now, ['food' => 0.35]));
$cf = insights_facts($cleo, $now)['nutrition'];
check('5 · food logged on 5 days but far under the energy target: no day counts as complete', $cf['logged_days'] === 5 && $cf['complete_days'] === 0);
$cday = insights_today($cleo, $now);
check('… so nothing is said about food', !isset($cday['slots']['nutrition']));
$typeId = health_metric_type_id('nutrition_rating');
foreach ([1, 2, 3] as $back) {
    $d = $now->modify("-$back days");
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at) VALUES (?, ?, ?, ?, 7, ?)',
        [$cleo, $typeId, health_source_id('manual'), 'daily-rating:' . $d->format('Y-m-d'), $d->setTime(20, 0)->format('Y-m-d H:i:s')]);
}
check('7 · the Voeding score is the cijfer, as before: 70', health_score_now($cleo, $now)['nutrition']['score'] === 70);
check('6 · complete logged food (Ann) gives food insights from the source data …', isset($first['slots']['nutrition']));
check('… and never replaces the cijfer: without one, no Voeding score at all', health_score_now($ann, $now)['nutrition']['score'] === null);

section('Both apps read the same stored result (17, 18)');
$data = require $root . '/config/dashboard.php';
$data['auth'] = app_auth_account($ann);
i18n_force('nl');
$web = app_page_data($data, $ann);
$app = app_state_payload(app_page_data($data, $ann));
check('Overzicht: three rows; sleep, nutrition and training filled', count($web['insights']['items']) === 3
    && array_column($web['insights']['items'], 'state') === ['filled', 'filled', 'filled']);
check('the app\'s state carries the very same insights, suggestion and research',
    json_encode($app['insights']) === json_encode(app_state_plain($web['insights']))
    && json_encode($app['recommendation']) === json_encode(app_state_plain($web['recommendation']))
    && json_encode($app['research']) === json_encode(app_state_plain($web['research'])));
check('each page\'s own insight is the Overzicht\'s: the same id', ($web['insights']['context']['sleep']['id'] ?? 0) === $web['insights']['items'][0]['id']);
$ids = array_merge(array_column(array_filter($web['insights']['items'], static fn ($i) => $i['state'] === 'filled'), 'research'),
    array_column($web['insights']['references'], 'research'), [$web['insights']['context']['body']['research'] ?? null]);
check('every "Bekijk onderzoek" opens a detail that is there', array_diff(array_filter($ids), array_keys($web['research']['details'])) === []);
$detail = reset($web['research']['details']);
check('a detail: finding, claim, source, year, strength, population, limitations, link',
    $detail['source']['findings'] !== '' && $detail['statement'] !== '' && $detail['source']['title'] !== '' && $detail['source']['year'] !== ''
    && $detail['strength']['label'] !== '' && $detail['population'] !== '' && $detail['limitations'] !== [] && str_starts_with($detail['source']['href'], 'https://'));
check('no percentage or probability on any label', !preg_match('/\d\s*%/', json_encode(array_column(array_column($web['research']['details'], 'strength'), 'label'))));
i18n_force('en');
$en = app_page_data($data, $ann);
check('in English: the same insights, in English, the numbers the English way', ($en['insights']['items'][0]['title'] ?? '') === 'Sleep duration'
    && str_contains($en['insights']['items'][0]['body'], 'a night on average'));
i18n_force('nl');

/* ======================================================================
   NO AI SERVICE (30.1)
   ====================================================================== */
section('No AI service writes or rewords an insight (30.1)');
check('everything above ran with a Gemini key and a stand-in answering — and the stand-in was never asked', requests() === []);
$insightCode = '';
foreach (glob($root . '/includes/insights/*.php') ?: [] as $file) {
    $insightCode .= (string) file_get_contents($file);
}
$insightCode .= (string) file_get_contents($root . '/includes/insights.php');
check('no insight code reaches an AI client', !preg_match('/gemini|includes\/ai\/|ai_request|ai_generate/i', $insightCode));
check('Gemini\'s wording is gone from the code and the config', !is_file($root . '/includes/insights/phrase.php')
    && !isset(insights_config()['gemini']) && !isset(insights_config()['consent_version']));
$rendered = insights_render(db_one('SELECT * FROM user_insights WHERE user_id = ? AND slot <> \'suggestion\' ORDER BY id LIMIT 1', [$ann]));
check('an insight as both apps get it: the template, nothing else', $rendered !== null && !array_key_exists('phrased', $rendered));
db_run("UPDATE user_insights SET phrased_body = 'Een oude Gemini-tekst.', phrased_locale = 'nl' WHERE user_id = ?", [$ann]);
$shown = insights_render(db_one('SELECT * FROM user_insights WHERE user_id = ? AND slot <> \'suggestion\' ORDER BY id LIMIT 1', [$ann]));
check('a wording left in the database from 29.0 is never shown', $shown !== null && $shown['body'] === $rendered['body']);
db_run('UPDATE user_insights SET phrased_body = NULL, phrased_locale = NULL WHERE user_id = ?', [$ann]);

require_once $root . '/lib/settings.php';
$privacy = settings_prepare(config_copy('settings'), ['signed_in' => true, 'user' => ['id' => $ann, 'username' => 'ann']]);
$keys = [];
foreach ($privacy['pages']['privacy']['blocks'] ?? [] as $block) {
    foreach ((array) ($block['items'] ?? []) as $item) { $keys[] = $item['key'] ?? null; }
}
check('Privacy: no Inzichten switch; OwnifyAI\'s is still there', !in_array('ai_insights_consent', $keys, true) && in_array('ai_consent', $keys, true));

/* Every sentence Ownify can say, in both languages, without a cause, an
   order, a diagnosis or a comparison with other people. */
foreach (['nl', 'en'] as $lang) {
    i18n_force($lang);
    $copy  = insights_copy();
    $texts = [];
    array_walk_recursive($copy, static function ($v) use (&$texts): void { if (is_string($v)) { $texts[] = $v; } });
    $bad = [];
    foreach ($texts as $text) {
        foreach ((array) insights_config()['forbidden'][$lang] as $word) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '/iu', $text)) {
                $bad[] = $word . ' in "' . mb_substr($text, 0, 60) . '"';
            }
        }
    }
    check("every template in '$lang' says no cause, order, diagnosis or comparison", $bad === [], implode('; ', array_slice($bad, 0, 3)));
}
i18n_force('nl');

section('Efficiency');
$evals = (int) db_value('SELECT COALESCE(SUM(evaluations), 0) FROM insight_days WHERE local_date = ?', [$date]);
printf("          people today: %d · evaluations: %d · AI requests: 0\n",
    (int) db_value('SELECT COUNT(*) FROM insight_days WHERE local_date = ?', [$date]), $evals);

} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

printf("\n%d passed, %d failed\n", $pass_, $fail_);
exit($fail_ === 0 ? 0 : 1);

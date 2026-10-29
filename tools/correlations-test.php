<?php
/**
 * Combined insights (docs/CORRELATIONS.md), tested on synthetic data only —
 * the statistics on made-up series, everything else on a THROWAWAY database
 * this script makes and drops (ownify_cor_<random>), never an existing one.
 * No real person, no AI service.
 *
 *     DB_USER=root php tools/correlations-test.php
 *
 * The statistics (no database):
 *   a planted difference is found, with its sign, its size and an interval
 *   that holds it · HRV in percent · people with no link at all rarely see a
 *   pattern (the false-alarm rate) · the weekend and a slow trend moving two
 *   things together are not a pattern · too few days and too small a group
 *   say what is missing · "Geen patroon" needs enough days and a tight
 *   interval · the weekend pair keeps its weekdays · Mogelijk patroon only
 *   below p = 0.10 · the same numbers on every run · Overzicht's four
 *
 * The day table (database):
 *   a night belongs to the date it ended · onset after midnight · the night
 *   of the clock change · a day without steps or a workout is no rest day ·
 *   only completely logged days of food · caffeine only for people who log
 *   it · HRV from the night's own readings · the pairs line up the right days
 *
 * The run (database):
 *   worked out once a day and stored · read back without working it out ·
 *   again when the day's insights are reassessed or the engine changes · one
 *   request at a time, and a request that died · old runs go · an account's
 *   results go with the account
 *
 * What both apps get:
 *   four on Overzicht, combined only · the Inzichten page by label · every
 *   insight's page with Chart A, numbers, research and method · research by
 *   age (65+ protein) · English with English numbers · no cause in any
 *   sentence · the website and the app read the same · without migration
 *   029 nothing changes
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$name = 'ownify_cor_' . bin2hex(random_bytes(3));
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
$import = static function (string $db, string $file) use ($host, $user, $pass): array {
    exec(sprintf('mysql -h%s -u%s %s %s < %s 2>&1', escapeshellarg($host), escapeshellarg($user),
        $pass === '' ? '' : '-p' . escapeshellarg($pass), escapeshellarg($db), escapeshellarg($file)), $out, $code);

    return [$code, $out];
};
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code, $out] = $import($name, $root . '/database/schema.sql');
if ($code !== 0) {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    fwrite(STDERR, "schema.sql did not import:\n" . implode("\n", $out) . "\n");
    exit(2);
}
$old = $name . '_029';   // a second one without migration 029, made below
register_shutdown_function(static function () use ($server, $name, $old): void {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    $server->exec("DROP DATABASE IF EXISTS `$old`");
});

foreach (['DB_HOST' => $host, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASSWORD' => $pass, 'GEMINI_API_KEY' => ''] as $k => $v) {
    putenv("$k=$v");
}

date_default_timezone_set('Europe/Amsterdam');
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/insights.php';
require_once $root . '/lib/app-data.php';

echo "\nCombined insights, on $name\n" . str_repeat('-', 72) . "\n";

$cfg = corr_config();

/* ---------------------------------------------------- synthetic series */

/** A seeded normal draw. */
function gauss(): float
{
    return sqrt(-2 * log(mt_rand(1, mt_getrandmax()) / mt_getrandmax())) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
}

/**
 * A synthetic day table: every variable the pairs read, independent noise,
 * unless $plant changes one. $days of history ending yesterday.
 */
function frame_of(int $seed, int $days = 90, ?Closure $plant = null): array
{
    mt_srand($seed);
    $to   = '2026-11-14';
    $from = corr_day($to, -89);
    $v    = [];
    $first = corr_day($to, -($days - 1));
    for ($i = -1; $i <= 90; $i++) {
        $d = corr_day($from, $i);
        if ($d < corr_day($first, -1)) {
            continue;
        }
        $trained = mt_rand(0, 99) < 45 ? 1.0 : 0.0;
        $hard    = $trained && mt_rand(0, 99) < 40 ? 1.0 : 0.0;
        $row = [
            'trained' => $trained, 'hard' => $hard, 'load' => $trained ? 40 + mt_rand(0, 60) : 0.0,
            'late_workout' => $trained && mt_rand(0, 99) < 25 ? 1.0 : 0.0,
            'steps' => 8000 + 2000 * gauss(), 'kcal' => 2200 + 250 * gauss(), 'protein_kg' => 1.2 + 0.25 * gauss(),
            'water' => 1.8 + 0.4 * gauss(), 'rating' => (float) mt_rand(5, 9), 'late_meal' => 30 * gauss(),
            'late_caffeine' => mt_rand(0, 99) < 30 ? 1.0 : 0.0,
        ];
        $night = [
            'sleep_min' => 430 + 35 * gauss(), 'onset_dev' => 25 * gauss(), 'onset_clock' => 660 + 25 * gauss(),
            'hrv' => log(55) + 0.15 * gauss(), 'sleep_hr' => 54 + 2 * gauss(),
        ];
        $night['short_night'] = $night['sleep_min'] <= 380 ? 1.0 : 0.0;
        $night['weekend'] = corr_weekend_evening($d) ? 1.0 : 0.0;
        if ($plant !== null) {
            [$row, $night] = $plant($d, $row, $night);
        }
        if ($d >= $first && $d <= $to) {
            foreach ($row as $k => $x) {
                $v[$k][$d] = (float) $x;
            }
        }
        $nd = corr_day($d, 1);
        if ($nd >= $first) {
            foreach ($night as $k => $x) {
                $v[$k][$nd] = (float) $x;
            }
        }
    }
    foreach ($v as $k => $series) {
        ksort($v[$k]);
    }

    return ['from' => $from, 'to' => $to, 'days' => 90, 'vars' => $v];
}

$strong = static fn (array $r): bool => in_array($r['tier'], ['clear', 'pattern'], true);

try {

/* ======================================================================
   THE STATISTICS
   ====================================================================== */
section('A difference that is there is found');
$hits = 0;
$effects = [];
$covered = 0;
for ($s = 1; $s <= 25; $s++) {
    $f = frame_of($s, 90, static function ($d, $row, $night) {
        $night['sleep_min'] += $row['load'] > 0 ? 25 : 0;
        return [$row, $night];
    });
    $r = corr_evaluate($f, $cfg, $s)['pairs']['C01'];
    $hits += $strong($r) && $r['effect'] > 0 ? 1 : 0;
    $effects[] = $r['effect'];
    $covered += ($r['lo'] <= 25 && $r['hi'] >= 25) ? 1 : 0;
}
$mean = array_sum($effects) / count($effects);
/* The design's power at 90 days for a difference this size, against
   noise of 35 minutes, is about 60–80% (round-1 simulation). */
check('training → 25 min more sleep: a Patroon or Duidelijk patroon, upward, in most people (' . $hits . '/25)', $hits >= 13);
check(sprintf('… the difference is about right on average (%.1f min against 25)', $mean), abs($mean - 25) < 5);
check('… and the 90% interval holds the true difference in most runs (' . $covered . '/25)', $covered >= 19);

$hrv = [];
$chartsOk = true;
for ($s = 30; $s < 50; $s++) {
    $f = frame_of($s, 90, static function ($d, $row, $night) {
        $night['hrv'] += $row['hard'] ? log(0.85) : 0.0;
        return [$row, $night];
    });
    $r = corr_evaluate($f, $cfg, $s)['pairs']['C03'];
    $hrv[] = $r['effect'];
    $chartsOk = $chartsOk && $r['chart']['kind'] === 'ms' && $r['chart']['low'] > 30 && $r['chart']['low'] < 80;
}
$mean = array_sum($hrv) / count($hrv);
check(sprintf('HRV 15%% lower after hard days: about that in percent on average (%.1f%%), as ms in the chart', $mean),
    $mean < -11 && $mean > -19 && $chartsOk);

$f = frame_of(9, 90, static function ($d, $row, $night) {
    $night['onset_clock'] += $night['weekend'] ? 60 : 0;
    return [$row, $night];
});
$r = corr_evaluate($f, $cfg, 9)['pairs']['C15'];
check('the weekend pair keeps the weekdays in: an hour later at weekends is found', $strong($r) && $r['effect'] > 40, json_encode([$r['tier'], $r['effect']]));

section('No link, no pattern (false alarms)');
$people = 0;
$falses = 0;
$tests  = 0;
$possibleOk = true;
$qOk = true;
for ($s = 100; $s < 160; $s++) {
    $run = corr_evaluate(frame_of($s), $cfg, $s);
    $any = false;
    foreach ($run['pairs'] as $p) {
        if ($p['tested']) {
            $tests++;
            $qOk = $qOk && $p['q'] >= $p['p'] - 1e-12;
        }
        if ($strong($p)) {
            $falses++;
            $any = true;
        }
        if ($p['tier'] === 'possible' && !($p['p'] < 0.10)) {
            $possibleOk = false;
        }
    }
    $people += $any ? 1 : 0;
}
check(sprintf('60 people with nothing linked: %d see a pattern (%.0f%%; at most 15%%)', $people, 100 * $people / 60), $people <= 9);
check(sprintf('… false patterns per test %.1f%% (at most 5%%)', 100 * $falses / max(1, $tests)), $falses / max(1, $tests) <= 0.05);
check('… every q-value at least its p-value (Benjamini–Hochberg)', $qOk);
check('… Mogelijk patroon only below p = 0.10, before correction', $possibleOk);

$weekend = 0;
$trend = 0;
for ($s = 200; $s < 230; $s++) {
    $wf = frame_of($s, 90, static function ($d, $row, $night) {
        $we = in_array((int) date('N', strtotime($d . ' 12:00')), [6, 7], true);
        $row['steps'] += $we ? 3000 : 0;                 // busier at weekends
        $night['sleep_min'] += corr_weekend_evening($d) ? 45 : 0;   // and longer weekend nights
        return [$row, $night];
    });
    $weekend += $strong(corr_evaluate($wf, $cfg, $s)['pairs']['C06']) ? 1 : 0;
    $tf = frame_of($s, 90, static function ($d, $row, $night) {
        $t = (strtotime($d) - strtotime('2026-08-17')) / 86400;
        $row['steps'] += 40 * $t;                          // both drift up over the months
        $night['sleep_min'] += 0.6 * $t;
        return [$row, $night];
    });
    $trend += $strong(corr_evaluate($tf, $cfg, $s)['pairs']['C06']) ? 1 : 0;
}
check('weekends that move steps and sleep together are no pattern (' . $weekend . '/30)', $weekend <= 3);
check('a slow trend in both is no pattern (' . $trend . '/30)', $trend <= 3);

section('Not enough yet, and no difference');
$r = corr_evaluate(frame_of(11, 15), $cfg, 11)['pairs']['C01'];
check('15 days: Nog onduidelijk, with the days still missing (' . json_encode($r['need']) . ')',
    $r['tier'] === 'unclear' && $r['need']['kind'] === 'days' && $r['need']['count'] === (int) $cfg['tiers']['pattern']['days'] - $r['n']);
$f = frame_of(12, 90, static function ($d, $row, $night) {
    $row['hard'] = (int) date('j', strtotime($d)) % 25 === 0 ? 1.0 : 0.0;   // 3–4 hard days
    return [$row, $night];
});
$r = corr_evaluate($f, $cfg, 12)['pairs']['C03'];
check('three hard days: Nog onduidelijk, with the hard workouts still missing', $r['tier'] === 'unclear' && $r['need']['kind'] === 'group' && $r['need']['count'] > 0);
$none = 0;
for ($s = 300; $s < 320; $s++) {
    $nf = frame_of($s, 90, static function ($d, $row, $night) {
        $night['sleep_min'] = 430 + 6 * gauss();   // steady sleep, nothing to do with steps
        return [$row, $night];
    });
    $none += corr_evaluate($nf, $cfg, $s)['pairs']['C06']['tier'] === 'none' ? 1 : 0;
}
check('steady sleep and enough days: Geen patroon (' . $none . '/20)', $none >= 15);
$r = corr_evaluate(frame_of(13, 25), $cfg, 13)['pairs']['C06'];
check('Geen patroon needs at least 30 days', $r['tier'] !== 'none');

section('The same numbers every time; Overzicht\'s four');
$a = corr_evaluate(frame_of(21), $cfg, 5);
$b = corr_evaluate(frame_of(21), $cfg, 5);
check('the same day table gives the same result, interval included', json_encode($a) === json_encode($b));
$f = frame_of(22, 90, static function ($d, $row, $night) {
    $night['sleep_min'] += $row['load'] > 0 ? 30 : 0;
    $night['hrv'] += $row['hard'] ? log(0.85) : 0.0;
    return [$row, $night];
});
$run   = corr_evaluate($f, $cfg, 22);
$four  = corr_overview($run['pairs'], $cfg, 40);
$tiers = array_map(static fn ($c) => $run['pairs'][$c]['tier'], $four);
$outs  = array_map(static fn ($c) => $cfg['pairs'][$c]['outcome'], array_filter($four, static fn ($c) => in_array($run['pairs'][$c]['tier'], ['clear', 'pattern'], true)));
check('four, patterns first, then Nog onduidelijk (' . implode(', ', $four) . ')', count($four) === 4
    && $tiers === array_merge(array_filter($tiers, static fn ($t) => $t !== 'unclear'), array_filter($tiers, static fn ($t) => $t === 'unclear')));
check('… never Mogelijk patroon or Geen patroon on Overzicht', array_intersect($tiers, ['possible', 'none']) === []);
check('… one per outcome', count($outs) === count(array_unique($outs)));

/* ======================================================================
   THE DAY TABLE, ON THE DATABASE
   ====================================================================== */

/** A synthetic person. */
function person(string $username, string $born): int
{
    db_run('INSERT INTO users (username) VALUES (?)', [$username]);
    $id = (int) db_insert_id();
    db_run("INSERT INTO user_profiles (user_id, date_of_birth, gender, activity_level) VALUES (?, ?, 'female', 'moderate')", [$id, $born]);
    user_record_measurement($id, 'height', 170.0, 'cm');
    user_record_measurement($id, 'weight', 66.0, 'kg');

    return $id;
}

/**
 * 60 days of synthetic records before $now: nights, steps (not every day),
 * workouts (hard ones late in the evening), meals (complete on most days),
 * HRV and heart rate in sleep, water, and caffeine on $caffeineDays days.
 */
function history(DateTimeImmutable $now, int $caffeineDays = 20, int $seed = 3): array
{
    mt_srand($seed);
    $recs = [];
    $kcal = nutrition_targets_for_user($GLOBALS['history_for'])['targets']['calorie_target_kcal'] ?? 2000;
    for ($i = 1; $i <= 60; $i++) {
        $day     = $now->modify("-$i days");
        $date    = $day->format('Y-m-d');
        $workout = $i % 3 === 0;
        $hard    = $i % 6 === 0;
        /* The night that ENDS on this date: onset around 23:15, some after midnight. */
        $start = $day->modify('-1 day')->setTime(23, 15)->modify('+' . (($i % 5) * 20 - 20 + ($hard ? 0 : 0)) . ' minutes');
        $end   = $day->setTime(7, 0)->modify('+' . ($i % 4) * 10 . ' minutes');
        $recs[] = ['type' => 'sleep', 'external_id' => 'n' . $date, 'started_at' => $start->format('Y-m-d\TH:i:s'), 'ended_at' => $end->format('Y-m-d\TH:i:s')];
        $recs[] = ['type' => 'metric', 'external_id' => 'h' . $date, 'metric_code' => 'hrv', 'value' => 50 + mt_rand(-8, 8),
                   'recorded_at' => $end->modify('-30 minutes')->format('Y-m-d\TH:i:s')];
        $recs[] = ['type' => 'metric', 'external_id' => 'r' . $date, 'metric_code' => 'sleeping_hr', 'value' => 55 + mt_rand(-3, 3),
                   'recorded_at' => $end->modify('-5 minutes')->format('Y-m-d\TH:i:s')];
        if ($i % 7 !== 2) {   // one day a week without steps: no data, not a rest day
            $recs[] = ['type' => 'metric', 'external_id' => 's' . $date, 'metric_code' => 'steps', 'value' => 6000 + mt_rand(0, 5000),
                       'recorded_at' => $day->setTime(21, 0)->format('Y-m-d\TH:i:s')];
        }
        if ($workout) {
            $recs[] = ['type' => 'workout', 'external_id' => 'w' . $date, 'activity_type' => 'running',
                       'started_at' => $day->setTime($hard ? 20 : 7, 0)->format('Y-m-d\TH:i:s'), 'duration_seconds' => 45 * 60,
                       'avg_hr' => $hard ? 165 : 110, 'max_hr' => $hard ? 182 : 130];
        }
        $share = $i % 9 === 4 ? 0.3 : 1.0;   // now and then an incomplete log
        $recs[] = ['type' => 'nutrition', 'external_id' => 'm' . $date, 'consumed_at' => $day->setTime(19, 30)->format('Y-m-d\TH:i:s'),
                   'meal_type' => 'dinner', 'label' => 'dag', 'carbs' => $kcal * 0.5 / 4 * $share, 'protein' => 80 * $share,
                   'fat' => ($kcal * 0.5 - 320) / 9 * $share, 'water' => 1.5 + ($workout ? 0.4 : 0)];
        if ($i <= $caffeineDays) {
            $recs[] = ['type' => 'nutrition', 'external_id' => 'c' . $date, 'consumed_at' => $day->setTime($i % 2 ? 9 : 17, 0)->format('Y-m-d\TH:i:s'),
                       'meal_type' => 'drink', 'label' => 'koffie', 'caffeine' => 95];
        }
    }

    return $recs;
}

section('The day table');
$now = new DateTimeImmutable('2026-11-15 12:00:00');
$who = person('fenna', '1992-03-04');
$GLOBALS['history_for'] = $who;
$r   = health_import_records($who, 'google_health_connect', history($now));
check('60 synthetic days import', $r['ok'] && $r['skipped'] === 0, json_encode($r['problems'] ?? []));
$fr = corr_frame($who, $now);
$v  = $fr['vars'];
check('the window ends yesterday and is 90 days long', $fr['to'] === '2026-11-14' && $fr['from'] === '2026-08-17');
$ends = array_map(static fn ($r) => substr((string) $r['ended_at'], 0, 10), db_all('SELECT ended_at FROM sleep_sessions WHERE user_id = ?', [$who]));
$ends = array_values(array_unique($ends));
sort($ends);
check('a night belongs to the date it ended', array_keys($v['sleep_min']) === $ends && !isset($v['sleep_min']['2026-11-15']));
$on = static fn (string $date) => $v['onset_clock'][$date] ?? null;
/* i = 1 → 2026-11-14: start 23:15 + (1·20 − 20) = 23:15 on the 13th → 11 h 15 after noon */
check('onset in minutes after noon of the evening: 23:15 is 675', abs(($on('2026-11-14') ?? 0) - 675) < 0.01, (string) $on('2026-11-14'));
/* i = 4 → 2026-11-11: start 23:15 + 60 = 00:15 → 735 */
check('… and 00:15 after midnight is 735, not a small number', abs(($on('2026-11-11') ?? 0) - 735) < 0.01, (string) $on('2026-11-11'));
/* i = 21 → 2026-10-25, the night the clocks went back: 23:15 + 0 → 675 still */
check('the night of the clock change (25 October) keeps its onset: 675', abs(($on('2026-10-25') ?? 0) - 675) < 0.01, (string) $on('2026-10-25'));
check('… and its length counts the extra hour', ($v['sleep_min']['2026-10-25'] ?? 0) > 8.5 * 60, (string) ($v['sleep_min']['2026-10-25'] ?? ''));
check('usual onset per month and kind of evening: the deviation is near 0 on average',
    abs(array_sum($v['onset_dev']) / count($v['onset_dev'])) < 30);
$noSteps = $now->modify('-2 days')->format('Y-m-d');   // i = 2: no steps, no workout
check('a day without steps or a workout is no rest day: no load, no "trained"', !isset($v['steps'][$noSteps]) && !isset($v['load'][$noSteps]) && !isset($v['trained'][$noSteps]));
$hardDay = $now->modify('-6 days')->format('Y-m-d');
$easyDay = $now->modify('-3 days')->format('Y-m-d');
check('a hard evening workout: hard, trained, load minutes × 3, and late', ($v['hard'][$hardDay] ?? null) === 1.0 && ($v['trained'][$hardDay] ?? null) === 1.0
    && abs(($v['load'][$hardDay] ?? 0) - 135) < 0.01 && ($v['late_workout'][$hardDay] ?? null) === 1.0);
check('an easy morning workout: not hard, load × 1, not late', ($v['hard'][$easyDay] ?? null) === 0.0 && abs(($v['load'][$easyDay] ?? 0) - 45) < 0.01
    && ($v['late_workout'][$easyDay] ?? null) === 0.0);
$partial = $now->modify('-4 days')->format('Y-m-d');   // i = 4: 30% logged
check('only completely logged days count for food', isset($v['kcal'][$now->modify('-1 day')->format('Y-m-d')]) && !isset($v['kcal'][$partial])
    && !isset($v['protein_kg'][$partial]));
check('… protein per kg of the latest weight', abs(($v['protein_kg'][$now->modify('-1 day')->format('Y-m-d')] ?? 0) - 80 / 66) < 0.01);
check('HRV from the night\'s own readings, as its logarithm', abs(($v['hrv']['2026-11-14'] ?? 0) - log((float) db_value(
    "SELECT value FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'hrv' AND m.recorded_on = '2026-11-14'", [$who]))) < 1e-9);
$late = array_sum($v['late_caffeine'] ?? []);
check('caffeine, logged on 20 days: late (17:00) on about half of them, early (09:00) never', isset($v['late_caffeine']) && $late >= 7 && $late <= 12, (string) $late);
$few = person('gijs', '1990-01-01');
$GLOBALS['history_for'] = $few;
health_import_records($few, 'google_health_connect', history($now, 3, 4));
check('caffeine logged on only 3 days says nothing: no late caffeine at all', !isset(corr_frame($few, $now)['vars']['late_caffeine']));

$run = corr_evaluate($fr, $cfg, $who);
$expected = 0;
foreach ($v['load'] as $d => $_) {
    $expected += ($d >= $fr['from'] && $d <= $fr['to'] && isset($v['sleep_min'][corr_day($d, 1)])) ? 1 : 0;
}
check('training → sleep pairs a day with the night after it (' . $run['pairs']['C01']['n'] . ' days)', $run['pairs']['C01']['n'] === $expected);

/* ======================================================================
   THE RUN, ON THE DATABASE
   ====================================================================== */
section('Once a day, stored');
$t0 = microtime(true);
$first = correlations_today($who, $now);
$ms = (microtime(true) - $t0) * 1000;
$row = db_one('SELECT * FROM correlation_runs WHERE user_id = ? AND local_date = ?', [$who, '2026-11-15']);
check('the first load works the day out and stores every pair', $first !== null && $row['state'] === 'done'
    && (int) db_value('SELECT COUNT(*) FROM user_correlations WHERE run_id = ?', [$row['id']]) === count($cfg['pairs']));
printf("          (worked out in %.0f ms, %d ms stored)\n", $ms, (int) $row['duration_ms']);
$storedTiers = array_map(static fn ($p) => [$p['tier'], $p['n']], $first['pairs']);
$engineTiers = array_map(static fn ($p) => [$p['tier'], $p['n']], $run['pairs']);
check('… what is stored is what the engine says', $storedTiers === $engineTiers,
    json_encode(array_diff_key(array_filter($storedTiers, static fn ($t, $k) => $t !== ($engineTiers[$k] ?? null), ARRAY_FILTER_USE_BOTH))) . ' vs '
    . json_encode(array_intersect_key($engineTiers, array_filter($storedTiers, static fn ($t, $k) => $t !== ($engineTiers[$k] ?? null), ARRAY_FILTER_USE_BOTH))));
check('… numbers only: no record, no birth date', !str_contains(json_encode(db_all('SELECT detail_json FROM user_correlations WHERE user_id = ?', [$who])), '1992'));
$again = correlations_today($who, $now->modify('+3 minutes'));
check('the next load reads it: nothing worked out again', db_value('SELECT computed_at FROM correlation_runs WHERE id = ?', [$row['id']]) === $row['computed_at']
    && json_encode($again) === json_encode($first));
correlations_today($who, $now->modify('+20 minutes'), true);
check('after the day\'s insights were reassessed it is worked out again', db_value('SELECT computed_at FROM correlation_runs WHERE id = ?', [$row['id']]) !== $row['computed_at']);
db_run("UPDATE correlation_runs SET engine_version = 'old' WHERE id = ?", [$row['id']]);
correlations_today($who, $now->modify('+25 minutes'));
check('a new engine version works it out again', db_value('SELECT engine_version FROM correlation_runs WHERE id = ?', [$row['id']]) === $cfg['engine_version']);

section('One at a time; a request that died; old runs');
db_run("UPDATE correlation_runs SET state = 'running', started_at = ? WHERE id = ?", [$now->modify('+30 minutes')->format('Y-m-d H:i:s'), $row['id']]);
check('while one request works it out, another does not', corr_claim_run($who, '2026-11-15', $now->modify('+31 minutes'), true) === null);
check('… and still shows the last finished result', correlations_today($who, $now->modify('+31 minutes')) !== null);
check('a run that died two minutes ago is taken over — by one request only',
    corr_claim_run($who, '2026-11-15', $now->modify('+33 minutes'), false) === (int) $row['id']
    && corr_claim_run($who, '2026-11-15', $now->modify('+33 minutes'), false) === null);
corr_run($who, (int) $row['id'], $now->modify('+33 minutes'));
$next = $now->modify('+1 day');
check('a new day: the first load claims it, a second at the same moment does not',
    corr_claim_run($who, $next->format('Y-m-d'), $next, false) !== null && corr_claim_run($who, $next->format('Y-m-d'), $next, false) === null);
db_run("INSERT INTO correlation_runs (user_id, local_date, state, computed_at, engine_version) VALUES (?, '2026-09-01', 'done', '2026-09-01 10:00:00', '1')", [$who]);
corr_run($who, (int) db_value('SELECT id FROM correlation_runs WHERE user_id = ? AND local_date = ?', [$who, $next->format('Y-m-d')]), $next);
check('runs older than five weeks go', (int) db_value("SELECT COUNT(*) FROM correlation_runs WHERE user_id = ? AND local_date = '2026-09-01'", [$who]) === 0);

/* ======================================================================
   WHAT BOTH APPS GET
   ====================================================================== */
section('Overzicht, Inzichten and each insight');
$data = require $root . '/config/dashboard.php';
$data['auth'] = app_auth_account($who);
i18n_force('nl');
$web = app_page_data($data, $who);
$ins = $web['insights'];
$cor = $web['correlations'] ?? null;
check('Overzicht: combined insights only, at most four, its head opens Inzichten', ($ins['combined'] ?? false) && count($ins['items']) <= 4
    && ($ins['open']['detail'] ?? '') === 'insights' && array_filter($ins['items'], static fn ($i) => $i['slot'] !== 'combined') === []);
check('… each row with its label and its own page', array_filter($ins['items'], static fn ($i) => !isset($cor['details'][$i['detail']]) || $i['tier']['label'] === '') === []);
$order = array_column($cor['page']['groups'], 'tier');
check('the Inzichten page: by label, strongest first (' . implode(', ', $order) . ')',
    $order === array_values(array_intersect(['clear', 'pattern', 'possible', 'none', 'unclear'], $order)));
check('… every pair once', array_sum(array_map(static fn ($g) => count($g['rows']), $cor['page']['groups'])) === count($cfg['pairs']));
$charts = array_filter(array_column($cor['details'], 'chart'));
$fillsOk = true;
foreach ($charts as $chart) {
    foreach ($chart['rows'] as $cr) {
        $fillsOk = $fillsOk && $cr['fill'] >= 0 && $cr['fill'] <= 1 && $cr['value'] !== '' && $cr['count'] !== '';
    }
    $fillsOk = $fillsOk && count($chart['rows']) === 2 && $chart['note'] !== '';
}
check('Chart A: two rows from zero, with value, days and the note that it is adjusted (' . count($charts) . ' charts)', $fillsOk);
$detail = $cor['details']['insight-C01'];
check('an insight\'s page: label and what it means, the sentence, the numbers, the method and its sources',
    $detail['tier']['means'] !== '' && $detail['body'] !== '' && count($detail['numbers']) >= 2 && count($detail['method']['paragraphs']) === 4
    && count($detail['method']['sources']) === 3);
$researchIds = [];
foreach ($cor['details'] as $d) {
    foreach ($d['research'] as $item) {
        $researchIds[] = $item['research'];
    }
    $ok = ($d['research'] !== []) !== ($d['own_data'] !== null);
    if (!$ok) {
        break;
    }
}
check('research or "only your own data", never both, never neither', $ok);
check('every research link opens a detail that is there', array_diff($researchIds, array_keys($web['research']['details'])) === []);
$strengths = array_merge(...array_values(array_map(static fn ($d) => array_column($d['research'], 'strength'), $cor['details'])));
check('… with a research label, never a percentage', !preg_match('/\d\s*%/', json_encode(array_column($strengths, 'label')))
    && array_diff(array_column($strengths, 'key'), ['zeer_betrouwbaar', 'betrouwbaar', 'beperkt']) === []);
$words = strtolower(json_encode(array_merge(array_column($ins['items'], 'body'), array_column($cor['details'], 'body')), JSON_UNESCAPED_UNICODE));
check('no sentence names a cause', !preg_match('/omdat|doordat|daardoor|zorgt ervoor|veroorzaa|komt door/', $words));
$app = app_state_payload(app_page_data($data, $who));
check('the website and the app get the same', json_encode($app['correlations']) === json_encode(app_state_plain($web['correlations']))
    && json_encode($app['insights']) === json_encode(app_state_plain($web['insights'])));

section('By age');
$older = person('wim', '1955-02-02');
$GLOBALS['history_for'] = $older;
health_import_records($older, 'google_health_connect', history($now, 0, 8));
$keysFor = static function (int $uid) use ($data): array {
    $d = $data;
    $d['auth'] = app_auth_account($uid);
    $page = app_page_data($d, $uid);
    $ids = array_column($page['correlations']['details']['insight-C12']['research'] ?? [], 'research');

    return array_map(static fn ($id) => $page['research']['details'][$id]['claim_key'] ?? '?', $ids);
};
$young = $keysFor($who);
$old71 = $keysFor($older);
check('34: protein on training days with the sport advice, never the 65+ one (' . implode(', ', $young) . ')',
    !in_array('nutrition.protein.older', $young, true));
check('71: the 65+ advice, never the 18–64 sport advice (' . implode(', ', $old71) . ')',
    in_array('nutrition.protein.older', $old71, true) && !in_array('nutrition.protein.exercise', $old71, true));

section('In English');
i18n_force('en');
$en = app_page_data($data, $who);
$text = json_encode([array_column($en['insights']['items'], 'body'), array_column($en['correlations']['details'], 'body'),
    array_column($en['correlations']['details'], 'numbers')], JSON_UNESCAPED_UNICODE);
preg_match_all('/\b(nodig|dagen|minuten|nachten|gemiddeld|tegenover)\b/', $text, $dutch);
preg_match_all('/.{0,80}\b(?:nodig|dagen|minuten|nachten|gemiddeld|tegenover)\b.{0,20}/u', $text, $where);
check('the same insights in English: no Dutch words left', $dutch[0] === [], implode(' | ', $where[0]));
check('… with English numbers (a decimal point)', !preg_match('/\d,\d{2}\b/', $text) || preg_match('/\d{1,3},\d{3}/', $text));
check('… and the label in English', in_array($en['insights']['items'][0]['tier']['label'] ?? '', ['Clear pattern', 'Pattern', 'Still unclear', 'Possible pattern', 'No pattern'], true));
i18n_force('nl');

section('Deleting an account');
db_run('DELETE FROM users WHERE id = ?', [$few]);
check('its runs and results go with it', (int) db_value('SELECT COUNT(*) FROM correlation_runs WHERE user_id = ?', [$few]) === 0
    && (int) db_value('SELECT COUNT(*) FROM user_correlations WHERE user_id = ?', [$few]) === 0);

section('Without migration 029');
$server->exec("CREATE DATABASE `$old` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
[$code] = $import($old, $root . '/database/schema.sql');
$server->exec("DROP TABLE `$old`.`user_correlations`");
$server->exec("DROP TABLE `$old`.`correlation_runs`");
$probe = <<<'PHP'
date_default_timezone_set('Europe/Amsterdam');
require 'includes/bootstrap.php'; require 'includes/insights.php'; require 'lib/app-data.php';
db_run('INSERT INTO users (username) VALUES (?)', ['oud']);
$id = (int) db_insert_id();
$d = require 'config/dashboard.php'; $d['auth'] = app_auth_account($id);
$p = app_page_data($d, $id);
echo json_encode(['installed' => corr_installed(), 'combined' => $p['insights']['combined'] ?? false, 'rows' => count($p['insights']['items']), 'cor' => isset($p['correlations'])]);
PHP;
$cmd = sprintf('cd %s && DB_NAME=%s php -r %s 2>/dev/null', escapeshellarg($root), escapeshellarg($old), escapeshellarg($probe));
$raw = (string) shell_exec($cmd);
$res = json_decode(substr($raw, (int) strrpos($raw, '{"installed"')), true);
check('the Overzicht keeps its 30.1 card: three area rows, no Inzichten', $code === 0 && is_array($res) && $res['installed'] === false
    && $res['combined'] === false && $res['rows'] === 3 && $res['cor'] === false, $res === null ? substr($raw, 0, 400) : json_encode($res));

} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

printf("\n%d passed, %d failed\n", $pass_, $fail_);
exit($fail_ === 0 ? 0 : 1);

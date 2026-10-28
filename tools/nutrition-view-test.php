<?php
/**
 * Voeding, drawn (lib/hydrate-nutrition.php, docs/NUTRITION.md) — tested on
 * the database.
 *
 *     php tools/nutrition-view-test.php
 *
 * Real Health Connect meals and water go into a throwaway account through
 * the import the phone uses, and this asks what the Voeding page is given:
 *
 *   1. the seven days: today first, their names, each day's cijfer
 *   2. a day's composition: the energy from carbohydrates, protein and fat
 *      (4, 4, 9 kcal per gram), each share, range and verdict, the meter;
 *      fibre and sugar under carbohydrates, saturated fat under fat
 *   3. a meal two apps wrote at the same minute counts once
 *   4. nothing invented: a nutrient no app logged is "niet gelogd" (never
 *      0 g), a day with only some macronutrients has no doughnut, a day with
 *      none says so
 *   5. the targets: protein against the profile's goal, the energy target
 *      in the ring's middle; without a profile, neither
 *   6. Energie and Water: the bereik as a zone, the target ("van …"), kJ
 *      on the right of the energy chart, the reading's verdict
 *   7. every reference value from config/nutrition-reference.php
 *
 * The accounts are named nutritionview_… and deleted at the end. Exit code
 * 0 when every check passes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'nl';

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-import.php';
require_once dirname(__DIR__) . '/includes/health-connect-map.php';
require_once dirname(__DIR__) . '/lib/hydrate-nutrition.php';

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

$today = date('Y-m-d');
$day   = static fn (int $back): string => (new DateTimeImmutable($today))->modify("-{$back} days")->format('Y-m-d');

$users = [];
$account = static function (bool $profile) use (&$users): int {
    db_run('INSERT INTO users (username) VALUES (?)', ['nutritionview_' . bin2hex(random_bytes(4))]);
    $id = (int) db_insert_id();
    db_run('UPDATE users SET created_at = NOW() - INTERVAL 60 DAY WHERE id = ?', [$id]);
    if ($profile) {
        db_run(
            "INSERT INTO user_profiles (user_id, date_of_birth, gender, activity_level) VALUES (?, '1994-05-12', 'male', 'moderate')
             ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), activity_level = VALUES(activity_level)",
            [$id]
        );
        foreach ([['height', 182, 'cm'], ['weight', 72, 'kg']] as [$type, $value, $unit]) {
            db_run(
                "INSERT INTO user_measurements (user_id, measurement_type, value, unit, source_id, external_id, measured_at, created_at)
                 SELECT ?, ?, ?, ?, s.id, ?, NOW() - INTERVAL 30 DAY, NOW() FROM data_sources s WHERE s.code = 'manual'",
                [$id, $type, $value, $unit, 'nutritionview-' . $type]
            );
        }
    }
    $users[] = $id;

    return $id;
};

register_shutdown_function(static function () use (&$users): void {
    foreach ($users as $id) {
        db_run('DELETE FROM point_events WHERE user_id = ?', [$id]);
        db_run('DELETE FROM users WHERE id = ?', [$id]);
    }
});

/** A meal as Health Connect hands it over: grams per nutrient, null left out. */
$meal = static function (string $id, string $date, string $time, array $g, string $origin = 'com.myfitnesspal.android'): array {
    $r = [
        'recordType' => 'Nutrition',
        'metadata'   => ['id' => $id, 'dataOrigin' => $origin],
        'startTime'  => "{$date}T{$time}:00Z",
        'endTime'    => "{$date}T{$time}:00Z",
    ];
    $kcal = 0.0;
    foreach (['carbs' => 'totalCarbohydrate', 'protein' => 'protein', 'fat' => 'totalFat', 'sat' => 'saturatedFat', 'fibre' => 'dietaryFiber', 'sugar' => 'sugar'] as $k => $field) {
        if (isset($g[$k])) {
            $r[$field] = ['grams' => $g[$k]];
        }
    }
    $kcal = ($g['carbs'] ?? 0) * 4 + ($g['protein'] ?? 0) * 4 + ($g['fat'] ?? 0) * 9;
    $r['energy'] = ['kilocalories' => $kcal];

    return $r;
};
$water = static fn (string $id, string $date, float $litres): array => [
    'recordType' => 'Hydration',
    'metadata'   => ['id' => $id, 'dataOrigin' => 'com.myfitnesspal.android'],
    'startTime'  => "{$date}T09:00:00Z",
    'endTime'    => "{$date}T09:05:00Z",
    'volume'     => ['liters' => $litres],
];
$import = static fn (int $user, array $records): array => health_import_records($user, 'google_health_connect', health_connect_map($records)['records']);

$area    = config_copy('health')['areas']['nutrition'];
$periods = config_copy('compass')['history']['periods'];
$ref     = nutrition_reference();

/* ---------------------------------------------------------------------- */
section('7. The reference values: one file');

check('4, 4 and 9 kcal per gram', $ref['kcal_per_gram'] === ['carbs' => 4.0, 'protein' => 4.0, 'fat' => 9.0]);
/* The 2026 norms (Gezondheidsraad 2026/14, NUT-01): carbohydrates have no
   percentage any more; fibre follows the day's own energy. */
check('carbohydrates: no percentage norm (2026), fat 20–40 %', $ref['macros']['carbs']['measure'] === 'none' && !isset($ref['macros']['carbs']['range'])
    && $ref['macros']['fat']['range'] === [20, 40]);
check('protein against your own goal', $ref['macros']['protein']['measure'] === 'target');
check('saturated fat under 10 % of the energy (the interim step), fibre 12,6–14,6 g per 1.000 kcal', $ref['details']['saturated_fat']['range'] === [0, 10]
    && $ref['details']['fibre']['measure'] === 'per_kcal' && $ref['details']['fibre']['range'] === [12.6, 14.6]);
check('sugar has no verdict', $ref['details']['sugar']['measure'] === 'none');
check('energy 90–110 % of the target, water 1,5–2,0 L, target 1,8 L', $ref['energy']['range'] === [0.9, 1.1] && $ref['water']['range'] === [1.5, 2.0] && (float) $ref['water']['target'] === 1.8);

/* ---------------------------------------------------------------------- */
$user = $account(true);
$import($user, [
    // Today: two meals and the same lunch from a second app.
    $meal('nv-l0', $day(0), '12:30', ['carbs' => 80, 'protein' => 40, 'fat' => 25, 'sat' => 10, 'fibre' => 9, 'sugar' => 30]),
    $meal('nv-d0', $day(0), '19:00', ['carbs' => 120, 'protein' => 50, 'fat' => 40, 'sat' => 12, 'fibre' => 13, 'sugar' => 40]),
    $meal('nv-l0-dup', $day(0), '12:30', ['carbs' => 80, 'protein' => 40, 'fat' => 25], 'com.sec.android.app.shealth'),
    $water('nv-w0', $day(0), 1.2),
    // Yesterday: carbohydrates and fat only.
    $meal('nv-l1', $day(1), '12:30', ['carbs' => 150, 'fat' => 50]),
    // Three days back: a full day, without fibre, sugar or saturated fat.
    $meal('nv-l3', $day(3), '12:30', ['carbs' => 300, 'protein' => 100, 'fat' => 60]),
    $water('nv-w3', $day(3), 2.4),
]);
// Yesterday's cijfer, as api/health/rating.php keeps it.
db_run(
    'INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at) VALUES (?, ?, ?, ?, ?, ?)',
    [$user, health_metric_type_id('nutrition_rating'), health_source_id('manual'), 'daily-rating:' . $day(1), 7, $day(1) . ' 20:00:00']
);

$view = hydrate_nutrition($area, $periods, $user, $today);
$days = $view['days'];

/* ---------------------------------------------------------------------- */
section('1. The seven days');

check('seven days, today first', count($days) === 7 && $days[0]['date'] === $today && $days[6]['date'] === $day(6));
check('Vandaag, Gisteren, Eergisteren, then the date', $days[0]['name'] === 'Vandaag' && $days[1]['name'] === 'Gisteren' && $days[2]['name'] === 'Eergisteren' && $days[3]['name'] !== '');
check('in a sentence: vandaag, gisteren', $days[0]['word'] === 'vandaag' && $days[1]['word'] === 'gisteren');
check("each day's own cijfer: 7 yesterday, none today", $days[1]['rating'] === 7 && $days[0]['rating'] === null);
check('the slider copy', $view['rating']['title'] === 'Hoe at je?' && str_contains($view['rating']['question'], '%s'));

/* ---------------------------------------------------------------------- */
section('2–3. A day: its composition, counted once');

$t     = $days[0];
$macro = static fn (array $d, string $k): array => array_values(array_filter($d['macros'], static fn ($m) => $m['key'] === $k))[0];
$carbs = $macro($t, 'carbs');
$prot  = $macro($t, 'protein');
$fat   = $macro($t, 'fat');

check('the lunch two apps wrote counts once: 200 g carbohydrates', abs((float) $carbs['grams'] - 200) < 0.01, (string) $carbs['grams']);
check('energy from the three: 200×4 + 90×4 + 65×9 = 1.745 kcal', $t['kcal'] == 1745, (string) $t['kcal']);
check('carbohydrates 45,8 % of it, Geen oordeel: no norm to judge it by', abs($carbs['share'] - 45.8) < 0.05 && $carbs['verdict'] === 'none' && $carbs['verdict_text'] === 'Geen oordeel');
check('fat 33,5 %, Binnen bereik', abs($fat['share'] - 33.5) < 0.05 && $fat['verdict'] === 'in');
check('the head in grams', $carbs['head'] === '200 g' && $fat['head'] === '65 g');
check('the sub line: the share alone, no guideline', $carbs['sub'] === ['46 % van je energie'], json_encode($carbs['sub'], JSON_UNESCAPED_UNICODE));
check('the meter: no band, the share its marker', $carbs['meter']['low'] === null && $carbs['meter']['high'] === null && abs($carbs['meter']['value'] - 45.85) < 0.05);
check('Richtlijn says there is no percentage', ($carbs['facts'][2]['value'] ?? null) === 'Geen percentage');
check('fat keeps its sub line and band', $fat['sub'] === ['34 % van je energie', 'richtlijn 20–40 %'] && $fat['meter']['low'] === 20 && $fat['meter']['high'] === 40,
    json_encode($fat['sub'], JSON_UNESCAPED_UNICODE));
check('what opens: Gegeten, Deel van je energie, Richtlijn', array_column($carbs['facts'], 'label') === ['Gegeten', 'Deel van je energie', 'Richtlijn']);
check('Gegeten in grams and kcal', $carbs['facts'][0]['value'] === '200 g · 800 kcal');

$details = array_column($carbs['details'], null, 'key');
check('fibre and sugar under carbohydrates', array_keys($details) === ['fibre', 'sugar']);
check('fibre 22 g of 1.745 kcal: 12,6 g per 1.000 kcal, Binnen bereik', $details['fibre']['value'] === '22 g'
    && $details['fibre']['extra'] === '12,6 g per 1.000 kcal' && $details['fibre']['verdict'] === 'in', json_encode($details['fibre'], JSON_UNESCAPED_UNICODE));
$fibre = static fn (?float $grams, ?float $kcal): array => hydrate_nutrition_details(['details' => ['fibre' => 'Vezels', 'sugar' => 'Suiker']], $ref, 'carbs',
    ['fibre' => $grams, 'sugar' => null], $kcal)[0];
check('…10 g of 2.000 kcal (5 per 1.000): Onder bereik', $fibre(10, 2000)['verdict'] === 'low' && $fibre(10, 2000)['extra'] === '5 g per 1.000 kcal');
check('…40 g of 2.000 kcal (20 per 1.000): Boven bereik', $fibre(40, 2000)['verdict'] === 'high');
check('…28 g of 2.000 kcal (14 per 1.000): Binnen bereik', $fibre(28, 2000)['verdict'] === 'in');
check('…without the day\'s energy: its grams, no verdict', $fibre(20, null)['value'] === '20 g' && $fibre(20, null)['verdict'] === null && $fibre(20, null)['extra'] === null);
check('sugar: Geen oordeel', $details['sugar']['verdict'] === 'none' && $details['sugar']['verdict_text'] === 'Geen oordeel');
$sat = $fat['details'][0];
check('saturated fat under fat: 22 g, 11,3 % of the energy, Boven bereik', $sat['key'] === 'saturated_fat' && $sat['value'] === '22 g' && $sat['extra'] === '11,3 %' && $sat['verdict'] === 'high');
check('no Gezondheidsraad line, no "(van koolhydraten…)"', !str_contains(json_encode($view, JSON_UNESCAPED_UNICODE), 'Gezondheidsraad') && !str_contains(json_encode($view, JSON_UNESCAPED_UNICODE), 'samen)'));

/* ---------------------------------------------------------------------- */
section('4. Nothing invented');

$y = $days[1];
check('yesterday, without protein: no doughnut', $y['kcal'] === null && $y['centre'] === null);
check('…and says why', is_string($y['note']) && str_contains($y['note'], 'gisteren'), (string) $y['note']);
check('protein not logged: "niet gelogd", never 0 g', $macro($y, 'protein')['head'] === 'niet gelogd' && $macro($y, 'protein')['grams'] === null && $macro($y, 'protein')['logged'] === false);
check('carbohydrates logged still show their grams', $macro($y, 'carbs')['head'] === '150 g' && $macro($y, 'carbs')['share'] === null);
check('a day without anything: Nog niets gelogd', $days[2]['kcal'] === null && str_starts_with((string) $days[2]['note'], 'Nog niets gelogd'));
$d3 = $days[3];
check('fibre no app logged: niet gelogd, no verdict', $macro($d3, 'carbs')['details'][0]['value'] === null && $macro($d3, 'carbs')['details'][0]['verdict'] === null && $macro($d3, 'carbs')['details'][0]['verdict_text'] === 'niet gelogd');
check('carbohydrates 3 days back: 300×4 / 2.140 = 56,1 %', abs($macro($d3, 'carbs')['share'] - 56.1) < 0.05);

/* ---------------------------------------------------------------------- */
section('5. The targets');

check('protein against the profile\'s goal: % van je doel', count($prot['sub']) === 2 && str_ends_with($prot['sub'][1], '% van je doel'));
check('the why line names g per kg', is_string($prot['why']) && str_contains($prot['why'], 'g per kg'));
check('the ring\'s middle: kcal and % of the target', $t['centre']['value'] === '1.745' && preg_match('/^kcal · \d+ % van [\d.]+$/', $t['centre']['sub']) === 1, (string) $t['centre']['sub']);

$bare     = $account(false);
$import($bare, [$meal('nv-b0', $today, '12:30', ['carbs' => 100, 'protein' => 50, 'fat' => 30])]);
$bareView = hydrate_nutrition($area, $periods, $bare, $today);
$bp       = $macro($bareView['days'][0], 'protein');
check('without a profile: no protein goal, no why, no meter', $bp['why'] === null && $bp['meter'] === null && $bp['sub'] === [$bp['sub'][0]]);
check('…the middle just kcal', $bareView['days'][0]['centre']['sub'] === 'kcal');
check('…and no energy bereik or target', ($bareView['charts'][0]['periods'][0]['band'] ?? null) === null && !isset($bareView['charts'][0]['latest']['of']));

/* ---------------------------------------------------------------------- */
section('6. Energie and Water');

$charts = array_column($view['charts'], null, 'id');
check('two charts: Energie and Water', array_keys($charts) === ['energy', 'water']);
$e = $charts['energy'];
$w = $charts['water'];
check('energy: today\'s value, its target, kJ under it', $e['latest']['texts'][0] === '1.745' && str_starts_with((string) $e['latest']['of'], 'van ') && $e['latest']['sub'] === '7.301 kJ', json_encode($e['latest'], JSON_UNESCAPED_UNICODE));
check('energy: kcal on the left, kJ on the right', ($e['units'] ?? null) === ['kcal', 'kJ'] && count($e['periods'][0]['grid_alt']) === count($e['periods'][0]['grid']));
check('energy: the bereik as a zone', is_array($e['periods'][0]['band']) && $e['periods'][0]['band']['top'] < $e['periods'][0]['band']['bottom']);
check('water: 1,2 L van 1,8 L, 1.200 ml', $w['latest']['texts'][0] === '1,2 L' && $w['latest']['of'] === 'van 1,8 L' && $w['latest']['sub'] === '1.200 ml', json_encode($w['latest'], JSON_UNESCAPED_UNICODE));
check('water: the bereik as a zone, no second scale', is_array($w['periods'][0]['band']) && $w['periods'][0]['grid_alt'] === [] && !isset($w['units']));
$last = end($w['periods'][0]['points']);
check("today's reading says Onder bereik (1,2 L under 1,5)", $last[1] === 'Onder bereik', json_encode($last, JSON_UNESCAPED_UNICODE));
$reading = end($e['periods'][0]['points']);
check('the energy reading in kcal and kJ', str_contains((string) end($reading), 'kcal · ') && str_ends_with((string) end($reading), 'kJ'), json_encode($reading, JSON_UNESCAPED_UNICODE));
check('every period: 7 dagen to 1 jaar', array_column($e['periods'], 'label') === array_column($periods, 'label'));
check('a partial day (protein missing) has no meter for its shares', $macro($y, 'carbs')['meter'] === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

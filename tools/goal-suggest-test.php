<?php
/**
 * Voorgesteld (docs/GOALS.md › Suggestions): the plan, without a database.
 * Made-up people, each a handful of facts, against the approved research as
 * config/research.php has it.
 *
 *   php tools/goal-suggest-test.php
 *
 * Covers: personal targets and their research levels (steps, sleep,
 * training, water, protein, fibre, salt), what happens without data or
 * without an age, age bands (young, adult, 65+), never 10.000 steps as a
 * minimum, not just "a little more than now", nothing that duplicates a
 * running goal, earlier goals, combined insights, something kept, the
 * catalogue both apps read, and English.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/includes/i18n.php';
require $root . '/includes/goal-suggest.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

/* The approved claims as the database would give them (research_claims()). */
$research = (array) require $root . '/config/research.php';
$claims = [];
foreach ($research['claims'] as $key => $claim) {
    $claims[$key] = ['claim_key' => $key] + $claim;
}

$catalogue = (array) require $root . '/config/goals.php';
$measurements = [];
foreach ($catalogue['measurements'] as $code => $m) {
    $measurements[$code] = ['code' => $code] + $m;
}

/** A person: nothing known unless said. */
function person(array $over = []): array
{
    $base = [
        'age' => 34, 'gender' => 'undisclosed', 'weight_kg' => null, 'energy_target' => null, 'imperial' => false,
        'focus' => 'general', 'daily' => [],
        'workouts' => ['recorded' => 0, 'per_week' => 0.0, 'minutes_week' => 0.0, 'hard_week' => 0.0, 'session_min' => null, 'strength_week' => 0.0],
        'nutrition' => ['complete_days' => 0],
        'goals' => ['active' => [], 'history' => []],
        'patterns' => [],
    ];

    return array_replace_recursive($base, $over);
}

function days(float $avg, int $days = 20, ?float $p90 = null): array
{
    return ['days' => $days, 'avg' => $avg, 'p90' => $p90 ?? $avg * 1.2, 'last' => $avg];
}

function plan(array $person): array
{
    global $measurements, $claims;

    return goal_suggest_plan($person, $measurements, $claims);
}

function entry(array $person, string $code): ?array
{
    return plan($person)['entries'][$code] ?? null;
}

i18n_force('nl');

/* ================================================================ steps */
section('Steps: research levels, not "a little more"');

$e = entry(person(['daily' => ['steps' => days(5000)]]), 'steps');
check('5.000 a day at 34: 7.000 (Ding), not 6.000', ($e['targets']['streak'] ?? null) == 7000.0, json_encode($e['targets'] ?? null));
check('… worked out from their own data and the research', $e['basis'] === 'personal' && $e['claim'] === 'activity.steps.ding');
check('… its sentence names their average and the research', str_contains($e['why'], '5.000') && str_contains($e['why'], '7.000'), $e['why']);
check('… and a reason for the list', $e['reason'] === 'Nu 5.000 · optimaal 7.000 stappen', $e['reason']);

$e = entry(person(['daily' => ['steps' => days(2500)]]), 'steps');
check('2.500 a day: a big step towards 7.000 (5.500), and said to be a step', $e['targets']['streak'] == 5500.0 && str_contains($e['why'], 'stap die kant op'), json_encode($e['targets']));

$e = entry(person(['daily' => ['steps' => days(6800)]]), 'steps');
check('6.800 a day: never only 10% more — 7.500, the level', $e['targets']['streak'] >= 7000.0, json_encode($e['targets']));

$e = entry(person(['daily' => ['steps' => days(7400)]]), 'steps');
check('7.400 a day at 34: the start of Paluch\'s plateau, 8.000', $e['targets']['streak'] == 8000.0 && $e['state'] === 'toward');

$e = entry(person(['daily' => ['steps' => days(9000)]]), 'steps');
check('9.000 a day: 10.000, said to be ambitious and no minimum', $e['targets']['streak'] == 10000.0 && $e['state'] === 'stretch' && str_contains($e['why'], 'geen ondergrens'), $e['why']);

$e = entry(person(['daily' => ['steps' => days(12300)]]), 'steps');
check('12.300 a day: already good — still a proposal just above it (13.000), never "Gaat al goed"', $e['targets']['streak'] == 13000.0 && $e['state'] === 'keep' && $e['reason'] === 'Nu 12.300 · optimaal 13.000 stappen', $e['reason']);

$e = entry(person(['age' => 70, 'daily' => ['steps' => days(6500)]]), 'steps');
check('70 and 6.500 a day: the 60+ plateau tops at 8.000, not 10.000', $e['targets']['streak'] == 8000.0, json_encode($e['targets']));

$e = entry(person(['age' => 70, 'daily' => ['steps' => days(8500)]]), 'steps');
check('70 and 8.500 a day: already beyond the 60+ plateau — keep', $e['state'] === 'keep');

$e = entry(person(), 'steps');
check('No steps at 34: the research level 7.000, as general advice', $e['targets']['streak'] == 7000.0 && $e['basis'] === 'general' && str_contains($e['why'], 'nog niet op jou afgestemd'), $e['why']);

$e = entry(person(['age' => null]), 'steps');
check('No steps and no age: nothing (no research applies, nothing is made up)', $e === null);

$e = entry(person(['age' => null, 'daily' => ['steps' => days(6000)]]), 'steps');
check('No age, their own steps: from their own average only, no claim', $e['basis'] === 'personal' && $e['claim'] === null && $e['score'] === 0, json_encode($e));

$e = entry(person(['age' => 15]), 'steps');
check('15: Ding and Paluch are for adults — no steps advice', $e === null);

/* ================================================================ sleep */
section('Sleep: the band for the age');

$e = entry(person(['daily' => ['sleep_duration' => days(360)]]), 'sleep_duration');
check('6:00 a night at 34: 7 hours (the adult minimum)', $e['targets']['streak'] == 420.0 && $e['claim'] === 'sleep.duration.adults', json_encode($e['targets']));

$e = entry(person(['daily' => ['sleep_duration' => days(300)]]), 'sleep_duration');
check('5:00 a night: 6:30, said to be a first step towards 7', $e['targets']['streak'] == 390.0 && str_contains($e['why'], 'eerste stap'), $e['why']);

$e = entry(person(['age' => 15, 'daily' => ['sleep_duration' => days(420)]]), 'sleep_duration');
check('15 with 7 hours: 8 hours, the band for 13–17', $e['targets']['streak'] == 480.0, json_encode($e['targets']));

$e = entry(person(['age' => 70, 'daily' => ['sleep_duration' => days(450)]]), 'sleep_duration');
check('70 with 7:30: within the band — keep', $e['state'] === 'keep' && $e['targets']['streak'] >= 420.0);
check('… and still a proposal: now and optimal, above their average', $e['targets']['streak'] > 450.0 && $e['reason'] === 'Nu 7:30 · optimaal 7:45', $e['reason']);

$e = entry(person(['daily' => ['sleep_duration' => days(600)]]), 'sleep_duration');
check('10 hours a night at 34: nothing to grow, nothing to cut — no suggestion', $e === null || $e['score'] === 0);

$e = entry(person(['daily' => ['sleep_duration' => days(360, 3)]]), 'sleep_duration');
check('3 nights are not an average: general advice', $e['basis'] === 'general');

/* ============================================================= training */
section('Training: the movement guideline');

$e = entry(person(['workouts' => ['recorded' => 6, 'per_week' => 1.0, 'minutes_week' => 45.0, 'session_min' => 45.0]]), 'workouts');
check('Once a week, 45 minutes: 4 times (150 minutes with their own sessions)', $e['targets']['period'] === 4 && $e['primary'] === 'period', json_encode($e['targets']));

$e = entry(person(['workouts' => ['recorded' => 10, 'per_week' => 2.0, 'minutes_week' => 120.0, 'hard_week' => 40.0, 'session_min' => 60.0]]), 'workouts');
check('2× 60 minutes with 40 hard: WHO counts hard double (160) — past 150, a stretch', $e['state'] === 'stretch', $e['state']);

$e = entry(person(['age' => 70, 'workouts' => ['recorded' => 6, 'per_week' => 1.0, 'minutes_week' => 60.0, 'session_min' => 60.0]]), 'workouts');
check('70: at least 3 times, and balance and strength named', $e['targets']['period'] >= 3 && str_contains($e['why'], 'balans en kracht'), $e['why']);

$e = entry(person(), 'workouts');
check('No workouts at 34: 3 times a week as general advice', $e['targets']['period'] === 3 && $e['basis'] === 'general');

$e = entry(person(['age' => 15]), 'workout_minutes');
check('15: 60 minutes every day (Beweegrichtlijnen youth)', $e['targets']['streak'] == 60.0 && $e['primary'] === 'streak', json_encode($e['targets']));

/* ============================================================ nutrition */
section('Water, protein, fibre, salt');

check('Water, a man, no data: 1,8 liter', entry(person(['gender' => 'male']), 'water')['targets']['streak'] == 1.8);
check('Water, a woman: 1,5 liter (at least 1,4)', entry(person(['gender' => 'female']), 'water')['targets']['streak'] == 1.5);
check('Water, a man who trains: 2,0 liter', entry(person(['gender' => 'male', 'workouts' => ['per_week' => 3.0]]), 'water')['targets']['streak'] == 2.0);
$e = entry(person(['gender' => 'female', 'daily' => ['water' => days(0.8)]]), 'water');
check('Water, 0,8 liter a day: 1,4 — a big step, not 0,9', abs($e['targets']['streak'] - 1.4) < 1e-9, json_encode($e['targets']));
check('Water at 10: no research for this age, nothing made up', entry(person(['age' => 10]), 'water') === null);

$e = entry(person(['weight_kg' => 70.0]), 'protein');
check('Protein, 70 kg, no training: 0,83 g/kg → 60 g', $e['targets']['streak'] == 60.0 && $e['claim'] === 'nutrition.protein', json_encode($e));
$e = entry(person(['weight_kg' => 70.0, 'workouts' => ['per_week' => 3.0]]), 'protein');
check('Protein, 70 kg, trains 3× a week: 1,4 g/kg → 100 g, and said to be limited research', $e['targets']['streak'] == 100.0 && str_contains($e['why'], 'beperkt'), $e['why']);
$e = entry(person(['age' => 72, 'weight_kg' => 80.0]), 'protein');
check('Protein at 72, 80 kg: the advice for older adults, 1,0 g/kg → 80 g', $e['targets']['streak'] == 80.0 && $e['claim'] === 'nutrition.protein.older');
check('Protein without a weight: no research target (it needs one)', entry(person(), 'protein') === null);
$e = entry(person(['weight_kg' => 70.0, 'nutrition' => ['protein' => ['days' => 2, 'avg' => 40.0]]]), 'protein');
check('Two logged days are not an average: general', $e['basis'] === 'general');

$e = entry(person(['energy_target' => 2400.0, 'nutrition' => ['fibre' => ['days' => 6, 'avg' => 18.0]]]), 'fibre');
check('Fibre, 18 g a day at 2.400 kcal: towards 30 g (12,6 per 1.000 kcal)', $e['targets']['streak'] >= 20.0 && $e['targets']['streak'] <= 30.0 && str_contains($e['why'], '30 g'), $e['why']);
check('Fibre without an energy target: no research target', entry(person(), 'fibre') === null);

$e = entry(person(['nutrition' => ['sodium' => ['days' => 5, 'avg' => 3500.0]]]), 'sodium');
check('Sodium 3.500 mg: at most 2.800, on the way to 2.400 (6 g salt)', $e['direction'] === 'decrease' && $e['targets']['streak'] <= 2900.0 && $e['targets']['streak'] >= 2400.0, json_encode($e['targets']));

$e = entry(person(['energy_target' => 2000.0]), 'sugar');
check('Sugar: a hint from the free-sugars norm, never suggested', $e['score'] === 0 && str_contains($e['why'], 'alle suikers'));

/* ================================================================ order */
section('Voorgesteld: the order');

$busy = person([
    'gender' => 'male', 'weight_kg' => 82.0, 'energy_target' => 2600.0,
    'daily' => ['steps' => days(4200), 'sleep_duration' => days(465), 'water' => days(1.2), 'distance' => days(3.0)],
    'workouts' => ['recorded' => 8, 'per_week' => 1.0, 'minutes_week' => 40.0, 'session_min' => 40.0],
    'nutrition' => ['complete_days' => 6, 'protein' => ['days' => 6, 'avg' => 70.0], 'fibre' => ['days' => 6, 'avg' => 20.0]],
]);
$p = plan($busy);
check('Growth first: steps and training lead the list', array_slice($p['order'], 0, 2) == ['steps', 'workouts'] || array_slice($p['order'], 0, 2) == ['workouts', 'steps'], implode(', ', $p['order']));
check('… and something they already do well is in it (sleep, to keep)', in_array('sleep_duration', $p['order'], true) && $p['entries']['sleep_duration']['state'] === 'keep', implode(', ', $p['order']));
check('… at most 6, at most 2 per category', count($p['order']) <= 6 && max(array_count_values(array_map(static fn ($c) => $p['entries'][$c]['category'] ?? 'x', $p['order']))) <= 2, implode(', ', $p['order']));
check('… one of steps / distance / active energy / floors', count(array_intersect($p['order'], ['steps', 'distance', 'active_energy', 'floors'])) === 1);

$withGoal = $busy;
$withGoal['goals']['active'] = ['steps', 'workouts'];
$p = plan($withGoal);
check('A running steps goal: no steps, distance or floors in Voorgesteld', array_intersect($p['order'], ['steps', 'distance', 'active_energy', 'floors']) === [], implode(', ', $p['order']));
check('A running training goal: nor training minutes', !in_array('workout_minutes', $p['order'], true) && !in_array('workouts', $p['order'], true));

$p = plan(person(['age' => null]));
check('Nothing known at all: the general order, nothing claimed', $p['order'] === ['sleep_duration', 'steps', 'workouts', 'water'] && $p['entries'] === [], implode(', ', $p['order']) . ' / ' . implode(',', array_keys($p['entries'])));

/* The general order fills up, but never with a third of one category (32.1). */
$fake = static fn (string $code, string $cat, int $score): array => ['code' => $code, 'category' => $cat, 'score' => $score, 'state' => 'grow'];
$order = goal_suggest_order(['protein' => $fake('protein', 'nutrition', 50), 'fibre' => $fake('fibre', 'nutrition', 40)], ['goals' => ['active' => []]], goal_suggest_config());
check('Two food suggestions: the general order adds no water (never 3 of one category)', !in_array('water', $order, true) && count(array_intersect($order, ['protein', 'fibre', 'water'])) === 2, implode(', ', $order));

/* Never "Gaat al goed", never "hoogstens": a proposal, said as now and optimal or at most. */
$everyone = [$busy, person(['age' => 70, 'daily' => ['sleep_duration' => days(450), 'steps' => days(8500)]]), person(['daily' => ['steps' => days(12300)]])];
$said = [];
foreach ($everyone as $who) {
    $planned = plan($who);
    foreach ($planned['order'] as $code) {
        if (isset($planned['entries'][$code])) {
            $said[] = $code . ': ' . $planned['entries'][$code]['reason'];
        }
    }
}
check('Every reason is a proposal: optimaal or maximaal, never "Gaat al goed" or "hoogstens"',
    $said !== [] && array_filter($said, static fn ($r) => !preg_match('/optimaal|maximaal|Optimaal|Maximaal/u', $r) || preg_match('/Gaat al goed|hoogstens/u', $r)) === [], implode(' | ', $said));

$plain = plan(person(['daily' => ['sleep_duration' => days(380)], 'workouts' => ['recorded' => 6, 'per_week' => 1.0, 'minutes_week' => 50.0, 'session_min' => 50.0]]));
$linked = plan(person(['daily' => ['sleep_duration' => days(380)], 'workouts' => ['recorded' => 6, 'per_week' => 1.0, 'minutes_week' => 50.0, 'session_min' => 50.0], 'patterns' => ['C01' => 'clear']]));
check('A clear pattern between training and sleep (C01) makes both more relevant',
    $linked['entries']['workouts']['score'] > $plain['entries']['workouts']['score'] && $linked['entries']['sleep_duration']['score'] > $plain['entries']['sleep_duration']['score']);

$fit = plan(person(['focus' => 'fitness']));
check('Focus Fitheid moves training and activity up', $fit['entries']['workouts']['score'] > entry(person(), 'workouts')['score']);

/* ============================================================== history */
section('Earlier goals');

$reached = person(['daily' => ['steps' => days(7400)], 'goals' => ['history' => ['steps' => ['streak_daily' => ['target' => 9000.0, 'direction' => 'increase', 'pct' => 100.0]]]]]);
$e = entry($reached, 'steps');
check('A 9.000-steps goal they reached: never asked again for less (9.000, not 8.000)', $e['targets']['streak'] == 9000.0 && str_contains($e['why'], 'haalde je'), $e['why']);

$short = person(['daily' => ['steps' => days(9200)], 'goals' => ['history' => ['steps' => ['streak_daily' => ['target' => 9500.0, 'direction' => 'increase', 'pct' => 40.0]]]]]);
$e = entry($short, 'steps');
check('A 9.500 goal reached for 40%: no higher this time (9.500, not 10.000)', $e['targets']['streak'] == 9500.0 && str_contains($e['why'], '40%'), $e['why']);

/* ============================================================ catalogue */
section('The catalogue both apps read');

$goals = config_copy('goals');
$applied = goal_suggest_apply($goals, plan($busy));
$m = $applied['measurements'];
check('No measurement says popular any more', array_filter($m, static fn ($x) => array_key_exists('popular', $x)) === []);
check('Voorgesteld ranks 1… in the plan\'s order', $m[plan($busy)['order'][0]]['suggested'] === 1);
check('Steps\' placeholder is the suggestion (7.000), in steps', $m['steps']['placeholder']['streak'] == 7000.0, json_encode($m['steps']['placeholder']));
check('Sleep\'s placeholder is in hours, the first unit (8 for 7:45 a night)', $m['sleep_duration']['placeholder']['streak'] == 8.0, json_encode($m['sleep_duration']['placeholder']));
check('Training per week: its placeholder is a count, its card says so', $m['workouts']['placeholder']['period'] == 4.0 && $m['workouts']['examples']['streak'] === '4 keer per week', json_encode($m['workouts']['examples'], JSON_UNESCAPED_UNICODE));
check('The first name idea is the suggestion', $m['steps']['names'][0] === 'Elke dag minstens 7.000 stappen', json_encode($m['steps']['names'], JSON_UNESCAPED_UNICODE));
check('Under the target: what is suggested, and why', str_starts_with($m['steps']['suggestion']['notes']['streak'], 'Voorgesteld: elke dag minstens 7.000 stappen. Je zette'), $m['steps']['suggestion']['notes']['streak']);
check('A Mijlpaal has its own note', isset($m['steps']['suggestion']['notes']['milestone']));
check('Not in Voorgesteld: no reason in the list, the notes stay', $m['distance']['suggested'] === null && ($m['distance']['suggestion']['reason'] ?? '') === '');
check('Body: no target of Ownify\'s own, and it says so', !isset($m['weight']['suggestion']) || !isset($m['weight']['placeholder']['milestone']) || $m['weight']['placeholder']['milestone'] == $goals['measurements']['weight']['placeholder']['milestone']);
check('Sodium: the direction is lower, the card says at most', $m['sodium']['default_direction'] === 'decrease');
$imperial = goal_suggest_apply($goals, plan(person(['gender' => 'male', 'imperial' => true])), true);
check('Imperiaal: water\'s example in fl oz, its placeholder still in the first unit (l)',
    str_contains($imperial['measurements']['water']['examples']['streak'], 'fl oz') && $imperial['measurements']['water']['placeholder']['streak'] == 1.8,
    json_encode([$imperial['measurements']['water']['examples'], $imperial['measurements']['water']['placeholder']], JSON_UNESCAPED_UNICODE));

/* =============================================================== English */
section('In English');

i18n_force('en');
$goals = config_copy('goals');
$p = plan($busy);
$en = goal_suggest_apply($goals, $p)['measurements'];
check('The list label', $goals['wizard']['suggested'] === 'Suggested');
check('A reason in English', $en['steps']['suggestion']['reason'] === 'Now 4,200 · optimal 7,000 steps', $en['steps']['suggestion']['reason']);
check('A note in English, with English numbers', str_starts_with($en['steps']['suggestion']['notes']['streak'], 'Suggested: every day at least 7,000 steps.'), $en['steps']['suggestion']['notes']['streak']);
check('Training per week in English', $en['workouts']['examples']['streak'] === '4 times per week', $en['workouts']['examples']['streak']);
$dutch = [];
foreach ($p['entries'] as $code => $entry) {
    foreach ([$entry['why'], $entry['reason']] as $text) {
        if (preg_match('/\b(je|jouw|dagen|stappen|minstens|voorstel|nachten|gemiddeld|onderzoek|liter per)\b/u', $text)) {
            $dutch[] = $code . ': ' . $text;
        }
    }
}
check('No Dutch left in any English sentence', $dutch === [], implode(' | ', $dutch));
i18n_force('nl');

/* ============================================================= database */
/* With DB_NAME (a development database, never a live one): the facts as a
   goal will read them, end to end through the wizard's catalogue. */
if ((string) getenv('DB_NAME') !== '') {
    require_once $root . '/includes/db.php';
    require_once $root . '/lib/render.php';
    require_once $root . '/lib/goals.php';
    require_once $root . '/lib/hydrate-goals.php';
    require_once $root . '/includes/goal-create.php';

    if (db_available() && goal_model_available()) {
        section('From the database: a person\'s own records');

        $uid = null;
        try {
            db_run('INSERT INTO users (username) VALUES (?)', ['gs_' . bin2hex(random_bytes(4))]);
            $uid = (int) db_insert_id();
            db_run('INSERT INTO user_profiles (user_id, date_of_birth, gender) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth), gender = VALUES(gender)', [$uid, '1990-05-01', 'male']);
            $today = goal_today();
            $metric = static fn (string $code): int => (int) db_value('SELECT id FROM health_metric_types WHERE code = ?', [$code]);

            /* New: nothing measured. General advice, said to be general. */
            $fresh = hydrate_goals(config_copy('goals'), $uid, $today)['measurements'];
            check('A new account: Voorgesteld is there, from research for its age', ($fresh['steps']['suggested'] ?? null) !== null
                && ($fresh['steps']['suggestion']['basis'] ?? null) === 'general' && $fresh['steps']['placeholder']['streak'] == 7000.0,
                json_encode($fresh['steps']['suggestion'] ?? null, JSON_UNESCAPED_UNICODE));
            check('… and says it is not tailored yet', str_contains($fresh['steps']['suggestion']['notes']['streak'] ?? '', 'nog niet op jou afgestemd'));

            /* Three weeks of 5.000 steps — and three days without a reading, which are not 0. */
            for ($i = 1; $i <= 24; $i++) {
                if ($i % 8 === 0) {
                    continue;
                }
                db_run('INSERT INTO health_metrics (user_id, metric_type_id, value, recorded_at) VALUES (?, ?, ?, ?)',
                    [$uid, $metric('steps'), 5000, $today->modify("-{$i} day")->format('Y-m-d') . ' 20:00:00']);
            }
            $m = hydrate_goals(config_copy('goals'), $uid, $today)['measurements'];
            check('5.000 steps a day, with gaps: 7.000 suggested, from their own data', $m['steps']['placeholder']['streak'] == 7000.0
                && $m['steps']['suggestion']['basis'] === 'personal', json_encode($m['steps']['suggestion'] ?? null, JSON_UNESCAPED_UNICODE));
            check('… the days without a reading are not zeros (average 5.000, not lower)', str_contains($m['steps']['suggestion']['reason'], 'Nu 5.000'),
                $m['steps']['suggestion']['reason']);
            check('… and steps lead Voorgesteld', $m['steps']['suggested'] === 1);

            /* A running steps goal: Voorgesteld leaves steps and their kin out. */
            $made = goal_create_from_input($uid, [
                'measurement' => 'steps', 'type' => 'streak', 'variant' => 'daily', 'name' => 'Stappen',
                'target_value' => '7000', 'unit' => 'steps', 'direction' => 'increase',
                'start_date' => $today->format('Y-m-d'), 'duration' => 'month',
            ], false, $today);
            $m = hydrate_goals(config_copy('goals'), $uid, $today)['measurements'];
            check('A running steps goal: no steps, distance or floors in Voorgesteld', $made['ok']
                && $m['steps']['suggested'] === null && $m['distance']['suggested'] === null && $m['floors']['suggested'] === null,
                json_encode([$made['error'] ?? null, $m['steps']['suggested'], $m['distance']['suggested']]));
            check('… but the wizard still has its target and note, for another kind of goal', isset($m['steps']['suggestion']['notes']['milestone']));
        } finally {
            if ($uid !== null) {
                db_run('DELETE FROM users WHERE id = ?', [$uid]);
            }
        }
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

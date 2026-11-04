<?php
/**
 * Research eligibility (38.0, config/research-eligibility.php,
 * docs/RESEARCH.md "Voor wie"): a claim is shown only to a person who
 * belongs to its population — age, sex, training status, health — and a
 * criterion Ownify cannot establish is not met. Also the Samenvatting
 * onderzoek points and a combined insight's progress.
 *
 *     php tools/research-eligibility-test.php
 *
 * No database: the claims come from config/research.php itself.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/research.php';
require_once $root . '/includes/correlations/engine.php';
require_once $root . '/includes/correlations/render.php';

$passed = $failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}
function section(string $name): void { echo "\n" . $name . "\n"; }

$registry = (array) require $root . '/config/research.php';
$config   = research_eligibility();
$claim    = static function (string $key) use ($registry): array {
    $c = $registry['claims'][$key];

    return ['claim_key' => $key, 'age_min' => $c['age_min'] ?? null, 'age_max' => $c['age_max'] ?? null];
};
/** A person, with health characteristics established where given. */
$person = static fn (?int $age, ?string $sex = null, ?string $training = null, array $health = []): array =>
    ['age' => $age, 'sex' => $sex, 'training' => $training, 'health' => $health];

section('1. The table itself');
$unknown = array_diff(array_keys((array) $config['claims']), array_keys((array) $registry['claims']));
check('every claim in the table is in the registry', $unknown === [], implode(', ', $unknown));
$bad = [];
foreach ((array) $config['claims'] as $key => $rule) {
    if (isset($rule['training']) && !array_key_exists($rule['training'], (array) $config['levels'])) { $bad[] = "$key training"; }
    foreach ((array) ($rule['health'] ?? []) as $h) { if (!in_array($h, (array) $config['health'], true)) { $bad[] = "$key health $h"; } }
    foreach ((array) ($rule['sex'] ?? []) as $x) { if (!in_array($x, ['female', 'male'], true)) { $bad[] = "$key sex $x"; } }
    foreach ((array) ($rule['ages'] ?? []) as $r) { if (!is_array($r) || count($r) !== 2) { $bad[] = "$key ages"; } }
}
check('every criterion is one Ownify knows how to check', $bad === [], implode(', ', $bad));
check('measurement and method claims have no person criteria', array_intersect(array_keys((array) $config['claims']),
    ['sleep.tracking', 'measurement.wearables', 'measurement.hrv', 'hrv.norms']) === []);

section('2. What Ownify knows about a person');
$p = research_person(['age' => 40, 'gender' => 'female', 'activity_level' => 'athlete']);
check('age, sex and training status come from the profile', $p['age'] === 40 && $p['sex'] === 'female' && $p['training'] === 'athlete');
check('health is never known: Ownify records none', $p['health'] === []);
foreach (['undisclosed', 'non_binary', 'other', null] as $g) {
    check('Geslacht "' . var_export($g, true) . '" is no known sex', research_person(['age' => 40, 'gender' => $g])['sex'] === null);
}
check('no Activiteitsniveau is no known training status', research_person(['age' => 40])['training'] === null);
check('an age alone is a person with only an age', research_person(33) === ['age' => 33, 'sex' => null, 'training' => null, 'health' => []]);

section('3. Age');
check('an age-bound claim is not shown without a birth date', !research_eligible($claim('sleep.duration.adults'), $person(null)));
check('… nor outside its ages', !research_eligible($claim('sleep.duration.adults'), $person(61)) && research_eligible($claim('sleep.duration.adults'), $person(60)));
check('a claim for everybody needs no age', research_eligible($claim('sleep.regularity'), $person(null)));
check('hrv.age: only the decades studied (25–74)', !research_eligible($claim('hrv.age'), $person(24, null, null, ['healthy' => true]))
    && research_eligible($claim('hrv.age'), $person(25, null, null, ['healthy' => true]))
    && !research_eligible($claim('hrv.age'), $person(75, null, null, ['healthy' => true])));

section('4. Sex');
$moore = $claim('nutrition.protein.meal.age');
$healthy = ['healthy' => true];
check('a men-only study: a man of 30 (were he known healthy)', research_eligible($moore, $person(30, 'male', null, $healthy)));
check('… not a woman', !research_eligible($moore, $person(30, 'female', null, $healthy)));
check('… not an unknown sex', !research_eligible($moore, $person(30, null, null, $healthy)));
check('… not 40 to 64, which it did not study', !research_eligible($moore, $person(50, 'male', null, $healthy))
    && research_eligible($moore, $person(70, 'male', null, $healthy)));

section('5. Training status');
$athletes = $claim('hrv.change');
check('athletes: Sporter yes, Actief no, unknown no', research_eligible($athletes, $person(30, null, 'athlete'))
    && !research_eligible($athletes, $person(30, null, 'active')) && !research_eligible($athletes, $person(30)));
check('elite athletes: never — Ownify does not ask', !research_eligible($claim('hrv.averaging'), $person(30, 'male', 'athlete')));
$sport = $claim('nutrition.protein.exercise');
check('"people who exercise": Actief or Sporter, and healthy', research_eligible($sport, $person(30, null, 'active', $healthy))
    && research_eligible($sport, $person(30, null, 'athlete', $healthy)) && !research_eligible($sport, $person(30, null, 'moderate', $healthy)));

section('6. Health');
check('NSF holds only without a sleep disorder: not shown to anyone today', !research_eligible($claim('sleep.duration.nsf'), $person(70, 'male', 'athlete')));
check('… and would be, once that is established', research_eligible($claim('sleep.duration.nsf'), $person(70, null, null, ['no_sleep_disorder' => true])));
check('CDC, by age only, holds from 65', research_eligible($claim('sleep.duration.cdc'), $person(70)));
check('PROT-AGE: not without knowing about severe kidney disease', !research_eligible($claim('nutrition.protein.older'), $person(70)));
check('the Dutch protein norm holds by age alone', research_eligible($claim('nutrition.protein'), $person(70)));
check('AASM adults: a scope by age, no health exclusion (verified)', research_eligible($claim('sleep.duration.adults'), $person(35)));

section('7. Samenvatting onderzoek: the findings as points');
$lossless = true;
$split = [];
foreach ($registry['sources'] as $code => $source) {
    $points = research_points((string) $source['findings']);
    if (implode(' ', $points) !== preg_replace('/\s+/u', ' ', trim((string) $source['findings']))) { $lossless = false; $split[] = $code; }
}
check('every source\'s findings, split, are its approved words — nothing left out or added', $lossless, implode(', ', $split));
check('one point per sentence', research_points('Een. Twee 7,7 minuten. Drie (r = −0,35).') === ['Een.', 'Twee 7,7 minuten.', 'Drie (r = −0,35).']);
check('numbers with a full stop stay whole: 18.712, 1.000', count(research_points('In 351 studies (18.712 mensen) en 1.000 kcal.')) === 1);

section('8. A combined insight still gathering: its bar');
$pair  = ['lag' => 'next', 'claims' => []];
$tiers = corr_config()['tiers']['pattern'];
check('nothing yet: an empty bar', corr_progress($pair, ['tier' => 'unclear', 'need' => ['kind' => 'days', 'count' => 30], 'n' => 0, 'n_high' => 0, 'n_low' => 0]) === 0.0);
$half = corr_progress($pair, ['tier' => 'unclear', 'need' => ['kind' => 'days', 'count' => 15], 'n' => (int) ($tiers['days'] / 2), 'n_high' => 20, 'n_low' => 20]);
check('half the days: half a bar', abs($half - round(((int) ($tiers['days'] / 2)) / $tiers['days'], 3)) < 0.001, (string) $half);
$group = corr_progress($pair, ['tier' => 'unclear', 'need' => ['kind' => 'group', 'count' => 2], 'n' => 60, 'n_high' => $tiers['group'] - 2, 'n_low' => 50]);
check('enough days but not in the smaller group: the group decides', abs($group - round(($tiers['group'] - 2) / $tiers['group'], 3)) < 0.001, (string) $group);
check('a result, or nothing missing: no bar', corr_progress($pair, ['tier' => 'unclear', 'need' => null, 'n' => 80]) === null
    && corr_progress($pair, ['tier' => 'clear', 'need' => null, 'n' => 80]) === null);

echo "\n" . $passed . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);

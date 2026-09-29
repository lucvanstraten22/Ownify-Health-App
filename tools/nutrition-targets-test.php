<?php
/**
 * The energy and protein targets, tested without a database.
 *
 *     php tools/nutrition-targets-test.php
 *
 * Every target in Ownify comes out of nutrition_targets_calculate() in
 * includes/nutrition-targets.php, with its numbers in
 * nutrition_targets_config() at the top of that file. This checks the
 * arithmetic on made-up profiles held in memory — Mifflin-St Jeor, the
 * activity multipliers, the neutral constant, the goal adjustment, the safety
 * floor, missing data that must stay null — and that changing a number in the
 * configuration changes the result. Nothing here touches the database or an
 * account.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/nutrition-targets.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

function targets(array $profile, ?array $config = null): array
{
    return nutrition_targets_calculate($profile, $config);
}

$sanne = ['age' => 36, 'gender' => 'female', 'height_cm' => 172.5, 'weight_kg' => 67.4, 'activity_level' => 'moderate'];

echo "The formula\n";

$r = targets($sanne + ['goal' => 'maintenance']);
/* 10 × 67.4 + 6.25 × 172.5 − 5 × 36 − 161 = 1411.125;  × 1.55 = 2187.24 */
check('female: BMR = 10w + 6.25h − 5a − 161', $r['targets']['bmr_kcal'] === 1411, (string) $r['targets']['bmr_kcal']);
check('TDEE = BMR × 1.55 for moderate', $r['targets']['tdee_kcal'] === 2187, (string) $r['targets']['tdee_kcal']);
check('no weight goal: the calorie target is TDEE', $r['targets']['calorie_target_kcal'] === 2187);
check('protein = 67.4 kg × 1.6 g/kg', $r['targets']['protein_target_g'] === 108, (string) $r['targets']['protein_target_g']);
check('nothing missing, nothing assumed', $r['missing'] === [] && $r['assumed'] === []);

$m = targets(['gender' => 'male'] + $sanne);
check('male: the same body, +5 instead of −161', $m['targets']['bmr_kcal'] === 1577, (string) $m['targets']['bmr_kcal']);

echo "\nGender that is not male or female\n";

$n = targets(['gender' => 'non_binary'] + $sanne);
check('non_binary: the neutral constant −78', $n['targets']['bmr_kcal'] === 1494 && $n['method']['sex_constant'] === 'neutral', (string) $n['targets']['bmr_kcal']);
check('  exactly midway between male and female', abs((1577.125 + 1411.125) / 2 - 1494.125) < 1e-9);
foreach (['other', 'undisclosed', null, ''] as $g) {
    $x = targets(['gender' => $g] + $sanne);
    check('  ' . var_export($g, true) . ' is neutral too', $x['targets']['bmr_kcal'] === 1494 && $x['method']['sex_constant'] === 'neutral');
}
check('  and the stored value is passed on as it is', targets(['gender' => 'undisclosed'] + $sanne)['inputs']['gender'] === 'undisclosed');

echo "\nThe goal\n";

$loss = targets($sanne + ['goal' => 'weight_loss']);
check('weight_loss: TDEE × 0.85', $loss['targets']['calorie_target_kcal'] === (int) round(2187.24 * 0.85), (string) $loss['targets']['calorie_target_kcal']);
$gain = targets($sanne + ['goal' => 'weight_gain']);
check('weight_gain: TDEE × 1.10', $gain['targets']['calorie_target_kcal'] === (int) round(2187.24 * 1.10), (string) $gain['targets']['calorie_target_kcal']);
check('a goal changes calories, never BMR, TDEE or protein',
    $loss['targets']['bmr_kcal'] === 1411 && $loss['targets']['tdee_kcal'] === 2187 && $loss['targets']['protein_target_g'] === 108);
check('an unknown goal is maintenance', targets($sanne + ['goal' => 'bulk'])['inputs']['goal'] === 'maintenance');

echo "\nThe safety floor\n";

$small = ['age' => 60, 'gender' => 'female', 'height_cm' => 155, 'weight_kg' => 55, 'activity_level' => 'sedentary', 'goal' => 'weight_loss'];
$s = targets($small);
/* BMR 1057.75, TDEE 1269.3, −15% = 1078.9 → held at 1200 */
check('a deficit below 1200 kcal is held at the floor', $s['targets']['calorie_target_kcal'] === 1200 && $s['method']['floor_applied'] === true, (string) $s['targets']['calorie_target_kcal']);
$tiny = ['age' => 75, 'gender' => 'female', 'height_cm' => 150, 'weight_kg' => 45, 'activity_level' => 'sedentary', 'goal' => 'weight_loss'];
$t = targets($tiny);
check('  but the floor never lifts the target above TDEE', $t['targets']['calorie_target_kcal'] === $t['targets']['tdee_kcal'] && $t['targets']['tdee_kcal'] < 1200, json_encode($t['targets']));
check('  a deficit that stays above the floor is left alone', $loss['method']['floor_applied'] === false);
$nb = targets(['gender' => 'other'] + $small);
check('  neutral gender uses the neutral floor (1350)', $nb['targets']['calorie_target_kcal'] === 1350 && $nb['targets']['tdee_kcal'] > 1350, json_encode($nb['targets']));

echo "\nMissing data\n";

foreach (['age', 'height_cm', 'weight_kg'] as $key) {
    $x = targets([$key => null] + $sanne);
    check("no $key: no BMR, TDEE or calorie target, and it says so",
        $x['targets']['bmr_kcal'] === null && $x['targets']['tdee_kcal'] === null && $x['targets']['calorie_target_kcal'] === null
        && $x['missing'] === [$key], json_encode($x['missing']));
}
check('no age or height: protein still comes from the weight', targets(['age' => null, 'height_cm' => null] + $sanne)['targets']['protein_target_g'] === 108);
check('no weight: no protein target either', targets(['weight_kg' => null] + $sanne)['targets']['protein_target_g'] === null);
$empty = targets([]);
check('an empty profile: every target null, three inputs missing', array_filter($empty['targets'], static fn ($v) => $v !== null) === [] && $empty['missing'] === ['age', 'height_cm', 'weight_kg']);

$a = targets(['activity_level' => null] + $sanne);
check('no activity level: sedentary, and it says it assumed that', $a['assumed'] === ['activity_level' => 'sedentary'] && $a['method']['activity_multiplier'] === 1.2);
check('  the stored value stays null in the inputs', $a['inputs']['activity_level'] === null);
check('  protein uses 1.2 g/kg', $a['targets']['protein_target_g'] === (int) round(67.4 * 1.2));
check('an activity level this file does not know is assumed the same way', targets(['activity_level' => 'marathon'] + $sanne)['assumed'] === ['activity_level' => 'sedentary']);

$young = targets(['age' => 16] + $sanne);
check('under 18: no targets, and it says an adult age is needed', array_filter($young['targets'], static fn ($v) => $v !== null) === [] && in_array('adult_age', $young['missing'], true));

echo "\nEvery activity level\n";

foreach (['sedentary' => [1.2, 1.2], 'light' => [1.375, 1.4], 'moderate' => [1.55, 1.6], 'active' => [1.725, 1.8], 'athlete' => [1.9, 2.0]] as $level => [$mult, $perKg]) {
    $x = targets(['activity_level' => $level] + $sanne);
    check("$level: × $mult and $perKg g/kg",
        $x['targets']['tdee_kcal'] === (int) round(1411.125 * $mult) && $x['targets']['protein_target_g'] === (int) round(67.4 * $perKg));
}

echo "\nThe configuration is the one place\n";

$config = nutrition_targets_config();
$config['activity_multiplier']['moderate'] = 1.60;
$config['protein_g_per_kg']['moderate']    = 1.8;
$config['weight_loss_deficit']             = 0.20;
$c = targets($sanne + ['goal' => 'weight_loss'], $config);
check('moderate × 1.60 instead of 1.55 changes TDEE', $c['targets']['tdee_kcal'] === (int) round(1411.125 * 1.60), (string) $c['targets']['tdee_kcal']);
check('1.8 g/kg instead of 1.6 changes protein', $c['targets']['protein_target_g'] === (int) round(67.4 * 1.8));
check('a 20% deficit instead of 15% changes the calorie target', $c['targets']['calorie_target_kcal'] === (int) round(1411.125 * 1.60 * 0.80));
$config['bmr']['sex_constant']['female'] = -150.0;
check('the female constant changes BMR', targets($sanne, $config)['targets']['bmr_kcal'] === (int) round(1411.125 + 11));

echo "\nDeterministic\n";

check('the same profile gives the same answer every time', targets($sanne) === targets($sanne));

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

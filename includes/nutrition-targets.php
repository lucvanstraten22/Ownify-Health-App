<?php
/**
 * Daily energy and protein targets — PRIVATE, scoped to its owner like every
 * health record.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT WORKS OUT
 * ---------------------------------------------------------------------------
 *   BMR      energy the body uses at rest — Mifflin-St Jeor
 *   TDEE     BMR × the multiplier for the person's activity level
 *   target   TDEE, or a moderate deficit or surplus when the person's primary
 *            goal is clearly to lose or to gain body weight
 *   protein  body weight × grams per kg for the activity level
 *
 * Every number that decides a result is in nutrition_targets_config(), at the
 * top of this file, and nowhere else. Change one there and every caller — the
 * phone's endpoint, and later the web pages and the Health Score — follows.
 *
 * ---------------------------------------------------------------------------
 * ONE CALCULATION, TWO LAYERS
 * ---------------------------------------------------------------------------
 *   nutrition_targets_calculate()  pure: profile values in, targets out. No
 *                                  database, no request — testable offline,
 *                                  usable anywhere.
 *   nutrition_targets_for_user()   reads the signed-in person's own profile
 *                                  (user_account()) and primary goal, then
 *                                  calls the one above.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS INVENTED
 * ---------------------------------------------------------------------------
 *   - no age, height or weight: no BMR, TDEE or calorie target (null), and
 *     `missing` says which input was absent;
 *   - no weight: no protein target either;
 *   - no activity level: the lowest multiplier is used, and `assumed` says so
 *     — the one input with a stated default, chosen so that a missing answer
 *     can only lower a target, never raise it;
 *   - a gender other than male or female, or none: the formula's neutral
 *     midpoint, never a guess at male or female;
 *   - under the minimum age: no targets at all — the formula and the
 *     protein rule are both for adults.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/goals.php';

if (!function_exists('nutrition_targets_config')) {

    /* ==================================================================
       CONFIGURATION — every value that decides a target, in one place
       ================================================================== */

    function nutrition_targets_config(): array
    {
        return [

            /* Mifflin-St Jeor: BMR = 10 × weight_kg + 6.25 × height_cm
                                      − 5 × age + sex constant (kcal/day). */
            'bmr' => [
                'per_kg'   => 10.0,
                'per_cm'   => 6.25,
                'per_year' => 5.0,          // subtracted
                /* The constant at the end of the formula. `neutral` is used for
                   non_binary, other, undisclosed and no answer: the midpoint of
                   the male and female constants, (5 + −161) / 2 = −78, so
                   nobody is assigned a sex they did not give. */
                'sex_constant' => [
                    'male'    => 5.0,
                    'female'  => -161.0,
                    'neutral' => -78.0,
                ],
            ],

            /* Both rules are for adults. Younger than this: no targets. */
            'min_age' => 18,

            /* TDEE = BMR × this, by the activity level on the profile. */
            'activity_multiplier' => [
                'sedentary' => 1.20,
                'light'     => 1.375,
                'moderate'  => 1.55,
                'active'    => 1.725,
                'athlete'   => 1.90,
            ],

            /* Protein per kg of body weight per day, by activity level. */
            'protein_g_per_kg' => [
                'sedentary' => 1.2,
                'light'     => 1.4,
                'moderate'  => 1.6,
                'active'    => 1.8,
                'athlete'   => 2.0,
            ],

            /* Used when the profile has no activity level. The lowest, so an
               unanswered question can only lower a target, never raise it. */
            'default_activity_level' => 'sedentary',

            /* How the calorie target moves from TDEE for a clear weight goal:
               a 15% deficit to lose weight, a 10% surplus to gain it. */
            'weight_loss_deficit' => 0.15,
            'weight_gain_surplus' => 0.10,

            /* SAFETY FLOOR — a deficit never takes the calorie target below
               this (kcal/day). These are the levels commonly given as the
               lowest intake to follow without medical supervision; `neutral`
               is their midpoint, for the same reason as the BMR constant. The
               floor never lifts a target above the person's own TDEE: it
               stops a deficit, it does not prescribe a surplus. */
            'calorie_floor' => [
                'male'    => 1500,
                'female'  => 1200,
                'neutral' => 1350,
            ],
        ];
    }

    /* ==================================================================
       THE CALCULATION — pure, no database
       ================================================================== */

    /**
     * Targets from profile values.
     *
     * @param array{age?: ?int, gender?: ?string, height_cm?: ?float, weight_kg?: ?float,
     *              activity_level?: ?string, goal?: ?string} $profile
     *        goal: 'weight_loss', 'weight_gain' or 'maintenance' (or null)
     * @return array{targets: array, inputs: array, missing: string[], assumed: array, method: array}
     */
    function nutrition_targets_calculate(array $profile, ?array $config = null): array
    {
        $c = $config ?? nutrition_targets_config();

        $age      = isset($profile['age']) && $profile['age'] !== null ? (int) $profile['age'] : null;
        $gender   = isset($profile['gender']) && $profile['gender'] !== '' ? (string) $profile['gender'] : null;
        $height   = isset($profile['height_cm']) && $profile['height_cm'] !== null ? (float) $profile['height_cm'] : null;
        $weight   = isset($profile['weight_kg']) && $profile['weight_kg'] !== null ? (float) $profile['weight_kg'] : null;
        $activity = isset($profile['activity_level']) && $profile['activity_level'] !== '' ? (string) $profile['activity_level'] : null;
        $goal     = in_array($profile['goal'] ?? null, ['weight_loss', 'weight_gain', 'maintenance'], true)
            ? (string) $profile['goal']
            : 'maintenance';

        $missing = [];
        $assumed = [];

        foreach (['age' => $age, 'height_cm' => $height, 'weight_kg' => $weight] as $name => $value) {
            if ($value === null || $value <= 0) {
                $missing[] = $name;
            }
        }

        /* An activity level nobody gave, or one this file does not know. */
        $level = $activity;
        if ($level === null || !isset($c['activity_multiplier'][$level], $c['protein_g_per_kg'][$level])) {
            $level = (string) $c['default_activity_level'];
            $assumed['activity_level'] = $level;
        }

        /* Male and female as given; everything else, and no answer, neutral. */
        $sex = in_array($gender, ['male', 'female'], true) ? $gender : 'neutral';

        $multiplier = (float) $c['activity_multiplier'][$level];
        $perKg      = (float) $c['protein_g_per_kg'][$level];

        $bmr = $tdee = $target = null;
        $adjustment   = 0.0;
        $floorApplied = false;
        $tooYoung     = $age !== null && $age < (int) $c['min_age'];

        if ($missing === [] && !$tooYoung) {
            $b = $c['bmr'];
            $bmr = $b['per_kg'] * $weight
                 + $b['per_cm'] * $height
                 - $b['per_year'] * $age
                 + $b['sex_constant'][$sex];

            $tdee = $bmr * $multiplier;

            $adjustment = match ($goal) {
                'weight_loss' => -(float) $c['weight_loss_deficit'],
                'weight_gain' => (float) $c['weight_gain_surplus'],
                default       => 0.0,
            };

            $target = $tdee * (1 + $adjustment);

            /* The floor stops a deficit; it never goes above TDEE itself. */
            $floor = min((float) $c['calorie_floor'][$sex], $tdee);
            if ($target < $floor) {
                $target = $floor;
                $floorApplied = true;
            }
        }

        $protein = ($weight !== null && $weight > 0 && !$tooYoung) ? $weight * $perKg : null;

        $round = static fn (?float $value): ?int => $value === null ? null : (int) round($value);

        return [
            'targets' => [
                'bmr_kcal'            => $round($bmr),
                'tdee_kcal'           => $round($tdee),
                'calorie_target_kcal' => $round($target),
                'protein_target_g'    => $round($protein),
            ],
            'inputs' => [
                'age'            => $age,
                'gender'         => $gender,
                'height_cm'      => $height,
                'weight_kg'      => $weight,
                'activity_level' => $activity,
                'goal'           => $goal,
            ],
            'missing' => $tooYoung ? [...$missing, 'adult_age'] : $missing,
            'assumed' => $assumed,
            'method'  => [
                'bmr_formula'         => 'mifflin_st_jeor',
                'sex_constant'        => $sex,
                'activity_multiplier' => $multiplier,
                'protein_g_per_kg'    => $perKg,
                'calorie_adjustment'  => $adjustment,
                'floor_applied'       => $floorApplied,
            ],
        ];
    }

    /* ==================================================================
       FOR ONE PERSON — their own profile and goal, from the database
       ================================================================== */

    /**
     * The targets for this account, from what it has stored: user_account()
     * for the profile (age from the birth date, the newest height and
     * weight), and its active primary goal for the direction. Null when the
     * account does not exist.
     */
    function nutrition_targets_for_user(int $userId, ?array $config = null): ?array
    {
        $account = user_account($userId);

        if ($account === null) {
            return null;
        }

        $height = $account['height'] ?? null;
        $weight = $account['weight'] ?? null;

        /* Both are recorded in cm and kg (profile/update.php, the Health
           Connect mapping); anything else is not converted by guesswork. */
        $heightCm = $height !== null && $height['unit'] === 'cm' ? (float) $height['value'] : null;
        $weightKg = $weight !== null && $weight['unit'] === 'kg' ? (float) $weight['value'] : null;

        return nutrition_targets_calculate([
            'age'            => $account['age'],
            'gender'         => $account['gender'],
            'height_cm'      => $heightCm,
            'weight_kg'      => $weightKg,
            'activity_level' => $account['activity_level'],
            'goal'           => nutrition_targets_goal($userId, $weightKg),
        ], $config);
    }

    /**
     * What the person's active primary goal says about body weight:
     * 'weight_loss', 'weight_gain' or 'maintenance'.
     *
     * A goal counts only when it clearly is one:
     *   - it is about body weight in kg — read from the scale (measurement
     *     'weight'), or a Gewicht goal kept by hand in kg. A Gewicht goal in
     *     % may be body fat, which is not the same thing;
     *   - it is a Mijlpaal, the one type whose direction the person chose
     *     ("lager is beter" / "hoger is beter"). On Optellen and Streak the
     *     direction means something else — "3 kg afvallen" adds up as an
     *     increase — so they are not read as a direction for body weight.
     *
     * A goal already reached — below its target when losing, above it when
     * gaining — asks for maintenance, not more of the same. Anything else:
     * maintenance.
     */
    function nutrition_targets_goal(int $userId, ?float $currentKg): string
    {
        foreach (goals_for_user($userId, 'active') as $goal) {
            if (($goal['priority'] ?? null) !== 'primary') {
                continue;
            }

            $scale  = ($goal['source_kind'] ?? null) === 'measurement' && ($goal['source_key'] ?? null) === 'weight';
            $byHand = ($goal['category'] ?? null) === 'weight' && mb_strtolower((string) ($goal['target_unit'] ?? '')) === 'kg';

            if ((!$scale && !$byHand) || ($goal['goal_type'] ?? 'milestone') !== 'milestone') {
                return 'maintenance';
            }

            $target = $goal['target_value'] !== null ? (float) $goal['target_value'] : null;

            return match ($goal['direction'] ?? null) {
                'decrease' => ($target !== null && $currentKg !== null && $currentKg <= $target) ? 'maintenance' : 'weight_loss',
                'increase' => ($target !== null && $currentKg !== null && $currentKg >= $target) ? 'maintenance' : 'weight_gain',
                default    => 'maintenance',
            };
        }

        return 'maintenance';
    }
}

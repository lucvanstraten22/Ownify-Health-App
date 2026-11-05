<?php
/**
 * The Health Score — every number that decides it, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE HEALTH SCORE IS
 * ---------------------------------------------------------------------------
 * How healthy somebody's recent PATTERN is: sleep, nutrition and training
 * over a rolling window of the last 168 hours — the current moment minus
 * seven days, moving with the clock, never a calendar week. There is one
 * Health Score and this is its window; the 30, 90 and 365 days the
 * Scorekompas shows are its history (one stored score a day), never other
 * scores.
 *
 * It is not the leaderboard. Nothing in this file awards a point, and nothing
 * that awards points reads a Health Score: points are for what somebody did,
 * and live in config/points.php.
 *
 * ---------------------------------------------------------------------------
 * HOW TO READ THE CURVES
 * ---------------------------------------------------------------------------
 * A curve is a list of [input, score] points. Between two points the score
 * follows a smooth monotone curve (health_curve() in
 * includes/health-signals.php), never a step, so 7:59 and 8:00 of sleep
 * score practically the same. Below the first point the first score holds;
 * past the last point the last one does. Change a number here and every
 * score follows on its next calculation.
 *
 * ---------------------------------------------------------------------------
 * MISSING IS NOT ZERO
 * ---------------------------------------------------------------------------
 * A category scores only with at least `min_days` distinct days of real data
 * in the window, and then over the days that HAVE data — 5 nights in 7 days
 * is an average over 5, not a sum over 7. A component with no data at all
 * (sleep stages from a phone without a watch, say) is left out and the
 * remaining weights are scaled up to fill its place, so nobody is marked down
 * for what their device cannot measure.
 *
 * ---------------------------------------------------------------------------
 * NO NEW DATA IS NOT A ZERO EITHER — BUT IT DOES NOT LAST
 * ---------------------------------------------------------------------------
 * A day without a new night or rating changes nothing: the score rests on
 * the days that are still in the window. After `expiry_days` days in a row
 * without new input, though, the category stops counting — it is left out
 * of the Health Score, never counted as zero, and the categories that are
 * still current make the score until new data comes in.
 */

declare(strict_types=1);

return [

    /* The window, and how much of it has to hold data before a category
       says anything: distinct days with data, not hours. The overall score
       appears as soon as one category has its days. */
    'window_hours' => 168,
    'min_days'     => 3,

    /* Days in a row without new input after which a category stops counting:
       its last night (the morning it ended), its last cijfer. Day 1 a night,
       days 2, 3 and 4 none: on day 4 Slaap is left out. Null: it does not
       expire. Training does not, on purpose — days without a workout are rest,
       which its balance scores, and three of them in a row is an ordinary
       week; the window still drops workouts older than 168 hours. */
    'expiry_days' => [
        'sleep'     => 3,
        'nutrition' => 3,
        'training'  => null,
    ],

    /* What a score means. Interpretation only: no calculation reads these. */
    'bands' => [
        90 => 'Uitstekend',
        80 => 'Zeer goed',
        70 => 'Goed',
        60 => 'Gemiddeld',
        45 => 'Onder gemiddeld',
        30 => 'Matig',
        0  => 'Zeer matig',
    ],

    /* Which colour a score is shown in: the dot beside each pillar on
       Overzicht. The same for every category — a category keeps its own
       colour whatever its score, and a score's colour says nothing about its
       category. Inclusive lower bounds: 80 is high, 60 is mid, 59 is low.
       score_colour_band() (includes/scoring.php) is the one place that reads
       these; the website and the app both show the band it hands them, in
       --score-high / -mid / -low (assets/css/theme.css) and Ownify.ScoreHigh
       / Mid / Low (OwnifyTheme.kt). */
    'colour_bands' => [
        80 => 'high',   // 80–100  #4E9F70
        60 => 'mid',    // 60–79   #AECA0F
        0  => 'low',    // 0–59    #C99A45
    ],

    /* Which recorded workouts count at all — for the Training score and for
       the points alike, so the two can never disagree about what a workout
       is. Two recordings of one session (a watch and a phone app both
       writing it) that overlap by more than `overlap` of the shorter one are
       one workout: the longer recording counts. */
    'workouts' => [
        'min_minutes'   => 10,
        'max_minutes'   => 480,
        'overlap'       => 0.5,
        /* A session this long counts as heavy even without intensity data. */
        'heavy_minutes' => 90,
    ],

    /* ==================================================================
       HOW A CATEGORY IS WEIGHED (38.0, docs/SCORE-COMPASS.md "Method")
       ------------------------------------------------------------------
       Every factor is graded twice, on the steps 1 / 0.5 / 0.25:

         evidence     how strong the research is that ties the factor to
                      health outcomes (1: large cohorts or meta-analyses with
                      a dose-response; 0.5: consistent but smaller or
                      indirect; 0.25: plausible, little direct evidence);
         measurement  how well an ordinary phone or watch measures it
                      (1: well; 0.5: reasonably, or only with some devices;
                      0.25: poorly, or only from what somebody logs).

       Its priority is evidence × measurement. Factors that largely measure
       the same thing (`overlap`, a correlation ρ) share their place: each
       priority is divided by 1 + the sum of its ρ, so two overlapping
       factors are not counted twice. The weight is that corrected priority
       as a share of the category's total — worked out from these grades by
       health_score_weights() (includes/health-signals.php), never typed in.
       A factor without data is left out and the others scale up.

       Interim values: the overlaps are estimates from the literature, to be
       re-estimated from Ownify's own data after eight weeks (D7).
       ================================================================== */

    /* Recent days count more within the window: a day weighs
       0.5 ^ (days ago / half_life_days) — today 1, four days ago ½,
       a week ago about 0.3. */
    'recency' => ['half_life_days' => 4],

    /* The uncertainty range stored with every score (not shown yet):
       the grades moved a step up or down at random and every overlap
       scaled, `draws` times; the 5th and 95th percentile of the scores. */
    'uncertainty' => [
        'draws'       => 2000,
        'step_chance' => 1 / 3,
        'overlap'     => [0.4, 1.3],
        'percentiles' => [5, 95],
        'seed'        => 38,
    ],

    /* ==================================================================
       SLEEP — eight factors
       ================================================================== */
    'sleep' => [
        'factors' => [
            'duration'      => ['evidence' => 1,    'measurement' => 1],
            'regularity'    => ['evidence' => 1,    'measurement' => 1],
            'social_jetlag' => ['evidence' => 0.5,  'measurement' => 1],
            'efficiency'    => ['evidence' => 0.5,  'measurement' => 0.25],
            'awake'         => ['evidence' => 0.5,  'measurement' => 0.25],
            'deep'          => ['evidence' => 0.25, 'measurement' => 0.25],
            'rem'           => ['evidence' => 0.25, 'measurement' => 0.25],
            'hrv'           => ['evidence' => 0.25, 'measurement' => 0.5],
        ],
        'overlap' => [['efficiency', 'awake', 0.7]],

        /* Two recordings of one night (a watch and a phone both writing it)
           that overlap by more than this share of the shorter one are one
           night: the one with sleep stages counts, otherwise the longer. */
        'overlap_nights' => 0.5,

        /* Two parts of one night — asleep, up for a while, asleep again — are
           one night when the gap between them is at most this many minutes.
           A nap hours away from the night stays a separate, shorter sleep and
           is never the night (includes/health-signals.php). */
        'merge_gap_minutes' => 60,

        /* Hours asleep in one night -> score: the AHA Life's Essential 8
           sleep points (7–9 h 100, 9–10 h 90, 6–7 h 70, 5–6 h 40, 4–5 h 20,
           < 4 h 0, ≥ 10 h 40), as a smooth curve through the middle of
           each band. */
        'duration_curve' => [
            [3.5, 0], [4.5, 20], [5.5, 40], [6.5, 70], [7, 100], [9, 100], [9.5, 90], [10.5, 40],
        ],

        /* How much bedtime and wake time move from night to night: the
           standard deviation over the window, in minutes -> score, the two
           averaged (Windred et al. 2024, regularity and mortality). */
        'regularity' => [
            'timing_curve' => [[30, 100], [60, 81], [90, 63], [120, 30], [150, 0]],
        ],

        /* The midpoint of sleep on free days (nights into Saturday and
           Sunday) against work days, in hours -> score (Roenneberg). */
        'social_jetlag' => [
            'curve' => [[1, 100], [2, 60], [3, 20]],
        ],

        /* HRV against the person's own baseline: the z-score of the
           window's mean ln(rMSSD) against the `baseline_days` before it ->
           score. Below zero is lower than usual; at or above it, 100. */
        'hrv' => [
            'baseline_days' => 60,
            'min_baseline'  => 14,
            'min_days'      => 3,
            /* The baseline's spread is never taken as smaller than this
               (ln units, about 5%): a very steady baseline would otherwise
               turn a tiny change into a large z. */
            'min_sd'        => 0.05,
            'curve'         => [[-3, 0], [-2, 30], [-1, 70], [0, 100]],
        ],

        /* Whatever the device measures about how the night went. Each night
           is scored on the measurements it has; a measurement no night has
           is simply not part of the score. These are broad, typical ranges
           for adults — not medical limits — and they are deliberately
           forgiving, because devices disagree with each other. The weights
           make a night's quality where one number is needed (the points). */
        'quality' => [
            'min_nights' => 3,
            'weights'    => ['efficiency' => 1, 'awake' => 1, 'deep' => 1, 'rem' => 1],
            /* Time asleep as a share of time in bed, % -> score. */
            'efficiency_curve' => [[50, 5], [65, 25], [75, 50], [80, 65], [85, 80], [90, 92], [95, 100]],
            /* Minutes awake after falling asleep -> score. */
            'awake_curve'      => [[0, 100], [10, 100], [20, 92], [30, 83], [45, 70], [60, 58],
                                   [90, 38], [120, 22], [180, 5]],
            /* Deep and REM sleep as a share of the time asleep, % -> score. */
            'deep_curve'       => [[0, 15], [5, 40], [10, 75], [15, 100], [25, 100], [30, 95], [40, 85]],
            'rem_curve'        => [[0, 15], [5, 40], [10, 65], [15, 85], [20, 100], [25, 100], [30, 95], [40, 80]],
        ],
    ],

    /* ==================================================================
       NUTRITION — ten factors
       What was eaten counts only on days whose log is complete enough to
       mean something: at least `food_day_share` of the energy target
       (without a target, `food_day_fallback_kcal`). Without any such day
       the daily rating makes the whole score.
       ================================================================== */
    'nutrition' => [
        'factors' => [
            'fibre'         => ['evidence' => 1,    'measurement' => 0.5],
            'sodium'        => ['evidence' => 1,    'measurement' => 0.25],
            'saturated_fat' => ['evidence' => 0.5,  'measurement' => 0.5],
            'sugar'         => ['evidence' => 0.5,  'measurement' => 0.25],
            'protein'       => ['evidence' => 0.25, 'measurement' => 0.5],
            'water'         => ['evidence' => 0.25, 'measurement' => 0.5],
            'caffeine'      => ['evidence' => 0.25, 'measurement' => 0.5],
            'fat_share'     => ['evidence' => 0.25, 'measurement' => 0.5],
            'energy'        => ['evidence' => 0.25, 'measurement' => 0.25],
            'rating'        => ['evidence' => 0.25, 'measurement' => 0.25],
        ],
        'overlap' => [['saturated_fat', 'fat_share', 0.6]],

        'food_day_share'         => 0.5,
        'food_day_fallback_kcal' => 1000,

        /* A 1-10 rating onto 0-100: 1 -> 10, 5 -> 50, 7 -> 70, 10 -> 100. Several
           ratings on one day count as that day's average. */
        'rating_scale' => 10,

        /* Grams of fibre per 1,000 kcal (Reynolds et al. 2019). */
        'fibre_curve'         => [[5, 0], [14, 100]],
        /* Grams of sodium per 1,000 kcal (WHO: < 2 g a day). */
        'sodium_curve'        => [[1.2, 100], [2.4, 0]],
        /* Saturated fat, % of energy (Gezondheidsraad: < 10%). */
        'saturated_fat_curve' => [[10, 100], [20, 0]],
        /* Sugars, % of energy (WHO: free sugars < 10%, total sugars here). */
        'sugar_curve'         => [[15, 100], [30, 0]],
        /* Protein, g per kg body weight (EFSA PRI 0.83). */
        'protein_curve'       => [[0.5, 0], [0.66, 50], [0.83, 100]],
        /* Fat, % of energy: 20–40% (Gezondheidsraad). */
        'fat_share_curve'     => [[10, 0], [15, 50], [20, 100], [40, 100], [45, 50], [50, 0]],
        /* Energy eaten against the target, |difference| as a share -> score. */
        'energy_curve'        => [[0.10, 100], [0.25, 50], [0.40, 0]],
        /* Water a day, as a share of the person's norm (NUT-08): at or above
           the norm 100, half of it or less 0. The norm in litres: women and
           anybody whose sex Ownify does not know 1.4, men 1.8. */
        'water_curve'         => [[0.5, 0], [1.0, 100]],
        'water_norm_l'        => ['female' => 1.4, 'male' => 1.8, 'unknown' => 1.4],
        /* Hours between the last caffeine of the day and the usual bedtime
           (Drake et al. 2013; 8.8 h, Gardiner et al. 2023). */
        'caffeine_curve'      => [[0, 0], [4, 40], [8.8, 100]],
    ],

    /* ==================================================================
       TRAINING — eight factors
       ================================================================== */
    'training' => [
        'factors' => [
            'steps'       => ['evidence' => 1,    'measurement' => 1],
            'aerobic'     => ['evidence' => 1,    'measurement' => 0.5],
            'strength'    => ['evidence' => 1,    'measurement' => 0.5],
            'intensity'   => ['evidence' => 0.5,  'measurement' => 0.5],
            'active_days' => ['evidence' => 0.25, 'measurement' => 1],
            'resting_hr'  => ['evidence' => 1,    'measurement' => 0.5],
            'recovery'    => ['evidence' => 0.25, 'measurement' => 0.5],
            'progression' => ['evidence' => 0.25, 'measurement' => 0.25],
        ],
        'overlap' => [['steps', 'aerobic', 0.5], ['aerobic', 'intensity', 0.5]],

        /* Steps a day, on completed days -> score: ln(HR) / ln(0.47) from
           Paluch et al. 2022 (mortality by daily steps), separately from 60. */
        'steps_curve'       => [[3550, 0], [5800, 68], [7000, 74], [7850, 79], [10000, 100]],
        'steps_curve_older' => [[3550, 0], [5800, 91], [7000, 100]],
        'older_from_age'    => 60,

        /* Aerobic minutes per week, vigorous counting double (WHO 2020)
           -> score, after Arem et al. 2015. */
        'aerobic_curve'  => [[0, 0], [75, 45], [150, 60], [225, 75], [375, 94], [600, 100]],
        /* Strength minutes per week (Momma et al. 2022). */
        'strength_curve' => [[0, 0], [30, 80], [60, 100]],
        /* The workouts that are strength training. */
        'strength_types' => ['strength', 'strength_training', 'weightlifting', 'weight_training', 'calisthenics'],
        /* The share of aerobic minutes that were vigorous -> score. */
        'intensity_curve' => [[0, 0], [0.5, 100]],
        /* Days with at least `active_day_minutes` aerobic minutes or
           `active_day_steps` steps, per week -> score. */
        'active_day_minutes' => 20,
        'active_day_steps'   => 7000,
        'active_days_curve'  => [[0, 0], [3, 80], [5, 100]],
        /* Resting heart rate, bpm -> score: a straight line from 55 to 90. */
        'resting_hr_curve'   => [[55, 100], [90, 0]],
        /* Recovery: the share of heavy days followed by a rest or easy day
           -> score, once at least `recovery_min` heavy days can be judged. */
        'recovery_curve'     => [[0, 40], [1, 100]],
        'recovery_min'       => 2,

        /* How hard a workout was, from what was measured — perceived effort
           first, then heart-rate zones, then average heart rate against
           the maximum. Hard is vigorous. */
        'intensity' => [
            'min_workouts'     => 3,
            /* Perceived effort, 1-10. */
            'rpe'        => ['moderate' => 5, 'hard' => 7],
            /* Time in zone >= hard_zone, as a share of the workout, makes it
               hard; time in the zone below it and up, moderate. */
            'hr_zones'   => ['hard_zone' => 4, 'hard_share' => 0.30, 'moderate_share' => 0.50],
            /* Average heart rate as a share of the maximum (ACSM: moderate
               from 64%, vigorous from 77%). The maximum is the highest heart
               rate the person recorded, or 208 - 0.7 x age if that is higher. */
            'hr_max_pct' => ['moderate' => 0.64, 'hard' => 0.77],
            /* The estimate of the maximum from age: base - per_year x age. */
            'hr_max_estimate' => ['base' => 208, 'per_year' => 0.7],
        ],

        /* Is this person getting better than they were? Only ever compared
           with their own earlier results in the window: pace over a
           distance, and results on their own strength or performance
           goals. Relative change (recent half vs earlier half)
           -> score; holding steady is average. */
        'progression' => [
            'min_samples'     => 4,
            'min_km'          => 1.0,
            'goal_categories' => ['training'],
            /* Goal results must span at least this many days before they
               say anything about a trend. */
            'goal_min_days'   => 7,
            /* A single signal moves the average by at most this much, so one
               odd measurement cannot decide the whole component. */
            'max_change'      => 0.30,
            'change_curve'    => [[-0.15, 15], [-0.10, 25], [-0.05, 40], [-0.02, 52], [0, 60],
                                  [0.02, 70], [0.05, 84], [0.10, 95], [0.15, 100]],
        ],
    ],
];

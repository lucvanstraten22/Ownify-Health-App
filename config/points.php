<?php
/**
 * Leaderboard points — every value, threshold and label in one place.
 *
 * ---------------------------------------------------------------------------
 * POINTS ARE FOR WHAT SOMEBODY DID
 * ---------------------------------------------------------------------------
 * A night slept, a day's nutrition rated, a workout done, a step count
 * reached, three workouts in one week. Each is an event, and each event pays
 * out once: a sync that sends the same workout three times is one workout.
 *
 * The Health Score never pays out. A score of 91 is not 91 points, and
 * nothing in includes/points.php reads a Health Score — see config/scoring.php
 * for that, separately.
 *
 * ---------------------------------------------------------------------------
 * TIERS PAY ONE TIER
 * ---------------------------------------------------------------------------
 * Every list of tiers pays the one tier reached, never the sum of those
 * below it: 8:30 of sleep is 45, not 25 + 35 + 45; 10.000 steps is 35. Bonuses
 * that describe something else about the same event — a workout that was
 * also intense, also a personal record — are separate awards and stack.
 *
 * There is no daily cap on purpose.
 */

declare(strict_types=1);

return [

    /* Activity from before the account existed earns nothing: connecting a
       phone with a year of history must not buy a place at the top of the
       board. null awards everything that is synced. */
    'award_from' => 'account_created',

    /* A record dated this far past the current time is not awarded (yet). */
    'future_tolerance_minutes' => 10,

    /* The weekly bonus counts weeks from this day. The choice in Instellingen
       is not stored yet; once it is, a person's own choice takes over. */
    'week_starts_on' => 'monday',

    /* ------------------------------------------------------------ sleep */
    'sleep' => [
        /* Minutes asleep in the night's main sleep: [from, up to (not
           including), points]. One tier per night. */
        'duration_tiers' => [
            [420, 450, 25],     // 7:00 – 7:29
            [450, 480, 35],     // 7:30 – 7:59
            [480, 540, 45],     // 8:00 – 8:59
            [540, 571, 35],     // 9:00 – 9:30
            [571, null, 20],    // more than 9:30
        ],
        /* Bedtime and wake time both within this many minutes of the person's
           usual times over their previous nights. */
        'regularity' => [
            'points'           => 10,
            'window_minutes'   => 45,
            'lookback_nights'  => 14,
            'min_prior_nights' => 5,
        ],
        /* The night's own measurements (efficiency, time awake, deep and REM
           sleep) scored on the curves in config/scoring.php, at least this
           high. A night without such measurements gets no quality bonus. */
        'quality' => [
            'points'    => 10,
            'min_score' => 75,
        ],
    ],

    /* -------------------------------------------------------- nutrition */
    'nutrition' => [
        /* The day's rating, 1-10: [from, to (including), points]. */
        'rating_tiers' => [
            [1, 3, 0],
            [4, 5, 10],
            [6, 7, 25],
            [8, 9, 40],
            [10, 10, 50],
        ],
    ],

    /* --------------------------------------------------------- training */
    'training' => [
        /* Minutes of a qualifying workout (config/scoring.php decides what
           qualifies): [from, up to (not including), points, what it is]. */
        'duration_tiers' => [
            [10, 30, 20, 'Korte training'],
            [30, 60, 35, 'Training'],
            [60, null, 45, 'Lange training'],
        ],

        /* An intense workout, read the same way as for the Training score:
           perceived effort, heart-rate zones, or average heart rate. */
        'intensity' => [
            'hard'      => 10,
            'very_hard' => 15,
            /* What makes it very hard rather than hard. */
            'very_hard_rpe'        => 9,
            'very_hard_zone_share' => 0.50,
            'very_hard_hr_pct'     => 0.85,
        ],

        /* A clear personal best for that kind of activity: the fastest pace
           over at least a comparable distance, or the longest distance —
           against at least `min_history` earlier workouts of the same kind,
           and by at least the margin, so a rounding difference is not a
           record. */
        'record' => [
            'points'          => 25,
            'min_history'     => 3,
            'min_km'          => 1.0,
            'speed_margin'    => 0.01,
            'distance_margin' => 0.05,
            /* A pace is only compared with workouts at least this share of
               this one's distance: a fast 2 km is not a record over 10 km. */
            'comparable_share' => 0.80,
        ],

        /* Consistency: this many qualifying workouts in one week, awarded the
           moment the last of them is recorded — once per week. On different
           days, because three walks in one afternoon are not a pattern. */
        'weekly' => [
            'workouts'      => 3,
            'points'        => 75,
            'distinct_days' => true,
        ],
    ],

    /* ------------------------------------------------------------ steps */
    'steps' => [
        /* Steps in one day: [at least, points]. The highest reached counts. */
        'tiers' => [
            [5000, 10],
            [7500, 20],
            [10000, 35],
            [12500, 45],
        ],
    ],

    /* ------------------------------------------------ what each award is */
    /* One row per rule in point_rules, kept in step with this list, so every
       award in the ledger names the rule it came from. */
    'rules' => [
        'sleep_duration'    => ['label' => 'Nachtrust',                'domain' => 'sleep',     'cadence' => 'daily'],
        'sleep_regularity'  => ['label' => 'Regelmatig geslapen',      'domain' => 'sleep',     'cadence' => 'daily'],
        'sleep_quality'     => ['label' => 'Goed geslapen',            'domain' => 'sleep',     'cadence' => 'daily'],
        'nutrition_rating'  => ['label' => 'Voeding beoordeeld',       'domain' => 'nutrition', 'cadence' => 'daily'],
        'workout'           => ['label' => 'Training',                 'domain' => 'training',  'cadence' => 'per_event'],
        'workout_intensity' => ['label' => 'Intensieve training',      'domain' => 'training',  'cadence' => 'per_event'],
        'workout_record'    => ['label' => 'Persoonlijk record',       'domain' => 'training',  'cadence' => 'per_event'],
        'steps'             => ['label' => 'Stappen',                  'domain' => 'training',  'cadence' => 'daily'],
        'weekly_workouts'   => ['label' => '3 trainingen deze week',   'domain' => 'training',  'cadence' => 'weekly'],
    ],
];

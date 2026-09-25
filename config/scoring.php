<?php
/**
 * The Health Score — every number that decides it, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE HEALTH SCORE IS
 * ---------------------------------------------------------------------------
 * How healthy somebody's recent PATTERN is: sleep, nutrition and training
 * over a rolling window of the last 90 days — the current moment minus 90
 * days, moving with the clock, never a calendar week or month.
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
 * in the window, and then over the days that HAVE data — 24 nights in 90 days
 * is an average over 24, not a sum over 90. A component with no data at all
 * (sleep stages from a phone without a watch, say) is left out and the
 * remaining weights are scaled up to fill its place, so nobody is marked down
 * for what their device cannot measure.
 */

declare(strict_types=1);

return [

    /* The window, and how much of it has to hold data before a category
       says anything. */
    'window_days' => 90,
    'min_days'    => 7,

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
       SLEEP — duration 45%, regularity 30%, quality 25%
       ================================================================== */
    'sleep' => [
        'weights' => ['duration' => 0.45, 'regularity' => 0.30, 'quality' => 0.25],

        /* Two recordings of one night (a watch and a phone both writing it)
           that overlap by more than this share of the shorter one are one
           night: the one with sleep stages counts, otherwise the longer. */
        'overlap' => 0.5,

        /* Hours asleep in one night -> score. Around 8 hours is the top; the
           curve falls away gently either side, faster below 6 and above 10. */
        'duration_curve' => [
            [2, 2], [3, 8], [4, 20], [5, 45], [5.5, 53], [6, 62], [6.5, 72],
            [7, 84], [7.5, 95], [8, 100], [8.5, 96], [9, 88], [9.5, 78],
            [10, 66], [11, 42], [12, 22], [13, 10], [14, 4],
        ],

        /* How much bedtime, wake time and duration move from night to night:
           the standard deviation over the window, in minutes -> score. */
        'regularity' => [
            'weights'        => ['bedtime' => 0.4, 'wake_time' => 0.4, 'duration' => 0.2],
            'timing_curve'   => [[0, 100], [15, 100], [30, 86], [45, 70], [60, 56], [75, 45],
                                 [90, 36], [120, 22], [180, 7], [240, 2]],
            'duration_curve' => [[0, 100], [20, 100], [30, 90], [45, 76], [60, 64], [90, 44],
                                 [120, 28], [180, 9], [240, 3]],
        ],

        /* Whatever the device measures about how the night went. Each night
           is scored on the measurements it has; a measurement no night has
           is simply not part of the score. These are broad, typical ranges
           for adults — not medical limits — and they are deliberately
           forgiving, because devices disagree with each other. */
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
       NUTRITION — the daily self-assessment, for now
       ================================================================== */
    'nutrition' => [
        /* A 1-10 rating onto 0-100: 1 -> 10, 5 -> 50, 7 -> 70, 10 -> 100. Several
           ratings on one day count as that day's average. Real nutrition data
           can be added as a second component later without touching this. */
        'rating_scale' => 10,
    ],

    /* ==================================================================
       TRAINING — volume 20%, intensity 20%, progression 25%, balance 35%
       More is not automatically better: volume flattens out, and balance —
       frequency, rest, load spikes, hard days back to back, sleep after
       training — carries the most weight.
       ================================================================== */
    'training' => [
        'weights' => ['volume' => 0.20, 'intensity' => 0.20, 'progression' => 0.25, 'balance' => 0.35],

        /* Workout minutes per week -> score. Steep at first, flat at the top,
           and slightly down again at extreme volumes. */
        'volume_curve' => [
            [0, 0], [30, 22], [60, 40], [90, 54], [120, 64], [150, 72], [225, 85],
            [300, 93], [420, 98], [540, 100], [720, 97], [900, 92],
        ],

        /* How hard the workouts were, from what was measured — perceived
           effort first, then heart-rate zones, then average heart rate
           against the maximum. A healthy mix scores best: all easy is fine
           but not ideal, and all hard is not better. */
        'intensity' => [
            'min_workouts'     => 3,
            'hard_share_curve' => [[0, 55], [0.1, 75], [0.2, 95], [0.3, 100], [0.4, 97],
                                   [0.5, 88], [0.7, 68], [1.0, 45]],
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
           distance, VO2max, and results on their own strength or
           performance goals. Relative change (recent half vs earlier half)
           -> score; holding steady is average. */
        'progression' => [
            'min_samples'     => 4,
            'min_km'          => 1.0,
            'goal_categories' => ['strength', 'performance', 'training'],
            /* VO2max readings and goal results must span at least this many
               days before they say anything about a trend. */
            'vo2_min_days'    => 14,
            'goal_min_days'   => 7,
            /* A single signal moves the average by at most this much, so one
               odd measurement cannot decide the whole component. */
            'max_change'      => 0.30,
            'change_curve'    => [[-0.15, 15], [-0.10, 25], [-0.05, 40], [-0.02, 52], [0, 60],
                                  [0.02, 70], [0.05, 84], [0.10, 95], [0.15, 100]],
        ],

        /* Training and recovery in balance. Each part is scored on its own
           and the balance is their weighted average; a part with no data is
           left out. Frequency counts double: rest days and the absence of
           load spikes are easy to have when there is hardly any training,
           and consistency is what this rewards. */
        'balance' => [
            'weights' => ['frequency' => 2, 'rest' => 1, 'spikes' => 1, 'hard_days' => 1, 'sleep' => 1],
            /* Training days per week -> score. */
            'frequency_curve' => [[0, 10], [0.5, 30], [1, 45], [2, 70], [3, 92], [4, 100], [5, 100],
                                  [5.5, 92], [6, 78], [6.5, 62], [7, 48]],
            /* Longest run of training days without a rest day -> score. */
            'streak_curve'    => [[1, 100], [3, 100], [4, 95], [5, 86], [6, 74], [7, 62],
                                  [10, 42], [14, 25], [21, 10]],
            /* A week whose training minutes jump past `spike_ratio` times the
               average of the four weeks before it is a load spike. Share of
               weeks that spiked -> score. */
            'spike_ratio'     => 1.5,
            'spike_curve'     => [[0, 100], [0.1, 85], [0.25, 65], [0.5, 40], [1, 20]],
            /* Weeks that can be judged, before load spikes are scored. */
            'spike_min_weeks' => 2,
            /* Share of heavy sessions that came the day after another heavy
               one -> score. */
            'hard_days_curve' => [[0, 100], [0.2, 85], [0.4, 65], [0.7, 40], [1, 25]],
            /* Heavy days needed before back-to-back ones are scored. */
            'hard_days_min'   => 3,
            /* Sleep in the nights after training days, on the sleep duration
               curve above, once there are this many such nights. */
            'sleep_min_nights' => 3,
        ],
    ],
];

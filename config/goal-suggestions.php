<?php
/**
 * Voorgesteld (docs/GOALS.md › Suggestions): what the goal wizard suggests to
 * one person, and with which targets. Ownify's own thresholds only — every
 * number about health comes from an approved research claim
 * (config/research.php), chosen by age with the same lists the insights use
 * (config/insights.php `rules`). No AI.
 *
 *   window_days   how far back the person's own data is read (complete days)
 *   min_days      how many days of a kind make a personal average
 *   list          how many measurements Voorgesteld shows
 *   per_category  at most this many from one category
 *   overlap       measurements that say much the same: an active goal on
 *                 one keeps the others out of Voorgesteld, and Voorgesteld
 *                 shows one of each group
 *   fallback      the order when nothing personal or age-specific is known
 *   patterns      combined insights (docs/CORRELATIONS.md) that make a
 *                 measurement more relevant, with the extra weight per label
 *   focus         the setup's focus: the categories it moves up
 */

declare(strict_types=1);

return [
    'window_days' => 28,
    'min_days' => [
        'sleep'     => 5,
        'daily'     => 5,
        'nutrition' => 3,
        'workouts'  => 2,      // recorded in the last 90 days at all
    ],
    'list'         => 6,
    'per_category' => 2,

    'overlap' => [
        ['workouts', 'workout_minutes'],
        ['steps', 'distance', 'active_energy', 'floors'],
    ],

    'fallback' => ['sleep_duration', 'steps', 'workouts', 'water', 'protein', 'fibre'],

    'patterns' => [
        'weights' => ['clear' => 8, 'pattern' => 5],
        'pairs' => [
            'C01' => ['workouts', 'sleep_duration'],
            'C02' => ['workouts'],
            'C03' => ['workouts'],
            'C04' => ['workouts'],
            'C05' => ['workouts'],
            'C06' => ['steps', 'sleep_duration'],
            'C07' => ['sleep_duration', 'steps'],
            'C08' => ['sleep_duration'],
            'C09' => ['sleep_duration', 'workouts'],
            'C10' => ['sleep_duration'],
            'C11' => ['sleep_duration'],
            'C12' => ['protein'],
            'C14' => ['water'],
            'C16' => ['workouts', 'sleep_duration'],
            'C17' => ['sleep_duration'],
        ],
    ],

    'focus' => [
        'weight'  => [],
        'sleep'   => ['sleep' => 8],
        'energy'  => ['sleep' => 5, 'nutrition' => 5],
        'fitness' => ['training' => 8, 'activity' => 8],
        'general' => [],
    ],

    /* The research claims per measurement, by age, in the shape of
       config/insights.php `rules`. Lists the insights already have are read
       from there (sleep.duration, activity.*, nutrition.*). */
    'claims' => [
        'water'           => [[13, null, 'nutrition.fluid']],
        /* 65+: the advice for older adults; 18–64 who train at least twice
           a week: the sport advice (Beperkt onderbouwd, and said so);
           everybody else 18+: the Gezondheidsraad's norm. */
        'protein_older'    => [[65, null, 'nutrition.protein.older']],
        'protein_exercise' => [[18, 64, 'nutrition.protein.exercise']],
        'protein'          => [[18, null, 'nutrition.protein']],
        'sugar'           => [[null, null, 'nutrition.free_sugars']],
        'resting_hr'      => [[18, null, 'heart.resting.risk']],
    ],
];

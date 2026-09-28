<?php
/**
 * Gezondheid — the three health areas, their metrics and their trends.
 *
 * ------------------------------------------------------------------
 * WHERE THE VALUES COME FROM
 * ------------------------------------------------------------------
 * This file is the page's shape: which areas exist, which metrics belong to
 * which group, what each is called and in what unit. Every value is null
 * here and is filled for the signed-in user by lib/hydrate-health.php, from
 * that user's own records.
 *
 * A metric nobody has recorded stays null and renders as an empty state. No
 * number in this file is a measurement, and none is a stand-in for one.
 *
 * ------------------------------------------------------------------
 * AVAILABILITY
 * ------------------------------------------------------------------
 *   'input'   comes from the user, not a sensor
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    'title' => 'Gezondheid',
    'lede'  => 'Je drie pijlers. Tik op een onderdeel voor de details.',

    /* Instead of the lede while there is no score at all yet (see
       lib/hydrate-health.php). %s = "3 dagen", "1 dag". */
    'lede_collecting' => 'Je hebt nog %s data nodig om een score te ontgrendelen.',

    /* ============================================================ metrics */
    'metrics' => [
        /* --- sleep ----------------------------------------------------- */
        'sleep_duration'   => ['label' => 'Slaapduur',       'unit' => 'u'],
        'time_in_bed'      => ['label' => 'Tijd in bed',     'unit' => 'u'],
        'sleep_efficiency' => ['label' => 'Efficiëntie',     'unit' => '%'],
        'bedtime'          => ['label' => 'Bedtijd',         'unit' => ''],
        'wake_time'        => ['label' => 'Wektijd',         'unit' => ''],
        'sleep_regularity' => ['label' => 'Regelmaat',       'unit' => '%'],
        'stage_deep'       => ['label' => 'Diep',            'unit' => 'min'],
        'stage_rem'        => ['label' => 'REM',             'unit' => 'min'],
        'stage_light'      => ['label' => 'Licht',           'unit' => 'min'],
        'stage_awake'      => ['label' => 'Wakker',          'unit' => 'min'],
        'awakenings'       => ['label' => 'Keer wakker',     'unit' => ''],
        'awake_time'       => ['label' => 'Tijd wakker',     'unit' => 'min'],
        'movement'         => ['label' => 'Beweging',        'unit' => '',     'availability' => 'device'],

        /* --- shared physiology (sleep + training) ---------------------- */
        'sleeping_hr'      => ['label' => 'Hartslag in slaap', 'unit' => 'bpm', 'availability' => 'device'],
        'resting_hr'       => ['label' => 'Rusthartslag',      'unit' => 'bpm', 'availability' => 'device'],
        'hrv'              => ['label' => 'HRV',               'unit' => 'ms',  'availability' => 'device'],
        'respiratory_rate' => ['label' => 'Ademhaling',        'unit' => '/min','availability' => 'device'],
        'skin_temp'        => ['label' => 'Huidtemperatuur',   'unit' => '°C',  'availability' => 'device'],
        'spo2'             => ['label' => 'Zuurstofsaturatie', 'unit' => '%',   'availability' => 'device'],

        /* --- nutrition -------------------------------------------------- */
        'meals'            => ['label' => 'Maaltijden',      'unit' => '',     'availability' => 'input'],
        'meal_window'      => ['label' => 'Eetvenster',      'unit' => '',     'availability' => 'input'],
        'self_rating'      => ['label' => 'Eigen beoordeling', 'unit' => '/10', 'availability' => 'input'],
        'water'            => ['label' => 'Water',           'unit' => 'l'],
        'hydration_goal'   => ['label' => 'Van je doel',     'unit' => '%'],
        'energy'           => ['label' => 'Energie',         'unit' => 'kcal'],
        'protein'          => ['label' => 'Eiwit',           'unit' => 'g'],
        'carbs'            => ['label' => 'Koolhydraten',    'unit' => 'g'],
        'fat'              => ['label' => 'Vetten',          'unit' => 'g'],
        'saturated_fat'    => ['label' => 'Verzadigd vet',   'unit' => 'g'],
        'fibre'            => ['label' => 'Vezels',          'unit' => 'g'],
        'sugar'            => ['label' => 'Suikers',         'unit' => 'g'],
        'sodium'           => ['label' => 'Natrium',         'unit' => 'mg'],

        /* --- training ---------------------------------------------------- */
        'steps'            => ['label' => 'Stappen',         'unit' => ''],
        'distance'         => ['label' => 'Afstand',         'unit' => 'km'],
        'active_energy'    => ['label' => 'Actieve calorieën', 'unit' => 'kcal'],
        'total_energy'     => ['label' => 'Totale calorieën', 'unit' => 'kcal'],
        'floors'           => ['label' => 'Verdiepingen',    'unit' => '',     'availability' => 'device'],
        'active_minutes'   => ['label' => 'Actieve minuten', 'unit' => 'min'],
        'sessions'         => ['label' => 'Sessies',         'unit' => ''],
        'session_duration' => ['label' => 'Trainingsduur',   'unit' => 'min'],
        'avg_hr'           => ['label' => 'Gem. hartslag',   'unit' => 'bpm',  'availability' => 'device'],
        'max_hr'           => ['label' => 'Max. hartslag',   'unit' => 'bpm',  'availability' => 'device'],
        'hr_zones'         => ['label' => 'Hartslagzones',   'unit' => '',     'availability' => 'device'],
        'vo2max'           => ['label' => 'VO₂max',          'unit' => 'ml/kg/min', 'availability' => 'device'],
        'cadence'          => ['label' => 'Cadans',          'unit' => 'spm',  'availability' => 'device'],
        'elevation'        => ['label' => 'Hoogtemeters',    'unit' => 'm',    'availability' => 'device'],
        'readiness'        => ['label' => 'Herstel',         'unit' => '/100', 'availability' => 'device'],
        'training_load'    => ['label' => 'Belasting',       'unit' => '',     'availability' => 'device'],
    ],

    /* ============================================================== areas */
    'areas' => [

        'sleep' => [
            'label'   => 'Slaap',
            'icon'    => 'moon',
            'accent'  => 'health',
            'score'   => ['value' => null, 'max' => 100],
            'summary' => 'Hoe je nacht is verlopen: duur, ritme en herstel.',
            'empty'   => 'Koppel een bron om je slaapscore te berekenen.',
            /* Some nights recorded, fewer than the score needs. %s = "3 dagen". */
            'collecting' => 'Nog %s met slaapgegevens nodig voor je slaapscore.',

            'highlights' => [
                ['key' => 'sleep_duration',   'value' => null],
                ['key' => 'bedtime',          'value' => null],
                ['key' => 'wake_time',        'value' => null],
                ['key' => 'sleep_efficiency', 'value' => null],
            ],

            'timeline' => [
                'title'  => 'Slaapverloop',
                'hint'   => 'De nacht van slaapfase tot slaapfase.',
                'stages' => [
                    ['key' => 'stage_deep',  'label' => 'Diep',   'tone' => 'deep',  'share' => null],
                    ['key' => 'stage_rem',   'label' => 'REM',    'tone' => 'rem',   'share' => null],
                    ['key' => 'stage_light', 'label' => 'Licht',  'tone' => 'light', 'share' => null],
                    ['key' => 'stage_awake', 'label' => 'Wakker', 'tone' => 'awake', 'share' => null],
                ],
            ],

            'groups' => [
                [
                    'title'   => 'Duur en timing',
                    'metrics' => [
                        ['key' => 'time_in_bed',      'value' => null],
                        ['key' => 'sleep_regularity', 'value' => null],
                    ],
                ],
                [
                    'title'   => 'Onderbrekingen',
                    'metrics' => [
                        ['key' => 'awakenings', 'value' => null],
                        ['key' => 'awake_time', 'value' => null],
                        ['key' => 'movement',   'value' => null],
                    ],
                ],
                [
                    'title'   => 'Nachtelijke waarden',
                    'hint'    => 'Komt beschikbaar zodra je een horloge of ring koppelt.',
                    'metrics' => [
                        ['key' => 'sleeping_hr',      'value' => null],
                        ['key' => 'resting_hr',       'value' => null],
                        ['key' => 'hrv',              'value' => null],
                        ['key' => 'respiratory_rate', 'value' => null],
                        ['key' => 'skin_temp',        'value' => null],
                        ['key' => 'spo2',             'value' => null],
                    ],
                ],
            ],
        ],

        'nutrition' => [
            'label'   => 'Voeding',
            'icon'    => 'utensils',
            'accent'  => 'nutrition',
            'score'   => ['value' => null, 'max' => 100],
            'summary' => 'Wat je eet en drinkt, en hoe regelmatig je dat doet.',
            'empty'   => 'Geef je voeding een dagcijfer om je voedingsscore te berekenen.',
            'collecting' => 'Nog %s met een dagcijfer nodig voor je voedingsscore.',

            /* The daily self-assessment the Nutrition score is built from.
               One cijfer per day; saving again replaces it. */
            'rating' => [
                'title'       => 'Hoe at je vandaag?',
                'label'       => 'Jouw dagcijfer, van 1 tot 10',
                'placeholder' => '1 tot 10',
                'button'      => 'Opslaan',
                'hint'        => 'Eén cijfer per dag. Opnieuw opslaan vervangt het cijfer van vandaag.',
            ],

            'highlights' => [
                ['key' => 'meals',       'value' => null],
                ['key' => 'water',       'value' => null],
                ['key' => 'protein',     'value' => null],
                ['key' => 'self_rating', 'value' => null],
            ],

            'groups' => [
                [
                    'title'   => 'Energie en macro\'s',
                    'metrics' => [
                        ['key' => 'energy',        'value' => null],
                        ['key' => 'protein',       'value' => null],
                        ['key' => 'carbs',         'value' => null],
                        ['key' => 'fat',           'value' => null],
                        ['key' => 'saturated_fat', 'value' => null],
                        ['key' => 'fibre',         'value' => null],
                        ['key' => 'sugar',         'value' => null],
                        ['key' => 'sodium',        'value' => null],
                    ],
                ],
                [
                    'title'   => 'Hydratatie',
                    'metrics' => [
                        ['key' => 'water',          'value' => null],
                        ['key' => 'hydration_goal', 'value' => null],
                    ],
                ],
                [
                    'title'   => 'Eigen invoer',
                    'hint'    => 'Jij bepaalt dit zelf — er komt geen sensor aan te pas.',
                    'metrics' => [
                        ['key' => 'self_rating', 'value' => null],
                        ['key' => 'meal_window', 'value' => null],
                    ],
                ],
            ],
        ],

        'training' => [
            'label'   => 'Training',
            'icon'    => 'dumbbell',
            'accent'  => 'activity',
            'score'   => ['value' => null, 'max' => 100],
            'summary' => 'Wat je hebt bewogen, hoe zwaar het was en hoe je herstelt.',
            'empty'   => 'Koppel een bron om je trainingsscore te berekenen.',
            'collecting' => 'Nog %s met een training nodig voor je trainingsscore.',

            'highlights' => [
                ['key' => 'steps',            'value' => null],
                ['key' => 'active_energy',    'value' => null],
                ['key' => 'session_duration', 'value' => null],
                ['key' => 'distance',         'value' => null],
            ],

            'groups' => [
                [
                    'title'   => 'Activiteit',
                    'metrics' => [
                        ['key' => 'steps',          'value' => null],
                        ['key' => 'distance',       'value' => null],
                        ['key' => 'active_energy',  'value' => null],
                        ['key' => 'total_energy',   'value' => null],
                        ['key' => 'active_minutes', 'value' => null],
                        ['key' => 'floors',         'value' => null],
                    ],
                ],
                [
                    'title'   => 'Trainingen',
                    'metrics' => [
                        ['key' => 'sessions',         'value' => null],
                        ['key' => 'session_duration', 'value' => null],
                        ['key' => 'avg_hr',           'value' => null],
                        ['key' => 'max_hr',           'value' => null],
                        ['key' => 'hr_zones',         'value' => null],
                    ],
                ],
                [
                    'title'   => 'Conditie en herstel',
                    'hint'    => 'Komt beschikbaar zodra je een horloge of ring koppelt.',
                    'metrics' => [
                        ['key' => 'vo2max',        'value' => null],
                        ['key' => 'resting_hr',    'value' => null],
                        ['key' => 'hrv',           'value' => null],
                        ['key' => 'readiness',     'value' => null],
                        ['key' => 'training_load', 'value' => null],
                        ['key' => 'cadence',       'value' => null],
                        ['key' => 'elevation',     'value' => null],
                    ],
                ],
            ],
        ],
    ],

    /* ============================================================== trend */
    'trend' => [
        'title'  => 'Verloop',
        'empty'  => 'Zodra er meetmomenten zijn, verschijnt hier je verloop.',
        'ranges' => [
            'week'  => ['label' => 'Week',  'points' => ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo']],
            'month' => ['label' => 'Maand', 'points' => ['Week 1', 'Week 2', 'Week 3', 'Week 4']],
        ],
        'series' => [
            'sleep' => [
                'week'  => ['values' => [null, null, null, null, null, null, null]],
                'month' => ['values' => [null, null, null, null]],
            ],
            'nutrition' => [
                'week'  => ['values' => [null, null, null, null, null, null, null]],
                'month' => ['values' => [null, null, null, null]],
            ],
            'training' => [
                'week'  => ['values' => [null, null, null, null, null, null, null]],
                'month' => ['values' => [null, null, null, null]],
            ],
        ],
    ],
];

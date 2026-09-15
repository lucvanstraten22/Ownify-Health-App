<?php
/**
 * Gezondheid — the three health areas, their metrics and their trends.
 *
 * ------------------------------------------------------------------
 * PLACEHOLDER CONTRACT (same as the rest of the app)
 * ------------------------------------------------------------------
 * Nothing here is a real measurement. Every `value` is null, so the UI
 * renders honest empty states.
 *
 * `demo` holds review-only numbers. They are used ONLY when 'demo' below is
 * true, which lets the design be looked at with the charts populated without
 * ever shipping invented health results. Leave it false.
 *
 * ------------------------------------------------------------------
 * SHARED METRICS
 * ------------------------------------------------------------------
 * A metric is defined once in `metrics` and referenced by key from any area,
 * so HRV means the same thing — and looks the same — in Slaap and in Training
 * without being defined twice.
 *
 * `availability`:
 *   null      always shown; renders "—" until there is data
 *   'device'  needs a connected wearable; grouped and dimmed until then
 *   'input'   comes from the user, not a sensor
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    'demo'  => false,

    'title' => 'Gezondheid',
    'lede'  => 'Je drie pijlers. Tik op een onderdeel voor de details.',

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
        'self_rating'      => ['label' => 'Eigen beoordeling', 'unit' => '/5', 'availability' => 'input'],
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
            'score'   => ['value' => null, 'demo' => 84, 'max' => 100],
            'summary' => 'Hoe je nacht is verlopen: duur, ritme en herstel.',
            'empty'   => 'Koppel een bron om je slaapscore te berekenen.',

            'highlights' => [
                ['key' => 'sleep_duration',   'value' => null, 'demo' => '7:24'],
                ['key' => 'bedtime',          'value' => null, 'demo' => '23:10'],
                ['key' => 'wake_time',        'value' => null, 'demo' => '06:42'],
                ['key' => 'sleep_efficiency', 'value' => null, 'demo' => 91],
            ],

            'timeline' => [
                'title'  => 'Slaapverloop',
                'hint'   => 'De nacht van slaapfase tot slaapfase.',
                'stages' => [
                    ['key' => 'stage_deep',  'label' => 'Diep',   'tone' => 'deep',  'share' => null, 'demo' => 22],
                    ['key' => 'stage_rem',   'label' => 'REM',    'tone' => 'rem',   'share' => null, 'demo' => 22],
                    ['key' => 'stage_light', 'label' => 'Licht',  'tone' => 'light', 'share' => null, 'demo' => 52],
                    ['key' => 'stage_awake', 'label' => 'Wakker', 'tone' => 'awake', 'share' => null, 'demo' => 4],
                ],
            ],

            'groups' => [
                [
                    'title'   => 'Duur en timing',
                    'metrics' => [
                        ['key' => 'time_in_bed',      'value' => null, 'demo' => '8:05'],
                        ['key' => 'sleep_regularity', 'value' => null, 'demo' => 78],
                    ],
                ],
                [
                    'title'   => 'Onderbrekingen',
                    'metrics' => [
                        ['key' => 'awakenings', 'value' => null, 'demo' => 2],
                        ['key' => 'awake_time', 'value' => null, 'demo' => 14],
                        ['key' => 'movement',   'value' => null, 'demo' => 'Laag'],
                    ],
                ],
                [
                    'title'   => 'Nachtelijke waarden',
                    'hint'    => 'Komt beschikbaar zodra je een horloge of ring koppelt.',
                    'metrics' => [
                        ['key' => 'sleeping_hr',      'value' => null, 'demo' => 52],
                        ['key' => 'resting_hr',       'value' => null, 'demo' => 49],
                        ['key' => 'hrv',              'value' => null, 'demo' => 58],
                        ['key' => 'respiratory_rate', 'value' => null, 'demo' => 14.2],
                        ['key' => 'skin_temp',        'value' => null, 'demo' => 36.4],
                        ['key' => 'spo2',             'value' => null, 'demo' => 97],
                    ],
                ],
            ],
        ],

        'nutrition' => [
            'label'   => 'Voeding',
            'icon'    => 'leaf',
            'accent'  => 'nutrition',
            'score'   => ['value' => null, 'demo' => 76, 'max' => 100],
            'summary' => 'Wat je eet en drinkt, en hoe regelmatig je dat doet.',
            'empty'   => 'Voeg maaltijden toe of koppel een bron voor je voedingsscore.',

            'highlights' => [
                ['key' => 'meals',       'value' => null, 'demo' => 3],
                ['key' => 'water',       'value' => null, 'demo' => 1.8],
                ['key' => 'protein',     'value' => null, 'demo' => 96],
                ['key' => 'self_rating', 'value' => null, 'demo' => 4],
            ],

            'groups' => [
                [
                    'title'   => 'Energie en macro\'s',
                    'metrics' => [
                        ['key' => 'energy',        'value' => null, 'demo' => 2140],
                        ['key' => 'protein',       'value' => null, 'demo' => 96],
                        ['key' => 'carbs',         'value' => null, 'demo' => 221],
                        ['key' => 'fat',           'value' => null, 'demo' => 78],
                        ['key' => 'saturated_fat', 'value' => null, 'demo' => 21],
                        ['key' => 'fibre',         'value' => null, 'demo' => 28],
                        ['key' => 'sugar',         'value' => null, 'demo' => 54],
                        ['key' => 'sodium',        'value' => null, 'demo' => 2300],
                    ],
                ],
                [
                    'title'   => 'Hydratatie',
                    'metrics' => [
                        ['key' => 'water',          'value' => null, 'demo' => 1.8],
                        ['key' => 'hydration_goal', 'value' => null, 'demo' => 72],
                    ],
                ],
                [
                    'title'   => 'Eigen invoer',
                    'hint'    => 'Jij bepaalt dit zelf — er komt geen sensor aan te pas.',
                    'metrics' => [
                        ['key' => 'self_rating', 'value' => null, 'demo' => 4],
                        ['key' => 'meal_window', 'value' => null, 'demo' => '08:10 – 20:35'],
                    ],
                ],
            ],
        ],

        'training' => [
            'label'   => 'Training',
            'icon'    => 'dumbbell',
            'accent'  => 'activity',
            'score'   => ['value' => null, 'demo' => 91, 'max' => 100],
            'summary' => 'Wat je hebt bewogen, hoe zwaar het was en hoe je herstelt.',
            'empty'   => 'Koppel een bron om je trainingsscore te berekenen.',

            'highlights' => [
                ['key' => 'steps',            'value' => null, 'demo' => 9420],
                ['key' => 'active_energy',    'value' => null, 'demo' => 612],
                ['key' => 'session_duration', 'value' => null, 'demo' => 48],
                ['key' => 'distance',         'value' => null, 'demo' => 7.1],
            ],

            'groups' => [
                [
                    'title'   => 'Activiteit',
                    'metrics' => [
                        ['key' => 'steps',          'value' => null, 'demo' => 9420],
                        ['key' => 'distance',       'value' => null, 'demo' => 7.1],
                        ['key' => 'active_energy',  'value' => null, 'demo' => 612],
                        ['key' => 'total_energy',   'value' => null, 'demo' => 2480],
                        ['key' => 'active_minutes', 'value' => null, 'demo' => 74],
                        ['key' => 'floors',         'value' => null, 'demo' => 12],
                    ],
                ],
                [
                    'title'   => 'Trainingen',
                    'metrics' => [
                        ['key' => 'sessions',         'value' => null, 'demo' => 1],
                        ['key' => 'session_duration', 'value' => null, 'demo' => 48],
                        ['key' => 'avg_hr',           'value' => null, 'demo' => 138],
                        ['key' => 'max_hr',           'value' => null, 'demo' => 171],
                        ['key' => 'hr_zones',         'value' => null, 'demo' => 'Z2 · Z3'],
                    ],
                ],
                [
                    'title'   => 'Conditie en herstel',
                    'hint'    => 'Komt beschikbaar zodra je een horloge of ring koppelt.',
                    'metrics' => [
                        ['key' => 'vo2max',        'value' => null, 'demo' => 48],
                        ['key' => 'resting_hr',    'value' => null, 'demo' => 49],
                        ['key' => 'hrv',           'value' => null, 'demo' => 58],
                        ['key' => 'readiness',     'value' => null, 'demo' => 82],
                        ['key' => 'training_load', 'value' => null, 'demo' => 'In balans'],
                        ['key' => 'cadence',       'value' => null, 'demo' => 168],
                        ['key' => 'elevation',     'value' => null, 'demo' => 96],
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
                'week'  => ['values' => [null, null, null, null, null, null, null], 'demo' => [72, 78, 74, 81, 79, 88, 84]],
                'month' => ['values' => [null, null, null, null], 'demo' => [74, 78, 81, 84]],
            ],
            'nutrition' => [
                'week'  => ['values' => [null, null, null, null, null, null, null], 'demo' => [68, 71, 80, 66, 73, 62, 76]],
                'month' => ['values' => [null, null, null, null], 'demo' => [70, 68, 74, 76]],
            ],
            'training' => [
                'week'  => ['values' => [null, null, null, null, null, null, null], 'demo' => [84, 62, 90, 88, 70, 94, 91]],
                'month' => ['values' => [null, null, null, null], 'demo' => [79, 83, 86, 91]],
            ],
        ],
    ],
];

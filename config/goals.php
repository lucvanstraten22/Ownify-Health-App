<?php
/**
 * Doelen — the vocabulary a goal is built from, and its copy.
 *
 * ------------------------------------------------------------------
 * WHERE THE GOALS COME FROM
 * ------------------------------------------------------------------
 * This file holds no goals. The goals are rows in `goals` (migration 025,
 * docs/GOALS.md), read for the signed-in user by lib/hydrate-goals.php and
 * written by api/goals/*.php. A user with none gets the empty state below.
 *
 * ------------------------------------------------------------------
 * THE CATALOGUE
 * ------------------------------------------------------------------
 * Six categories, and under them the measurements a goal can be about. Each
 * measurement says where its figures come from (`source_kind`/`source_key`:
 * health data Ownify really reads, or `manual` — kept by hand on the goal's
 * page), the unit it is stored in (`unit_code`) and the units it can be
 * shown and typed in (`unit_codes`, the metric default first;
 * `imperial_unit` for Imperiaal), which goal types make sense for it, and
 * which way is better (`direction`: increase, decrease or both — only
 * `both` asks the person).
 *
 * The codes here are the ones migration 025 seeds into goal_categories,
 * goal_units and goal_measurements, so a goal row can only name something
 * this file knows; tools/goal-engine-test.php checks the two agree.
 *
 * Everything else — the labels, the examples, the copy — is Dutch, and is
 * read in the reader's language (lang/en/goals.php, docs/I18N.md).
 */

declare(strict_types=1);

return [

    'title' => 'Doelen',
    'lede'  => 'Waar je aan werkt, en hoe ver je bent.',

    /* ------------------------------------------------------------ limits */
    /* Active goals at most, paused ones included: one primary, the rest
       secondary. `active` is the one number the pages, the app, the assistant
       and the server read (GOAL_MAX_ACTIVE in includes/goals.php). */
    'limits' => [
        'active'    => 5,
        'primary'   => 1,
    ],

    /* ------------------------------------------------------------- views */
    'views' => [
        'active'    => ['label' => 'Actief'],
        'completed' => ['label' => 'Afgerond'],
    ],

    'default_view' => 'active',

    /* -------------------------------------------------------- categories */
    /* The six, in this order everywhere (goal_categories.sort_order). */
    'categories' => [
        'sleep'     => ['label' => 'Slaap',            'icon' => 'moon',     'accent' => 'sleep'],
        'nutrition' => ['label' => 'Voeding',          'icon' => 'utensils', 'accent' => 'nutrition'],
        'training'  => ['label' => 'Training',         'icon' => 'dumbbell', 'accent' => 'training'],
        'activity'  => ['label' => 'Activiteit',       'icon' => 'bolt',     'accent' => 'training'],
        'body'      => ['label' => 'Lichaam',          'icon' => 'ruler',    'accent' => 'nutrition'],
        'recovery'  => ['label' => 'Herstel & balans', 'icon' => 'heart',    'accent' => 'health'],
    ],

    /* ------------------------------------------------------------- units */
    /*
     * Every unit a goal can be shown and typed in. `canonical` is the unit
     * the figure is stored in, and `factor` turns a typed figure into it:
     * stored = typed × factor (5 lb × 0.45359237 = 2.27 kg). What is stored
     * never depends on the unit somebody chose to see it in.
     *
     * `label`/`one`: the word, and its singular where it has one;
     * `attached`: written against the number ("85%"); `decimals`: at most.
     */
    'goal_units' => [
        'kg'       => ['label' => 'kg',            'canonical' => 'kg',       'factor' => 1.0,                'decimals' => 1],
        'lb'       => ['label' => 'lb',            'canonical' => 'kg',       'factor' => 0.45359237,         'decimals' => 1],
        'cm'       => ['label' => 'cm',            'canonical' => 'cm',       'factor' => 1.0,                'decimals' => 1],
        'in'       => ['label' => 'inch',          'canonical' => 'cm',       'factor' => 2.54,               'decimals' => 1],
        'km'       => ['label' => 'km',            'canonical' => 'km',       'factor' => 1.0,                'decimals' => 1],
        'mi'       => ['label' => 'mi',            'canonical' => 'km',       'factor' => 1.609344,           'decimals' => 1],
        'min'      => ['label' => 'min',           'canonical' => 'min',      'factor' => 1.0,                'decimals' => 0],
        'h'        => ['label' => 'uur',           'canonical' => 'min',      'factor' => 60.0,               'decimals' => 1],
        'l'        => ['label' => 'liter',         'canonical' => 'l',        'factor' => 1.0,                'decimals' => 1],
        'ml'       => ['label' => 'ml',            'canonical' => 'l',        'factor' => 0.001,              'decimals' => 0],
        'floz'     => ['label' => 'fl oz',         'canonical' => 'l',        'factor' => 0.0295735295625,    'decimals' => 0],
        'kcal'     => ['label' => 'kcal',          'canonical' => 'kcal',     'factor' => 1.0,                'decimals' => 0],
        'kj'       => ['label' => 'kJ',            'canonical' => 'kcal',     'factor' => 0.239005736137667,  'decimals' => 0],
        'g'        => ['label' => 'g',             'canonical' => 'g',        'factor' => 1.0,                'decimals' => 0],
        'mg'       => ['label' => 'mg',            'canonical' => 'mg',       'factor' => 1.0,                'decimals' => 0],
        'pct'      => ['label' => '%',             'canonical' => 'pct',      'factor' => 1.0,                'decimals' => 1, 'attached' => true],
        'bpm'      => ['label' => 'bpm',           'canonical' => 'bpm',      'factor' => 1.0,                'decimals' => 0],
        'ms'       => ['label' => 'ms',            'canonical' => 'ms',       'factor' => 1.0,                'decimals' => 0],
        'score10'  => ['label' => '/10',           'canonical' => 'score10',  'factor' => 1.0,                'decimals' => 1, 'attached' => true],
        'steps'    => ['label' => 'stappen',       'one' => 'stap',      'canonical' => 'steps',    'factor' => 1.0, 'decimals' => 0],
        'floors'   => ['label' => 'verdiepingen',  'one' => 'verdieping', 'canonical' => 'floors', 'factor' => 1.0, 'decimals' => 0],
        'sessions' => ['label' => 'trainingen',    'one' => 'training',  'canonical' => 'sessions', 'factor' => 1.0, 'decimals' => 0],
        'times'    => ['label' => 'keer',          'canonical' => 'times',    'factor' => 1.0,                'decimals' => 0],
        'pages'    => ['label' => "pagina's",      'one' => 'pagina',    'canonical' => 'pages',    'factor' => 1.0, 'decimals' => 0],
        'glasses'  => ['label' => 'glazen',        'one' => 'glas',      'canonical' => 'glasses',  'factor' => 1.0, 'decimals' => 0],
        'reps'     => ['label' => 'herhalingen',   'one' => 'herhaling', 'canonical' => 'reps',     'factor' => 1.0, 'decimals' => 0],
    ],

    /* ------------------------------------------------------- measurements */
    /*
     * What a goal can be about, by category, in the order the wizard lists
     * them. Only what Ownify really reads is automatic: Health Connect and
     * Polar (docs/HEALTH-CONNECT.md, docs/POLAR.md), the profile's weigh-ins,
     * the day's own voedingsbeoordeling. Meditatie has no source, so it is
     * kept by hand on the goal's page; so is an Eigen gewoonte.
     *
     *   types      milestone, streak, accumulate — which make sense here
     *   variants   a streak's: daily (a target every day) and/or period
     *              (a number of times per week, month, quarter or year —
     *              only for what can be counted: `count` is its word)
     *   daily      the source is a day's total that builds up (steps):
     *              today counts for a Mijlpaal once it beats the last
     *              whole day (includes/goal-progress.php)
     *   popular    its place among the first measurements the wizard shows
     *   examples   per type, what the type card says for this measurement
     *   names      name suggestions for step two
     *   keywords   what search also finds it by, in both languages
     */
    'measurements' => [

        /* ---------------------------------------------------------- slaap */
        'sleep_duration' => [
            'label' => 'Slaapduur', 'category' => 'sleep',
            'source_kind' => 'metric', 'source_key' => 'sleep_duration',
            'unit_code' => 'min', 'unit_codes' => ['h', 'min'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'both', 'default_direction' => 'increase',
            'popular' => 2,
            'keywords' => ['slapen', 'nacht', 'uren', 'sleep', 'night', 'hours'],
            'names' => ['Elke nacht 8 uur slapen', 'Meer slaap', 'Een nacht van 8 uur'],
            'examples' => [
                'milestone' => 'Een nacht van 8 uur',
                'streak'    => 'Elke nacht minstens 7,5 uur',
            ],
            'placeholder' => ['milestone' => 8, 'streak' => 7.5],
        ],
        'sleep_efficiency' => [
            'label' => 'Slaapefficiëntie', 'category' => 'sleep',
            'source_kind' => 'metric', 'source_key' => 'sleep_efficiency',
            'unit_code' => 'pct', 'unit_codes' => ['pct'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['efficientie', 'kwaliteit', 'efficiency', 'quality'],
            'names' => ['Efficiënter slapen', 'Elke nacht 85% efficiëntie'],
            'examples' => [
                'milestone' => 'Een nacht met 90% efficiëntie',
                'streak'    => 'Elke nacht minstens 85%',
            ],
            'placeholder' => ['milestone' => 90, 'streak' => 85],
        ],

        /* -------------------------------------------------------- voeding */
        'water' => [
            'label' => 'Water', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'water',
            'unit_code' => 'l', 'unit_codes' => ['l', 'ml', 'floz'], 'imperial_unit' => 'floz',
            'types' => ['streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'popular' => 5,
            'keywords' => ['drinken', 'hydratatie', 'drink', 'hydration'],
            'names' => ['Elke dag 2 liter water', 'Meer water drinken'],
            'examples' => [
                'streak'     => 'Elke dag 2 liter',
                'accumulate' => '60 liter deze maand',
            ],
            'placeholder' => ['streak' => 2, 'accumulate' => 60],
        ],
        'energy' => [
            'label' => 'Calorieën', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'energy',
            'unit_code' => 'kcal', 'unit_codes' => ['kcal', 'kj'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'both', 'default_direction' => 'decrease',
            'keywords' => ['kcal', 'energie', 'eten', 'calories', 'energy', 'food'],
            'names' => ['Elke dag hoogstens 2.200 kcal', 'Bewuster eten'],
            'examples' => ['streak' => 'Elke dag hoogstens 2.200 kcal'],
            'placeholder' => ['streak' => 2200],
        ],
        'protein' => [
            'label' => 'Eiwit', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'protein',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['eiwitten', 'proteine', 'protein'],
            'names' => ['Elke dag 120 g eiwit', 'Meer eiwit eten'],
            'examples' => ['streak' => 'Elke dag minstens 120 g'],
            'placeholder' => ['streak' => 120],
        ],
        'carbs' => [
            'label' => 'Koolhydraten', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'carbs',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'both', 'default_direction' => 'decrease',
            'keywords' => ['carbs', 'suikers', 'carbohydrates'],
            'names' => ['Elke dag hoogstens 200 g koolhydraten'],
            'examples' => ['streak' => 'Elke dag hoogstens 200 g'],
            'placeholder' => ['streak' => 200],
        ],
        'fat' => [
            'label' => 'Vet', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'fat',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'both', 'default_direction' => 'decrease',
            'keywords' => ['vetten', 'fat'],
            'names' => ['Elke dag hoogstens 70 g vet'],
            'examples' => ['streak' => 'Elke dag hoogstens 70 g'],
            'placeholder' => ['streak' => 70],
        ],
        'fibre' => [
            'label' => 'Vezels', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'fibre',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['vezel', 'fibre', 'fiber'],
            'names' => ['Elke dag 30 g vezels'],
            'examples' => ['streak' => 'Elke dag minstens 30 g'],
            'placeholder' => ['streak' => 30],
        ],
        'sugar' => [
            'label' => 'Suiker', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'sugar',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'decrease', 'default_direction' => 'decrease',
            'keywords' => ['suikers', 'zoet', 'sugar'],
            'names' => ['Minder suiker', 'Elke dag hoogstens 30 g suiker'],
            'examples' => ['streak' => 'Elke dag hoogstens 30 g'],
            'placeholder' => ['streak' => 30],
        ],
        'saturated_fat' => [
            'label' => 'Verzadigd vet', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'saturated_fat',
            'unit_code' => 'g', 'unit_codes' => ['g'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'decrease', 'default_direction' => 'decrease',
            'keywords' => ['vet', 'saturated'],
            'names' => ['Elke dag hoogstens 20 g verzadigd vet'],
            'examples' => ['streak' => 'Elke dag hoogstens 20 g'],
            'placeholder' => ['streak' => 20],
        ],
        'sodium' => [
            'label' => 'Natrium', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'sodium',
            'unit_code' => 'mg', 'unit_codes' => ['mg'],
            'types' => ['streak'], 'variants' => ['daily'],
            'direction' => 'decrease', 'default_direction' => 'decrease',
            'keywords' => ['zout', 'salt', 'sodium'],
            'names' => ['Minder zout', 'Elke dag hoogstens 2.000 mg natrium'],
            'examples' => ['streak' => 'Elke dag hoogstens 2.000 mg'],
            'placeholder' => ['streak' => 2000],
        ],
        'nutrition_rating' => [
            'label' => 'Voedingsbeoordeling', 'category' => 'nutrition',
            'source_kind' => 'metric', 'source_key' => 'nutrition_rating',
            'unit_code' => 'score10', 'unit_codes' => ['score10'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['beoordeling', 'gezond eten', 'rating', 'healthy eating'],
            'names' => ['Gezonder eten', 'Elke dag minstens een 7'],
            'examples' => [
                'milestone' => 'Een dag met een 8',
                'streak'    => 'Elke dag minstens een 7',
            ],
            'placeholder' => ['milestone' => 8, 'streak' => 7],
        ],

        /* ------------------------------------------------------- training */
        'workouts' => [
            'label' => 'Trainingen', 'category' => 'training',
            'source_kind' => 'workout', 'source_key' => 'sessions',
            'unit_code' => 'sessions', 'unit_codes' => ['sessions'],
            'types' => ['streak', 'accumulate'], 'variants' => ['period', 'daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'count' => 'trainingen',
            'popular' => 3,
            'keywords' => ['sporten', 'workout', 'trainen', 'sport', 'exercise', 'gym'],
            'names' => ['3 keer per week trainen', '50 trainingen dit jaar'],
            'examples' => [
                'streak'     => '3 trainingen per week',
                'accumulate' => '50 trainingen dit jaar',
            ],
            'placeholder' => ['streak' => 1, 'accumulate' => 50, 'period' => 3],
        ],
        'workout_minutes' => [
            'label' => 'Trainingsminuten', 'category' => 'training',
            'source_kind' => 'workout', 'source_key' => 'minutes',
            'unit_code' => 'min', 'unit_codes' => ['min', 'h'],
            'types' => ['streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['minuten', 'trainingstijd', 'minutes', 'workout time'],
            'names' => ['Elke dag 30 minuten bewegen', '600 trainingsminuten'],
            'examples' => [
                'streak'     => 'Elke dag 30 minuten',
                'accumulate' => '600 minuten deze maand',
            ],
            'placeholder' => ['streak' => 30, 'accumulate' => 600],
        ],

        /* ----------------------------------------------------- activiteit */
        'steps' => [
            'label' => 'Stappen', 'category' => 'activity',
            'source_kind' => 'metric', 'source_key' => 'steps',
            'unit_code' => 'steps', 'unit_codes' => ['steps'],
            'types' => ['milestone', 'streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'daily' => true,
            'popular' => 1,
            'keywords' => ['lopen', 'wandelen', 'walk', 'walking', 'steps'],
            'names' => ['10.000 stappen per dag', '15.000 stappen op één dag', '200.000 stappen deze maand'],
            'examples' => [
                'milestone'  => '15.000 stappen op één dag',
                'streak'     => 'Elke dag 10.000 stappen',
                'accumulate' => '200.000 stappen deze maand',
            ],
            'placeholder' => ['milestone' => 15000, 'streak' => 10000, 'accumulate' => 200000],
        ],
        'distance' => [
            'label' => 'Afstand', 'category' => 'activity',
            'source_kind' => 'metric', 'source_key' => 'distance',
            'unit_code' => 'km', 'unit_codes' => ['km', 'mi'], 'imperial_unit' => 'mi',
            'types' => ['milestone', 'streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'daily' => true,
            'keywords' => ['kilometers', 'wandelen', 'hardlopen', 'running', 'distance', 'miles'],
            'names' => ['100 km deze maand', 'Elke dag 5 km', '10 km op één dag'],
            'examples' => [
                'milestone'  => '10 km op één dag',
                'streak'     => 'Elke dag 5 km',
                'accumulate' => '100 km deze maand',
            ],
            'placeholder' => ['milestone' => 10, 'streak' => 5, 'accumulate' => 100],
        ],
        'active_energy' => [
            'label' => 'Actieve calorieën', 'category' => 'activity',
            'source_kind' => 'metric', 'source_key' => 'active_energy',
            'unit_code' => 'kcal', 'unit_codes' => ['kcal', 'kj'],
            'types' => ['streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'daily' => true,
            'keywords' => ['verbranden', 'kcal', 'burn', 'active calories'],
            'names' => ['Elke dag 500 kcal verbranden', '15.000 actieve kcal'],
            'examples' => [
                'streak'     => 'Elke dag 500 kcal',
                'accumulate' => '15.000 kcal deze maand',
            ],
            'placeholder' => ['streak' => 500, 'accumulate' => 15000],
        ],
        'floors' => [
            'label' => 'Verdiepingen', 'category' => 'activity',
            'source_kind' => 'metric', 'source_key' => 'floors',
            'unit_code' => 'floors', 'unit_codes' => ['floors'],
            'types' => ['milestone', 'streak', 'accumulate'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'daily' => true,
            'keywords' => ['trappen', 'traplopen', 'stairs', 'floors'],
            'names' => ['Elke dag 10 verdiepingen', 'Vaker de trap'],
            'examples' => [
                'milestone'  => '30 verdiepingen op één dag',
                'streak'     => 'Elke dag 10 verdiepingen',
                'accumulate' => '300 verdiepingen deze maand',
            ],
            'placeholder' => ['milestone' => 30, 'streak' => 10, 'accumulate' => 300],
        ],

        /* -------------------------------------------------------- lichaam */
        'weight' => [
            'label' => 'Gewicht', 'category' => 'body',
            'source_kind' => 'measurement', 'source_key' => 'weight',
            'unit_code' => 'kg', 'unit_codes' => ['kg', 'lb'], 'imperial_unit' => 'lb',
            'types' => ['milestone'], 'variants' => [],
            'direction' => 'both', 'default_direction' => 'decrease',
            'popular' => 4,
            'keywords' => ['afvallen', 'aankomen', 'wegen', 'kilo', 'weight', 'lose weight'],
            'names' => ['Naar 75 kg', '3 kg afvallen', '5 kg aankomen'],
            'examples' => ['milestone' => 'Van 80 naar 75 kg'],
            'placeholder' => ['milestone' => 75],
        ],
        'body_fat_pct' => [
            'label' => 'Vetpercentage', 'category' => 'body',
            'source_kind' => 'measurement', 'source_key' => 'body_fat_pct',
            'unit_code' => 'pct', 'unit_codes' => ['pct'],
            'types' => ['milestone'], 'variants' => [],
            'direction' => 'both', 'default_direction' => 'decrease',
            'keywords' => ['vet', 'body fat'],
            'names' => ['Naar 18% vet', 'Lager vetpercentage'],
            'examples' => ['milestone' => 'Van 22% naar 18%'],
            'placeholder' => ['milestone' => 18],
        ],
        'waist_cm' => [
            'label' => 'Tailleomtrek', 'category' => 'body',
            'source_kind' => 'measurement', 'source_key' => 'waist_cm',
            'unit_code' => 'cm', 'unit_codes' => ['cm', 'in'], 'imperial_unit' => 'in',
            'types' => ['milestone'], 'variants' => [],
            'direction' => 'both', 'default_direction' => 'decrease',
            'keywords' => ['taille', 'buik', 'waist'],
            'names' => ['Taille naar 85 cm', 'Smallere taille'],
            'examples' => ['milestone' => 'Van 92 naar 85 cm'],
            'placeholder' => ['milestone' => 85],
        ],
        'lean_mass' => [
            'label' => 'Vetvrije massa', 'category' => 'body',
            'source_kind' => 'measurement', 'source_key' => 'lean_mass',
            'unit_code' => 'kg', 'unit_codes' => ['kg', 'lb'], 'imperial_unit' => 'lb',
            'types' => ['milestone'], 'variants' => [],
            'direction' => 'both', 'default_direction' => 'increase',
            'keywords' => ['spiermassa', 'spieren', 'muscle', 'lean mass'],
            'names' => ['Meer spiermassa', 'Naar 65 kg vetvrije massa'],
            'examples' => ['milestone' => 'Van 60 naar 65 kg'],
            'placeholder' => ['milestone' => 65],
        ],

        /* ------------------------------------------------ herstel & balans */
        'resting_hr' => [
            'label' => 'Rusthartslag', 'category' => 'recovery',
            'source_kind' => 'metric', 'source_key' => 'resting_hr',
            'unit_code' => 'bpm', 'unit_codes' => ['bpm'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'decrease', 'default_direction' => 'decrease',
            'popular' => 6,
            'keywords' => ['hartslag', 'hart', 'heart rate', 'resting heart rate'],
            'names' => ['Rusthartslag naar 55', 'Lagere rusthartslag'],
            'examples' => [
                'milestone' => 'Van 64 naar 55 bpm',
                'streak'    => 'Elke dag hoogstens 60 bpm',
            ],
            'placeholder' => ['milestone' => 55, 'streak' => 60],
        ],
        'hrv' => [
            'label' => 'HRV', 'category' => 'recovery',
            'source_kind' => 'metric', 'source_key' => 'hrv',
            'unit_code' => 'ms', 'unit_codes' => ['ms'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'keywords' => ['hartslagvariabiliteit', 'herstel', 'heart rate variability', 'recovery'],
            'names' => ['Hogere HRV', 'HRV naar 60 ms'],
            'examples' => [
                'milestone' => 'Van 45 naar 60 ms',
                'streak'    => 'Elke dag minstens 50 ms',
            ],
            'placeholder' => ['milestone' => 60, 'streak' => 50],
        ],
        'sleeping_hr' => [
            'label' => 'Hartslag in slaap', 'category' => 'recovery',
            'source_kind' => 'metric', 'source_key' => 'sleeping_hr',
            'unit_code' => 'bpm', 'unit_codes' => ['bpm'],
            'types' => ['milestone', 'streak'], 'variants' => ['daily'],
            'direction' => 'decrease', 'default_direction' => 'decrease',
            'keywords' => ['nachtelijke hartslag', 'sleeping heart rate'],
            'names' => ['Rustiger hart in de nacht'],
            'examples' => [
                'milestone' => 'Van 60 naar 54 bpm',
                'streak'    => 'Elke nacht hoogstens 58 bpm',
            ],
            'placeholder' => ['milestone' => 54, 'streak' => 58],
        ],
        'meditation' => [
            'label' => 'Meditatie', 'category' => 'recovery',
            'source_kind' => 'manual', 'source_key' => null,
            'unit_code' => 'min', 'unit_codes' => ['min', 'h'],
            'types' => ['streak', 'accumulate'], 'variants' => ['daily', 'period'],
            'direction' => 'increase', 'default_direction' => 'increase',
            'count' => 'keer',
            'keywords' => ['mediteren', 'ademhaling', 'mindfulness', 'meditate', 'breathing'],
            'names' => ['Elke dag 10 minuten mediteren', '600 minuten meditatie', '3 keer per week mediteren'],
            'examples' => [
                'streak'     => 'Elke dag 10 minuten',
                'accumulate' => '600 minuten meditatie',
            ],
            'placeholder' => ['streak' => 10, 'accumulate' => 600, 'period' => 3],
        ],
    ],

    /* The measurements the wizard shows before anybody searches or chooses a
       category: `popular` above, in that order. */

    /* ---------------------------------------------------- eigen gewoonte */
    /* Anything else, kept by hand: its category is chosen in step one, its
       name in step two, how it is counted in step three. */
    'custom' => [
        'label' => 'Eigen gewoonte',
        'hint'  => 'Iets dat je zelf bijhoudt',
        'unit_codes' => ['times', 'min', 'h', 'pages', 'glasses', 'reps', 'km', 'mi', 'kg', 'lb'],
        'types' => ['milestone', 'streak', 'accumulate'], 'variants' => ['daily', 'period'],
        'direction' => 'both', 'default_direction' => 'increase',
        'count' => 'keer',
        'keywords' => ['gewoonte', 'eigen', 'habit', 'custom', 'anders', 'other'],
        'names' => ['Elke dag 10 minuten lezen', '3 keer per week stretchen', '20 pull-ups'],
        'examples' => [
            'milestone'  => '20 pull-ups achter elkaar',
            'streak'     => 'Elke dag 10 minuten lezen',
            'accumulate' => '500 pagina\'s deze maand',
        ],
        'placeholder' => ['milestone' => 20, 'streak' => 10, 'accumulate' => 500, 'period' => 3],
    ],

    /* ------------------------------------------------------------ types */
    /*
     * How progress is worked out (includes/goal-progress.php, docs/GOALS.md):
     *
     *   milestone    from the baseline to the target, by the LATEST value:
     *                80 → 100 kg at 90 kg is 50%, and back at 85 it is 25%
     *   streak       daily: the share of the period's days that made it;
     *                period: the share of its weeks (months…) that made it
     *   accumulate   everything in the period added up, from zero
     */
    'types' => [
        'milestone' => [
            'label'   => 'Mijlpaal',
            'hint'    => 'Bereik één specifiek resultaat.',
            'example' => 'Naar 100 kg, of 15.000 stappen op één dag',
        ],
        'streak' => [
            'label'   => 'Streak',
            'hint'    => 'Houd een routine vol: elke dag, of een aantal keer per periode.',
            'example' => 'Elke dag je doel halen, of 3 keer per week trainen',
        ],
        'accumulate' => [
            'label'   => 'Optellen',
            'hint'    => 'Bouw een totaal op binnen je periode.',
            'example' => '200.000 stappen, of 600 minuten meditatie',
        ],
    ],

    'streak_variants' => [
        'daily'  => ['label' => 'Elke dag',    'hint' => 'Een doel voor elke dag. Een gemiste dag telt als niet gehaald, je doel loopt gewoon door.'],
        'period' => ['label' => 'Per periode', 'hint' => 'Een aantal keer per week, maand, kwartaal of jaar, gerekend vanaf je startdatum.'],
    ],

    /* A per-period streak's periods, counted from the goal's start date. */
    'periods' => [
        'week'    => ['label' => 'week',     'days' => 7],
        'month'   => ['label' => 'maand',    'months' => 1],
        'quarter' => ['label' => 'kwartaal', 'months' => 3],
        'year'    => ['label' => 'jaar',     'months' => 12],
    ],

    /* The five ways to set the end date from the start date. Months are
       calendar months, clamped to the month's last day (31 jan + 1 maand is
       28 or 29 feb), and the period ends the day before: 1 week from
       Monday runs to Sunday (goal_span_add() and its twins). */
    'durations' => [
        'week'     => ['label' => '1 week',    'days' => 7],
        'month'    => ['label' => '1 maand',   'months' => 1],
        'quarter'  => ['label' => '3 maanden', 'months' => 3],
        'halfyear' => ['label' => '6 maanden', 'months' => 6],
        'year'     => ['label' => '1 jaar',    'months' => 12],
    ],

    /* Where a goal's figures come from, as the detail page says it. */
    'sources' => [
        'auto'   => ['label' => 'Automatisch', 'icon' => 'sync', 'accent' => 'health', 'note' => 'Uit je eigen gezondheidsgegevens'],
        'manual' => ['label' => 'Handmatig',   'icon' => 'user', 'accent' => 'health', 'note' => 'Je vult je voortgang zelf in op deze pagina'],
    ],

    /* --------------------------------------------------------------- copy */
    'labels' => [
        'primary'        => 'Primair doel',
        'secondary'      => 'Secundaire doelen',
        'primary_chip'   => 'Primair',
        'paused_chip'    => 'Gepauzeerd',
        'add'            => 'Nieuw doel',
        'add_aria'       => 'Nieuw doel toevoegen',
        /* %1$d places left, %2$d the limit (`limits` → `active` above). */
        'slots_free'     => 'Nog %1$d van de %2$d doelen vrij.',
        'slots_one'      => 'Nog 1 van de %2$d doelen vrij.',
        'slots_full'     => 'Je %2$d doelplekken zijn bezet. Rond een doel af of verwijder er een om ruimte te maken.',
        'paused_counts'  => 'Een gepauzeerd doel houdt zijn plek.',
        'paused_line'    => 'Gepauzeerd, de periode loopt door',
        'open_aria'      => 'Open details van %s',
        'target'         => 'Doel',
        'current'        => 'Nu',
        'remaining'      => 'Resterend',
        'no_deadline'    => 'Geen einddatum',
        'last_day'       => 'Laatste dag',
        'completed_on'   => 'Behaald op %s',
        'ended_on'       => 'Afgelopen op %s',
        'took'           => 'in %s',
    ],

    'empty' => [
        'active' => [
            'title' => 'Nog geen doelen',
            'body'  => 'Stel je eerste doel in — één ding waar je aan werkt. Je voortgang verschijnt hier zodra je begint.',
            'cta'   => 'Eerste doel instellen',
        ],
        'completed' => [
            'title' => 'Nog niets afgerond',
            'body'  => 'Doelen die je behaalt of waarvan de periode voorbij is, blijven hier staan met hun eindstand.',
        ],
    ],

    /* --------------------------------------------------------- detail copy */
    'detail' => [
        'back'       => 'Doelen',
        'history'    => 'Verloop',
        'history_empty' => 'Nog geen verloop',
        'manage'     => 'Aanpassen',
        'period'     => 'Periode',
        /* Aanpassen's one selector: which of the two this goal is. */
        'priority'           => 'Prioriteit',
        'priority_primary'   => 'Primair',
        'priority_secondary' => 'Secundair',
        'priority_alone'     => 'Je enige doel is altijd je primaire doel.',
        'manual'     => 'Zelf bijhouden',
        'manual_save'  => 'Opslaan',
        'days'       => 'Per dag',
        'periods'    => 'Per periode',
        'days_note'  => 'Een dag zonder gegevens telt niet als gehaald. Je doel loopt gewoon door.',
        'days_window'=> 'De kalender toont de laatste zes weken. Het percentage telt de hele periode.',
        'pause'          => 'Doel pauzeren',
        'resume'         => 'Doel hervatten',
        'delete'         => 'Doel verwijderen',
        'delete_confirm' => 'Weet je het zeker? Je voortgang en geschiedenis verdwijnen.',
        'delete_yes'     => 'Verwijderen',
        'delete_no'      => 'Annuleren',
        'paused_body'    => 'Dit doel is gepauzeerd. Je voortgang wordt nog wel bijgehouden, en de periode loopt door.',
    ],

    /* --------------------------------------------------------- wizard copy */
    'wizard' => [
        'title' => 'Nieuw doel',
        /* The three steps the indicator counts. The review after them has no
           number of its own. */
        'steps' => [
            1 => ['label' => 'Meting',    'title' => 'Wat wil je bijhouden?', 'lede' => 'Zoek een meting of kies een categorie.'],
            2 => ['label' => 'Doel',      'title' => 'Wat is je doel?',       'lede' => 'Geef het een naam en kies hoe je het meet.'],
            3 => ['label' => 'Instellen', 'title' => 'Stel je doel in',       'lede' => 'Je streven en de periode waarin je het haalt.'],
        ],
        'review' => ['label' => 'Controleren', 'title' => 'Klopt alles?', 'lede' => 'Bekijk je doel voordat je het aanmaakt.'],

        /* Step one. */
        'search_label'   => 'Zoek een meting',
        'search_clear'   => 'Zoekopdracht wissen',
        'categories'     => 'Categorieën',
        'popular'        => 'Populair',
        'results'        => 'Metingen',
        'no_results'     => 'Geen meting gevonden. Je kunt het als eigen gewoonte bijhouden.',
        'auto'           => 'Automatisch',
        'manual'         => 'Zelf bijhouden',
        'custom_category'=> 'Kies hierboven de categorie van je gewoonte.',

        /* Step two. */
        'name_label'     => 'Naam',
        'name_ideas'     => 'Ideeën',
        'type_label'     => 'Soort doel',
        'example'        => 'Bijv. %s',
        'type_unavailable' => 'Kan niet met %s',

        /* Step three. */
        'variant_label'  => 'Hoe vaak',
        'target'         => [
            'milestone'  => 'Doelwaarde',
            'daily'      => 'Per dag',
            'period'     => 'Aantal keer',
            'accumulate' => 'Totaal',
        ],
        'unit_label'     => 'Eenheid',
        'unit_custom'    => 'Eigen eenheid…',
        'unit_custom_label' => 'Jouw eenheid',
        'unit_custom_placeholder' => "Bijv. pagina's",
        'per'            => 'per',
        'period_label'   => 'Periode',
        'direction_label'=> 'Wat is beter?',
        'direction' => [
            'increase' => ['label' => 'Hoger is beter', 'hint' => 'Hoger is beter: je voortgang groeit als je waarde stijgt.'],
            'decrease' => ['label' => 'Lager is beter', 'hint' => 'Lager is beter: je voortgang groeit als je waarde daalt.'],
        ],
        'direction_daily' => [
            'increase' => ['label' => 'Minstens',  'hint' => 'Minstens: een dag telt als je dit haalt of meer.'],
            'decrease' => ['label' => 'Hoogstens', 'hint' => 'Hoogstens: een dag telt als je hier niet boven komt.'],
        ],
        'direction_hold' => 'Houd vast voor uitleg',
        'baseline_label'    => 'Startwaarde',
        'baseline_measured' => 'Gemeten op %s',
        'baseline_manual'   => 'Er is nog geen meting. Vul je huidige waarde in.',
        'baseline_loading'  => 'Startwaarde ophalen…',
        'dates_label'    => 'Periode',
        'start'          => 'Start',
        'end'            => 'Einde',
        'end_choose'     => 'Kies einddatum',
        'calendar_done'  => 'Gereed',
        'calendar_prev'  => 'Vorige maand',
        'calendar_next'  => 'Volgende maand',
        'pick_start'     => 'Kies je startdatum',
        'pick_end'       => 'Kies je einddatum',
        'durations_label'=> 'Of kies een looptijd',

        /* The review. */
        'summary' => [
            'name'      => 'Naam',
            'measure'   => 'Meting',
            'type'      => 'Type',
            'target'    => 'Doel',
            'direction' => 'Richting',
            'baseline'  => 'Startwaarde',
            'dates'     => 'Periode',
            'tracking'  => 'Bijhouden',
        ],
        'tracking_auto'   => 'Automatisch, uit je eigen gegevens',
        'tracking_manual' => 'Handmatig, op de pagina van je doel',

        'back'     => 'Terug',
        'next'     => 'Volgende',
        'create'   => 'Doel aanmaken',
        'creating' => 'Doel aanmaken…',
        'close'    => 'Sluiten',
    ],

    /* ------------------------------------------------------- shipped goals */
    'goals' => [],

];

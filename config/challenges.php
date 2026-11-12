<?php
/**
 * Uitdagingen — what a challenge can count, how long it can run, and the
 * copy of its screens and sheets (docs/CHALLENGES.md).
 *
 * includes/challenges.php does the work; this file holds what can be tuned
 * and what is written on screen. Everything here is Dutch and read through
 * config_copy(), so it arrives in the reader's language; the keys and the
 * identifiers (`icon`, `accent`, `unit`) never change.
 *
 * ------------------------------------------------------------------
 * WHAT A CHALLENGE COUNTS
 * ------------------------------------------------------------------
 * Only what a connected source synced — Health Connect, Polar — never a
 * value somebody typed in. Each participant's total is worked out when it
 * is shown, from their own data, for this challenge's dates only.
 *
 *   step      the stepper of "Samen naar", in what is shown (km or mi)
 *   per_week  the target a new Samen challenge suggests per week of it
 */

declare(strict_types=1);

return [

    'metrics' => [
        'workouts' => [
            'category' => 'training',
            'label'  => 'Trainingen',
            'icon'   => 'dumbbell',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s training',
            'many'   => '%s trainingen',
            'what'   => 'trainingen',
            'versus' => 'Wie traint het vaakst?',
            'step'   => 10,
            'per_week' => 20,
        ],
        'steps' => [
            'category' => 'activity',
            'label'  => 'Stappen',
            'icon'   => 'bolt',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s stap',
            'many'   => '%s stappen',
            'what'   => 'stappen',
            'versus' => 'Wie zet de meeste stappen?',
            'step'   => 50000,
            'per_week' => 250000,
        ],
        'active_minutes' => [
            'category' => 'activity',
            'label'  => 'Actieve minuten',
            'icon'   => 'pulse',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s actieve minuut',
            'many'   => '%s actieve minuten',
            'what'   => 'actieve minuten',
            'versus' => 'Wie beweegt het meest?',
            'step'   => 250,
            'per_week' => 1000,
        ],
        'distance' => [
            'category' => 'activity',
            'label'  => 'Afstand',
            'icon'   => 'flag',
            'accent' => 'training',
            'unit'   => 'km',
            'one'    => '%s km',
            'many'   => '%s km',
            'what'   => 'kilometers',
            'versus' => 'Wie legt de meeste afstand af?',
            'step'   => 25,
            'per_week' => 100,
        ],
        'sleep_nights' => [
            'category' => 'sleep',
            'label'  => 'Nachten van 7 uur',
            'icon'   => 'moon',
            'accent' => 'sleep',
            'unit'   => '',
            'one'    => '%s nacht van 7 uur',
            'many'   => '%s nachten van 7 uur',
            'what'   => 'nachten van 7 uur',
            'versus' => 'Wie slaapt het vaakst 7 uur?',
            /* 49.3: no longer offered for a new challenge (Uren slaap is);
               challenges that count it go on. */
            'retired' => true,
            'step'   => 10,
            'per_week' => 25,
        ],
        /* 50.0 (migration 031): what a goal can count too, where a group
           can add it up — synced only, never typed in. */
        'active_energy' => [
            'category' => 'activity',
            'label'  => 'Actieve calorieën',
            'icon'   => 'pulse',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s actieve kcal',
            'many'   => '%s actieve kcal',
            'what'   => 'actieve calorieën',
            'versus' => 'Wie verbrandt de meeste actieve calorieën?',
            'step'   => 1000,
            'per_week' => 10000,
        ],
        'floors' => [
            'category' => 'activity',
            'label'  => 'Verdiepingen',
            'icon'   => 'arrow-up',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s verdieping',
            'many'   => '%s verdiepingen',
            'what'   => 'verdiepingen',
            'versus' => 'Wie loopt de meeste trappen?',
            'step'   => 50,
            'per_week' => 300,
        ],
        'step_days' => [
            'category' => 'activity',
            'label'  => 'Dagen met 10.000 stappen',
            'icon'   => 'calendar',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s dag met 10.000 stappen',
            'many'   => '%s dagen met 10.000 stappen',
            'what'   => 'dagen met 10.000 stappen',
            'versus' => 'Wie haalt het vaakst 10.000 stappen?',
            /* 49.5: no longer offered for a new challenge (Stappen is, with
               the number the group chooses); running ones go on. */
            'retired' => true,
            'step'   => 5,
            'per_week' => 15,
        ],
        'workout_minutes' => [
            'category' => 'training',
            'label'  => 'Trainingsminuten',
            'icon'   => 'dumbbell',
            'accent' => 'training',
            'unit'   => '',
            'one'    => '%s trainingsminuut',
            'many'   => '%s trainingsminuten',
            'what'   => 'trainingsminuten',
            'versus' => 'Wie traint het langst?',
            'step'   => 60,
            'per_week' => 600,
        ],
        'sleep_hours' => [
            'category' => 'sleep',
            'label'  => 'Uren slaap',
            'icon'   => 'moon',
            'accent' => 'sleep',
            'unit'   => '',
            'one'    => '%s uur slaap',
            'many'   => '%s uur slaap',
            'what'   => 'uren slaap',
            'versus' => 'Wie slaapt het meest?',
            'step'   => 50,
            'per_week' => 200,
        ],
        'water' => [
            'category' => 'nutrition',
            'label'  => 'Water',
            'icon'   => 'utensils',
            'accent' => 'nutrition',
            'unit'   => 'l',
            'one'    => '%s liter water',
            'many'   => '%s liter water',
            'what'   => 'liters water',
            'versus' => 'Wie drinkt het meeste water?',
            'step'   => 5,
            'per_week' => 50,
        ],
        'water_days' => [
            'category' => 'nutrition',
            'label'  => 'Dagen met 2 liter water',
            'icon'   => 'calendar',
            'accent' => 'nutrition',
            'unit'   => '',
            'one'    => '%s dag met 2 liter water',
            'many'   => '%s dagen met 2 liter water',
            'what'   => 'dagen met 2 liter water',
            'versus' => 'Wie drinkt het vaakst 2 liter water?',
            /* 49.4: no longer offered for a new challenge (Water is, with
               the litres the group chooses); running ones go on. */
            'retired' => true,
            'step'   => 5,
            'per_week' => 15,
        ],
        'protein' => [
            'category' => 'nutrition',
            'label'  => 'Eiwit',
            'icon'   => 'utensils',
            'accent' => 'nutrition',
            'unit'   => '',
            'one'    => '%s gram eiwit',
            'many'   => '%s gram eiwit',
            'what'   => 'gram eiwit',
            'versus' => 'Wie eet de meeste eiwitten?',
            'step'   => 500,
            'per_week' => 3000,
        ],
        'fibre' => [
            'category' => 'nutrition',
            'label'  => 'Vezels',
            'icon'   => 'utensils',
            'accent' => 'nutrition',
            'unit'   => '',
            'one'    => '%s gram vezels',
            'many'   => '%s gram vezels',
            'what'   => 'gram vezels',
            'versus' => 'Wie eet de meeste vezels?',
            'step'   => 100,
            'per_week' => 800,
        ],
    ],

    /* The groups of step 1, in this order — the goal wizard's categories
       (config/goals.php), those a challenge can count. */
    'categories' => [
        'activity'  => ['label' => 'Beweging'],
        'training'  => ['label' => 'Training'],
        'sleep'     => ['label' => 'Slaap'],
        'nutrition' => ['label' => 'Voeding'],
    ],

    'kinds' => [
        'together' => ['label' => 'Samen', 'hint' => 'Jullie tellen samen op naar één doel.'],
        'versus'   => ['label' => 'Wedstrijd', 'hint' => 'Ieder voor zich; wie het meest haalt, wint. Bij gelijkspel gaat het door tot er een winnaar is.'],
    ],

    'lengths' => [
        '1w'    => ['label' => '1 week', 'weeks' => 1],
        '2w'    => ['label' => '2 weken', 'weeks' => 2],
        'month' => ['label' => '1 maand', 'weeks' => 4],
    ],

    /* 49.1: the goal wizard's dates (Periode: Start → Einde, or a
       looptijd) — a challenge starts today or later, within `start_within`
       days, and lasts at most `longest` days. The looptijden are the goal
       wizard's (config/goals.php → durations). */
    'dates' => [
        'start_within' => 90,
        'longest'      => 366,
    ],

    /* The Uitdagingen page. */
    'page' => [
        'title'    => 'Uitdagingen',
        'lede'     => 'Samen of tegen elkaar, met wie jij kiest.',
        'create'   => 'Nieuwe uitdaging',
        'join'     => 'Code invoeren',
        'invites'  => 'Uitnodigingen',
        'ended'    => 'Afgelopen',
        'empty_title' => 'Nog geen uitdagingen',
        'back'     => 'Community',
        /* Running challenges are grouped by kind; these head the groups. */
        'together' => 'Samen',
        'versus'   => 'Wedstrijd',
        /* With nothing yet: three ways to begin, each opening Nieuwe
           uitdaging with its kind and what it counts already chosen. */
        'ideas_lede' => 'Begin met een van deze, of maak je eigen.',
        'ideas' => [
            ['kind' => 'together', 'metric' => 'workouts',     'title' => 'Samen trainen',              'sub' => 'Samen naar één doel · 1 week'],
            ['kind' => 'versus',   'metric' => 'steps',        'title' => 'Wie zet de meeste stappen?', 'sub' => 'Ieder voor zich · 1 week'],
            ['kind' => 'together', 'metric' => 'sleep_hours',  'title' => 'Samen goed slapen',          'sub' => 'Uren slaap · 1 week'],
        ],
    ],

    /* One challenge. */
    'detail' => [
        'back'          => 'Uitdagingen',
        'contributions' => 'Wie draagt wat bij',
        'standings'     => 'Stand',
        'share_title'   => 'Iemand erbij halen',
        'copy'          => 'Code kopiëren',
        'share'         => 'Code delen',
        'invite'        => 'Vrienden uitnodigen',
        'invite_hint'   => 'Ze doen pas mee als ze zelf meedoen',
        'leave'         => 'Uitdaging verlaten',
        'leave_title'   => 'Uitdaging verlaten?',
        'leave_body'    => 'Je doet dan niet meer mee, en wat je bijdroeg telt niet meer. Met de code kun je later weer meedoen, zolang hij loopt.',
        'leave_confirm' => 'Verlaten',
        'cancel'        => 'Annuleren',
    ],

    /* Code invoeren. */
    'code' => [
        'title'     => 'Code invoeren',
        'hint'      => 'Vul de 5 cijfers in die je van een vriend kreeg. Je ziet eerst waar je aan meedoet.',
        'label'     => 'Code van 5 cijfers',
        'searching' => 'Zoeken…',
    ],

    /* Bekijken: an invitation, or what a code found. */
    'review' => [
        'title_invite' => 'Uitnodiging',
        'title_code'   => 'Meedoen?',
        'kind_label'   => 'Soort',
        'what'         => 'Wat',
        'period'       => 'Periode',
        'creator'      => 'Gemaakt door',
        'people'       => 'Doen mee',
        'join'         => 'Meedoen',
        'decline'      => 'Afwijzen',
        'later'        => 'Niet nu',
        'joined_title' => 'Je doet mee',
        'close'        => 'Sluiten',
        'see'          => 'Bekijken',
    ],

    /* Nieuwe uitdaging. */
    'create' => [
        'title'        => 'Nieuwe uitdaging',
        'kind_label'   => 'Soort',
        'metric'       => 'Wat tellen jullie?',
        'target'       => 'Samen naar',
        'less'         => 'Minder',
        'more'         => 'Meer',
        'length'       => 'Hoe lang?',
        'from_today'   => 'Vanaf vandaag',
        'name'         => 'Naam',
        'name_example' => 'Bijvoorbeeld: 100 trainingen in oktober',
        'friends'      => 'Vrienden uitnodigen',
        'friends_hint' => 'Ze doen pas mee als ze zelf op Meedoen tikken. Je krijgt ook een code.',
        'no_friends'   => 'Nog geen vrienden. Deel straks de code, of voeg vrienden toe in je account.',
        'pick_first'   => 'Kies eerst wat jullie tellen',
        'make'         => 'Uitdaging maken',
        'preview_empty'=> 'Kies hieronder wat jullie tellen',
        'preview_name' => 'Je uitdaging',
        'made_title'   => 'Je uitdaging staat klaar',
        'made_code'    => 'Code om mee te doen',
        'made_hint'    => 'Wie de code invult, ziet eerst wat het is en doet pas mee na Meedoen.',
        'copy'         => 'Kopiëren',
        'share'        => 'Delen',
        'open'         => 'Naar je uitdaging',
        /* 49.0: the goal wizard's steps (components/goal-wizard.php), for a group. */
        'step1_label'  => 'Wat',
        'step1_title'  => 'Wat tellen jullie?',
        'step1_lede'   => 'Alleen wat jullie gekoppelde bronnen meten — nooit iets wat iemand intypt.',
        'step2_label'  => 'Soort',
        'step2_title'  => 'Samen of tegen elkaar?',
        'step2_lede'   => 'Tel samen op naar één doel, of kijk wie het meest haalt.',
        'step3_label'  => 'Instellen',
        'step3_title'  => 'Hoeveel en hoe lang?',
        'step3_lede'   => 'Kies het doel en de periode, net als bij een eigen doel.',
        'move'         => 'Beweging',
        'sleep'        => 'Slaap',
        'auto'         => 'Uit jullie gekoppelde bronnen',
        'list_label'   => 'Te kiezen',
        'review_label' => 'Controleren',
        'review_title' => 'Klopt alles?',
        'review_lede'  => 'Zo ziet je uitdaging eruit. Met terug pas je elke stap aan.',
        'invite_label' => 'Uitnodigen',
        'invite_title' => 'Wie nodig je uit?',
        'search'       => 'Zoek een vriend',
        'no_match'     => 'Geen vriend met die naam.',
        'picked'       => '%d gekozen',
        'none_picked'  => 'Nog niemand gekozen — je kunt ook straks de code delen.',
        'period'       => 'Periode',
        'next'         => 'Volgende',
        'back'         => 'Terug',
        'close'        => 'Sluiten',
        'making'       => 'Bezig…',
        /* 49.1: step 1 searches, step 3 has the goal wizard's Periode. */
        'find'         => 'Zoek wat jullie tellen',
        'no_metric'    => 'Niets gevonden. Probeer een ander woord.',
        'dates_label'  => 'Periode',
        'start'        => 'Start',
        'end'          => 'Einde',
        'calendar_done'=> 'Gereed',
        'calendar_prev'=> 'Vorige maand',
        'calendar_next'=> 'Volgende maand',
        'pick_start'   => 'Kies de startdatum',
        'pick_end'     => 'Kies de einddatum',
        'durations_label' => 'Of kies een looptijd',
    ],

    /* Vrienden uitnodigen, from a challenge. */
    'invite' => [
        'title'   => 'Vrienden uitnodigen',
        'body'    => 'Ze krijgen een uitnodiging en doen pas mee als ze zelf op Meedoen tikken.',
        'none'    => 'Al je vrienden doen mee of zijn uitgenodigd.',
        'pick'    => 'Kiezen',
        'picked'  => 'Gekozen',
        'send'    => 'Uitnodigen',
        'cancel'  => 'Annuleren',
    ],

];

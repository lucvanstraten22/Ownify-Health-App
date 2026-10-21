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
            'label'  => 'Nachten van 7 uur',
            'icon'   => 'moon',
            'accent' => 'sleep',
            'unit'   => '',
            'one'    => '%s nacht van 7 uur',
            'many'   => '%s nachten van 7 uur',
            'what'   => 'nachten van 7 uur',
            'versus' => 'Wie slaapt het vaakst 7 uur?',
            'step'   => 10,
            'per_week' => 25,
        ],
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
            ['kind' => 'together', 'metric' => 'sleep_nights', 'title' => 'Samen goed slapen',          'sub' => 'Nachten van 7 uur · 1 week'],
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

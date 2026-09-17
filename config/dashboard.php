<?php
/**
 * Central dashboard configuration — the shape of the Overzicht page.
 *
 * ------------------------------------------------------------------
 * SHAPE HERE, VALUES FROM THE DATABASE
 * ------------------------------------------------------------------
 * Every metric below is `null`, and stays null: this file says what the page
 * is made of, not what any particular person's day looked like.
 *
 *   null  => the UI renders an honest empty state ("—" + caption)
 *   int   => the UI renders a real score, ring, bar and colour state
 *
 * index.php fills these in for the signed-in user — the ring from
 * includes/scoring.php, the goal card from the user's primary goal. A user
 * with no records keeps the empty state, which is the truthful answer rather
 * than a placeholder one.
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    /* -------------------------------------------------- app + shell */
    'app' => [
        'name'        => 'Johnnie',
        'tagline'     => 'Gezondheidsoverzicht',
        'locale'      => 'nl',
        'theme_color' => '#302D2F',
    ],

    /**
     * Onboarding focus. Drives which block gets visual priority.
     * general | sleep | nutrition | mobility | mental
     * Set to 'general' until onboarding exists.
     */
    'focus'        => 'general',
    'focus_labels' => [
        'general'   => 'Algemeen',
        'sleep'     => 'Slaap',
        'nutrition' => 'Voeding',
        'mobility'  => 'Mobiliteit',
        'mental'    => 'Mentaal',
    ],

    /* -------------------------------------------------- header */
    'header' => [
        'devices' => [
            'label'      => 'Apparaten',
            'aria'       => 'Verbonden apparaten beheren',
            'connected'  => 0,      // no integrations in this version
        ],
        'account' => [
            'label' => 'Account',
            'aria'  => 'Account en profiel openen',
        ],
    ],

    /* -------------------------------------------------- scores */
    'overview' => [
        'title'    => 'Vandaag',
        'subtitle' => null,  // filled at render time with the current date
    ],

    'scores' => [

        /* A — primary score, visually dominant.
         * `value` is DERIVED, never configured: index.php overwrites it with
         * health_overall_score(), the average of the three Gezondheid pillars.
         * Setting a number here would only be overwritten on the next render. */
        'overall' => [
            'label'       => 'Gezondheidsscore',
            'value'       => null,
            'max'         => 100,
            'caption'     => 'Nog geen gegevens',
            'description' => 'Je score bundelt slaap, voeding en beweging tot één beeld van vandaag.',
            'empty_hint'  => 'Verbind een bron om je dagscore te berekenen.',
        ],

        /* Ring legend — the three pillars the score averages, each with its own
         * score beside it. `area` points at the Gezondheid area the row reads
         * from, which is why the Sport row can carry the Training score without
         * either name having to change. The three count equally, so there are
         * no weights here. Values are DERIVED, like the overall score. */
        'contributors' => [
            ['area' => 'sleep',     'label' => 'Slaap',   'accent' => 'health',    'value' => null],
            ['area' => 'nutrition', 'label' => 'Voeding', 'accent' => 'nutrition', 'value' => null],
            ['area' => 'training',  'label' => 'Sport',   'accent' => 'activity',  'value' => null],
        ],

    ],

    /* -------------------------------------------------- goal progress */
    'goal' => [
        'title'       => 'Persoonlijk doel',
        'state'       => 'unset',           // unset | active | reached
        'name'        => null,              // e.g. "Rustiger slaapritme"
        'progress'    => null,              // 0–100, null while unset
        'unit'        => 'van je weekdoel',
        'headline'    => 'Nog geen doel ingesteld',
        'description' => 'Tijdens de onboarding kies je één doel. Je voortgang van deze week verschijnt hier.',
        /* A goal exists but has nothing measured against it yet — true the
           moment one is created, and until something is recorded. */
        'pending'     => 'Je voortgang verschijnt zodra je iets vastlegt voor dit doel.',
        'cta'         => ['label' => 'Doel instellen', 'enabled' => true, 'note' => 'Opent de Doelen-pagina'],
        'milestones'  => [
            ['label' => 'Start',  'reached' => false],
            ['label' => 'Halverwege', 'reached' => false],
            ['label' => 'Bijna',  'reached' => false],
            ['label' => 'Doel',   'reached' => false],
        ],
    ],

    /* -------------------------------------------------- insights */
    'insights' => [
        'title'    => 'Nuttige inzichten',
        'subtitle' => 'Verschijnt zodra er ritme zichtbaar is',
        'state'    => 'empty',
        'items'    => [
            [
                'icon'   => 'pulse',
                'accent' => 'health',
                'title'  => 'Ritme in je week',
                'body'   => 'Hier zie je straks welk dagritme het beste bij je past.',
                'state'  => 'empty',
            ],
            [
                'icon'   => 'leaf',
                'accent' => 'nutrition',
                'title'  => 'Voeding en energie',
                'body'   => 'Hier komt het verband tussen je eetmomenten en je energie.',
                'state'  => 'empty',
            ],
            [
                'icon'   => 'bolt',
                'accent' => 'activity',
                'title'  => 'Beweging en herstel',
                'body'   => 'Hier zie je hoe beweging en herstel elkaar beïnvloeden.',
                'state'  => 'empty',
            ],
        ],
    ],

    /* -------------------------------------------------- patterns + research */
    'patterns' => [
        'title'       => 'Patronen & onderzoek',
        'state'       => 'empty',
        'headline'    => 'Nog niets te tonen',
        'description' => 'Patronen uit je eigen gegevens en onderliggend onderzoek verschijnen hier zodra er genoeg meetmomenten zijn.',
        'topics'      => ['Slaap', 'Voeding', 'Beweging', 'Herstel'],
        'range'       => '7 dagen',
    ],

    /* -------------------------------------------------- recommendation */
    'recommendation' => [
        'title'       => 'Eén kleine suggestie',
        'state'       => 'empty',
        'headline'    => 'Nog geen suggestie',
        'description' => 'Zodra je ritme bekend is, staat hier één vrijblijvende suggestie voor vandaag. Niet meer dan één.',
        'note'        => 'Altijd een suggestie, nooit een opdracht.',
    ],

    /* -------------------------------------------------- social layer */
    'leaderboard' => [
        'title'    => 'Ranglijst',
        'subtitle' => 'Deze week',
        'state'    => 'empty',
        'empty'    => 'Nog geen deelnemers',
        'rows'     => [
            ['rank' => 1, 'name' => null, 'value' => null, 'self' => false],
            ['rank' => 2, 'name' => null, 'value' => null, 'self' => false],
            ['rank' => 3, 'name' => null, 'value' => null, 'self' => false],
        ],
        'self'     => ['rank' => null, 'name' => 'Jij', 'value' => null, 'self' => true],
        'footnote' => 'Vergelijken is optioneel — je eigen lijn telt.',
    ],

    /* -------------------------------------------------- bottom navigation
     * The app's five primary destinations, in order. This array is the only
     * definition of the navigation: label, icon, destination, order and
     * active state all live here.
     *
     * `destination` is the page that renders it. All five are built now, so
     * every entry names one.
     *
     *   Gezondheid  — slaap, voeding en sport, samen in één sectie
     *   Doelen      — persoonlijke doelen en voortgang
     *   Overzicht   — het dagelijkse dashboard (hier)
     *   Community   — ranglijst en toekomstige sociale functies
     *   Instellingen— app- en gebruikersinstellingen
     */
    'navigation' => [
        ['id' => 'health',    'label' => 'Gezondheid',   'icon' => 'heart',     'destination' => 'health',   'active' => false],
        ['id' => 'goals',     'label' => 'Doelen',       'icon' => 'flag',      'destination' => 'goals',    'active' => false],
        ['id' => 'overview',  'label' => 'Overzicht',    'icon' => 'rings',     'destination' => 'overview', 'active' => true],
        ['id' => 'community', 'label' => 'Community',    'icon' => 'community', 'destination' => 'community','active' => false],
        ['id' => 'settings',  'label' => 'Instellingen', 'icon' => 'sliders',   'destination' => 'settings', 'active' => false],
    ],

    /* -------------------------------------------------- AI assistant layer
     * The assistant itself does not exist yet: no model, no API, no messages.
     * It is a sheet that pulls up over whichever page you are on, so its copy
     * never names a page to return to — closing returns you where you were.
     */
    'ai' => [
        'title'   => 'Assistent',
        'status'  => 'Binnenkort beschikbaar',
        'open'    => ['aria' => 'Assistent openen — of veeg omhoog'],
        'close'   => ['label' => 'Sluiten', 'aria' => 'Assistent sluiten — of veeg omlaag'],
        'composer' => [
            'note' => 'Hier komt je invoerveld',
            'aria' => 'Gereserveerde ruimte voor het toekomstige invoerveld',
        ],
    ],

    /* -------------------------------------------------- footer note */
    /**
     * The line under each page. It used to say "example view", which was true
     * while the pages were filled from this file. Now it depends on who is
     * asking, so index.php picks one of these; a signed-in account with data
     * gets none of them, because there is nothing left to disclaim.
     */
    'disclaimers' => [
        'signed_out' => 'Log in om je eigen gegevens te zien.',
        'no_data'    => 'Je gegevens verschijnen hier zodra je ze vastlegt of een bron koppelt.',
        'no_database'=> 'Geen databaseverbinding — er kan nu niets worden opgeslagen of geladen.',
    ],
    'disclaimer' => '',
];

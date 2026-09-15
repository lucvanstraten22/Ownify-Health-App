<?php
/**
 * Central dashboard configuration + placeholder data.
 *
 * ------------------------------------------------------------------
 * IMPORTANT — PLACEHOLDER CONTRACT
 * ------------------------------------------------------------------
 * There is no database, no authentication and no device integration in
 * this version. Every metric below is therefore intentionally `null`.
 *
 *   null  => the UI renders an honest empty state ("—" + caption)
 *   int   => the UI renders a real score, ring, bar and colour state
 *
 * No value in this file is an invented user measurement. To go live,
 * replace this array with the output of a repository/service layer that
 * returns the exact same shape — the UI does not need to change.
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    /* -------------------------------------------------- app + shell */
    'app' => [
        'name'        => 'Vitalis',
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

        /* A — primary score, visually dominant */
        'overall' => [
            'label'       => 'Gezondheidsscore',
            'value'       => null,
            'max'         => 100,
            'caption'     => 'Nog geen gegevens',
            'description' => 'Je score bundelt slaap, voeding en beweging tot één beeld van vandaag.',
            'empty_hint'  => 'Verbind een bron om je dagscore te berekenen.',
        ],

        /* Ring segments — explains *why* the primary score is what it is */
        'contributors' => [
            ['key' => 'sleep',     'label' => 'Slaap',   'accent' => 'health',    'weight' => 0.4, 'value' => null],
            ['key' => 'nutrition', 'label' => 'Voeding', 'accent' => 'nutrition', 'weight' => 0.3, 'value' => null],
            ['key' => 'sport',     'label' => 'Sport',   'accent' => 'activity',  'weight' => 0.3, 'value' => null],
        ],

        /* B + C — secondary category cards (identical card system) */
        'secondary' => [
            [
                'key'     => 'sleep',
                'label'   => 'Slaap',
                'caption' => 'Dagscore',
                'icon'    => 'moon',
                'accent'  => 'health',
                'value'   => null,
                'max'     => 100,
                'metrics' => [
                    ['label' => 'Duur',      'value' => null, 'accent' => 'health'],
                    ['label' => 'Regelmaat', 'value' => null, 'accent' => 'health'],
                ],
                /* Shown only when the onboarding focus is 'sleep'. */
                'focus_metrics' => [
                    ['label' => 'Slaapduur', 'value' => null, 'accent' => 'health'],
                    ['label' => 'Bedtijd',   'value' => null, 'accent' => 'health'],
                    ['label' => 'Wektijd',   'value' => null, 'accent' => 'health'],
                ],
            ],
            [
                'key'     => 'nutrition_sport',
                'label'   => 'Voeding & Sport',
                'caption' => 'Dagscore',
                'icon'    => 'leaf',
                'accent'  => 'nutrition',
                'value'   => null,
                'max'     => 100,
                'metrics' => [
                    ['label' => 'Voeding', 'value' => null, 'accent' => 'nutrition'],
                    ['label' => 'Sport',   'value' => null, 'accent' => 'activity'],
                ],
                'focus_metrics' => [
                    ['label' => 'Voeding',  'value' => null, 'accent' => 'nutrition'],
                    ['label' => 'Sport',    'value' => null, 'accent' => 'activity'],
                    ['label' => 'Balans',   'value' => null, 'accent' => 'activity'],
                ],
            ],
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
        'cta'         => ['label' => 'Doel instellen', 'enabled' => false, 'note' => 'Beschikbaar na onboarding'],
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
     * `destination` is the page that renders the section. Only 'overview'
     * exists today; the others are null, so the component renders them as
     * inert buttons rather than links to pages that are not there yet.
     *
     *   Gezondheid  — slaap, voeding en sport, samen in één sectie
     *   Doelen      — persoonlijke doelen en voortgang
     *   Overzicht   — het dagelijkse dashboard (hier)
     *   Community   — ranglijst en toekomstige sociale functies
     *   Instellingen— app- en gebruikersinstellingen
     */
    'navigation' => [
        ['id' => 'health',    'label' => 'Gezondheid',   'icon' => 'heart',     'destination' => 'health',   'active' => false],
        ['id' => 'goals',     'label' => 'Doelen',       'icon' => 'flag',      'destination' => null,       'active' => false],
        ['id' => 'overview',  'label' => 'Overzicht',    'icon' => 'rings',     'destination' => 'overview', 'active' => true],
        ['id' => 'community', 'label' => 'Community',    'icon' => 'community', 'destination' => null,       'active' => false],
        ['id' => 'settings',  'label' => 'Instellingen', 'icon' => 'sliders',   'destination' => null,       'active' => false],
    ],

    /* -------------------------------------------------- section placeholders
     * The four sections beside Overzicht are not built yet. They exist as
     * pages so the horizontal navigation is real, and say plainly what will
     * live there — nothing is invented.
     */
    'sections' => [
        'health' => [
            'title'  => 'Gezondheid',
            'icon'   => 'heart',
            'lede'   => 'Slaap, voeding en sport komen hier samen in één beeld.',
            'status' => 'Binnenkort beschikbaar',
            'topics' => ['Slaap', 'Voeding', 'Sport'],
        ],
        'goals' => [
            'title'  => 'Doelen',
            'icon'   => 'flag',
            'lede'   => 'Je persoonlijke doelen en je voortgang daarnaartoe.',
            'status' => 'Binnenkort beschikbaar',
            'topics' => ['Doel kiezen', 'Voortgang', 'Mijlpalen'],
        ],
        'community' => [
            'title'  => 'Community',
            'icon'   => 'community',
            'lede'   => 'De ranglijst en toekomstige sociale functies.',
            'status' => 'Binnenkort beschikbaar',
            'topics' => ['Ranglijst', 'Vergelijken'],
        ],
        'settings' => [
            'title'  => 'Instellingen',
            'icon'   => 'sliders',
            'lede'   => 'App-, account- en privacyinstellingen.',
            'status' => 'Binnenkort beschikbaar',
            'topics' => ['Account', 'Apparaten', 'Privacy'],
        ],
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
    'disclaimer' => 'Voorbeeldweergave — er zijn nog geen persoonlijke gegevens gekoppeld.',
];

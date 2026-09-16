<?php
/**
 * Community — leaderboard scopes, periods and copy.
 *
 * ------------------------------------------------------------------
 * POINTS ARE NOT DECIDED YET
 * ------------------------------------------------------------------
 * Nothing here awards points. `points` is just a number carried on an entry,
 * kept entirely separate from the UI, so a future scoring engine can produce
 * the ranking without the leaderboard changing at all.
 *
 * ------------------------------------------------------------------
 * PLACEHOLDER CONTRACT (same as the rest of the app)
 * ------------------------------------------------------------------
 * Shipped, there are no entries and no names: `demo` is false and both boards
 * render their empty state. Turning `demo` on generates a controlled roster —
 * deterministic, defined in lib/community.php, never a real person — so the
 * design and the floating-position behaviour can be reviewed with a full
 * board. No invented social claim ever reaches the shipped page.
 */

declare(strict_types=1);

return [

    'demo' => true,

    'title' => 'Community',

    /* Ranking scope — which people you are compared with. */
    'scopes' => [
        'friends' => [
            'label' => 'Vrienden',
            'limit' => 50,
            'empty' => [
                'title' => 'Nog geen vrienden',
                'body'  => 'Zodra je vrienden toevoegt, verschijnt jullie ranglijst hier.',
            ],
        ],
        'netherlands' => [
            'label' => 'Nederland',
            'limit' => 50,
            'empty' => [
                'title' => 'Ranglijst nog niet beschikbaar',
                'body'  => 'De landelijke top 50 verschijnt zodra er punten worden bijgehouden.',
            ],
        ],
    ],

    /* Ranking period — over which window the points are counted. */
    'periods' => [
        'month'   => ['label' => 'Maand'],
        'year'    => ['label' => 'Jaar'],
        'alltime' => ['label' => 'All-time'],
    ],

    'default_scope'  => 'friends',
    'default_period' => 'month',

    /* The signed-in user. Rank and points come from the board, not from here. */
    'you' => ['name' => 'Jij'],

    'labels' => [
        'unit'       => 'pt',
        'you_hint'   => 'Jouw positie',
        'outside'    => 'Buiten de top 50',
        'board_head' => 'Ranglijst',
    ],

    /* Reserved, secondary, and deliberately not built: see §21 of the brief. */
    'badges' => [
        'title' => 'Badges en prestaties',
        'body'  => 'Mijlpalen en persoonlijke records krijgen hier een plek.',
    ],

    /**
     * Demo roster settings. Used only when `demo` is true.
     * `you` is where the signed-in user sits in each board — inside the top 50
     * among friends, well outside it nationally, which is what exercises the
     * floating-position behaviour in both directions.
     */
    'demo_settings' => [
        'names' => [
            'Lisa', 'Noah', 'Sam', 'Emma', 'Daan', 'Tess', 'Luuk', 'Fenna', 'Bram', 'Sanne',
            'Jesse', 'Nora', 'Thijs', 'Yara', 'Milan', 'Julia', 'Ruben', 'Anne', 'Sven', 'Maud',
            'Kian', 'Roos', 'Timo', 'Lotte', 'Jurre', 'Iris', 'Cas', 'Elin', 'Stijn', 'Mila',
            'Joep', 'Vera', 'Rens', 'Saar', 'Gijs', 'Lynn', 'Teun', 'Amber', 'Mees', 'Fleur',
            'Bas', 'Nina', 'Hugo', 'Isa', 'Jorn', 'Livia', 'Pim', 'Merel', 'Koen', 'Britt',
        ],
        'you' => [
            'friends'     => ['month' => 7,   'year' => 5,   'alltime' => 6],
            'netherlands' => ['month' => 235, 'year' => 412, 'alltime' => 1180],
        ],
        'top' => [
            'friends'     => ['month' => 5320, 'year' => 38400, 'alltime' => 96200],
            'netherlands' => ['month' => 8940, 'year' => 71200, 'alltime' => 184500],
        ],
    ],
];

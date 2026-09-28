<?php
/**
 * Community — leaderboard scopes, periods and copy.
 *
 * ------------------------------------------------------------------
 * WHERE THE POINTS COME FROM
 * ------------------------------------------------------------------
 * includes/points.php awards them for what people did — a night, a day's
 * nutrition rating, a workout, steps, three workouts in a week — with every
 * value in config/points.php. The Health Score never pays out.
 *
 * ------------------------------------------------------------------
 * WHO IS ON A BOARD
 * ------------------------------------------------------------------
 * Real accounts, and only ever their public fields — a handle and a picture.
 * lib/hydrate-community.php assembles the boards; this file holds the scopes,
 * the periods and the copy. There is no generated roster any more: an empty
 * board is the honest answer, an invented one never was.
 */

declare(strict_types=1);

return [

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
                'body'  => 'De landelijke top 50 verschijnt zodra er in deze periode punten zijn verdiend.',
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

    /* The first row of every Vrienden board (never Nederland): opens Vriend
       toevoegen in the account panel. */
    'add_friends' => 'Vrienden toevoegen',

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

];

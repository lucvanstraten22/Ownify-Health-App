<?php
/**
 * Doelen — goal definitions, the vocabulary a goal is built from, and copy.
 *
 * ------------------------------------------------------------------
 * PLACEHOLDER CONTRACT
 * ------------------------------------------------------------------
 * There is no goal storage yet: nothing on this page is read from or written
 * to a database, and no health integration feeds it.
 *
 *   'goals'      the shipped list — empty, so the page renders its empty state
 *   'demo_goals' controlled example goals, used ONLY while 'demo' is true
 *
 * The example goals exist because this screen cannot be judged empty: a goal
 * board is its progress. They belong to nobody, they are marked as examples on
 * the page itself, and every change made to them lives for one page view. The
 * moment goals come from a database, set 'demo' to false and fill 'goals'.
 *
 * ------------------------------------------------------------------
 * SHAPE
 * ------------------------------------------------------------------
 * A goal is deliberately not "a number and a target". It is:
 *
 *   name       what the user is working toward, in their own words
 *   category   which part of life it belongs to        (drives icon + accent)
 *   type       how success is measured                 (value/habit/streak/…)
 *   priority   primary | secondary — an ordering, not a separate kind of goal
 *   status     active | paused | completed
 *   percent    0–100, however that percentage is arrived at
 *   labels     current + target as text, so a habit goal reads as naturally
 *              as a weight goal without the UI knowing the difference
 *   sources    which health data would keep it up to date
 *
 * Dates are stored as offsets from today rather than as fixed days, so the
 * examples keep reading sensibly however long this file sits here.
 */

declare(strict_types=1);

return [

    /* Flip to false the moment real goals arrive; the page needs no change. */
    'demo' => true,

    'title' => 'Doelen',
    'lede'  => 'Waar je aan werkt, en hoe ver je bent.',

    /* ------------------------------------------------------------ limits */
    'limits' => [
        'active'    => 3,
        'primary'   => 1,
        'secondary' => 2,
    ],

    /* ------------------------------------------------------------- views */
    'views' => [
        'active'    => ['label' => 'Actief'],
        'completed' => ['label' => 'Behaald'],
    ],

    'default_view' => 'active',

    /**
     * Categories. Open-ended on purpose — adding one is a line here, and the
     * card, the wizard and the detail page all pick it up. `accent` reuses the
     * app's own three colours; nothing new is introduced.
     */
    'categories' => [
        'health'      => ['label' => 'Gezondheid', 'icon' => 'heart',    'accent' => 'health',    'units' => ['%', 'u', 'punten']],
        'weight'      => ['label' => 'Gewicht',    'icon' => 'chart',    'accent' => 'nutrition', 'units' => ['kg', '%']],
        'strength'    => ['label' => 'Kracht',     'icon' => 'dumbbell', 'accent' => 'activity',  'units' => ['kg', 'reps']],
        'activity'    => ['label' => 'Activiteit', 'icon' => 'bolt',     'accent' => 'activity',  'units' => ['stappen', 'km', 'min', 'keer']],
        'nutrition'   => ['label' => 'Voeding',    'icon' => 'leaf',     'accent' => 'nutrition', 'units' => ['g', 'kcal', 'l']],
        'habit'       => ['label' => 'Gewoonte',   'icon' => 'sparkle',  'accent' => 'health',    'units' => ['dagen', 'keer']],
        'performance' => ['label' => 'Prestatie',  'icon' => 'pulse',    'accent' => 'activity',  'units' => ['min', 'km', 'bpm']],
        'other'       => ['label' => 'Anders',     'icon' => 'flag',     'accent' => 'health',    'units' => []],
    ],

    /**
     * How success is measured. This is what keeps the page from assuming every
     * goal is a number: the target step asks a different question per type.
     */
    'types' => [
        'value' => [
            'label'  => 'Doelwaarde',
            'hint'   => 'Eén getal om te bereiken, zoals 100 kg of 80 kg.',
            'target' => 'number',
        ],
        'habit' => [
            'label'  => 'Gewoonte',
            'hint'   => 'Iets wat je regelmatig doet, geteld in dagen.',
            'target' => 'frequency',
        ],
        'streak' => [
            'label'  => 'Reeks',
            'hint'   => 'Zoveel dagen achter elkaar volhouden.',
            'target' => 'days',
        ],
        'milestone' => [
            'label'  => 'Mijlpaal',
            'hint'   => 'Eén moment: gehaald of nog niet.',
            'target' => 'none',
        ],
    ],

    /* Durations the app supports, per §14 of the brief. */
    'durations' => [
        'week'     => ['label' => 'Week',      'days' => 7],
        'month'    => ['label' => 'Maand',     'days' => 30],
        'halfyear' => ['label' => 'Half jaar', 'days' => 182],
        'year'     => ['label' => 'Jaar',      'days' => 365],
    ],

    /**
     * Where progress could come from once the data is there. A goal names the
     * sources it would listen to; the detail page shows them so it is always
     * clear what is counting and what still needs a hand.
     */
    'sources' => [
        'sleep'     => ['label' => 'Slaap',     'icon' => 'moon',     'accent' => 'health',    'note' => 'Slaapduur en regelmaat'],
        'nutrition' => ['label' => 'Voeding',   'icon' => 'leaf',     'accent' => 'nutrition', 'note' => 'Maaltijden en hydratatie'],
        'training'  => ['label' => 'Training',  'icon' => 'dumbbell', 'accent' => 'activity',  'note' => 'Workouts en belasting'],
        'activity'  => ['label' => 'Beweging',  'icon' => 'bolt',     'accent' => 'activity',  'note' => 'Stappen en dagelijkse beweging'],
        'body'      => ['label' => 'Metingen',  'icon' => 'chart',    'accent' => 'nutrition', 'note' => 'Gewicht en lichaamssamenstelling'],
        'manual'    => ['label' => 'Handmatig', 'icon' => 'user',     'accent' => 'health',    'note' => 'Eén bevestiging per dag'],
    ],

    /* --------------------------------------------------------------- copy */
    'labels' => [
        'primary'        => 'Primair doel',
        'secondary'      => 'Overige doelen',
        'primary_chip'   => 'Primair',
        'paused_chip'    => 'Gepauzeerd',
        'add'            => 'Nieuw doel',
        'add_aria'       => 'Nieuw doel toevoegen',
        'slots_free'     => 'Nog %d van de 3 doelen vrij.',
        'slots_one'      => 'Nog 1 van de 3 doelen vrij.',
        'slots_full'     => 'Je drie doelplekken zijn bezet. Rond een doel af of verwijder er een om ruimte te maken.',
        'paused_counts'  => 'Een gepauzeerd doel houdt zijn plek.',
        'paused_line'    => 'Telt nu niet mee',
        'demo_note'      => 'Voorbeelddoelen — dit zijn nog niet je eigen gegevens.',
        'session_note'   => 'Wijzigingen blijven in dit voorbeeld niet bewaard.',
        'open_aria'      => 'Open details van %s',
        'target'         => 'Doel',
        'current'        => 'Nu',
        'remaining'      => 'Resterend',
        'no_deadline'    => 'Geen einddatum',
        'last_day'       => 'Laatste dag',
        'completed_on'   => 'Behaald op %s',
        'took'           => 'in %s',
    ],

    'empty' => [
        'active' => [
            'title' => 'Nog geen doelen',
            'body'  => 'Stel je eerste doel in — één ding waar je aan werkt. Je voortgang verschijnt hier zodra je begint.',
            'cta'   => 'Eerste doel instellen',
        ],
        'completed' => [
            'title' => 'Nog niets behaald',
            'body'  => 'Doelen die je afrondt blijven hier staan, zodat je terug kunt zien wat je al gedaan hebt.',
        ],
    ],

    /* --------------------------------------------------------- detail copy */
    'detail' => [
        'back'       => 'Doelen',
        'history'    => 'Verloop',
        'history_empty' => 'Nog geen verloop',
        'sources'    => 'Wat telt mee',
        'activity'   => 'Recent',
        'manage'     => 'Beheer',
        'manual'     => 'Vandaag afvinken',
        'manual_note'=> 'Opslaan komt met de database. Eén bevestiging per dag is genoeg.',
        'make_primary'   => 'Maak primair',
        'is_primary'     => 'Dit is je primaire doel',
        'make_secondary' => 'Maak secundair',
        'pause'          => 'Doel pauzeren',
        'resume'         => 'Doel hervatten',
        'delete'         => 'Doel verwijderen',
        'delete_confirm' => 'Weet je het zeker? Je voortgang en geschiedenis verdwijnen.',
        'delete_yes'     => 'Verwijderen',
        'delete_no'      => 'Annuleren',
        'paused_body'    => 'Dit doel telt niet mee zolang het gepauzeerd is. Je voortgang blijft bewaard.',
    ],

    /* --------------------------------------------------------- wizard copy */
    'wizard' => [
        'title' => 'Nieuw doel',
        'steps' => [
            1 => ['label' => 'Categorie', 'title' => 'Waar gaat je doel over?',   'lede' => 'Kies het gebied waar dit doel bij hoort.'],
            2 => ['label' => 'Doel',      'title' => 'Wat wil je bereiken?',       'lede' => 'Schrijf het op zoals jij het zegt.'],
            3 => ['label' => 'Streven',   'title' => 'Wanneer is het gelukt?',     'lede' => 'Dit bepaalt hoe je voortgang wordt gemeten.'],
            4 => ['label' => 'Periode',   'title' => 'Hoe lang geef je jezelf?',   'lede' => 'Je kunt dit later nog aanpassen.'],
            5 => ['label' => 'Klaar',     'title' => 'Klopt dit?',                 'lede' => 'Nog één blik voordat je begint.'],
        ],
        'back'    => 'Terug',
        'next'    => 'Volgende',
        'create'  => 'Doel aanmaken',
        'close'   => 'Sluiten',
        'name_label'     => 'Naam van je doel',
        'name_hint'      => 'Bijvoorbeeld: bench press 100 kg',
        'type_label'     => 'Hoe meet je dit?',
        'target_number'  => 'Doelwaarde',
        'target_unit'    => 'Eenheid',
        'target_days'    => 'Aantal dagen achter elkaar',
        'target_freq'    => 'Hoeveel dagen wil je dit doen?',
        'target_none'    => 'Een mijlpaal heeft geen getal: je vinkt hem af zodra het gelukt is.',
        'priority_label' => 'Prioriteit',
        'priority_swap'  => 'Je huidige primaire doel wordt dan secundair.',
        'summary_goal'     => 'Doel',
        'summary_category' => 'Categorie',
        'summary_target'   => 'Streven',
        'summary_duration' => 'Periode',
        'summary_priority' => 'Prioriteit',
        'done_title' => 'Zo ziet je doel eruit',
        'done_body'  => 'Doelen worden nog niet opgeslagen — dat komt zodra de database gekoppeld is. Je keuzes hierboven laten wel precies zien wat er straks wordt bewaard.',
        'done_close' => 'Terug naar doelen',
        /* Suggestions per category: they fill the name field, nothing more. */
        'suggestions' => [
            'health'      => ['Beter slapen', '8 uur slaap per nacht'],
            'weight'      => ['Naar 80 kg', '3 kg afvallen'],
            'strength'    => ['Bench press 100 kg', '10 pull-ups'],
            'activity'    => ['10.000 stappen per dag', '3x per week trainen'],
            'nutrition'   => ['Elke dag 2 liter water', '30 dagen gezond eten'],
            'habit'       => ['Elke dag creatine', 'Elke dag 10 minuten rekken'],
            'performance' => ['5 km onder 25 minuten', 'Rusthartslag omlaag'],
            'other'       => [],
        ],
    ],

    /* ------------------------------------------------------- shipped goals */
    'goals' => [],

    /* --------------------------------------------------------- demo goals */
    'demo_goals' => [

        [
            'id'            => 'bench',
            'name'          => 'Bench press 100 kg',
            'category'      => 'strength',
            'type'          => 'value',
            'priority'      => 'primary',
            'status'        => 'active',
            'percent'       => 72,
            'current_label' => '86 kg',
            'target_label'  => '100 kg',
            'started_days_ago' => 100,
            'ends_in_days'     => 18,
            'duration'      => 'halfyear',
            'sources'       => ['training'],
            'history'       => [40, 44, 48, 51, 55, 58, 60, 63, 66, 68, 70, 72],
            'history_step'  => 'week',
            'activity'      => [
                ['label' => 'Bankdrukken 4×5',  'meta' => '2 dagen geleden', 'value' => '86 kg'],
                ['label' => 'Bankdrukken 5×5',  'meta' => '6 dagen geleden', 'value' => '82,5 kg'],
                ['label' => 'Bankdrukken 4×6',  'meta' => '9 dagen geleden', 'value' => '82,5 kg'],
            ],
            'note' => 'Voortgang komt uit je zwaarste set per trainingsweek.',
        ],

        [
            'id'            => 'steps',
            'name'          => '10.000 stappen per dag',
            'category'      => 'activity',
            'type'          => 'habit',
            'priority'      => 'secondary',
            'status'        => 'active',
            'percent'       => 64,
            'current_label' => '19 van 30 dagen',
            'target_label'  => '30 dagen',
            'started_days_ago' => 18,
            'ends_in_days'     => 12,
            'duration'      => 'month',
            'sources'       => ['activity', 'training'],
            'history'       => [6, 11, 17, 22, 28, 33, 39, 39, 44, 50, 56, 61, 64],
            'history_step'  => 'day',
            'activity'      => [
                ['label' => 'Gisteren',        'meta' => 'Doel gehaald',      'value' => '11.240'],
                ['label' => 'Eergisteren',     'meta' => 'Doel gehaald',      'value' => '10.610'],
                ['label' => '3 dagen geleden', 'meta' => 'Net niet',          'value' => '9.480'],
            ],
            'note' => 'Elke dag boven de 10.000 telt als één dag.',
        ],

        [
            'id'            => 'eating',
            'name'          => 'Gezond eten',
            'category'      => 'nutrition',
            'type'          => 'streak',
            'priority'      => 'secondary',
            'status'        => 'active',
            'percent'       => 43,
            'current_label' => '13 van 30 dagen',
            'target_label'  => '30 dagen',
            'started_days_ago' => 13,
            'ends_in_days'     => 17,
            'duration'      => 'month',
            'sources'       => ['nutrition', 'manual'],
            'history'       => [3, 7, 10, 13, 17, 20, 23, 27, 30, 33, 37, 40, 43],
            'history_step'  => 'day',
            'activity'      => [
                ['label' => 'Gisteren',        'meta' => 'Bevestigd', 'value' => 'Ja'],
                ['label' => 'Eergisteren',     'meta' => 'Bevestigd', 'value' => 'Ja'],
                ['label' => '3 dagen geleden', 'meta' => 'Bevestigd', 'value' => 'Ja'],
            ],
            'note' => 'Deze reeks vraagt één bevestiging per dag.',
        ],

        [
            'id'            => 'water',
            'name'          => 'Elke dag 2 liter water',
            'category'      => 'nutrition',
            'type'          => 'habit',
            'priority'      => 'secondary',
            'status'        => 'completed',
            'percent'       => 100,
            'current_label' => '30 van 30 dagen',
            'target_label'  => '30 dagen',
            'started_days_ago'   => 42,
            'completed_days_ago' => 12,
            'took_days'     => 30,
            'duration'      => 'month',
            'sources'       => ['nutrition'],
            'history'       => [10, 20, 30, 43, 53, 63, 73, 83, 93, 100],
            'history_step'  => 'day',
            'activity'      => [],
            'note' => 'Afgerond zonder een dag te missen.',
        ],

        [
            'id'            => 'sleep-rhythm',
            'name'          => 'Rustiger slaapritme',
            'category'      => 'health',
            'type'          => 'value',
            'priority'      => 'primary',
            'status'        => 'completed',
            'percent'       => 100,
            'current_label' => '82 punten',
            'target_label'  => '80 punten',
            'started_days_ago'   => 138,
            'completed_days_ago' => 48,
            'took_days'     => 90,
            'duration'      => 'halfyear',
            'sources'       => ['sleep'],
            'history'       => [52, 55, 58, 57, 62, 66, 69, 72, 74, 79, 84, 100],
            'history_step'  => 'week',
            'activity'      => [],
            'note' => 'Doel bereikt met een slaapscore van 82.',
        ],
    ],
];

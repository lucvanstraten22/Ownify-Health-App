<?php
/**
 * Doelen — goal definitions, the vocabulary a goal is built from, and copy.
 *
 * ------------------------------------------------------------------
 * WHERE THE GOALS COME FROM
 * ------------------------------------------------------------------
 * This file holds the vocabulary a goal is built from — the categories, the
 * ways of measuring one, the durations and the copy. It holds no goals.
 *
 * The goals themselves are rows in the `goals` table, read for the signed-in
 * user by lib/hydrate-goals.php and written by api/goals/*.php. A user with
 * none gets the empty state below, never an example.
 *
 * ------------------------------------------------------------------
 * SHAPE
 * ------------------------------------------------------------------
 * A goal is deliberately not "a number and a target". It is:
 *
 *   name       what the user is working toward, in their own words
 *   category   which part of life it belongs to        (drives icon + accent)
 *   type       how progress is worked out              (milestone/streak/accumulate)
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
        'nutrition'   => ['label' => 'Voeding',    'icon' => 'utensils',     'accent' => 'nutrition', 'units' => ['g', 'kcal', 'l']],
        'habit'       => ['label' => 'Gewoonte',   'icon' => 'sparkle',  'accent' => 'health',    'units' => ['dagen', 'keer']],
        'performance' => ['label' => 'Prestatie',  'icon' => 'pulse',    'accent' => 'activity',  'units' => ['min', 'km', 'bpm']],
        'other'       => ['label' => 'Anders',     'icon' => 'flag',     'accent' => 'health',    'units' => []],
    ],

    /**
     * How progress is worked out — three types, and the type decides the
     * arithmetic (includes/goal-progress.php), not just the label:
     *
     *   milestone    the best result so far         60, 75, 85, 70 kg -> 85 kg
     *   streak       successful days in a row       a day that does not count breaks it
     *   accumulate   everything added together      8.000 + 11.000 + 9.000 steps
     *
     * The keys are the ones stored in goals.goal_type.
     */
    'types' => [
        'milestone' => [
            'label' => 'Mijlpaal',
            'hint'  => 'Eén concreet resultaat om te behalen. Je voortgang is gebaseerd op je beste resultaat.',
        ],
        'streak' => [
            'label' => 'Streak',
            'hint'  => 'Iets een bepaald aantal dagen achter elkaar volhouden.',
        ],
        'accumulate' => [
            'label' => 'Optellen',
            'hint'  => 'Bouw een totaal op door meerdere activiteiten of metingen over tijd bij elkaar op te tellen.',
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
        'nutrition' => ['label' => 'Voeding',   'icon' => 'utensils',     'accent' => 'nutrition', 'note' => 'Maaltijden en hydratatie'],
        'training'  => ['label' => 'Training',  'icon' => 'dumbbell', 'accent' => 'activity',  'note' => 'Workouts en belasting'],
        'activity'  => ['label' => 'Beweging',  'icon' => 'bolt',     'accent' => 'activity',  'note' => 'Stappen en dagelijkse beweging'],
        'body'      => ['label' => 'Metingen',  'icon' => 'chart',    'accent' => 'nutrition', 'note' => 'Gewicht en lichaamssamenstelling'],
        'manual'    => ['label' => 'Handmatig', 'icon' => 'user',     'accent' => 'health',    'note' => 'Je vult je voortgang zelf in'],
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
        'manual'     => 'Zelf bijhouden',
        'manual_save'  => 'Opslaan',
        'days'       => 'Per dag',
        'days_note'  => 'Dagen zonder gegevens tellen niet mee als gemist.',
        'days_window'=> 'De kalender toont de laatste zes weken. Het percentage telt de hele periode.',
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
            3 => ['label' => 'Bijhouden', 'title' => 'Hoe houd je dit bij?',       'lede' => 'Kies zelf waar je voortgang vandaan komt.'],
            4 => ['label' => 'Streven',   'title' => 'Wanneer is het gelukt?',     'lede' => 'Dit bepaalt hoe je voortgang wordt gemeten.'],
            5 => ['label' => 'Periode',   'title' => 'Hoe lang geef je jezelf?',   'lede' => 'Je kunt dit later nog aanpassen.'],
            6 => ['label' => 'Klaar',     'title' => 'Klopt dit?',                 'lede' => 'Nog één blik voordat je begint.'],
        ],

        /* Step three's copy. The manual option is not a fallback for when we
           could not work something out — it is a real answer, and plenty of
           worthwhile goals have no health data behind them at all. */
        'source_label'   => 'Waar komt je voortgang vandaan?',
        'source_manual'  => 'Geen data mogelijk',
        'source_manual_hint' => 'Handmatig bijhouden — jij vult de voortgang zelf in.',
        'source_none'    => 'Nog geen bron gekozen',
        'summary_source' => 'Bijhouden',

        /* Step four asks what the chosen type needs, in the source's own unit. */
        'target_lede' => [
            'milestone'  => 'Het resultaat dat je wilt halen. Je beste resultaat telt, ook als een latere poging minder is.',
            'streak'     => 'Hoeveel dagen achter elkaar. Eén dag die niet telt, en je begint opnieuw.',
            'accumulate' => 'Waar je naartoe telt. Alles wat je doet komt erbij.',
        ],
        'target_best'    => 'Welk resultaat wil je halen?',
        'target_total'   => 'Welk totaal wil je halen?',
        'target_better'  => 'Wat is beter?',
        'better_up'      => 'Hoger is beter',
        'better_down'    => 'Lager is beter',
        'target_measure' => 'Wat tel je op?',
        'measure_amount' => 'Een totaal',
        'measure_days'   => 'Dagen',
        'target_streak'  => 'Hoeveel dagen achter elkaar?',
        'target_days'    => 'Hoeveel dagen?',
        'daily_label'    => 'Wanneer telt een dag als gelukt?',
        'daily_floor'    => 'Minstens',
        'daily_ceiling'  => 'Hoogstens',
        'daily_hint'     => 'Wat één dag moet halen om mee te tellen, zoals minstens 10.000 stappen.',
        'tick_streak'    => 'Je vinkt elke dag af die gelukt is. Alleen dagen achter elkaar tellen: een dag zonder vinkje breekt je streak.',
        'tick_days'      => 'Je vinkt elke dag af die gelukt is. De dagen hoeven niet achter elkaar te liggen.',
        'too_short'      => 'Te kort voor %s',
        'back'    => 'Terug',
        'next'    => 'Volgende',
        'create'  => 'Doel aanmaken',
        'close'   => 'Sluiten',
        'name_label'     => 'Naam van je doel',
        'name_hint'      => 'Bijvoorbeeld: bench press 100 kg',
        'type_label'     => 'Hoe meet je dit?',
        'target_unit'    => 'Eenheid',
        'priority_label' => 'Prioriteit',
        'priority_swap'  => 'Je huidige primaire doel wordt dan secundair.',
        'summary_goal'     => 'Doel',
        'summary_category' => 'Categorie',
        'summary_target'   => 'Streven',
        'summary_duration' => 'Periode',
        'summary_priority' => 'Prioriteit',
        'done_title' => 'Zo ziet je doel eruit',
        'done_body'  => 'Je doel wordt opgeslagen zodra je op aanmaken tikt, en staat daarna op je doelenbord.',
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

];

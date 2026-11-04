<?php
/**
 * The Scorekompas — the Health Score explained: what it is made of, what is
 * changing and how it compares with the person's own past. (38.0: the
 * "Mogelijk aandachtspunt" section is gone.)
 *
 * ---------------------------------------------------------------------------
 * IT READS THE SCORE, IT NEVER SCORES
 * ---------------------------------------------------------------------------
 * Every number comes from the Health Score engine (includes/health-score.php,
 * with its numbers in config/scoring.php): the score as it stands, and the
 * score as it stood at the end of each earlier day. includes/score-compass.php
 * only reads those results. Nothing in this file changes a score — the rules
 * below only decide when the compass has enough to say something, and the
 * words it says it in.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT NEVER SAYS
 * ---------------------------------------------------------------------------
 *   - why: it says what happened when ("sinds", "in dezelfde periode"), never
 *     that one thing caused another;
 *   - what to do: never an instruction, a time or a target;
 *   - anything about anybody else: the only comparison is with the person's
 *     own earlier scores;
 *   - anything without the data for it: too little data is an empty state,
 *     never a zero and never a guess.
 */

declare(strict_types=1);

return [

    'title'    => 'Scorekompas',
    'lede'     => 'Hoe je Gezondheidsscore is opgebouwd en wat er verandert.',
    'back'     => 'Overzicht',
    /* The Overzicht card opens the compass; this ends its spoken label. */
    'open'     => 'Open het Scorekompas',
    'footnote' => 'Het Scorekompas beschrijft wat er in je eigen gegevens gebeurde, niet waarom. Je wordt alleen met jezelf vergeleken.',

    /* The direction under the score, on Overzicht and here. */
    'directions' => [
        'up'   => 'Stijgend',
        'flat' => 'Stabiel',
        'down' => 'Dalend',
    ],

    /* ------------------------------------------------------------------
       WHEN THERE IS ENOUGH TO SAY SOMETHING
       Days are days with a Health Score: a day without one is left out,
       never counted as zero.
       ------------------------------------------------------------------ */
    'rules' => [
        /* The line, and the period "Wat er verandert" looks at. */
        'trend_days'       => 30,
        /* Days with a score in it before the period has a direction. */
        'trend_min_days'   => 14,
        /* The week at each end of the period that are compared, and the days
           with a score each needs. */
        'segment_days'     => 7,
        'segment_min'      => 4,
        /* A smaller difference between the two weeks is stable. */
        'direction_points' => 2,
        /* A day-to-day change at least this big is worth naming. */
        'step_points'      => 3,
        /* A dip this far below where the period began, and back up by as
           much, is a recovery. */
        'recovery_points'  => 3,

        /* Averages: days with a score needed in the last 7 days, and in each
           30-day period. */
        'week_min'         => 4,
        'period_days'      => 30,
        'period_min'       => 15,

    ],

    /* ------------------------------------------------------------------
       1  WAAR JE SCORE UIT BESTAAT
       ------------------------------------------------------------------ */
    'composition' => [
        'title'       => 'Waar je score uit bestaat',
        /* %1$s the categories, %2$d the window in days (config/scoring.php). */
        'note'        => 'Je Gezondheidsscore is het gemiddelde van %1$s over de afgelopen %2$d dagen. Elk telt even zwaar.',
        'note_one'    => 'Je Gezondheidsscore is nu je score voor %1$s, over de afgelopen %2$d dagen: de andere categorieën tellen nog niet mee.',
        'none'        => 'Je Gezondheidsscore wordt het gemiddelde van Slaap, Voeding en Training over de afgelopen %2$d dagen, zodra er gegevens zijn.',
        'days'        => ['%d dag met gegevens', '%d dagen met gegevens'],
        /* A category with data, but none new for three days. %s its last day. */
        'expired'     => 'Telt nu niet mee: laatste gegevens van %s',
        'not_counted' => 'Telt nu niet mee',
        'empty_value' => 'Nog geen gegevens',
        /* Voeding has one part: a sentence instead of a list. %s the average cijfer. */
        'rating'      => 'Je voedingsscore is je gemiddelde dagcijfer (%s) keer tien.',

        /* The factors of each category, as config/scoring.php weighs them
           (38.0): `label` in its category's list, `title` on its own (in a
           sentence about what moved), `missing` when it has no data and
           does not count. */
        'parts' => [
            'sleep' => [
                'duration'      => ['label' => 'Slaapduur',          'title' => 'Slaapduur',               'missing' => 'Nog geen nachten'],
                'regularity'    => ['label' => 'Regelmaat',          'title' => 'Slaapregelmaat',          'missing' => 'Nog te weinig nachten'],
                'social_jetlag' => ['label' => 'Sociale jetlag',     'title' => 'Sociale jetlag',          'missing' => 'Nog geen nacht op een vrije dag én op een werkdag'],
                'efficiency'    => ['label' => 'Efficiëntie',        'title' => 'Slaapefficiëntie',        'missing' => 'Nog te weinig nachten met tijd in bed'],
                'awake'         => ['label' => 'Wakker in de nacht', 'title' => 'Tijd wakker in de nacht', 'missing' => 'Nog te weinig nachten met tijd wakker'],
                'deep'          => ['label' => 'Diepe slaap',        'title' => 'Diepe slaap',             'missing' => 'Nog te weinig nachten met slaapfasen'],
                'rem'           => ['label' => 'REM-slaap',          'title' => 'REM-slaap',               'missing' => 'Nog te weinig nachten met slaapfasen'],
                'hrv'           => ['label' => 'HRV',                'title' => 'HRV tegenover je eigen basis', 'missing' => 'Nog te weinig HRV-metingen voor een eigen basis'],
            ],
            'nutrition' => [
                'fibre'         => ['label' => 'Vezels',             'title' => 'Vezels',                  'missing' => 'Nog geen volledige dagen met vezels'],
                'sodium'        => ['label' => 'Natrium',            'title' => 'Natrium',                 'missing' => 'Nog geen volledige dagen met natrium'],
                'saturated_fat' => ['label' => 'Verzadigd vet',      'title' => 'Verzadigd vet',           'missing' => 'Nog geen volledige dagen met verzadigd vet'],
                'sugar'         => ['label' => 'Suikers',            'title' => 'Suikers',                 'missing' => 'Nog geen volledige dagen met suikers'],
                'protein'       => ['label' => 'Eiwit',              'title' => 'Eiwit',                   'missing' => 'Nog geen gewicht of volledige dagen met eiwit'],
                'water'         => ['label' => 'Water',              'title' => 'Water',                   'missing' => 'Nog geen water vastgelegd'],
                'caffeine'      => ['label' => 'Cafeïne',            'title' => 'Cafeïne voor het slapen', 'missing' => 'Nog geen cafeïne of nachten vastgelegd'],
                'fat_share'     => ['label' => 'Aandeel vet',        'title' => 'Aandeel vet',             'missing' => 'Nog geen volledige dagen met vet'],
                'energy'        => ['label' => 'Energiebalans',      'title' => 'Energiebalans',           'missing' => 'Nog geen energiedoel of volledige dagen'],
                'rating'        => ['label' => 'Dagcijfer',          'title' => 'Dagcijfer voor voeding',  'missing' => 'Nog geen dagcijfers'],
            ],
            'training' => [
                'steps'         => ['label' => 'Stappen',            'title' => 'Stappen per dag',         'missing' => 'Nog geen volledige dagen met stappen'],
                'aerobic'       => ['label' => 'Duurbeweging',       'title' => 'Minuten duurbeweging',    'missing' => 'Nog geen trainingen'],
                'strength'      => ['label' => 'Kracht',             'title' => 'Krachttraining',          'missing' => 'Nog geen trainingen'],
                'intensity'     => ['label' => 'Intensiteit',        'title' => 'Trainingsintensiteit',    'missing' => 'Nog te weinig trainingen met hartslag of ervaren inspanning'],
                'active_days'   => ['label' => 'Actieve dagen',      'title' => 'Actieve dagen',           'missing' => 'Nog geen gegevens'],
                'resting_hr'    => ['label' => 'Rusthartslag',       'title' => 'Rusthartslag',            'missing' => 'Nog geen rusthartslag'],
                'recovery'      => ['label' => 'Herstel',            'title' => 'Herstel na zware dagen',  'missing' => 'Nog te weinig zware trainingsdagen'],
                'progression'   => ['label' => 'Progressie',         'title' => 'Progressie',              'missing' => 'Nog niets om mee te vergelijken'],
            ],
        ],

        /* Parts a day recorded before 38.0 can still carry, for reading
           that day back in the history: their labels as they were. */
        'earlier_parts' => [
            'sleep'    => ['quality' => 'Kwaliteit'],
            'training' => ['volume' => 'Volume', 'balance' => 'Balans'],
        ],

        /* One line of what each factor was worked out from. */
        'facts' => [
            'duration'      => 'Gemiddeld %s per nacht',
            'regularity'    => 'Bedtijd wisselt ±%1$d min, opstaan ±%2$d min',
            'social_jetlag' => 'Midden van je slaap verschuift %s op vrije dagen',
            'efficiency'    => 'Gemiddeld %d%% van de tijd in bed geslapen',
            'awake'         => 'Gemiddeld %d min wakker per nacht',
            'stage'         => 'Gemiddeld %d%% van je slaap',
            'hrv'           => 'Gemiddeld %1$d ms, je eigen basis %2$d ms',
            'per_kcal'      => '%s g per 1.000 kcal',
            'energy_share'  => '%d%% van je energie',
            'protein'       => '%s g per kg lichaamsgewicht',
            'water'         => 'Gemiddeld %s per dag',
            'caffeine'      => 'Gemiddeld %s tussen je laatste cafeïne en je bedtijd',
            'energy'        => 'Gemiddeld %d%% naast je energiedoel',
            'rating'        => 'Gemiddeld dagcijfer %s',
            'steps'         => 'Gemiddeld %s stappen per dag',
            'aerobic'       => '%d min per week, zwaar telt dubbel',
            'strength'      => '%d min per week',
            'intensity'     => '%d%% van je duurminuten zwaar',
            'active_days'   => '%s actieve dagen per week',
            'resting_hr'    => 'Gemiddeld %d slagen per minuut',
            'recovery'      => '%1$d van %2$d zware dagen gevolgd door een rustige dag',
            'progression'   => '%s, recent tegenover eerder',
        ],
        /* What progression compared, in the order it lists them. */
        'signals' => ['pace' => 'tempo', 'goals' => 'doelresultaten'],
    ],

    /* ------------------------------------------------------------------
       2  WAT ER VERANDERT
       ------------------------------------------------------------------ */
    'trend' => [
        'title'      => 'Wat er verandert',
        'collecting' => 'Meer gegevens maken je trend duidelijker.',
        'empty'      => 'Nog niet genoeg gegevens.',
        'today'      => 'Vandaag',
        /* The chart's spoken label. %1$d days; %2$s what it shows. */
        'aria'       => 'Je Gezondheidsscore per dag, de afgelopen %1$d dagen%2$s',

        /* The sentences. Dates as "3 september"; no sentence says why. */
        'up'        => 'Je score steeg van gemiddeld %1$d in de week van %2$s naar %3$d in de afgelopen week.',
        'down'      => 'Je score daalde van gemiddeld %1$d in de week van %2$s naar %3$d in de afgelopen week.',
        'flat'      => 'Je score bleef sinds %1$s tussen %2$d en %3$d.',
        'flat_same' => 'Je score bleef sinds %1$s op %2$d.',
        'recovery'  => 'Je score zakte van gemiddeld %1$d tot %2$d rond %3$s en steeg daarna weer tot %4$d.',
        'weeks_up'   => 'Hij steeg %d weken op rij.',
        'weeks_down' => 'Hij daalde %d weken op rij.',
        'joined'       => 'Sinds %1$s telt %2$s mee in je score; die dag stond %2$s op %3$d.',
        'joined_again' => 'Sinds %1$s telt %2$s weer mee in je score; die dag stond %2$s op %3$d.',
        'left'         => 'Sinds %1$s telt %2$s niet meer mee in je score.',
        'joined_step'  => 'Op %1$s ging je score van %4$d naar %5$d; die dag ging %2$s meetellen, met %3$d.',
        'joined_step_again' => 'Op %1$s ging je score van %4$d naar %5$d; die dag ging %2$s weer meetellen, met %3$d.',
        'step'      => 'De grootste verandering in één dag was op %1$s: van %2$d naar %3$d.',
        'part'      => 'In dezelfde periode ging je score voor %1$s van gemiddeld %2$d naar %3$d.',
    ],

    /* ------------------------------------------------------------------
       2b  DE GESCHIEDENIS
       The same Health Score as it was recorded each day, over a period the
       person picks. The periods are views of one score's history, never
       scores of their own: the score itself is always the last 168 hours.
       ------------------------------------------------------------------ */
    'history' => [
        /* `spoken` goes into the chart's spoken label; `ticks` is how many
           dates the 30 days an older app draws name (the line's own dates
           are Ownify's time axis, lib/time-axis.php); `day_dots` marks
           every day — only where there are few enough days to tell apart. */
        'periods' => [
            ['days' => 7,   'label' => '7 dagen',  'spoken' => 'de afgelopen 7 dagen',  'ticks' => 4, 'day_dots' => true],
            ['days' => 30,  'label' => '30 dagen', 'spoken' => 'de afgelopen 30 dagen', 'ticks' => 3, 'day_dots' => false],
            ['days' => 90,  'label' => '90 dagen', 'spoken' => 'de afgelopen 90 dagen', 'ticks' => 4, 'day_dots' => false],
            ['days' => 365, 'label' => '1 jaar',   'spoken' => 'het afgelopen jaar',    'ticks' => 5, 'day_dots' => false],
        ],
        /* The period the card opens on: the score's own week. */
        'default'   => 7,
        'switch'    => 'Periode kiezen',
        /* The period reaches back before the first score. %s that day. */
        'since'     => 'Je geschiedenis begint op %s.',
        /* The week: too short to compare a first and a last week. */
        'week'      => 'De afgelopen 7 dagen lag je score tussen %1$d en %2$d.',
        'week_same' => 'De afgelopen 7 dagen stond je score op %d.',
        /* The chart's spoken label. %1$s the period, %2$s its direction. */
        'aria'      => 'Je Gezondheidsscore per dag, %1$s%2$s',
        /* The reading of one day. */
        'score'     => 'Gezondheidsscore',
        'carried'   => 'Geen nieuwe gegevens: de score van %s gold nog.',
        'none'      => 'Geen score op deze dag.',
        'hint'      => 'Tik of schuif over de lijn om je score te bekijken.',
        /* The line stands on Ownify's time axis (lib/time-axis.php,
           docs/CHARTS.md): over 90 days a point is a week, over a year a
           month. Its spoken label then, %1$s the period, %2$s its
           direction; its days, "12 – 18 sep"; beside them, that its score is
           their mean; or that it had none. */
        'aria_per'  => [
            'week'  => 'Je Gezondheidsscore per week, %1$s%2$s',
            'month' => 'Je Gezondheidsscore per maand, %1$s%2$s',
        ],
        'range'     => '%1$s – %2$s',
        'mean'      => ['week' => 'weekgemiddelde', 'month' => 'maandgemiddelde'],
        'empty_at'  => ['week' => 'Geen score in deze week.', 'month' => 'Geen score in deze maand.'],
    ],

    /* ------------------------------------------------------------------
       3  VERGELEKEN MET JEZELF
       ------------------------------------------------------------------ */
    'comparison' => [
        'title' => 'Vergeleken met jezelf',
        'note'  => 'Gemiddelden van je Gezondheidsscore per dag.',
        'rows'  => [
            'now'      => ['label' => 'Nu',                'note' => 'Over de afgelopen %d dagen'],
            'week'     => ['label' => 'Laatste 7 dagen',   'note' => 'Gemiddeld'],
            'period'   => ['label' => 'Laatste 30 dagen',  'note' => 'Gemiddeld'],
            'previous' => ['label' => '30 dagen daarvoor', 'note' => 'Gemiddeld'],
        ],
        'empty' => 'Nog niet genoeg gegevens',
        'delta' => [
            'up'   => '+%d ten opzichte van de 30 dagen daarvoor',
            'down' => '−%d ten opzichte van de 30 dagen daarvoor',
            'same' => 'Gelijk aan de 30 dagen daarvoor',
        ],
    ],
];

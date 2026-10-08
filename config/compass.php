<?php
/**
 * The Scorekompas — the Health Score explained: what it is made of, what is
 * changing, how it compares with the person's own past, and where it has the
 * most room.
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
 *   - what to do: an opportunity says what a higher score would go together
 *     with, never an instruction, a time or a target;
 *   - anything about anybody else: the only comparison is with the person's
 *     own earlier scores;
 *   - anything without the data for it: too little data is an empty state,
 *     never a zero and never a guess.
 */

declare(strict_types=1);

return [

    'title'    => 'Scorekompas',
    'lede'     => 'Hoe je Gezondheidsscore is opgebouwd, wat er verandert en waar de meeste ruimte zit.',
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

        /* An opportunity: a category with at least this many days of data,
           and at least this many points of room on the Health Score. Days in
           the score's own 7-day window: as many as a category needs to have
           a score at all (min_days, config/scoring.php). */
        'opportunity_min_days'   => 3,
        'opportunity_min_points' => 2,

        /* Where a scoring curve counts as at its top (config/scoring.php):
           the inputs that score at least this. */
        'top_score'        => 95,
    ],

    /* ------------------------------------------------------------------
       1  WAAR JE SCORE UIT BESTAAT
       ------------------------------------------------------------------ */
    'composition' => [
        'title'       => 'Waar je score uit bestaat',
        /* %1$s the categories, %2$d the window in days (config/scoring.php). */
        'note'        => 'Je Gezondheidsscore is het gemiddelde van %1$s over de afgelopen %2$d dagen. Elk telt even zwaar.',
        'note_one'    => 'Je Gezondheidsscore is nu je score voor %1$s, over de afgelopen %2$d dagen: de andere categorieën tellen nog niet mee.',
        'none'        => 'Je Gezondheidsscore wordt het gemiddelde van Slaap, Voeding en Sport over de afgelopen %2$d dagen, zodra er gegevens zijn.',
        'days'        => ['%d dag met gegevens', '%d dagen met gegevens'],
        /* A category with data, but none new for three days. %s its last day. */
        'expired'     => 'Telt nu niet mee: laatste gegevens van %s',
        'not_counted' => 'Telt nu niet mee',
        'empty_value' => 'Nog geen gegevens',
        /* Voeding has one part: a sentence instead of a list. %s the average cijfer. */
        'rating'      => 'Je voedingsscore is je gemiddelde dagcijfer (%s) keer tien.',

        /* The components of each category, as config/scoring.php weighs
           them: `label` in its category's list, `title` on its own (Grootste
           kans), `missing` when it has no data and does not count. */
        'parts' => [
            'sleep' => [
                'duration'   => ['label' => 'Slaapduur',   'title' => 'Slaapduur',      'missing' => 'Nog geen nachten'],
                'regularity' => ['label' => 'Regelmaat',   'title' => 'Slaapregelmaat', 'missing' => 'Nog te weinig nachten'],
                'quality'    => ['label' => 'Kwaliteit',   'title' => 'Slaapkwaliteit', 'missing' => 'Nog te weinig nachten met slaapfasen of tijd wakker'],
            ],
            'nutrition' => [
                'rating'     => ['label' => 'Dagcijfer',   'title' => 'Dagcijfer voor voeding', 'missing' => 'Nog geen dagcijfers'],
            ],
            'training' => [
                'volume'      => ['label' => 'Volume',      'title' => 'Trainingsvolume',      'missing' => 'Nog geen trainingen'],
                'intensity'   => ['label' => 'Intensiteit', 'title' => 'Trainingsintensiteit', 'missing' => 'Nog te weinig trainingen met hartslag of ervaren inspanning'],
                'progression' => ['label' => 'Progressie',  'title' => 'Progressie',           'missing' => 'Nog niets om mee te vergelijken'],
                'balance'     => ['label' => 'Balans',      'title' => 'Trainingsbalans',      'missing' => 'Nog geen trainingen'],
            ],
        ],

        /* One line of what each component was worked out from. */
        'facts' => [
            'duration'    => 'Gemiddeld %s per nacht',
            'regularity'  => 'Bedtijd wisselt ±%1$d min, opstaan ±%2$d min',
            'quality'     => ['Gemeten in %d nacht', 'Gemeten in %d nachten'],
            'volume'      => 'Gemiddeld %d min per week',
            'intensity'   => '%1$d van %2$d trainingen zwaar',
            'progression' => '%s, recent tegenover eerder',
            'balance'     => 'Gemiddeld %s trainingsdagen per week',
        ],
        /* What progression compared, in the order it lists them. */
        'signals' => ['pace' => 'tempo', 'vo2' => 'VO2max', 'goals' => 'doelresultaten'],
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
           dates the axis names; `day_dots` marks every day — only where
           there are few enough days to tell them apart. */
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
        /* What a point of the line stands for, by a period's days: a day
           where not named — 90 days a week, a year a month; a history
           younger than `half_days` shows its year as twelve half months
           from its first day (lib/hydrate-compass.php, as Gezondheid's
           Verloop). Its spoken label then, %1$s the period, %2$s its
           direction; its days, "12 – 18 sep"; beside them, that its score is
           their mean; or that it had none. */
        'group'     => [90 => 'week', 365 => 'month'],
        'half_days' => 183,
        'aria_per'  => [
            'week'  => 'Je Gezondheidsscore per week, %1$s%2$s',
            'month' => 'Je Gezondheidsscore per maand, %1$s%2$s',
            'half'  => 'Je Gezondheidsscore per halve maand, %1$s%2$s',
        ],
        'range'     => '%1$s – %2$s',
        'mean'      => ['week' => 'weekgemiddelde', 'month' => 'maandgemiddelde', 'half' => 'gemiddelde'],
        'empty_at'  => ['week' => 'Geen score in deze week.', 'month' => 'Geen score in deze maand.', 'half' => 'Geen score in deze halve maand.'],
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

    /* ------------------------------------------------------------------
       4  GROOTSTE KANS
       The component where the Health Score has the most room: its weight in
       the score times what it lacks of 100. Each pair says what was measured
       and what a higher score would go together with — never what to do.
       ------------------------------------------------------------------ */
    'opportunity' => [
        'title' => 'Grootste kans',
        'empty' => 'Nog niet genoeg gegevens om een kans aan te wijzen.',
        'none'  => 'Geen onderdeel springt eruit: overal zit weinig ruimte.',
        'gain'  => 'Op 100 zou dit onderdeel je Gezondheidsscore met zo\'n %d punten verhogen.',

        'texts' => [
            'sleep.duration.short' => [
                'fact'     => '%1$d van je %2$d nachten duurden korter dan %3$s.',
                'relation' => 'Meer nachten tussen %3$s en %4$s zouden samengaan met een hogere duurscore.',
            ],
            'sleep.duration.long' => [
                'fact'     => '%1$d van je %2$d nachten duurden langer dan %4$s.',
                'relation' => 'Meer nachten tussen %3$s en %4$s zouden samengaan met een hogere duurscore.',
            ],
            'sleep.regularity.bedtime' => [
                'fact'     => 'Je bedtijd wisselt doorgaans zo\'n %d minuten.',
                'relation' => 'Een regelmatiger bedtijd zou samengaan met een hogere regelmaatscore.',
            ],
            'sleep.regularity.wake_time' => [
                'fact'     => 'Je opstaantijd wisselt doorgaans zo\'n %d minuten.',
                'relation' => 'Een regelmatiger opstaantijd zou samengaan met een hogere regelmaatscore.',
            ],
            'sleep.regularity.duration' => [
                'fact'     => 'De lengte van je nachten wisselt doorgaans zo\'n %d minuten.',
                'relation' => 'Nachten van een regelmatiger lengte zouden samengaan met een hogere regelmaatscore.',
            ],
            'sleep.quality.efficiency' => [
                'fact'     => 'Je slaapt gemiddeld %d%% van de tijd dat je in bed ligt.',
                'relation' => 'Een hogere slaapefficiëntie zou samengaan met een hogere kwaliteitsscore.',
            ],
            'sleep.quality.awake' => [
                'fact'     => 'Je bent gemiddeld %d minuten per nacht wakker.',
                'relation' => 'Minder tijd wakker zou samengaan met een hogere kwaliteitsscore.',
            ],
            'sleep.quality.deep.more' => [
                'fact'     => 'Diepe slaap is gemiddeld %d%% van je slaap.',
                'relation' => 'Een groter aandeel diepe slaap zou samengaan met een hogere kwaliteitsscore.',
            ],
            'sleep.quality.deep.less' => [
                'fact'     => 'Diepe slaap is gemiddeld %d%% van je slaap.',
                'relation' => 'Een kleiner aandeel diepe slaap zou samengaan met een hogere kwaliteitsscore.',
            ],
            'sleep.quality.rem.more' => [
                'fact'     => 'REM-slaap is gemiddeld %d%% van je slaap.',
                'relation' => 'Een groter aandeel REM-slaap zou samengaan met een hogere kwaliteitsscore.',
            ],
            'sleep.quality.rem.less' => [
                'fact'     => 'REM-slaap is gemiddeld %d%% van je slaap.',
                'relation' => 'Een kleiner aandeel REM-slaap zou samengaan met een hogere kwaliteitsscore.',
            ],
            'nutrition.rating' => [
                'fact'     => 'Je gemiddelde dagcijfer voor voeding is %1$s, over %2$d dagen.',
                'relation' => 'Hogere dagcijfers zouden samengaan met een hogere voedingsscore.',
            ],
            'training.volume.more' => [
                'fact'     => 'Je traint gemiddeld %d minuten per week.',
                'relation' => 'Meer trainingsminuten per week zouden samengaan met een hogere volumescore.',
            ],
            'training.volume.less' => [
                'fact'     => 'Je traint gemiddeld %d minuten per week.',
                'relation' => 'Minder trainingsminuten per week zouden samengaan met een hogere volumescore.',
            ],
            'training.intensity.more' => [
                'fact'     => '%1$d van je %2$d trainingen met gemeten inspanning waren zwaar.',
                'relation' => 'Een groter aandeel zware trainingen zou samengaan met een hogere intensiteitsscore.',
            ],
            'training.intensity.less' => [
                'fact'     => '%1$d van je %2$d trainingen met gemeten inspanning waren zwaar.',
                'relation' => 'Een kleiner aandeel zware trainingen zou samengaan met een hogere intensiteitsscore.',
            ],
            'training.progression' => [
                /* %1$s what was compared, %2$s the change: "2% beter". */
                'fact'     => 'Je recente resultaten voor %1$s zijn gemiddeld %2$s dan je eerdere.',
                'same'     => 'Je recente resultaten voor %1$s zijn gemiddeld gelijk aan je eerdere.',
                'relation' => 'Betere resultaten dan eerder zouden samengaan met een hogere progressiescore.',
            ],
            'training.balance.frequency.more' => [
                'fact'     => 'Je traint gemiddeld op %s dagen per week.',
                'relation' => 'Meer trainingsdagen per week zouden samengaan met een hogere balansscore.',
            ],
            'training.balance.frequency.less' => [
                'fact'     => 'Je traint gemiddeld op %s dagen per week.',
                'relation' => 'Meer rustdagen per week zouden samengaan met een hogere balansscore.',
            ],
            'training.balance.rest' => [
                'fact'     => 'Je langste reeks trainingsdagen zonder rustdag was %d dagen.',
                'relation' => 'Kortere reeksen zonder rustdag zouden samengaan met een hogere balansscore.',
            ],
            'training.balance.spikes' => [
                'fact'     => 'In %1$d van %2$d weken lag je trainingstijd veel hoger dan in de weken ervoor.',
                'relation' => 'Een geleidelijker opbouw zou samengaan met een hogere balansscore.',
            ],
            'training.balance.hard_days' => [
                'fact'     => '%1$d van je %2$d zware trainingsdagen volgden direct op een andere zware dag.',
                'relation' => 'Minder zware dagen direct na elkaar zouden samengaan met een hogere balansscore.',
            ],
            'training.balance.sleep.short' => [
                'fact'     => 'Na trainingsdagen sliep je gemiddeld %s.',
                'relation' => 'Langere nachten na trainingsdagen zouden samengaan met een hogere balansscore.',
            ],
            'training.balance.sleep.long' => [
                'fact'     => 'Na trainingsdagen sliep je gemiddeld %s.',
                'relation' => 'Kortere nachten na trainingsdagen zouden samengaan met een hogere balansscore.',
            ],
            /* Any component without a sentence of its own, or without the
               facts to fill one. */
            'default' => [
                'fact'     => 'Je score voor %1$s staat op %2$d van 100.',
                'relation' => 'Een hogere score voor %1$s zou samengaan met een hogere Gezondheidsscore.',
            ],
        ],
    ],
];

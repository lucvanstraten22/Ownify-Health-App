<?php
/**
 * The first days — the words of a new account's setup and of the baseline
 * its first days build (docs/FIRST-DAYS.md).
 *
 *   setup        pages/setup.php on the website, SetupScreen in the app: what
 *                the person most wants to understand, their health data, a
 *                few profile facts and an optional first goal
 *   calibration  the card at the top of Overzicht for the first days
 *                (components/calibration.php, the app's CalibrationCard)
 *
 * Both apps show these words as they are: the app receives them through
 * api/app/state.php and writes none of its own, apart from the phone's own
 * Health Connect buttons.
 *
 * The tone: calm, short, about the person. An observation says what
 * happened, never what to do; nothing is called realistic, good or bad.
 */
declare(strict_types=1);

return [

    /* ==================================================================
       THE SETUP
       ================================================================== */
    'setup' => [
        'title'  => 'Hoe moet Ownify voor jou werken?',
        'count'  => 'Stap %1$d van %2$d',
        'back'   => 'Terug',
        'next'   => 'Verder',
        'logout' => 'Uitloggen',

        'steps' => [

            /* 1 — what the person most wants to understand. One answer; it
               shapes the order of things, never what is shown. */
            'focus' => [
                'label' => 'Focus',
                'title' => 'Wat wil je het liefst begrijpen?',
                'lede'  => 'Ownify stemt je overzicht hierop af. Al je gegevens blijven zichtbaar.',
                'options' => [
                    'sleep'   => ['label' => 'Slaap',   'line' => 'Hoe je slaapt, en wat je nachten verandert', 'icon' => 'moon',     'accent' => 'sleep'],
                    'energy'  => ['label' => 'Energie', 'line' => 'Hoe slaap, voeding en beweging samen je dag vormen', 'icon' => 'bolt', 'accent' => 'health'],
                    'fitness' => ['label' => 'Fitheid', 'line' => 'Je trainingen, je belasting en je vooruitgang', 'icon' => 'dumbbell', 'accent' => 'training'],
                    'weight'  => ['label' => 'Gewicht', 'line' => 'Je gewicht, en wat je eet en beweegt', 'icon' => 'chart',    'accent' => 'nutrition'],
                    'general' => ['label' => 'Alles',   'line' => 'Een breed beeld van slaap, voeding en sport', 'icon' => 'rings',    'accent' => 'health'],
                ],
                'error' => 'Je keuze kon niet worden bewaard. Probeer het opnieuw.',
            ],

            /* 2 — the health data the person already has. Only what Ownify
               can actually read; always possible to go on without. */
            'connect' => [
                'label' => 'Gegevens',
                'title' => 'Gebruik wat je al meet',
                'lede'  => 'Ownify kan je bestaande gezondheidsgegevens gebruiken voor een persoonlijker beeld, zonder dat je alles zelf hoeft bij te houden.',
                'health_connect' => [
                    'label' => 'Health Connect',
                    'note'  => 'Android',
                    /* The website cannot reach a phone: it says where to go. */
                    'web'   => 'Log in de Ownify-app op je Android-telefoon in met dit account en geef toegang tot Health Connect. Je slaap, stappen en trainingen komen dan vanzelf binnen.',
                    /* The app asks Health Connect itself. */
                    'app'   => 'Ownify leest je slaap, stappen en trainingen uit Health Connect. Je kiest zelf wat het mag lezen.',
                ],
                'connected'     => 'Verbonden',
                'not_connected' => 'Nog niet verbonden',
                'last_sync'     => 'Laatste synchronisatie: %s',
                'manual' => 'Liever zelf bijhouden? Op Gezondheid geef je je voeding elke dag een cijfer.',
                'later'  => 'Je kunt dit later altijd doen in Instellingen → Apparaten & Gezondheid.',
                'skip'   => 'Doorgaan zonder koppelen',
            ],

            /* 3 — only what Ownify actually uses, each with why. */
            'profile' => [
                'label' => 'Over jou',
                'title' => 'Een paar gegevens over jou',
                'lede'  => 'Alleen wat Ownify echt gebruikt. Alles is optioneel.',
                'fields' => [
                    'date_of_birth' => [
                        'label'  => 'Geboortedatum',
                        'reason' => 'Met je leeftijd schat Ownify je maximale hartslag, en daarmee hoe zwaar een training was.',
                        'locked' => 'Je geboortedatum staat daarna vast.',
                    ],
                    'height' => [
                        'label'  => 'Lengte',
                        'reason' => 'Met je gewicht en leeftijd berekent Ownify AI je energiebehoefte.',
                    ],
                    'weight' => [
                        'label'  => 'Gewicht',
                        'reason' => 'Het beginpunt van een gewichtsdoel, en nodig voor je energiebehoefte.',
                    ],
                ],
                'skip'  => 'Overslaan',
                'save'  => 'Opslaan en verder',
                'error' => 'Dit kon niet worden opgeslagen. Probeer het opnieuw.',
            ],

            /* 4 — an optional first goal, through the normal goal wizard. */
            'goal' => [
                'label'  => 'Doel',
                'title'  => 'Wil je meteen een doel stellen?',
                'lede'   => 'Een doel geeft je voortgang een richting. Het hoeft niet nu: je kunt het altijd instellen bij Doelen.',
                'own'    => 'Zelf een doel instellen',
                'added'  => 'Doel toegevoegd: %s. Je vindt het bij Doelen.',
                'finish' => 'Naar Ownify',
                'error'  => 'De setup kon niet worden afgerond. Probeer het opnieuw.',

                /* Only when it can be worked out from the person's own data:
                   their average, and the next step up from it. Never called
                   realistic — it is a possibility, theirs to take or leave. */
                'suggestion' => [
                    'eyebrow' => 'Een mogelijk eerste doel',
                    'add'     => 'Toevoegen',
                    'decline' => 'Niet nu',
                    'sleep' => [
                        'basis' => ['Je sliep de afgelopen %1$d nachten gemiddeld %2$s.'],
                        /* %1$d nights, %2$s hours ("7" or "7,5") */
                        'name'  => '%1$d nachten van minstens %2$s uur',
                    ],
                    'training' => [
                        'basis' => ['Je trainde de afgelopen twee weken %1$d keer.'],
                        'name'  => '%d trainingen deze week',
                    ],
                ],
            ],
        ],
    ],

    /* ==================================================================
       THE FIRST DAYS ON OVERZICHT
       ================================================================== */
    'calibration' => [
        /* Days the card is shown: the first three, and two more to arrive
           at the starting point. */
        'window' => 5,
        'days'   => 3,

        'eyebrow_day' => 'Dag %1$d van %2$d',
        'eyebrow'     => 'Je basislijn',

        'building' => [
            'title' => 'Je basislijn wordt opgebouwd',
            'lede'  => [
                1       => 'De komende dagen leert Ownify je ritme kennen. Je eerste score volgt zodra er 3 dagen met gegevens zijn.',
                2       => 'Ownify leert je ritme kennen. Dit is er tot nu toe bij gekomen.',
                3       => 'Er zijn nog te weinig gegevens voor je eerste score. Hij volgt zodra er 3 dagen van zijn.',
                'later' => 'Je basislijn is bijna klaar. Je eerste score volgt zodra er 3 dagen met gegevens zijn.',
            ],
        ],

        /* One row per category: how far it is, in its own unit. */
        'progress' => [
            'count' => '%1$d van %2$d %3$s',
            'units' => [
                'sleep'     => ['nacht', 'nachten'],
                'nutrition' => ['dag met een cijfer', 'dagen met een cijfer'],
                'training'  => ['trainingsdag', 'trainingsdagen'],
            ],
            /* What the days so far hold. */
            'detail' => [
                'sleep_one'      => '%s geslapen',
                'sleep'          => 'Gemiddeld %s per nacht',
                'nutrition_one'  => 'Een %s',
                'nutrition'      => 'Gemiddeld een %s',
                'training'       => ['%1$d training, %2$d min', '%1$d trainingen, %2$d min'],
            ],
            /* Where a category with nothing yet gets its data from. */
            'how' => [
                'connected' => 'Komt binnen via je koppeling',
                'source'    => 'Komt binnen zodra je Health Connect koppelt',
                'nutrition' => 'Geef je voeding een cijfer op Gezondheid',
            ],
        ],

        /* One small fact a day, from the newest data — never advice. */
        'observation' => [
            'sleep_one'       => 'Je laatste nacht: %1$s geslapen, van %2$s tot %3$s.',
            'sleep'           => 'Je ging de afgelopen %1$d nachten gemiddeld om %2$s naar bed.',
            'nutrition_one'   => 'Je gaf je voeding %1$s een %2$s.',
            'nutrition'       => 'Je dagcijfers voor voeding lagen tussen %1$s en %2$s.',
            'nutrition_same'  => 'Je gaf je voeding %1$d dagen een %2$s.',
            'training'        => 'Je laatste training duurde %1$d minuten, %2$s.',
            'weight'          => 'Je startgewicht: %s kg.',
            'today'           => 'vandaag',
            'yesterday'       => 'gisteren',
            'on'              => 'op %s',
        ],

        /* The first score: the engine's own, with its real components. */
        'first' => [
            'title'   => 'Je eerste %s',
            'names'   => ['sleep' => 'slaapscore', 'nutrition' => 'voedingsscore', 'training' => 'trainingsscore'],
            'only'    => 'Je gezondheidsscore rust voorlopig alleen op %1$s. %2$s tellen mee zodra er 3 dagen van zijn.',
            'only_one'=> 'Je gezondheidsscore rust voorlopig alleen op %1$s. %2$s telt mee zodra er 3 dagen van zijn.',
            'all'     => 'Je gezondheidsscore is nu %1$d: het gemiddelde van %2$s.',
            'alone'   => 'Je gezondheidsscore rust nu alleen op %s.',
            'open'    => 'Bekijk de opbouw in het Scorekompas',
        ],

        /* A category with enough days once, but nothing new for three
           (config/scoring.php, expiry_days): it does not count now — which
           is not the same as too little data. */
        'expired' => [
            'title'    => 'Geen nieuwe gegevens',
            'lede'     => 'Er zijn al een paar dagen geen nieuwe gegevens, dus je eerdere tellen nu niet mee. Je score komt terug zodra er weer gegevens binnenkomen.',
            /* In the category's row; %s = "op 30 september". */
            'detail'   => 'Laatste gegevens %s, telt nu niet mee',
            'note_one' => '%s telt weer mee zodra er nieuwe gegevens zijn.',
            'note'     => '%s tellen weer mee zodra er nieuwe gegevens zijn.',
        ],

        /* The end of the first days: where the person starts from. */
        'baseline' => [
            'title'   => 'Je startpunt',
            'lede'    => 'Geen oordeel, maar een vertrekpunt: vanaf hier zie je wat er verandert.',
            'missing' => '%s komen erbij zodra er 3 dagen van zijn.',
            'missing_one' => '%s komt erbij zodra er 3 dagen van zijn.',
            'facts' => [
                'sleep'     => 'Gemiddeld %s per nacht',
                'nutrition' => 'Gemiddeld dagcijfer %s',
                'training'  => 'Gemiddeld %s trainingsdagen per week',
            ],
            'empty_title' => 'Nog geen startpunt',
            'empty_lede'  => 'Daarvoor zijn 3 dagen met gegevens nodig. Zodra ze er zijn, verschijnt je eerste score hier.',
            'open'    => 'Bekijk je score in het Scorekompas',
        ],

        /* Names in a sentence: "slaap", "voeding en sport". */
        'names' => ['sleep' => 'slaap', 'nutrition' => 'voeding', 'training' => 'sport'],
    ],
];

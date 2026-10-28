<?php
/**
 * Insights: what Ownify may say about a person's own data, and with which
 * research (docs/INSIGHTS.md).
 *
 * ---------------------------------------------------------------------------
 * HOW AN INSIGHT COMES ABOUT
 * ---------------------------------------------------------------------------
 * Once per local day — on the first page or app load — the server reads the
 * person's derived facts (includes/insights/facts.php: nights, workouts, day
 * totals, logged meals, all counted once by the existing rules), runs every
 * rule below, keeps the candidates the data and the research allow, ranks
 * them and stores at most one per slot and one suggestion
 * (includes/insights/lifecycle.php). Both apps show that stored result.
 *
 * A rule names the claims it may use, per age group, in the source's own age
 * boundaries (config/research.php). Without a known age, a rule whose claim
 * has an age bound gives nothing. `applicability` says how closely Ownify's
 * data matches what the research measured:
 *
 *   direct  the same thing (measured sleep, counted steps)
 *   proxy   an approximation (recorded workouts for all activity, logged
 *           food for what was eaten): the label shown is one step lower
 *   weak    a loose fit (logged sodium for all salt, BMI for one person):
 *           two steps lower
 *
 * Below Beperkt onderbouwd there is no insight at all.
 *
 * ---------------------------------------------------------------------------
 * THE NUMBERS IN `data` ARE OWNIFY'S OWN DATA RULES, NOT RESEARCH
 * ---------------------------------------------------------------------------
 * How many nights make a week, when a logged day looks complete, how big a
 * change must be: these decide whether there is enough data to say anything,
 * never what is healthy. Every number about health comes from a claim.
 *
 * The texts are Dutch and translated like any config (lang/en/insights.php).
 * They describe and compare; they never say why (no causes), never diagnose
 * and never promise an outcome. A suggestion is always optional.
 */

declare(strict_types=1);

return [

    /* Raise when a rule or a text changes meaning: the next load works the
       day out again instead of reusing what was stored under the old one. */
    'engine_version' => '1',

    /* ------------------------------------------------------------ lifecycle */
    'lifecycle' => [
        /* A 'running' day older than this was left by a request that died. */
        'stale_running_seconds' => 120,
        /* After a save or a sync: a reassessment on the next load, at most
           this often and this many times a day. Never a new Gemini call,
           never a second suggestion. */
        'reassess_min_minutes'  => 10,
        'reassess_max_per_day'  => 8,
        /* Stored insights and days older than this are deleted. */
        'keep_days'             => 35,
        /* The same suggestion is not repeated within this many days. */
        'suggestion_cooldown_days' => 3,
    ],

    /* ------------------------------------------------- data rules (Ownify) */
    'data' => [
        'sleep_window_hours'     => 168,  // the Health Score's window: the last 7 days
        'sleep_min_nights'       => 5,    // nights in it before anything is said
        'sleep_fresh_days'       => 2,    // the latest night at most this many days ago
        'sleep_previous_days'    => 21,   // the stretch before it, for a change in regularity
        'sleep_previous_min'     => 10,   // nights in that stretch
        'regularity_change_min'  => 15,   // minutes of spread before it counts as changed
        'regularity_level_min'   => 45,   // a spread worth mentioning on its own, in minutes
        'steps_days'             => 7,    // complete days, yesterday back
        'steps_min_days'         => 5,
        'activity_fresh_days'    => 2,    // a steps reading at most this old: the sync is alive
        'workout_history_days'   => 28,   // the person records workouts at all …
        'workout_history_min'    => 2,    // … at least this many in that time
        'nutrition_days'         => 7,    // complete days, yesterday back
        'nutrition_min_days'     => 3,    // days whose log looks complete
        'complete_energy_share'  => [0.7, 1.6],  // logged kcal against the energy target
        'weight_max_age_days'    => 180,  // a weight older than this is not used
        'strength_types'         => ['strength', 'weightlifting', 'weight_training', 'calisthenics'],
    ],

    /* ---------------------------------------------------------------- slots */
    /* Where each slot shows (both apps): Overzicht's Nuttige inzichten for
       sleep, nutrition and training, and the page it belongs to; body in
       Instellingen → Account, under Lichaamsmaten; the suggestion in Eén kleine
       suggestie. In this order on Overzicht. */
    'slots' => ['sleep', 'nutrition', 'training', 'body'],

    /* ---------------------------------------------------------------- rules */
    /* claims: [age_min, age_max (null: no upper), claim key]; the first that
       fits the age is used. relevance per outcome: how much it matters to
       say (0: not an insight on its own). suggest: the outcomes that may
       become the day's suggestion, and with which claim. */
    'rules' => [
        'sleep.duration' => [
            'slot' => 'sleep', 'applicability' => 'direct',
            'claims' => [[6, 17, 'sleep.duration.youth'], [18, 60, 'sleep.duration.adults'],
                         [61, 64, 'sleep.duration.cdc'], [65, null, 'sleep.duration.nsf']],
            'relevance' => ['below' => 3, 'above' => 2, 'within' => 1],
            'suggest' => ['below' => null],
        ],
        'sleep.regularity' => [
            'slot' => 'sleep', 'applicability' => 'direct',
            'claims' => [[null, null, 'sleep.regularity']],
            'relevance' => ['less_steady' => 3, 'steadier' => 2, 'level' => 1],
            'suggest' => ['less_steady' => null, 'level' => null],
        ],
        'activity.minutes' => [
            'slot' => 'training', 'applicability' => 'proxy',
            'claims' => [[4, 17, 'activity.gr.youth'], [18, null, 'activity.gr.adults']],
            'relevance' => ['below' => 3, 'reached' => 1],
            'suggest' => ['below' => 'activity.who.some'],
        ],
        'activity.strength' => [
            'slot' => 'training', 'applicability' => 'proxy',
            'claims' => [[4, 17, 'activity.gr.youth'], [18, null, 'activity.gr.adults']],
            'relevance' => ['below' => 2, 'reached' => 1],
            'suggest' => ['below' => null],
        ],
        'activity.steps' => [
            'slot' => 'training', 'applicability' => 'direct',
            /* Below 7.000 a day: Ding's dose–response; from there, Paluch's
               plateau for the person's age. */
            'claims' => [[18, null, 'activity.steps.ding']],
            'plateau' => [[18, null, 'activity.steps.paluch']],
            'relevance' => ['below' => 3, 'toward' => 1, 'plateau' => 1],
            'suggest' => ['below' => null],
        ],
        'nutrition.fat' => [
            'slot' => 'nutrition', 'applicability' => 'proxy',
            'claims' => [[4, null, 'nutrition.fat']],
            'relevance' => ['below' => 2, 'above' => 2, 'within' => 1],
            'suggest' => [],
        ],
        'nutrition.saturated_fat' => [
            'slot' => 'nutrition', 'applicability' => 'proxy',
            'claims' => [[null, null, 'nutrition.saturated_fat']],
            'relevance' => ['above' => 3, 'within' => 1],
            'suggest' => ['above' => null],
        ],
        'nutrition.fibre' => [
            'slot' => 'nutrition', 'applicability' => 'proxy',
            'claims' => [[2, null, 'nutrition.fibre']],
            'relevance' => ['below' => 3, 'within' => 1, 'above' => 1],
            'suggest' => ['below' => null],
        ],
        'nutrition.protein' => [
            'slot' => 'nutrition', 'applicability' => 'proxy',
            'claims' => [[18, null, 'nutrition.protein']],
            'relevance' => ['below' => 3, 'reached' => 1],
            'suggest' => ['below' => null],
        ],
        'nutrition.salt' => [
            'slot' => 'nutrition', 'applicability' => 'weak',
            'claims' => [[18, null, 'nutrition.salt']],
            'relevance' => ['above' => 2, 'within' => 1],
            'suggest' => ['above' => null],
        ],
        'body.bmi' => [
            'slot' => 'body', 'applicability' => 'weak',
            'claims' => [[18, null, 'body.bmi']],
            'relevance' => ['below' => 1, 'overweight' => 1, 'obesity' => 1],
            'suggest' => [],
        ],
    ],

    /* Ranking: relevance first, then the evidence as shown, then how much
       data there was; the same rule and outcome as yesterday in this slot
       counts a little less, so a new finding can come first. */
    'rank' => ['relevance' => 10, 'evidence' => 3, 'quality' => 2, 'repeat_penalty' => 4],

    /* ----------------------------------------------------------------- texts */
    'copy' => [
        /* The option beside every label chip; the page it opens is titled
           "Uit onderzoek blijkt" (config/research.php). */
        'research'   => 'Bekijk onderzoek',
        'evidence'   => 'Bewijskracht',
        'suggestion_note' => 'Altijd een suggestie, nooit een opdracht.',
        'withdrawn'  => 'Deze suggestie past niet meer bij je nieuwste gegevens.',

        'titles' => [
            'sleep.duration'          => 'Slaapduur',
            'sleep.regularity'        => 'Slaapritme',
            'activity.minutes'        => 'Beweging',
            'activity.strength'       => 'Spierversterkend bewegen',
            'activity.steps'          => 'Stappen',
            'nutrition.fat'           => 'Vet',
            'nutrition.saturated_fat' => 'Verzadigd vet',
            'nutrition.fibre'         => 'Vezels',
            'nutrition.protein'       => 'Eiwit',
            'nutrition.salt'          => 'Zout',
            'body.bmi'                => 'BMI',
        ],

        /* The insight itself: rule => outcome => sentence. The arguments are
           filled in by includes/insights/render.php, always formatted for the
           person's language and units. */
        'bodies' => [
            'sleep.duration' => [
                /* %1$d nights, %2$s average (h:mm), %3$d min hours, %4$d max hours */
                'below_min'    => 'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Voor jouw leeftijd wordt minstens %3$d uur aangeraden.',
                'below_range'  => 'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Voor jouw leeftijd wordt %3$d tot %4$d uur aangeraden.',
                'within_min'   => 'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat is minstens de %3$d uur die voor jouw leeftijd wordt aangeraden.',
                'within_range' => 'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat valt binnen de %3$d tot %4$d uur die voor jouw leeftijd wordt aangeraden.',
                'above_range'  => 'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat is meer dan de %3$d tot %4$d uur die voor jouw leeftijd wordt genoemd; ook meer slaap kan passend zijn.',
            ],
            'sleep.regularity' => [
                /* %1$d nights, %2$d bedtime spread, %3$d wake spread, %4$d spread before, %5$d spread now */
                'level'       => 'Je bedtijd wisselde de afgelopen %1$d nachten zo\'n %2$d minuten en je opstaantijd zo\'n %3$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.',
                'less_steady' => 'Je slaaptijden wisselden de afgelopen %1$d nachten meer dan in de drie weken ervoor: zo\'n %5$d minuten, tegen %4$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.',
                'steadier'    => 'Je slaaptijden wisselden de afgelopen %1$d nachten minder dan in de drie weken ervoor: zo\'n %5$d minuten, tegen %4$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.',
            ],
            'activity.minutes' => [
                /* %1$s minutes recorded (or per day), %2$d the guideline */
                'below_week'   => 'Je legde de afgelopen 7 dagen %1$s minuten training vast. De Beweegrichtlijn noemt minstens %2$d minuten matig intensief bewegen per week. Ook wandelen en fietsen tellen mee, en die ziet Ownify niet allemaal.',
                'reached_week' => 'Je legde de afgelopen 7 dagen %1$s minuten training vast: al minstens de %2$d minuten per week uit de Beweegrichtlijn, en dan telt Ownify alleen je vastgelegde trainingen.',
                'below_day'    => 'Je legde de afgelopen 7 dagen gemiddeld %1$s minuten training per dag vast. De Beweegrichtlijn noemt voor kinderen en jongeren elke dag minstens %2$d minuten bewegen. Ook buitenspelen en fietsen tellen mee, en die ziet Ownify niet allemaal.',
                'reached_day'  => 'Je legde de afgelopen 7 dagen gemiddeld %1$s minuten training per dag vast: al minstens de %2$d minuten per dag uit de Beweegrichtlijn.',
            ],
            'activity.strength' => [
                /* %1$d days with strength training, %2$d the guideline */
                'below'   => 'Je legde de afgelopen 7 dagen op %1$d dagen krachttraining vast. De Beweegrichtlijn noemt minstens %2$d keer per week spier- en botversterkende activiteiten.',
                'reached' => 'Je legde de afgelopen 7 dagen op %1$d dagen krachttraining vast: minstens de %2$d keer per week spier- en botversterkende activiteiten uit de Beweegrichtlijn.',
            ],
            'activity.steps' => [
                /* %1$d days, %2$s average steps, %3$s reference, %4$s plateau low, %5$s plateau high */
                'below'   => 'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek hangt meer stappen per dag samen met minder gezondheidsrisico\'s; rond %3$s stappen per dag was dat verband al duidelijk.',
                'toward'  => 'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek bij volwassenen van jouw leeftijd vlakte het verband met een lager sterfterisico af rond %4$s tot %5$s stappen per dag.',
                'plateau' => 'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek bij volwassenen van jouw leeftijd vlakte het verband met een lager sterfterisico af rond %4$s tot %5$s stappen per dag; daar zit je al.',
            ],
            'nutrition.fat' => [
                /* %1$d days, %2$s share, %3$d low, %4$d high */
                'below'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit vet. De Gezondheidsraad noemt %3$d tot %4$d %%; de kwaliteit van wat je eet telt daarbij meer dan de hoeveelheid vet.',
                'within' => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit vet: binnen de %3$d tot %4$d %% die de Gezondheidsraad noemt.',
                'above'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit vet. De Gezondheidsraad noemt %3$d tot %4$d %%; de kwaliteit van wat je eet telt daarbij meer dan de hoeveelheid vet.',
            ],
            'nutrition.saturated_fat' => [
                /* %1$d days, %2$s share, %3$d interim maximum */
                'above'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit verzadigd vet. De Gezondheidsraad noemt zo laag mogelijk, met %3$d %% als tussenstap.',
                'within' => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit verzadigd vet: onder de %3$d %% die de Gezondheidsraad als tussenstap noemt. Het advies is zo laag mogelijk.',
            ],
            'nutrition.fibre' => [
                /* %1$d days, %2$s g per 1.000 kcal, %3$s low, %4$s high */
                'below'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal. De Gezondheidsraad noemt %3$s tot %4$s gram per 1.000 kcal.',
                'within' => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal: binnen de %3$s tot %4$s gram die de Gezondheidsraad noemt.',
                'above'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal: meer dan de %3$s tot %4$s gram die de Gezondheidsraad noemt.',
            ],
            'nutrition.protein' => [
                /* %1$d days, %2$s g per kg, %3$s norm */
                'below'   => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram eiwit per kilogram lichaamsgewicht. De Gezondheidsraad raadt volwassenen %3$s gram per kilogram per dag aan.',
                'reached' => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram eiwit per kilogram lichaamsgewicht: minstens de %3$s gram die de Gezondheidsraad volwassenen aanraadt.',
            ],
            'nutrition.salt' => [
                /* %1$d days, %2$s grams of salt, %3$d maximum */
                'above'  => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam het natrium in je log neer op zo\'n %2$s gram zout per dag. De Gezondheidsraad noemt maximaal %3$d gram; zout dat je zelf toevoegt, staat meestal niet in je log.',
                'within' => 'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam het natrium in je log neer op zo\'n %2$s gram zout per dag: onder de %3$d gram die de Gezondheidsraad als maximum noemt. Zout dat je zelf toevoegt, staat meestal niet in je log.',
            ],
            'body.bmi' => [
                /* %1$s BMI, %2$d overweight from, %3$d obesity from */
                'below'      => 'Met je lengte en gewicht is je BMI %1$s. De WHO gebruikt bij volwassenen %2$d als grens voor overgewicht en %3$d voor obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.',
                'overweight' => 'Met je lengte en gewicht is je BMI %1$s. De WHO noemt bij volwassenen een BMI vanaf %2$d overgewicht en vanaf %3$d obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.',
                'obesity'    => 'Met je lengte en gewicht is je BMI %1$s. De WHO noemt bij volwassenen een BMI vanaf %2$d overgewicht en vanaf %3$d obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.',
            ],
        ],

        /* The suggestion: optional, small, and never a promise. */
        'suggestions' => [
            'sleep.duration.below'          => 'Misschien iets voor deze week: één avond wat eerder naar bed gaan, en kijken hoe die nacht verloopt.',
            'sleep.regularity.less_steady'  => 'Misschien iets voor deze week: een paar avonden rond dezelfde tijd naar bed gaan.',
            'sleep.regularity.level'        => 'Misschien iets voor deze week: een paar avonden rond dezelfde tijd naar bed gaan.',
            'activity.minutes.below'        => 'Misschien iets voor deze week: een extra wandeling of fietstocht. Ook een korte telt mee.',
            'activity.strength.below'       => 'Misschien iets voor deze week: een moment voor oefeningen die je spieren versterken, zoals oefeningen met je eigen gewicht.',
            'activity.steps.below'          => 'Misschien iets voor deze week: op een paar dagen een korte wandeling extra.',
            'nutrition.saturated_fat.above' => 'Misschien iets voor deze week: eens kijken uit welke producten het verzadigd vet in je log komt.',
            'nutrition.fibre.below'         => 'Misschien iets voor deze week: eens volkoren kiezen, of peulvruchten, groente of noten aan een maaltijd toevoegen.',
            'nutrition.protein.below'       => 'Misschien iets voor deze week: eens kijken in welke maaltijden je eiwit eet.',
            'nutrition.salt.above'          => 'Misschien iets voor deze week: eens kijken hoeveel zout er in je vaste producten zit.',
        ],

        /* Overzicht's three rows when a slot has nothing to say (yet). */
        'empty' => [
            'sleep'     => ['title' => 'Slaap',    'body' => 'Hier verschijnt een inzicht zodra er genoeg nachten zijn gemeten en je leeftijd bekend is.'],
            'nutrition' => ['title' => 'Voeding',  'body' => 'Hier verschijnt een inzicht zodra je een paar dagen volledig hebt gelogd.'],
            'training'  => ['title' => 'Beweging', 'body' => 'Hier verschijnt een inzicht zodra er een week aan stappen of trainingen binnen is.'],
        ],
        'subtitle_empty' => 'Verschijnt zodra er genoeg gegevens zijn',
        'subtitle'       => 'Uit je eigen gegevens, met het onderzoek erbij',
        'no_suggestion'  => 'Vandaag geen suggestie',
        'no_suggestion_text' => 'Er is vandaag niets waarbij een kleine suggestie past. Dat is ook een uitkomst.',
    ],

    /* ------------------------------------------------- Gemini's wording */
    /* Only with the separate consent (user_profiles.ai_insights_consent) and
       only to reword what the template already says: the facts, the claim
       and its limits go along, nothing else. One request per person per day
       at most, under the shared limits of config/ai.php. */
    'consent_version' => '2026-10-insights-gemini-free',
    'gemini' => [
        /* Off for now: whether health data may go to Gemini's free API is
           not decided yet. Off, the Inzichten switch is not shown, a yes
           cannot be given, no wording is asked for and none is shown; every
           insight is Ownify's own sentence. OwnifyAI is not affected. Set to
           true once that is decided (docs/INSIGHTS.md). */
        'enabled'              => false,
        'calls_per_user_day'   => 1,
        /* Of config/ai.php's global_daily_limit, the most insights may use:
           the chat keeps the rest. */
        'global_daily_calls'   => 60,
        'max_output_tokens'    => 2048,
        'body_max_chars'       => 420,
        /* Words a reworded insight may never contain: causes, diagnoses,
           orders and comparisons with other people. */
        'forbidden' => [
            'nl' => ['omdat', 'doordat', 'vanwege', 'dankzij', 'waardoor', 'veroorzaak', 'zorgt ervoor', 'leidt tot', 'komt door',
                     'het gevolg', 'je moet', 'moet je', 'zorg dat', 'zorg voor', 'diagnose', 'ziekte', 'stoornis', 'apneu',
                     'slapeloosheid', 'gegarandeerd', 'garantie', 'altijd beter', 'anderen', 'leeftijdsgenoten', 'gemiddelde nederlander',
                     'http', 'www.'],
            'en' => ['because', 'due to', 'thanks to', 'caused', 'causes', 'leads to', 'results in', 'you must', 'you should',
                     'make sure', 'diagnos', 'disease', 'disorder', 'apnea', 'apnoea', 'insomnia', 'guarantee', 'others',
                     'other people', 'your peers', 'average person', 'http', 'www.'],
        ],
    ],
];

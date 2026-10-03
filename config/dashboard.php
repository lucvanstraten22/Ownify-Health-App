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
        'name'        => 'Ownify',
        'tagline'     => 'Gezondheidsoverzicht',
        'locale'      => 'nl',
        'theme_color' => '#302D2F',
        /* The same, in White Mode (lib/theme.php): its --bg-main. */
        'theme_color_light' => '#FBFAFA',
    ],

    /* -------------------------------------------------- opening screen */
    /* What everyone who is not signed in sees (pages/welcome.php). The title
       is the app name above; the rest is here and only here. */
    'welcome' => [
        'subtitle' => 'Je slaap, voeding en sport in één helder overzicht.',
        'login'    => 'Inloggen',
        'register' => 'Registreren',
    ],

    /**
     * The focus: what the person most wants to understand, chosen in the setup
     * a new account starts with and changeable in Instellingen → Account
     * (includes/setup.php, docs/FIRST-DAYS.md). app_page_data() fills in the
     * account's own; `general` until there is one. It decides what comes
     * first — the ring's legend, the Scorekompas, the first days — never
     * what is shown. The chip on the score card says it.
     */
    'focus'        => 'general',
    'focus_labels' => [
        'general' => 'Alles',
        'sleep'   => 'Slaap',
        'energy'  => 'Energie',
        'fitness' => 'Fitheid',
        'weight'  => 'Gewicht',
    ],

    /* -------------------------------------------------- header */
    'header' => [
        'devices' => [
            'label' => 'Apparaten',
            'aria'  => 'Verbonden apparaten beheren',
            /* The quick look behind the button: what is linked, and the way
               to the devices screen (components/devices-popup.php). */
            'empty' => 'Geen apparaten gekoppeld',
            'add'   => 'Apparaat koppelen',
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
            /* Instead, once some data is in but not yet enough for a score;
               the hint then says how many days are still needed. */
            'caption_collecting' => 'Nog geen score',
            'description' => 'Je score bundelt slaap, voeding en beweging over de afgelopen 90 dagen.',
            'empty_hint'  => 'Verbind een bron om je gezondheidsscore te berekenen.',
        ],

        /* Ring legend — the three pillars the score averages, each with its own
         * score beside it. `area` points at the Gezondheid area the row reads
         * from, which is why the Sport row can carry the Training score without
         * either name having to change. The three count equally, so there are
         * no weights here. Values are DERIVED, like the overall score.
         * `accent` is the pillar's own category colour and never changes;
         * `score_band` (high | mid | low, or null without a score) is how high
         * its score is — the colour of the dot beside it — filled in by
         * health_contributor_scores() from config/scoring.php. */
        'contributors' => [
            ['area' => 'sleep',     'label' => 'Slaap',   'accent' => 'sleep',     'value' => null, 'score_band' => null],
            ['area' => 'nutrition', 'label' => 'Voeding', 'accent' => 'nutrition', 'value' => null, 'score_band' => null],
            ['area' => 'training',  'label' => 'Sport',   'accent' => 'training',  'value' => null, 'score_band' => null],
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
        'description' => 'Je voortgang van deze week verschijnt hier zodra je een doel instelt.',
        /* A goal exists but has nothing measured against it yet — true the
           moment one is created, and until something is recorded. */
        'pending'     => 'Je voortgang verschijnt zodra je iets vastlegt voor dit doel.',
        'cta'         => ['label' => 'Doel instellen', 'enabled' => true, 'note' => 'Opent de Doelen-pagina'],
        /* `at`: where each stop sits on the bar, in percent, and the progress
           at which it is reached — the same number, so a dot lights as the
           fill passes it. */
        'milestones'  => [
            ['label' => 'Start',      'at' => 0,   'reached' => false],
            ['label' => 'Halverwege', 'at' => 50,  'reached' => false],
            ['label' => 'Bijna',      'at' => 85,  'reached' => false],
            ['label' => 'Doel',       'at' => 100, 'reached' => false],
        ],
        /* What the bar says when you point at it or hold it: "85 kg van
           100 kg". Null until a goal has both numbers. */
        'reading'     => null,
    ],

    /* -------------------------------------------------- insights */
    'insights' => [
        'title'    => 'Nuttige inzichten',
        'subtitle' => 'Verschijnt zodra er ritme zichtbaar is',
        'state'    => 'empty',
        'items'    => [
            [
                'icon'   => 'pulse',
                'accent' => 'sleep',
                'title'  => 'Ritme in je week',
                'body'   => 'Hier zie je straks welk dagritme het beste bij je past.',
                'state'  => 'empty',
            ],
            [
                'icon'   => 'utensils',
                'accent' => 'nutrition',
                'title'  => 'Voeding en energie',
                'body'   => 'Hier komt het verband tussen je eetmomenten en je energie.',
                'state'  => 'empty',
            ],
            [
                'icon'   => 'bolt',
                'accent' => 'training',
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
    /**
     * Ownify AI — the assistant in the sheet you swipe up. Both apps show
     * these words as they are; the conversation itself comes from
     * api/ai/state.php and api/ai/chat.php (includes/ai/).
     *
     * `consent` is what people say yes to before anything goes to Google
     * Gemini. Change its substance and change `consent_version` in
     * config/ai.php with it, so everybody is asked again.
     */
    'ai' => [
        'title'   => 'Ownify AI',
        'status'  => 'Je persoonlijke gezondheidsassistent',
        'open'    => ['aria' => 'Ownify AI openen — of veeg omhoog'],
        'close'   => ['label' => 'Sluiten', 'aria' => 'Ownify AI sluiten — of veeg omlaag'],
        'composer' => [
            'placeholder' => 'Vraag iets over je gezondheid…',
            'send'        => 'Versturen',
            'aria'        => 'Je vraag aan Ownify AI',
            'note'        => '',
        ],
        'new_chat'      => 'Nieuw gesprek',
        'history'       => 'Gesprekken',
        'history_empty' => 'Nog geen gesprekken.',
        'delete_chat'   => 'Gesprek verwijderen',
        'thinking'      => 'Ownify AI denkt na…',
        'remaining'     => 'Nog %d van %d berichten vandaag',
        'retry'         => 'Opnieuw proberen',

        'empty' => [
            'title'       => 'Je persoonlijke gezondheidsassistent',
            'body'        => 'Stel een vraag over je slaap, voeding, training of voortgang — met je eigen Ownify-gegevens.',
            'no_data'     => 'Je hebt nog geen gezondheidsgegevens. Koppel een bron of vul iets in, dan kan de assistent je er meer over vertellen. Algemene vragen kun je altijd stellen.',
            'suggestions' => [
                'Hoe was mijn gezondheid deze week?',
                'Waarom veranderde mijn slaapscore?',
                'Hoe gaat het met mijn doelen?',
            ],
        ],

        'consent' => [
            'title'  => 'Ownify AI gebruiken?',
            'intro'  => 'Ownify AI is je persoonlijke gezondheidsassistent, gemaakt met Google Gemini. Voordat je begint, dit moet je weten:',
            'points' => [
                'Om je vragen te beantwoorden stuurt Ownify gegevens uit je account naar Google Gemini: je profiel (zoals je voornaam, leeftijd, geslacht, lengte en gewicht), je slaap, voeding en training, je doelen en je gezondheidsscores.',
                'Dat gaat automatisch — je hoeft je gegevens niet zelf te typen. Er gaat alleen mee wat bij je vraag past.',
                'Ownify gebruikt de gratis versie van de Gemini API. Google kan wat daar binnenkomt gebruiken om zijn producten te verbeteren, en medewerkers van Google kunnen het lezen. Je gegevens blijven dus niet alleen bij Ownify.',
                'Je gesprekken worden bewaard in je Ownify-account, zodat je ze op je telefoon en op de website terugziet. Je kunt ze altijd wissen.',
                'De assistent helpt je je gegevens te begrijpen. Hij stelt geen diagnoses en vervangt geen arts.',
            ],
            'accept'  => 'Toestaan en beginnen',
            'decline' => 'Niet nu',
            'footer'  => 'Je kunt dit altijd wijzigen in Instellingen → Privacy.',
        ],

        'declined' => [
            'title'  => 'Ownify AI staat uit',
            'body'   => 'Om Ownify AI te gebruiken moet je toestaan dat je Ownify-gezondheidsgegevens door Google Gemini worden verwerkt. Zolang je dat niet doet, gaat er niets naar Gemini.',
            'review' => 'Toestemming bekijken',
        ],

        'errors' => [
            'unavailable' => 'De AI-assistent is tijdelijk niet beschikbaar. Probeer het later opnieuw.',
            'quota'       => 'De AI-assistent is tijdelijk niet beschikbaar: de gratis gebruikslimiet is bereikt. Probeer het later opnieuw.',
            'limit'       => 'Je hebt de gratis AI-berichten van vandaag gebruikt. Morgen kun je weer verder.',
            'timeout'     => 'Het antwoord duurde te lang. Probeer het opnieuw.',
            'blocked'     => 'Op deze vraag kan ik geen antwoord geven. Probeer het anders te formuleren.',
            'consent'     => 'Om Ownify AI te gebruiken moet je toestaan dat je Ownify-gezondheidsgegevens door Gemini worden verwerkt.',
            'empty'       => 'Typ eerst een vraag.',
            'too_long'    => 'Je vraag is te lang. Houd het onder de 2000 tekens.',
            'not_found'   => 'Dit gesprek bestaat niet meer.',
            'action_gone' => 'Dit voorstel is al afgehandeld.',
            'network'     => 'Geen verbinding. Controleer je internet en probeer het opnieuw.',
            'signed_out'  => 'Je bent niet meer ingelogd. Log opnieuw in om Ownify AI te gebruiken.',
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

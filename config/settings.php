<?php
/**
 * Instellingen — the settings tree, its detail screens and their copy.
 *
 * ------------------------------------------------------------------
 * WHAT IS REAL AND WHAT IS NOT
 * ------------------------------------------------------------------
 * Nothing here writes anything. No setting is stored, no integration is
 * connected, no notification is sent. The page is the structure those things
 * will hang from.
 *
 * That said, not every value on this page is a placeholder. Three kinds of
 * value appear, and the page never mixes them up:
 *
 *   FACT         The app genuinely behaves this way today. The theme is dark,
 *                the interface is Dutch, measurements are metric, and health
 *                data never leaves the owner's account. These are read off
 *                the app as built, not invented.
 *
 *   NOT SET      Nothing is connected and no profile data has been entered,
 *                so the row says exactly that rather than showing a number.
 *
 *   PREFERENCE   A choice the user will make later. Selectable now so the
 *                design can be judged; each screen says it is not yet saved.
 *
 * `demo` turns on a review-only connected integration, so the connected
 * state of the devices screen can be looked at without the app ever claiming
 * a device is attached. It ships false.
 *
 * ------------------------------------------------------------------
 * PROFILE FIELDS
 * ------------------------------------------------------------------
 * `edit` says how a field behaves, and the UI reads it rather than guessing:
 *
 *   true        an ordinary editable field
 *   'locked'    set once during onboarding — gender and date of birth
 *   'derived'   calculated from another field, so it is never typed in
 *
 * Age is 'derived', not editable: the schema stores a date of birth and
 * computes age from it (user_age()), so an age you could type would be a
 * second, contradictory fact about the same person.
 */

declare(strict_types=1);

return [

    'demo' => false,

    'title' => 'Instellingen',
    'lede'  => 'Je account, je gegevens en hoe de app zich gedraagt.',

    /* ============================================================ main page
     * One card per group, rows inside it. Every row opens a detail screen.
     */
    'groups' => [

        [
            'label' => 'Account',
            'rows'  => [
                ['id' => 'account', 'icon' => 'user', 'label' => 'Account',
                 'hint' => 'Profiel, naam en lichaamsmaten'],
            ],
        ],

        [
            'label' => 'Gezondheid',
            'rows'  => [
                ['id' => 'devices', 'icon' => 'device', 'label' => 'Apparaten & Gezondheid',
                 'hint' => 'Koppelingen en synchronisatie'],
            ],
        ],

        [
            'label' => 'Privacy',
            'rows'  => [
                ['id' => 'privacy', 'icon' => 'shield', 'label' => 'Privacy',
                 'value' => 'Gezondheidsdata privé'],
            ],
        ],

        [
            'label' => 'App',
            'rows'  => [
                ['id' => 'notifications', 'icon' => 'bell',          'label' => 'Meldingen',               'value' => 'Uit'],
                ['id' => 'theme',         'icon' => 'moon',          'label' => 'Thema & uiterlijk',       'value' => 'Donker'],
                ['id' => 'language',      'icon' => 'globe',         'label' => 'Taal',                    'value' => 'Nederlands'],
                ['id' => 'units',         'icon' => 'ruler',         'label' => 'Eenheden',                'value' => 'Metrisch'],
                ['id' => 'week',          'icon' => 'calendar',      'label' => 'Eerste dag van de week',  'value' => 'Maandag'],
                ['id' => 'accessibility', 'icon' => 'accessibility', 'label' => 'Toegankelijkheid',        'value' => 'Systeem'],
            ],
        ],

        [
            'label' => 'Over',
            'rows'  => [
                ['id' => 'about', 'icon' => 'info', 'label' => 'Over de app', 'value' => 'Versie Beta 1.2.1'],
            ],
        ],
    ],

    /* ------------------------------------------------------- account actions
     * Deliberately not a settings group: signing out and deleting an account
     * are not preferences.
     */
    'actions' => [
        'logout' => [
            'label'     => 'Uitloggen',
            'signed_out'=> 'Je bent niet ingelogd',
        ],
        /* Two steps before anything happens: what goes, then "are you sure".
           On the second the buttons swap sides, so a double tap on the first
           confirmation lands on "Nee, toch niet" rather than deleting. */
        'delete' => [
            'label'   => 'Account verwijderen',
            'title'   => 'Account verwijderen?',
            'body'    => 'Je profiel, je gezondheidsgegevens, je doelen, je gekoppelde apparaten en je plek op de ranglijst worden verwijderd.',
            'google'  => 'Ook je koppeling met Google wordt verwijderd.',
            'confirm' => 'Ja, verwijderen',
            'cancel'  => 'Annuleren',
            'note'    => 'Je wordt daarna overal uitgelogd.',

            'final_title'  => 'Weet je het zeker?',
            'final_body'   => 'Dit is de laatste stap. Je account en alles wat erbij hoort worden direct en voorgoed verwijderd. Dit kun je niet ongedaan maken.',
            'final_google' => 'Daarna ga je heel even langs Google, zodat Ownify ook uit je Google-account verdwijnt.',
            'final_yes'    => 'Definitief verwijderen',
            'final_no'     => 'Nee, toch niet',
        ],
    ],

    /* Shown once at the foot of every screen that offers a choice. */
    'not_saved' => 'Voorkeuren worden nog niet bewaard.',

    /* ========================================================= detail screens
     * Each screen is a list of blocks. The template switches on `type`, so a
     * new screen is config rather than a new file:
     *
     *   identity      avatar and username
     *   fields        profile fields, each with its own `edit` behaviour
     *   signin        the ways into this account, and linking Google
     *   integrations  a health source, expandable in place
     *   choice        pick one of several options
     *   states        read-only facts: label, value, one line of explanation
     *   toggles       switches, disabled while the feature does not exist
     *   actions       a button that does one thing after "are you sure?"
     *   rows          plain label/value information
     *   note          one framed line of explanation
     */
    'pages' => [

        /* ------------------------------------------------------------ account */
        'account' => [
            'title' => 'Account',
            'icon'  => 'user',
            'lede'  => 'Je profiel en de gegevens die de app over je bewaart.',
            'blocks' => [

                ['type' => 'identity'],

                [
                    'type'  => 'fields',
                    'title' => 'Profiel',
                    'fields' => [
                        /* These two already have a working endpoint behind the
                           account panel, so they open it rather than sitting
                           dead next to fields that have nowhere to save to. */
                        ['key' => 'avatar',     'label' => 'Profielfoto',     'edit' => true, 'kind' => 'image', 'opens' => 'account'],
                        ['key' => 'username',   'label' => 'Gebruikersnaam',  'edit' => true, 'opens' => 'account'],
                        ['key' => 'first_name', 'label' => 'Voornaam',        'edit' => true],
                        ['key' => 'last_name',  'label' => 'Achternaam',      'edit' => true],
                    ],
                ],

                /* How this account can be signed in to, and linking Google. */
                ['type' => 'signin', 'title' => 'Inloggen'],

                [
                    'type'  => 'fields',
                    'title' => 'Eenmalig in te vullen',
                    'lede'  => 'Deze gegevens vul je één keer in. Daarna staan ze vast, omdat je scores erop gebaseerd zijn.',
                    'fields' => [
                        ['key' => 'date_of_birth', 'label' => 'Geboortedatum', 'edit' => 'locked'],
                        ['key' => 'gender',        'label' => 'Geslacht',      'edit' => 'locked'],
                        ['key' => 'age',           'label' => 'Leeftijd',      'edit' => 'derived',
                         'note' => 'Berekend uit je geboortedatum'],
                    ],
                ],

                [
                    'type'  => 'fields',
                    'title' => 'Lichaamsmaten',
                    'lede'  => 'Deze veranderen mee. Elke wijziging wordt bewaard, zodat je het verloop terugziet.',
                    'fields' => [
                        ['key' => 'height', 'label' => 'Lengte',  'edit' => true, 'unit' => 'cm'],
                        ['key' => 'weight', 'label' => 'Gewicht', 'edit' => true, 'unit' => 'kg'],
                    ],
                ],

                ['type' => 'note', 'icon' => 'lock',
                 'text' => 'Je gegevens staan op je eigen account en zijn alleen voor jou zichtbaar. Lengte en gewicht bewaren hun verloop: elke wijziging komt erbij, de vorige verdwijnt niet.'],
            ],
        ],

        /* ------------------------------------------------------------ devices */
        'devices' => [
            'title' => 'Apparaten & Gezondheid',
            'icon'  => 'device',
            'lede'  => 'Waar je gezondheidsgegevens vandaan komen. De knop linksboven in de app toont alleen de status; hier stel je alles in.',
            'blocks' => [
                ['type' => 'integrations', 'title' => 'Koppelingen'],

                [
                    'type'  => 'states',
                    'title' => 'Synchronisatie',
                    'items' => [
                        ['label' => 'Frequentie',   'value' => 'Automatisch', 'note' => 'Zodra een bron gegevens levert'],
                        ['label' => 'Alleen wifi',  'value' => 'Uit',         'note' => 'Synchroniseren kan straks ook op mobiel data'],
                        ['label' => 'Laatste sync', 'value' => null,          'note' => 'Nog niets gesynchroniseerd'],
                    ],
                ],

                ['type' => 'note', 'icon' => 'lock',
                 'text' => 'Een koppeling leest alleen de categorieën die je goedkeurt, en alleen voor jouw account. Je kunt hem hier altijd weer verbreken; daarna wordt er niets meer opgehaald.'],
            ],
        ],

        /* ------------------------------------------------------------ privacy */
        'privacy' => [
            'title' => 'Privacy',
            'icon'  => 'shield',
            'lede'  => 'Wie wat van je ziet. Standaard: zo weinig mogelijk.',
            'blocks' => [
                [
                    'type'  => 'states',
                    'title' => 'Hoe het nu werkt',
                    'items' => [
                        /* Both notes are the account's own, written by
                           settings_prepare(): with Ownify AI on, data does
                           leave the account — for Gemini, per question. */
                        ['label' => 'Gezondheidsgegevens', 'value' => 'Privé',
                         'note' => 'Slaap, voeding, training en metingen verlaten je account niet',
                         'note_ai' => 'Alleen wat bij je vraag past gaat naar Google Gemini, als je Ownify AI iets vraagt'],
                        ['label' => 'Vrienden zien',       'value' => 'Naam en foto',
                         'note' => 'Verder niets — geen scores, geen metingen'],
                        ['label' => 'Ranglijst toont',     'value' => 'Punten en positie',
                         'note' => 'En je profielfoto, tenzij je die hieronder uitzet — nooit onderliggende gegevens'],
                        ['label' => 'Ownify AI',           'value' => 'Uit',
                         'note' => 'Er gaat niets naar Google Gemini',
                         'value_ai' => 'Aan',
                         'note_ai' => 'Leest je gegevens om je vragen te beantwoorden, via Google Gemini'],
                    ],
                ],

                /* A switch with a `key` really saves (api/profile/privacy.php);
                   `on` and `note` are the account's own, filled in by
                   settings_prepare(). */
                [
                    'type'  => 'toggles',
                    'title' => 'Ranglijst',
                    'items' => [
                        ['key' => 'leaderboard_avatar', 'label' => 'Profielfoto op de ranglijst',
                         'note_on'  => 'Anderen zien je profielfoto naast je naam.',
                         'note_off' => 'Op de ranglijst staat je initiaal in plaats van je foto.',
                         'on' => true],
                    ],
                ],

                /* Ownify AI: the same yes or no as in the assistant itself
                   (api/ai/consent.php). Nothing goes to Gemini while it is
                   off; the conversations stay until they are wiped below. */
                [
                    'type'  => 'toggles',
                    'title' => 'Ownify AI',
                    'lede'  => 'De assistent die je omhoog veegt, gemaakt met Google Gemini. Aanzetten is toestemming om je gegevens daarvoor te gebruiken.',
                    'items' => [
                        ['key' => 'ai_consent', 'label' => 'Gegevens verwerken met Google Gemini',
                         'note_on'  => 'Bij elke vraag gaat wat erbij past naar Gemini: profiel, slaap, voeding, training, doelen en scores.',
                         'note_off' => 'De assistent werkt niet, en er gaat niets naar Gemini.',
                         'on' => false],
                    ],
                ],

                ['type' => 'note', 'icon' => 'info',
                 'text' => 'Ownify gebruikt de gratis Gemini API. Google kan wat daar binnenkomt gebruiken om zijn producten te verbeteren, en medewerkers van Google kunnen het lezen. Je gesprekken worden in je Ownify-account bewaard, tot je ze wist.'],

                /* A button that does one thing once it is confirmed: see
                   pages/settings-detail.php (`actions`) and settings.js. */
                [
                    'type'  => 'actions',
                    'title' => 'AI-gesprekken',
                    'items' => [
                        ['key' => 'ai_clear_history', 'label' => 'AI-gesprekken wissen',
                         'note'     => 'Verwijdert al je gesprekken met Ownify AI, op al je apparaten. Andere gegevens blijven staan.',
                         'question' => 'Al je AI-gesprekken wissen? Dit kun je niet ongedaan maken.',
                         'confirm'  => 'Alles wissen',
                         'cancel'   => 'Annuleren',
                         'endpoint' => 'api/ai/delete.php',
                         'fields'   => ['all' => '1'],
                         'danger'   => true],
                    ],
                ],

                [
                    'type'  => 'toggles',
                    'title' => 'Later instelbaar',
                    'lede'  => 'Deze keuzes komen er zodra er iets te delen valt.',
                    'items' => [
                        ['label' => 'Profiel vindbaar',        'note' => 'Anderen kunnen je op gebruikersnaam vinden', 'on' => true],
                        ['label' => 'Meedoen aan ranglijsten', 'note' => 'Zonder dit verschijn je nergens',           'on' => true],
                    ],
                ],

                [
                    'type'  => 'rows',
                    'title' => 'Je gegevens',
                    'items' => [
                        ['label' => 'Gegevens downloaden', 'value' => 'Binnenkort'],
                        ['label' => 'Gegevens wissen',     'value' => 'Binnenkort'],
                    ],
                ],

                ['type' => 'note', 'icon' => 'lock',
                 'text' => 'De app schermt je gegevens af, niet de database zelf: wie beheerderstoegang tot de server heeft, kan de tabellen lezen. Dat geldt voor elke app.'],
            ],
        ],

        /* ------------------------------------------------------ notifications */
        'notifications' => [
            'title' => 'Meldingen',
            'icon'  => 'bell',
            'lede'  => 'Er worden nog geen meldingen verstuurd. Dit zijn de soorten die er komen.',
            'blocks' => [
                [
                    'type'  => 'toggles',
                    'title' => 'Soorten',
                    'items' => [
                        ['label' => 'Doelen',        'note' => 'Voortgang en mijlpalen',        'on' => false],
                        ['label' => 'Dagherinnering','note' => 'Eén moment per dag, door jou gekozen', 'on' => false],
                        ['label' => 'Gezondheid',    'note' => 'Opvallende veranderingen',      'on' => false],
                        ['label' => 'Suggesties',    'note' => 'Hooguit één per dag',           'on' => false],
                        ['label' => 'Community',     'note' => 'Vriendverzoeken en ranglijst',  'on' => false],
                    ],
                ],

                ['type' => 'note', 'icon' => 'lock',
                 'text' => 'Welke meldingen er precies komen, is nog niet besloten. De app stuurt liever te weinig dan te veel.'],
            ],
        ],

        /* -------------------------------------------------------------- theme */
        'theme' => [
            'title' => 'Thema & uiterlijk',
            'icon'  => 'moon',
            'lede'  => 'De app is ontworpen voor donker.',
            'blocks' => [
                [
                    'type'     => 'choice',
                    'title'    => 'Thema',
                    'name'     => 'theme',
                    'selected' => 'dark',
                    'options'  => [
                        ['key' => 'dark',  'label' => 'Donker', 'note' => 'Het enige thema dat de app nu heeft'],
                        ['key' => 'light', 'label' => 'Licht',  'note' => 'Nog niet beschikbaar', 'disabled' => true],
                    ],
                ],

                ['type' => 'note', 'icon' => 'lock',
                 'text' => 'Het hele ontwerp — de glaslagen, de accenten, het contrast — is op de donkere ondergrond afgestemd. Een lichte variant is een eigen ontwerp, geen omgekeerde kleur.'],
            ],
        ],

        /* ----------------------------------------------------------- language */
        'language' => [
            'title' => 'Taal',
            'icon'  => 'globe',
            'lede'  => 'De taal van de app.',
            'blocks' => [
                [
                    'type'     => 'choice',
                    'title'    => 'Taal',
                    'name'     => 'language',
                    'selected' => 'nl',
                    'options'  => [
                        ['key' => 'nl', 'label' => 'Nederlands', 'note' => 'Standaard'],
                        ['key' => 'en', 'label' => 'English',    'note' => 'Nog niet vertaald', 'disabled' => true],
                    ],
                ],
            ],
        ],

        /* -------------------------------------------------------------- units */
        'units' => [
            'title' => 'Eenheden',
            'icon'  => 'ruler',
            'lede'  => 'Hoe lengte, gewicht en afstand worden getoond.',
            'blocks' => [
                [
                    'type'     => 'choice',
                    'title'    => 'Stelsel',
                    'name'     => 'units',
                    'selected' => 'metric',
                    'options'  => [
                        ['key' => 'metric',   'label' => 'Metrisch', 'note' => 'kg · cm · km'],
                        ['key' => 'imperial', 'label' => 'Imperiaal','note' => 'lb · inch · mijl', 'disabled' => true],
                    ],
                ],

                [
                    'type'  => 'rows',
                    'title' => 'Nu in gebruik',
                    'items' => [
                        ['label' => 'Gewicht',  'value' => 'kilogram (kg)'],
                        ['label' => 'Lengte',   'value' => 'centimeter (cm)'],
                        ['label' => 'Afstand',  'value' => 'kilometer (km)'],
                        ['label' => 'Energie',  'value' => 'kilocalorie (kcal)'],
                    ],
                ],
            ],
        ],

        /* --------------------------------------------------------------- week */
        'week' => [
            'title' => 'Eerste dag van de week',
            'icon'  => 'calendar',
            'lede'  => 'Bepaalt waar je week begint in overzichten en grafieken.',
            'blocks' => [
                [
                    'type'     => 'choice',
                    'title'    => 'Week begint op',
                    'name'     => 'week',
                    'selected' => 'monday',
                    'options'  => [
                        ['key' => 'monday', 'label' => 'Maandag', 'note' => 'Standaard in Nederland'],
                        ['key' => 'sunday', 'label' => 'Zondag'],
                    ],
                ],
            ],
        ],

        /* ------------------------------------------------------ accessibility */
        'accessibility' => [
            'title' => 'Toegankelijkheid',
            'icon'  => 'accessibility',
            'lede'  => 'De app volgt je systeeminstellingen waar dat kan.',
            'blocks' => [
                [
                    'type'  => 'states',
                    'title' => 'Nu actief',
                    'items' => [
                        ['label' => 'Minder beweging', 'value' => 'Volgt je systeem',
                         'note' => 'Staat dit aan op je toestel, dan vervallen alle animaties'],
                        ['label' => 'Tekstgrootte',    'value' => 'Volgt je systeem',
                         'note' => 'De app schaalt mee met de tekstgrootte van je browser'],
                        ['label' => 'Contrast',        'value' => 'Verhoogd',
                         'note' => 'Elke tekstkleur haalt minimaal WCAG AA op zijn eigen ondergrond'],
                    ],
                ],

                [
                    'type'  => 'toggles',
                    'title' => 'Later instelbaar',
                    'lede'  => 'Eigen instellingen, los van je systeem.',
                    'items' => [
                        ['label' => 'Grotere tekst',   'note' => 'Onafhankelijk van je toestel', 'on' => false],
                        ['label' => 'Extra contrast',  'note' => 'Sterkere randen en tekst',     'on' => false],
                        ['label' => 'Animaties uit',   'note' => 'Altijd, ongeacht je systeem',  'on' => false],
                    ],
                ],
            ],
        ],

        /* -------------------------------------------------------------- about */
        'about' => [
            'title' => 'Over de app',
            'icon'  => 'info',
            'lede'  => null,
            'blocks' => [
                [
                    'type'  => 'rows',
                    'title' => 'App',
                    'items' => [
                        ['label' => 'Naam',    'value' => 'Ownify Health App'],
                        ['label' => 'Versie',  'value' => 'Beta 1.2.1'],
                        ['label' => 'Gebouwd met', 'value' => 'PHP · HTML · CSS · JS'],
                    ],
                ],

                [
                    'type'  => 'rows',
                    'title' => 'Juridisch',
                    'items' => [
                        ['label' => 'Privacyverklaring', 'value' => 'Binnenkort'],
                        ['label' => 'Voorwaarden',       'value' => 'Binnenkort'],
                        ['label' => 'Licenties',         'value' => 'Geen externe pakketten'],
                    ],
                ],

                [
                    'type'  => 'rows',
                    'title' => 'Hulp',
                    'items' => [
                        ['label' => 'Contact', 'value' => 'Binnenkort'],
                    ],
                ],

                ['type' => 'note', 'icon' => 'info',
                 'text' => 'Ownify Health App is geen medisch hulpmiddel. De scores en suggesties zijn bedoeld om je eigen ritme te volgen, niet om een diagnose te stellen.'],
            ],
        ],
    ],

    /* ========================================================= integrations
     * Every entry ships disconnected, because none of them is implemented.
     * `demo` fills in a connected state so that design can be reviewed.
     */
    /**
     * The sources Ownify can take data from.
     *
     * `provider` is the key in includes/integrations.php and the code in
     * data_sources — the same string all the way down, so a row on this screen
     * and a row in the database cannot drift apart.
     *
     * `transport` is stated because it decides what is possible, not just how
     * it looks. A cloud source the server can read on its own is a button
     * here; one whose data lives on a phone needs something on that phone,
     * and saying so is more use than a button that cannot work.
     */
    'integrations' => [
        [
            'key'      => 'google_health',
            'provider' => 'google_health',
            'label' => 'Google Health',
            'icon'  => 'rings',
            'note'  => 'Fitbit en Pixel Watch, via je Google-account',
            'categories' => ['Slaap', 'Activiteit', 'Training', 'Hartslag', 'Voeding', 'Lichaamsmaten'],
        ],
        [
            'key'      => 'apple_health',
            'provider' => 'apple_health',
            'label' => 'Apple Health',
            'icon'  => 'heart',
            'note'  => 'iPhone en Apple Watch',
            'categories' => ['Slaap', 'Activiteit', 'Training', 'Hartslag', 'Lichaamsmaten'],
        ],
        [
            'key'      => 'health_connect',
            'provider' => 'google_health_connect',
            'label' => 'Health Connect',
            'icon'  => 'pulse',
            'note'  => 'Android — Google Health Connect',
            'categories' => ['Slaap', 'Activiteit', 'Training', 'Hartslag'],
        ],
        [
            'key'   => 'watch',
            'label' => 'Smartwatch',
            'icon'  => 'device',
            'note'  => 'Rechtstreekse koppeling',
            'categories' => ['Hartslag', 'HRV', 'Training', 'Slaap'],
        ],
        [
            'key'   => 'ring',
            'label' => 'Slaapring',
            'icon'  => 'rings',
            'note'  => 'Rechtstreekse koppeling',
            'categories' => ['Slaap', 'HRV', 'Huidtemperatuur', 'Zuurstofsaturatie'],
        ],
    ],

    'integration_labels' => [
        'connected'    => 'Verbonden',
        'disconnected' => 'Niet verbonden',
        'status'       => 'Status',
        'last_sync'    => 'Laatste synchronisatie',
        'never'        => 'Nog nooit',
        'categories'   => 'Gegevens',
        'permissions'  => 'Toestemmingen',
        'permissions_note' => 'Per categorie',
        'connect'      => 'Koppelen',
        'disconnect'   => 'Ontkoppelen',
        'sync_now'     => 'Nu synchroniseren',
        'unavailable'  => 'Koppelen kan nog niet',
        'account'      => 'Account',
        'revoked'      => 'Toegang ingetrokken',
        'error'        => 'Synchroniseren mislukt',
        'reconnect'    => 'Opnieuw koppelen',
        'disconnect_confirm' => 'Ontkoppelen stopt het ophalen van nieuwe gegevens. Wat al binnen is blijft staan.',
        'expand'       => 'Instellingen van %s tonen',

        /* The phones paired to a source. One line each, revocable one at a
           time — losing a phone costs you that phone, not every phone. */
        'devices'       => 'Gekoppelde apparaten',
        'revoke'        => 'Ontkoppelen',
        'revoke_device' => '%s ontkoppelen',
    ],
];

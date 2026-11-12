<?php
/**
 * Instellingen › Over de app › Juridisch (39.0, docs/LEGAL.md): the
 * Privacyverklaring, the Voorwaarden and the Licenties, each on a page of
 * its own over Over de app, on the website and in the app alike.
 *
 * Every sentence here describes what Ownify actually does, as the code does
 * it (docs/LEGAL.md lists where each fact comes from). Change the code, and
 * change the sentence with it. Nothing here names a person, an address or a
 * company that does not exist: where that is missing, the text says so.
 *
 * A section has a `title` and any of: `text` (paragraphs), `points` (a
 * bulleted list), `link` (label + href, opened in the browser). The
 * Licenties' package sections are added by lib/legal.php from
 * config/licenses-android.php and the vendored PHPMailer.
 */
declare(strict_types=1);

return [

    'back'      => 'Over de app',
    /* The in-place toggle over a licence's full text (Beperkingen's). */
    'licence_text' => 'Licentietekst',

    'documents' => [

        /* ============================================================ privacy */
        'privacy' => [
            'label' => 'Privacyverklaring',
            'icon'  => 'shield',
            'title' => 'Privacyverklaring',
            'lede'  => 'Welke gegevens Ownify gebruikt, waarom, waar ze staan en wat je er zelf mee kunt.',
            'sections' => [
                [
                    'title' => 'Over Ownify',
                    'text'  => [
                        'Ownify is een niet-commercieel schoolproject. Het is gratis, verkoopt geen gegevens, toont geen advertenties en gebruikt geen analyse- of trackingdiensten.',
                        'Er is nog geen apart contactadres voor privacyvragen. De meeste dingen regel je zelf in Instellingen; hieronder staat waar.',
                    ],
                ],
                [
                    'title'  => 'Welke gegevens Ownify gebruikt',
                    'points' => [
                        'Account: je gebruikersnaam, je e-mailadres en je wachtwoord. Het wachtwoord wordt alleen als onleesbare hash bewaard. Log je in met Google, dan bewaart Ownify alleen de id die Google je geeft en je e-mailadres, nooit een wachtwoord of sleutel van Google.',
                        'Profiel: voornaam, achternaam, geboortedatum, geslacht, activiteitsniveau, profielfoto, taal, eenheden en je weergavevoorkeuren.',
                        'Lichaamsmaten: lengte, gewicht, vetpercentage en tailleomvang.',
                        'Gezondheidsgegevens: slaap, stappen, afstand, calorieën, hartslag, rusthartslag, hartslagvariabiliteit (HRV), zuurstofsaturatie, trainingen, verdiepingen, voeding en water — uit Health Connect, uit Polar of zoals je ze zelf invult, zoals maaltijden en je dagcijfer voor voeding.',
                        'Wat Ownify daaruit berekent: je Gezondheidsscore per dag, inzichten, doelen en hun voortgang, en punten.',
                        'Community: je vrienden, wie je hebt geblokkeerd en de uitdagingen waar je aan meedoet.',
                        'OwnifyAI: je gesprekken met OwnifyAI, als je die gebruikt.',
                        'Techniek: de apparaten die je koppelt, je inlogsessies, wanneer je Ownify voor het laatst gebruikte en hoeveel vragen je per dag aan OwnifyAI stelt.',
                    ],
                ],
                [
                    'title'  => 'Waarvoor',
                    'points' => [
                        'Je overzicht, je scores, je inzichten en je doelen laten zien.',
                        'Je laten inloggen en je account beveiligen.',
                        'E-mails over je account sturen: een adres bevestigen, je wachtwoord herstellen, melden dat je wachtwoord of e-mailadres is gewijzigd.',
                        'Community laten werken: vrienden, de ranglijst en uitdagingen.',
                        'OwnifyAI je vragen laten beantwoorden — alleen als je daar toestemming voor geeft.',
                    ],
                ],
                [
                    'title' => 'Waar je gegevens staan',
                    'text'  => [
                        'Je gegevens staan in de database van de Ownify-server, bereikbaar via ownify.acits.nl over een versleutelde verbinding (HTTPS). Je profielfoto staat als bestand op diezelfde server.',
                        'De Android-app bewaart op je telefoon alleen wat ze nodig heeft: de sleutel waarmee ze met je account verbonden is (versleuteld met de Android Keystore, en nooit in een back-up), je app-instellingen en onderzoeks-pdf\'s die je opende, in de cache.',
                    ],
                ],
                [
                    'title'  => 'Health Connect',
                    'text'   => [
                        'De Android-app leest uit Health Connect alleen de soorten gegevens die je zelf toestaat: stappen, afstand, calorieën, hartslag, rusthartslag, HRV, slaap, trainingen, verdiepingen, zuurstofsaturatie, voeding, water, gewicht, lengte en vetpercentage. Ze schrijft niets naar Health Connect.',
                        'Met je toestemming leest de app ook op de achtergrond: ongeveer elk uur, en als je de app opent. Elke keer gaan de gegevens van de afgelopen zeven dagen naar je account.',
                    ],
                    'points' => [
                        'Toestemming intrekken doe je in de instellingen van Health Connect op je telefoon.',
                        'Wat al in Ownify staat, wis je in Instellingen › Privacy › Gegevens wissen.',
                    ],
                ],
                [
                    'title' => 'Polar',
                    'text'  => [
                        'Koppel je een Polar-account, dan leest Ownify daaruit trainingen, activiteit, slaap en herstel; de eerste keer de afgelopen 28 dagen. De sleutel die Polar Ownify daarvoor geeft, wordt versleuteld bewaard. Ontkoppelen kan in Instellingen › Apparaten & Gezondheid.',
                    ],
                ],
                [
                    'title'  => 'OwnifyAI',
                    'text'   => [
                        'OwnifyAI werkt alleen als je dat zelf aanzet in Instellingen › Privacy › OwnifyAI. Je vragen worden dan beantwoord door Google Gemini, in de gratis versie. Bij die versie mag Google wat het ontvangt gebruiken om zijn producten te verbeteren, en kunnen medewerkers van Google het lezen.',
                        'Met elke vraag stuurt Ownify mee: je voornaam, leeftijd, geslacht, lengte, gewicht, activiteitsniveau, taal en eenheden, je scores, je doelen, een samenvatting van de afgelopen twee weken en de laatste berichten van het gesprek. Afhankelijk van je vraag gaan er ook recente gegevens over slaap, training, activiteit, voeding, lichaamsmaten of je plek op de ranglijst mee. Je achternaam, geboortedatum en e-mailadres gaan nooit mee.',
                    ],
                    'points' => [
                        'Ownify bewaart je gesprekken (de nieuwste 50), niet wat er naar Google ging.',
                        'Je gesprekken wis je in Instellingen › Privacy › OwnifyAI-gesprekken wissen.',
                        'Zet je de toestemming uit, dan gaat er vanaf dat moment niets meer naar Google.',
                    ],
                ],
                [
                    'title'  => 'Wat anderen zien',
                    'points' => [
                        'Iedereen met punten staat op de landelijke ranglijst, met gebruikersnaam, profielfoto, punten en plaats. Je kunt in Instellingen › Privacy kiezen voor je initiaal in plaats van je foto. Meedoen aan de ranglijst kun je nog niet uitzetten.',
                        'Vrienden zien je gebruikersnaam, profielfoto, punten en plaats op jullie ranglijst.',
                        'Wie in dezelfde uitdaging zit, ziet jouw totaal voor die uitdaging.',
                        'Anderen zien nooit je naam, geboortedatum, lichaamsmaten of gezondheidsgegevens.',
                        'Je kunt iemand blokkeren en vriendverzoeken uitzetten.',
                    ],
                ],
                [
                    'title' => 'E-mail',
                    'text'  => [
                        'E-mails over je account gaan via Gmail van Google, vanaf noreply.ownify@gmail.com. Een e-mail wacht kort in een wachtrij op de server — de inhoud versleuteld — en wordt verwijderd zodra hij is verstuurd of na een paar mislukte pogingen.',
                    ],
                ],
                [
                    'title'  => 'Cookies',
                    'text'   => ['Ownify gebruikt alleen cookies die nodig zijn om de website te laten werken; geen cookies om je te volgen.'],
                    'points' => [
                        'Sessiecookie: houdt je ingelogd zolang je browser open is.',
                        'Inlogcookie: houdt je ingelogd op dit apparaat, tot een jaar na je laatste bezoek. De server bewaart er alleen een hash van.',
                        'Taal en thema: onthouden je keuze, een jaar.',
                    ],
                ],
                [
                    'title'  => 'Hoe lang',
                    'text'   => ['Je gegevens blijven bewaard zolang je account bestaat. Een paar dingen verdwijnen eerder vanzelf:'],
                    'points' => [
                        'Een registratie die niet binnen 7 dagen is bevestigd.',
                        'Inzichten en de berekeningen erachter na 35 dagen.',
                        'OwnifyAI-gesprekken ouder dan de nieuwste 50.',
                        'Codes en sleutels zodra ze verlopen zijn.',
                    ],
                ],
                [
                    'title'  => 'Je rechten en wat je zelf kunt',
                    'points' => [
                        'Inzien en meenemen: Instellingen › Privacy › Gegevens downloaden geeft al je gegevens in één JSON-bestand.',
                        'Aanpassen: je profiel en je lichaamsmaten pas je aan in Instellingen › Account. Je geboortedatum en geslacht liggen vast zodra ze zijn ingevuld.',
                        'Zelf invoeren en verwijderen: met de + op Slaap en op Training voer je een nacht of een training in, of pas je de tijden van een nacht aan. Een training verwijder je op zijn eigen pagina. Kwam die uit Health Connect of Polar, dan verdwijnt alleen de kopie in Ownify; Ownify onthoudt welke het was, zodat de volgende synchronisatie hem niet terugzet.',
                        'Wissen: Instellingen › Privacy › Gegevens wissen verwijdert al je gezondheidsgegevens, scores, punten, inzichten en doelen. Je account blijft bestaan.',
                        'Account verwijderen: onderaan Instellingen. Je account en alles wat erbij hoort worden direct en definitief verwijderd; Ownify vraagt Google ook de toegang van Ownify tot je Google-account in te trekken. Uitdagingen die je maakte blijven voor de anderen bestaan, zonder jouw naam.',
                        'Toestemming intrekken: voor OwnifyAI in Instellingen › Privacy, voor Health Connect in Health Connect zelf.',
                    ],
                ],
                [
                    'title' => 'Wijzigingen',
                    'text'  => ['Verandert wat Ownify met je gegevens doet, dan verandert deze verklaring mee. De versie van de app staat in Instellingen › Over de app.'],
                ],
            ],
        ],

        /* ============================================================== terms */
        'terms' => [
            'label' => 'Voorwaarden',
            'icon'  => 'info',
            'title' => 'Voorwaarden',
            'lede'  => 'De afspraken voor het gebruik van Ownify.',
            'sections' => [
                [
                    'title' => 'Over Ownify',
                    'text'  => [
                        'Ownify is een niet-commercieel schoolproject. Je gebruikt het gratis en zoals het is: het kan fouten bevatten, veranderen of ophouden te bestaan.',
                        'Door een account aan te maken ga je akkoord met deze voorwaarden en met de Privacyverklaring.',
                    ],
                ],
                [
                    'title'  => 'Je account',
                    'points' => [
                        'Een account is voor één persoon. Vul je gegevens naar waarheid in.',
                        'Houd je wachtwoord geheim. Wat er met je account gebeurt, is jouw verantwoordelijkheid.',
                        'Kies een gebruikersnaam die niemand kwetst en niet doet alsof je iemand anders bent.',
                        'Je kunt je account altijd verwijderen, onderaan Instellingen.',
                    ],
                ],
                [
                    'title'  => 'Gezondheidsgegevens en koppelingen',
                    'points' => [
                        'Jij kiest welke bronnen je koppelt en welke gegevens Ownify mag lezen.',
                        'Ownify laat zien wat die bronnen aanleveren en wat je zelf invult. Dat kan onvolledig of onjuist zijn.',
                        'Diensten als Health Connect, Polar, Google en Google Gemini hebben hun eigen voorwaarden.',
                    ],
                ],
                [
                    'title'  => 'Scores, inzichten en OwnifyAI',
                    'points' => [
                        'De Gezondheidsscore en de inzichten worden berekend uit je eigen gegevens en uit goedgekeurd onderzoek. Ze geven een beeld, geen oordeel, en kunnen ernaast zitten.',
                        'Een inzicht laat een samenhang in je gegevens zien, geen oorzaak.',
                        'OwnifyAI is een taalmodel. Het kan zich vergissen en dingen zeker laten klinken die het niet zijn. Controleer belangrijke antwoorden.',
                    ],
                ],
                [
                    'title' => 'Geen medisch advies',
                    'text'  => [
                        'Ownify is geen medisch hulpmiddel en vervangt geen arts, diëtist of andere zorgverlener. Ownify stelt geen diagnose en geeft geen behandeling. Twijfel je over je gezondheid, neem dan contact op met een zorgverlener. Bij spoed bel je 112.',
                    ],
                ],
                [
                    'title'  => 'Netjes gebruiken',
                    'points' => [
                        'Wees respectvol tegen anderen in Community.',
                        'Probeer niet in te breken, Ownify te overbelasten of gegevens van anderen te verzamelen.',
                        'Deel geen gegevens van anderen zonder hun toestemming.',
                        'Bij misbruik kan een account worden geblokkeerd of verwijderd.',
                    ],
                ],
                [
                    'title' => 'Beschikbaarheid',
                    'text'  => [
                        'Ownify doet zijn best om bereikbaar te zijn, maar belooft dat niet. Functies kunnen veranderen of verdwijnen, en gegevens kunnen verloren gaan. Wil je je gegevens zeker bewaren, download ze dan in Instellingen › Privacy.',
                    ],
                ],
                [
                    'title' => 'Wijzigingen',
                    'text'  => ['Deze voorwaarden kunnen veranderen als Ownify verandert. De versie van de app staat in Instellingen › Over de app.'],
                ],
            ],
        ],

        /* =========================================================== licenses */
        'licenses' => [
            'label' => 'Licenties',
            'icon'  => 'info',   // B8 (50.0): a document's icon, as Voorwaarden — copy only copies
            'title' => 'Licenties',
            'lede'  => 'De software van anderen die Ownify gebruikt, met de licentie die daarbij hoort.',
            'sections' => [
                [
                    'title' => 'Over deze lijst',
                    'text'  => [
                        'De website gebruikt één extern pakket: PHPMailer, voor het versturen van e-mail. Verder geen JavaScript-bibliotheken, geen lettertypen van anderen en geen pictogrammen van anderen: de pictogrammen zijn van Ownify zelf en de tekst staat in het systeemlettertype van je apparaat.',
                        'De Android-app gebruikt de pakketten hieronder, met de versie die in de app zit. De lijst is gemaakt uit de bouw van de app zelf.',
                    ],
                ],
            ],
        ],
    ],

    /* What lib/legal.php puts in the Licenties' package sections. */
    'licenses' => [
        'website'  => 'Website',
        'app'      => 'Android-app',
        'licence'  => 'Licentie: %s',
        'source'   => 'Bron',
        'packages' => ['%d pakket', '%d pakketten'],
        'texts'    => 'Licentieteksten',
        'sdk_note' => 'Deze pakketten van Google vallen onder de Android Software Development Kit License; de tekst staat bij de bron.',
    ],
];

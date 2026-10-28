<?php
/**
 * The research behind Ownify's insights — every source and every claim, in
 * one reviewed place (docs/RESEARCH.md).
 *
 * ---------------------------------------------------------------------------
 * WHAT MAY BE IN HERE
 * ---------------------------------------------------------------------------
 * Only sources the owner approved one by one (Phase 1 report, 27 October
 * 2026: all 17 approved). Each was checked against the publisher, PubMed or
 * the official PDF before it was proposed. A source that is not approved is
 * not in this file, and a claim names exactly one source. A new source, a
 * new claim or a changed number is a new approval: change `status` only on
 * the owner's word, raise `version` when a claim's meaning changes.
 *
 * ---------------------------------------------------------------------------
 * WHERE IT LIVES
 * ---------------------------------------------------------------------------
 * This file is the reviewed text; the database holds the same registry
 * (research_sources, research_claims — migration 028) and is what the
 * insight engine reads, so every stored insight points at the exact claim
 * version it used. `php tools/research-registry.php --check` compares the
 * two; `--sync` writes this file into a database (an existing install, after
 * a change here), `--sql` prints the seed migration 028 carries.
 *
 * Numbers are the source's own (`finding`); the Dutch text is what Ownify
 * shows, translated like any config (config_copy, lang/en/research.php).
 * A claim's `strength` is the evidence for the claim itself
 * (docs/INSIGHTS.md, "Bewijskracht"); a rule that can only apply it to an
 * approximation of what the source measured shows it one step lower.
 *
 * `pdf.mode`:
 *   hosted        CC BY: Ownify serves an unmodified copy with attribution
 *                 (assets/research/), readable inside both apps
 *   official_pdf  a free official PDF the device itself fetches from the
 *                 publisher — embedded on the website where the publisher
 *                 allows it, drawn by the Android app — never copied
 *   link          only the publisher's page (or its PDF) in the browser:
 *                 no reuse licence, NC terms, or bot protection
 *
 * `language` is the language the publication itself is written in (nl, en).
 * Its title stays exactly as published, in that language, and the detail
 * says so where it is not the app's.
 */

declare(strict_types=1);

return [

    /* What a kind of source is called, and what a strength means. */
    'kinds' => [
        'health_info'       => 'Officiële gezondheidsinformatie (samenvatting van consensusverklaringen)',
        'consensus'         => 'Consensusverklaring van deskundigen, na literatuuronderzoek',
        'guideline'         => 'Officiële richtlijn, op basis van systematische reviews',
        'meta_analysis'     => 'Meta-analyse van cohortonderzoek',
        'systematic_review' => 'Systematische review met meta-analyse',
        'umbrella_review'   => 'Overzicht van systematische reviews',
        'validation_study'  => 'Validatiestudie in een slaaplaboratorium',
        'reference_values'  => 'Officiële voedingsnormen',
        'position_stand'    => 'Standpunt van een beroepsvereniging',
        'fact_sheet'        => 'Officieel feitenblad',
    ],

    'strengths' => [
        'zeer_betrouwbaar' => [
            'label' => 'Zeer betrouwbaar',
            'means' => 'Een officiële richtlijn of norm, gebaseerd op systematisch beoordeeld onderzoek, die past bij wat Ownify meet.',
        ],
        'betrouwbaar' => [
            'label' => 'Betrouwbaar',
            'means' => 'Een consensus van deskundigen of een meta-analyse van vooral observationeel onderzoek, of een sterke richtlijn die Ownify maar bij benadering kan toepassen.',
        ],
        'beperkt' => [
            'label' => 'Beperkt onderbouwd',
            'means' => 'Eén studie, een standpunt van een vereniging, of een meting die maar deels past bij wat het onderzoek mat.',
        ],
    ],

    'strength_note' => 'Dit label zegt hoe sterk onderzoek deze bewering onderbouwt. Het is geen kans en geen percentage.',

    /* Why a label shows lower than the claim's own strength
       (config/insights.php, `applicability`). */
    'applicability' => [
        'proxy' => 'Ownify past dit maar bij benadering toe op je eigen gegevens. Daarom staat het label hier een stap lager dan het onderzoek zelf.',
        'weak'  => 'Je gegevens passen maar deels bij wat het onderzoek mat. Daarom staat het label hier twee stappen lager dan het onderzoek zelf.',
    ],

    /* "Uit onderzoek blijkt": the detail view's words, on both apps. */
    'detail' => [
        'title'        => 'Uit onderzoek blijkt',
        'found'        => 'Wat het onderzoek vond',
        'claim'        => 'Wat Ownify ermee zegt',
        'evidence'     => 'Bewijskracht',
        'population'   => 'Voor wie',
        'limitations'  => 'Beperkingen',
        'source'       => 'Bron',
        'kind_label'   => 'Soort onderzoek',
        /* Said under the title when the publication is not in the app's language. */
        'language'     => [
            'nl' => 'De publicatie is in het Nederlands.',
            'en' => 'De publicatie is in het Engels.',
        ],
        'published'    => 'Verschenen',
        'verified'     => 'Gecontroleerd op %s',
        'open_source'  => 'Bekijk de publicatie',
        'read_pdf'     => 'Lees de pdf',
        'open_pdf'     => 'Open de pdf',
        'download_pdf' => 'Download de pdf',
        'pdf_hosted'   => 'Een ongewijzigde kopie van de publicatie, gedeeld onder %s.',
        'pdf_official' => 'De officiële pdf, rechtstreeks van de uitgever.',
        'pdf_none'     => 'Geen pdf om in Ownify te lezen: de publicatie opent bij de uitgever.',
        'pdf_loading'  => 'De pdf wordt geladen…',
        'pdf_failed'   => 'De pdf kon niet worden geladen. Je kunt hem bij de uitgever openen.',
        'offline'      => 'Je bent offline. De publicatie opent zodra je weer verbinding hebt.',
        'page'         => 'Pagina %1$d van %2$d',
        'retired'      => 'Ownify gebruikt inmiddels een nieuwere versie van deze bewering.',
    ],

    /* ======================================================== SOURCES === */
    'sources' => [

        'SLP-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'health_info',
            'language' => 'en',
            'title' => 'About Sleep',
            'authors' => null,
            'organisation' => 'Centers for Disease Control and Prevention (CDC)',
            'published' => '2024-05-15',
            'citation' => 'CDC, Sleep, About Sleep (15 mei 2024)',
            'url' => 'https://www.cdc.gov/sleep/about/',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Webpagina, geen pdf', 'hosted' => null],
            'findings' => 'Aanbevolen slaap per dag: 6–12 jaar 9–12 uur, 13–17 jaar 8–10 uur, 18–60 jaar 7 uur of meer, 61–64 jaar 7–9 uur en vanaf 65 jaar 7–8 uur.',
        ],

        'SLP-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Recommended Amount of Sleep for a Healthy Adult: A Joint Consensus Statement of the American Academy of Sleep Medicine and Sleep Research Society',
            'authors' => 'Watson NF, Badr MS, Belenky G, et al.',
            'organisation' => 'American Academy of Sleep Medicine en Sleep Research Society',
            'published' => '2015',
            'citation' => 'Sleep 2015;38(6):843–844',
            'url' => 'https://doi.org/10.5665/sleep.4716',
            'doi' => '10.5665/sleep.4716', 'pmid' => '26039963', 'pmcid' => 'PMC4434546', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4434546/',
                      'licence' => 'Gratis te lezen, alle rechten voorbehouden', 'hosted' => null],
            'findings' => 'Volwassenen horen regelmatig 7 uur of meer per nacht te slapen. Regelmatig korter dan 7 uur slapen hangt samen met onder meer gewichtstoename, diabetes, hoge bloeddruk, hart- en vaatziekten, depressie en een hoger sterfterisico. Meer dan 9 uur kan passend zijn voor jonge volwassenen, bij het inhalen van slaaptekort of bij ziekte; voor anderen is onzeker of dat een risico geeft.',
        ],

        'SLP-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'National Sleep Foundation\'s sleep time duration recommendations: methodology and results summary',
            'authors' => 'Hirshkowitz M, Whiton K, Albert SM, et al.',
            'organisation' => 'National Sleep Foundation',
            'published' => '2015',
            'citation' => 'Sleep Health 2015;1(1):40–43',
            'url' => 'https://doi.org/10.1016/j.sleh.2014.12.010',
            'doi' => '10.1016/j.sleh.2014.12.010', 'pmid' => '29073412', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://www.sleephealthjournal.org/article/S2352721815000157/pdf',
                      'licence' => 'Gratis te lezen, alle rechten voorbehouden', 'hosted' => null],
            'findings' => 'Een panel van deskundigen noemt als passende slaapduur: schoolkinderen 9–11 uur, tieners (14–17) 8–10 uur, jongvolwassenen en volwassenen (18–64) 7–9 uur en ouderen (65+) 7–8 uur. Wat daarbuiten valt kan passend zijn, maar grote afwijkingen zijn zeldzaam.',
        ],

        'SLP-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Recommended Amount of Sleep for Pediatric Populations: A Consensus Statement of the American Academy of Sleep Medicine',
            'authors' => 'Paruthi S, Brooks LJ, D\'Ambrosio C, et al.',
            'organisation' => 'American Academy of Sleep Medicine',
            'published' => '2016',
            'citation' => 'J Clin Sleep Med 2016;12(6):785–786',
            'url' => 'https://doi.org/10.5664/jcsm.5866',
            'doi' => '10.5664/jcsm.5866', 'pmid' => '27250809', 'pmcid' => 'PMC4877308', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4877308/',
                      'licence' => 'Gratis te lezen, alle rechten voorbehouden', 'hosted' => null],
            'findings' => 'Kinderen van 6–12 jaar horen 9–12 uur per 24 uur te slapen en tieners van 13–18 jaar 8–10 uur. Regelmatig korter slapen hangt samen met problemen met aandacht, gedrag en leren, en met meer ongelukken, hoge bloeddruk, overgewicht, diabetes en depressie.',
        ],

        'SLP-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'The importance of sleep regularity: a consensus statement of the National Sleep Foundation sleep timing and variability panel',
            'authors' => 'Sletten TL, Weaver MD, Foster RG, et al.',
            'organisation' => 'National Sleep Foundation',
            'published' => '2023',
            'citation' => 'Sleep Health 2023;9(6):801–820',
            'url' => 'https://doi.org/10.1016/j.sleh.2023.07.016',
            'doi' => '10.1016/j.sleh.2023.07.016', 'pmid' => '37684151', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://www.sleephealthjournal.org/article/S2352721823001663/pdf',
                      'licence' => 'Gratis te lezen, alle rechten voorbehouden', 'hosted' => null],
            'findings' => 'Na een literatuuronderzoek concludeerde een panel van slaap- en ritmedeskundigen dat regelmatige tijden van inslapen en opstaan belangrijk zijn voor gezondheid, veiligheid en prestaties. Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.',
        ],

        'SLP-06' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'validation_study',
            'language' => 'en',
            'title' => 'Performance of seven consumer sleep-tracking devices compared with polysomnography',
            'authors' => 'Chinoy ED, Cuellar JA, Huwa KE, et al.',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Sleep 2021;44(5):zsaa291',
            'url' => 'https://doi.org/10.1093/sleep/zsaa291',
            'doi' => '10.1093/sleep/zsaa291', 'pmid' => '33378539', 'pmcid' => 'PMC8120339', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://academic.oup.com/sleep/article-pdf/44/5/zsaa291/37933402/zsaa291.pdf',
                      'licence' => 'CC BY-NC 4.0', 'hosted' => null],
            'findings' => 'In een slaaplaboratorium (34 gezonde jonge volwassenen, 3 nachten) herkenden zeven consumentenapparaten slaap goed, maar wakker zijn veel minder goed. Slaapfasen kwamen wisselend overeen en op onrustige nachten werkten de apparaten slechter.',
        ],

        'ACT-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'guideline',
            'language' => 'en',
            'title' => 'WHO guidelines on physical activity and sedentary behaviour',
            'authors' => null,
            'organisation' => 'Wereldgezondheidsorganisatie (WHO)',
            'published' => '2020-11-25',
            'citation' => 'WHO, Genève 2020, 104 pagina\'s',
            'url' => 'https://www.who.int/publications/i/item/9789240015128',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'ISBN 9789240015128',
            'pdf' => ['mode' => 'official_pdf', 'url' => 'https://iris.who.int/server/api/core/bitstreams/faa83413-d89e-4be9-bb01-b24671aef7ca/content',
                      'licence' => 'CC BY-NC-SA 3.0 IGO', 'hosted' => null, 'embed' => false],
            'findings' => 'Volwassenen (18–64): per week minstens 150–300 minuten matig of 75–150 minuten zwaar intensieve aerobe activiteit, plus spierversterkende activiteiten op 2 of meer dagen (sterke aanbeveling, matige zekerheid). Vanaf 65: hetzelfde, plus gevarieerde activiteit met nadruk op balans en kracht op 3 of meer dagen. 5–17 jaar: gemiddeld 60 minuten per dag. Beperk zitten; een beetje bewegen is beter dan niets.',
        ],

        'ACT-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'guideline',
            'language' => 'nl',
            'title' => 'Beweegrichtlijnen 2017',
            'authors' => null,
            'organisation' => 'Gezondheidsraad',
            'published' => '2017-08-22',
            'citation' => 'Gezondheidsraad, Den Haag 2017, publicatienr. 2017/08',
            'url' => 'https://www.gezondheidsraad.nl/documenten/adviezen/2017/08/22/beweegrichtlijnen-2017',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'Gezondheidsraad nr. 2017/08',
            'pdf' => ['mode' => 'official_pdf', 'url' => 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2017/08/22/beweegrichtlijnen-2017/kernadvies-Beweegrichtlijnen-2017.pdf',
                      'licence' => 'Gratis te lezen, auteursrecht voorbehouden', 'hosted' => null, 'embed' => true],
            'findings' => 'Bewegen is goed, meer bewegen is beter. Volwassenen en ouderen: minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, minstens twee keer per week spier- en botversterkende activiteiten (ouderen met balansoefeningen) en voorkom veel stilzitten. Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.',
        ],

        'ACT-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Daily steps and health outcomes in adults: a systematic review and dose-response meta-analysis',
            'authors' => 'Ding D, Nguyen B, Nau T, et al.',
            'organisation' => null,
            'published' => '2025',
            'citation' => 'Lancet Public Health 2025;10(8):e668–e681',
            'url' => 'https://doi.org/10.1016/S2468-2667(25)00164-1',
            'doi' => '10.1016/S2468-2667(25)00164-1', 'pmid' => '40713949', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://www.thelancet.com/pdfs/journals/lanpub/PIIS2468-2667(25)00164-1.pdf',
                      'licence' => 'CC BY-NC 4.0', 'hosted' => null],
            'findings' => 'In 57 studies uit 35 cohorten hing 7.000 stappen per dag, vergeleken met 2.000, samen met 47% minder sterfte, 25% minder hart- en vaatziekten, 38% minder dementie en 22% minder depressieve klachten. Voor sterfte vlakte het verband af rond 5.000–7.000 stappen. De zekerheid van het bewijs was voor de meeste uitkomsten matig.',
        ],

        'ACT-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'meta_analysis',
            'language' => 'en',
            'title' => 'Daily steps and all-cause mortality: a meta-analysis of 15 international cohorts',
            'authors' => 'Paluch AE, Bajpai S, Bassett DR, et al.',
            'organisation' => 'Steps for Health Collaborative',
            'published' => '2022',
            'citation' => 'Lancet Public Health 2022;7(3):e219–e228',
            'url' => 'https://doi.org/10.1016/S2468-2667(21)00302-9',
            'doi' => '10.1016/S2468-2667(21)00302-9', 'pmid' => '35247352', 'pmcid' => 'PMC9289978', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => 'https://www.thelancet.com/article/S2468266721003029/pdf',
                      'licence' => 'CC BY-NC-ND 4.0', 'hosted' => null],
            'findings' => 'In 15 cohorten (47.471 volwassenen) hing meer stappen per dag samen met een steeds lager sterfterisico, tot het afvlakte rond 6.000–8.000 stappen bij 60-plussers en rond 8.000–10.000 bij volwassenen onder de 60.',
        ],

        'MEA-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'umbrella_review',
            'language' => 'en',
            'title' => 'Keeping Pace with Wearables: A Living Umbrella Review of Systematic Reviews Evaluating the Accuracy of Consumer Wearable Technologies in Health Measurement',
            'authors' => 'Doherty C, Baldwin M, Keogh A, et al.',
            'organisation' => null,
            'published' => '2024',
            'citation' => 'Sports Med 2024;54(11):2907–2926',
            'url' => 'https://doi.org/10.1007/s40279-024-02077-2',
            'doi' => '10.1007/s40279-024-02077-2', 'pmid' => '39080098', 'pmcid' => 'PMC11560992', 'other_id' => null,
            'pdf' => ['mode' => 'hosted', 'url' => 'https://link.springer.com/content/pdf/10.1007/s40279-024-02077-2.pdf',
                      'licence' => 'CC BY 4.0', 'hosted' => 'assets/research/MEA-01.pdf'],
            'findings' => 'Een overzicht van 24 systematische reviews (249 validatiestudies): hartslag wijkt gemiddeld ongeveer 3% af, stappen worden meestal iets ondergeschat (−9% tot 12%), energieverbruik kan flink afwijken (−21% tot +15%), zuurstofsaturatie tot 2 procentpunt, en slaaptijd wordt meestal overschat, vaak met meer dan 10%. Ongeveer 11% van de apparaten is voor minstens één meting gevalideerd.',
        ],

        'NUT-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'reference_values',
            'language' => 'nl',
            'title' => 'Voedingsnormen voor vetten, vetzuren, verteerbare koolhydraten en voedingsvezels',
            'authors' => null,
            'organisation' => 'Gezondheidsraad',
            'published' => '2026-07-07',
            'citation' => 'Gezondheidsraad, Den Haag 2026, publicatienr. 2026/14 (gecorrigeerde pdf 7 september 2026)',
            'url' => 'https://www.gezondheidsraad.nl/documenten/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'Gezondheidsraad nr. 2026/14',
            'pdf' => ['mode' => 'official_pdf', 'url' => 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels/14-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels-erratum.pdf',
                      'licence' => 'Gratis te lezen, auteursrecht voorbehouden', 'hosted' => null, 'embed' => true],
            'findings' => 'Totaal vet: 20–40% van de energie (vanaf 4 jaar); de kwaliteit van het voedingspatroon telt meer dan de hoeveelheid vet. Verzadigd vet en vrije suikers: zo laag mogelijk, met 10% van de energie als tussenstap. Koolhydraten: geen percentage, wel vooral uit volkoren, groente, fruit, peulvruchten en zuivel. Vezels: 3,0–3,5 gram per megajoule, ofwel 12,6–14,6 gram per 1.000 kcal.',
        ],

        'NUT-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'reference_values',
            'language' => 'nl',
            'title' => 'Voedingsnormen voor eiwitten',
            'authors' => null,
            'organisation' => 'Gezondheidsraad',
            'published' => '2021-03-02',
            'citation' => 'Gezondheidsraad, Den Haag 2021, publicatienr. 2021/10',
            'url' => 'https://www.gezondheidsraad.nl/documenten/2021/03/02/voedingsnormen-voor-eiwitten',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'Gezondheidsraad nr. 2021/10',
            'pdf' => ['mode' => 'official_pdf', 'url' => 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2021/03/02/voedingsnormen-voor-eiwitten/Advies-Voedingsnormen-voor-eiwitten-referentiewaarden-voor-de-inname-van-eiwitten.pdf',
                      'licence' => 'Gratis te lezen, auteursrecht voorbehouden', 'hosted' => null, 'embed' => true],
            'findings' => 'Volwassenen hebben gemiddeld 0,66 gram eiwit per kilogram lichaamsgewicht per dag nodig; de aanbevolen hoeveelheid is 0,83 gram per kilogram per dag. Er is geen overtuigend bewijs dat alle ouderen meer nodig hebben; sommige groepen, zoals kwetsbare ouderen, mogelijk wel.',
        ],

        'NUT-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'guideline',
            'language' => 'nl',
            'title' => 'Richtlijnen goede voeding 2015',
            'authors' => null,
            'organisation' => 'Gezondheidsraad',
            'published' => '2015-11-04',
            'citation' => 'Gezondheidsraad, Den Haag 2015, publicatienr. 2015/24, ISBN 978-94-6281-089-1',
            'url' => 'https://www.gezondheidsraad.nl/documenten/adviezen/2015/11/04/richtlijnen-goede-voeding-2015',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'Gezondheidsraad nr. 2015/24',
            'pdf' => ['mode' => 'official_pdf', 'url' => 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2015/11/04/richtlijnen-goede-voeding-2015/201524_Richtlijnen+goede+voeding+2015.pdf',
                      'licence' => 'Gratis te lezen, auteursrecht voorbehouden', 'hosted' => null, 'embed' => true],
            'findings' => 'Eet meer plantaardig en minder dierlijk: dagelijks minstens 200 gram groente en 200 gram fruit, minstens 90 gram volkoren, wekelijks peulvruchten, 15 gram ongezouten noten, enkele porties zuivel en wekelijks vis. Beperk rood en vooral bewerkt vlees, drink zo min mogelijk suikerhoudende dranken, geen of hooguit één glas alcohol per dag, en maximaal 6 gram zout per dag.',
        ],

        'NUT-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Comparison of predictive equations for resting metabolic rate in healthy nonobese and obese adults: a systematic review',
            'authors' => 'Frankenfield D, Roth-Yousey L, Compher C',
            'organisation' => 'American Dietetic Association',
            'published' => '2005',
            'citation' => 'J Am Diet Assoc 2005;105(5):775–789',
            'url' => 'https://doi.org/10.1016/j.jada.2005.02.005',
            'doi' => '10.1016/j.jada.2005.02.005', 'pmid' => '15883556', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Van de vier meest gebruikte formules voorspelde Mifflin-St Jeor het rustmetabolisme het vaakst binnen 10% van de gemeten waarde, bij mensen met en zonder obesitas. Voor individuen blijven er opvallende afwijkingen.',
        ],

        'NUT-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'position_stand',
            'language' => 'en',
            'title' => 'International Society of Sports Nutrition Position Stand: protein and exercise',
            'authors' => 'Jäger R, Kerksick CM, Campbell BI, et al.',
            'organisation' => 'International Society of Sports Nutrition',
            'published' => '2017',
            'citation' => 'J Int Soc Sports Nutr 2017;14:20',
            'url' => 'https://doi.org/10.1186/s12970-017-0177-8',
            'doi' => '10.1186/s12970-017-0177-8', 'pmid' => '28642676', 'pmcid' => 'PMC5477153', 'other_id' => null,
            'pdf' => ['mode' => 'hosted', 'url' => 'https://jissn.biomedcentral.com/track/pdf/10.1186/s12970-017-0177-8',
                      'licence' => 'CC BY 4.0', 'hosted' => 'assets/research/NUT-05.pdf'],
            'findings' => 'Voor het opbouwen en behouden van spiermassa is 1,4–2,0 gram eiwit per kilogram lichaamsgewicht per dag voldoende voor de meeste mensen die sporten. Bij krachtsporters die afvallen kan meer nodig zijn om spiermassa te behouden.',
        ],

        'BOD-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-27', 'verified_on' => '2026-10-27',
            'kind' => 'fact_sheet',
            'language' => 'en',
            'title' => 'Obesity and overweight',
            'authors' => null,
            'organisation' => 'Wereldgezondheidsorganisatie (WHO)',
            'published' => '2026-10-07',
            'citation' => 'WHO-feitenblad, bijgewerkt 7 oktober 2026',
            'url' => 'https://www.who.int/news-room/fact-sheets/detail/obesity-and-overweight',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Webpagina, geen pdf', 'hosted' => null],
            'findings' => 'Bij volwassenen (18+) noemt de WHO een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas. Voor 5–19 jaar gelden groeicurves. Ook buikomvang kan worden gebruikt.',
        ],
    ],

    /* ========================================================= CLAIMS === */
    /* `finding` is machine-readable and read by the rules; `bands` hold an
       age-dependent finding: [age_min, age_max (null: no upper), values]. */
    'claims' => [

        'sleep.duration.cdc' => [
            'source' => 'SLP-01', 'version' => 1, 'topic' => 'sleep.duration', 'metric' => 'sleep_minutes',
            'age_min' => 6, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['unit' => 'hours', 'bands' => [
                [6, 12, ['min' => 9, 'max' => 12]],
                [13, 17, ['min' => 8, 'max' => 10]],
                [18, 60, ['min' => 7, 'max' => null]],
                [61, 64, ['min' => 7, 'max' => 9]],
                [65, null, ['min' => 7, 'max' => 8]],
            ]],
            'statement' => 'Per leeftijd een aanbevolen slaapduur: 7 uur of meer van 18 tot 60 jaar, 7–9 uur van 61 tot 64, 7–8 uur vanaf 65, en 8–10 uur voor tieners van 13 tot 17.',
            'population' => 'Iedereen vanaf 6 jaar, zonder slaapstoornis.',
            'limitations' => [
                'Een samenvatting van twee panels met iets andere leeftijdsgrenzen.',
                'Iedereen heeft een eigen slaapbehoefte.',
                'Een wearable schat slaaptijd vaak te hoog in.',
            ],
        ],

        'sleep.duration.adults' => [
            'source' => 'SLP-02', 'version' => 1, 'topic' => 'sleep.duration', 'metric' => 'sleep_minutes',
            'age_min' => 18, 'age_max' => 60, 'strength' => 'betrouwbaar',
            'finding' => ['unit' => 'hours', 'min' => 7],
            'statement' => 'Volwassenen van 18 tot 60 jaar: regelmatig minstens 7 uur slaap per nacht. Korter slapen hangt samen met meer gezondheidsrisico\'s.',
            'population' => 'Gezonde volwassenen van 18 tot 60 jaar.',
            'limitations' => [
                'Gebaseerd op verbanden, niet op bewezen oorzaken.',
                'Gaat alleen over duur, niet over kwaliteit of tijdstip.',
                'Niet voor mensen met een slaapstoornis.',
            ],
        ],

        'sleep.duration.nsf' => [
            'source' => 'SLP-03', 'version' => 1, 'topic' => 'sleep.duration', 'metric' => 'sleep_minutes',
            'age_min' => 14, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['unit' => 'hours', 'bands' => [
                [14, 17, ['min' => 8, 'max' => 10]],
                [18, 64, ['min' => 7, 'max' => 9]],
                [65, null, ['min' => 7, 'max' => 8]],
            ]],
            'statement' => 'Bij volwassenen past 7–9 uur slaap, bij 65-plussers 7–8 uur en bij tieners 8–10 uur.',
            'population' => 'Gezonde mensen zonder slaapstoornis.',
            'limitations' => [
                'Een consensus van deskundigen; de bereiken zijn geen diagnose.',
                'Daarbuiten slapen kan passend zijn.',
            ],
        ],

        'sleep.duration.youth' => [
            'source' => 'SLP-04', 'version' => 1, 'topic' => 'sleep.duration', 'metric' => 'sleep_minutes',
            'age_min' => 6, 'age_max' => 18, 'strength' => 'betrouwbaar',
            'finding' => ['unit' => 'hours', 'bands' => [
                [6, 12, ['min' => 9, 'max' => 12]],
                [13, 18, ['min' => 8, 'max' => 10]],
            ]],
            'statement' => 'Kinderen van 6 tot 12 jaar: 9–12 uur slaap per 24 uur; tieners van 13 tot 18 jaar: 8–10 uur.',
            'population' => 'Kinderen en tieners.',
            'limitations' => [
                'Gebaseerd op verbanden, niet op bewezen oorzaken.',
                'Iedereen heeft een eigen slaapbehoefte.',
            ],
        ],

        'sleep.regularity' => [
            'source' => 'SLP-05', 'version' => 1, 'topic' => 'sleep.regularity', 'metric' => 'sleep_timing',
            'age_min' => null, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['consistent_timing' => true, 'threshold_minutes' => null],
            'statement' => 'Regelmatige tijden van inslapen en opstaan zijn belangrijk voor gezondheid, veiligheid en prestaties.',
            'population' => 'Volwassenen en jongeren.',
            'limitations' => [
                'Vooral observationeel onderzoek.',
                'Er is geen aanbevolen aantal minuten speling.',
                'Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.',
            ],
        ],

        'sleep.tracking' => [
            'source' => 'SLP-06', 'version' => 1, 'topic' => 'measurement.sleep', 'metric' => 'sleep_stages',
            'age_min' => null, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['sleep_sensitivity_min' => 0.93, 'wake_specificity' => [0.18, 0.54], 'stages' => 'inconsistent'],
            'statement' => 'Wearables herkennen slaap goed, maar wakker liggen en slaapfasen zoals diep en REM veel minder betrouwbaar.',
            'population' => 'Gezonde jonge volwassenen, apparaten uit 2019.',
            'limitations' => [
                'Eén kleine studie.',
                'Oudere apparaten dan die van nu.',
            ],
        ],

        'activity.who.adults' => [
            'source' => 'ACT-01', 'version' => 1, 'topic' => 'activity.aerobic', 'metric' => 'activity_minutes_week',
            'age_min' => 18, 'age_max' => 64, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['moderate_minutes_week' => [150, 300], 'vigorous_minutes_week' => [75, 150], 'strength_days_week' => 2],
            'statement' => 'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, plus spierversterkende activiteiten op minstens 2 dagen.',
            'population' => 'Volwassenen van 18 tot 64 jaar, ook met een chronische aandoening.',
            'limitations' => [
                'Alle beweging telt mee, ook wandelen, fietsen en klussen.',
                'Ownify ziet alleen de trainingen die je vastlegt.',
            ],
        ],

        'activity.who.older' => [
            'source' => 'ACT-01', 'version' => 1, 'topic' => 'activity.aerobic', 'metric' => 'activity_minutes_week',
            'age_min' => 65, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['moderate_minutes_week' => [150, 300], 'vigorous_minutes_week' => [75, 150], 'strength_days_week' => 2, 'balance_strength_days_week' => 3],
            'statement' => 'Vanaf 65 jaar dezelfde hoeveelheid beweging, plus op minstens 3 dagen per week activiteiten voor balans en kracht.',
            'population' => 'Volwassenen vanaf 65 jaar.',
            'limitations' => [
                'Alle beweging telt mee, ook wandelen, fietsen en klussen.',
                'Ownify ziet alleen de trainingen die je vastlegt.',
            ],
        ],

        'activity.who.some' => [
            'source' => 'ACT-01', 'version' => 1, 'topic' => 'activity.any', 'metric' => null,
            'age_min' => 5, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['any_activity_better_than_none' => true],
            'statement' => 'Een beetje bewegen is beter dan niets: zitten vervangen door bewegen van elke intensiteit, ook licht, levert gezondheidswinst op.',
            'population' => 'Iedereen vanaf 5 jaar.',
            'limitations' => [
                'Een algemene aanbeveling, geen voorspelling voor één persoon.',
            ],
        ],

        'activity.gr.adults' => [
            'source' => 'ACT-02', 'version' => 1, 'topic' => 'activity.aerobic', 'metric' => 'activity_minutes_week',
            'age_min' => 18, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['moderate_minutes_week' => 150, 'strength_bone_per_week' => 2],
            'statement' => 'Minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, en minstens twee keer per week spier- en botversterkende activiteiten.',
            'population' => 'Volwassenen en ouderen (ouderen ook balansoefeningen).',
            'limitations' => [
                'Alle beweging telt mee, ook wandelen en fietsen.',
                'Ownify ziet alleen de trainingen die je vastlegt.',
            ],
        ],

        'activity.gr.youth' => [
            'source' => 'ACT-02', 'version' => 1, 'topic' => 'activity.aerobic', 'metric' => 'activity_minutes_day',
            'age_min' => 4, 'age_max' => 17, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['moderate_minutes_day' => 60, 'strength_bone_per_week' => 3],
            'statement' => 'Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.',
            'population' => 'Kinderen en jongeren van 4 tot 18 jaar.',
            'limitations' => [
                'Ownify ziet alleen de trainingen die je vastlegt.',
            ],
        ],

        'activity.steps.ding' => [
            'source' => 'ACT-03', 'version' => 1, 'topic' => 'activity.steps', 'metric' => 'steps_day',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['reference_steps' => 7000, 'comparison_steps' => 2000, 'levelling_steps' => [5000, 7000]],
            'statement' => 'Meer stappen per dag hangt samen met minder gezondheidsrisico\'s; rond 7.000 stappen per dag was dat verband al duidelijk.',
            'population' => 'Volwassenen.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Voor de meeste uitkomsten maar enkele studies.',
                'Stappentellers tellen meestal iets te weinig.',
            ],
        ],

        'activity.steps.paluch' => [
            'source' => 'ACT-04', 'version' => 1, 'topic' => 'activity.steps', 'metric' => 'steps_day',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['bands' => [
                [18, 59, ['plateau' => [8000, 10000]]],
                [60, null, ['plateau' => [6000, 8000]]],
            ]],
            'statement' => 'Het verband met een lager sterfterisico vlakt af rond 8.000–10.000 stappen per dag onder de 60 en rond 6.000–8.000 vanaf 60 jaar.',
            'population' => 'Volwassenen.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Gaat alleen over sterfte.',
            ],
        ],

        'measurement.wearables' => [
            'source' => 'MEA-01', 'version' => 1, 'topic' => 'measurement.accuracy', 'metric' => null,
            'age_min' => null, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['heart_rate_bias_pct' => 3, 'steps_error_pct' => [-9, 12], 'energy_error_pct' => [-21, 15],
                          'spo2_difference_max' => 2, 'sleep_time' => 'overestimated'],
            'statement' => 'Wearables meten hartslag en stappen redelijk nauwkeurig, maar energieverbruik en slaaptijd veel minder: calorieën kunnen ruim 20% afwijken en slaaptijd wordt meestal overschat.',
            'population' => 'Consumentenwearables, vooral bij gezonde volwassenen.',
            'limitations' => [
                'Studies gebruiken verschillende methoden.',
                'Apparaten veranderen sneller dan het onderzoek.',
            ],
        ],

        'nutrition.fat' => [
            'source' => 'NUT-01', 'version' => 1, 'topic' => 'nutrition.fat', 'metric' => 'fat_energy_pct',
            'age_min' => 4, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['energy_pct' => [20, 40]],
            'statement' => 'Totaal vet: 20–40% van je energie. De kwaliteit van wat je eet telt meer dan de hoeveelheid vet.',
            'population' => 'Iedereen vanaf 4 jaar.',
            'limitations' => [
                'Ownify ziet wat je logt, niet alles wat je eet.',
            ],
        ],

        'nutrition.saturated_fat' => [
            'source' => 'NUT-01', 'version' => 1, 'topic' => 'nutrition.saturated_fat', 'metric' => 'saturated_fat_energy_pct',
            'age_min' => null, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['as_low_as_possible' => true, 'interim_energy_pct_max' => 10],
            'statement' => 'Verzadigd vet: zo laag mogelijk, met 10% van je energie als tussenstap.',
            'population' => 'Iedereen.',
            'limitations' => [
                'Ownify ziet wat je logt, niet alles wat je eet.',
            ],
        ],

        'nutrition.carbohydrates' => [
            'source' => 'NUT-01', 'version' => 1, 'topic' => 'nutrition.carbohydrates', 'metric' => 'carbs_energy_pct',
            'age_min' => null, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['percentage_norm' => false],
            'statement' => 'Koolhydraten hebben geen percentagenorm: het gaat om de bron, vooral volkoren, groente, fruit, peulvruchten en zuivel.',
            'population' => 'Iedereen.',
            'limitations' => [
                'Ownify ziet niet uit welke producten je koolhydraten komen.',
            ],
        ],

        'nutrition.free_sugars' => [
            'source' => 'NUT-01', 'version' => 1, 'topic' => 'nutrition.sugars', 'metric' => 'free_sugars_energy_pct',
            'age_min' => null, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['as_low_as_possible' => true, 'interim_energy_pct_max' => 10],
            'statement' => 'Vrije suikers: zo laag mogelijk, met 10% van je energie als tussenstap.',
            'population' => 'Iedereen.',
            'limitations' => [
                'Je apps loggen alle suikers samen, ook die in fruit en melk; vrije suikers zijn daaruit niet af te lezen.',
            ],
        ],

        'nutrition.fibre' => [
            'source' => 'NUT-01', 'version' => 1, 'topic' => 'nutrition.fibre', 'metric' => 'fibre_per_1000_kcal',
            'age_min' => 2, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['g_per_mj' => [3.0, 3.5], 'g_per_1000_kcal' => [12.6, 14.6]],
            'statement' => 'Vezels: 3,0–3,5 gram per megajoule, dat is 12,6–14,6 gram per 1.000 kcal, uit volkoren, groente, fruit, peulvruchten en noten.',
            'population' => 'Iedereen vanaf 2 jaar.',
            'limitations' => [
                'Ownify ziet wat je logt, niet alles wat je eet.',
            ],
        ],

        'nutrition.protein' => [
            'source' => 'NUT-02', 'version' => 1, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg',
            'age_min' => 18, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['g_per_kg_day' => 0.83, 'average_requirement_g_per_kg' => 0.66],
            'statement' => 'Volwassenen: aanbevolen 0,83 gram eiwit per kilogram lichaamsgewicht per dag.',
            'population' => 'Volwassenen, ook ouderen.',
            'limitations' => [
                'Ownify ziet wat je logt, niet alles wat je eet.',
                'Je eiwitdoel in Ownify hangt af van je activiteit en kan hoger liggen.',
            ],
        ],

        'nutrition.protein.exercise' => [
            'source' => 'NUT-05', 'version' => 1, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['g_per_kg_day' => [1.4, 2.0], 'applies_to' => 'exercising'],
            'statement' => 'Voor mensen die sporten noemt een sportvoedingsvereniging 1,4–2,0 gram eiwit per kilogram per dag voldoende om spiermassa op te bouwen en te behouden.',
            'population' => 'Gezonde mensen die sporten.',
            'limitations' => [
                'Het standpunt van een vereniging, geen officiële norm.',
                'Voor de algemene bevolking geldt 0,83 gram per kilogram.',
            ],
        ],

        'nutrition.salt' => [
            'source' => 'NUT-03', 'version' => 1, 'topic' => 'nutrition.salt', 'metric' => 'salt_g_day',
            'age_min' => 18, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['salt_g_day_max' => 6, 'salt_per_sodium' => 2.5],
            'statement' => 'Maximaal 6 gram zout per dag.',
            'population' => 'Volwassenen.',
            'limitations' => [
                'Apps loggen zout dat je zelf toevoegt meestal niet.',
            ],
        ],

        'nutrition.pattern' => [
            'source' => 'NUT-03', 'version' => 1, 'topic' => 'nutrition.pattern', 'metric' => null,
            'age_min' => null, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['plant_based' => true],
            'statement' => 'Gezond eten is meer plantaardig en minder dierlijk: veel groente, fruit, volkoren, peulvruchten en noten, en weinig rood en bewerkt vlees en suikerhoudende dranken.',
            'population' => 'De algemene bevolking.',
            'limitations' => [
                'Deels bijgewerkt: in 2025 kwam een nieuw advies over eiwitbronnen; groente, fruit en granen volgen.',
                'Ownify ziet niet welke producten je eet.',
            ],
        ],

        'nutrition.energy_estimate' => [
            'source' => 'NUT-04', 'version' => 1, 'topic' => 'nutrition.energy', 'metric' => 'energy_target',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['within_pct' => 10],
            'statement' => 'Je energiedoel is een schatting met de Mifflin-St Jeor-formule. Die zit bij de meeste mensen binnen 10% van het gemeten rustverbruik, maar kan per persoon meer afwijken.',
            'population' => 'Gezonde volwassenen, met en zonder obesitas.',
            'limitations' => [
                'Gaat over rustverbruik; de factor voor je activiteit is een tweede schatting.',
                'Onderzoek uit 2005.',
            ],
        ],

        'body.bmi' => [
            'source' => 'BOD-01', 'version' => 1, 'topic' => 'body.bmi', 'metric' => 'bmi',
            'age_min' => 18, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['overweight_min' => 25, 'obesity_min' => 30],
            'statement' => 'De WHO noemt bij volwassenen een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas.',
            'population' => 'Volwassenen vanaf 18 jaar.',
            'limitations' => [
                'BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.',
                'Een indeling voor groepen, geen oordeel over één persoon.',
            ],
        ],
    ],
];

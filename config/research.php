<?php
/**
 * The research behind Ownify's insights — every source and every claim, in
 * one reviewed place (docs/RESEARCH.md).
 *
 * ---------------------------------------------------------------------------
 * WHAT MAY BE IN HERE
 * ---------------------------------------------------------------------------
 * Only sources the owner approved one by one (Phase 1 report, 27 October
 * 2026: all 17 approved; 31.0, 28 October 2026: 40 more, round 1 and the
 * age sources of round 2). Each was checked against the publisher, PubMed or
 * the official PDF before it was proposed. A source that is not approved is
 * not in this file, and a claim names exactly one source. A new source, a
 * new claim or a changed number is a new approval: change `status` only on
 * the owner's word, raise `version` when a claim's meaning changes.
 *
 * ---------------------------------------------------------------------------
 * WHERE IT LIVES
 * ---------------------------------------------------------------------------
 * This file is the reviewed text; the database holds the same registry
 * (research_sources, research_claims — migrations 028 and 029) and is what the
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
        /* 31.0 */
        'observational'     => 'Observationeel onderzoek',
        'review'            => 'Overzichtsartikel',
        'methods'           => 'Artikel over onderzoeksmethoden',
        'definitions'       => 'Officiële definities',
        'pooled_analysis'   => 'Samengevoegde analyse van laboratoriumstudies',
        'public_info'       => 'Officiële voorlichting',
        'device_validation' => 'Validatiestudie tegen een ecg',
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

        /* ---- 31.0: approved one by one by the owner on 28 October 2026
           (round 1: 32 sources; round 2: the eight AGE sources). Link only
           for now: hosting the CC BY ones is a later step. */

        'XSA-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The effects of physical activity on sleep: a meta-analytic review',
            'authors' => 'Kredlow MA, Capozzoli MC, Hearon BA, Calkins AW, Otto MW',
            'organisation' => null,
            'published' => '2015',
            'citation' => 'J Behav Med 2015;38(3):427–449',
            'url' => 'https://doi.org/10.1007/s10865-015-9617-6',
            'doi' => '10.1007/s10865-015-9617-6', 'pmid' => '25596964', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 66 studies had één keer bewegen een klein gunstig effect op totale slaaptijd, inslaaptijd, slaapefficiëntie en diepe slaap, en een matig effect op wakker liggen na het inslapen. Regelmatig bewegen had kleine gunstige effecten op slaaptijd en efficiëntie, klein tot matig op inslaaptijd en matig op slaapkwaliteit. De effecten hingen af van geslacht, leeftijd, fitheid, soort beweging, tijdstip, duur en volhouden, niet van de intensiteit.',
        ],

        'XSA-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'umbrella_review',
            'language' => 'en',
            'title' => 'Physical activity and sleep: An updated umbrella review of the 2018 Physical Activity Guidelines Advisory Committee report',
            'authors' => 'Kline CE, Hillman CH, Bloodgood Sheppard B, et al.',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Sleep Med Rev 2021;58:101489',
            'url' => 'https://doi.org/10.1016/j.smrv.2021.101489',
            'doi' => '10.1016/j.smrv.2021.101489', 'pmid' => '33934046', 'pmcid' => 'PMC8338757', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Gratis te lezen in PMC, geen hergebruiklicentie', 'hosted' => null],
            'findings' => 'Sterk bewijs dat zowel één keer bewegen als regelmatig bewegen de slaap verbetert. Matig bewijs dat langere sessies meer helpen, dat het effect geldt voor volwassenen van alle leeftijden en beide geslachten, en dat bewegen de slaap verbetert bij volwassenen met slapeloosheidsklachten of obstructieve slaapapneu.',
        ],

        'XSA-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Daily associations between sleep and physical activity: A systematic review and meta-analysis',
            'authors' => 'Atoui S, Chevance G, Romain AJ, et al.',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Sleep Med Rev 2021;57:101426',
            'url' => 'https://doi.org/10.1016/j.smrv.2021.101426',
            'doi' => '10.1016/j.smrv.2021.101426', 'pmid' => '33571893', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 33 studies bij volwassenen was er geen steun voor een verband in twee richtingen van dag tot dag. Slaapkwaliteit, slaapefficiëntie en wakker liggen in de nacht hingen samen met bewegen de dag erna, maar die verbanden waren klein en verschilden in richting en niveau (tussen mensen of binnen één persoon). Tussen mensen ging meer bewegen overdag samen met iets korter slapen de nacht erna (een klein effect).',
        ],

        'XSA-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Effects of Evening Exercise on Sleep in Healthy Participants: A Systematic Review and Meta-Analysis',
            'authors' => 'Stutz J, Eiholzer R, Spengler CM',
            'organisation' => null,
            'published' => '2019',
            'citation' => 'Sports Med 2019;49(2):269–287',
            'url' => 'https://doi.org/10.1007/s40279-018-1015-0',
            'doi' => '10.1007/s40279-018-1015-0', 'pmid' => '30374942', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 23 studies maakte bewegen in de avond de slaap niet slechter, eerder het omgekeerde: de REM-slaap begon 7,7 minuten later, diepe slaap nam 1,3 procentpunt toe en de lichtste slaap 0,9 procentpunt af. Zware inspanning die een uur of minder voor bedtijd eindigde, kan het inslapen verlengen en de slaaptijd en efficiëntie verlagen.',
        ],

        'XSA-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The effects of evening high-intensity exercise on sleep in healthy adults: A systematic review and meta-analysis',
            'authors' => 'Frimpong E, Mograss M, Zvionow T, Dang-Vu TT',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Sleep Med Rev 2021;60:101535',
            'url' => 'https://doi.org/10.1016/j.smrv.2021.101535',
            'doi' => '10.1016/j.smrv.2021.101535', 'pmid' => '34416428', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 15 studies (194 deelnemers) verminderde zware inspanning die 0,5 tot 4 uur voor bedtijd eindigde de REM-slaap licht (−2,34%); andere slaapmaten veranderden niet. Regelmatig zwaar trainen in de avond verstoorde de slaap niet, en inspanning 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen van 18 tot 50 jaar niet.',
        ],

        'XSA-06' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'observational',
            'language' => 'en',
            'title' => 'Dose-response relationship between evening exercise and sleep',
            'authors' => 'Leota J, Presby DM, Le F, et al.',
            'organisation' => null,
            'published' => '2025',
            'citation' => 'Nat Commun 2025;16(1):3297',
            'url' => 'https://doi.org/10.1038/s41467-025-58271-x',
            'doi' => '10.1038/s41467-025-58271-x', 'pmid' => '40234380', 'pmcid' => 'PMC12000559', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY-NC-ND 4.0', 'hosted' => null],
            'findings' => 'Bij 14.689 dragers van een wearable (ruim 4 miljoen nachten in een jaar) ging later en zwaarder trainen samen met later inslapen, korter en minder goed slapen, een hogere hartslag in de nacht en een lagere HRV. Training die minstens 4 uur voor het inslapen eindigde, ging niet samen met een andere slaap, hoe zwaar ook. Voorbeeld: maximale in plaats van lichte inspanning die 2 uur voor de gewone inslaaptijd eindigde, ging samen met 36 minuten later inslapen. De gewone inslaaptijd werd per persoon per maand bepaald, doordeweeks en in het weekend apart.',
        ],

        'XSA-08' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The effect of resistance exercise on sleep: A systematic review of randomized controlled trials',
            'authors' => 'Kovacevic A, Mavros Y, Heisz JJ, Fiatarone Singh MA',
            'organisation' => null,
            'published' => '2018',
            'citation' => 'Sleep Med Rev 2018;39:52–68',
            'url' => 'https://doi.org/10.1016/j.smrv.2017.07.002',
            'doi' => '10.1016/j.smrv.2017.07.002', 'pmid' => '28919335', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 13 gerandomiseerde studies verbeterde regelmatige krachttraining alle aspecten van slaap, vooral de slaapkwaliteit; samen met duurtraining was het voordeel kleiner. Het effect van één krachttraining is weinig onderzocht en wisselend.',
        ],

        'XSP-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Effects of Acute Sleep Loss on Physical Performance: A Systematic and Meta-Analytical Review',
            'authors' => 'Craven J, McCartney D, Desbrow B, et al.',
            'organisation' => null,
            'published' => '2022',
            'citation' => 'Sports Med 2022;52(11):2669–2690',
            'url' => 'https://doi.org/10.1007/s40279-022-01706-y',
            'doi' => '10.1007/s40279-022-01706-y', 'pmid' => '35708888', 'pmcid' => 'PMC9584849', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Gratis te lezen in PMC, licentie nog te bevestigen', 'hosted' => null],
            'findings' => 'In 69 publicaties verminderde slaaptekort (6 uur of minder in 24 uur) de sportprestatie gemiddeld met 7,56% (95%-interval −11,9 tot −3,13), in elke soort inspanning. Het effect was er na een nacht zonder slaap en na eerder opstaan dan gewoonlijk.',
        ],

        'XSP-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Sleep and the athlete: narrative review and 2021 expert consensus recommendations',
            'authors' => 'Walsh NP, Halson SL, Sargent C, et al.',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Br J Sports Med 2021;55(7):356–368',
            'url' => 'https://doi.org/10.1136/bjsports-2020-102025',
            'doi' => '10.1136/bjsports-2020-102025', 'pmid' => '33144349', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Licentie niet bevestigd', 'hosted' => null],
            'findings' => 'Sporters slapen vaak korter dan 7 uur. Een nacht of meer zonder slaap vermindert de prestatie; het effect van 1 tot 3 nachten gedeeltelijk slaaptekort is onduidelijk. In de bredere bevolking maakt gewoonlijk korter dan 7 uur slapen vatbaarder voor luchtweginfecties. Eén advies van 7–9 uur voor iedereen is waarschijnlijk niet ideaal; de deskundigen raden een persoonlijke aanpak aan.',
        ],

        'XSN-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The effects of partial sleep deprivation on energy balance: a systematic review and meta-analysis',
            'authors' => 'Al Khatib HK, Harding SV, Darzi J, Pot GK',
            'organisation' => null,
            'published' => '2017',
            'citation' => 'Eur J Clin Nutr 2017;71(5):614–624',
            'url' => 'https://doi.org/10.1038/ejcn.2016.201',
            'doi' => '10.1038/ejcn.2016.201', 'pmid' => '27804960', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Gedeeltelijk slaaptekort verhoogde de energie-inname met 385 kcal per dag (95%-interval 252 tot 517), zonder effect op het totale energieverbruik: per saldo meer energie binnen dan eruit.',
        ],

        'XSN-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The influence of sleep health on dietary intake: a systematic review and meta-analysis of intervention studies',
            'authors' => 'Fenton S, Burrows TL, Skinner JA, Duncan MJ',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'J Hum Nutr Diet 2021;34(2):273–285',
            'url' => 'https://doi.org/10.1111/jhn.12813',
            'doi' => '10.1111/jhn.12813', 'pmid' => '33001515', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Gedeeltelijk slaaptekort tot 5,5 uur of minder per nacht verhoogde de energie-inname per dag (gestandaardiseerd verschil 0,37, 95%-interval 0,21 tot 0,52), en ook de inname van vet, eiwit en koolhydraten.',
        ],

        'XSN-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'The effect of caffeine on subsequent sleep: A systematic review and meta-analysis',
            'authors' => 'Gardiner C, Weakley J, Burke LM, et al.',
            'organisation' => null,
            'published' => '2023',
            'citation' => 'Sleep Med Rev 2023;69:101764',
            'url' => 'https://doi.org/10.1016/j.smrv.2023.101764',
            'doi' => '10.1016/j.smrv.2023.101764', 'pmid' => '36870101', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY volgens Europe PMC, nog te bevestigen', 'hosted' => null],
            'findings' => 'In 24 studies verkortte cafeïne de totale slaap met 45 minuten en de slaapefficiëntie met 7%, en verlengde het inslapen met 9 minuten en het wakker liggen met 12 minuten. Om niet korter te slapen zou koffie (107 mg per 250 ml) minstens 8,8 uur voor bedtijd moeten worden gedronken, en een gangbaar pre-workoutsupplement (217,5 mg) minstens 13,2 uur ervoor.',
        ],

        'XTH-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'review',
            'language' => 'en',
            'title' => 'Cardiac parasympathetic reactivation following exercise: implications for training prescription',
            'authors' => 'Stanley J, Peake JM, Buchheit M',
            'organisation' => null,
            'published' => '2013',
            'citation' => 'Sports Med 2013;43(12):1259–1277',
            'url' => 'https://doi.org/10.1007/s40279-013-0083-4',
            'doi' => '10.1007/s40279-013-0083-4', 'pmid' => '23912805', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Volledig herstel van het autonome zenuwstelsel van het hart na één duurtraining duurt tot 24 uur na lichte inspanning, 24 tot 48 uur na inspanning rond de drempel en minstens 48 uur na zware inspanning. Fittere mensen herstellen sneller, en het verschilt sterk per persoon.',
        ],

        'XTH-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Monitoring Athletic Training Status Through Autonomic Heart Rate Regulation: A Systematic Review and Meta-Analysis',
            'authors' => 'Bellenger CR, Fuller JT, Thomson RL, et al.',
            'organisation' => null,
            'published' => '2016',
            'citation' => 'Sports Med 2016;46(10):1461–1486',
            'url' => 'https://doi.org/10.1007/s40279-016-0484-2',
            'doi' => '10.1007/s40279-016-0484-2', 'pmid' => '26888648', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Als training tot een betere prestatie leidde, steeg de HRV in rust licht (gestandaardiseerd verschil 0,58), net als het herstel van de hartslag na inspanning. Bij overbelasting veranderde de HRV in rust nauwelijks, dus HRV alleen kan goede en slechte aanpassing niet van elkaar onderscheiden.',
        ],

        'XTH-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'review',
            'language' => 'en',
            'title' => 'Training adaptation and heart rate variability in elite endurance athletes: opening the door to effective monitoring',
            'authors' => 'Plews DJ, Laursen PB, Stanley J, Kilding AE, Buchheit M',
            'organisation' => null,
            'published' => '2013',
            'citation' => 'Sports Med 2013;43(9):773–781',
            'url' => 'https://doi.org/10.1007/s40279-013-0071-8',
            'doi' => '10.1007/s40279-013-0071-8', 'pmid' => '23852425', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Bij topsporters zijn zowel een stijging als een daling van de HRV in verband gebracht met slechte aanpassing aan training. Volg HRV van dag tot dag met gemiddelden (lopende gemiddelden van logaritmisch omgerekende waarden), niet met losse metingen.',
        ],

        'XTH-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'review',
            'language' => 'en',
            'title' => 'Monitoring training status with HR measures: do all roads lead to Rome?',
            'authors' => 'Buchheit M',
            'organisation' => null,
            'published' => '2014',
            'citation' => 'Front Physiol 2014;5:73',
            'url' => 'https://doi.org/10.3389/fphys.2014.00073',
            'doi' => '10.3389/fphys.2014.00073', 'pmid' => '24578692', 'pmcid' => 'PMC3936188', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY', 'hosted' => null],
            'findings' => 'De meeste tegenstrijdige uitkomsten komen door verschillen in methode en verkeerd lezen. Bij één persoon moet je een verandering in een hartslagmaat lezen tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van de training.',
        ],

        'XTH-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'observational',
            'language' => 'en',
            'title' => 'What Is behind Changes in Resting Heart Rate and Heart Rate Variability? A Large-Scale Analysis of Longitudinal Measurements Acquired in Free-Living',
            'authors' => 'Altini M, Plews D',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Sensors 2021;21(23):7932',
            'url' => 'https://doi.org/10.3390/s21237932',
            'doi' => '10.3390/s21237932', 'pmid' => '34883936', 'pmcid' => 'PMC8659706', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Bij 28.175 mensen (9 miljoen ochtendmetingen in 5 jaar) was de HRV (rMSSD) na zware training 3% lager en de hartslag 0,7% hoger; na lichte training was de HRV 1,6% hoger. Na veel alcohol: HRV 12% lager, hartslag 6% hoger. Bij ziekte: HRV 10% lager, hartslag 6% hoger. De HRV daalde met de leeftijd (r = −0,35). HRV is gevoelig maar niet specifiek: veel dingen verlagen haar.',
        ],

        'XNT-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'position_stand',
            'language' => 'en',
            'title' => 'International society of sports nutrition position stand: nutrient timing',
            'authors' => 'Kerksick CM, Arent S, Schoenfeld BJ, et al.',
            'organisation' => 'International Society of Sports Nutrition',
            'published' => '2017',
            'citation' => 'J Int Soc Sports Nutr 2017;14:33',
            'url' => 'https://doi.org/10.1186/s12970-017-0189-4',
            'doi' => '10.1186/s12970-017-0189-4', 'pmid' => '28919842', 'pmcid' => 'PMC5596471', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Het moment van eten en de verhouding van de voedingsstoffen kunnen herstel en spieropbouw helpen na veel of zware training. Glycogeenvoorraden zijn het grootst bij 8 tot 12 gram koolhydraten per kilogram per dag; koolhydraten (0,8 g/kg per uur) samen met eiwit (0,2–0,4 g/kg per uur) helpen ze snel aan te vullen.',
        ],

        'TRN-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'methods',
            'language' => 'en',
            'title' => 'Acute:Chronic Workload Ratio: Conceptual Issues and Fundamental Pitfalls',
            'authors' => 'Impellizzeri FM, Tenan MS, Kempton T, Novak A, Coutts AJ',
            'organisation' => null,
            'published' => '2020',
            'citation' => 'Int J Sports Physiol Perform 2020;15(6):907–913',
            'url' => 'https://doi.org/10.1123/ijspp.2019-0864',
            'doi' => '10.1123/ijspp.2019-0864', 'pmid' => '32502973', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Er is geen bewijs dat de verhouding tussen recente en langere trainingsbelasting (acute:chronic workload ratio) helpt om blessures te voorkomen; de verhouding is statistisch onnauwkeurig en voegt ruis en schijnverbanden toe.',
        ],

        'TRN-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Recovery and Performance in Sport: Consensus Statement',
            'authors' => 'Kellmann M, Bertollo M, Bosquet L, et al.',
            'organisation' => null,
            'published' => '2018',
            'citation' => 'Int J Sports Physiol Perform 2018;13(2):240–245',
            'url' => 'https://doi.org/10.1123/ijspp.2017-0759',
            'doi' => '10.1123/ijspp.2017-0759', 'pmid' => '29345524', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Een balans tussen belasting (training, wedstrijden en wat het leven verder vraagt) en herstel is essentieel. Hoe iemand reageert verschilt tussen mensen en bij één persoon van moment tot moment, dus herstel volg je het best per persoon.',
        ],

        'BOD-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Definition and diagnostic criteria of clinical obesity',
            'authors' => 'Rubino F, Cummings DE, Eckel RH, et al.',
            'organisation' => 'Lancet Diabetes & Endocrinology Commission',
            'published' => '2025',
            'citation' => 'Lancet Diabetes Endocrinol 2025;13(3):221–262',
            'url' => 'https://doi.org/10.1016/S2213-8587(24)00316-4',
            'doi' => '10.1016/S2213-8587(24)00316-4', 'pmid' => '39824205', 'pmcid' => 'PMC11870235', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Gratis te lezen in PMC, geen hergebruiklicentie', 'hosted' => null],
            'findings' => 'Een wereldwijde commissie van 58 deskundigen: gebruik BMI alleen als ruwe maat voor gezondheidsrisico in groepen, voor onderzoek of screening, niet als maat voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een directe meting van het vetpercentage of met minstens één andere lichaamsmaat (buikomvang, taille-heupverhouding of taille-lengteverhouding) naast BMI, of met twee zulke maten ongeacht de BMI; boven een BMI van 40 mag je het aannemen.',
        ],

        'BOD-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'observational',
            'language' => 'en',
            'title' => 'Weekly, seasonal and holiday body weight fluctuation patterns among individuals engaged in a European multi-centre behavioural weight loss maintenance intervention',
            'authors' => 'Turicchi J, O\'Driscoll R, Horgan G, et al.',
            'organisation' => null,
            'published' => '2020',
            'citation' => 'PLoS One 2020;15(4):e0232152',
            'url' => 'https://doi.org/10.1371/journal.pone.0232152',
            'doi' => '10.1371/journal.pone.0232152', 'pmid' => '32353079', 'pmcid' => 'PMC7192384', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Bij 1.421 mensen met een slimme weegschaal schommelde het gewicht binnen een week met 0,35%: hoger na het weekend, lager doordeweeks. Rond kerst nam het gewicht gemiddeld 1,35% toe, en dat was in de maanden erna niet helemaal weer verdwenen.',
        ],

        'ACT-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'guideline',
            'language' => 'en',
            'title' => 'World Health Organization 2020 guidelines on physical activity and sedentary behaviour',
            'authors' => 'Bull FC, Al-Ansari SS, Biddle S, et al.',
            'organisation' => 'Wereldgezondheidsorganisatie (WHO)',
            'published' => '2020',
            'citation' => 'Br J Sports Med 2020;54(24):1451–1462',
            'url' => 'https://doi.org/10.1136/bjsports-2020-102955',
            'doi' => '10.1136/bjsports-2020-102955', 'pmid' => '33239350', 'pmcid' => 'PMC7719906', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Volwassenen: per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een gelijkwaardige mix; spierversterkende activiteiten voor alle leeftijden; kinderen en jongeren gemiddeld 60 minuten per dag; minder stilzitten; een beetje bewegen is beter dan niets.',
        ],

        'ACT-07' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'definitions',
            'language' => 'nl',
            'title' => 'Bewegen: Verantwoording, definities',
            'authors' => null,
            'organisation' => 'RIVM, VZinfo.nl',
            'published' => '2026',
            'citation' => 'RIVM, VZinfo.nl, Bewegen – Verantwoording, definities (geraadpleegd 28 oktober 2026)',
            'url' => 'https://www.vzinfo.nl/bewegen/verantwoording-definities',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Webpagina, geen pdf', 'hosted' => null],
            'findings' => 'Herhaalt de Beweegrichtlijnen 2017 en geeft de intensiteiten: licht 1,6–2,9 MET, matig 3,0–5,9 MET, zwaar 6 MET of meer; zittend gedrag is 1,5 MET of minder, zittend of liggend en niet slapend.',
        ],

        'NUT-07' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'guideline',
            'language' => 'nl',
            'title' => 'Richtlijnen goede voeding: eiwitbronnen en voedingspatronen 2025',
            'authors' => null,
            'organisation' => 'Gezondheidsraad',
            'published' => '2025-12-04',
            'citation' => 'Gezondheidsraad, Den Haag 2025, publicatienr. 2025/19',
            'url' => 'https://zoek.officielebekendmakingen.nl/blg-1226497.pdf',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => 'Gezondheidsraad nr. 2025/19',
            'pdf' => ['mode' => 'link', 'url' => 'https://zoek.officielebekendmakingen.nl/blg-1226497.pdf',
                      'licence' => 'Gratis te lezen, auteursrecht voorbehouden', 'hosted' => null],
            'findings' => 'Werkt een deel van de Richtlijnen goede voeding 2015 bij, voor eiwitbronnen (peulvruchten, noten, zuivel, vis, vlees, eieren en vervangers): een meer plantaardig en minder dierlijk voedingspatroon, met hooguit ongeveer 200 gram rood vlees per week en ongeveer 250 gram (bereide) peulvruchten per week. Groente, fruit en granen volgen in een later advies; tot dan gelden daarvoor de richtlijnen van 2015.',
        ],

        'NUT-08' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'public_info',
            'language' => 'nl',
            'title' => 'Vocht',
            'authors' => null,
            'organisation' => 'Voedingscentrum',
            'published' => '2026',
            'citation' => 'Voedingscentrum, encyclopedie: Vocht (geraadpleegd 28 oktober 2026)',
            'url' => 'https://www.voedingscentrum.nl/encyclopedie/vocht.aspx',
            'doi' => null, 'pmid' => null, 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Webpagina, geen pdf', 'hosted' => null],
            'findings' => 'Vrouwen en meisjes vanaf 13 jaar: drink minstens 1,4 liter per dag; mannen en jongens vanaf 13 jaar: minstens 1,4 tot 1,8 liter. Alle dranken tellen mee. Bij flink sporten en zweten meer drinken (per uur sporten kun je 1 tot 2 liter verliezen). Op een heel warme dag minstens 2 liter, en niet meer dan 3 liter per dag.',
        ],

        'HRT-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'meta_analysis',
            'language' => 'en',
            'title' => 'Resting heart rate and all-cause and cardiovascular mortality in the general population: a meta-analysis',
            'authors' => 'Zhang D, Shen X, Qi X',
            'organisation' => null,
            'published' => '2016',
            'citation' => 'CMAJ 2016;188(3):E53–E63',
            'url' => 'https://doi.org/10.1503/cmaj.150535',
            'doi' => '10.1503/cmaj.150535', 'pmid' => '26598376', 'pmcid' => 'PMC4754196', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Gratis te lezen in PMC, geen hergebruiklicentie', 'hosted' => null],
            'findings' => 'In 46 cohortstudies ging elke 10 slagen per minuut hogere rusthartslag samen met een relatief risico van 1,09 op overlijden door alle oorzaken en 1,08 door hart- en vaatziekten. Boven 80 slagen per minuut was dat 1,45 en 1,33, vergeleken met de laagste groep.',
        ],

        'HRT-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'review',
            'language' => 'en',
            'title' => 'An Overview of Heart Rate Variability Metrics and Norms',
            'authors' => 'Shaffer F, Ginsberg JP',
            'organisation' => null,
            'published' => '2017',
            'citation' => 'Front Public Health 2017;5:258',
            'url' => 'https://doi.org/10.3389/fpubh.2017.00258',
            'doi' => '10.3389/fpubh.2017.00258', 'pmid' => '29034226', 'pmcid' => 'PMC5624990', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht; waarden over 24 uur, over enkele minuten en over minder dan een minuut zijn niet uitwisselbaar. Gepubliceerde normen moeten worden aangevuld met waarden uit de eigen groep.',
        ],

        'MEA-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'device_validation',
            'language' => 'en',
            'title' => 'Assessing the Accuracy of Popular Commercial Technologies That Measure Resting Heart Rate and Heart Rate Variability',
            'authors' => 'Stone JD, Ulman HK, Tran K, et al.',
            'organisation' => null,
            'published' => '2021',
            'citation' => 'Front Sports Act Living 2021;3:585870',
            'url' => 'https://doi.org/10.3389/fspor.2021.585870',
            'doi' => '10.3389/fspor.2021.585870', 'pmid' => '33733234', 'pmcid' => 'PMC7956986', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Bij 5 volwassenen (148 metingen tegen een ecg) maten alle zeven apparaten de gemiddelde rusthartslag nauwkeurig. Voor HRV (rMSSD) liep de nauwkeurigheid sterk uiteen: een gemiddelde afwijking van 4,1% bij het beste apparaat en 112% bij een camera-app.',
        ],

        'MTH-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'methods',
            'language' => 'en',
            'title' => 'Controlling the false discovery rate: a practical and powerful approach to multiple testing',
            'authors' => 'Benjamini Y, Hochberg Y',
            'organisation' => null,
            'published' => '1995',
            'citation' => 'J R Stat Soc Series B 1995;57(1):289–300',
            'url' => 'https://doi.org/10.1111/j.2517-6161.1995.tb02031.x',
            'doi' => '10.1111/j.2517-6161.1995.tb02031.x', 'pmid' => null, 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Een methode die, als je veel dingen tegelijk toetst, het verwachte aandeel valse vondsten onder de gerapporteerde vondsten onder een gekozen grens houdt.',
        ],

        'MTH-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'methods',
            'language' => 'en',
            'title' => 'Causal Analysis of Self-tracked Time Series Data Using a Counterfactual Framework for N-of-1 Trials',
            'authors' => 'Daza EJ',
            'organisation' => null,
            'published' => '2018',
            'citation' => 'Methods Inf Med 2018;57(1):e10–e21',
            'url' => 'https://doi.org/10.3414/ME16-02-0044',
            'doi' => '10.3414/ME16-02-0044', 'pmid' => '29621835', 'pmcid' => 'PMC6087468', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY-NC-ND', 'hosted' => null],
            'findings' => 'Verbanden in de zelf bijgehouden gegevens van één persoon kunnen wijzen op mogelijke oorzaak en gevolg, maar om ze zo te lezen moet je expliciet rekening houden met hoe dagen op elkaar lijken, met trends in de tijd, met doorwerking en met vertraagde effecten; een gerandomiseerde n-van-1-studie is de echte toets.',
        ],

        'MTH-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'methods',
            'language' => 'en',
            'title' => 'The state of the art and future opportunities for using longitudinal n-of-1 methods in health behaviour research: a systematic literature overview',
            'authors' => 'McDonald S, Quinn F, Vieira R, et al.',
            'organisation' => null,
            'published' => '2017',
            'citation' => 'Health Psychol Rev 2017;11(4):307–323',
            'url' => 'https://doi.org/10.1080/17437199.2017.1316672',
            'doi' => '10.1080/17437199.2017.1316672', 'pmid' => '28406349', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY volgens Europe PMC, nog te bevestigen', 'hosted' => null],
            'findings' => 'In 39 n-van-1-studies konden effecten binnen één persoon anders zijn dan die tussen mensen. Herhaald meten bij één persoon kan inzichten over gezondheidsgedrag persoonlijk maken, ook voor slapen, bewegen en eten.',
        ],

        'AGE-01' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Age-predicted maximal heart rate revisited',
            'authors' => 'Tanaka H, Monahan KD, Seals DR',
            'organisation' => null,
            'published' => '2001',
            'citation' => 'J Am Coll Cardiol 2001;37(1):153–156',
            'url' => 'https://doi.org/10.1016/s0735-1097(00)01054-8',
            'doi' => '10.1016/s0735-1097(00)01054-8', 'pmid' => '11153730', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 351 studies (18.712 mensen) en een eigen laboratoriumonderzoek (514 mensen) was de maximale hartslag te voorspellen met 208 − 0,7 × leeftijd (r = −0,90 met de leeftijd); het laboratorium gaf vrijwel hetzelfde: 209 − 0,7 × leeftijd. Er was geen verschil tussen mannen en vrouwen of naar hoeveel iemand bewoog. De bekende 220 − leeftijd schat de maximale hartslag van ouderen te laag.',
        ],

        'AGE-02' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'position_stand',
            'language' => 'en',
            'title' => 'American College of Sports Medicine position stand. Quantity and quality of exercise for developing and maintaining cardiorespiratory, musculoskeletal, and neuromotor fitness in apparently healthy adults: guidance for prescribing exercise',
            'authors' => 'Garber CE, Blissmer B, Deschenes MR, et al.',
            'organisation' => 'American College of Sports Medicine',
            'published' => '2011',
            'citation' => 'Med Sci Sports Exerc 2011;43(7):1334–1359',
            'url' => 'https://doi.org/10.1249/MSS.0b013e318213fefb',
            'doi' => '10.1249/MSS.0b013e318213fefb', 'pmid' => '21694556', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'Voor de meeste volwassenen: matige duurinspanning minstens 30 minuten op minstens 5 dagen (150 minuten of meer per week), of zware minstens 20 minuten op minstens 3 dagen (75 minuten of meer), of een combinatie; krachtoefeningen voor elke grote spiergroep op 2 tot 3 dagen; oefeningen voor balans, behendigheid en coördinatie op 2 tot 3 dagen. De indeling naar intensiteit legt matig bij 64–76% en zwaar bij 77–95% van de maximale hartslag.',
        ],

        'AGE-03' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Meta-analysis of quantitative sleep parameters from childhood to old age in healthy individuals: developing normative sleep values across the human lifespan',
            'authors' => 'Ohayon MM, Carskadon MA, Guilleminault C, Vitiello MV',
            'organisation' => null,
            'published' => '2004',
            'citation' => 'Sleep 2004;27(7):1255–1273',
            'url' => 'https://doi.org/10.1093/sleep/27.7.1255',
            'doi' => '10.1093/sleep/27.7.1255', 'pmid' => '15586779', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Geen open licentie', 'hosted' => null],
            'findings' => 'In 65 studies (3.577 gezonde mensen van 5 tot 102 jaar) namen bij volwassenen de totale slaaptijd, de slaapefficiëntie, het aandeel diepe slaap, het aandeel REM-slaap en de tijd tot de eerste REM-slaap af met de leeftijd, terwijl de inslaaptijd, het aandeel lichte slaap en het wakker liggen in de nacht toenamen. Na je 60e bleef alleen de slaapefficiëntie verder dalen. Hoe sterk de veranderingen leken, hing sterk af van hoe zorgvuldig de deelnemers waren geselecteerd.',
        ],

        'AGE-04' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Evidence-based recommendations for optimal dietary protein intake in older people: a position paper from the PROT-AGE Study Group',
            'authors' => 'Bauer J, Biolo G, Cederholm T, et al.',
            'organisation' => 'PROT-AGE Study Group',
            'published' => '2013',
            'citation' => 'J Am Med Dir Assoc 2013;14(8):542–559',
            'url' => 'https://doi.org/10.1016/j.jamda.2013.05.021',
            'doi' => '10.1016/j.jamda.2013.05.021', 'pmid' => '23867520', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY-NC-ND', 'hosted' => null],
            'findings' => 'Ouderen hebben meer eiwit nodig dan jongere volwassenen. Boven de 65: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag; 1,2 g/kg of meer voor wie sport of anderszins actief is; 1,2–1,5 g/kg voor de meeste mensen met een acute of chronische ziekte. Uitzondering: ernstige nierziekte zonder dialyse.',
        ],

        'AGE-05' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'consensus',
            'language' => 'en',
            'title' => 'Protein intake and exercise for optimal muscle function with aging: recommendations from the ESPEN Expert Group',
            'authors' => 'Deutz NE, Bauer JM, Barazzoni R, et al.',
            'organisation' => 'ESPEN Expert Group',
            'published' => '2014',
            'citation' => 'Clin Nutr 2014;33(6):929–936',
            'url' => 'https://doi.org/10.1016/j.clnu.2014.04.007',
            'doi' => '10.1016/j.clnu.2014.04.007', 'pmid' => '24814383', 'pmcid' => 'PMC4208946', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Gratis te lezen in PMC, licentie nog te bevestigen', 'hosted' => null],
            'findings' => 'Gezonde ouderen: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag. Ouderen die ondervoed zijn of dat dreigen te worden door een acute of chronische ziekte: 1,2–1,5 g/kg, meer bij ernstige ziekte of letsel. Dagelijks bewegen of sporten (kracht en duur) voor alle ouderen, zo lang het kan.',
        ],

        'AGE-06' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'pooled_analysis',
            'language' => 'en',
            'title' => 'Protein ingestion to stimulate myofibrillar protein synthesis requires greater relative protein intakes in healthy older versus younger men',
            'authors' => 'Moore DR, Churchward-Venne TA, Witard O, et al.',
            'organisation' => null,
            'published' => '2015',
            'citation' => 'J Gerontol A Biol Sci Med Sci 2015;70(1):57–62',
            'url' => 'https://doi.org/10.1093/gerona/glu103',
            'doi' => '10.1093/gerona/glu103', 'pmid' => '25056502', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'De spiereiwitopbouw na één maaltijd vlakte af bij ongeveer 0,24 gram eiwit per kilogram lichaamsgewicht bij jonge mannen (rond 22 jaar) en bij ongeveer 0,40 g/kg bij oudere mannen (rond 71 jaar); het verschil was net niet significant (p = 0,055). Per kilogram vetvrije massa was het 0,25 tegenover 0,60 g/kg (p < 0,01). Oudere mannen reageren minder op kleine porties eiwit en hebben per maaltijd meer nodig.',
        ],

        'AGE-07' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'observational',
            'language' => 'en',
            'title' => 'Short-term heart rate variability—influence of gender and age in healthy subjects',
            'authors' => 'Voss A, Schroeder R, Heitmann A, Peters A, Perz S',
            'organisation' => null,
            'published' => '2015',
            'citation' => 'PLoS One 2015;10(3):e0118308',
            'url' => 'https://doi.org/10.1371/journal.pone.0118308',
            'doi' => '10.1371/journal.pone.0118308', 'pmid' => '25822720', 'pmcid' => 'PMC4378923', 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'CC BY 4.0', 'hosted' => null],
            'findings' => 'Bij 1.906 gezonde volwassenen (metingen van 5 minuten) veranderde de HRV in vrijwel al haar maten duidelijk met de leeftijd, over de decennia 25–34, 35–44, 45–54, 55–64 en 65–74. Verschillen tussen mannen en vrouwen verdwenen in de laatste twee decennia, en de leeftijdseffecten in het laatste.',
        ],

        'AGE-08' => [
            'status' => 'approved', 'approved_on' => '2026-10-28', 'verified_on' => '2026-10-28',
            'kind' => 'systematic_review',
            'language' => 'en',
            'title' => 'Advancing Age Is Not Associated With Greater Exercise-Induced Muscle Damage: A Systematic Review, Meta-Analysis, and Meta-Regression',
            'authors' => 'Fernandes JFT, Wilson LJ, Dingley AF, et al.',
            'organisation' => null,
            'published' => '2025',
            'citation' => 'J Aging Phys Act 2025;33(6):606–624',
            'url' => 'https://doi.org/10.1123/japa.2024-0165',
            'doi' => '10.1123/japa.2024-0165', 'pmid' => '40174882', 'pmcid' => null, 'other_id' => null,
            'pdf' => ['mode' => 'link', 'url' => null, 'licence' => 'Niet vrij beschikbaar (abonnement)', 'hosted' => null],
            'findings' => 'In 36 studies die oudere met jongere volwassenen vergeleken, was de daling van de spierfunctie 24 en 72 uur na inspanning, en de piek ervan, bij ouderen niet anders dan bij jongeren. Spierpijn en creatinekinase waren bij jongeren juist hoger. Conclusie: een hogere leeftijd gaat niet samen met meer spierschade door inspanning.',
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

        /* v2 (31.0, D9): the guideline's own journal paper (ACT-05, CC BY),
           with a hard minute counting as two moderate ones. */
        'activity.who.adults' => [
            'source' => 'ACT-05', 'version' => 2, 'topic' => 'activity.aerobic', 'metric' => 'activity_minutes_week',
            'age_min' => 18, 'age_max' => 64, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['moderate_minutes_week' => [150, 300], 'vigorous_minutes_week' => [75, 150], 'strength_days_week' => 2,
                          'vigorous_counts_double' => true],
            'statement' => 'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een mix waarin een zware minuut telt als twee matige, plus spierversterkende activiteiten op minstens 2 dagen.',
            'population' => 'Volwassenen van 18 tot 64 jaar, ook met een chronische aandoening.',
            'limitations' => [
                'Alle beweging telt mee, ook wandelen, fietsen en klussen.',
                'Ownify ziet alleen de trainingen die je vastlegt.',
                'Of een training zwaar was, leidt Ownify af uit je hartslag tegen de maximale hartslag voor je leeftijd.',
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

        /* v2 (31.0, D9): 18–64; from 65 the PROT-AGE and ESPEN advice
           (nutrition.protein.older). */
        'nutrition.protein.exercise' => [
            'source' => 'NUT-05', 'version' => 2, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg',
            'age_min' => 18, 'age_max' => 64, 'strength' => 'beperkt',
            'finding' => ['g_per_kg_day' => [1.4, 2.0], 'applies_to' => 'exercising'],
            'statement' => 'Voor mensen die sporten noemt een sportvoedingsvereniging 1,4–2,0 gram eiwit per kilogram per dag voldoende om spiermassa op te bouwen en te behouden.',
            'population' => 'Gezonde mensen van 18 tot 64 jaar die sporten.',
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

        /* v2 (31.0): the 2025 advice on protein sources and patterns
           (NUT-07) that v1's limitation already announced. */
        'nutrition.pattern' => [
            'source' => 'NUT-07', 'version' => 2, 'topic' => 'nutrition.pattern', 'metric' => null,
            'age_min' => null, 'age_max' => null, 'strength' => 'zeer_betrouwbaar',
            'finding' => ['plant_based' => true, 'legumes_g_week' => 250, 'red_meat_g_week_max' => 200],
            'statement' => 'Gezond eten is meer plantaardig en minder dierlijk. Het advies van 2025 over eiwitbronnen noemt per week ongeveer 250 gram peulvruchten en hooguit ongeveer 200 gram rood vlees; voor groente, fruit en granen gelden de richtlijnen van 2015.',
            'population' => 'De algemene bevolking.',
            'limitations' => [
                'Groente, fruit en granen krijgen een nieuw advies; tot dan gelden de richtlijnen van 2015.',
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
        /* ---- 31.0: the claims behind combined insights
           (config/correlations.php) and the age proposal (D9, round 2). */

        'cross.activity.sleep' => [
            'source' => 'XSA-02', 'version' => 1, 'topic' => 'cross.activity_sleep', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['acute' => 'improves', 'regular' => 'improves', 'longer_bouts' => 'more'],
            'statement' => 'Bewegen, één keer of regelmatig, gaat gemiddeld samen met beter slapen; langere sessies helpen meer.',
            'population' => 'Volwassenen van alle leeftijden, ook met slapeloosheidsklachten of slaapapneu.',
            'limitations' => [
                'Gebaseerd op reviews van wisselende kwaliteit.',
                'Gemiddelden over groepen, geen voorspelling voor één persoon.',
                'Het beste tijdstip en de beste hoeveelheid zijn nog onzeker.',
            ],
        ],

        'cross.exercise.sleep' => [
            'source' => 'XSA-01', 'version' => 1, 'topic' => 'cross.activity_sleep', 'metric' => null,
            'age_min' => null, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['acute_effect' => 'small', 'regular_effect' => 'small_to_moderate', 'intensity_mattered' => false],
            'statement' => 'Eén keer bewegen gaat gemiddeld samen met iets langer en efficiënter slapen, regelmatig bewegen ook met een betere slaapkwaliteit. De effecten zijn klein tot matig.',
            'population' => 'Gemengde groepen, ook mensen met slapeloosheid.',
            'limitations' => [
                'Studies tot 2013, vooral klein en in een laboratorium.',
                'Gemiddelden over groepen, geen voorspelling voor één persoon.',
            ],
        ],

        'cross.daily.sleep_activity' => [
            'source' => 'XSA-03', 'version' => 1, 'topic' => 'cross.activity_sleep', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['day_to_day' => 'small', 'direction' => 'varies'],
            'statement' => 'Van dag tot dag hangen slapen en bewegen maar weinig samen: de verbanden zijn klein en verschillen per persoon en per richting.',
            'population' => 'Volwassenen.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Weinig studies keken binnen één persoon.',
            ],
        ],

        'cross.strength.sleep' => [
            'source' => 'XSA-08', 'version' => 1, 'topic' => 'cross.activity_sleep', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['regular' => 'improves', 'single_session' => 'unclear'],
            'statement' => 'Regelmatige krachttraining verbetert de slaap, vooral de slaapkwaliteit; over één losse training is weinig bekend.',
            'population' => 'Volwassenen in gerandomiseerde studies.',
            'limitations' => [
                'Geen samengevoegde getallen.',
                'Gemengde groepen deelnemers.',
            ],
        ],

        'cross.evening_exercise.sleep' => [
            'source' => 'XSA-06', 'version' => 1, 'topic' => 'cross.exercise_timing', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['safe_hours_before_onset' => 4, 'example_onset_delay_minutes' => 36],
            'statement' => 'Later en zwaarder trainen ging samen met later inslapen, korter slapen, een hogere hartslag en een lagere HRV in de nacht; training die minstens 4 uur voor het inslapen eindigde, niet.',
            'population' => 'Volwassen dragers van één merk wearable, vooral actieve mensen.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Eén studie, met gebruikers van één merk.',
                'De wearable mat niet wanneer mensen probeerden te gaan slapen.',
            ],
        ],

        'cross.evening_exercise.review' => [
            'source' => 'XSA-04', 'version' => 1, 'topic' => 'cross.exercise_timing', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['evening_exercise' => 'no_harm', 'vigorous_within_hours' => 1],
            'statement' => 'Bewegen in de avond maakt de slaap gemiddeld niet slechter; alleen zware inspanning die een uur of minder voor bedtijd eindigt, kan het inslapen vertragen.',
            'population' => 'Gezonde, vooral jonge volwassenen.',
            'limitations' => [
                'Losse sessies in een laboratorium.',
                'De effecten van tijdstip en zwaarte waren niet stevig.',
            ],
        ],

        'cross.evening_hie.sleep' => [
            'source' => 'XSA-05', 'version' => 1, 'topic' => 'cross.exercise_timing', 'metric' => null,
            'age_min' => 18, 'age_max' => 50, 'strength' => 'betrouwbaar',
            'finding' => ['rem_pct_change' => -2.34, 'no_disruption_hours' => [2, 4]],
            'statement' => 'Zware training 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen niet; dichter op bedtijd verminderde ze de REM-slaap licht.',
            'population' => 'Gezonde volwassenen van 18 tot 50 jaar die goed slapen.',
            'limitations' => [
                'Kleine studies.',
                'Alleen goede slapers.',
            ],
        ],

        'cross.training.hrv' => [
            'source' => 'XTH-05', 'version' => 1, 'topic' => 'cross.training_heart', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['after_hard_pct' => -3, 'after_light_pct' => 1.6, 'alcohol_pct' => -12, 'sickness_pct' => -10],
            'statement' => 'Na zware training was de HRV de volgende ochtend gemiddeld ongeveer 3% lager, na lichte training ongeveer 1,6% hoger. Ook alcohol en ziekte verlagen de HRV, dus een lage HRV zegt niet waardoor.',
            'population' => 'Volwassen gebruikers van een HRV-app.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Een meting van 1 minuut bij het wakker worden; Ownify gebruikt de waarden uit de nacht.',
                'Gezondheidsbewuste gebruikers van één app.',
            ],
        ],

        'cross.training.hr' => [
            'source' => 'XTH-05', 'version' => 1, 'topic' => 'cross.training_heart', 'metric' => 'resting_hr',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['after_hard_pct' => 0.7, 'alcohol_pct' => 6, 'sickness_pct' => 6],
            'statement' => 'Na zware training was de hartslag in rust de volgende ochtend gemiddeld ongeveer 0,7% hoger. Ook alcohol en ziekte verhogen hem, dus een hogere hartslag zegt niet waardoor.',
            'population' => 'Volwassen gebruikers van een HRV-app.',
            'limitations' => [
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
                'Een meting van 1 minuut bij het wakker worden; Ownify gebruikt de waarden uit de nacht.',
            ],
        ],

        'cross.training.recovery' => [
            'source' => 'XTH-01', 'version' => 1, 'topic' => 'recovery', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['hours_light' => 24, 'hours_threshold' => [24, 48], 'hours_hard_min' => 48],
            'statement' => 'Het hart herstelt na lichte duurtraining binnen ongeveer 24 uur, na training rond de drempel in 24 tot 48 uur en na zware training in minstens 48 uur. Fittere mensen herstellen sneller.',
            'population' => 'Volwassenen na duurtraining.',
            'limitations' => [
                'Vooral duurtraining; over krachttraining is weinig bekend.',
                'Verschilt sterk per persoon.',
            ],
        ],

        'hrv.monitoring' => [
            'source' => 'XTH-02', 'version' => 1, 'topic' => 'hrv', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['better_performance_smd' => 0.58, 'overreaching' => 'little_change'],
            'statement' => 'Een hogere HRV in rust kan bij goede aanpassing aan training horen, maar bij overbelasting verandert de HRV nauwelijks: één HRV-waarde zegt niet of training goed of slecht uitpakt.',
            'population' => 'Duursporters.',
            'limitations' => [
                'Vooral getrainde duursporters.',
                'Veel kleine studies.',
            ],
        ],

        'hrv.averaging' => [
            'source' => 'XTH-03', 'version' => 1, 'topic' => 'hrv', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['use' => 'rolling_mean_of_log'],
            'statement' => 'Volg HRV met gemiddelden over meerdere dagen, niet met losse metingen: zowel een stijging als een daling kan bij slechte aanpassing horen.',
            'population' => 'Topsporters in duursport.',
            'limitations' => [
                'Een overzichtsartikel over topsporters.',
            ],
        ],

        'hrv.change' => [
            'source' => 'XTH-04', 'version' => 1, 'topic' => 'hrv', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['read_against' => ['measurement_error', 'smallest_worthwhile_change']],
            'statement' => 'Lees een verandering in je HRV of hartslag tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van je training.',
            'population' => 'Sporters.',
            'limitations' => [
                'Een overzichtsartikel met een mening.',
            ],
        ],

        'hrv.norms' => [
            'source' => 'HRT-02', 'version' => 1, 'topic' => 'hrv', 'metric' => 'hrv',
            'age_min' => null, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['depends_on' => ['recording_length', 'age', 'sex']],
            'statement' => 'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht, dus vergelijk je HRV het best met je eigen waarden.',
            'population' => 'Iedereen.',
            'limitations' => [
                'Een verhalend overzichtsartikel.',
            ],
        ],

        'measurement.hrv' => [
            'source' => 'MEA-02', 'version' => 1, 'topic' => 'measurement.accuracy', 'metric' => 'hrv',
            'age_min' => null, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['resting_hr' => 'accurate', 'hrv_error_pct' => [4.1, 112]],
            'statement' => 'Wearables meten de rusthartslag nauwkeurig, maar hoe goed ze HRV meten verschilt sterk per apparaat.',
            'population' => 'Volwassenen, met apparaten van 2020.',
            'limitations' => [
                'Een heel kleine studie.',
                'Oudere apparaten dan die van nu.',
            ],
        ],

        'heart.resting.risk' => [
            'source' => 'HRT-01', 'version' => 1, 'topic' => 'heart.resting', 'metric' => 'resting_hr',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['rr_per_10_bpm' => ['all_cause' => 1.09, 'cardiovascular' => 1.08], 'rr_above_80' => ['all_cause' => 1.45, 'cardiovascular' => 1.33]],
            'statement' => 'In bevolkingsonderzoek ging elke 10 slagen per minuut hogere rusthartslag samen met 9% meer risico op overlijden en 8% meer op overlijden door hart- en vaatziekten; boven 80 slagen per minuut was dat 45% en 33% meer dan in de laagste groep. Ownify volgt je rusthartslag alleen tegen je eigen basis.',
            'population' => 'De algemene volwassen bevolking.',
            'limitations' => [
                'Een risico voor groepen, geen voorspelling voor één persoon.',
                'De studies verschilden sterk, en er is kans op publicatiebias.',
                'Observationeel onderzoek: een verband, geen bewezen oorzaak.',
            ],
        ],

        'cross.sleep.energy' => [
            'source' => 'XSN-01', 'version' => 1, 'topic' => 'cross.sleep_nutrition', 'metric' => 'energy_kcal',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['extra_kcal_day' => 385, 'ci' => [252, 517], 'expenditure' => 'unchanged'],
            'statement' => 'Na korter slapen aten mensen in studies gemiddeld 385 kcal per dag meer, zonder meer te verbruiken.',
            'population' => 'Volwassenen in korte laboratoriumstudies.',
            'limitations' => [
                'Korte studies, met vrij toegang tot eten.',
                'Kleine groepen.',
            ],
        ],

        'cross.sleep.diet' => [
            'source' => 'XSN-02', 'version' => 1, 'topic' => 'cross.sleep_nutrition', 'metric' => 'energy_kcal',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['short_night_hours_max' => 5.5, 'smd' => 0.37],
            'statement' => 'Slapen tot 5,5 uur of minder per nacht verhoogde in studies de energie-inname, en ook de inname van vet, eiwit en koolhydraten.',
            'population' => 'Volwassenen in interventiestudies.',
            'limitations' => [
                'Korte interventies.',
                'Weinig studies naar langer slapen.',
            ],
        ],

        'cross.sleep.performance' => [
            'source' => 'XSP-01', 'version' => 1, 'topic' => 'cross.sleep_training', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['performance_pct' => -7.56, 'ci' => [-11.9, -3.13], 'short_night_hours_max' => 6],
            'statement' => 'Na een nacht van 6 uur of minder was de sportprestatie gemiddeld ongeveer 7,6% lager, bij elke soort inspanning.',
            'population' => 'Volwassenen, vaak sporters.',
            'limitations' => [
                'Gaat over prestatie, niet over of iemand traint.',
                'Vooral korte laboratoriumstudies.',
            ],
        ],

        'sleep.athletes' => [
            'source' => 'XSP-03', 'version' => 1, 'topic' => 'cross.sleep_training', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['approach' => 'individual'],
            'statement' => 'Hoeveel slaap iemand nodig heeft verschilt; deskundigen raden sporters een persoonlijke aanpak aan in plaats van één advies van 7–9 uur.',
            'population' => 'Sporters.',
            'limitations' => [
                'Gaat over topsporters.',
                'Een verhalend overzicht met een consensus.',
            ],
        ],

        'cross.caffeine.sleep' => [
            'source' => 'XSN-05', 'version' => 1, 'topic' => 'cross.caffeine_sleep', 'metric' => 'caffeine',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['total_sleep_minutes' => -45, 'efficiency_pct' => -7, 'coffee_hours_before_bed' => 8.8, 'preworkout_hours_before_bed' => 13.2],
            'statement' => 'Cafeïne verkortte de slaap in studies gemiddeld met 45 minuten. Om dat te voorkomen zou koffie minstens 8,8 uur voor bedtijd gedronken moeten worden.',
            'population' => 'Volwassenen in laboratoriumstudies.',
            'limitations' => [
                'Laboratoriumstudies met vaste doses.',
                'Ownify ziet alleen cafeïne die je logt.',
            ],
        ],

        'nutrition.fluid' => [
            'source' => 'NUT-08', 'version' => 1, 'topic' => 'nutrition.fluid', 'metric' => 'water_l',
            'age_min' => 13, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['women_min_l' => 1.4, 'men_l' => [1.4, 1.8], 'hot_day_min_l' => 2, 'max_l' => 3, 'exercise_loss_l_per_hour' => [1, 2]],
            'statement' => 'Vanaf 13 jaar: drink minstens 1,4 liter per dag (mannen en jongens 1,4 tot 1,8 liter), en meer als je flink sport en zweet.',
            'population' => 'Iedereen vanaf 13 jaar.',
            'limitations' => [
                'Een algemeen advies.',
                'Ownify ziet alleen wat je logt.',
            ],
        ],

        'nutrition.timing' => [
            'source' => 'XNT-01', 'version' => 1, 'topic' => 'nutrition.timing', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['carbs_g_per_kg_h' => 0.8, 'protein_g_per_kg_h' => [0.2, 0.4]],
            'statement' => 'Na veel of zware training kunnen het moment van eten en de verhouding tussen koolhydraten en eiwit het herstel helpen.',
            'population' => 'Getrainde mensen.',
            'limitations' => [
                'Het standpunt van een vereniging, gericht op getrainde mensen.',
            ],
        ],

        'recovery.individual' => [
            'source' => 'TRN-02', 'version' => 1, 'topic' => 'recovery', 'metric' => null,
            'age_min' => null, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['monitoring' => 'individual'],
            'statement' => 'Herstel na belasting verschilt tussen mensen en bij één persoon van moment tot moment; je volgt het daarom het best bij jezelf.',
            'population' => 'Sporters.',
            'limitations' => [
                'Een consensus over begrippen, met weinig getallen.',
            ],
        ],

        'body.bmi.individual' => [
            'source' => 'BOD-02', 'version' => 1, 'topic' => 'body.bmi', 'metric' => 'bmi',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['bmi_individual_measure' => false, 'confirm_with' => ['waist', 'waist_hip', 'waist_height', 'body_fat']],
            'statement' => 'BMI is een maat voor groepen, niet voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een andere maat, zoals je buikomvang.',
            'population' => 'Volwassenen.',
            'limitations' => [
                'Een klinisch kader; Ownify meet geen buikomvang.',
            ],
        ],

        'activity.intensity.age' => [
            'source' => 'AGE-01', 'version' => 1, 'topic' => 'activity.intensity', 'metric' => 'hr_max',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['hr_max' => ['base' => 208, 'per_year' => 0.7]],
            'statement' => 'Je maximale hartslag is ongeveer 208 − 0,7 × je leeftijd. Ownify gebruikt je eigen hoogst gemeten hartslag als die hoger is.',
            'population' => 'Gezonde volwassenen, mannen en vrouwen.',
            'limitations' => [
                'Een formule voor groepen: de echte maximale hartslag van één persoon kan ervan afwijken.',
            ],
        ],

        'activity.intensity.bands' => [
            'source' => 'AGE-02', 'version' => 1, 'topic' => 'activity.intensity', 'metric' => 'hr_max_pct',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['moderate_pct' => [64, 76], 'vigorous_pct' => [77, 95]],
            'statement' => 'Matig intensief is vanaf ongeveer 64% van je maximale hartslag, zwaar vanaf 77%.',
            'population' => 'Gezonde volwassenen van alle leeftijden.',
            'limitations' => [
                'Het standpunt van een vereniging.',
                'De grenzen zijn een afspraak, geen gemeten omslagpunt.',
            ],
        ],

        'sleep.architecture.age' => [
            'source' => 'AGE-03', 'version' => 1, 'topic' => 'sleep.architecture', 'metric' => 'sleep_stages',
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['decrease_with_age' => ['total_sleep', 'efficiency', 'deep_pct', 'rem_pct'], 'after_60' => ['efficiency']],
            'statement' => 'Met de jaren nemen slaapefficiëntie en diepe slaap af; na je 60e daalt vooral de efficiëntie nog. Een lagere waarde dan op je 25e is op latere leeftijd gewoon.',
            'population' => 'Gezonde mensen van 5 tot 102 jaar.',
            'limitations' => [
                'Onderzoek uit 1960–2003, in een slaaplaboratorium.',
                'Weinig deelnemers van middelbare leeftijd.',
            ],
        ],

        'hrv.age' => [
            'source' => 'AGE-07', 'version' => 1, 'topic' => 'hrv', 'metric' => 'hrv',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['with_age' => 'lower', 'sex_difference_fades_from' => 55],
            'statement' => 'HRV is gemiddeld lager naarmate je ouder bent, en verschillen tussen mannen en vrouwen verdwijnen rond je 55e. Daarom vergelijkt Ownify je HRV alleen met je eigen waarden.',
            'population' => 'Gezonde volwassenen van 25 tot 74 jaar.',
            'limitations' => [
                'Korte metingen overdag, geen waarden uit de nacht.',
                'Eén Duitse groep.',
            ],
        ],

        'recovery.age' => [
            'source' => 'AGE-08', 'version' => 1, 'topic' => 'recovery', 'metric' => null,
            'age_min' => 18, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['older_more_damage' => false],
            'statement' => 'Oudere volwassenen kregen na inspanning niet meer spierschade dan jongere: hun spierfunctie na 24 en 72 uur was hetzelfde. Ownify gebruikt daarom bij elke leeftijd dezelfde hersteltijden.',
            'population' => 'Oudere en jongere volwassenen.',
            'limitations' => [
                'Inspanning in een laboratorium.',
                'Gaat niet over het herstel van de hartslag.',
            ],
        ],

        'nutrition.protein.older' => [
            'source' => 'AGE-04', 'version' => 1, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg',
            'age_min' => 65, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['g_per_kg_day' => [1.0, 1.2], 'active_g_per_kg_min' => 1.2, 'dutch_norm_g_per_kg' => 0.83],
            'statement' => 'Vanaf 65 jaar: minstens 1,0–1,2 gram eiwit per kilogram per dag, en 1,2 of meer als je actief bent. De Nederlandse norm blijft 0,83 gram per kilogram.',
            'population' => 'Ouderen vanaf 65 jaar, zonder ernstige nierziekte.',
            'limitations' => [
                'Advies van deskundigen, op basis van het bewijs van 2013.',
                'Niet bij ernstige nierziekte zonder dialyse.',
            ],
        ],

        'nutrition.protein.older.espen' => [
            'source' => 'AGE-05', 'version' => 1, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg',
            'age_min' => 65, 'age_max' => null, 'strength' => 'betrouwbaar',
            'finding' => ['g_per_kg_day' => [1.0, 1.2], 'ill_g_per_kg_day' => [1.2, 1.5]],
            'statement' => 'Een tweede groep deskundigen: gezonde ouderen minstens 1,0–1,2 gram eiwit per kilogram per dag, en elke dag bewegen of sporten zolang het kan.',
            'population' => 'Ouderen.',
            'limitations' => [
                'Advies van deskundigen, zonder precieze leeftijdsgrens voor ouderen.',
            ],
        ],

        'nutrition.protein.meal.age' => [
            'source' => 'AGE-06', 'version' => 1, 'topic' => 'nutrition.protein', 'metric' => 'protein_g_per_kg_meal',
            'age_min' => 18, 'age_max' => null, 'strength' => 'beperkt',
            'finding' => ['bands' => [
                [18, 39, ['per_meal_g_per_kg' => 0.24]],
                [65, null, ['per_meal_g_per_kg' => 0.40]],
            ]],
            'statement' => 'Per maaltijd vlakte de spieropbouw af bij ongeveer 0,24 gram eiwit per kilogram bij jonge mannen en bij ongeveer 0,40 gram per kilogram bij oudere mannen.',
            'population' => 'Gezonde mannen van rond de 22 en rond de 71 jaar.',
            'limitations' => [
                'Alleen mannen, en niets over 40 tot 64 jaar.',
                'Porties eiwit in een laboratorium.',
            ],
        ],
    ],
];

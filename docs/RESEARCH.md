# Research: the approved sources behind Ownify's insights

Ownify says nothing about health that an approved source does not say. This
is the registry those sources live in, how a source becomes a claim Ownify
may use, how strong each claim is labelled, and how people read the source
itself — "Uit onderzoek blijkt" — on the website and in the app. What Ownify
does with the claims is in [INSIGHTS.md](INSIGHTS.md).

## The rule

**Every source was approved by the owner, one by one, before it was used**
(27 October 2026: the seventeen below; 28 October 2026: forty more, for the
combined insights and the advice by age in 31.0). A source that is not in the registry,
or not `approved`, supports nothing: no insight, no reference value, no label.
Adding one is a change to `config/research.php` that the owner approves first.
Never invent a source, a finding, a link or a number; a claim's numbers are
the source's own.

## Where it lives

| What | Where |
| --- | --- |
| The reviewed record — sources and claims, in Dutch | `config/research.php` |
| The English of every Dutch text in it | `lang/en/research.php` |
| What the engine reads at runtime | `research_sources`, `research_claims` (migration 028; 029 brings the 31.0 rows) |
| Ownify's own copies of CC BY PDFs, with their attribution | `assets/research/` (its `README.md`) |
| Reading, checking, hashing, the detail | `includes/research.php` |
| Keeping the four in step | `tools/research-registry.php` |
| The detail page | `pages/research-detail.php`, `assets/js/research.js`; Android `ui/screens/research/` |

The file is what was reviewed; the database is what runs. Every row carries
`content_hash`, the sha256 of its reviewed record, and the check compares
every column, so a row edited by hand or stored in the wrong character set
shows up.

## Sources

One row per publication (`research_sources`), keyed by a stable code
(`SLP-01`). It holds the publication exactly as published — title (in its own
`language`, `nl` or `en`), authors or organisation, date, citation, the
address of the publication, DOI/PMID/PMCID where it has them — what it found
(`findings`, in Dutch), when it was approved and last checked, and its PDF:

| `pdf.mode` | Means | On the website | In the app |
| --- | --- | --- | --- |
| `hosted` | CC BY: Ownify serves an unmodified copy with attribution (`assets/research/<code>.pdf`) | read in a frame inside the page where the browser can show a PDF (`navigator.pdfViewerEnabled`), otherwise opened by the browser; **Download de pdf** | read in the app (below); **Download de pdf** to the phone's downloads |
| `official_pdf` | a free official PDF the device fetches from the publisher itself | in a frame where the publisher allows it (`embed`), otherwise opened by the browser | read in the app |
| `link` | no PDF Ownify may show: no reuse licence, NC terms, or bot protection | **Bekijk de publicatie** at the publisher | the same |

Only a licence that explicitly allows it is hosted: CC BY. NC, ND and "all
rights reserved" are not copied, however freely they can be read. Nothing is
proxied through Ownify: a browser or phone fetches the publisher's file
itself.

A withdrawn source (`status = withdrawn`) stays in the table for the stored
insights that used it, and supports nothing new.

### The seventeen approved sources

| Code | Publication | Kind | Language | PDF |
| --- | --- | --- | --- | --- |
| SLP-01 | CDC, *About Sleep* (2024) | official health information | en | link (web page) |
| SLP-02 | Watson et al., AASM & SRS consensus, *Recommended Amount of Sleep for a Healthy Adult* (2015) | consensus | en | link |
| SLP-03 | Hirshkowitz et al., National Sleep Foundation sleep duration recommendations (2015) | consensus | en | link |
| SLP-04 | Paruthi et al., AASM, *Recommended Amount of Sleep for Pediatric Populations* (2016) | consensus | en | link |
| SLP-05 | Sletten et al., NSF, *The importance of sleep regularity* (2023) | consensus | en | link |
| SLP-06 | Chinoy et al., seven consumer sleep trackers against polysomnography (2021) | validation study | en | link (CC BY-NC) |
| ACT-01 | WHO, *Guidelines on physical activity and sedentary behaviour* (2020) | guideline | en | official PDF (CC BY-NC-SA, not hosted; not framed) |
| ACT-02 | Gezondheidsraad, *Beweegrichtlijnen 2017* | guideline | nl | official PDF |
| ACT-03 | Ding et al., daily steps dose–response meta-analysis (2025) | systematic review | en | link (CC BY-NC) |
| ACT-04 | Paluch et al., Steps for Health Collaborative (2022) | meta-analysis | en | link (CC BY-NC-ND) |
| MEA-01 | Doherty et al., *Keeping Pace with Wearables* (2024) | umbrella review | en | **hosted** (CC BY 4.0) |
| NUT-01 | Gezondheidsraad, *Voedingsnormen voor vetten, vetzuren, verteerbare koolhydraten en voedingsvezels* (2026/14) | reference values | nl | official PDF |
| NUT-02 | Gezondheidsraad, *Voedingsnormen voor eiwitten* (2021/10) | reference values | nl | official PDF |
| NUT-03 | Gezondheidsraad, *Richtlijnen goede voeding 2015* | guideline | nl | official PDF |
| NUT-04 | Frankenfield et al., predictive equations for resting metabolic rate (2005) | systematic review | en | link (subscription) |
| NUT-05 | Jäger et al., ISSN position stand: protein and exercise (2017) | position stand | en | **hosted** (CC BY 4.0) |
| BOD-01 | WHO, *Obesity and overweight* fact sheet | official fact sheet | en | link (web page) |

### The forty added for 31.0

For the combined insights ([CORRELATIONS.md](CORRELATIONS.md)) — exercise
and sleep (XSA), sleep and performance (XSP), sleep and food (XSN), training
and the heart (XTH), nutrient timing (XNT), training load (TRN), the method
(MTH) — and for advice by age (AGE). None may be hosted or framed: each opens
at its publisher (`link`).

| Code | Publication | Kind | Language | PDF |
| --- | --- | --- | --- | --- |
| XSA-01 | Kredlow MA et al., *The effects of physical activity on sleep: a meta-analytic review* (2015) | systematic review | en | link |
| XSA-02 | Kline CE et al., *Physical activity and sleep: An updated umbrella review of the 2018 Physical Activity Guidelines Advisory Committee report* (2021) | umbrella review | en | link |
| XSA-03 | Atoui S et al., *Daily associations between sleep and physical activity: A systematic review and meta-analysis* (2021) | systematic review | en | link |
| XSA-04 | Stutz J et al., *Effects of Evening Exercise on Sleep in Healthy Participants: A Systematic Review and Meta-Analysis* (2019) | systematic review | en | link |
| XSA-05 | Frimpong E et al., *The effects of evening high-intensity exercise on sleep in healthy adults: A systematic review and meta-analysis* (2021) | systematic review | en | link |
| XSA-06 | Leota J et al., *Dose-response relationship between evening exercise and sleep* (2025) | observational study | en | link |
| XSA-08 | Kovacevic A et al., *The effect of resistance exercise on sleep: A systematic review of randomized controlled trials* (2018) | systematic review | en | link |
| XSP-01 | Craven J et al., *Effects of Acute Sleep Loss on Physical Performance: A Systematic and Meta-Analytical Review* (2022) | systematic review | en | link |
| XSP-03 | Walsh NP et al., *Sleep and the athlete: narrative review and 2021 expert consensus recommendations* (2021) | consensus | en | link |
| XSN-01 | Al Khatib HK et al., *The effects of partial sleep deprivation on energy balance: a systematic review and meta-analysis* (2017) | systematic review | en | link |
| XSN-02 | Fenton S et al., *The influence of sleep health on dietary intake: a systematic review and meta-analysis of intervention studies* (2021) | systematic review | en | link |
| XSN-05 | Gardiner C et al., *The effect of caffeine on subsequent sleep: A systematic review and meta-analysis* (2023) | systematic review | en | link |
| XTH-01 | Stanley J et al., *Cardiac parasympathetic reactivation following exercise: implications for training prescription* (2013) | review | en | link |
| XTH-02 | Bellenger CR et al., *Monitoring Athletic Training Status Through Autonomic Heart Rate Regulation: A Systematic Review and Meta-Analysis* (2016) | systematic review | en | link |
| XTH-03 | Plews DJ et al., *Training adaptation and heart rate variability in elite endurance athletes: opening the door to effective monitoring* (2013) | review | en | link |
| XTH-04 | Buchheit M, *Monitoring training status with HR measures: do all roads lead to Rome?* (2014) | review | en | link |
| XTH-05 | Altini M & Plews D, *What Is behind Changes in Resting Heart Rate and Heart Rate Variability? A Large-Scale Analysis of Longitudinal Measurements Acquired in Free-Living* (2021) | observational study | en | link |
| XNT-01 | Kerksick CM et al., *International society of sports nutrition position stand: nutrient timing* (2017) | position stand | en | link |
| TRN-01 | Impellizzeri FM et al., *Acute:Chronic Workload Ratio: Conceptual Issues and Fundamental Pitfalls* (2020) | methods | en | link |
| TRN-02 | Kellmann M et al., *Recovery and Performance in Sport: Consensus Statement* (2018) | consensus | en | link |
| BOD-02 | Rubino F et al., *Definition and diagnostic criteria of clinical obesity* (2025) | consensus | en | link |
| BOD-03 | Turicchi J et al., *Weekly, seasonal and holiday body weight fluctuation patterns among individuals engaged in a European multi-centre behavioural weight loss maintenance intervention* (2020) | observational study | en | link |
| ACT-05 | Bull FC et al., *World Health Organization 2020 guidelines on physical activity and sedentary behaviour* (2020) | guideline | en | link |
| ACT-07 | RIVM, VZinfo.nl, *Bewegen: Verantwoording, definities* (2026) | definitions | nl | link |
| NUT-07 | Gezondheidsraad, *Richtlijnen goede voeding: eiwitbronnen en voedingspatronen 2025* (2025) | guideline | nl | link |
| NUT-08 | Voedingscentrum, *Vocht* (2026) | official health information | nl | link |
| HRT-01 | Zhang D et al., *Resting heart rate and all-cause and cardiovascular mortality in the general population: a meta-analysis* (2016) | meta-analysis | en | link |
| HRT-02 | Shaffer F & Ginsberg JP, *An Overview of Heart Rate Variability Metrics and Norms* (2017) | review | en | link |
| MEA-02 | Stone JD et al., *Assessing the Accuracy of Popular Commercial Technologies That Measure Resting Heart Rate and Heart Rate Variability* (2021) | validation study | en | link |
| MTH-01 | Benjamini Y & Hochberg Y, *Controlling the false discovery rate: a practical and powerful approach to multiple testing* (1995) | methods | en | link |
| MTH-02 | Daza EJ, *Causal Analysis of Self-tracked Time Series Data Using a Counterfactual Framework for N-of-1 Trials* (2018) | methods | en | link |
| MTH-03 | McDonald S et al., *The state of the art and future opportunities for using longitudinal n-of-1 methods in health behaviour research: a systematic literature overview* (2017) | methods | en | link |
| AGE-01 | Tanaka H et al., *Age-predicted maximal heart rate revisited* (2001) | systematic review | en | link |
| AGE-02 | Garber CE et al., *American College of Sports Medicine position stand. Quantity and quality of exercise for developing and maintaining cardiorespiratory, musculoskeletal, and neuromotor fitness in apparently healthy adults: guidance for prescribing exercise* (2011) | position stand | en | link |
| AGE-03 | Ohayon MM et al., *Meta-analysis of quantitative sleep parameters from childhood to old age in healthy individuals: developing normative sleep values across the human lifespan* (2004) | systematic review | en | link |
| AGE-04 | Bauer J et al., *Evidence-based recommendations for optimal dietary protein intake in older people: a position paper from the PROT-AGE Study Group* (2013) | consensus | en | link |
| AGE-05 | Deutz NE et al., *Protein intake and exercise for optimal muscle function with aging: recommendations from the ESPEN Expert Group* (2014) | consensus | en | link |
| AGE-06 | Moore DR et al., *Protein ingestion to stimulate myofibrillar protein synthesis requires greater relative protein intakes in healthy older versus younger men* (2015) | pooled analysis | en | link |
| AGE-07 | Voss A et al., *Short-term heart rate variability—influence of gender and age in healthy subjects* (2015) | observational study | en | link |
| AGE-08 | Fernandes JFT et al., *Advancing Age Is Not Associated With Greater Exercise-Induced Muscle Damage: A Systematic Review, Meta-Analysis, and Meta-Regression* (2025) | systematic review | en | link |

## Claims

What Ownify says with a source is a claim (`research_claims`): a stable key
(`sleep.duration.adults`), a version, its source, the topic and metric it is
about, the ages it holds for (in the source's own boundaries), its numbers
(`finding_json`, machine-readable, with units), the sentence Ownify shows
(`statement`), for whom (`population`) and its limitations — and how strong
the evidence for this claim is.

A claim is never edited in place once it is used. Changing its meaning, its
numbers or its text means a new version; the old one is `retired` and stays,
because every stored insight points at the exact claim version it was
written with (`user_insights.claim_id`).

| Claim | Source | Label | Used by |
| --- | --- | --- | --- |
| `sleep.duration.youth` (6–18) | SLP-04 | Betrouwbaar | Slaapduur |
| `sleep.duration.adults` (18–60) | SLP-02 | Betrouwbaar | Slaapduur; the Scorekompas |
| `sleep.duration.cdc` (6+) | SLP-01 | Betrouwbaar | Slaapduur, 61–64 |
| `sleep.duration.nsf` (14+) | SLP-03 | Betrouwbaar | Slaapduur, 65+ |
| `sleep.regularity` | SLP-05 | Betrouwbaar | Slaapritme; the Scorekompas |
| `sleep.tracking` | SLP-06 | Beperkt onderbouwd | beside Slaap's stages; the Scorekompas |
| `activity.gr.youth` (4–17), `activity.gr.adults` (18+) | ACT-02 | Zeer betrouwbaar | Beweging, Spierversterkend bewegen; the Scorekompas |
| `activity.who.some` (5+) | ACT-01 | Zeer betrouwbaar | Beweging's suggestion ("some is better than none") |
| `activity.who.adults`, `activity.who.older` | ACT-01 | Zeer betrouwbaar | approved, not used yet |
| `activity.steps.ding`, `activity.steps.paluch` (18+) | ACT-03, ACT-04 | Betrouwbaar | Stappen |
| `measurement.wearables` | MEA-01 | Betrouwbaar | beside Training's heart rate |
| `nutrition.fat` (4+) | NUT-01 | Zeer betrouwbaar | Vet; beside Voeding's fat row |
| `nutrition.saturated_fat` | NUT-01 | Zeer betrouwbaar | Verzadigd vet |
| `nutrition.carbohydrates` | NUT-01 | Zeer betrouwbaar | beside Voeding's carbohydrate row |
| `nutrition.fibre` (2+) | NUT-01 | Zeer betrouwbaar | Vezels |
| `nutrition.free_sugars` | NUT-01 | Zeer betrouwbaar | approved, not used yet |
| `nutrition.protein` (18+) | NUT-02 | Zeer betrouwbaar | Eiwit |
| `nutrition.protein.exercise` (18+) | NUT-05 | Beperkt onderbouwd | approved, not used yet |
| `nutrition.salt` (18+) | NUT-03 | Zeer betrouwbaar | Zout |
| `nutrition.pattern` | NUT-03 | Zeer betrouwbaar | beside Voeding's cijfer; the Scorekompas |
| `nutrition.energy_estimate` (18+) | NUT-04 | Betrouwbaar | approved, not placed (see below) |
| `body.bmi` (18+) | BOD-01 | Zeer betrouwbaar | BMI, in Instellingen |

A claim with an age bound is used only when the person's age is known and
inside it. Without a birth date, a rule whose claim is age-bound says nothing.

**Not placed.** `nutrition.energy_estimate` (NUT-04) belongs beside
Voeding's energy target, which shows on the Energie chart's own page — a
page over Voeding. A research detail opens one level over the page that
shows it, so it cannot open from there; rather than a chip that does
nothing, it is not shown. The other unused claims wait for a place where
they are relevant.

## The label

Three words, never a percentage, never a probability:

| Label | Means (`config/research.php` `strengths`) |
| --- | --- |
| **Zeer betrouwbaar** | an official guideline or norm, based on systematically reviewed research, that fits what Ownify measures |
| **Betrouwbaar** | a consensus of experts or a meta-analysis of mostly observational research, or a strong guideline Ownify can only apply approximately |
| **Beperkt onderbouwd** | one study, a position of an association, or a measurement that fits only partly what the research measured |

A claim's label is the evidence for the claim itself. Where Ownify can only
apply it to an approximation of what the source measured, the label shown is
lower, and the detail says why:

| Applicability | Ownify's data | Steps lower | Example |
| --- | --- | --- | --- |
| `direct` | the same thing | 0 | measured sleep, counted steps |
| `proxy` | an approximation | 1 | recorded workouts for all activity, logged food for what was eaten |
| `weak` | a loose fit | 2 | logged sodium for all salt, a BMI for one person |

Below Beperkt onderbouwd there is no insight at all.

## "Uit onderzoek blijkt"

The evidence beside an insight or a reference value is "Bekijk onderzoek" as
the goal wizard's glass chip (`.glass--chip`; Android `GlassChip`). It opens
the claim's detail, titled "Uit onderzoek blijkt" — the one place the label
(Zeer betrouwbaar, …) is shown, since 29.1 — over whatever page showed
it (`data-detail-parent="*"`; Android `Detail.Research(id, over)`), one
level deep; back returns there.

The detail (`pages/research-detail.php`, Android `ResearchDetailPage`) is
built from Instellingen' own pieces — page intro, eyebrows, the settings
card's rows, notes, `.btn` — so it is no new kind of page:

1. **Uit onderzoek blijkt** and the claim as Ownify uses it
2. **Bewijskracht** — the label as a status (the same chip, never pressed),
   what it means, why it is lower here when it is, and that it is no chance
   or percentage
3. **Wat het onderzoek vond** — the source's findings
4. **Voor wie** — the population
5. **Beperkingen**
6. **Bron** — the title as published (marked with its own language for screen
   readers, `lang`; with "De publicatie is in het Engels/Nederlands." when that
   is not the app's), authors or organisation and year, citation, kind of
   research, when it was last checked; then **Lees de pdf** / **Download de
   pdf** / **Bekijk de publicatie**, what the PDF is (its licence), and the
   offline line.

A detail's id is the claim version and the steps its label stands lower,
`c12-1`: digits, so it is never read as a word in either language.

### The PDF

- **Website** (`assets/js/research.js`): **Lees de pdf** is a plain link the
  browser can always follow. Where the browser can show a PDF inside a page
  and the publisher allows it, the link opens a frame in the detail instead
  (in a Well, 78% of the screen tall, at most 900 px), loaded only then.
  Elsewhere — Chrome on Android, a publisher that forbids framing — it is
  **Open de pdf** and the browser opens it. Offline, nothing loads and the
  detail says why.
- **App** (`ResearchPdf.kt`): **Lees de pdf** fetches the file once into the
  app's cache (`cache/research/`, up to 40 MB, only a file that starts with
  `%PDF-`; a publisher's error page in its place is refused) and draws it with
  Android's `PdfRenderer` in the same Well — pages on their paper, scrolled
  inside it; two fingers zoom up to three times, a double tap goes between
  whole and twice, which page you are on under it. Offline it says so; a file
  that will not open says so, and the publication is a tap away. **Download
  de pdf** (Ownify's own copies) uses the phone's downloads (Android 10 and
  later; on 9 the browser takes it).

## Changing the registry

1. The owner approves the source (or the change).
2. Edit `config/research.php`. A claim whose meaning, numbers or text change
   gets a higher `version`.
3. Add the English for every new Dutch text to `lang/en/research.php`, then
   `php tools/i18n-build.php` (and `--check`).
4. `php tools/research-registry.php --write` puts the seed into migrations
   028 and 029 and `database/schema.sql`.
5. `php tools/research-registry.php --check` — the file, the English, both
   seeds and (with `DB_NAME`) the database agree, column by column.
6. On a running server: `php tools/research-registry.php --sync`, in one
   transaction — sources added or updated (withdrawn when no longer in the
   file), claim versions added, other versions retired; a claim changed
   without a higher version is refused. Importing migration 029 does the
   same for 31.0's registry (its seed, then older claim versions retired), so
   that needs no `--sync`.

`tools/migration-028-test.php` and `tools/migration-029-test.php` build a
throwaway database, apply the migration and its rollback, and check the
registry against it.

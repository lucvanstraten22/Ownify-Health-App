# Combined insights: how your sleep, training, food and heart go together

31.0. Ownify looks for patterns **across** areas in the person's own data —
training and the night after it, a short night and the next day's eating,
a hard session and the night's HRV — and says what it found in plain words,
with the numbers and the approved research behind it. No AI: statistics in
application code, Ownify's own sentences in Dutch and English. Always a
pattern in the person's own data, never a cause, never a diagnosis.

The single-area insights (Slaap, Voeding, Training: [INSIGHTS.md](INSIGHTS.md))
and the research registry ([RESEARCH.md](RESEARCH.md)) are unchanged in
kind; this page is what 31.0 adds.

## Where it shows (both apps)

| Where | What |
| --- | --- |
| Overzicht › **Nuttige inzichten** | four combined insights (2×2 on a desktop), each with its label; the card's head opens Inzichten. A link still being gathered fills an empty place as a quiet row ("Nog 2 gemeten nachten nodig.") |
| **Inzichten** (`pages/insights.php`, Android `InsightsPage`) | all 17 links, grouped by label, strongest first; each row opens its own page. **Mogelijk patroon** is only shown here, with how likely chance is |
| An insight's page (`pages/insight-detail.php`, `InsightDetailPage`) | Wat Ownify zag (the label, what it means, "a pattern, not a cause", Chart A), In cijfers, Uit onderzoek blijkt (the research at the person's age, each with "Bekijk onderzoek", or that it is their own data only), Hoe Ownify dit berekent (the method and its three sources) |
| Slaap, Voeding, Training | their own single-area insight, as before (R5) |
| Overzicht › Eén kleine suggestie | unchanged (R5) |

Built from pieces that were there: the insights card and its rows,
Instellingen' page intro, blocks, cards, rows, notes and glass chip, and the
Scorekompas's rows with their meter. No new kind of page.

Detail pages stack: Inzichten opens over Overzicht, an insight's page over
Inzichten, and the research it shows over that page. Back goes one page at a
time; a tab closes them all (`assets/js/detail-layer.js` `parents`,
`ShellState.beneath`).

Until migration 029 is imported nothing here runs and Overzicht keeps the
30.1 card (`corr_installed()`).

## The 17 links

`config/correlations.php` `pairs`. *Lag*: 1 = the day after (a training day
and the night that follows), 0 = the same day, `week` = per ISO week.
*Split*: how the days are put in two groups for Chart A and the difference —
the person's own median, or yes/no.

| Code | Title | What with what | Lag | Research (sources) |
| --- | --- | --- | --- | --- |
| C01 | Training en slaap | training load → sleep duration | 1 | XSA-01, -02, -03, -08 |
| C02 | Late training en inslapen | training ending < 4 h before the usual onset → onset vs. usual | 1 | XSA-04, -05, -06 |
| C03 | Zware training en HRV | a hard session → the night's HRV (ln) | 1 | XTH-05, XSA-06, XTH-02, AGE-07, HRT-02, MEA-02, AGE-02 |
| C04 | Zware training en hartslag in slaap | a hard session → the night's heart rate | 1 | XTH-05, XSA-06, HRT-01, MEA-02, AGE-02 |
| C05 | Herstel na zware training | a hard session → HRV two nights later | 2 | XTH-01, AGE-08, TRN-02, XTH-03, XTH-04 |
| C06 | Stappen en slaap | steps → sleep duration | 1 | XSA-03, XSA-02 |
| C07 | Slaap en stappen | sleep duration → the day's steps | 0 | XSA-03 |
| C08 | Korte nachten en eten | a short night (≤ 5:30 or the person's shortest quarter) → the day's energy | 0 | XSN-01, XSN-02 |
| C09 | Slaap en trainen | sleep duration → trained that day | 0 | XSP-01, XSP-03 |
| C10 | Voedingscijfer en slaap | Voeding's cijfer → sleep duration | 1 | own data only |
| C11 | Laat eten en inslapen | the last meal's time → onset vs. usual | 1 | own data only |
| C12 | Eiwit op trainingsdagen | trained → protein per kg | 0 | NUT-05 (18–64), AGE-04 / AGE-05 (65+), NUT-02, XNT-01 |
| C13 | Energie op trainingsdagen | trained → the day's energy | 0 | own data only |
| C14 | Water op trainingsdagen | trained → water | 0 | NUT-08 (13+) |
| C15 | Weekend en inslapen | Friday/Saturday evening → onset clock time | 0 | SLP-05 |
| C16 | Trainingsweken en slaap | the week's load → the week's sleep (8 weeks at least) | week | own data only |
| C17 | Cafeïne en slaap | caffeine < 8.8 h before the usual onset → sleep duration | 1 | XSN-05 |

A claim with an age bound is used only inside it; without a known age only
the claims without one. *Direction* (+1, −1, 0) is what the research
expects; when a pattern goes the same way, the page says "Dit komt overeen
met wat onderzoek gemiddeld vindt", and the other way, that it differs —
the person's own data is not wrong for that.

## The day table

`includes/correlations/frame.php`: one value per variable per local day, over
the 90 days that end yesterday, read through the counting Ownify already
has — `health_nights_on()`, `health_workouts_counted()`,
`health_metric_totals()`, the complete-day rule for food — so a night, a
workout or a day's steps mean the same here as everywhere else. **A day
without a measurement is absent, never zero.**

- **Nights** belong to the date they ended. Duration; onset as minutes after
  noon of the evening (`onset_clock`) and against the person's usual onset
  for that weekday type (`onset_dev`); the night's heart rate from sleeping
  heart rate only; HRV as the ln of the mean RMSSD in the night window.
- **Training**, only on days a source synced: load (effort-weighted
  minutes), trained yes/no, a hard session, training ending late.
- **Food**, complete days only (logged energy ≥ the share of the target in
  `config/insights.php` `data.complete_energy_share`): energy, protein per
  kg, water, Voeding's cijfer, the last meal's time. Caffeine only when it
  was logged on at least 5 days.
- **Weeks** (C16): load and sleep per ISO week.

## The statistics

`includes/correlations/stats.php`, pure functions, validated on simulated
self-tracking data before they were built:

1. per variable, remove the person's slow trend (linear and curved) and
   weekday pattern by least squares — weekends and slow drifts move many
   things at once and are the main source of chance patterns (C15, which
   *is* the weekend, keeps its weekdays);
2. cap outliers at 3.5 robust standard deviations;
3. pre-whiten the pair with the exposure's lag-1 dependence, on consecutive
   days only;
4. Spearman's rank correlation, with an effective number of days that counts
   the remaining day-to-day dependence and the terms removed in step 1, and
   a Fisher-z p-value;
5. Benjamini–Hochberg over every test in the person's run (q);
6. the same direction in both halves of the window (stable);
7. the difference between the two day groups (Hodges–Lehmann; a mean for
   trained yes/no) with a 90% interval from a moving-block bootstrap (7-day
   blocks, 400 draws, seeded per person and link so a run repeats exactly).

Whether a difference matters is judged in the outcome's own units
(`outcomes`: 15 minutes of sleep or onset, 2 bpm, 1,000 steps, 150 kcal,
0.2 g/kg, 0.25 l, 15 points of training days; for HRV half the person's own
day-to-day spread in ln units).

## The labels

| Label | When |
| --- | --- |
| **Duidelijk patroon** | q ≤ 0.05, \|ρ\| ≥ 0.3, a difference that matters, the same sign as ρ, stable, ≥ 45 days and ≥ 12 in the smaller group; never for a weekly link |
| **Patroon** | q ≤ 0.10, \|ρ\| ≥ 0.2, matters, same sign, stable, ≥ 30 days, ≥ 8 per group |
| **Mogelijk patroon** | p < 0.10, \|ρ\| ≥ 0.2, matters, same sign — only on Inzichten, with the chance |
| **Geen patroon** | ≥ 30 days and the whole 90% interval within what matters |
| **Nog onduidelijk** | everything else: too few days, or no clear picture either way |

What is still needed is counted against **Patroon**'s minimum (30 paired
days, 8 per group; for a weekly link 8 weeks and 3 per group): "Nog 2
gemeten nachten nodig."

Simulated, 60 people without any real link: 3% saw any pattern, 0.2% of
tests gave a false one; weekend and trend confounders 0 of 30; a real null
with enough days was Geen patroon 20 of 20.

## Overzicht's four

`corr_overview()`: first Duidelijk patroon and Patroon, ordered by label,
then whether research exists for the person's age, then |ρ| — at most one
per outcome. Places left are filled with links still being gathered (that
have data and could be shown), the closest to enough first. Mogelijk
patroon, Geen patroon and links without any data never go on Overzicht.

## Chart A and the numbers

Chart A (round-1 decision D2) is the two day groups as two of the
Scorekompas's rows — the group, its days, its value — each with the meter
from zero in the page's accent, the longer one at 1/1.1 of the width. Values
are stored raw and shown in the person's units at read time
(`kind`: duration, clock, ms, bpm, steps, kcal, share, g_per_kg, litres).
In cijfers: the difference and its 90% interval, ρ (with a real minus sign),
the chance and the corrected chance, the days per group, the period.

Every sentence is rendered when it is read (`includes/correlations/render.php`),
so language and units always follow the person's settings.

## Once a day, stored

`includes/correlations/store.php`, with the day's insights
([INSIGHTS.md](INSIGHTS.md)):

- worked out in the same request as the day's insights (first load, a
  reassessment after a sync, a new engine version), or by the first load of
  a day without a run (claimed with `INSERT IGNORE` on user + date);
- every other load reads the newest finished run — also while today's is
  being worked out, so nothing flickers; a `running` row older than two
  minutes is taken over;
- `engine_version` in `config/correlations.php` is raised when a link or
  text changes meaning;
- runs older than 35 days are deleted. A run takes about 50 ms.

## Caffeine (R7)

Caffeine is read from Health Connect's nutrition records (Android: the
`caffeine` field, sent in grams; the server stores mg, `caffeine` in
`health_metric_types`). The first sync after 31.0 also reads the meals of
the 30 days before the usual week, once (`OwnifySyncStatus.mealHistorySent`),
so C17 does not have to wait a month; a sync that fails, or before meals may
be read, tries again next time.

## Data and privacy

Migration 029 ([DATABASE.md](DATABASE.md)): `correlation_runs` (one per
person per day) and `user_correlations` (one row per link per run: the
label, days, ρ, p, q, the difference and the rest as `detail_json`). Both
hang off `users` with `ON DELETE CASCADE`. 029 also carries the full research
registry seed (57 sources, 58 claims) and retires older claim versions, so
`tools/research-registry.php --sync` is not needed. Rollback:
`029-rollback.sql` drops both tables and the caffeine type when nothing uses
it; the research stays.

Only the signed-in person's own data is read or shown; nothing is shared
with Community.

## Tests

- `tools/correlations-test.php` — a difference that is there is found;
  false alarms on simulated people without links; not enough yet and no
  difference; the same numbers every time; Overzicht's four; the day table;
  the lifecycle (once a day, concurrency, a dead run, pruning); the pages;
  the research by age (34 and 71); English; deleting an account; a server
  without migration 029.
- `tools/migration-029-test.php` — 029 and its rollback on the 30.1 schema.
- `tools/insights-test.php` — Overzicht's combined card, and that no AI
  service is ever asked, this code included.
- Android: `ui/CorrelationsFlowTest` (the stored state, Overzicht →
  Inzichten → an insight's page → its research and back, a tab closing the
  stack, own-data-only links, a state without them),
  `connection/IngestPayloadTest` and `OwnifyBackgroundSyncTest` (caffeine
  and the one-time 30 days of meals).

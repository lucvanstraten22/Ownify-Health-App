# Lens 4 — Charts and data visualizations

Reviewer 4 of 9. Read-only. Skills loaded: `dataviz` (form, marks, interaction,
anti-patterns, palette validator run with `node scripts/validate_palette.js`) and
`anthropic-skills:apple-design`. Binding standard: `docs/CHARTS.md` +
`lib/time-axis.php`; `docs/THEME.md` overrides dataviz's palette. The review was
stopped early on the coordinator's instruction (usage); what was not reached is
listed at the end.

`$A` = `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/audit`

## Summary

1. **Date labels collide at the edges.** The edge shift moves an end date inwards, and nothing then stops it running into the date next to it. You can see this on 1 jaar ("novdec", "oktnov"; gaps between month labels as small as 1 px), on 90 dagen ("11 18"), and on the Android heart-rate day ("00:0003:00").
2. **Web and Android disagree on the heart-rate day's hours.** At the same phone width the web shows a time every 6 hours and Android every 3 hours. On Android the labels overlap. CHARTS.md says every 3 hours, which matches neither the web code nor what fits.
3. **The line key in a small card's header reads as a minus sign or a range.** "— 93" and "– 730 kcal" read as negative numbers, and "■ 9.956 – 7,5 km" reads as a range from 9.956 to 7,5.
4. **The reading says "Vandaag"** on Gezondheid's Verloop and on the Scorekompas. The area charts say "11 november". On Android the reading's glass also sits over the period switch, and the switch's text shows through it.
5. **Markers follow 4 policies, in 3 dot sizes (8 / 7 / 5 px), with 3 different horizontal insets** (0 %, 3 %, 0.3 of a slot) for the same 7-day axis. Overlapping dots hide each other: Voeding 83 sits under Training 84.
6. **An empty small chart shows a different week from the card beside it.** It shows the week starting today (Wo–Di, mostly in the future) while its neighbour shows the past week (Do–Wo).
7. **A goal that ended in April is drawn over a year**, apr to apr, with 11 empty months.
8. **Two series in one chart are drawn on two hidden scales** ("Stappen + Afstand", "Tijd in bed + Regelmaat"). This is the dual-axis anti-pattern. The steps line simply traces the bar tops.
9. **The palette checks fail.** Voeding ↔ Training: ΔE 5.3 under protan colour blindness, below the floor. In Dark Mode the Slaap and Voeding lines are 2.7–2.8:1 against the card, and the mixed-chart bars are 1.8–2.3:1.
10. **The test data starves the score charts.** `daily_scores` has 2 days, and 113 of 114 nights have no time in bed. Every score history therefore shows a single dot, and the full-history, 30-day and 1-year shapes of the score charts could not be judged.

## Inventory (what was looked at)

| Chart | Type | Web | Android | Evidence |
|---|---|---|---|---|
| Gezondheid Verloop (3 scores) | multi-line + dots, legend Well | `components/health-history.php` | `HistoryCard` / `HistoryPlot` (`ui/design/HistoryChart.kt`) | `web/phone-dark/page-health-0/1`, `android/dark/app-health-history-{1,7,30,90}`, `-reading`, `-reading-90` |
| Category Verloop (one score) | line + dots | `health-history.php` (`history_area`) | `HistoryCard(only)` | `web/phone-dark/detail-sleep-2/3`, `detail-training-3`, `android/dark/app-detail-sleep-3` |
| Scorekompas history | line + dots | `components/compass-history.php` | `PeriodChart` (`ScoreCompass.kt`) | `web/phone-dark/detail-score-compass-4`, `android/dark/app-compass-history-{1,7,30,90}` |
| Area charts, small 2×2 + large page | line / bars / mixed | `components/area-chart.php`, `lib/area-charts.php` | `AreaCharts.kt` | `detail-sleep-1/2`, `detail-sleep-{spo2,bed,sleeping_hr}-0`, `detail-training-1`, `android/dark/app-detail-sleep-2/3`, `app-sleep-chart-*`, `app-training-chart-steps` |
| Heart rate: day / period / session + zones | zone-coloured line, bands | `components/heart-plot.php`, `heart-chart.php`, `heart-zones.php` | `HeartPlotView` (`TrainingView.kt`) | `detail-training-2`, `android/dark/app-detail-training-3`, `app-training-session` |
| Goal Verloop | line + wash + target | `pages/goal-detail.php`, `lib/goal-chart.php`, `assets/js/goal-chart.js` | `GoalChart.kt` | `web/phone-dark/detail-goal-1-1` (Android shots show the empty Doelen page, no goal) |
| Slaapverloop "Vandaag" | stage timeline (hypnogram) | `components/sleep-timeline.php` | `SleepView.kt` | `detail-sleep-1`, `android/dark/app-detail-sleep-2` |

Capture caveat (VERIFIED): `cap.js` takes each shot 350 ms after a scroll, and `health-trend.js:47-76` (Android `HistoryChart.kt:188-192`) draws a line on over 900 ms. Lines that stop one segment short in the web shots — SpO₂'s Di→Wo, HRV, Rusthartslag, Verdiepingen, Actieve minuten — are therefore unfinished draw-ons, **not** gaps. The data has values on all seven days (SpO₂ 4–11 nov in `health_metrics`).

## Findings

### 4.1 End dates collide with their neighbours on 1 jaar, 90 dagen and the heart-rate day
- Status: VERIFIED (Android shots; web metrics for 1 jaar) / HYPOTHESIS for web 90 dagen (computed, no web shot) · Kind: defect
- Where:
  - Android Verloop 1 jaar: `android/dark/app-health-history-1.png` ("novdec … oktnov").
  - Android 90 dagen: `app-health-history-90.png`, `app-compass-history-90.png` ("11 18").
  - Web goal Verloop 1 jaar: `web/phone-dark/detail-goal-1-1.json`.
  - Android heart-rate day: `app-detail-training-3.png` ("00:0003:00").
  - Code: web `assets/js/chart-axis.js:33-51` (shifts an end tick but never checks its neighbour); Android `HistoryChart.kt:524-536` (`coerceIn`) and `GoalChart.kt` `XTicks`.
- Problem:
  - The 1-year axis has 13 labels about 17–19 px wide, spaced about 22–24 px apart.
  - Measured web gaps: apr–mei 2, sep–okt 2, nov–dec **1**, mrt–apr 2 px.
  - Both end labels are pushed inwards against their neighbours.
  - 90 dagen: the first date "11 / nov" is pushed right by half the width of "nov" and touches "18".
- Why it matters: "novdec" can't be read. These axes appear on every chart with a period switch.
- Proposal:
  - Give the Verloop and the Scorekompas the area charts' horizontal inset (`lib/area-charts.php:223-228`, 0.3 of a slot). Then the end dates can stand centred without being pushed into their neighbours.
  - In addition, on 1 jaar only, when two labels would come within 4 px, hide the second boundary label. It stays the same date at the same place, and the reading still gives it. This needs an exception to the CHARTS.md "13 boundaries" rule: **NEW CONVENTION**.
- Affects:
  - Verloop, category Verloop, Scorekompas, area charts (large), goal chart, on both apps.
  - Worst at 320 px (`web/small-dark` not checked).
- Complexity: M · Risk: axis tests (`tools/time-axis-test.php`), TokenParity of insets.
- Priority: P1

### 4.2 The heart-rate day's hours differ between the apps; Android's overlap
- Status: VERIFIED · Kind: defect (parity + overlap)
- Where:
  - Web `detail-training-2.png`: 00:00 · 06:00 · 12:00 · 18:00.
  - Android `app-detail-training-3.png/.json`: 00:00 … 21:00, eight labels, the first two touching.
  - Web `assets/css/training.css:245-252`: hides every second tick when the container is ≤ 480 px, i.e. always on a phone.
  - Android `TrainingView.kt:382-383`: hides them only below 380 dp.
  - `docs/CHARTS.md` § "A day of heart rate": "the hours every three".
- Problem: the same chart has a different axis on each app. At 412 dp, eight labels of "00:00" do not fit; the web comment (47.0) names exactly this overlap.
- Proposal:
  - Use one rule on both apps: thin by the plot's measured width, as the web does with its container query.
  - Update CHARTS.md to "every three hours where they fit, otherwise every six".
- Affects: heart-rate day and a session's heart rate (session ticks are irregular: 18:15 · 18:30 · 18:40 · 18:54, `app-training-session.json` — not checked further).
- Complexity: S · Risk: low.
- Priority: P1

### 4.3 The line key in a small card's header reads as a minus sign or a range
- Status: VERIFIED · Kind: usability defect
- Where:
  - `detail-sleep-1.png`: "Tijd in bed + Regelmaat — 93".
  - `detail-training-1.png`: "■ 9.956 – 7,5 km" and "– 730 kcal".
  - `android/dark/app-detail-sleep-2.png`: the same.
  - Code: `components/area-chart.php:56-62`, `assets/css/area-charts.css:206` (`.area-chart__key[data-tone="light"]` 10 × 2.5 px).
- Problem:
  - The line key is a horizontal dash at text height, placed directly before a number.
  - Between two values it reads as a range ("9.956 to 7,5 km"). Alone, it reads as a negative value ("−93").
  - The key carries no name. In the Slaap shot, the 93 also equals the Slaap score.
- Why it matters: a key that changes the meaning of the number next to it.
- Proposal:
  - Option A: in the small card's head, use the same shape as the legend and the mark: a dot for a line series, as in `.legend__dot`.
  - Option B: drop the key when only one series has a value, and name it ("Regelmaat 93").
  - Either way, keep the key's shape the same in the head, the legend and the reading. Today a line series has a dash in the head, a dot in the legend and a stroke in the reading.
  - Needs approval if the shape changes.
- Affects: every mixed small chart (Slaap 1, Training 2, Voeding Energie / Water if mixed) on both apps.
- Complexity: S · Risk: low.
- Priority: P1

### 4.4 The reading says "Vandaag", and on Android its glass covers the period switch
- Status: VERIFIED (Android shot + code); web wording follows from the same server data · Kind: defect vs CHARTS.md / usability
- Where:
  - `android/dark/app-health-history-reading.png`.
  - The label comes from the Scorekompas's day list: `lib/hydrate-compass.php:354` (`'label' => $day['label']`) and `:386` (a week of one day).
  - The area charts use the full date instead: `lib/area-charts.php:239-241` (`score_compass_date_in`).
  - The reading is placed above the plot: `goals.css` `.goal-chart__tip` `bottom: calc(100% + 2px)`, and Android `HistoryChart.kt:474-500`.
- Problem:
  - CHARTS.md says "the reading carries the full date" and "never Vandaag". The Verloop and the Scorekompas say "Vandaag" (and presumably "Gisteren"); Training's and Slaap's charts say "11 november".
  - On Android the tip sits over "7 dagen | 30 dagen" and "Je geschiedenis begint…". The switch's text shows through the glass and mixes with "Vandaag".
- Proposal:
  - Use the full date in the reading of the Verloop and the Scorekompas, as the area charts do. The day panel's "Vandaag" heading can stay.
  - Make the tip the near-solid `--tip-surface-solid` / `Panes.Tip` wherever it covers a control.
  - Or reserve a reading strip above the plot so the tip never covers the switch. That would be a **NEW CONVENTION**.
- Affects: Verloop (both), category Verloop, Scorekompas, small area charts (their tip rises over the card head, `area-charts.css:247`).
- Complexity: S (label) / M (placement) · Risk: low.
- Priority: P1 (label) / P2 (overlap)

### 4.5 An empty chart shows a different week from its neighbour
- Status: VERIFIED · Kind: defect (consistency)
- Where:
  - `detail-sleep-2.png` and `android/dark/app-detail-sleep-3.png`: Huidtemperatuur and Ademfrequentie read "Wo Do Vr Za Zo Ma Di", next to HRV and Rusthartslag reading "Do … Wo".
  - Also `detail-training-2/3.png`: Hartbelasting.
  - Code: `lib/time-axis.php:76-78` (`$first === null` → the window starts today); goal `lib/goal-chart.php:274-279`.
- Problem: with no history, the window becomes today plus six days in the future. In the 2×2 grid, the two cards in a row show different weeks.
- Proposal: with no history, use the window that ends today, as a full history would, so the dates match the neighbouring card. CHARTS.md does not cover the no-history case yet: **NEW CONVENTION** (one line in the standard).
- Affects: every empty area chart, the empty goal chart, the empty Verloop for dev_lisa (`pop-phone-*/empty-*`, not checked).
- Complexity: S · Risk: `tools/time-axis-test.php` expectations.
- Priority: P2

### 4.6 Four marker policies, three dot sizes, three horizontal insets
- Status: VERIFIED (code + shots) · Kind: inconsistency
- Where — markers:
  - Verloop: a dot on every point in every period, 5 px when a period has more than 7 days (`config/health.php:548`, `health-history.php:126-139`, `health.css:265-275`).
  - Scorekompas: 30 dagen shows only the first and lone dots (`config/compass.php:209-212`, `compass-history.php`, `hydrate-compass.php:116`).
  - Area charts: every point (`area-chart.php:153-163`).
  - Goal chart: every point up to 16 points, then only the last (`lib/goal-chart.php:445-452`).
  - Heart rate: lone points only (`heart-plot.php:85-91`).
- Where — dot sizes:
  - 8 px goal (`goals.css` `.goal-chart__dot`; `GoalChart.kt:341-346`).
  - 7 px history (`compass.css:459-468`).
  - 5 px small.
  - 12 px focus.
- Where — insets:
  - Verloop and Scorekompas: 0 %, the first dot on the plot edge, so its label is pushed about 8 px off it (`page-health-0.png`: dot x 66.5, "Wo" centred at about 74.5).
  - Goal chart: 3 % (`goal-chart.php:42`).
  - Area charts: 0.3 of a slot (`area-charts.php:226-228`; `detail-sleep-spo2-0`: first dot 14 px in, its label centred under it).
- Problem: CHARTS.md says the Scorekompas is drawn "as Gezondheid's Verloop draws its three", yet over 30 days they look different.
- Proposal: one policy, see the conventions table below. Every point gets a dot up to 31 points (5 px over 30 days); weeks and months always do; intra-day data only where a point stands alone; the goal chart joins the same rule. Use one dot size (7 px, 5 px when dense) and one inset (the area charts').
- Affects: all charts over time, on both apps.
- Complexity: M · Risk: visual only.
- Priority: P2

### 4.7 Overlapping dots hide each other
- Status: VERIFIED · Kind: defect
- Where:
  - `page-health-0.png`, `android/dark/app-health-history-{1,7,30}.png`: Voeding 83 sits under Training 84, with only a 1 px green sliver showing.
  - The focus dots in `app-health-history-reading.png` overlap the same way.
  - Code: `compass.css:459-468` (ring in `--bg-secondary`), `HistoryChart.kt:396-412`.
- Problem: a 7 px dot with a 2 px ring, at scores 1 point apart (≈2 px on a 30-point scale), covers the other dot completely. One category disappears.
- Proposal:
  - Keep the 2 px surface ring (dataviz) and draw the dots in a fixed category order.
  - When two dots on the same day are less than one dot apart, offset them sideways by ±2 px, or rely on the reading. This needs a decision: **NEW CONVENTION**.
- Affects: the Verloop only (the one multi-line chart).
- Complexity: S · Risk: low.
- Priority: P2

### 4.8 A goal that ended in April is drawn over a year
- Status: VERIFIED (web) · Kind: defect (time window)
- Where: `web/phone-dark/detail-goal-1-1.png`: "300.000 stappen in april", ended 30 apr. The axis runs apr–apr, with one point on 1 apr. Code: `lib/goal-chart.php:291`, `time_axis_fit($first, $today)`.
- Problem: the window runs to *today*, not to the goal's end. A one-month goal gets the year window, with 7 empty past months and 5 future ones. At this width the month labels also crowd (see 4.1).
- Proposal: for a goal that has ended, fit the window from its first day to its end date (`time_axis_fit($first, min($today, $end))`). This is a window choice, not a calculation.
- Affects: every finished or ended goal (Afgerond tab), both apps (`GoalChart.kt` draws what the server sends).
- Complexity: S · Risk: goal-chart tests.
- Priority: P2

### 4.9 Two hidden scales in one plot
- Status: VERIFIED · Kind: design issue (dataviz anti-pattern #1)
- Where:
  - `detail-training-1.png` "Stappen + Afstand": the distance line sits exactly on the bar tops.
  - `detail-sleep-bed-0.png` "Tijd in bed + Regelmaat".
  - Code: `lib/area-charts.php:10-18`, `:394-397` (`own` scale, line beside bars); `config/health.php:426-436`, `:200`.
- Problem:
  - Two measures share one plot, each on its own unnamed y-scale, so how they line up is arbitrary.
  - Steps and distance are proportional, so the line repeats the bars and its dots cover their rounded tops.
  - Regelmaat (0–100) beside hours invents a relation that isn't there.
- Proposal:
  - Show the second measure only in the reading and the head, or give it its own small chart.
  - "Actieve + Totale calorieën" shares one kcal scale and is fine.
  - Owner decision: **NEW CONVENTION**.
- Affects: Slaap 1 chart, Training 1 chart, both apps.
- Complexity: M · Risk: page layout (2×2 grid).
- Priority: P2 (PREFERENCE, backed by dataviz)

### 4.10 Palette fails the CVD and contrast checks (validator, not eyeballed)
- Status: VERIFIED (`validate_palette.js`, card surface ≈ #2F2C2E dark / #FBFAFA light) · Kind: accessibility
- Results:
  - Verloop's `--sleep` / `--nutrition` / `--training`: CVD **FAIL**, Training ↔ Voeding ΔE 5.3 (protan), tritan 4.0. Chroma floor FAIL for `--nutrition`. Dark: Slaap 2.69:1 and Voeding 2.81:1 against the card (WARN).
  - Mixed-chart bars (`--bar` = accent at 58 %, `area-charts.css:15`): 2.34:1 for Training and 1.76:1 for Slaap against the card.
  - Heart zones (`theme.css:108-111`, used as an ordered scale): FAIL as an ordinal ramp, with a hue spread of 141° and lightness out of order.
  - `heart-plot.php:8-13` still describes "grey low, the Training colour higher", which is stale.
- Why it matters:
  - Category colours are brand tokens. They stay the same in both themes (THEME.md), and this lens does not propose changing them.
  - Identity can't depend on colour alone. The Verloop has a legend but no direct labels, and Voeding and Training are the two that overlap (4.7).
- Proposal:
  - Add a secondary encoding: a direct label at each Verloop line's last point (Slaap / Voeding / Training). This would be a **NEW CONVENTION**.
  - Raise `--bar` to about 75 % (needs approval).
  - Owner decision on the zone ramp.
- Affects: Verloop, mixed area charts, heart charts, both themes.
- Complexity: M · Risk: brand.
- Priority: P2

### 4.11 The small chart keeps room for a second row of dates it no longer has
- Status: VERIFIED · Kind: polish
- Where:
  - Web `assets/css/area-charts.css:228-235`: axis 2.5em, "two rows".
  - `lib/area-charts.php:302-304`: `axis_rows` null since 40.1.
  - Android `HistoryChart.kt:442-444`: `compact` = 2.5 × caption.
  - `detail-sleep-1.json`: ticks y 729–744, content ends at 758, leaving ≈14 px empty under the weekday labels.
- Proposal: one row (1.25 em) now that 7 dagen names weekdays. The square card then gives the plot that height.
- Affects: all small area charts, both apps.
- Complexity: S · Risk: the grid's square sizing.
- Priority: P3

### 4.12 Measurement scales magnify noise
- Status: VERIFIED (shot) / PREFERENCE (fix) · Kind: design
- Where:
  - `detail-sleep-spo2-0.png`: 96–98 % fills the plot.
  - `detail-sleep-sleeping_hr-0.png`: 51–54 bpm fills it.
  - Code: `lib/area-charts.php:493-513` (span + 15 %, no minimum).
- Problem: a 2-point SpO₂ change, inside the wrist's own ±2 points (the page's own "Over SpO₂" says so), looks like a big swing. The score charts have a minimum span (30); the measurements have none.
- Proposal: a minimum span per measure in the chart's config, e.g. SpO₂ 10 points, heart rate 10 bpm, temperature 1 °C. This is presentation only: **NEW CONVENTION**.
- Affects: SpO₂, HRV, Rusthartslag, skin temperature, breathing rate.
- Complexity: S · Risk: low.
- Priority: P3

### 4.13 The floating "+" covers the SpO₂ small chart's day labels (known case)
- Status: VERIFIED · Kind: usability (transient)
- Where:
  - `detail-sleep-1.json`: the FAB at 333–377 × 726–770 covers the SpO₂ ticks "Di" (327–338) and "Wo" (344–361) at y 729–744.
  - `pages/health-detail.php:131-135`, `components.css:1853-1883`.
  - Android: the same in `app-detail-sleep-2.png` (HRV card).
- Problem: at mid-scroll the "+" sits over the right-hand column's dates and over its drag area. At the end of the page it is clear (`detail-sleep-3.png`: card ends at 697, FAB at 726).
- Proposal: accept it as normal floating behaviour, or hide the "+" while the page scrolls down and bring it back on scroll up (iOS-like; motion lens). No chart change.
- Affects: Slaap and Training (Voeding has no "+").
- Complexity: S · Risk: low.
- Priority: P3

### 4.14 One-point charts (known case: "Tijd in bed + Regelmaat", the Gezondheidsscore history)
- Status: VERIFIED · Kind: mostly correct by the standard, with gaps
- Where: `detail-sleep-1.png`, `detail-sleep-bed-0.png`, `detail-score-compass-4.png`, `page-health-0.png`.
- What is right: one dot at its day on the left, the rest of the period empty, and "Je geschiedenis begint op 11 november". This follows CHARTS.md.
- What is wrong:
  - When one series of two has no value in the whole period, its legend entry ("Tijd in bed") stays, with no word that it has nothing.
  - The y-range of a single score still spans 30 points, with only inner levels labelled. The dot at 93 sits above the topmost label "90", with no 100 label.
- Proposal: mute a legend item whose series has no point in the period, and add "geen gegevens" (copy plus i18n).
- Priority: P3

### 4.15 Smaller notes (VERIFIED unless marked)
- The draw-on sequencing: dots appear at once while the line draws over 900 ms, so a dot shows before its line arrives (`health-trend.js:47-76`). Apple-design: motion should hint where it is going. PREFERENCE: fade the dots in along the draw (motion lens). P3.
- Fill: only the goal chart has a wash (gradient .16 → 0, `goals.css:1036-1039`). This is consistent with "lines only, three washes stack" for the multi-line Verloop, but a single-score Scorekompas line and a single area-chart line have none. PREFERENCE: one rule, either a wash only where a target exists or none at all. P3.
- Y-label policy: the goal chart names 0 and its top; history charts name inner levels only; bar charts name from step to top. Reasonable per data type, see the table. P3.
- The "Trainingen per dag" bars reach the top (max 1 per day → high = 1), leaving no headroom (`area-charts.php:336-342`). P3.
- The heart-rate y-range is the week's (`hydrate-training.php:307-315`), so a quiet day leaves about 60 % of the plot empty above the line, under a red zone-4 wash. PREFERENCE. P3.
- Sleep timeline times "00:07 · 02:00 · 04:00 · 07:05": uneven, 06:00 dropped. Not a chart over days, so CHARTS.md does not apply. HYPOTHESIS that 06:00 is dropped for collision. P3.

## Shared conventions by data type (proposal)

| Data type | Form | Markers | Line | Fill | Labels | Empty / missing |
|---|---|---|---|---|---|---|
| Score 0–100 (Gezondheid, categories) | monotone line | 7 px on every point up to 31 points (5 px over 30 days); every week or month; ring when carried | 2.2 | none | inner levels in round tens, span ≥ 30; full date in the reading | gap, never 0; first point is a dot; "since" line |
| Nightly / daily measurement (SpO₂, HRV, RHR, temperature, breathing rate, floors, active minutes, distance) | monotone line | as for scores | 2.2 | none | own range with a per-measure minimum span (4.12) | gap; "Nog geen metingen" over the same week as its neighbours (4.5) |
| Count / total per day (steps, trainings, total kcal, water, energy intake) | bars from 0, ≤ 24 px, 4 px top radius | none (focus dot on reading) | — | bar ≥ 3:1 (4.10); band for a bereik | levels from 0; head value + "van …" | a missing day draws no bar; reading says "Geen meting" |
| Intra-day continuous (heart rate day / session) | straight segments, zone-coloured | lone points only | 1.8 | faint zone bands | hours every 3 h where they fit, else 6 h, the same on both apps (4.2) | gap for > 20 min |
| Goal progress | monotone line + target hairline | every point ≤ 16, then the last | 2.2 | the one gradient wash | 0 for counts; direct label on the last point | window fitted to the goal's own span (4.8) |
| Two related measures | separate charts, or second value in the reading only (4.9) | — | — | — | legend whenever there are ≥ 2 series | legend item muted when empty (4.14) |
| Night stages | timeline rows, not a chart over days | — | — | — | real times | an empty row stays |

## DATA-SUSPECT (no calculation change proposed)

- `ownify_e2e.sleep_sessions` for dev_jij: 114 nights, but `time_in_bed_minutes` is NULL on 113 (only 2026-04-11 = 485). Tijd in bed draws no bars in any week, even though `started_at` / `ended_at` exist.
- `daily_scores` for dev_jij has only 2026-11-10 (every domain NULL, `data_days` 0, although a sleep session exists that night) and 2026-11-11, despite 120 days of synced data. Every score history (Verloop, Scorekompas, Regelmaat) is one dot, so the multi-day shapes could not be judged.
- "Actieve + Totale calorieën": no Totaal bars in the week, only the Actief line (`detail-training-1.png`). HYPOTHESIS: `total_energy` is missing in the fixture (could not query; DB down).
- Goal-1 "300.000 stappen in april": total 9.420 from a single point, while sleep data exists from April. HYPOTHESIS: thin goal measurements in the fixture.
- Goal title mojibake "DEV â€" 300.000" (`detail-goal-1-0.png`): fixture encoding, other lens.

## Not checked / could not verify

- White Mode charts (`web/phone-light`, `android/light`): ring colour `--bg-secondary` (#FFF) on the light glass card, and light-mode contrast of bars and lines in the shots (the validator was run for light only on the category colours).
- 320 px (`web/small-dark`): label collisions are expected to be worse (4.1); not measured.
- English and imperial (`web/en-phone-dark`): "Oct 8" order, mi/°F levels, wider labels.
- Voeding's Energie and Water charts (band, `of`, `sub`, kJ second scale and gutter 34/40), `detail-nutrition-*`, `app-nutrition-chart-*`.
- Insight charts (`detail-insight-C*`, `insight-progress.php` meter), Overzicht mini charts, the hero ring.
- Training session heart rate and its irregular ticks (`detail-training-session-*`, `app-training-session`).
- Slaap's 7/30/90-day stacked night bars (40.1).
- Web readings, keyboard reading, TalkBack steps, reduced motion (`web/a11y/reduced-motion-*`).
- Android goal chart: the Android shots show the empty Doelen page, no goal was opened.
- Web 30 dagen, 90 dagen and 1 jaar shots don't exist (the capture shows default periods only); 4.1 on web for the Verloop is computed from the same rule.
- Anything needing the database after it went down (skin temperature and breathing rate fixture coverage).

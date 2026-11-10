# Scorekompas

46.0: on screen this page is called **Gezondheidsscore**, with **Gezondheid**
under it (`config/compass.php`, both apps); "Scorekompas" stays the name in
the code and these documents.

The Health Score explained. Overzicht's score card opens it, on the website
and in the Android app alike, and says under the score where it is heading.
It answers three questions:

1. **Waar je score uit bestaat** — what the score is made of
2. **Wat er verandert** — what is changing
3. **Vergeleken met jezelf** — how it compares with the person's own past

A fourth, **Mogelijk aandachtspunt** ("Grootste kans" until 29.0) — the
component with the most room on the score, with the research about it — was
removed in 38.0 on both apps, with its sentences, its rules and its
translations.

## It reads the score, it never scores

| | |
| --- | --- |
| Engine | `includes/health-score.php`, numbers in `config/scoring.php` — unchanged |
| History | `health_score_history($userId, 365)`: the score as it was **recorded** each day in `daily_scores` — never recalculated from today's records — and today's as it stands (below, *The history*) |
| Compass | `includes/score-compass.php` — pure: no database, no clock |
| Words and rules | `config/compass.php` |
| Page data | `lib/hydrate-compass.php` → `$data['compass']` in `app_page_data()`, so `index.php` and `api/app/state.php` (the app) show the same block |
| Website | `pages/score-compass.php`, `components/compass-history.php`, `components/score-direction.php`, `assets/css/compass.css`, `assets/js/compass-history.js` |
| App | `ui/screens/overview/ScoreCompass.kt`, `Detail.ScoreCompass`, `data/AppData.kt` (`Compass`, `CompassPeriod`, `CompassDay`) |

Nothing the compass does changes a weight, a curve, the window, the minimum
number of days, how missing data is handled or a score band. (The method itself changed in 38.0 — below, *The method* — and the
compass followed.) The one change the compass made
to the engine is additive: each category's result also carries the `facts`
its components were worked out from — every night's length, the spread of
bedtime and wake time, each quality measurement, minutes a week, the parts of
balance and progression — so the compass can say them in words. They are not
stored (`daily_scores` keeps the components only) and nothing reads them to
score. `tools/score-compass-test.php` checks that they give the components
back exactly.

## The window: one score, the last 168 hours

**The Health Score is calculated over the last 168 hours** (`window_hours` in
`config/scoring.php`, `HEALTH_SCORE_VERSION` `factors-v2` since 38.0, `rolling168-v1` before): the moment of
calculation minus 168 hours, moving with the clock. There is one score. The
7 days, 30 days, 90 days and year the compass shows are its **history** —
the score as it was each day — never scores of their own. The compass says
"Over de afgelopen 7 dagen" where it shows the score, read from the config.

Until 1.6.0 the window was 90 days (`rolling90-v1`). Rows stored then are
kept as they were and shown in the history for their days; nothing is
recalculated.

**Missing data is not zero, and old data does not last.** Without new input
a category keeps its score while its data is still in the window — for at
most `expiry_days` (3) days for Slaap and Voeding. On the third day without
a new night (or cijfer) the category stops counting: it is left out of the
overall score, which is recalculated from the categories that still count,
never counted as a zero. Training has no expiry: a rest day is part of what it
scores, and the window drops old workouts by itself.

## The method (38.0)

From 38.0 the categories are built from **26 factors** instead of eight
components, by a method the user approved (decisions D1–D7 of the Health
Score proposal; `config/scoring.php`, `includes/health-score.php`,
`HEALTH_SCORE_VERSION` `factors-v2`). It is a school project: the aim is a
method that is explicit, reproducible and explainable, more than one tuned
for accuracy.

**Grades, not chosen weights (D1).** Every factor is graded twice on the
steps 1 / 0.5 / 0.25: *evidence* — how strongly research ties it to health
outcomes — and *measurement* — how well an ordinary phone or watch measures
it. Its priority is evidence × measurement. Factors that largely measure the
same thing share their place: each priority is divided by 1 + the sum of its
overlaps ρ. The weight is that corrected priority as a share of the
category's total (`health_score_weights()`); nothing types a weight in.

| Sleep | E | M | Weight | Nutrition | E | M | Weight | Training | E | M | Weight |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Duration | 1 | 1 | 34.5% | Fibre | 1 | 0.5 | 31.1% | Steps | 1 | 1 | 26.5% |
| Regularity | 1 | 1 | 34.5% | Sodium | 1 | 0.25 | 15.5% | Strength minutes | 1 | 0.5 | 19.8% |
| Social jetlag | 0.5 | 1 | 17.3% | Saturated fat (ρ 0.6) | 0.5 | 0.5 | 9.7% | Resting heart rate | 1 | 0.5 | 19.8% |
| HRV vs own baseline | 0.25 | 0.5 | 4.3% | Sugar | 0.5 | 0.25 | 7.8% | Aerobic minutes (ρ 0.5 ×2) | 1 | 0.5 | 9.9% |
| Efficiency (ρ 0.7) | 0.5 | 0.25 | 2.5% | Protein | 0.25 | 0.5 | 7.8% | Active days | 0.25 | 1 | 9.9% |
| Time awake (ρ 0.7) | 0.5 | 0.25 | 2.5% | Water | 0.25 | 0.5 | 7.8% | Intensity (ρ 0.5) | 0.5 | 0.5 | 6.6% |
| Deep sleep | 0.25 | 0.25 | 2.2% | Caffeine timing | 0.25 | 0.5 | 7.8% | Recovery | 0.25 | 0.5 | 5.0% |
| REM sleep | 0.25 | 0.25 | 2.2% | Fat share (ρ 0.6) | 0.25 | 0.5 | 4.9% | Progression | 0.25 | 0.25 | 2.5% |
| | | | | Energy balance | 0.25 | 0.25 | 3.9% | | | | |
| | | | | Daily rating | 0.25 | 0.25 | 3.9% | | | | |

**Curves (D2, D4, D6).** On the log-risk scale of the source, or from its
own table: sleep duration from the AHA Life's Essential 8 points; regularity
from the bedtime and wake-time spread (Windred et al. 2024); social jetlag
(Roenneberg); HRV as a z-score of the window's mean ln(rMSSD) against the
person's own 60 days before it; fibre per 1,000 kcal (Reynolds et al. 2019);
sodium (WHO); saturated fat and fat share (Gezondheidsraad); sugars (WHO);
protein per kg (EFSA); water against the norm for the person's sex (NUT-08:
1.4 l, men 1.8 l); caffeine — hours from the last intake to the usual
bedtime (Drake et al. 2013, 8.8 h after Gardiner et al. 2023); steps per day
as ln(HR)/ln(0.47) from Paluch et al. 2022 (from 60 its own curve); aerobic
minutes per week, vigorous counting double (WHO 2020), on Arem et al. 2015;
strength minutes (Momma et al. 2022); resting heart rate, straight from 55 to
90 bpm. The Paluch and Windred numbers were checked in the full papers. The
aerobic points are ln(HR)/ln(0.61) of Arem's published hazard ratios (0.80,
0.69, 0.63, 0.61) at the middle of each band. Momma reports a J-shape —
little extra benefit, and in some outcomes less, beyond about an hour of
strength training a week; the curve simply stays at 100 there.

**Recent days count more (D3).** Within the 7-day window a day weighs
0.5 ^ (days ago / 4): today 1, four days ago ½, six days ago about ⅓. "Per
week" is the weighted total scaled so an even week counts exactly its own
total.

**What counts as data.**

- Nutrition counts what was eaten only on days logged to at least half the
  energy target (`food_day_share`; without a target 1,000 kcal). Without one
  such day the daily rating is the whole Voeding score, as before 38.0.
  Caffeine counts for somebody who records it at all; a food day without
  any is a day without caffeine.
- Training's days are days with a workout or a completed day of steps;
  today's steps wait until the day is over. Without a workout in the window
  the workout factors (aerobic, strength, intensity, recovery, progression)
  are not measured — left out, not zero.
- Unchanged: 3 days per category, expiry after 3 days without input (Slaap,
  Voeding), missing factors left out and the rest re-weighted, the overall
  score the plain average of the categories, and nothing here touches the
  Community points.

**Uncertainty and coverage (D5).** Every score is stored with a range and a
coverage in `daily_scores.inputs` — `{"components", "range", "coverage"}`;
the overall row holds its own range and coverage. The range is the 5th–95th
percentile of the score when the method itself is varied 2,000 times: each
grade moves a step down or up with a chance of 1/3 each, every ρ is scaled
by 0.4–1.3 (a fixed seed, so the same data always gives the same range and a
row only changes when the data does). The proposal's sensitivity analysis
used 10,000 draws; per score 2,000 keep the calculation near 30 ms and move
the bounds by at most a few tenths. Coverage is the share of the category's
weight whose factors had data; overall, the three averaged, a category
without a score counting as 0. **Neither is shown yet**: putting them in the
Scorekompas is a design change that goes to the user first.

**Recalibration (D7).** The overlaps ρ and the interim curve anchors are
estimates from the literature. After eight weeks of Ownify's own data they
are to be re-estimated — ρ from the correlations between factor scores
across users and days, the anchors from the distributions people actually
reach — and changed in `config/scoring.php` only, with a new
`HEALTH_SCORE_VERSION`.

**Worked example** (the proposal's, `tools/health-score-test.php`): Slaap
95.7 (range 92.9–96.6), Voeding 71.8 (68.5–81.2), Training 76.9 (72.1–81.3),
Health Score 81.5 → 82.

**What changed in behaviour.** Training volume no longer turns down at
extreme amounts: aerobic minutes flatten at 600 a week, and overdoing it
shows only in recovery (5%). Voeding now rewards what was eaten, where it was
logged. A phone alone now gives a Training score, from steps and active days.

## The history

Every calculation of the score is written to `daily_scores` — one row per
person, day and category (the primary key; calculating again the same day
updates that day's row) with its days, its components and `valid_until`,
the last day it holds without new input (migration 017). It is written
whenever the score is calculated: a save on Gezondheid, a Health Connect
sync, every page render and every app read. No scheduler.

- **A past day is never written again.** `health_score_store()` only writes
  today's rows, so what a day recorded stays what it was, whatever comes in
  later.
- **A day without a row of its own** (nobody opened Ownify and nothing came
  in) keeps the last recorded score, each category only up to its
  `valid_until` — `carried`, with the day it came from — and the overall
  score is combined again from the categories that still held. After that
  the day has no score.
- **No backfill.** A new account's history starts on its first day with a
  score: 18 days of history are 18 days, never a year with 347 empty or
  invented ones. The periods show where the history begins.
- **A year at most** is read. Nothing is deleted.

`tools/score-history-test.php` checks all of this against a database.

## 1 — What the score is made of

The categories as Overzicht names them (Slaap, Voeding, Training), each with its
score and its band's dot (`score_colour_band()`: 80+ high, 60+ mid, below
low), its tile in its own category colour, and its days with data — or, with
no score yet, what Gezondheid says is still needed.

Each component shows the weight it has **now**: a component without data is
left out and the others share its weight, exactly as `health_weighted()` does
(Training with only steps and aerobic minutes: 26.5 : 9.9 → 73% and 27%).
Before a category has a score, the weights it will have. Voeding on its
daily cijfer alone also says so in a sentence. The first-days card on
Overzicht lists only the factors that count; the Scorekompas lists them all,
with why the others do not count yet.

## 2 — What is changing

The Health Score's history over a period the person picks — **7 dagen**
(where it opens: the score's own week), **30 dagen**, **90 dagen** or **1
jaar** — in the range switch's Liquid Glass capsule, as a line with
sentences, drawn as Gezondheid's Verloop draws its three
(`hydrate_compass_periods()` in `lib/hydrate-compass.php`) on Ownify's time
axis ([CHARTS.md](CHARTS.md)): a young history from its first day at the
left, the rest of the period empty ahead; a full one rolling, today at the
right. 7 and 30 days have a point a day; 90 days a point a week and a year a
point a month, each the mean of its days' Health Scores, standing at its own
date. The line is 160 px / dp tall over the
period's own range in round tens (at least 30 points), its levels named on
the left, a monotone curve without a wash. In a week every day is a dot (a
ring for a day whose score was carried), and so is every week and month; in
30 days only where the line begins and a day on its own. The dates: every
day of a week, every third day of 30, every week of 90 (those two in two
rows, the month under the day where it begins), 13 month boundaries for a
year; never "Vandaag" or a year. A point without a score is a gap in the line,
never a drop to zero. The sentences and direction are worked out from the
days as before; the 30 days an older app draws (`trend.chart`) stay 0–100.

**Reading a day.** A finger on the line (press and slide; a vertical drag
still scrolls), a cursor or the arrow keys pick the nearest day: its date
and score appear above the line as on a goal's Verloop, and the panel under
the chart shows the whole day — its Health Score, Slaap, Voeding and Training
with their bands and their parts ("Slaapduur 72 · Regelmaat 60 · Kwaliteit
70", "Dagcijfer 7,7") — and, for a carried day, that the score of an
earlier day still held; for a day without one, that it had none. A week or
month reads as one: its days with "weekgemiddelde" or "maandgemiddelde"
beside them, the mean Health Score and each category's mean, without parts.
The panel
shows today until a day is read, and today again when the period changes.
TalkBack steps through the days with the node's actions (Vorige dag,
Volgende dag).

**On Gezondheid.** The Verloop under Gezondheid's three cards is this same
history — the same days, periods, windows and dates — drawn as three lines,
Slaap, Voeding and Training, each in its category's colour, instead of the
one Health Score (`hydrate_health_history()` in `lib/hydrate-compass.php`,
`components/health-history.php`; `HistoryCard` in the app). 7 and 30 days
are days, 90 days weeks and a year months, each the mean of its days'
scores, on the same time axis; a line begins at a point of its own. A
category's page shows it with its one line. Its height is the period's own range in round tens, at least
30 points, with the levels named; its lines are monotone curves
(`goal_chart_monotone()`). Its reading shows the date or days and each
category's score, above the lines; there is no panel. A week names every
date, under its own dots, and every point is a dot. The Scorekompas's own
line is drawn the same way, its one line the Health Score. The chart and its reading are one piece of code for both:
`compass-history.js` on the website, `HistoryPlot` (`ui/design/HistoryChart.kt`)
in the app.

**Sentences per period.** A week is too short to compare a first and a
last week, so it says what the week held ("De afgelopen 7 dagen lag je
score tussen 68 en 73."). 30 days, 90 days and a year are analysed with the
rules below, each over its own days, with its own direction chip; the
direction under the score on Overzicht and the hero stays the 30 days'.

| Rule | Value |
| --- | --- |
| Days with a score before there is a direction | 14 (`trend_min_days`) |
| Compared | the first week of the period (from its first day with a score) against the last week; each needs 4 days with a score |
| Stijgend / Dalend | the two weeks' rounded averages differ by 2 or more; otherwise Stabiel |
| "x weken op rij" | only when every week's average moved the same way, three times |
| Recovery | a week in between averaged 3 or more below both ends |
| A category starting or stopping to count | told with its date and its value that day |
| The biggest day | named when it moved the score 3 or more, after the first week (a young score jumps while its first days come in) |
| What moved with it | when the score moved: of the categories that moved 2 or more, the one that moved most — and within it the component whose change counted most |

Too few days: the line with what exists, and "Meer gegevens maken je trend
duidelijker." No days: "Nog niet genoeg gegevens."

## 3 — Compared with yourself

| Row | What it is | Needs |
| --- | --- | --- |
| Nu | the Health Score now, over its 168 hours | a score |
| Laatste 7 dagen | the average of the daily score | 4 of the 7 days |
| Laatste 30 dagen | the average of the daily score | 15 of the 30 days |
| 30 dagen daarvoor | the same, the 30 days before | 15 of those 30 days |

The difference ("+5 ten opzichte van de 30 dagen daarvoor") is taken from the
two numbers shown, so it always adds up. A day without a score is left out of
an average, never counted as zero. Nobody else's score is ever shown.

## Words

Every sentence is in `config/compass.php`. `tools/score-compass-test.php`
fails on any word that says why ("omdat", "doordat", "waardoor", "zorgt
ervoor"…), what to do ("je moet", "probeer", "zorg dat"…) or compares with
anybody else ("anderen", "vrienden", "Nederland"…).

## Limits

- **The history is what was recorded.** A day before 1.6.0 shows the 90-day
  score of that time; a day nobody's data reached has no score.
- **Sentences are about the score, not the behaviour behind it.** "In
  dezelfde periode ging je score voor slaapduur van gemiddeld 80 naar 66" is
  read from the engine's components; the compass does not look at single
  nights to find "three shorter nights".
- **Cost.** The 60 days are recalculated on each page read, as Gezondheid's
  28 are: 8–30 ms for the demo accounts.

## Tests

- `php tools/score-compass-test.php` — directions, recovery, weeks in a row,
  a category starting or stopping to count, the averages and when they are
  withheld, that there is no Mogelijk aandachtspunt, every factor's words,
  empty states, the engine's facts against its components, and the wording.
- `php tools/health-score-test.php` — the score itself: the 26 weights
  against the approved table, the worked example and its ranges, recency,
  every factor's curve and its data rules.
- `AppDataParseTest` — the block as the server sent it, for the demo account,
  a new account and a server without it.
- `OwnifyAppFlowTest` — the card opens it by touch and as TalkBack does, the
  page under it is out of reach, back returns; without it the card stays a
  card.

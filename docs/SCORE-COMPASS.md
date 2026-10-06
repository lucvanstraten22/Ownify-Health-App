# Scorekompas

The Health Score explained. Overzicht's score card opens it, on the website
and in the Android app alike, and says under the score where it is heading.
It answers four questions:

1. **Waar je score uit bestaat** — what the score is made of
2. **Wat er verandert** — what is changing
3. **Vergeleken met jezelf** — how it compares with the person's own past
4. **Grootste kans** — where the score has the most room

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
number of days, how missing data is handled or a score band. The one change
to the engine is additive: each category's result also carries the `facts`
its components were worked out from — every night's length, the spread of
bedtime and wake time, each quality measurement, minutes a week, the parts of
balance and progression — so the compass can say them in words. They are not
stored (`daily_scores` keeps the components only) and nothing reads them to
score. `tools/score-compass-test.php` checks that they give the components
back exactly.

## The window: one score, the last 168 hours

**The Health Score is calculated over the last 168 hours** (`window_hours` in
`config/scoring.php`, `HEALTH_SCORE_VERSION` `rolling168-v1`): the moment of
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
never counted as a zero. Sport has no expiry: a rest day is part of what it
scores, and the window drops old workouts by itself.

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

The categories as Overzicht names them (Slaap, Voeding, Sport), each with its
score and its band's dot (`score_colour_band()`: 80+ high, 60+ mid, below
low), its tile in its own category colour, and its days with data — or, with
no score yet, what Gezondheid says is still needed.

Each component shows the weight it has **now**: a component without data is
left out and the others share its weight, exactly as `health_weighted()` does
(Sport with no intensity and no progression: volume 20:35 of 55 → 36%,
balance 64%). Before a category has a score, the weights it will have.
Voeding has one component, its daily cijfer, so it is a sentence instead of a
list.

## 2 — What is changing

The Health Score's history over a period the person picks — **7 dagen**
(where it opens: the score's own week), **30 dagen**, **90 dagen** or **1
jaar** — in the range switch's Liquid Glass capsule, as a line (0–100, the
same box and draw-on as Gezondheid's trend) with sentences. The line adapts
to the period: in a week every day is a dot (a ring for a day whose score
was carried), in longer periods only a day on its own is; the axis names 4
dates in a week, 3 in 30 days, 4 in 90 and 5 in a year, each under its own
day. A day without a score is a gap in the line, never a drop to zero.

**Reading a day.** A finger on the line (press and slide; a vertical drag
still scrolls), a cursor or the arrow keys pick the nearest day: its date
and score appear above the line as on a goal's Verloop, and the panel under
the chart shows the whole day — its Health Score, Slaap, Voeding and Sport
with their bands and their parts ("Slaapduur 72 · Regelmaat 60 · Kwaliteit
70", "Dagcijfer 7,7") — and, for a carried day, that the score of an
earlier day still held; for a day without one, that it had none. The panel
shows today until a day is read, and today again when the period changes.
TalkBack steps through the days with the node's actions (Vorige dag,
Volgende dag).

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

## 4 — The biggest opportunity

For every component with data, in a category with at least **3 days** of
data (the minimum for a score, now that the window is 7 days):

```
room = (100 − component) × its weight among its category's counted components
                         ÷ the number of categories with a score
```

— the points the Health Score would gain if that component were 100, because
the overall score is the equal average of the categories and each category the
weighted average of its components. The largest room wins, if it is at least
**2 points**; otherwise "Geen onderdeel springt eruit". So it is not simply
the lowest category: Sport at 65 loses to Slaap at 73 when Slaap's duration
is at 40, because duration weighs 45% of Slaap.

What it says comes from the facts — what was measured — and what a higher
score would go together with, using the curve's top from `config/scoring.php`
where a range is named ("Meer nachten tussen 7:30 en 8:30 zouden samengaan met
een hogere duurscore"). Within regularity, quality and balance it names the
part with the most room (bedtime's spread, time awake, rest days…). It never
says what to do, when to sleep or what to aim for.

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
- **Room is linear.** It ignores rounding, and that a component can rarely be
  100 (a cijfer of 10 every day). It ranks components; it is not a promise.
- **Mean-based explanations.** Sleep quality's parts are averages over the
  nights that measured them. When the average lies inside the curve's top but
  the nights vary, there is no honest "more" or "less", and the opportunity
  says what the component scores instead.
- **Cost.** The 60 days are recalculated on each page read, as Gezondheid's
  28 are: 8–30 ms for the demo accounts.

## Tests

- `php tools/score-compass-test.php` — directions, recovery, weeks in a row,
  a category starting or stopping to count, the averages and when they are
  withheld, the opportunity (weight × room, not the lowest category, the
  7-day and 2-point rules), every explanation, empty states, the engine's
  facts against its components, and the wording.
- `php tools/health-score-test.php` — the score itself, unchanged.
- `AppDataParseTest` — the block as the server sent it, for the demo account,
  a new account and a server without it.
- `OwnifyAppFlowTest` — the card opens it by touch and as TalkBack does, the
  page under it is out of reach, back returns; without it the card stays a
  card.

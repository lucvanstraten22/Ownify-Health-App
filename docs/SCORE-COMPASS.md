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
| History | `health_score_history($userId, 60)`: the engine's own result at the end of each of the last 60 days, recalculated from the records (`health_score_trend()` is now this, reduced to the four numbers — the same records, the same moments) |
| Compass | `includes/score-compass.php` — pure: no database, no clock |
| Words and rules | `config/compass.php` |
| Page data | `lib/hydrate-compass.php` → `$data['compass']` in `app_page_data()`, so `index.php` and `api/app/state.php` (the app) show the same block |
| Website | `pages/score-compass.php`, `components/score-direction.php`, `assets/css/compass.css` |
| App | `ui/screens/overview/ScoreCompass.kt`, `Detail.ScoreCompass`, `data/AppData.kt` (`Compass`) |

Nothing the compass does changes a weight, a curve, the window, the minimum
number of days, how missing data is handled or a score band. The one change
to the engine is additive: each category's result also carries the `facts`
its components were worked out from — every night's length, the spread of
bedtime and wake time, each quality measurement, minutes a week, the parts of
balance and progression — so the compass can say them in words. They are not
stored (`daily_scores` keeps the components only) and nothing reads them to
score. `tools/score-compass-test.php` checks that they give the components
back exactly, and every account's scores, days and components for 90 days
were compared before and after the change: identical.

## The window

**The Health Score is calculated over a rolling 90 days** (`window_days` in
`config/scoring.php`, `HEALTH_SCORE_VERSION` `rolling90-v1`), not over the
last 168 hours. The compass is built around the score as it is, and says so
where it shows it ("Over de afgelopen 90 dagen", read from the config, so the
words follow if the window ever changes). Making it a 7-day score would be a
deliberate change of the scoring model — one line in `config/scoring.php`,
but every score, the stored history and what Ownify AI is told would move
with it — and is not part of the compass.

A 90-day score moves slowly: a week of short nights lowers it by a point or
two. The compass's thresholds are set for that.

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

The last 30 days of the daily Health Score, as a line (0–100, the same box
and draw-on as Gezondheid's trend), and sentences.

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
| Nu | the Health Score now, over its 90 days | a score |
| Laatste 7 dagen | the average of the daily score | 4 of the 7 days |
| Laatste 30 dagen | the average of the daily score | 15 of the 30 days |
| 30 dagen daarvoor | the same, the 30 days before | 15 of those 30 days |

The difference ("+5 ten opzichte van de 30 dagen daarvoor") is taken from the
two numbers shown, so it always adds up. A day without a score is left out of
an average, never counted as zero. Nobody else's score is ever shown.

## 4 — The biggest opportunity

For every component with data, in a category with at least **7 days** of
data:

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

- **The window is 90 days** (above): the compass describes how a slow score
  moved, not last week's behaviour.
- **Sentences are about the score, not the behaviour behind it.** "In
  dezelfde periode ging je score voor slaapduur van gemiddeld 80 naar 66" is
  read from the engine's components; the compass does not look at single
  nights to find "three shorter nights", because a 90-day score does not move
  on three nights, and naming them would suggest that it did.
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

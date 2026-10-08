# Charts over time

Every Ownify chart that shows something over days — Gezondheid's Verloop,
a category's own Verloop on its page, the Scorekompas's Health Score
history, a goal's Verloop, Slaap's four charts (docs/SLEEP.md), and any
chart that comes after them — follows
one standard, on the website and in the Android app alike. Read this before
you add or change one.

> **A chart represents time, not the amount of data there happens to be.**
>
> Fixed time period → the actual history starts at the left → the
> unavailable, future part of the period stays empty on the right → once the
> history fills the period, the window rolls: new data enters on the right
> and old data leaves on the left.

The rule is code, in one place: **`lib/time-axis.php`** (`time_axis()`). It
decides the window, the dates under the chart and where each point stands.
A chart never works out its own time positions; both apps draw what the
server sends. Its own test is `tools/time-axis-test.php`.

## The periods

| Period | The window | The dates under it | A point is |
| --- | --- | --- | --- |
| **7 dagen** (the default) | 7 days | every day: `8 okt · 9 okt · 10 okt …` | a day |
| **30 dagen** | 30 days | every third day: `8 okt · 11 · 14 · … · 29 · 1 nov · 4` (10 dates) | a day |
| **90 dagen** | 90 days | every seventh day: `8 okt · 15 · 22 · 29 · 5 nov …` (13 dates) | a week |
| **1 jaar** | 365 days | 13 month boundaries: `okt · nov · dec · … · sep · okt` | a month |

- **The window.** It begins at the history's first day while the history is
  shorter than the period, and runs the full period from there: the part
  after today is empty, waiting. Once the history is as long as the period,
  the window is the period that ends today, today at the right edge.
  - 2 days of history in 7 dagen: 8 to 14 oktober, points on the 8th and 9th,
    the rest of the week empty. Never the two days shifted to the right,
    never the chart zoomed into them.
  - A full history in 7 dagen: 2 to 8 oktober, today the last date.
- **The dates** stand at their days, counted from the window's first day.
  They are real dates: never "Vandaag", never a year (the reading carries
  the full date). Over 30 and 90 days a date names its month at the first
  date and where the month changes (`29 · 1 nov · 4`) — the full `11 okt`
  on every one would not fit across a phone.
- **A year** has 12 months and 13 boundaries: `okt … okt` is one year from
  October to October, the closing October its end. Each boundary is the
  same day of the month as the window's first day (the last day of a
  shorter month: 31 jan, 28 feb, 31 mrt …). The 13th boundary is not a 13th
  month.
- **A chart too narrow for a week's dates** (Slaap's small charts) writes
  them in two rows as 30 and 90 days do — the day over its month, the month
  where it is named (`time_axis_rows()`): the same dates, at the same days.
- **A chart without a period switch** (a goal's Verloop) takes the shortest
  period that holds its whole history — 7 dagen, then 30, 90, then a year,
  which rolls from then on (`time_axis_fit()`).

## The points

- **Data points are not dates.** The dates describe time; the points are the
  data, worked out by the existing history and its aggregation, never
  invented to fill the axis. Over 30 days the line has a point a day and
  the axis a date every third day; over a year 12 points and 13 boundaries.
- **A week or a month is one point**: it stands at the date it begins and
  covers the days up to the next date, each line's mean over the scores it
  had in those days, rounded as a score is. Its reading says its days and
  that it is a mean ("2 – 8 okt · weekgemiddelde").
- **Only what has begun.** A day, week or month that lies ahead of today is
  no point; one that has begun is, with the days it has so far.
- **A line begins at a point of its own.** Lines share the axis but not
  their start: Slaap can begin on the 8th, Voeding on the 9th and Training
  on the 12th, each line from its own first day. Where a line's first score
  falls inside a week or month, the line begins at the next one — a point
  never stands before its category existed, and no line is drawn into a
  period before its first score. The first point of a line is always a
  visible dot.
- **Gaps stay gaps.** A score that still holds (its `valid_until`) carries
  the line on; without a valid score there is no point — a gap, never a 0.
  No history is made up.

## The rest of a chart

These are the same on every chart and are not part of the time rule:

- the height: the period's own range in round tens, at least 30 points, its
  levels named in a gutter on the left (`hydrate_health_history_chart()`); a
  goal's range is rounded around its values and its target;
- monotone curves (`goal_chart_monotone()`), which never bend past a point;
- the reading: a finger, a cursor or the arrow keys pick the nearest point,
  with a crosshair, a dot on each line and the reading above the chart; the
  touch reading lingers 1.6 s; TalkBack steps with Vorige dag / Volgende dag;
- the period switch (`7 dagen | 30 dagen | 90 dagen | 1 jaar`, 7 dagen first)
  in the range switch's glass;
- every colour through the theme (`docs/THEME.md`), Dark and White Mode.

## Where it lives

| | Server (draws the geometry) | Website | Android app |
| --- | --- | --- | --- |
| The time axis | `lib/time-axis.php` | — | — |
| Gezondheid's Verloop, a category's own | `hydrate_health_history()` (`lib/hydrate-compass.php`) | `components/health-history.php` (`history_area` for a category's page) | `HistoryCard` (`only` for a category's page) |
| The Scorekompas's history | `hydrate_compass_periods()` | `components/compass-history.php` | `PeriodChart` in `ScoreCompass.kt` |
| A goal's Verloop | `goal_chart_build()` (`lib/goal-chart.php`) | `pages/goal-detail.php`, `goal-chart.js` | `GoalChart.kt` |
| Slaap's four charts, small and on their own page (docs/SLEEP.md) | `hydrate_sleep_charts()` (`lib/hydrate-sleep.php`) | `components/sleep-chart.php` | `SleepChartsGrid`, `SleepChartDetail` |
| Reading a chart | — | `assets/js/compass-history.js`, `goal-chart.js` | `HistoryPlot` (`ui/design/HistoryChart.kt`), `GoalChart.kt` |

A new chart over time: build its points on `time_axis()` — its `slots` are
where the points go, its `ticks` the dates — and draw them with the shared
pieces above. Nothing new needs its own window, dates or positions.

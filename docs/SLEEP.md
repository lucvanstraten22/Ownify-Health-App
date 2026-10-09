# Slaap, drawn

The Slaap page shows the night and the weeks around it as charts, not as
rows of numbers. It is the same on the website and in the Android app. The
server works out every position and word (`lib/hydrate-sleep.php`), and both
apps draw what it sends.

```
Slaap ──┬── the score                    (unchanged)
        ├── Slaapverloop                 the last night, stage by stage
        ├── Tijd in bed + Regelmaat │ SpO₂
        │   Huidtemperatuur         │ Hartslagvariabiliteit
        ├── Nachtelijke waarden          what no chart shows (hartslag, ademhaling)
        └── Verloop                      the sleep score over time (unchanged)
```

Nothing here is a new calculation, and nothing is advice. Every value is
one Ownify already recorded. A day without one is a gap, never a 0.

## Slaapverloop — the night

The last night of the past seven days, by the one rule every reader uses
(`health_night_on()`: the main sleep of the morning it ended, never a nap,
a duplicate counted once, a night broken by getting up still one night).

- **The rows**, top to bottom: Wakker, Rusteloosheid, REM, Licht, Diep. Each
  is named on the left with its time over the night ("129 min").
- **The time axis** runs from the night's own bedtime at the left edge to
  its wake time at the right ("23:01 … 07:19"), never a fixed clock. Whole
  hours are marked between them, every second or third hour on a long
  night, and never so close to either end that the labels touch.
- **The blocks**: each recorded period of a stage, on its row, from when it
  began to when it ended. Periods of the same stage that follow each other
  directly are one block.
- **The reading**: a finger, a cursor or the arrow keys show the period
  there, with its stage and its times ("REM · 00:48 – 01:04"), above the
  chart. That block is ringed and the others step back. TalkBack steps
  through the periods with *Volgende fase* / *Vorige fase*.
- **The head**: the night ("Nacht van 7 op 8 okt"), how long was slept
  ("8:12 u geslapen") and how much of the time in bed ("99% efficiënt").

### Which stage is which row

Health Connect numbers its stages. Each row draws:

| Row | Health Connect stage |
| --- | --- |
| Wakker | 1 — awake |
| Rusteloosheid | 7 — awake in bed: lying awake, not an awakening (the minutes count it as awake, the awakenings do not) |
| REM | 6 |
| Licht | 4 |
| Diep | 5 |
| *(no row: a gap)* | 2 — asleep, kind unknown; 3 — out of bed |

Health Connect has no stage called "restless". Stage 7 is the closest it
records, so Rusteloosheid shows it. A source that never writes stage 7
leaves that row empty, honestly.

### Where the periods come from

Health Connect sends each sleep session with its stage periods. Until
migration 018 only their minutes per stage were kept. Now each period is
also kept as it was recorded:

- `sleep_stages`: session, stage, start and end, on the session's own clock
- written by `health_import_sleep_stages()`, replaced as a whole whenever
  the session is synced again
- removed with its session

The minutes per stage, the night, the sleep score and the points are worked
out exactly as before; this only keeps what they were added up from.

- **Before migration 018 is imported**, a night shows its times without
  stages.
- **After it**, the app's next sync fills in the last seven nights (it
  sends the last seven days each time).
- **A night recorded without stages** (a phone without a watch) keeps its
  times and empty rows, and says *Van deze nacht zijn geen slaapfasen
  opgenomen.*

## The four charts

Ownify's area charts (`lib/area-charts.php`, docs/CHARTS.md), the same
system Training's charts are drawn with. Two by two, each card square: two side by side are as wide as one
full-width card. When a long name needs a line more, both cards of that row
grow to the taller one.

| Chart | What it draws | From |
| --- | --- | --- |
| Tijd in bed + Regelmaat | Tijd in bed as bars; Regelmaat as a line with dots, over it | the night's time in bed (`sleep_sessions`); the sleep score's regularity part as it was recorded each day (`daily_scores`, the same "Regelmaat" the Scorekompas shows), carried as the score is carried |
| SpO₂ | a line with dots | each day's value (`health_daily_metric()`) |
| Huidtemperatuur | a line with dots | the same |
| Hartslagvariabiliteit | a line with dots | the same |

- **The bars and the line in the first chart have heights of their own.**
  The bars run from 0 to the most hours. Regelmaat is a 0–100 score. So
  neither scale is named, and the reading gives both values.
- **The other three** name their levels in a gutter on the large chart,
  never finer than the values themselves (a whole percent, a tenth of a
  degree).
- **The small card** shows its name, the value its week ends on (with that
  day's date when it is not today's), and its week. The dates are in two
  rows (`time_axis_rows()`), the day over its month, because "8 okt" seven
  times does not fit.
- **A tap or click on a small card opens its page.** A finger moved
  sideways reads a day first, as the large chart is read, and does not
  open the page. A cursor reads on hover.

### Ownify's time axis

Every chart follows `docs/CHARTS.md` and `lib/time-axis.php`:

- The window starts at that chart's own first value. Two nights of SpO₂
  are two points at the left of the seven days, with the rest of the week
  empty ahead. Once the history fills the period, the window rolls.
- Each point is a day over 7 and 30 days, a week over 90, and a month over
  a year. A week or month is the mean of its days with a value, and the
  reading says so ("weekgemiddelde").
- A series that began during a week begins at the next week.
- The plot is inset by most of half a point's room, so a bar over the
  first or last date stays inside it; the dates move with it.

## A chart's own page

`pages/area-chart.php`, `AreaChartDetail`: the chart's name, then the
same chart, large. It has the Verloop's switch (7 dagen, 30 dagen, 90 dagen,
1 jaar), the plot 160 px tall, the reading above it, the legend when there
are two series, and the hint. Nothing else.

It opens over the Slaap page, the one place in Ownify where a detail opens
over another detail. It slides in the same way and goes back the same way:
the back pill, a swipe to the right, system back or Escape. It returns to
Slaap as it was left, with the same scroll and focus. Leaving for another
tab closes both. On the website this is `detail-layer.js` (`parent`, a page
whose `data-detail-parent` names the detail in front); on Android it is
`ShellState.under`.

## The colours

All through the theme, the same in Dark and White Mode:

- Diep: `--sleep`
- REM: `--sleep-light`
- Licht: half of `--sleep`
- Rusteloosheid and Wakker: the text colour (`--ink`), Wakker the brighter

Each stage also has its own row, so none is told by its colour alone.
Tijd in bed's bars are `--sleep` softened; a line alone is `--sleep`, and
beside bars it is `--sleep-light`.

## Where it lives

| | Website | Android app |
| --- | --- | --- |
| The data | `lib/hydrate-sleep.php` → `health.areas.sleep.view` | `SleepView` (`data/AppData.kt`) |
| The night | `components/sleep-night.php`, `assets/js/sleep.js` | `SleepNightCard` |
| The page | `components/sleep-view.php` | `HealthDetail` (`SleepView`) |
| The four charts | `lib/area-charts.php`; `components/area-chart.php` (`chart_mode` mini), `area-charts-grid.php`, read by `compass-history.js`, a tap by `area-charts.js` | `AreaChartsGrid`, `HistoryPlot` (`bars`, `readOnDrag`) |
| A chart's page | `pages/area-chart.php` | `AreaChartDetail` (`Detail.AreaChart("sleep", …)`) |
| Styles | `assets/css/sleep.css`, `area-charts.css` | `ui/screens/health/SleepView.kt`, `AreaCharts.kt` |
| Copy | `config/health.php` (`night`, `charts`, `chart_copy`) | from the server |
| Tests | `tools/sleep-view-test.php` | `SleepViewTest` |

An app from before this keeps the Slaap page it had, from `highlights` and
`timeline`, which the server still sends. Only the rows the charts now
show (Duur en timing, Onderbrekingen, SpO₂, Huidtemperatuur, HRV) are gone
from its groups.

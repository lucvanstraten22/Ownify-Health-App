# Training, drawn

The Training page shows what was done, how often, and how the heart behaved
— as charts, not as rows of numbers. It is the same on the website and in
the Android app. The server works out every position and word
(`lib/hydrate-training.php`), and both apps draw what it sends.

```
Training ──┬── the score                              (unchanged)
           ├── Recente trainingen │ Trainingen per dag
           ├── Stappen + Afstand  │ Actieve + Totale calorieën
           │   Verdiepingen       │ Actieve minuten
           ├── Hartslag            Vandaag | 7 dagen | 30 dagen | 90 dagen | 1 jaar
           ├── HRV                │ Hartbelasting
           ├── Conditie en herstel what no chart shows (VO₂max, rusthartslag, herstel)
           └── Verloop             the training score over time (unchanged)
```

Nothing here is advice, and no value is made up. Every value is one Ownify
recorded. A day without one is a gap, never a 0; a figure a session does
not have is not shown.

## The sessions

**What counts as a session** is Ownify's one definition of a workout, the
one the training score and the points use (`health_workouts_counted()`):

- a recorded exercise session (Health Connect's `ExerciseSession`), never
  the day's ordinary movement — steps on the way to the station are no
  session;
- of 10 minutes to 8 hours (`config/scoring.php`, `workouts`);
- each real session once: two apps that recorded the same run are one run
  (the longer is kept).

**Recente trainingen** lists the latest four, newest first: the kind
("Hardlopen", `health_activity_label()`), the day ("Vandaag", "Gisteren",
"5 okt"), the start time and how long. A tap opens its page.

**Trainingen per dag** counts those sessions per day, as bars. From the
first session on, a day without one is a 0 (none was recorded); before it
there is nothing. Over 90 dagen and 1 jaar a point is the week's or month's
mean per day ("0,6 per dag").

### A session's own page

`pages/training-session.php`, `TrainingSessionDetail`: its kind, its day
and times, then

- **its heart rate** minute by minute, from its start at the left to its
  end at the right, its zones over it — when a heart rate was recorded
  during it;
- **its figures**, only those it has:

| Figure | From |
| --- | --- |
| Duur | the session's own duration (or its start to its end) |
| Afstand, Tempo or Snelheid | the session's own distance and speed; without them, Afstand as the readings during it count it (below) — and then no Tempo or Snelheid, because a phone's steps on a bike ride are no ride's distance |
| Actieve / Totale calorieën, Stappen, Verdiepingen, Actieve minuten | the session's own where it has them; otherwise the readings during it (below) |
| Gem. / Max. hartslag | the session's own; otherwise its minutes' mean and highest |
| Hoogtemeters, Cadans | the session's own |

**The readings during a session** are counted by the rule a day's total is
(`health_metric_window_total()`, `includes/health-totals.php`): a reading
that spans part of the session counts for the share of it that falls in
the session, and each moment once over the apps that recorded it. A reading
that spans more than an hour and more than the session — a day's total
calories, spread evenly over the day — says nothing about the session and
is left out.

## The charts

Ownify's area charts (`lib/area-charts.php`, docs/CHARTS.md), the same
system Slaap's are drawn with: small two by two, each opening its own page
with the chart large over 7 dagen, 30 dagen, 90 dagen and 1 jaar.

| Chart | What it draws | From |
| --- | --- | --- |
| Stappen + Afstand | steps as bars, distance as a line with dots, each on its own height from 0 | each day's total (`health_daily_metric()`), each moment once over the apps |
| Actieve + Totale calorieën | total as bars, active as a line, on one height (both kcal), its levels named | the same |
| Verdiepingen | a line with dots | the same |
| Actieve minuten | a line with dots | each day's `active_minutes` |
| HRV | a line with dots | each day's HRV (the same value Slaap shows) |
| Hartbelasting | a line with dots | each day's `training_load` |

**Actieve minuten and Hartbelasting are empty for a Health Connect user.**
Ownify keeps both as daily values (`active_minutes`, `training_load`), but
Health Connect has no record of either, so nothing sends them today. Their
charts say *Nog geen metingen.* rather than show a figure Ownify would have
to invent; they fill in as soon as a source sends them.

## The heart rate

Health Connect sends heart rate as records of samples. Ownify keeps each
minute's mean, per app (`heart_rate_minutes`, migration 019); two apps'
minute is one, weighed by their samples. The average heart rate *during
sleep* (Slaap's "Hartslag in slaap") is kept as before, from the night's
samples alone.

### Vandaag

One day on the clock, not a span of days (docs/CHARTS.md):

- **always the whole day**, 00:00 at the left edge, 24:00 at the right —
  never from the first measurement;
- **a point per five minutes** that had a heart rate: the mean of its
  minutes, at the middle of the five; a line through points no more than
  20 minutes apart, a dot where one stands alone, a gap where none was
  measured;
- **the hours** every three: 00:00, 03:00 … 21:00;
- **the day's name above it** — Vandaag, Gisteren, Eergisteren, 5 oktober —
  never under it;
- **back over seven days**: the arrows either side of the name (‹ older,
  newer ›), a swipe over the name, or a quick one over the chart (to the
  right an older day). A slow slide over the chart is still a reading.
  Choosing Vandaag again goes back to today. A day without any heart rate
  says *Geen hartslag gemeten op deze dag.*;
- the seven days share one height, so a zone stands at the same place on
  every day.

### 7 dagen to 1 jaar

Each day's average heart rate, on Ownify's time axis: a point per day over
7 and 30 dagen, **a point per day over 90 dagen too** (its dates still every
seventh day), and per month over a year (the month's mean of its days).

**A day's average is the mean of its five-minute means** — the points its
day is drawn with — not of its minutes. A watch that measures every second
during a run and every ten minutes otherwise would otherwise make every
training day's average a training's.

The height is the heart rate's own: its values with a few beats' room, in
steps of 10, 20 or 40 bpm, never from 0.

### The zones

Four zones over every heart-rate chart, a **visual layer only**: no value,
average or history is changed by them. They come from the person's own
heart rate where Ownify knows it:

| Known | Zone 2 from | Zone 3 from | Zone 4 from |
| --- | --- | --- | --- |
| a resting heart rate and a maximum | 30% of the heart-rate reserve | 40% | 60% |
| a maximum only | 57% of the maximum | 64% | 77% |
| neither | — no zones — | | |

- **The heart-rate reserve** (Karvonen) is the maximum minus the resting
  heart rate; a zone begins at resting + that share of the reserve. The
  shares are ACSM's light, moderate and vigorous intensity.
- **The resting heart rate** is the median of the last 30 days' resting
  heart rate (Health Connect's `RestingHeartRate`, which Ownify for Android
  reads from 13.0).
- **The maximum** is `health_hr_max()`, the one the training score uses:
  the highest heart rate recorded in a training, or 208 − 0,7 × age if that
  is higher (Tanaka).
- **Without a resting heart rate** the zones are shares of the maximum: 57%
  is ACSM's light, 64% and 77% are the moderate and vigorous the score
  already uses (`config/scoring.php`, `hr_max_pct`).
- **Without a maximum** — no birth date and no training with a heart rate —
  there are no zones, and the chart says so under it.

This is personal only as far as the data is: the maximum is an estimate
from age unless a training recorded a higher one, and nothing here is a
measured lactate threshold. The line under the zones says what they are
based on ("Op basis van je rusthartslag (55 bpm) en maximale hartslag
(183 bpm).").

**How they show**: the line takes each zone's colour where it runs through
it — quiet grey in zone 1, through a greyed Training colour and the
Training colour, to the clearest, brightest Training orange in zone 4
(`--zone-1` … `--zone-4`, Ownify.zone()); a faint band marks zones 2 to 4
behind it; the zones are named with their ranges under the chart; and the
reading names the zone with the time and the bpm ("14:35 · Zone 3 ·
Hartslag 142 bpm"), so no zone is told by colour alone.

## Health Connect

The heart rate through the day, the total calories, floors, resting heart
rate, HRV and SpO₂ come from Ownify for Android 13.0, which reads five more
record types and sends every heart-rate sample (docs/HEALTH-CONNECT.md).
A phone that granted the earlier seven shows the new ones as not yet
granted until they are asked for (Instellingen → Apparaten → Deze telefoon).
The phone sends the last seven days on each sync, so a week of heart rate
fills in at the first sync after 13.0.

## Where it lives

| | Website | Android app |
| --- | --- | --- |
| The data | `lib/hydrate-training.php` → `health.areas.training.view` | `TrainingView` (`data/AppData.kt`) |
| The page | `components/training-view.php` | `TrainingViewContent` (`TrainingView.kt`) |
| The sessions | `components/training-sessions.php` | `SessionsCard` |
| The charts | `lib/area-charts.php`; `components/area-chart.php`, `area-charts-grid.php` | `AreaChartsGrid`, `AreaChartDetail` |
| The heart rate | `components/heart-chart.php`, `heart-plot.php`, `heart-zones.php`, `assets/js/training.js` | `HeartCard`, `HeartPlotView`, `HeartZonesLegend` |
| A session's page | `pages/training-session.php` | `TrainingSessionDetail` (`Detail.TrainingSession`) |
| Styles | `assets/css/training.css`, `area-charts.css` | `TrainingView.kt`, `AreaCharts.kt` |
| Copy | `config/health.php` (`sessions`, `layout`, `charts`, `chart_copy`, `heart`) | from the server |
| Heart rate kept | `heart_rate_minutes` (migration 019), `health_import_heart_rate()`, `health_heart_minutes()`, `health_heart_days()` | `IngestPayload` (`allSamples`) |
| Tests | `tools/training-view-test.php` | `TrainingViewTest`, `IngestPayloadTest` |

An app from before 13.0 keeps the Training page it had, from `highlights`
and the groups, which the server still sends. Only the rows the charts and
the sessions now show (Activiteit, Trainingen) are gone from its groups.

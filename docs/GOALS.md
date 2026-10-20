# Goals

Doelen, on both apps: what a goal can be about, how it is made, how far along
it is, and how it is stored. Introduced in **22.0** (migration 025), which
replaced the earlier goal model entirely.

| Part | Where |
| --- | --- |
| The vocabulary: categories, measurements, units, types, periods, looptijden, all copy | `config/goals.php` |
| The same, pure: units, dates, periods, the Mijlpaal formula | `includes/goal-catalogue.php` |
| Making a goal: every rule the wizard, the assistant and the setup share | `includes/goal-create.php` |
| Where a goal stands: reading data, the three calculations, finishing | `includes/goal-progress.php` |
| The board's rules: the limit, one primary, pause/resume, delete | `includes/goals.php` |
| What the pages and the app read | `lib/hydrate-goals.php`, `lib/goals.php`, `lib/goal-chart.php` |
| The wizard on the website | `components/goal-wizard.php`, `assets/js/goal-wizard.js`, `assets/css/goals.css` |
| The wizard in the app | `OwnifyAndroid/…/ui/screens/goals/GoalWizard.kt` |
| The tables | `database/migrations/025-goals-rebuild.sql`, `database/schema.sql` |
| Tests | `tools/goal-engine-test.php`, `tools/goals-test.sh`, `GoalWizardFlowTest.kt` |

Nothing is worked out in a browser or on the phone. The server stores where a
goal stands and both apps show that; the wizards only repeat the date and unit
arithmetic so the review screen says exactly what will be stored.

## The wizard

Three numbered steps and a review, on both apps alike. The indicator counts
the three steps; the review has no number. Every screen ends in the same pair:
a small round glass back button and one glass primary button.

1. **Meting** — a search field, six category bubbles and a short list that
   scrolls on its own (about five rows). Before anything is searched or chosen
   the list shows the popular measurements (`popular` in the config). A bubble
   filters the list; tapping it again lets it go. Search ignores accents
   ("efficientie" finds Slaapefficiëntie) and searches labels and keywords in
   both languages. Choosing a measurement highlights it and does not move on;
   **Volgende** stays disabled until one is chosen. **Eigen gewoonte** (at the
   end of the list, and always there when a search finds nothing) asks for its
   category with the same bubbles before it can move on. Each row says whether the goal will be read automatically
   or kept by hand.
2. **Doel** — the name (with example names for the chosen measurement as
   chips) and three type cards — **Mijlpaal**, **Streak**, **Optellen** —
   each with its explanation and an example for this measurement. A type the
   measurement cannot be is shown disabled ("Kan niet met gewicht"). A
   measurement with one type has it chosen for you.
3. **Instellen** — what it takes: a number with its unit dropdown (always
   there), or for a Streak per periode how many times per week, maand,
   kwartaal or jaar. Which way is better, as two round icon buttons (up,
   down) — only where both make sense; a long press explains each and chooses
   nothing. A Mijlpaal's starting value: measured (and shown) when there is
   data, typed when there is none. Then the period: **Start** (today, or
   earlier — up to a year) and **Einde**. Tapping Start opens the calendar for
   the start; **Gereed** moves on to the end's calendar. Five looptijden
   (1 week, 1 maand, 3 maanden, 6 maanden, 1 jaar) fill in the end date,
   which stays editable. The end date is required and lies after the start.
- **Controleren** — everything at a glance, read-only, and **Doel aanmaken**.
  The button shows a spinner while saving and cannot send twice: the wizard
  sends a one-time key (`create_token`), and the server answers a repeat with
  the goal it already made. An error is shown above the buttons and the wizard
  stays open with every answer.

**Back** keeps every answer, on every step; back on step one closes the
wizard. After **Doel aanmaken** the wizard closes onto Doelen, on Actief,
with the new goal's card in view and briefly marked.

The surfaces are the floating glass of the sync capsule (`.glass`,
`components.css`; `Modifier.glass`, `GlassControls.kt`) at the size of the
Health Connect data chips. A chosen bubble or card is a quiet green tint and
rim, no checkmark.

## What a goal can be about

Six categories: **Slaap**, **Voeding**, **Training**, **Activiteit**,
**Lichaam**, **Herstel & balans**. Every measurement belongs to one; an
**Eigen gewoonte** picks its own.

| Measurement | Code | Category | Read from | Stored in | Shown in | Types | Better |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Slaapduur | `sleep_duration` | Slaap | metric `sleep_duration` | min | h, min | Mijlpaal, Streak elke dag | either (default higher) |
| Slaapefficiëntie | `sleep_efficiency` | Slaap | metric `sleep_efficiency` | pct | pct | Mijlpaal, Streak elke dag | higher |
| Water | `water` | Voeding | metric `water` | l | l, ml, floz | Streak elke dag, Optellen | higher |
| Calorieën | `energy` | Voeding | metric `energy` | kcal | kcal, kj | Streak elke dag | either (default lower) |
| Eiwit | `protein` | Voeding | metric `protein` | g | g | Streak elke dag | higher |
| Koolhydraten | `carbs` | Voeding | metric `carbs` | g | g | Streak elke dag | either (default lower) |
| Vet | `fat` | Voeding | metric `fat` | g | g | Streak elke dag | either (default lower) |
| Vezels | `fibre` | Voeding | metric `fibre` | g | g | Streak elke dag | higher |
| Suiker | `sugar` | Voeding | metric `sugar` | g | g | Streak elke dag | lower |
| Verzadigd vet | `saturated_fat` | Voeding | metric `saturated_fat` | g | g | Streak elke dag | lower |
| Natrium | `sodium` | Voeding | metric `sodium` | mg | mg | Streak elke dag | lower |
| Voedingsbeoordeling | `nutrition_rating` | Voeding | metric `nutrition_rating` | score10 | score10 | Mijlpaal, Streak elke dag | higher |
| Trainingen | `workouts` | Training | workout `sessions` | sessions | sessions | Streak per periode, Streak elke dag, Optellen | higher |
| Trainingsminuten | `workout_minutes` | Training | workout `minutes` | min | min, h | Streak elke dag, Optellen | higher |
| Stappen | `steps` | Activiteit | metric `steps` | steps | steps | Mijlpaal, Streak elke dag, Optellen | higher |
| Afstand | `distance` | Activiteit | metric `distance` | km | km, mi | Mijlpaal, Streak elke dag, Optellen | higher |
| Actieve calorieën | `active_energy` | Activiteit | metric `active_energy` | kcal | kcal, kj | Streak elke dag, Optellen | higher |
| Verdiepingen | `floors` | Activiteit | metric `floors` | floors | floors | Mijlpaal, Streak elke dag, Optellen | higher |
| Gewicht | `weight` | Lichaam | measurement `weight` | kg | kg, lb | Mijlpaal | either (default lower) |
| Vetpercentage | `body_fat_pct` | Lichaam | measurement `body_fat_pct` | pct | pct | Mijlpaal | either (default lower) |
| Tailleomtrek | `waist_cm` | Lichaam | measurement `waist_cm` | cm | cm, in | Mijlpaal | either (default lower) |
| Vetvrije massa | `lean_mass` | Lichaam | measurement `lean_mass` | kg | kg, lb | Mijlpaal | either (default higher) |
| Rusthartslag | `resting_hr` | Herstel & balans | metric `resting_hr` | bpm | bpm | Mijlpaal, Streak elke dag | lower |
| HRV | `hrv` | Herstel & balans | metric `hrv` | ms | ms | Mijlpaal, Streak elke dag | higher |
| Hartslag in slaap | `sleeping_hr` | Herstel & balans | metric `sleeping_hr` | bpm | bpm | Mijlpaal, Streak elke dag | lower |
| Meditatie | `meditation` | Herstel & balans | by hand | min | min, h | Streak elke dag, Streak per periode, Optellen | higher |
| Eigen gewoonte | — | chosen | by hand | its unit | times, min, h, pages, glasses, reps, km, mi, kg, lb, or its own word | all | either (default higher) |

Why some types are missing: a weight cannot be added up and a day's calories
are no milestone; only what can be counted — trainingen, Meditatie, an Eigen
gewoonte — can be done "a number of times per week". The direction is only
asked where both make sense; a resting heart rate is always better lower.

The config and the database tables `goal_categories`, `goal_measurements` and
`goal_units` say the same; `tools/goal-engine-test.php` checks they agree.

## Units

A goal's figures are **stored** in the measurement's unit (kg, min, l, km…)
and **shown** in the goal's own unit (`goals.unit_code`), chosen in step 3:
stored = typed × factor (176 lb × 0.45359237 = 79.83 kg). The unit starts as
the reader's: Imperiaal (Instellingen › Eenheden) starts weight in lb,
distance in mi, waist in in, water in fl oz; metric in kg, km, cm, l. Changing
the unit in the wizard converts what was typed (80 kg becomes 176,4 lb). A
goal keeps its unit afterwards — switching Eenheden later changes nothing
about an existing goal. Nothing stored is ever converted.

A typed figure is read the way the reader writes it (`goal_parse_number()`,
`parsed()` in goal-wizard.js, `goalNumber()` in GoalWizard.kt): "72,5" and
"72.5" are both 72,5, and a whole number grouped in threes the reader's way is
thousands — "10.000" in Dutch, "10,000" in English — as the wizard's own
examples write it.

## Dates

- The start date is today by default and can be earlier, up to 365 days back
  (the server takes tomorrow too, for a phone a time zone ahead). The end
  date is required, after the start, not before today, and at most three
  years after the start.
- A looptijd ends on the day **before** the same date a week, month… later:
  1 week from Monday 6 januari ends Sunday 12 januari; 1 maand from 1 maart
  ends 31 maart.
- Calendar months are clamped to the month's last day and always counted from
  the original date, so they never drift: 31 januari + 1 maand = 28 (or 29)
  februari, + 2 maanden = 31 maart. 1 maand from 31 januari therefore ends
  27 februari (28 february is where the next month begins), in a leap year
  28 februari. 29 februari + 1 jaar = 28 februari.
- PHP (`goal_add_months()`), the website (`addMonths()`) and the app
  (`LocalDate.plusMonths`, which clamps the same way) give the same end date to
  the day; both test suites pin it.

## How far along a goal is

One calculation per type, on one figure per day (`goal_day_values()`), so a
goal read from health data and one kept by hand are worked out by the same
code. A day without data is absent, never 0 — except workouts, where a day
without one really is a day with none.

**Mijlpaal** — from the baseline to the target, by the **latest** value:

    percent = (latest − baseline) / (target − baseline) × 100, bounded 0–100

80 → 100 kg at 90 is 50%; back at 85 a week later, 25%. Lower is better the
same way (90 → 80 at 85 is 50%). Below the start is 0%, past the target 100%.
A target equal to its baseline is refused when made; if one ever exists, it is
100% when reached and 0% otherwise — never a division by zero. The baseline is
fixed when the goal is made: the measurement on the start date, else the most
recent one before it (up to a year back), else what the person typed. For a
day's total that is still building (today's steps), today counts once it has
passed yesterday's, and a start of today takes yesterday's whole day as its
baseline. 100% finishes the goal at once.

**Streak, elke dag** — each day from the start is met or not:

    percent = days met / all days of the period × 100

A missed day is just a missed day; the goal carries on. Kept by hand, a day is
met when it is ticked off; read from data, when the day's figure reaches the
daily target (at least, or at most). Today is "open", not missed, until it is
over. The percentage only grows, and on the end date it is the final share. It
never finishes early — 100% so far is not the end of the period.

**Streak, per periode** — weeks, months, quarters or years counted **from the
start date** (week 1 is the goal's first seven days, not a calendar week).
Each period is met as soon as its count is reached — even before it is over —
and missed only once it is over without. A last period the end date cuts short
asks its share, rounded half up: 3 per week over the last 3 days of a goal is
3 × 3/7 = 1,29 → 1. A share that rounds to 0 asks nothing and counts neither
way. A goal whose periods ask nothing at all is refused when made.

    percent = periods met / periods that ask something × 100

**Optellen** — everything inside the period added up from zero:

    percent = total since the start / target × 100, bounded 0–100

What came before the start date never counts. 100% finishes it at once.

### Finishing

A Mijlpaal or Optellen finishes the moment it reaches 100%; every goal
finishes once its end date has passed, with what it had. Finished is
`completed` (100%) or `ended` (below). The final percentage is stored in
`final_pct` together with the moment in `completed_at`, in one statement that
only matches a goal still running (`goal_finish()`), so it can never be
overwritten: a later measurement, a recalculation, a second finish change
nothing. A finished goal takes no entries and cannot be paused.

### Read automatically, or kept by hand

A goal is read automatically only when the person really has data for it: any
reading of that measurement in the last 90 days at the moment it is made
(`goal_user_has_data()`); the wizard says so per measurement. Otherwise — and
always for Meditatie and an Eigen gewoonte — it is kept by hand, and only on
the goal's own page:

| Type | What the page asks |
| --- | --- |
| Mijlpaal | a new measurement — the latest counts |
| Streak | yes or no for today (Vandaag gedaan, undo) — once a day |
| Optellen | an amount to add |

Entries are for a day within the goal's period and not after today. A goal
made by hand stays by hand, even when data arrives later.

## The board

Doelen has two tabs: **Actief** (running and paused goals, one of them
primary) and **Afgerond** (reached and ended goals). Afgerond is sorted by
final percentage, highest first, then the newest goal first. A reached goal
wears its award; an ended one does not. The first goal made is primary on its
own; when the primary goal finishes or is deleted, the first of Secundaire
doelen takes its place. Up to five active goals (`limits` in the config);
finished goals do not count.

## The tables

Migration **025** (`database/migrations/025-goals-rebuild.sql`) touches only
goal tables. On a database with the old `goals` it first copies the old goals
and progress to `goals_backup_024` and `goal_progress_backup_024` (plain
copies, no keys, to look back at or drop), then drops the old `goal_progress`
and `goals` and creates:

| Table | Holds |
| --- | --- |
| `goal_categories` | the six categories, in order |
| `goal_units` | every unit a goal is shown in: the unit it is stored as, and the factor |
| `goal_measurements` | what a goal can be about: category, source, stored unit, direction, which types |
| `goals` | one row per goal: what (`category_code`, `measurement_code`), how (`goal_type`, `streak_variant`, `tracking`, `unit_code` or `custom_unit`, `direction`, `target_value` or `frequency` + `period_unit`), its baseline (`baseline_value`, `_on`, `_source`), its period (`start_date`, `end_date`), where it stands (`current_value`, `progress_pct`, `progress_at`), and once finished `final_pct` and `completed_at`; `create_token` for the one-time key |
| `goal_entries` | what was logged by hand on the goal's page (NULL value: a day ticked off) |
| `goal_day_results` | a daily streak's days: met, missed, unknown, pending |
| `goal_period_results` | a per-period streak's periods: from, to, required, achieved, state |
| `goal_progress` | one snapshot per goal per day, for the Verloop |

The database enforces the model itself: foreign keys to the vocabulary and to
`users` (deleting an account deletes its goals and everything under them),
and CHECKs that a streak has its variant, a per-period streak its frequency, a
goal either a unit code or its own word, a Mijlpaal its baseline, a finished
goal its final percentage and moment (and a running one neither), the end
after the start, and percentages within 0–100. `UNIQUE (user_id,
create_token)` makes a repeated create impossible. Re-running the migration is
safe: a database that already has the new `goals` keeps it and its rows.
`database/schema.sql` has the same tables for a fresh install.

## The endpoints

| Endpoint | Does |
| --- | --- |
| `api/goals/create.php` | makes a goal (fields below); answers `goal_id` |
| `api/goals/baseline.php` | a Mijlpaal's measured start for a measurement and start date, or none |
| `api/goals/progress.php` | logs on a goal kept by hand: `value` (typed in the goal's unit) or `done` = 1/0, and an optional `date`; answers the new `percent` and `status` |
| `api/goals/update.php` | pause, resume, primary, secondary — never finish |
| `api/goals/delete.php` | deletes a goal and everything under it |

`create.php` takes `name`, `measurement` (a code or `custom`), `category` (an
Eigen gewoonte's), `type`, `variant`, `unit` (or `custom` and `custom_unit`),
`target_value`, `direction`, `frequency` and `period` (per-period streak),
`start_date`, `end_date` or `duration`, `baseline_value` (only when nothing
was measured) and `create_token`. Every rule is in
`goal_create_from_input()`, which the assistant's `create_goal` and the
setup's suggested goal use as well, so nothing can make a goal the wizard
would refuse.

## Decisions worth knowing

- A paused goal keeps its place and its period runs on; its progress is still
  worked out, and it can still finish.
- A goal can no longer be "completed" by hand (the old *Doel afronden*): it
  finishes by its own rules.
- The old goals could not be carried over — their types, categories and
  sources mean other things now — so 025 starts goals afresh and keeps the old
  rows in the two backup tables.

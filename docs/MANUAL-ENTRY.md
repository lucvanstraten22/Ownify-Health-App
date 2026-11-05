# Sleep and training entered by hand (40.0)

Slaap and Training each have their own floating **+**. It opens that area's
own entry page over it. The two pages share one layout and one script: the
same intro, one card of fields, a note, and Annuleren | Opslaan. Only the
fields and their words differ. A training's own page also gets Doelen's
**delete** with its confirmation.

| Where | What |
| --- | --- |
| `database/migrations/030-manual-entry.sql` | `sleep_sessions.corrected_at`, `health_import_removed` (rollback: `030-rollback.sql`) |
| `includes/health-manual.php` | entering a night, entering a training, removing a training, what the pages get |
| `config/manual-entry.php`, `lang/en/manual-entry.php` | the words, and the activity types |
| `api/health/sleep-entry.php`, `training-entry.php`, `training-delete.php` | website (session + CSRF) and app (account token) |
| `pages/manual-entry.php`, `assets/js/manual-entry.js` | the website's two pages |
| `pages/health-detail.php`, `pages/training-session.php` | the + and the delete |
| `OwnifyAndroid/…/screens/health/ManualEntry.kt` | `ManualEntryPage`, `EntryFab`, `RemoveTraining` |
| `includes/health-score.php` | `health_score_restore_days()`: a past day's recorded scores |
| `tools/manual-entry-test.php`, `ManualEntryFlowTest.kt` | the tests |

Before migration 030 nothing is offered (`health_manual_ready()`): no + and
no delete.

## A night

- **Which night.** The date is the **morning** the person got up, because
  Ownify files a night under the morning it ended. A bedtime later in the day
  than the wake-up time was the evening before (23:30 → 07:00). An earlier
  one is the same day (01:15 → 08:30, or a nap 14:00 → 14:40). The page shows
  the night it makes as the fields are typed, for example "di 3 nov 23:00 tot
  wo 4 nov 07:00 · 8:00 u".
- **A night already there** (`health_night_on()`, the main sleep of that
  morning) keeps its row, source and measurements: stages, minutes per stage
  and awakenings. Only its times change, plus what follows from them:
  - time in bed is the new period;
  - asleep is the new period less the time awake the device measured;
  - efficiency follows, where it had one.
- **Nothing there:** a new row with the `manual` source.
- **Marked as the person's.** Either way `corrected_at` is set, so the night
  becomes the person's.
- **Validation.** A future night, two equal times, and a bedtime that clashes
  with another sleep's start are refused.

**Sync.** `health_import_sleep()` treats a corrected night like this:

- Its own record, sent again, keeps the person's times. It still brings the
  device's measurements (stage minutes, stages).
- Any other recording that overlaps it is left out, so a sync never puts the
  device's version beside the person's.
- When a night is chosen from several recordings, a corrected one comes first
  (`health_night_main()`).

## A training

- **Fields.** Date, activity, start time, duration (1–1440 min), and calories
  (optional, stored as active kcal), with the `manual` source.
- **Activity types** are Health Connect's own names: the ones a synced
  training is stored under (`ExerciseSessionRecord`, `IngestPayload.kt`). So
  the training reads like a synced one: its label, the score and the points.
- **Refused:** a training that overlaps any training already recorded, from
  any source. The message is "Er staat al een activiteit geregistreerd op dit
  tijdstip." (409). Nothing is merged, overwritten or deleted.

## Heart rate

Only recorded heart rate counts: `heart_rate_minutes` within the entered
period.

- **A training** stores the mean and the highest minute (`avg_hr`,
  `max_hr`). Its page draws the minutes as for any training.
- **A night** gets their mean as a `sleeping_hr` reading on it, with source
  `derived` and external id `sleep:<id>`. It is replaced when the night is
  corrected again, and removed when no minutes fall in it any more.

No minutes means no heart rate. Nothing is estimated.

## Removing a training

- **How.** On its own page, Doelen's delete with its confirmation, then the
  row goes (`health_manual_remove_workout()`, the person's own only).
- **What it cannot do.** Ownify cannot delete anything in Health Connect or
  Polar. A synced training is removed from Ownify only, and the confirmation
  says so.
- **Kept removed.** Its source and id go into `health_import_removed`, so the
  next sync, which sends the last days again, leaves it out
  (`health_import_workout()`).
- **Recalculated.** Its points go, and its week's bonus is worked out again
  from the trainings left.

## After every change

The same things run as after a sync:

- `goal_refresh_all()`
- `points_process()`
- `health_score_refresh()`
- `insights_note_change()`

The website reads the page again and opens on the area it came from. The
app reads the state again (`OwnifyActions.form`) and goes back to the area.

For an entry, correction or removal on a day that has passed,
`health_score_restore_days()` also works out that day's recorded Sleep,
Training and overall scores (`daily_scores`) again, as at the end of that
day, so the Scorekompas, the Verloop and the history show them. The days
after it whose 7 days reach back over it (at most six, never today) are
worked out again too, because their scores include that day. A day whose
scores come out the same is not written. Nothing before the date changes.
Any other day that has passed keeps the score it had, by design.

**Krachttraining.** Health Connect's `strength_training`, entered or synced,
counts as strength (`strength_types` in `config/scoring.php` and
`config/insights.php`), alongside weightlifting and calisthenics.

## Limits

- Deleting works on the trainings that have a page of their own: Training's
  latest sessions, as before.
- When a night is several recorded parts, a correction changes its main
  part. The other parts stay and still join the night.

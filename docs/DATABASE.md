# Database and accounts

MySQL foundation for the app: accounts, profiles, health data, goals,
friendships and leaderboards. The pages read from it — there is no placeholder
data left anywhere in the application, and an account with no records renders
its empty state rather than an example.

**How a page gets its data.** The files in `config/` describe the *shape* of a
page — which areas exist, what they are called, in what unit. The values come
from the signed-in user's own rows, filled in by `lib/hydrate-health.php`,
`lib/hydrate-goals.php` and `lib/hydrate-community.php`, which `index.php`
calls with the id from the session. A key nothing fills stays `null`, and
`null` is what the components already draw as an empty state.

**Scores.** Every score in the app comes out of `includes/scoring.php`. The
overall score is the average of whichever of sleep, nutrition and training
that day actually has: a missing pillar is skipped, never counted as a zero,
and when all three are missing there is no score rather than a `0`. Moving to
a weighted average is a change to `score_combine()` and nothing else.

## Setting it up (WampServer)

1. Start Wamp, open **phpMyAdmin**.
2. Import `database/schema.sql`. It creates the `jolu` database and every
   table, and can be re-imported at any time to rebuild from scratch.
3. Optional: import `database/seed-dev.sql` for fake development data.
4. Credentials default to Wamp's `root` with no password. To change them,
   create `config/database.local.php` (git-ignored) returning only what you
   want to override, or set `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD`:

   ```php
   <?php // config/database.local.php
   return ['username' => 'jolu', 'password' => 'secret'];
   ```

Until the schema is imported the app still runs: it renders signed out, and
the account panel says the database is unreachable instead of erroring.

### Coming from a `vitalis` database

The app used to be called Vitalis and its database was named accordingly. If
you already imported the old schema, either re-import `schema.sql` (it now
creates `jolu`) and drop `vitalis`, or, to keep the accounts you already have,
rename it in phpMyAdmin: select `vitalis` → **Operations** → *Rename database
to* → `jolu`. The session cookie is now `jolu_session`, so everyone signs in
again once either way.

## The tables

**Identity**

| Table | Holds |
| --- | --- |
| `users` | the account: id, unique username, status, timestamps |
| `user_profiles` | the person: name, date of birth, gender, avatar, locale |
| `user_auth_identities` | one row per way of signing in |

**Reference**

| Table | Holds |
| --- | --- |
| `data_sources` | manual / Apple Health / Health Connect / wearable / derived |
| `health_metric_types` | the metric catalogue: code, label, unit, domain, how to roll up |

**Health — private**

| Table | Holds |
| --- | --- |
| `user_measurements` | height, weight and other body values, with history |
| `sleep_sessions` | one night: timing, duration, efficiency, stage totals |
| `nutrition_entries` | one meal or drink |
| `workouts` | one training session, every column nullable |
| `workout_hr_zones` | seconds per heart-rate zone |
| `health_metrics` | every scalar reading, whatever its frequency |
| `daily_scores` | where the future scoring engine writes its results |

**Goals — private**

| Table | Holds |
| --- | --- |
| `goals` | name, category, type, target, direction, dates, status |
| `goal_progress` | dated snapshots, for the chart and for manual goals |

**Community**

| Table | Holds |
| --- | --- |
| `friendships` | one row per pair, symmetric, with a status |
| `user_blocks` | one-directional blocks |
| `point_rules` | what earns points — **empty**, no formula is decided |
| `point_events` | the ledger of awards |
| `user_period_points` | points per period, plus the national position |
| `leaderboard_best_positions` | best rank ever reached |

## Relationships worth knowing

**Height and weight are not profile columns.** They change, and overwriting
them would destroy the history, so they are rows in `user_measurements`. The
current value is the newest row; every earlier one stays for charts.

**Age is not stored.** It is derived from `date_of_birth` by `user_age()`, so
it cannot drift.

**One readings table, one catalogue.** `health_metrics` holds every scalar
value and `health_metric_types` says what each one means. Adding a nutrient or
a new wearable metric is an INSERT into the catalogue, not a migration. A
reading can point at a `sleep_session_id`, a `workout_id` or a
`nutrition_entry_id` — that is how a nutrient hangs off a meal and an overnight
heart rate off a night, without either needing its own column.

**Friendship is one row, not two.** The pair is stored in a fixed id order
(`user_low_id < user_high_id`), so the unique key makes a mirrored duplicate
impossible; `requested_by` records who asked. There is no follow.

**Blocking is separate from friendship** because it is one-directional: A can
block B without B blocking A. A block wins over any friendship row, and
`friend_ids()` filters both directions out.

**There is no limit on friends.** The 50 is a *leaderboard* limit, applied when
a board is read.

## Privacy

Two classes of data, and the boundary is deliberate.

| Public — other users may see | Private — owner and authorised services only |
| --- | --- |
| `users.username` | every health table |
| `user_profiles.avatar_path` | `user_measurements` |
| `user_period_points.points`, `.position` | `goals`, `goal_progress` |

The rules:

- Every function in `includes/health-data.php` and `includes/goals.php` takes
  the authenticated user id as its **first argument** and every statement
  filters on `user_id = ?`. There is no function that reads one user's health
  data on behalf of another, and none should be added.
- Anything that renders *another* person — a leaderboard row, a friend search —
  goes through `user_public_profile()`, which returns username and avatar and
  nothing else.
- No endpoint accepts a user id from the request. `api_require_user()` takes it
  from the session, so a crafted request cannot ask for someone else's records.
- The frontend enforces nothing. It is the server functions above that do.

**What this does not claim.** MySQL grants are per connection, not per end
user: the application's database account can read every table, and so can a
database administrator. Privacy here is an *application* boundary, not an
encryption or a permission one. Do not build an admin feature that selects
from a private table across users — admin-facing user information should stay
within username, email, age, avatar, activity level and creation date.

## Authentication

An account is not defined by how you sign in. `user_auth_identities` holds one
row per method, so connecting Google to an existing account later is an INSERT.

```
users (1) ──< user_auth_identities
                 ├── provider 'email'   subject = email,  password_hash set
                 ├── provider 'apple'   subject = Apple 'sub'
                 └── provider 'google'  subject = Google 'sub'
```

Email/password is implemented: `password_hash()` on the way in,
`password_verify()` plus `password_needs_rehash()` on the way out, a generic
error for both an unknown address and a wrong password, and
`session_regenerate_id(true)` on login. No provider password or token is
stored, ever.

**Apple and Google are not implemented, and nothing pretends they are.**
`auth_provider_available()` returns false for both, the buttons render
disabled, and `api/auth/oauth.php` answers 501. To implement one:

1. Redirect to the provider and handle the callback.
2. **Verify the ID token server-side** — signature against the provider's
   public keys, plus issuer, audience, nonce and expiry.
3. Only then call `auth_link_identity($userId, $provider, $verifiedSub)`.
4. Flip `auth_provider_available()` for that provider.

Step 2 is the whole security of the flow. `auth_link_identity()` trusts its
arguments, so calling it with an unverified `sub` hands out accounts.

## Where the future work goes

**Apple Health / Health Connect sync.** Write an importer that calls
`health_record_metric()`, `user_record_measurement()` and inserts into
`sleep_sessions` / `workouts` with the matching `data_sources` row. Nothing in
the schema needs to change: a metric the platform reports that we do not know
yet is a new `health_metric_types` row. Add the importer under `includes/` as
its own file; keep the raw platform payloads out of these tables.

**The points system.** Decide the rules, insert them into `point_rules`, and
have the engine call `points_award($userId, $points, $ruleCode, ...)` for each
qualifying event. Then call `leaderboard_rebuild($periodType)` to roll the
ledger up. Nothing in the UI or the leaderboard functions changes — they read
points, they never compute them.

**Scores.** `daily_scores` holds a recorded per-domain score, and a recorded
score always wins over a derived one — that is where an importer or a future
scoring engine writes. When nothing is stored, `includes/scoring.php` derives
the day's score from the readings the user actually has (v1: sleep against
eight hours tempered by efficiency, nutrition from the 1-10 rating, training
from active minutes with steps as a fallback). Those three targets are the
only judgement in the file and they are constants at the top of their
functions. The overall score is never stored: it is the average of the
pillars that exist, computed on every render so it cannot disagree with them.

**The assistant.** It should read a user's own data through the same
`includes/health-data.php` functions, with the id from the session. It must
never be handed another user's records.

## Leaderboards

Points live in one ledger and everything else is derived from it.

```
point_events  ──rollup──>  user_period_points  ──read──>  boards
   (truth)                  (points + national position)
```

`period_key` is `'2026-04'` for a month, `'2026'` for a year, `'all'` for
all-time.

- **National top 50** — `leaderboard_national()` reads `user_period_points`
  ordered by the stored `position`, using `idx_board_position`.
- **Friends top 50** — `leaderboard_friends()` is *not* stored: a friends
  ranking depends on who is asking, so it is derived by joining the rollup to
  that user's accepted friendships and ranking within the group. Cheap, and
  always correct.
- **Your own position** — `leaderboard_position()` reads your row directly,
  whether you are 7th or 1180th.
- **Best ever** — `leaderboard_best_positions` is a separate concept from the
  current position and from points. Lower is better, and
  `leaderboard_note_best_position()` only ever lowers it.

`leaderboard_rebuild()` reconstructs the rollup from the ledger, so the rollup
is a cache: losing it costs nothing.

## Development data

`database/seed-dev.sql` is **entirely fake**. Accounts are named `dev_*`, email
addresses use the reserved `example.invalid` domain, and the numbers exist only
to exercise the tables. It is never loaded automatically and must never reach a
production database.


## What writes to the database

Every one of these takes the user id from the **session**, never from the
request, and every statement behind them carries `user_id = ?`. An id in a
request only ever names *another* person (a friend, a block) — never whose
private data is returned.

| Endpoint | Writes |
| --- | --- |
| `api/auth/register.php` | `users`, `user_profiles`, `user_auth_identities` |
| `api/auth/login.php` / `logout.php` | session only; touches `last_login_at` |
| `api/profile/username.php` | `users.username` |
| `api/profile/avatar.php` | `user_profiles.avatar_path` + the file under `uploads/` |
| `api/profile/update.php` | names, activity level, and height/weight as `user_measurements` |
| `api/profile/onboarding.php` | `date_of_birth`, `gender` — once, then it refuses |
| `api/health/sleep.php` | `sleep_sessions` |
| `api/health/nutrition.php` | `nutrition_entries` + the rating and nutrients as `health_metrics` |
| `api/health/training.php` | `workouts` |
| `api/goals/create.php` | `goals`, subject to three active and one primary |
| `api/goals/update.php` | pause, resume, complete, re-prioritise |
| `api/goals/delete.php` | deletes the goal and its history |
| `api/goals/progress.php` | `goal_progress`, and completes a goal that reaches its target |
| `api/friends/search.php` | reads only, and only public fields |
| `api/friends/request.php` | `friendships` |
| `api/friends/block.php` | `user_blocks` |

Height and weight are not columns on the profile. Saving either **adds a row**
to `user_measurements`, so last month's weight is still there; the current
value is simply the newest row.

## What is not connected yet

Honest list, so nobody goes looking for wiring that is not there.

- **Apple and Google sign-in.** No OAuth flow exists and none is faked.
  `api/auth/oauth.php` answers `501`, the buttons render disabled, and
  `auth_link_identity()` is ready for a verified `sub` when someone implements
  the flow. See *Where the future work goes*.
- **Points, and therefore both leaderboards.** `point_rules` ships empty
  because the rules are a product decision nobody has made. Until something
  awards points, the boards show their empty state. The plumbing either side
  of that decision is finished.
- **App preferences** — theme, language, units, first day of the week,
  accessibility, notifications. These have no columns and no endpoints; the
  settings screens say so rather than pretending. Profile data on those same
  screens *is* persisted.
- **Entry screens for sleep and training, and the nutrition slider.** The
  endpoints and the tables are complete and tested, but the app has no UI that
  posts to them yet — building those screens is design work, not wiring.

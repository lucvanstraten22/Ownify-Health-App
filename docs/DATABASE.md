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

## Secrets on the server

Three things must exist on a machine running the app and must never exist in
the repository: the database password, any OAuth client secret, and
`JOLU_APP_KEY`. All three follow the same pattern — a git-ignored file next to
a tracked `.example` that shows the shape, or an environment variable that
wins over the file.

| Secret | File | Environment | Needed for |
| --- | --- | --- | --- |
| Database | `config/database.local.php` | `DB_HOST` `DB_NAME` `DB_USER` `DB_PASSWORD` | everything |
| App key | `config/app.local.php` | `JOLU_APP_KEY` | storing OAuth tokens |
| Google client | `config/integrations.local.php` | `GOOGLE_HEALTH_CLIENT_*` | the Google Health cloud source |

Check what a machine actually has, without printing any of it:

```bash
php tools/check-config.php
```

It reports the PHP extensions, the database settings in force and whether they
connect, the state of the key and where it came from, and which sources are
configured. It prints no passwords and no keys — not even a prefix — so its
output is safe to paste somewhere when asking for help. It exits non-zero when
something essential is missing, so a deploy step can fail on it.

### `JOLU_APP_KEY`

32 bytes of randomness, base64-encoded, used by `includes/crypto.php` to
encrypt OAuth tokens before they go in the database. The key lives outside the
database, which is the entire point: a stolen dump is then worth nothing.

```bash
php tools/check-config.php --generate-key
```

The app looks in three places, first one wins:

1. `getenv('JOLU_APP_KEY')` — the process environment
2. `$_SERVER['JOLU_APP_KEY']` — Apache `SetEnv`, and several FastCGI setups
3. `config/app.local.php`, returning `['app_key' => '...']`

A request header could never be mistaken for the key: headers reach PHP with an
`HTTP_` prefix, so the most a caller can set is `HTTP_JOLU_APP_KEY`, which
nothing reads.

**Without it the app still runs.** Signing in, health data, goals, and the whole
Health Connect pairing and sync flow need no key at all — a device token is
hashed, not encrypted. What refuses is anything that would have to *store* an
OAuth token: it declines to connect rather than writing the token in the clear,
and the devices screen says the server cannot store tokens safely yet. Every
request also writes one line to the server's error log saying the key is
missing, once per PHP worker.

**Losing or changing it** makes every token encrypted with the old key
unreadable, and everyone affected has to reconnect their source. There is no
recovery, by design. Rotating it is a deliberate job — decrypt with the old key
and re-encrypt with the new one, or accept that everyone reconnects.

### Where to put it on Hestia

**The recommended way — `config/app.local.php`.** It is the same mechanism as
`config/database.local.php`, which already works on this server, and it needs
no Hestia configuration at all. It is git-ignored, so it is not in the
checkout the deploy uploads; and the deploy only adds and overwrites files, so
a file the deploy does not carry is left alone. Create it once over SSH or in
Hestia's File Manager, in the web root beside `index.php`:

```bash
cd /home/<hestia-user>/web/<your-domain>/public_html
cp config/app.local.php.example config/app.local.php
php tools/check-config.php --generate-key        # copy the line it prints
nano config/app.local.php                        # paste it as 'app_key'
php tools/check-config.php                       # must say: set and usable
chmod 600 config/app.local.php                   # only the site's user reads it
chown <hestia-user>:<hestia-user> config/app.local.php
```

`config/` is under the document root, so also confirm the file cannot be
fetched — a `.php` file returns nothing useful when executed, but check anyway:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://<your-domain>/config/app.local.php
```

**The alternative — a PHP-FPM environment variable.** Hestia gives each web
domain its own PHP-FPM pool, at
`/etc/php/<version>/fpm/pool.d/<your-domain>.conf`. Adding a line there works:

```ini
env[JOLU_APP_KEY] = "the-base64-key"
```

followed by `systemctl reload php<version>-fpm`. Know what you are taking on:
**Hestia regenerates that pool file from its template**, so the line is lost
whenever the domain is rebuilt or its backend template is changed, and the site
silently goes back to having no key. If you want the environment route to
survive that, put it in a *custom* Hestia PHP-FPM template under
`/usr/local/hestia/data/templates/web/php-fpm/` and assign the domain to it.
Otherwise use the file — it is one less thing that can be quietly undone.

Do **not** put the key in `.htaccess` with `SetEnv`: `.htaccess` is in the
document root and is exactly the sort of file that ends up copied into a
backup, a screenshot or a repository.

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
| `api/integrations/ingest.php` | health rows, and then re-derives every automatic goal |
| `api/friends/search.php` | reads only, and only public fields |
| `api/friends/request.php` | `friendships` |
| `api/friends/block.php` | `user_blocks` |

Height and weight are not columns on the profile. Saving either **adds a row**
to `user_measurements`, so last month's weight is still there; the current
value is simply the newest row.

## How a goal knows where it stands

Every goal says where its progress comes from, and the person picks it when
they make the goal — `tracking_mode`, `source_kind` and `source_key` on
`goals`. A source is not always a metric: weight is a row in
`user_measurements`, training is rows in `workouts`, steps are readings in
`health_metrics`, so the column that names one has to say which kind it is.
"Geen data mogelijk" is a real answer, stored as `manual`, and those goals are
the only ones with an entry box.

`includes/goal-progress.php` is the only thing that calculates a percentage.
The bar, the figure, the day blocks and the completion check all read it, so
they cannot disagree. It runs on every render of the Doelen page and again
after every import, which is what makes an automatic goal move on its own.

Three things it will not do:

- **Invent a number.** No data means no percentage and the empty state the
  cards already draw, not a zero.
- **Treat a missing day as a failure.** A day with no step count is drawn as an
  outline and counted as neither met nor missed.
- **Rewrite a finished goal.** A completed goal keeps the figure it finished
  on; losing more weight afterwards does not change the goal you achieved.

`start_value` is the baseline, captured when the goal is made or on the first
reading after it. Without it a decreasing goal cannot be measured at all —
75 / 82 is 91%, which would read as nearly finished to somebody who has lost
nothing.

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

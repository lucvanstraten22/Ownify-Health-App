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

**Scores.** Every Health Score in the app comes out of
`includes/health-score.php`: sleep, nutrition and training, each 0-100 over
the last 168 hours, and none until a category has 3 days of data. The overall
score is the average of whichever of the three exist, in `score_combine()`
(`includes/scoring.php`): a missing pillar is skipped, never counted as a zero,
and when all three are missing there is no score rather than a `0`. See
*Health Score and points* below.

**Points.** Leaderboard points come out of `includes/points.php`, a separate
system: points pay for things somebody did — a night, a rated day, a workout,
steps, three workouts in a week — and each of those pays once. Nothing that
awards points reads a Health Score.

**Goals.** Where a goal stands comes out of `includes/goal-progress.php`, and
its type decides the arithmetic: a **Mijlpaal** follows its latest value from
the baseline to the target (80 → 100 kg at 90 is 50%, back at 85 it is 25%), a
**Streak** is the share of the period's days — or weeks, months… — that made
it, and **Optellen** adds up everything since the start date (8.000 + 11.000 +
9.000 steps against 100.000 is 28%). Missing data is never a zero. Where a goal
stands is stored on the goal row (`current_value`, `progress_pct`), and once
it finishes its final percentage is frozen in `final_pct` with `completed_at`.
The model, the formulas and the tables are [GOALS.md](GOALS.md); an existing
database gets them from `database/migrations/025-goals-rebuild.sql`, and
`schema.sql` already has them.

## Setting it up (WampServer)

1. Start Wamp, open **phpMyAdmin**.
2. Create a database named `ownify` (phpMyAdmin → **Databases** → *Create
   database*, collation `utf8mb4_unicode_ci`), select it, and import
   `database/schema.sql` into it. That creates every table, and can be
   re-imported at any time to rebuild from scratch.
3. Optional: import `database/seed-dev.sql` for fake development data.
4. Credentials default to Wamp's `root` with no password. To change them,
   create `config/database.local.php` (git-ignored) returning only what you
   want to override, or set `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD`:

   ```php
   <?php // config/database.local.php
   return ['username' => 'ownify', 'password' => 'secret'];
   ```

Until the schema is imported the app still runs: it renders signed out, and
the account panel says the database is unreachable instead of erroring.

### Before the rename to Ownify

Until the app was renamed to Ownify, a checkout without
`config/database.local.php` looked for a database called `jolu`; it now looks
for `ownify`. A local database is not carried over: create `ownify` and import
the schema as in step 2 above, and drop the old `jolu` when you like.

The production database on Hestia moves from `luc_healthapp` to `luc_ownify`
by *copying* it, never by renaming or dropping the old one, so that stays the
backup until the new one has proven itself:

```bash
php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify
```

It copies every table with its keys, foreign keys and `AUTO_INCREMENT`
counters, then checks the copy against the original — tables, columns,
indexes, foreign keys, row counts and a checksum of every table — and says so
if anything differs. Signed-in people stay signed in: the sign-in rows are
copied with everything else. `docs/OWNIFY-MIGRATION.md`, section A, has the
whole procedure, including the switch-over.

## Secrets on the server

Four things must exist on a machine running the app and must never exist in
the repository: the database password, any OAuth client secret,
`OWNIFY_APP_KEY` and the Gemini API key. All four follow the same pattern — a
git-ignored file next to a tracked `.example` that shows the shape, or an
environment variable that wins over the file.

| Secret | File | Environment | Needed for |
| --- | --- | --- | --- |
| Database | `config/database.local.php` | `DB_HOST` `DB_NAME` `DB_USER` `DB_PASSWORD` | everything |
| App key | `config/app.local.php` | `OWNIFY_APP_KEY` | storing OAuth tokens |
| Google sign-in | `config/auth.local.php` | `GOOGLE_SIGNIN_CLIENT_ID` `_CLIENT_SECRET` `_REDIRECT_URI` `_ANDROID_CLIENT_IDS` | signing in with Google (the last: in the Ownify app) |
| Google client | `config/integrations.local.php` | `GOOGLE_HEALTH_CLIENT_*` | the Google Health cloud source |
| Gemini API key | `config/ai.local.php` | `GEMINI_API_KEY` | OwnifyAI, the assistant — [AI.md](AI.md) |

Check what a machine actually has, without printing any of it:

```bash
php tools/check-config.php
```

It reports the PHP extensions, the database settings in force and whether they
connect, the state of the key and where it came from, and which sources are
configured. It prints no passwords and no keys — not even a prefix — so its
output is safe to paste somewhere when asking for help. It exits non-zero when
something essential is missing, so a deploy step can fail on it.

### `OWNIFY_APP_KEY`

32 bytes of randomness, base64-encoded, used by `includes/crypto.php` to
encrypt OAuth tokens before they go in the database. The key lives outside the
database, which is the entire point: a stolen dump is then worth nothing.

```bash
php tools/check-config.php --generate-key
```

The app looks in three places, first one wins:

1. `getenv('OWNIFY_APP_KEY')` — the process environment
2. `$_SERVER['OWNIFY_APP_KEY']` — Apache `SetEnv`, and several FastCGI setups
3. `config/app.local.php`, returning `['app_key' => '...']`

A request header could never be mistaken for the key: headers reach PHP with an
`HTTP_` prefix, so the most a caller can set is `HTTP_OWNIFY_APP_KEY`, which
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
env[OWNIFY_APP_KEY] = "the-base64-key"
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

### Google sign-in

Until this is configured the Google button stays disabled and nothing about
signing in changes. It needs an OAuth client in a Google Cloud project — the
same project as the Google Health source is fine, and so is a new one.

**In Google Cloud Console** (console.cloud.google.com). Google has renamed
these menus: in current consoles they are under **Google Auth Platform**, in
older ones under **APIs & Services → OAuth consent screen / Credentials**.

1. **Branding** (older: *OAuth consent screen*). Audience *External*. App name
   — what people see on Google's screen — a support e-mail, and your e-mail as
   developer contact. Under **Data access** (older: *Scopes*) add only
   `openid`, `.../auth/userinfo.email` and `.../auth/userinfo.profile`. These
   are not sensitive scopes, so there is no verification review.
2. **Audience.** While the app is in *Testing*, only the Google accounts listed
   as *Test users* can sign in: add yourself and anyone testing. When it should
   be open to everyone, press **Publish app** there.
3. **Clients → Create client** (older: *Credentials → Create credentials →
   OAuth client ID*). Application type *Web application*. Under **Authorised
   redirect URIs** add exactly:

   ```
   https://ownify.acits.nl/api/auth/google-callback.php
   ```

   No JavaScript origins are needed. Create it, and copy the **Client ID** and
   **Client secret** straight away — newer consoles show the secret only once
   (a lost one can be replaced with a new secret on the same client).

**On the server**, the same way as the app key — a git-ignored file the deploy
never touches, in the web root:

```bash
cd /home/<hestia-user>/web/<your-domain>/public_html
cp config/auth.local.php.example config/auth.local.php
nano config/auth.local.php          # paste the client id and secret
php tools/check-config.php          # must say: Google sign-in configured
chmod 600 config/auth.local.php
```

The redirect URI in that file is already the live one; it must match the one
registered in step 3 character for character, or Google answers
`redirect_uri_mismatch`. Without SSH, Hestia's File Manager can create the file:
copy `config/auth.local.php.example`, rename the copy to `auth.local.php`, and
edit the two values.

The Ownify app signs in with Google through this same project, consent screen
and Web client, plus one OAuth client of type *Android* per key the app is
signed with: [APP-AUTH.md](APP-AUTH.md#setting-up-google-for-the-app).

## The tables

**Identity**

| Table | Holds |
| --- | --- |
| `users` | the account: id, unique username, status, timestamps |
| `user_profiles` | the person: name, date of birth (age is worked out from it, never stored), gender, avatar, language and units (migration 021, docs/I18N.md), the first day of the week and three accessibility choices (migration 024), focus, and where the setup a new account starts with stands |
| `user_auth_identities` | one row per way of signing in |
| `user_login_tokens` | one row per browser that signed in and has not signed out: a selector and the SHA-256 of a validator |

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
| `sleep_stages` | a night's stages period by period, as recorded — for the Slaap timeline (migration 018, docs/SLEEP.md) |
| `heart_rate_minutes` | heart rate through the day: each minute's mean, per app that recorded it — for Training's heart rate (migration 019, docs/TRAINING.md) |
| `nutrition_entries` | one meal or drink |
| `workouts` | one training session, every column nullable |
| `workout_hr_zones` | seconds per heart-rate zone |
| `health_metrics` | every scalar reading, whatever its frequency |
| `health_metric_day_totals` | a day's steps, distance and calories as `health_metric_totals()` worked them out, kept so a long goal is not worked out again on every page — nothing in it is a source |
| `daily_scores` | the Health Score per category, one row per day it was calculated, with the days and components behind it |

**Goals — private**

| Table | Holds |
| --- | --- |
| `goal_categories`, `goal_units`, `goal_measurements` | the vocabulary a goal is made from — not personal; the same rows as `config/goals.php` |
| `goals` | name, category, measurement, type (Mijlpaal, Streak elke dag or per periode, Optellen), unit, target, direction, baseline, dates, status, where it stands, and once finished its frozen final percentage |
| `goal_entries` | what was logged by hand on the goal's page |
| `goal_day_results`, `goal_period_results` | a streak's days and periods: met, missed, open |
| `goal_progress` | one snapshot per goal per day, for the goal's Verloop |

**Community**

| Table | Holds |
| --- | --- |
| `friendships` | one row per pair: a friend request (`pending`), a friendship (`accepted`) or a turned-down request (`declined`), with who asked and when |
| `user_blocks` | one-directional blocks |
| `point_rules` | the nine things that earn points; their values are in `config/points.php` |
| `point_events` | the ledger: one row per award, at most one per `award_key` |
| `user_period_points` | points per period, plus the national position |
| `leaderboard_best_positions` | best rank ever reached |
| `challenges` | a challenge (Uitdagingen): Samen or Wedstrijd, what it counts, its target (Samen, stored units), its name, first and last day, and its five-digit code — which works until the last day and is `NULL` once a later challenge was given it ([CHALLENGES.md](CHALLENGES.md)) |
| `challenge_members` | who is in a challenge and how: `invited` (by whom), `joined` (since when — what they do counts from that day), `declined` or `left`. Nobody's health is copied in here: totals are worked out when shown |

**OwnifyAI — private** ([AI.md](AI.md))

| Table | Holds |
| --- | --- |
| `ai_conversations` | one row per conversation with the assistant: its owner, a title (the first question, shortened), when it was last used |
| `ai_messages` | what was said, `user` / `assistant` / `system` (Ownify's own note, e.g. that a goal was added), and a proposed change with what became of it (`action_json`, `action_state`) — never the health data that was sent along |
| `ai_usage` | per person per day: questions (the daily limit), Gemini requests, tokens, errors, times the limit was hit |
| `ai_service_state` | facts about Gemini, not about anyone: until when its free quota is known to be used up |

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

**A day's total counts every moment once.** A phone and a watch can both
record the same walk in Health Connect — 5.000 steps from one, 4.900 from the
other — and adding the rows up gives 9.900 steps nobody walked. So a reading
that covers a span keeps it: `started_at` to `recorded_at`, plus
`data_origin`, the app that wrote it (its package name). For the metrics
listed in `config/health-sources.php` — steps, distance, active and total
calories, floors — `health_metric_totals()` (`includes/health-totals.php`) cuts
the day into moments and lets one record count at each: the highest-ranked
app's, for its share of the value; the next app fills in where it has nothing,
and a record crossing midnight is split over both days. Which app ranks
highest is set in that config file: a priority list (empty by default), then
the app covering most of that day, then the larger total, then the name. The
rule decides what *counts*; nothing is deleted, and every row stays as it
arrived. A reading without a span — typed in by hand, or imported before the
columns existed — counts as it is. That one function is the day total for the
Training card, goal progress and the steps points alike, and any other summed
metric (water, nutrients) is still the plain sum per day. Each day it works
out is kept in `health_metric_day_totals` with a fingerprint of the readings
behind it; when a reading changes — however it changes — the fingerprint no
longer matches and the day is worked out again on the next read, so no code
that writes readings has to know the table is there. An existing database
gets the two columns and the table from
`database/migrations/012-metric-intervals.sql`; until then every total is the
plain sum it always was, and the phone's next sync fills the columns in for
the days it sends again.

**Friendship is one row, not two.** The pair is stored in a fixed id order
(`user_low_id < user_high_id`), so the unique key makes a mirrored duplicate
impossible; `requested_by` records who asked. There is no follow. The row is
the request and then the friendship:

| State | Means |
| --- | --- |
| `pending` | a friend request; `requested_by` sent it, `created_at` is when |
| `accepted` | friends, both ways, since `responded_at` |
| `declined` | the request was turned down: closed, nobody is a friend; either can ask again |
| no row | nothing between them — a friend removed, a request withdrawn |

So the same request can never be sent twice: a second request for the pair is
the same row, and the write that reopens a declined one is a single statement,
so two requests at the same moment still leave one.

**Who may send you a request** is `user_profiles.allow_friend_requests`
("Vriendverzoeken toestaan"), on unless the person switches it off. Off, nobody
can send them a new request; friends they have and requests already waiting
are not touched. An existing database gets the column from
`database/migrations/011-friend-requests-setting.sql`; until then everybody
accepts requests and the switch says it cannot be saved yet.

**Your picture on the boards** is `user_profiles.leaderboard_avatar`
("Profielfoto op de ranglijst", Instellingen → Privacy), on unless the person
switches it off. Off, every board shows their initial instead of their
picture — to everybody, themselves included. It is decided in the board
queries themselves (`leaderboard_avatar_select()`), so the path of a picture
kept off the boards is never sent to anyone. The picture itself, and the
friends list, are not touched. An existing database gets the column from
`database/migrations/014-leaderboard-avatar-setting.sql`; until then every
picture shows and the switch says it cannot be saved yet.

**The boards and friends lists show a small copy** of each picture, not the
upload itself (up to 3 MB): the centre square, turned upright, at 192 px, as
WebP (JPEG where PHP cannot write WebP), beside the original in
`uploads/avatars/` with `-s` added to its name (`avatar_small()`,
`includes/user.php`). It is made at upload; a picture uploaded before this
gets its copy the first time it is shown. Without GD, or for a file that
cannot be read, the original is shown as before (`tools/check-config.php`
says which). Replacing a picture removes the old one and its copy; deleting
the account removes both.

**Whether OwnifyAI may send someone's data to Gemini** is
`user_profiles.ai_consent` — NULL never asked, 1 yes, 0 no or withdrawn — with
`ai_consent_version` (the wording they answered, `consent_version` in
`config/ai.php`) and `ai_consent_at`. The sheet's first question and the
Privacy switch *Gegevens verwerken met Google Gemini* write the same columns.
A yes to an older wording counts as never asked. An existing database gets the
columns and the four `ai_` tables from
`database/migrations/015-ai-assistant.sql`; until then the assistant says it is
not available and nothing else changes. See [AI.md](AI.md#consent-and-privacy).

**The setup a new account starts with** is `user_profiles.setup_state`:

- `NULL` is an account from before the setup existed, which never sees it;
- `pending` is a new account that has not finished it, which opens on it on
  the website and the phone;
- `done` is finished, and `setup_done_at` is day 1 of the first days.

Only registration writes `pending`, inside its transaction, so nothing had
to be filled in for the accounts that exist. `user_profiles.focus` is what
the person most wants to understand (`general` for *Alles*, `sleep`,
`energy`, `fitness`, `weight`; `NULL` never chosen, shown as *Alles*). It
orders the categories and never hides one. An existing database gets the
three columns from `database/migrations/016-setup-and-focus.sql`; until then
nobody gets the setup and everybody's focus is *Alles*. See
[FIRST-DAYS.md](FIRST-DAYS.md).

**A night's stages, period by period** (`sleep_stages`): the periods Health
Connect sends with each sleep session, kept as recorded so the Slaap page can
draw the night stage by stage (docs/SLEEP.md). Replaced whenever the session
is synced again, removed with it. The minutes per stage on `sleep_sessions`
are what the night, the score and the points read, as before. An existing
database gets the table from `database/migrations/018-sleep-stages.sql`;
until then a night shows its times without its stages, and the app's next
sync after it fills in the last seven nights.

**Heart rate, minute by minute** (`heart_rate_minutes`): every heart-rate
sample Health Connect sends, kept as the mean of each minute per app — one
row per user, minute and app, its number of samples beside it, written
again the same on a re-sync. Training draws a day from it (each five
minutes' mean), a session (each minute), and a period of daily averages
(each day the mean of its five-minute means); docs/TRAINING.md. The
average during sleep is still kept as `sleeping_hr`, as before. An existing
database gets the table from `database/migrations/019-heart-rate-minutes.sql`;
until then Training's heart rate is empty and nothing else changes, and the
app's next sync after it fills in the last seven days.

**Connecting a cloud source** (`integration_oauth_states`): one Polar
connection being made — only the SHA-256 of the state sent to Polar, whose it
is (from the session or the app's account token, never from the callback),
and where it may be finished (the browser session that started it, or the
app's confirmation page with its one-time token and the code waiting,
sealed). Single use, ten minutes, removed with the account.
`user_integrations.sync_started_at` marks a sync running now. An existing
database gets both, and the source `polar`, from
`database/migrations/020-polar.sql`; until then Polar is shown as not yet set
up and nothing else changes. See [POLAR.md](POLAR.md).

**The language and the units** are `user_profiles.locale` — `nl` or `en`
when chosen in Instellingen › Taal, NULL to follow the browser's or phone's
language — and `user_profiles.units`: `imperial`, or NULL for metric. Only
how things are shown and typed changes: every measurement stays stored in
kg, cm, km or m and °C, and every stored text in Dutch (the server
translates it when it is shown). An existing database gets the column, and
the old default `nl` turned into NULL (it was never anybody's choice), from
`database/migrations/021-language-and-units.sql`; until then the language
follows the device, everything is metric, and choosing either says it
cannot be kept yet. See [I18N.md](I18N.md).

**Uitdagingen** are `challenges` and `challenge_members`, from
`database/migrations/022-challenges.sql`; until it is imported Community shows
the leaderboard card only. Deleting an account takes its memberships with it;
a challenge it made goes on for the others (`creator_id` becomes NULL). See
[CHALLENGES.md](CHALLENGES.md).

**VO2max** is no longer an Ownify metric (18.6):
`database/migrations/023-remove-vo2max.sql` deletes its readings and its
`health_metric_types` row, and turns a goal tracked from it into one kept by
hand (its name, target and recorded days stay). Until it is imported nothing
reads or shows VO2max any more; the goal wizard of 22.0 does not offer it
either.

**Voorkeuren and phone names** (19.0):
`database/migrations/024-preferences-and-device-names.sql` adds
`user_profiles.week_start` (`sunday`, or NULL for Monday — where a week
begins in a goal's day calendar and OwnifyAI's weekly totals),
`text_large`, `contrast_more` and `motion_off` (Voorkeuren ›
Toegankelijkheid, each 0 or 1, on whatever the device says; see
[THEME.md](THEME.md)), and `user_devices.custom_label`: the name the owner gave
a paired phone in Instellingen › Apparaten & Gezondheid. It is shown instead
of `label` — what the phone calls itself, which the app rewrites when it
signs in again — and only the owner changes it (`api/integrations/device-rename.php`,
one phone of their own at a time, at most 40 characters; empty gives the
phone its own name back). Renaming changes nothing else: not the token, the
pairing, the sync or the data. Every new column starts empty or 0, which is
how the app behaved before; until it is imported the switches and the week
cannot be kept (the endpoints answer 503) and a phone cannot be renamed.

**Goals, rebuilt** (22.0): `database/migrations/025-goals-rebuild.sql`
replaces the goal tables and touches nothing else. The old goals and their
progress are copied to `goals_backup_024` and `goal_progress_backup_024`
first; then the old `goal_progress` and `goals` are dropped and the new
vocabulary (`goal_categories`, `goal_units`, `goal_measurements`) and goal
tables (`goals`, `goal_entries`, `goal_day_results`, `goal_period_results`,
`goal_progress`) are created — see [GOALS.md](GOALS.md). The old goals cannot
be carried over (their types and sources mean other things now), so goals
start afresh. Re-running it is safe. Until it is imported, Doelen shows no
goals and a new one cannot be made.

There is no `age` column: Leeftijd is no longer a field in Instellingen
(19.0). Whatever needs an age — the nutrition targets, the estimated maximum
heart rate, OwnifyAI's context — works it out from `date_of_birth` when it
needs it (`user_age()`), as it already did.

**Blocking is separate from friendship** because it is one-directional: A can
block B without B blocking A. A block wins: it deletes whatever row the pair
had, neither can find or ask the other, and `friend_ids()` filters both
directions out.

**At most 50 friends.** An account holds at most `FRIEND_LIMIT` (50) friends,
the size of the friends leaderboard — checked for both people when a request
is sent and again when it is accepted.

## Privacy

Two classes of data, and the boundary is deliberate.

| Public — other users may see | Private — owner and authorised services only |
| --- | --- |
| `users.username` | every health table |
| `user_profiles.avatar_path` | `user_measurements` |
| `user_period_points.points`, `.position` | `goals` and every table under it (`goal_entries`, `goal_day_results`, `goal_period_results`, `goal_progress`) |
| your total for a challenge you joined yourself — to its participants only | `ai_conversations`, `ai_messages`, `ai_usage` |

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

**Deleting an account deletes it.** `api/profile/delete.php` removes the
signed-in account with one `DELETE FROM users`: every table holding something
of a user's references `users` with `ON DELETE CASCADE`, so the profile, both
ways of signing in (the password and the Google link), every browser it stays
signed in on, health data, goals and
their history, measurements, paired phones and their tokens, pairing codes,
integrations, friendships in both directions, blocks, points and leaderboard
positions, and every conversation with OwnifyAI and its usage all go in the
same statement — rows removed, not marked. The profile
picture is a file and is removed from `uploads/avatars/` separately. The
browser is signed out; a session still open on another device is turned away on
its next request, and a paired phone's token stops working. The endpoint only
acts on `confirm=verwijderen`, which only the second confirmation step sends.

If Google was linked, the answer carries one silent round trip past Google
(`prompt=none`, hinted to that Google account): the access token it yields is
used once to revoke Ownify's access and then dropped, so Ownify also disappears
from the person's Google account. The account is already deleted before that
trip starts, so nothing about Google can stop or undo the deletion; if Google
needs to ask something first, the page says so and links to Google's own list
of connected apps.

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
                 └── provider 'google'  subject = Google 'sub'
```

Email/password is implemented: `password_hash()` on the way in,
`password_verify()` plus `password_needs_rehash()` on the way out, a generic
error for both an unknown address and a wrong password, and
`session_regenerate_id(true)` on login. No provider password or token is
stored, ever.

**Staying signed in** is `user_login_tokens` and `includes/persistent-login.php`.
Every sign-in — password, registration, Google — adds a row for this browser
and sets a cookie holding `selector.validator`; the row keeps the selector and
only the SHA-256 of the validator, so a copy of the table signs nobody in.
When a request arrives with no session (the browser was closed, or the server
cleared the session away), the cookie puts one back, on a new session id, with
the CSRF token that sign-in always had. The validator is replaced every time
that happens; the one before it keeps working only until the browser shows it
has the new one, and presenting it after that revokes the row. Signing out
deletes this browser's row, and a year without use lets it expire. An existing
database gets the table from `database/migrations/008-persistent-login.sql`;
until then a sign-in lasts as long as its session.

**Google is implemented** in `includes/google-signin.php`, as OpenID Connect
with the authorization code flow and PKCE:

1. `api/auth/oauth.php` (POST, CSRF-checked) puts a random state, nonce and
   PKCE verifier in the server session and answers with Google's address.
2. Google sends the browser back to `api/auth/google-callback.php`. The state
   must be the one in this session; the flow is consumed either way, so a
   callback works once.
3. The code is exchanged server to server, with the client secret and the
   PKCE verifier, and **the ID token is verified here**: RS256 against
   Google's published keys (no other algorithm is accepted), issuer, audience
   and authorised party, expiry and issue time, and the nonce. Only its `sub`
   identifies the person; the e-mail address is used only if Google says it is
   verified.
4. Then:
   - `sub` already linked → signed in (`session_regenerate_id` included).
   - the verified address belongs to an e-mail/password account → **refused**.
     Nobody is signed in and nothing is created; the panel says to sign in with
     the password and link Google under Instellingen → Account.
   - otherwise the verified `sub` and address wait **in the server session
     only**, for ten minutes, while the person chooses a username
     (`api/auth/google-username.php`, same rules as e-mail sign-up). Only then
     is the account created, already linked, in one transaction. Walking away
     saves nothing.
5. Linking from Instellingen → Account runs the same flow with `mode=link`: the
   verified `sub` is attached to the account that started it, if that account
   is still the one signed in, has no Google account yet, and the Google
   account is not already someone else's.
6. Deleting an account with Google runs it once more with `mode=revoke`,
   silently, to withdraw Ownify's access at Google — see *Privacy*.

Codes and tokens are never stored or logged. Test the verification offline
with `php tools/google-signin-test.php`.

**Failed sign-ins are limited** (`includes/auth-throttle.php`,
`auth_attempts`): 5 wrong passwords per 15 minutes for one name from one
network address, then `429` until the oldest failure is out of the window. A
name without an account is treated identically, a correct password clears the
count, and nothing locks for good. Rows hold a SHA-256 of the name and address
together, never either as text, and are deleted after a day.

**The Ownify app signs in as an account** with its own token, not a cookie:
`user_devices.scope` is `sync` for a phone paired with a code (upload only) and
`account` for the app after signing in with the password or Google (acts as the
account where an endpoint takes `api_require_account_user()`). Only SHA-256
hashes are stored; an account token lapses after a year without use. See
[APP-AUTH.md](APP-AUTH.md). An existing database gets the column and the table
from `database/migrations/013-app-tokens.sql`; until then every token is a
sync token and sign-ins are not counted.

## Where the future work goes

**Health Connect sync** is built: the Ownify Android app posts to
`api/integrations/ingest.php`, and `includes/health-import.php` writes the
records into `sleep_sessions`, `workouts` and `health_metrics` with the
matching `data_sources` row ([HEALTH-CONNECT.md](HEALTH-CONNECT.md)). The
server also accepts an `apple_health` source, but nothing can send one: that
needs an iPhone app, which Ownify does not have. A metric a platform reports
that we do not know yet is a new `health_metric_types` row; the raw platform
payloads stay out of these tables.

**Nutrition from real food data.** The nutrition score is the daily 1-10
rating for now. Logged meals can become a second component in
`health_score_nutrition()` with its own weight in `config/scoring.php`; the
rating keeps working beside it.

**The first day of the week.** The weekly training bonus counts weeks from
`week_starts_on` in `config/points.php` (Monday). Once Instellingen stores a
person's own choice, `points_week_start()` is the one place that reads it.

**The assistant.** Built: OwnifyAI ([AI.md](AI.md)) reads a user's own data
through the same functions the pages use, with the id from the session, and is
never handed another user's records. New areas of data reach it as a block in
`includes/ai/context.php` and, for a longer stretch, a tool in
`includes/ai/tools.php`.

## Health Score and points

Two systems that never touch. The Health Score **measures a pattern** — how
healthy the last 168 hours (7 days) were. Points **pay for actions** — a night, a rated
day, a workout — and put people on the leaderboard. Nothing that awards points
reads a Health Score, and a score of 91 is never 91 points. Every number either
of them uses is in `config/scoring.php` or `config/points.php` and nowhere else.

### The Health Score

`includes/health-score.php`, reading the records through
`includes/health-signals.php`.

- **Window.** The moment of calculation minus 168 hours (`window_hours`). Not
  this calendar week: an hour from now the score has an hour more at the
  front and an hour less at the back. There is one Health Score; the longer
  periods the Scorekompas shows are its stored history, never other scores.
  Until 1.6.0 the window was 90 days (`rolling90-v1`).
- **At least 3 days.** A category needs 3 distinct days of its own data in the
  window (`min_days` in `config/scoring.php`). Below that it has no score, and
  the page says how many days are still needed — on the card, and, while no
  category has a score yet, in Gezondheid's intro ("Je hebt nog 2 dagen data
  nodig om een score te ontgrendelen."). A day without data is not a day of zero: 5 nights in 7 days
  are averaged over 5.
- **No new data does not last.** After `expiry_days` (3) days in a row without
  a new night, Slaap stops counting; without a new cijfer, Voeding. The
  category is `expired`: left out of the overall score — which is then the
  average of the categories still current — never counted as zero, until new
  data comes in. Training does not expire: rest days are part of what it scores,
  and workouts older than 168 hours drop out of the window by themselves.
- **Missing parts.** A component the person's device does not measure is left
  out and the other components' weights are scaled up (`health_weighted()`),
  rather than it counting as a zero.
- **No steps.** Every measurement becomes 0-100 on a smooth curve through the
  points in the config (`health_curve()`, monotone cubic), so 7:59 and 8:00 of
  sleep score almost the same.

| Category | Formula |
| --- | --- |
| Sleep | 45% duration + 30% regularity + 25% quality. **Duration**: each night's main sleep on the duration curve (95 at 7:30, 100 at 8:00, 96 at 8:30, 84 at 7:00, 62 at 6:00), averaged over the nights. **Regularity**: the night-to-night standard deviation of bedtime and wake time (40% each, measured on the clock, so 23:50 and 00:10 are 20 minutes apart) and of the duration (20%), each on its curve (15 min or less is 100, an hour 56 for the times). **Quality**: each night's efficiency, time awake, deep and REM share on their curves — only what the device measured — averaged over at least 3 such nights. |
| Nutrition | The day's rating × 10 (several on one day count as their average), averaged over the rated days. |
| Training | 20% volume + 20% intensity + 25% progression + 35% balance, over the weeks since the first workout in the window. **Volume**: minutes a week, flattening out (54 at 90 min, 85 at 225, 100 at 540) and dipping beyond. **Intensity**: the share of hard sessions — perceived effort, else heart-rate zones, else average heart rate against the maximum — best around 30%, so all-hard is not better. **Progression**: the relative change, recent half against earlier half of the window, in pace per kind of activity and the person's own strength and performance goal results; holding steady is 60 — in 168 hours there are often too few results for it, and then it is left out and the others weigh more. **Balance**: training days a week (counts double; 4-5 is best), the longest run without a rest day, weekly load spikes (more than 1.5× the four weeks before — which a 7-day window does not hold, so they are not scored), heavy days back to back, and sleep in the nights after training. |
| Overall | The average of the categories that have a score (`score_combine()`). None of them: no score, never a 0. |

What counts as one night or one workout is decided once, in
`includes/health-signals.php`, for the scores and the points alike: two
recordings of one night are one night (the one with sleep stages, else the
longer), and two recordings of one workout that overlap by more than half are
one workout (the longer). A workout counts from 10 minutes to 8 hours.

Scores are calculated from the records whenever they are read, and every
calculation is written to `daily_scores` — the score, `data_days`, the
components as JSON in `inputs` and `valid_until`, the last day it holds
without new input — one row per category and one for the overall score per
day, rewritten only when something changed, and **only for today**: a day
that has passed keeps what it recorded. That is the history the Gezondheid
trend, the Scorekompas (7 days to a year) and OwnifyAI read
(`health_score_history()`): a day without a row of its own carries the last
recorded categories while they hold, and has no score after that — never a
recalculation from today's records, never a backfill. An existing database
needs `database/migrations/017-score-history.sql` for `valid_until`; without
it the scores are stored and shown as before and nothing is carried. Each result also carries the `facts` its
components were worked out from (each night's length, the spreads, minutes a
week…) for the Scorekompas to put into words ([SCORE-COMPASS.md](SCORE-COMPASS.md));
they are never stored and never scored.

### Points

`includes/points.php`. One entry point, `points_process($userId, $touched)`,
is handed the nights, workouts and days a save or a sync touched, and makes
their awards what they should be.

| Rule (`point_rules.code`) | Pays |
| --- | --- |
| `sleep_duration` | minutes asleep in the night's main sleep: 7:00-7:29 **25**, 7:30-7:59 **35**, 8:00-8:59 **45**, 9:00-9:30 **35**, longer **20** |
| `sleep_regularity` | **+10** when bedtime and wake time are both within 45 minutes of the usual times of the previous 14 nights (at least 5 of them) |
| `sleep_quality` | **+10** when the night's measured quality is at least 75 |
| `nutrition_rating` | the day's rating: 1-3 **0**, 4-5 **10**, 6-7 **25**, 8-9 **40**, 10 **50** |
| `workout` | a qualifying workout: 10-29 min **20**, 30-59 min **35**, 60 min or more **45** |
| `workout_intensity` | **+10** hard, **+15** very hard (effort 9+, half the time in zone 4+, or 85% of the maximum heart rate) |
| `workout_record` | **+25** for the longest distance, or the fastest pace over a comparable distance, of that kind of activity — at least 1 km, against at least 3 earlier ones |
| `steps` | steps in a day: 5.000 **10**, 7.500 **20**, 10.000 **35**, 12.500 **45** |
| `weekly_workouts` | **+75** the moment the third workout of the week, on a third different day, is recorded — once per week |

A list of tiers pays the one tier reached, not their sum. There is no daily
cap.

**Each event pays once.** Every award has an `award_key` naming the event it
is for — `workout:123`, `sleep_duration:2026-09-24`, `nutrition_rating:2026-09-24`,
`steps:2026-09-24`, `weekly_workouts:2026-09-21` — and `(user_id, award_key)`
is UNIQUE, so the database itself refuses a second award for the same event,
however often and however simultaneously the record arrives. Every write is an
upsert on that key. An award follows its record: a corrected rating moves its
award up or down instead of adding one, and a deleted night takes its points
with it.

- `awarded_at` is when the activity happened, so a night synced three days
  late counts in the month it was slept.
- Activity from before the account existed earns nothing (`award_from`), so
  connecting a phone with a year of history does not buy a place on the board;
  and nothing dated in the future does.
- After every change the rollup is rebuilt for the month, year and all-time of
  that award, so the boards show new points on the next page view.
- `points_history()` lists a person's own awards with the rule behind each —
  the answer to "why do I have these points?".

An existing database needs `database/migrations/010-health-score-and-points.sql`
(`schema.sql` already has all of it). It adds `point_events.award_key` with its
unique key, `point_events.updated_at`, the `day` and `week` reference types,
the nine rules, and `daily_scores.data_days` and `.inputs`; it can be imported
more than once. Until it is imported the scores work and no points are
awarded — the server log says why, and `php tools/check-config.php` reports it.

After importing it, run `php tools/points-backfill.php` once: activity saved
before the migration never went through the points engine, and this runs every
existing night, workout, rating and step count through the same rules as a
live save. A second run changes nothing, so it is safe to repeat.

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
  always correct. Once somebody has friends the board is the whole group —
  them and every friend, a friend without points in the period at 0 — so a
  request accepted a moment ago puts the friend on the board at once, and a
  removed friend is gone from it at once. Without friends it is only you, once
  you have points.
- **Your own position** — `leaderboard_position()` reads your row directly,
  whether you are 7th or 1180th.
- **Best ever** — `leaderboard_best_positions` is a separate concept from the
  current position and from points. Lower is better, and
  `leaderboard_note_best_position()` only ever lowers it.

`leaderboard_rebuild()` reconstructs the rollup from the ledger, so the rollup
is a cache: losing it costs nothing. The points engine calls it for every
period an award lands in, and deleting an account calls it for every period
that account had points in, so nobody else's position is left stale.

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
| `api/auth/register.php` | `users`, `user_profiles` (its setup `pending`), `user_auth_identities`, `user_login_tokens` |
| `api/auth/login.php` / `logout.php` | this browser's `user_login_tokens` row, added or deleted; login touches `last_login_at` |
| `api/auth/google-callback.php` | a Google identity on the signed-in account (linking); otherwise this browser's `user_login_tokens` row |
| `api/auth/google-username.php` | `users`, `user_profiles` (its setup `pending`), `user_auth_identities`, `user_login_tokens` — the Google identity waiting in the session |
| `api/profile/delete.php` | deletes the signed-in account and everything of it (see *Privacy*), and its avatar file; then re-ranks every board it had points on |
| `api/profile/username.php` | `users.username` |
| `api/profile/avatar.php` | `user_profiles.avatar_path` + the file under `uploads/`, and its small copy |
| `api/profile/update.php` | names, activity level, `user_profiles.focus`, and height/weight as `user_measurements` |
| `api/setup/finish.php` | `user_profiles.setup_state` → `done` and `setup_done_at`, once: only a pending setup finishes |
| `api/profile/onboarding.php` | `date_of_birth`, `gender` — once, then it refuses |
| `api/health/sleep.php` | `sleep_sessions`; then that night's points and the Health Score |
| `api/health/nutrition.php` | `nutrition_entries` + the rating and nutrients as `health_metrics`; a rating earns that day's points |
| `api/health/rating.php` | the day's 1-10 nutrition rating, one `health_metrics` row per day (today or up to 6 days back, saving again replaces it); then that day's points and the Health Score |
| `api/health/training.php` | `workouts`; then its points, the week's bonus and the Health Score |
| `api/goals/create.php` | `goals`, subject to the limit on active goals (`limits` in `config/goals.php`, five) and one primary; a repeated one-time key gets the goal already made |
| `api/goals/baseline.php` | reads only: a Mijlpaal's measured start for the wizard |
| `api/goals/update.php` | pause, resume, re-prioritise — a goal finishes by its own rules |
| `api/goals/delete.php` | deletes the goal and everything under it |
| `api/goals/progress.php` | `goal_entries` the way the goal's type needs it (a measurement, an amount, a day ticked off), then where it stands; finishes a goal that reaches 100% |
| `api/integrations/ingest.php` | health rows, and then re-derives every automatic goal, awards the points for what arrived and recalculates the Health Score |
| `api/friends/search.php` | reads only: the one account with exactly that username (case does not matter), its username and picture, and where the two of you stand |
| `api/friends/request.php` | `friendships`: send a request, accept or decline one sent to you, withdraw your own, or remove a friend (deletes the row) — each checked against the pair's own row |
| `api/friends/settings.php` | `user_profiles.allow_friend_requests` of the signed-in account |
| `api/profile/privacy.php` | `user_profiles.leaderboard_avatar`, or `ai_consent` (with its version and time), of the signed-in account |
| `api/friends/block.php` | `user_blocks`, and deletes the pair's `friendships` row |
| `api/challenges/create.php` | a `challenges` row with a fresh five-digit code, its maker `joined` in `challenge_members`, the chosen friends `invited` (friends only) |
| `api/challenges/lookup.php` | reads only — but a wrong code counts in `auth_attempts` (`challenge_code`), five in fifteen minutes, as a wrong password does |
| `api/challenges/join.php` | `challenge_members` → `joined`, on the person's own tap only: through an invitation they have, or the code (checked again, and counted when wrong) |
| `api/challenges/respond.php` | `challenge_members` → `declined` (an invitation) or `left` (what they contributed no longer counts; the code lets them back in while it runs) |
| `api/challenges/invite.php` | `challenge_members` → `invited`, for your own friends, while it runs; never somebody in it, already invited, or who turned it down |
| `api/ai/consent.php` | `user_profiles.ai_consent`, `ai_consent_version`, `ai_consent_at` |
| `api/ai/chat.php` | `ai_usage` (reserved before Gemini is asked, given back if it fails); on an answer, the question and the answer in `ai_messages`, a new `ai_conversations` row for a first question (the oldest beyond 50 are deleted), and a proposal still waiting in that conversation marked expired; `ai_service_state` when Gemini's free quota is used up. A "ja" or "nee" to a proposal does what `action.php` does |
| `api/ai/action.php` | carries out or turns down one proposal: `goals` through the same code as `api/goals/create.php` and `update.php`, `ai_messages.action_state`, and a note from Ownify in `ai_messages` |
| `api/ai/delete.php` | deletes one of the person's conversations, or all of them, with their messages |
| `api/ai/state.php` | reads only |

"Points" means `point_events`, and the `user_period_points` rollup for the
periods they land in; "the Health Score" means `daily_scores`. Rendering
the app also records the day's Health Score, only when it changed.

Any request can also replace the validator on this browser's
`user_login_tokens` row, or delete a row that has run out: that is how a
sign-in outlives its session (see *Authentication*).

Height and weight are not columns on the profile. Saving either **adds a row**
to `user_measurements`, so last month's weight is still there; the current
value is simply the newest row.

## How a goal knows where it stands

A goal is read from the person's own data only when there is some: a reading
of its measurement in the last 90 days when the goal is made. Then
`goals.tracking` is `auto` and the measurement's source (`goal_measurements`:
a metric in `health_metrics`, a weigh-in in `user_measurements`, `workouts`)
feeds it. Otherwise it is `manual`, and the goal's page is where the person
logs it (`goal_entries`). A source is not always a metric, so the vocabulary
says which kind it is.

`includes/goal-progress.php` is the only thing that calculates a percentage.
The bar, the figure, the calendar, the chart and the finish all read what it
stored, so they cannot disagree. It runs on every render of the Doelen page
and again after every import, which is what makes an automatic goal move on
its own.

Three things it will not do:

- **Invent a number.** No data means no progress, never a made-up line.
- **Treat a missing day as zero.** A day without a step count is "no data";
  in a daily streak it is not met, and the goal simply carries on.
- **Rewrite a finished goal.** The final percentage is stored with the moment
  it finished, in one statement that only matches a goal still running;
  losing more weight afterwards does not change the goal you achieved.

A Mijlpaal's baseline is fixed when it is made — the start date's measurement,
else the latest before it, else what the person typed — and the target must
lie ahead of it. Without one a decreasing goal cannot be measured at all:
75 / 82 is 91%, which would read as nearly finished to somebody who has lost
nothing. The formulas are in [GOALS.md](GOALS.md).

## What is not connected yet

Honest list, so nobody goes looking for wiring that is not there.

- **Points from what a phone does not send.** A Health Connect workout
  arrives with its type and times only, so it earns the duration points and
  counts toward the weekly bonus, but never the intensity bonus or a personal
  record — those need effort, heart rate or distance on the workout, which
  `api/health/training.php` accepts today. Strength sets are not stored at
  all, so strength progress is read from the person's own strength goals.
  A night without sleep stages earns no quality bonus.
- **App preferences** — language, units, first day of the week,
  accessibility, notifications. These have no columns and no endpoints; the
  settings screens say so rather than pretending. The theme is kept per
  device (a cookie on the website, the phone's own storage in the app), and
  profile data on those same screens *is* persisted. Until the first day of
  the week is stored, weeks start on Monday for the weekly bonus.
- **Entry screens for sleep and training.** The endpoints and the tables are
  complete and tested, but the app has no UI that posts to them yet —
  building those screens is design work, not wiring. Nutrition has one: the
  day's 1-10 rating on the Voeding page.

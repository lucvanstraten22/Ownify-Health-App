# Polar

Ownify reads a person's Polar data — trainings, sleep, steps, heart rate and
Nightly Recharge — straight from Polar, server to server, through **Polar
AccessLink Dynamic API v4** (<https://www.polar.com/polar-api-v4/>). The
person connects their Polar account once, in Instellingen › Apparaten &
Gezondheid › Koppelingen › Polar, on the website or in the Android app.
After that, Ownify fetches new data on its own.

```
Instellingen ── Polar ── Koppelen ──► auth.polar.com (Polar asks the person)
                                          │
        api/integrations/polar/callback.php ◄┘  ?code&state
                 │  state checked, code exchanged, tokens sealed
                 ▼
        user_integrations (polar)  ──►  polar_sync()  ──►  health_import_records()
                                       28 days first,      the one importer every
                                       then since the      source goes through
                                       last sync
```

| | Where |
| --- | --- |
| Connecting, tokens, syncing, mapping | `includes/polar.php` |
| Start, callback (+ the app's confirmation page), sync | `api/integrations/polar/start.php`, `callback.php`, `sync.php` |
| Disconnecting | `api/integrations/disconnect.php` (`polar_disconnect()`) |
| The scheduled sync | `tools/polar-sync.php` (cron) |
| Configuration | `config/integrations.php` (`polar`), environment `POLAR_*` |
| Database | migration `020-polar.sql`: `integration_oauth_states`, `user_integrations.sync_started_at`, source `polar` |
| Settings row | `config/settings.php` (`integrations`), `components/settings-integration.php`, `assets/js/settings.js`; Android `IntegrationCard` (`SettingsDetail.kt`) |
| Tests | `tools/polar-test.php` with the stand-in `tools/polar-fake.php` |

## What is read, and what it becomes

Everything goes through the importer Health Connect uses
(`includes/health-import.php`), so a Polar training, night or minute counts
exactly as a Health Connect one does, and the same rules count something both
sent once. Every record carries Polar's own id (`polar:…`); fetching a day
again rewrites it, never adds to it.

| Polar (v4) | Scope | Ownify |
| --- | --- | --- |
| `GET /training-sessions/list` (a window, no features) | `training_sessions:read` | `workouts`: start and end on the clock it was recorded on (Polar's local time plus `timezoneOffsetMinutes`), duration, distance, calories (`total_kcal`), average and maximum heart rate, ascent; the sport by Polar's own name (`sports/list`: "running", "road_biking"…) |
| `GET /training-sessions/list?features=samples` (one day per training day) | `training_sessions:read` | the training's heart rate, minute by minute (`heart_rate_minutes`, origin `polar:<device>`) — imported last, so its minutes win over the 24/7 reading of the same watch |
| `GET /sports/list` | `sports:read` | only to name the sport |
| `GET /sleeps` (which nights), then `?features=sleep-result&features=sleep-evaluation` per night | `sleep:read` | `sleep_sessions` + `sleep_stages`: the night under the morning it ended (`sleepDate`); its stages from the hypnogram — NREM1/2 light, NREM3 deep, REM, wake; "unknown" (e.g. poor skin contact) is left out — and its minutes worked out by the same rule as a Health Connect night (`health_connect_sleep()`); a night without a hypnogram keeps Polar's measured sleep time |
| `GET /continuous-samples?features=heart-rate-samples` (the window at once) | `continuous_samples:read` | 24/7 heart rate, minute by minute (`heart_rate_minutes`); and each night's average from the readings during it (`sleeping_hr`) |
| `GET /nightly-recharge-results` (the window at once) | `nightly_recharge:read` | `hrv` (the night's mean RMSSD, ms) and `respiratory_rate` (60 000 ÷ the mean respiration interval in ms), at the end of that night |
| `GET /activity/list` (which days), then `?features=samples` per day | `activity:read` | `steps` per hour, per device, each hour with its span — so a day's total counts every moment once against any other source (`health_metric_totals()`); never an hour still to come |
| `GET /user-devices` | `devices:read` | the watch's name on the settings row ("Apparaten: Polar Pacer Pro") |

**Not read, on purpose.** Profile and e-mail (`profile:read`): Ownify does not
need them, and the row names the watch instead. PPI, routes, tests, calendar,
training targets, skin contact and temperature: nothing in Ownify shows them.

**Read, but not kept, on purpose.** Nightly Recharge's recovery status (a
class of 1 to 6 relative to the person's own baseline, with a sub-level) is
not a 0–100 score, so it is not put in Ownify's *Herstel*. A training's
`trainingLoad` is a per-session value, while Ownify's *Hartbelasting* is one
value per day, so it is not put there either. Both would mean something
different from what the page says. Daily activity in v4 has steps and MET
values but no calories or distance per day, so those come from trainings and
other sources only. **Nothing is estimated**: a figure Polar does not send
for a device stays empty.

## Connecting

The OAuth 2.0 authorization-code flow, as Polar documents it:

- **Authorize**: `https://auth.polar.com/oauth/authorize` with
  `response_type=code`, `client_id`, `redirect_uri` (always sent — Polar
  requires it when a client has more than one), `scope` (the seven above,
  read-only) and `state`.
- **Token**: `POST https://auth.polar.com/oauth/token`, Basic auth with the
  client id and secret, `grant_type=authorization_code`, the `code` and the
  same `redirect_uri`. The answer: an access token valid 12 hours and a
  refresh token.
- **Refresh**: the same endpoint with `grant_type=refresh_token`. A new
  refresh token in the answer replaces the old one.

**The state** is 32 random bytes. Only its SHA-256 is stored
(`integration_oauth_states`), with whose it is — from the session or the
app's account token that started it, never from a request — and where it
may be finished. It is single use and lasts ten minutes; nobody can start
more than ten in ten minutes. The callback believes nothing else: the user
the connection is stored for is the state's.

- **Started on the website**: the state is bound to a random value in that
  browser's session. Only that browser, signed in as the same person, can
  finish it. Then back to Instellingen, with a line saying how it went.
- **Started in the app**: the app asks `start.php` with its account token for
  Polar's address and opens it in the browser. That browser has no Ownify
  session, so coming back it shows a page naming the Ownify account Polar is
  about to go to, with **Koppelen** and **Annuleren**. A one-time token only
  that page has must come back; the page cannot be framed. If the browser is
  signed in to Ownify as someone else, the page says so by name. The code
  waits, sealed, until the person confirms. This stops a link started by
  someone else from quietly putting your Polar data in their account.

**Tokens** are sealed with the app key (`includes/crypto.php`,
`OWNIFY_APP_KEY`) before they are stored. They never reach a browser, the app,
a log or an answer. Logs carry at most Polar's error code (`invalid_grant`)
and an HTTP status.

## Syncing

Polar v4 sends no notifications, so Ownify asks:

1. **Right after connecting**: the callback answers first and then fetches
   the last **28 days** (`fastcgi_finish_request()` on the live server). The
   row reads "Bezig met synchroniseren…" while it runs.
2. **Nu synchroniseren**, on the website and in the app
   (`api/integrations/polar/sync.php`).
3. **On a schedule**: `tools/polar-sync.php` from cron (below). It syncs
   whoever has not synced for 55 minutes.

After the first sync, each one fetches from the last successful sync with
**three days' overlap**, because a watch reaches Polar Flow when it is synced,
not when it measured. It never goes back further than 28 days, the most
Polar answers for Nightly Recharge in one request. Polar's own limits are
kept: one day per request whenever `features` are asked for, 30 days for
sleep and continuous heart rate, 28 for Nightly Recharge. One sync per person
runs at a time (`GET_LOCK`); a second answers "already syncing".

**When something goes wrong**, the person gets one sentence on the row
(`user_integrations.last_error`), never Polar's answer:

| Polar answers | Ownify does |
| --- | --- |
| 401 | refreshes the access token once and tries again |
| refresh refused (`invalid_grant`) | the connection becomes *Toegang ingetrokken*, the tokens are destroyed, "Koppel Polar opnieuw." The data stays |
| 403 | the sync is *partial*: "Polar gaf geen toegang tot … Accepteer de toestemmingen in je Polar-account (account.polar.com) en koppel Polar opnieuw." — Polar refuses data until the person has accepted its mandatory consents |
| 429 | stops at once: "Polar beperkt het aantal verzoeken even…"; the rest comes with the next sync |
| 5xx, no answer | tries once more after a moment, then *partial* with what did not come through |

The next good sync clears the sentence. A sync endpoint never answers 401 for
a Polar problem, because to the app a 401 means its own sign-in is gone.

**Rate limits.** Polar allows 3 000 requests per 15 minutes and 100 000 per 24
hours, **per client** (for all Ownify users together). A first sync takes
about 70 requests; a later one about 15. The cron run stops at a budget of
1 200 requests (`--budget`). At a 30-minute schedule and 60 minutes between
syncs per person, that is room for a few hundred connected people. Raise the
interval before that becomes tight.

## Disconnecting

**Ontkoppelen** destroys the stored tokens at once and drops any connection
still being made. What was already imported stays: it is the person's
history. Other sources are untouched. Polar's v4 API has no endpoint for a
service to withdraw its own access, so the row tells the person they can also
remove Ownify in their Polar account (account.polar.com). If they do that
first, Ownify notices at the next refresh and marks the connection revoked.

## Setting it up on the server

### 1. Register Ownify at Polar

At <https://admin.polaraccesslink.com> (sign in with a Polar Flow account),
create a client, or edit it, and add exactly this **redirect URL**:

```
https://ownify.acits.nl/api/integrations/polar/callback.php
```

Write down the client id and secret it shows.

### 2. Give the server the id and secret

The code reads `POLAR_CLIENT_ID` and `POLAR_CLIENT_SECRET` from the
environment first, then from `config/integrations.local.php`. Never put them
in `config/integrations.php` or anywhere in the repository.

**Recommended on Hestia — the local file.** It is the same mechanism as
`config/database.local.php` and `config/app.local.php`, which already work on
this server. It is git-ignored, so the deploy never carries or overwrites it.
It is also the only place the cron job (step 4) reads as well as the website:
PHP on the command line does not see a PHP-FPM pool's environment.

```bash
cd /home/<hestia-user>/web/ownify.acits.nl/public_html
cp config/integrations.local.php.example config/integrations.local.php
nano config/integrations.local.php          # the 'polar' block: client_id, client_secret
chmod 600 config/integrations.local.php
php tools/check-config.php                  # [ ok ] Polar  client id from config/integrations.local.php, …
```

Without SSH, Hestia's File Manager does the same: copy
`config/integrations.local.php.example`, rename the copy to
`integrations.local.php`, and fill in the `polar` block. If that file already
exists, add only the `polar` block to it.

**As real environment variables — the PHP-FPM pool.** Add to
`/etc/php/<version>/fpm/pool.d/ownify.acits.nl.conf`:

```ini
env[POLAR_CLIENT_ID] = "…"
env[POLAR_CLIENT_SECRET] = "…"
```

and `systemctl reload php<version>-fpm`. Hestia regenerates that file from its
template whenever the domain is rebuilt, which quietly removes the lines.
Making it permanent needs a custom template under
`/usr/local/hestia/data/templates/web/php-fpm/` (see
[DATABASE.md](DATABASE.md#where-to-put-it-on-hestia)). The cron job then needs
the same two variables in its own command line, e.g.
`POLAR_CLIENT_ID=… POLAR_CLIENT_SECRET=… php tools/polar-sync.php`. That is
why the file is the recommended way.

`POLAR_REDIRECT_URI` overrides the redirect URL. It is only needed on another
address, such as a local copy; the live one is the default.

### 3. The database

Import `database/migrations/020-polar.sql` in phpMyAdmin: select the database
first, then Import. Re-running it is safe. Until it is imported, the Polar row
says the database is not yet updated, and nothing else changes.

### 4. The schedule

In Hestia: **Cron Jobs › Add**, every 30 minutes (minute `*/30`, everything
else `*`), with the command:

```
cd /home/<hestia-user>/web/ownify.acits.nl/public_html && php tools/polar-sync.php >/dev/null 2>&1
```

Use the PHP version the site runs if `php` is another one, e.g. `php8.3`.
`tools/` cannot be reached over the web (`tools/.htaccess`), and the script
refuses anything but the command line.

### 5. Check

`php tools/check-config.php` reports Polar (whether the id and secret are set
and where they come from, never their values), the redirect URL, migration
020 and curl. The Polar row in Instellingen then shows **Koppelen**.

## Testing

**Automated**, without Polar and without real credentials:

```bash
DB_NAME=<a development database with migration 020> DB_USER=… php tools/polar-test.php
```

This starts the app and a stand-in for Polar (`tools/polar-fake.php`, which
keeps Polar's rules: Basic auth, the exact redirect URI, single-use codes,
rotating refresh tokens, 401 for an expired token, one day per request with
features). It checks 76 things:

- **State**: tampered, missing, expired, replayed or refused states connect
  nothing, and neither does a callback in another account's browser.
- **Both flows**: the website flow and the app's confirmation page,
  including Annuleren, a made-up or reused confirmation, and the warning when
  another account is signed in.
- **Tokens**: sealed in storage, refreshed before expiry and after a 401,
  marked revoked when a refresh is refused.
- **Data**: every type mapped on its own clock, with no duplicates after
  repeated syncs.
- **Errors**: 500, 429 and 403 each end in a sentence.
- **Isolation**: each account syncs only its own Polar, and no token appears
  in the app's state.
- **Disconnect and schedule**: disconnecting keeps the data and other
  sources, and the cron script runs.

**By hand, on the live site**, once steps 1–5 are done:

1. Website: Instellingen › Apparaten & Gezondheid › Polar › **Koppelen**.
   Polar's page names Ownify and the data it asks for. Agree. You come back
   to Instellingen with "Polar is gekoppeld…"; the row shows *Verbonden*,
   your watch, and soon a last-sync time. Trainingen, Slaap and the heart
   rate fill with the last 28 days.
2. Press **Nu synchroniseren**: "Gesynchroniseerd", and no training or night
   appears twice.
3. Android: the same row › **Koppelen** opens the browser. After Polar, the
   page names your Ownify account. Press **Koppelen**, go back to the app,
   and the row shows *Verbonden*.
4. Start connecting in the app, but finish in another browser where someone
   else is signed in to Ownify: the page warns, and Annuleren connects
   nothing.
5. Refuse at Polar: back in Ownify, "Je hebt Polar geen toegang gegeven",
   nothing connected.
6. **Ontkoppelen**: the row says *Niet verbonden*, and the data stays. Remove
   Ownify at account.polar.com as well if you like.
7. In Polar Flow, remove Ownify's access while still connected, then press Nu
   synchroniseren: *Toegang ingetrokken*, "Koppel Polar opnieuw."
8. On the server: `php tools/polar-sync.php --min-age=0` prints one line per
   connected person (`user 12: ok, 340 records, 18 requests`).

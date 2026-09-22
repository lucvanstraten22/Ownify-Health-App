# Health Connect — the Android companion app

Everything on the JoLu side is built and tested. This is the contract the
Android app implements, and the reasoning behind the shape of it.

## Why there has to be an app at all

Health Connect is an on-device Android API. The data sits on the phone, behind
permissions the person grants to an app on that phone, and **there is no web,
REST or server-to-server way to reach it**. No OAuth flow, no API key and no
amount of server code gets at it.

This is not a JoLu limitation. Google Fit's REST API was the one that could be
called from a web server, and it is closed to new developers and supported only
to the end of 2026. Google's migration FAQ says it plainly: *"There is no
alternative to the Fit REST API."* The two things it points at are Health
Connect, which is the phone, and the Google Health API, which is the cloud but
returns **Fitbit and Pixel Watch data only** — nothing from a plain Android
phone.

So: an app on the phone reads Health Connect and posts to JoLu. That app is the
only part that does not exist yet.

## How the app proves whose data it is sending

It cannot use the session cookie — it is not a browser, and a cookie living
long enough for a background sync is a cookie that is dangerous in a browser.
It must not hold the account password either: a credential that can change the
e-mail address is far too much authority for something whose job is to upload
step counts.

So it holds a device token, with exactly that authority and no more.

```
website                          phone app
   |                                 |
   |  1. user taps Koppelen          |
   |     -> 8-character code         |
   |                                 |
   |            2. user types the code in the app
   |                                 |
   |  <---- 3. POST /pair.php {code} |
   |  ----> device token (once) ---->|
   |                                 |
   |  <---- 4. POST /status.php -----|   still paired?
   |  <---- 5. POST /ingest.php  ----|
   |         Authorization: Bearer   |
```

The code is single-use and lives ten minutes. The token is 256 bits of server
randomness. **Neither is stored in a form that can be read back** — only
SHA-256 hashes are kept, so a database dump yields nothing replayable. Whose
data a request carries comes from the token and from nothing in the body; there
is no user id in this contract at all, because a user id in a body is a user id
an attacker can change.

Revoking a device kills that token and nothing else, so losing a phone costs
you that phone rather than every phone. Disconnecting in Settings revokes every
phone for that source.

## The endpoints

### 1. Exchange a pairing code — `POST /api/integrations/pair.php`

No session, no CSRF token. The pairing code *is* the credential.

```json
{ "code": "56B7QFMD", "label": "Pixel 8", "platform": "android", "app_version": "1.0.0" }
```

```json
{ "ok": true, "token": "3f2a…64 hex chars…", "provider": "google_health_connect" }
```

Store the token in `EncryptedSharedPreferences` or the Android Keystore. **It is
returned once and cannot be fetched again** — losing it means pairing afresh.

Every failure answers `401` with the same message, deliberately: a response that
distinguished "no such code" from "that code expired" would let somebody grind
the code space and learn which guesses were once real.

### 2. Send records — `POST /api/integrations/ingest.php`

```
Authorization: Bearer <device token>
Content-Type: application/json
```

```json
{ "records": [ { "recordType": "SleepSession", "metadata": { "id": "…" }, … } ] }
```

Up to 2000 records per call. Sending nothing is a **success**, not an error —
a phone with no new data or no granted permissions is a normal Tuesday, and an
error there would make the app retry forever.

```json
{ "ok": true, "written": 7, "skipped": 0, "days": ["2026-09-18"],
  "unmapped": { "MenstruationFlow": 1 }, "problems": [] }
```

`unmapped` lists record types JoLu does not handle yet, by type and count, so
they surface instead of vanishing.

`401` means the token is unknown or revoked. The app's answer to both is the
same: stop syncing and ask the user to pair again.

### 3. Am I still paired? — `POST /api/integrations/status.php`

```
Authorization: Bearer <device token>
```

```json
{ "ok": true, "provider": "google_health_connect", "label": "Pixel 8",
  "last_sync_at": "2026-09-22T08:14:00Z", "last_sync": "ok", "max_records": 2000 }
```

Call it at startup, before gathering anything. Without it the only way to learn
the token is dead is to read the records, build the batch, upload it and get a
`401` — the whole job done to find out it was pointless, on every sync, for as
long as the user never re-pairs.

`401` means the same as it does on ingest: stop syncing, ask the user to pair
again.

It says nothing about the account behind the token — no e-mail, no username, no
user id. A stolen token should reveal no more than the upload endpoint it was
stolen for already allows.

`POST`, not `GET`, because a cached "you are fine" is the one answer that must
never be stale.

### 4. Disconnect

Handled on the website, two ways, and the app cannot tell them apart — both
just start answering `401`:

- **Ontkoppelen on one phone.** The settings screen lists every phone paired to
  a source, with the last time each one sent anything, and revokes them one at
  a time. Losing a phone costs you that phone.
- **Disconnecting the source.** Revokes every phone on it at once.

## What the owner sees

Everything the server knows about a phone, the person it belongs to can see:
its name, the platform it reported, and when it last sent anything. Never the
token — only a SHA-256 hash of that is stored, so there is nothing to show.

That list is the answer to "what has access to my health data", and it has to
be answerable per phone rather than per source, which is why each row revokes
on its own.

## The record format

Health Connect has **no wire format** — its records are Kotlin objects — so
somebody has to decide what they look like as JSON. That decision lives on the
server (`includes/health-connect-map.php`), not in the app, because a mapping
mistake is then a deploy rather than an app release and a week of people not
updating.

The app's job is therefore deliberately dumb: read records, serialise them with
Health Connect's own field names, post, forget. Units stay SI, as Health
Connect gives them — converting on the phone is one more thing to get wrong
somewhere it cannot be fixed quickly.

`metadata.id` is **required** on every record. It is Health Connect's own record
id, and it is what makes re-syncing safe: the same night sent twice is the same
id twice, and the server upserts on it. A record without one is refused rather
than guessed at, because a source that cannot name its records cannot be synced
without eventually duplicating them.

### What is mapped today

| Health Connect record | Becomes | Notes |
| --- | --- | --- |
| `SleepSession` | a sleep session | stages → deep/REM/light/awake minutes; efficiency derived from time asleep over time in bed |
| `ExerciseSession` | a workout | `exerciseTypeName`, `title` or `exerciseType`, whichever is present |
| `Steps` | metric `steps` | |
| `Distance` | metric `distance` | metres → km |
| `ActiveCaloriesBurned` | metric `active_energy` | |
| `TotalCaloriesBurned` | metric `total_energy` | |
| `FloorsClimbed` | metric `floors` | |
| `HeartRate` | metric `sleeping_hr` | samples averaged; storing each would be a row every few seconds for a figure nothing reads |
| `RestingHeartRate` | metric `resting_hr` | |
| `HeartRateVariabilityRmssd` | metric `hrv` | |
| `OxygenSaturation` | metric `spo2` | |
| `RespiratoryRate` | metric `respiratory_rate` | |
| `Vo2Max` | metric `vo2max` | |
| `SkinTemperature`, `BodyTemperature` | metric `skin_temp` | |
| `Hydration` | metric `water` | litres |
| `Nutrition` | a meal + its nutrients | sodium grams → milligrams |
| `Weight` | measurement `weight` | kg |
| `Height` | measurement `height` | metres → cm |
| `BodyFat` | measurement `body_fat_pct` | |
| `LeanBodyMass` | measurement `lean_mass` | |

Anything else is reported in `unmapped` and ignored. Adding one is a case in
`health_connect_map_one()` — server side, no app release.

### Example

```json
{
  "recordType": "SleepSession",
  "metadata": { "id": "a3f1…", "dataOrigin": "com.google.android.apps.fitness" },
  "startTime": "2026-09-17T23:10:00Z",
  "endTime":   "2026-09-18T06:42:00Z",
  "stages": [
    { "startTime": "2026-09-17T23:10:00Z", "endTime": "2026-09-18T00:50:00Z", "stage": 4 },
    { "startTime": "2026-09-18T00:50:00Z", "endTime": "2026-09-18T02:30:00Z", "stage": 5 }
  ]
}
```

Stage numbers are Health Connect's own: 1 awake, 2 sleeping, 3 out of bed,
4 light, 5 deep, 6 REM, 7 awake in bed.

## What the app has to do

1. Declare the Health Connect permissions it needs, and ask for them. Users
   grant **per category**, so assume you will get some and not others — a
   missing category is a category with no data, never a zero.
2. On first run, ask for the pairing code and exchange it.
3. Sync periodically (WorkManager). Use Health Connect's **changes token** so
   each run sends what changed rather than everything; overlap the last window
   by a day or two, because data arrives late and gets corrected. Re-sending is
   safe by design.
4. Check `status.php` before each sync, and skip the work when it answers
   `401`.
5. On `401`, clear the stored token and prompt to pair again.

A thin Kotlin sketch of the sync:

```kotlin
val client = HealthConnectClient.getOrCreate(context)

val sessions = client.readRecords(
    ReadRecordsRequest(
        recordType = SleepSessionRecord::class,
        timeRangeFilter = TimeRangeFilter.between(since, Instant.now())
    )
).records

val json = sessions.map { r ->
    mapOf(
        "recordType" to "SleepSession",
        "metadata" to mapOf("id" to r.metadata.id),
        "startTime" to r.startTime.toString(),
        "endTime" to r.endTime.toString(),
        "stages" to r.stages.map {
            mapOf("startTime" to it.startTime.toString(),
                  "endTime" to it.endTime.toString(),
                  "stage" to it.stage)
        }
    )
}

// POST { "records": json } with Authorization: Bearer <token>
```

## Testing the server without a phone

The whole flow can be driven with curl, and is:

```bash
tools/health-connect-test.sh                             # against localhost
BASE_URL=https://your-domain.tld tools/health-connect-test.sh
```

Run it from a checkout — on the Hestia server, over SSH, in the web root. It
makes the same requests the Android app will make, in the same order, through
the same endpoints with the same authentication. Nothing is stubbed and there
is no test mode in the server: the account is created through the public
registration endpoint, the pairing code through the signed-in one, and the
device token is whatever the server issues.

What it walks through:

| | |
| --- | --- |
| 1 | a test account signs up |
| 2 | the website mints a pairing code — 8 characters, ten minutes |
| 3 | the phone exchanges it with no session and no CSRF token |
| 4 | it receives a 64-character device token |
| 5 | it posts a realistic batch with `Authorization: Bearer` |
| 6 | the records are checked in the tables they landed in |
| 7 | the identical batch is posted again |
| 8 | nothing is duplicated — rows are counted, not just read |
| 9 | an empty batch is posted |
| 10 | it succeeds, having written nothing |
| 11 | the owner disconnects in Settings |
| 12 | the old token is refused with `401` |
| 13 | the health data it already sent is still there |

Along the way it also checks that a used code cannot be used twice, that a
guessed code is refused in the same words as an expired one, that a missing,
unknown or malformed token all get `401`, and that a cloud source refuses to
issue a pairing code at all.

It leaves behind a real account named `hctest_<timestamp>`, and removes it
again at the end — one `DELETE FROM users`, which cascades. `KEEP=1` keeps it
to look at in phpMyAdmin; remove it later with:

```bash
php tools/hc-verify.php --user=hctest_… --cleanup
```

`tools/hc-verify.php` is the other half: it holds the test batch *and* what
each record must have become, so a fixture and its expectations cannot drift
apart. `--fixture` prints the batch the shell script posts. It refuses to touch
an account whose name does not start with `hctest`.

Running it across the network against somebody else's server, where this
checkout's database credentials are no use, set `NO_DB=1`: the HTTP contract is
still checked in full, the table-level assertions are skipped, and the test
account is left for you to remove on the server.

Both tools are CLI-only — they answer `404` to a browser, and `tools/.htaccess`
denies the directory as well, because a configuration report is a map of the
server.

**One thing worth knowing:** `pairing-code.php` issues a code even while
`app_available` is `false`. That is deliberate, not a gap. The flag decides
whether the *devices screen* offers pairing; the endpoint behind it is
session-authenticated and CSRF-checked, and a code only ever grants access to
the account that asked for it. It is what lets the flow be tested without
flipping a production switch.

## Turning it on

The devices screen says Health Connect needs an app that does not exist yet,
rather than handing out a code with nothing to type it into. One switch changes
that, in `config/integrations.php`:

```php
'google_health_connect' => [
    'app_available' => true,        // was false
    'store_url'     => 'https://play.google.com/store/apps/details?id=…',
],
```

The server half is finished and tested; nothing else needs to change.

## What you still have to do outside the code

- **Build and publish the Android app.** Health Connect access requires a
  declaration form about which data types you read and why, and health data has
  its own Play Store policy. Check the current requirements when you submit —
  they change, and they are stricter than for an ordinary app.
- **Set `JOLU_APP_KEY`** on the server — `docs/DATABASE.md`, *Where to put it
  on Hestia*, has the exact commands. None of this flow needs it: a device
  token is hashed, not encrypted, and the test above passes on a server with no
  key at all. What needs it is any future OAuth source, which refuses to store
  a token rather than keeping it in the clear. `php tools/check-config.php`
  says whether a machine has one.
- **Serve over HTTPS.** A bearer token over plain HTTP is a token anyone on the
  network has.

## What this cannot do

- **iPhones.** Apple Health is the same shape of problem with a different SDK;
  the server side here already accepts an `apple_health` provider, but it needs
  its own app.
- **Backfill before the app is installed.** Health Connect keeps a limited
  history, so a new user brings whatever their phone still holds, not years.
- **Data the user did not grant.** Permissions are per category and revocable
  at any time. A category you cannot read is a category with no score — never a
  zero.

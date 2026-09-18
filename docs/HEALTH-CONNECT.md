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
   |  <---- 4. POST /ingest.php  ----|
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

## The three endpoints

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

### 3. Disconnect

Handled on the website. The app simply starts getting `401`.

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
4. On `401`, clear the stored token and prompt to pair again.

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
- **Set `JOLU_APP_KEY`** on the server (see `docs/DATABASE.md`). Pairing works
  without it, but any future OAuth source refuses to store tokens rather than
  keeping them in the clear.
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

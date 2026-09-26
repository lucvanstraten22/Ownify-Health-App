# JoLu Android

The Android client of JoLu. It reads Health Connect and sends the records to
the JoLu backend, which is the single source of truth: every total, score,
point and goal is worked out there, never here.

The screen is a technical test interface for now; the JoLu section at the
bottom is where pairing and syncing live.

## Syncing with JoLu

One pipeline, `jolu/JoluSyncRunner.kt`, does every sync:

1. the stored device token (`JoluConnection`, encrypted by `JoluTokenStore`) —
   none: nothing is read or sent;
2. Health Connect access — none: nothing is sent;
3. the records of the last 7 days (`HealthConnectSyncReader`), granted types
   only;
4. the JSON the server reads (`IngestPayload`), Health Connect's own format;
5. `POST /api/integrations/ingest.php` in batches (`JoluApi.ingest`), with
   `Authorization: Bearer <token>` over HTTPS — no user id, no token in the
   body.

The same 7 days are sent again every time. That is safe: the server updates a
record it already has (by Health Connect's `metadata.id`), counts two apps'
records of the same moment once, and pays points once. Nothing is added up,
removed or deduplicated on the phone.

Two things start that pipeline:

- **the "Sync to JoLu" button** (`JoluSync.sync`) — now, in the app;
- **the automatic sync** (`JoluSyncWorker`, scheduled by `JoluBackgroundSync`).

### When the automatic sync runs

WorkManager runs it; no service stays running and nothing wakes the phone that
the system would not.

| When | Condition |
| --- | --- |
| every hour (WorkManager may run it in the last 20 minutes of the hour) | paired, on a network, battery not low |
| right after pairing | on a network |
| right after Health Connect access is granted in the app | on a network |
| on opening the app, if the last successful sync is over 30 minutes old | on a network |

WorkManager keeps the schedule when the app is closed and after a restart.

| What happens | Then |
| --- | --- |
| synced | the next run comes on schedule |
| no token, or the server answers `401` (phone removed on the website, account gone) | the token is forgotten and the automatic sync is switched off until the phone is paired again — an invalid token is never used twice |
| offline, a timeout, a server error (5xx, 429) | retried with exponential backoff from 10 minutes, at most 3 times, then the next hourly run; the token is kept |
| no Health Connect, no permission, or no background access | nothing is sent and nothing retried; the next scheduled run looks again (without using the network) |

The phone keeps only how the last sync went (`shared_prefs/jolu_sync_status.xml`:
the time of the last successful sync, of the last attempt, its outcome and how
many records the server stored) — no health data. It is excluded from backups
and device transfers, as the token is.

## Permissions

| Permission | Why |
| --- | --- |
| `android.permission.health.READ_*` (steps, distance, active calories, heart rate, sleep, nutrition, exercise) | the records a sync sends; granted per category, a missing one is skipped |
| `android.permission.health.READ_HEALTH_DATA_IN_BACKGROUND` | reading Health Connect while the app is **closed** — see below |
| `android.permission.INTERNET` | the JoLu server |
| `WAKE_LOCK`, `ACCESS_NETWORK_STATE`, `RECEIVE_BOOT_COMPLETED`, `FOREGROUND_SERVICE` | added by WorkManager's own manifest; JoLu starts no foreground service |

### Background access

Health Connect only lets an app read while it is in the foreground. To read
while JoLu is closed it needs `READ_HEALTH_DATA_IN_BACKGROUND`
("Access data in the background"), which:

- exists only where Health Connect reports
  `HealthConnectFeatures.FEATURE_READ_HEALTH_DATA_IN_BACKGROUND` as available
  (checked at runtime, `JoluBackgroundSync.backgroundRead`);
- is asked for separately: the JoLu section shows **Allow background sync**
  when it is supported and not granted yet.

Without it the automatic sync still runs whenever JoLu is open, and when it
is closed it skips (sending nothing) and says so: "Syncs while JoLu is open;
allow background access to sync when it is closed". On a phone whose Health
Connect has no background reading at all, the section says that JoLu syncs
while it is open.

A Play Store release has to declare this background use in the Health
Connect declaration form; the in-app rationale
(`PermissionsRationaleActivity`) already describes it.

## Tests

```bash
./gradlew :app:testDebugUnitTest
```

`app/src/test/.../jolu/JoluBackgroundSyncTest.kt` runs under Robolectric: the
real WorkManager (its test driver standing in for time, network and battery)
schedules the real `JoluSyncWorker`, which runs the real pipeline against a
JoLu server on localhost. Only Health Connect and the Android Keystore, which
the JVM does not have, are stand-ins. The first run downloads Robolectric's
Android jar.

# JoLu Android

The Android client of JoLu. It reads Health Connect and sends the records to
the JoLu backend, which is the single source of truth: every total, score,
point and goal is worked out there, never here.

The screens are a native Jetpack Compose copy of the JoLu web app: the same
pages, panels, copy, tokens, glass and gestures, filled from the same server
pipeline the website renders from (`api/app/state.php`). What the app shows
and the few places it cannot copy the website are in
[`docs/PARITY.md`](docs/PARITY.md).

- **Signing in and registering**: the opening screen's buttons, or the
  account button in the header, open the account panel (Inloggen / Account
  aanmaken).
- **Pairing**: "Of koppel deze telefoon met een koppelcode" under the login
  form.
- **Syncing**: Instellingen › Apparaten & Gezondheid, this phone's Health
  Connect card (and, on a phone that is only paired, its opening screen).

## Signing in

Two ways to connect a phone, both the server's (docs/APP-AUTH.md in the
backend):

| Way | Endpoint | Credential | May |
| --- | --- | --- | --- |
| **Inloggen** (username or e-mail + password) | `api/auth/app-login.php` | account token | sync, and act as the account |
| **Account aanmaken** (username, e-mail, password) | `api/auth/app-register.php` | account token | the same |
| pairing code from the website | `api/integrations/pair.php` | sync token | sync only |

The phone holds **one** credential — `JoluCredential(token, scope)` — kept by
`JoluTokenStore`: the token encrypted with a key in the Android Keystore, the
scope (`sync`/`account`) beside it. A token stored before scopes existed was
made by pairing and reads as `sync`, so a phone paired earlier carries on.
No password, user id or profile is ever stored, and nothing is logged.

Signing in on a paired phone sends the sync token along as the bearer token;
the server turns that phone's row into the account row with a new token and
the old one stops working, so the phone keeps one entry under Apparaten on
the website and the automatic sync carries on with the account token. A token
of another account is left alone by the server.

- **Restart**: a stored account token signs in again without a password
  (profile and targets are read with it). Offline, it stays signed in.
- **Uitloggen** (`api/auth/app-logout.php`): the token is forgotten on the
  phone and the automatic sync stopped first, then it is revoked on the
  server — the phone signs out even if the server cannot be reached. Health
  Connect permissions are not touched; signing in again syncs straight away.
  A phone that was paired before signing in was the same row, so it is signed
  out too: sign in again, or pair again, to sync.
- **401** anywhere (revoked on the website, the account gone, a year unused):
  the token is forgotten, automatic sync stops, and the screen asks to sign
  in (account) or pair (sync) again.

### A late 401 cannot sign anybody out

A sync can be on its way with the paired token at the moment the phone signs
in; the server has already replaced that token, so the sync gets 401. That
401 is about a token that is gone, and it must not delete the account token
just stored. So a 401 forgets the credential **only if it is still the very
token that was refused** (`JoluConnection.rejected`, under the same lock as
every write to the credential); otherwise nothing is forgotten or stopped,
and the sync run starts once more with the token stored now
(`JoluSyncOutcome.TokenReplaced`). The same holds for a late 401 after
signing out.

## Syncing with JoLu

One pipeline, `jolu/JoluSyncRunner.kt`, does every sync:

1. the stored token — sync or account, both may upload (`JoluConnection`,
   encrypted by `JoluTokenStore`) — none: nothing is read or sent;
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

- **Nu synchroniseren** (`JoluSync.sync`) — now, in the app;
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
| no token, or the server answers `401` (phone removed on the website, account gone) | the token is forgotten and the automatic sync is switched off until the phone is paired or signed in again — an invalid token is never used twice |
| `401` for a token the phone has already replaced (signed in or out meanwhile) | nothing is forgotten or stopped; the run starts once more with the current token |
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
- is asked for separately: this phone's Health Connect card shows **Op de
  achtergrond toestaan** when it is supported and not granted yet.

Without it the automatic sync still runs whenever JoLu is open, and when it
is closed it skips (sending nothing) and says so: "Synchroniseert terwijl
JoLu open is; sta de achtergrond toe voor daarna". On a phone whose Health
Connect has no background reading at all, the card says that JoLu syncs
while it is open.

A Play Store release has to declare this background use in the Health
Connect declaration form; the in-app rationale
(`PermissionsRationaleActivity`) already describes it.

## Tests

```bash
./gradlew :app:testDebugUnitTest
```

Everything runs under Robolectric against a JoLu server on localhost
(`FakeJoluServer` in `SyncTestKit.kt`, answering with a real
`api/app/state.php` response). The real WorkManager (its test driver standing
in for time, network and battery) schedules the real `JoluSyncWorker`, which
runs the real pipeline. Only Health Connect and the Android Keystore, which
the JVM does not have, are stand-ins. The first run downloads Robolectric's
Android jar.

- `jolu/JoluAccountTest` — signing in, registering, signing out, the session
  after a restart, expired and revoked tokens, scopes, and the late-401 race
  (a sync held mid-request while the phone signs in), through the real
  `JoluConnection`, `JoluApi` and worker.
- `jolu/JoluBackgroundSyncTest` — when the automatic sync runs, what each
  answer does (401, offline, 5xx, no access), pairing, and that the button
  and the worker run one pipeline and send the same records again unchanged.
- `jolu/JoluTokenStoreTest` — what is kept in `jolu_connection.xml`,
  including a token stored before scopes existed.
- `data/JoluAppStateTest` — the app's read and its writes: the Bearer token
  only, a sync token refused the account (403, nothing forgotten), offline
  keeps the last pages, a revoked token signs out, and a stale 401 on a read
  or a write never signs out the session that replaced it.
- `data/AppDataParseTest` — the state response parsed as the pages read it.
- `ui/JoluAppFlowTest` — the whole app: sign in, a wrong password, register,
  restore, sign out, offline and retry, a revoked session, a paired phone,
  the tabs, a detail and back, the panels, and accessibility (46 dp targets,
  headings, the navigation's name).
- `ui/PhoneSyncUiTest` — this phone's Health Connect card in each state
  (unavailable, update needed, no/part/all access, background) and "Nu
  synchroniseren" end to end: success, revoked, offline.
- `ui/PresentationHelpersTest` — the few things the app formats itself
  (points, avatar accent and initial, member since, slot note, spans, Dutch
  numbers, sync moments), each checked against the website's own output.

### Side-by-side with the website

`ui/ScreenshotCapture` takes a shot of every page, detail, panel and wizard
step at 412 × 915 dp and 420 dpi, and writes each text's position, size,
weight and spacing beside it. It is skipped unless asked for:

```bash
./gradlew :app:testDebugUnitTest --tests '*ScreenshotCapture*' \
  -Djolu.shots=<dir> -Djolu.state=<state.json> \
  -Djolu.state.free=<state.json of an account with a free goal slot> \
  -Djolu.server=<the JoLu server the photos come from>
```

`<state.json>` is `api/app/state.php`'s answer for a signed-in account, read
the same day as the website is captured (the pages carry "Vandaag"). Add
`-Djolu.tree=1` for a dump of the layout tree. The website is captured in a
browser at the same size and pixel ratio (2.625), with reduced motion, and
the two dumps are compared text by text.

## Regenerating the icons

`ui/design/JoluIcons.kt` is generated from the website's
`components/icons.php`; after changing an icon there:

```bash
python3 HealthappAndroid/tools/gen_icons.py components/icons.php \
  HealthappAndroid/app/src/main/java/com/healthapp/android/ui/design/JoluIcons.kt
```

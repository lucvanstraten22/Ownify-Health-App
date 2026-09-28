# Signing in in the JoLu app

How the JoLu Android app signs in **as an account** — the foundation for the
full app. The website's own sign-in (session cookie, CSRF token, the
stay-signed-in cookie) is unchanged and described in
[DATABASE.md](DATABASE.md#authentication); pairing a phone for Health Connect
is unchanged and described in [HEALTH-CONNECT.md](HEALTH-CONNECT.md).

Needs `database/migrations/013-app-tokens.sql`. Until it is imported
everything works as before, the app's sign-in endpoints answer `503`, and
failed sign-ins are not counted.

## Three ways in, two kinds of token

| Who | Credential | May |
| --- | --- | --- |
| the website | session cookie + CSRF token | everything, as the signed-in person |
| a paired phone | **sync token** — `user_devices.scope = 'sync'`, from a pairing code | upload records; read what a sync needs (`status`, `profile`, `nutrition-targets`) |
| the JoLu app, signed in | **account token** — `user_devices.scope = 'account'`, from signing in in the app | all of the above, and act as the account on every endpoint that takes `api_require_account_user()` |

Both tokens are 256 bits from `random_bytes()`, shown to the app once and
stored only as SHA-256. They are sent only as `Authorization: Bearer <token>`,
never in a body or a URL, and never logged. They are unrelated to the password,
to the browser's sign-in cookie and to each other.

**A sync token never becomes an account token.** Pairing always writes
`scope = 'sync'`. Signing in in the app is the only thing that issues an account
token, and signing in means the password or Google was just proved. When the
app signs in on a phone that already has a token of the same account and sends
it along, that phone's row gets a **new** token with the account scope and the
old one stops working that instant — one phone stays one row in Settings, with
its sync history.

## Endpoints

All `POST`, JSON in (a form works too), JSON out, `Cache-Control: no-store`.
The sign-in endpoints need https (plain http only from localhost) and no CSRF
token: there is no browser and no cookie to protect.

### `api/auth/app-login.php`

```json
{ "identifier": "sanne", "password": "…",
  "label": "Pixel 10", "platform": "android", "app_version": "1.0" }
```

`identifier` is the username or the e-mail address, as on the website. The
password is checked by `auth_login_password()`, the website's own function.

| Status | When |
| --- | --- |
| 200 | `{ ok, token, scope: "account", provider: "password", account: { username, avatar, created_at, age } }` |
| 401 | wrong name or password — one message for both, whether the name exists or not; also an account that is not active |
| 403 | plain http from elsewhere than localhost |
| 409 | the account already has 5 phones on this source — remove one in Settings |
| 422 | a field empty or not a string |
| 429 | too many failures for this name from this address; `Retry-After` in seconds |
| 503 | migration 013 is not in |

No user id, no e-mail address, nothing about health comes back.

### `api/auth/app-register.php`

```json
{ "email": "…", "username": "…", "password": "…", "label": "…", "platform": "android", "app_version": "…" }
```

Creates the account with `auth_register_email()` — the website's rules, hash
and messages — and answers like `app-login.php`. `422` with the website's
message for an invalid or taken address or username, or a password shorter
than 8 or longer than 200. `429` after 5 "taken" answers for one address from
one network address in 15 minutes. No browser session or cookie is made.

### `api/auth/app-logout.php`

`Authorization: Bearer <account token>`, no body. Revokes that token and
nothing else — not the account's other phones, not a browser.

| Status | When |
| --- | --- |
| 200 `{ ok, revoked: true }` | revoked just now |
| 200 `{ ok, revoked: false }` | nothing to revoke: unknown, already revoked, lapsed, or a sync token |
| 400 | no token sent |

Always a success when a token is sent, so the app can always finish signing
out (forget the token, stop syncing) — also when the website revoked it first.
A sync token is not revoked here; that is the website's revoke button, as
always.

### `api/auth/app-google.php`

Sign in with Google through Android Credential Manager, in up to three
requests after a question. The app keeps cookies for this conversation only:
the nonce and a new person's verified identity wait in the server session,
exactly as on the website, and the session is thrown away once the token is
issued.

0. `{ "action": "status" }` → `{ ok, available }` — whether this server offers
   Google to the app at all (Web client and Android clients configured, the
   database and migration 013 there). Answered even when it is not set up, so
   the app can show its button as the website shows its own: working, or
   disabled with "Google is nog niet gekoppeld."
1. `{ "action": "nonce" }` → `{ ok, nonce, expires_in: 600, client_id }`.
   `client_id` is the website's **Web** client id — public: it is in every
   Google sign-in address the website sends a browser to — so the app carries
   no copy of it that could drift from the audience checked in step 2. The
   app passes both to Credential Manager:
   `GetSignInWithGoogleOption.Builder(client_id).setNonce(nonce)` (the option
   for a Sign in with Google button; `GetGoogleIdOption` with
   `setServerClientId` and `setNonce` gives the same token).
2. `{ "action": "verify", "id_token": "…", label, platform, app_version }`.
   Verified by `google_signin_verify()` — the website's check: RS256 against
   Google's keys, issuer, **audience = the Web client**, **authorised party
   (`azp`) = one of the app's Android clients**, expiry, issue time, and the
   nonce from step 1, which is then used up. Then `google_signin_match()`,
   the rules the website uses:
   - Google account already linked → `{ ok, status: "signed_in", token, scope, provider: "google", account }`
   - new, with an address Google has verified and no password account uses →
     `{ ok, status: "choose_username", email, expires_in: 600 }`
   - the address belongs to a password account → `409`, the website's message
     (sign in with the password; link Google in Settings on the website).
     **Never merged** on the strength of an e-mail address.
   - no verified address, or an inactive account → `403`
   - anything wrong with the token → `401`, one message; the reason goes to
     the server log, never the token
   - Google's keys could not be fetched → `502`
   - no nonce in this conversation, or it expired or was used → `410`
3. `{ "action": "username", "username": "…", label, platform, app_version }`
   → `{ ok, status: "signed_in", token, … }`, the account made by
   `google_signin_create_account()`, already linked. `422` with the website's
   message for a bad or taken username; `410` when the ten minutes are up or
   the conversation was not started by step 2.
   `{ "action": "cancel" }` forgets the waiting identity.

Nothing the app says about the person is believed — only what comes out of an
ID token verified here. A token from a paired phone sent along as the bearer
is upgraded, as with `app-login.php`.

Every step but `status` answers `501` until the Android clients are
configured (below).

## Acting as the account: `api_require_account_user()`

`api/goals/update.php` was the first endpoint that takes both kinds of caller;
the app's read (`api/app/state.php`) and every endpoint the app's pages write
to now do too — see [APP-STATE.md](APP-STATE.md). Through
`api_require_account_user()` in `api/bootstrap.php`:

| Request | Answer |
| --- | --- |
| a bearer **account** token of an active account | accepted, no CSRF token |
| a bearer **sync** token | `403` — it may upload, not change goals |
| an unknown, revoked or lapsed bearer token | `401` |
| no bearer token | the website's rules, unchanged: CSRF token (`419`), then the session (`401`) |

A request that carries a bearer token is decided by it alone; it never falls
back to the session cookie. No CSRF token is needed with a bearer token because
a browser never adds an `Authorization` header on its own and another site
cannot make it add one without a CORS preflight this server does not answer.
Every other endpoint still takes the website's session only (signing in on
the website, Google's redirects, the manual sleep, training and nutrition
entry endpoints); one moves over when the app needs it, by swapping its
`api_require_csrf()` + `api_require_user()` for `api_require_account_user()`.

## Lifetime, revocation, switching accounts

- **Lifetime.** An account token lapses after **365 days without use**
  (`DEVICE_ACCOUNT_TOKEN_DAYS`). Every authenticated request moves
  `user_devices.last_seen_at`, which is what the days count from — so no extra
  column. A lapsed token is refused like a revoked one (`401`), is not listed in
  Settings and does not count towards the 5 phones. Sync tokens do not lapse.
- **No rotation yet, on purpose.** The app's background sync and its screens
  can use the token at the same moment; rotating on use means one of them can
  miss the new token and sign the phone out. That needs a grace window like
  the website's stay-signed-in cookie has, and it is left for later.
- **Revocation.** Settings → Apparaten & Gezondheid lists the app under Health
  Connect like a paired phone, with the same revoke button; disconnecting
  Health Connect revokes every phone on it, the app included; deleting the
  account deletes every token. The app's next request gets `401`, and the app
  signs out.
- **Switching accounts.** One token belongs to one account. Sign out
  (`app-logout.php`), forget the token, sign in as the other account. A token of
  account A sent along when signing in as B is left alone (it is A's phone
  entry, A can see and revoke it); B gets a row of its own.

## Failed sign-ins are limited

`includes/auth-throttle.php`, for the website's `api/auth/login.php` and the
app's `app-login.php` alike (the same counter):

- **5 wrong passwords per 15 minutes** for one name from one network address.
  After that the name is refused from that address — without the password
  being checked — until the oldest failure is 15 minutes old. `429`, with
  `Retry-After`.
- A correct password clears the count. Nothing is ever locked for good.
- A name without an account is counted and refused exactly the same way, with
  the same words, so the limit tells nobody which names exist.
- Another name, or the same name from another address, is not affected.
- Stored: one row per failure in `auth_attempts` — a SHA-256 of the name and
  the address together, never either as text — deleted after a day.
- The address is `REMOTE_ADDR` only. A forwarded-for header is whatever the
  caller says, so it is not believed. **Behind the Hestia proxy this must be
  the visitor's address** (Apache's `mod_remoteip` from nginx's `X-Real-IP`,
  which Hestia sets up); if it were the proxy's, the limit would be per name
  only — still temporary, but then somebody could make one person wait 15
  minutes by failing on purpose.

## Setting up Google for the app

**What already exists and is reused.** The website's "Doorgaan met Google"
uses an OAuth client of type **Web application** in a Google Cloud project
(`client_id`, `client_secret`, `redirect_uri` in `config/auth.local.php` on
the server; [DATABASE.md](DATABASE.md#google-sign-in)). The app uses that same
project, the same consent screen (Branding, Audience, the three basic scopes)
and the same Web client — the ID token is made out to it, and the server
checks exactly that. **The only new thing is one OAuth client of type
Android per key the app is signed with.** No new project, no new Web client,
no new secret: an Android client has no secret at all.

**Nothing goes into the Android project or the repository.** The app gets the
Web client's id from the server (step 1 above); the server gets the Android
clients' ids from `config/auth.local.php`, which is git-ignored and never
deployed. Client ids are not secrets, but they belong to the deployment. The
Web client's secret stays where it is, on the server.

### 1. The SHA-1 of each key the app is signed with

Google gives an Android client only to one package name signed with one
certificate. The package name is `com.healthapp.android` (`applicationId` in
`HealthappAndroid/app/build.gradle.kts`). The SHA-1 depends on who signs:

| Build | Signed with | Where its SHA-1 is |
| --- | --- | --- |
| **Debug** — Android Studio's Run ▶, `./gradlew installDebug` | this computer's debug key, `~/.android/debug.keystore` (Windows: `%USERPROFILE%\.android\debug.keystore`), made by Android Studio. **Every computer has its own.** | `./gradlew signingReport` in `HealthappAndroid` (Android Studio: the Gradle panel → *app* → *Tasks* → *android* → *signingReport*, or type it in the Terminal tab). Under `Variant: debug`, `Config: debug`: the line `SHA1: 12:34:…`. Or: `keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android` |
| **Release, signed yourself** (an APK or AAB from your own keystore) | your release key | `keytool -list -v -keystore <your keystore> -alias <your alias>`, the `SHA1:` line |
| **Installed from Google Play** (Play App Signing) | Google's app signing key — Play re-signs what you upload | Play Console → the app → **Test and release** → **App integrity** → **Play app signing** (*Settings*) → *App signing key certificate* → **SHA-1 certificate fingerprint**. If you also install builds signed with your upload key directly, that key needs a client too (*Upload key certificate*, same page) |

The SHA-1 is 20 pairs like `A1:B2:…:F6`; paste it with the colons.

### 2. One Android client per SHA-1, in the same project as the Web client

In Google Cloud Console, <https://console.cloud.google.com>:

1. **The project.** In the project picker at the top, choose the project the
   website's Web client is in. A client id starts with its project's number:
   the website's is `683455913655-….apps.googleusercontent.com`, so it is the
   project whose **Project number** is `683455913655` (the picker shows ids;
   the number is on the project's *Dashboard* / *Settings*). The Android
   clients **must** be in this project: Google issues the app a token for the
   Web client only when both are in the same one.
2. Menu ☰ → **Google Auth Platform** → **Clients** (older consoles: *APIs &
   Services* → *Credentials*). The Web client is listed there.
3. **+ Create client** (older: *+ Create credentials* → *OAuth client ID*).
4. **Application type: Android.**
5. **Name:** anything that says which key it is, for yourself — e.g.
   `JoLu app — debug (<computer>)` or `JoLu app — Play`.
6. **Package name:** `com.healthapp.android`
7. **SHA-1 certificate fingerprint:** the SHA-1 from step 1.
8. **Create.** Copy the **Client ID** it shows
   (`683455913655-….apps.googleusercontent.com`). There is no secret to copy.

Repeat for every SHA-1: each computer that builds debug builds, and the
release or Play app signing key. Google refuses a package name + SHA-1 pair
that another client (in any project, Firebase included) already has — then
use or delete that one. Google deletes OAuth clients that have been unused for
six months; a debug client can simply be made again.

### 3. Tell the server

On the server, in the web root, `config/auth.local.php` — the file that
already holds the Web client — gets the ids in its `google` block:

```php
'google' => [
    // … client_id, client_secret, redirect_uri as they are …
    'android_client_ids' => [
        '683455913655-aaaa….apps.googleusercontent.com',  // debug, <computer>
        '683455913655-bbbb….apps.googleusercontent.com',  // Play app signing
    ],
],
```

(SSH: `nano config/auth.local.php`; without SSH, Hestia's File Manager edits
it. `GOOGLE_SIGNIN_ANDROID_CLIENT_IDS=id1,id2` in the environment does the
same.) Then:

```bash
php tools/check-config.php
```

must say `[  ok  ] Google in the app  2 Android clients — the app offers Google
sign-in`. It says so when an id is the Web client's own, is not a client id, or
is in another project than the Web client. From then on the app's button
works — it asks the server (`status`) each time the sign-in panel opens; no new
app build is needed.

### 4. Who may sign in

Google Auth Platform → **Audience**: while *Publishing status* is **Testing**,
only the **Test users** listed there can sign in — on the website and in the
app alike. Add each tester's Google address there (**+ Add users**), or press
**Publish app** to let everyone in. The consent screen asks only for `openid`,
`email` and `profile`, which need no verification review.

### When it does not work

| What you see | Usually |
| --- | --- |
| The button is disabled, "Google is nog niet gekoppeld." | the server has no Android client ids (step 3), or no Web client. `php tools/check-config.php` says which |
| "Er staat geen Google-account op deze telefoon…" although there is one; or "Inloggen met Google is niet gelukt" straight after the chooser, with nothing in the server log | Google refused this build before any token was made: no Android client for **the SHA-1 this build is signed with** (a debug build from another computer, a Play build without the app signing key's client), a typo in the package name or SHA-1, or the Android client in another project than the Web client. Compare `./gradlew signingReport` with the client. Google says a new or changed client can take from five minutes to a few hours to take effect |
| "Inloggen met Google is niet gelukt" after choosing an account, and the server log has `[google-signin] app ID token refused: …` | the server refused the token: e.g. `azp` (the Android client) not in `android_client_ids`, or the server's clock is off. The reason is in the log line |
| Signing in fails for some Google accounts only, or Google says the app is not available to them | *Testing*, and those accounts are not test users (step 4) |
| "Inloggen met Google werkt niet op deze telefoon: Google Play-services ontbreken of zijn verouderd." | an emulator image without Google Play, or Play services too old: update them in the Play Store |
| "Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen." | working as intended: that address has a password account. Sign in with the password, then Instellingen → Inloggen → Koppel Google (on the website) |

## What the app does

Implemented in the Android app (HealthappAndroid/README.md, "Signing in"):

1. Sign in through one of the endpoints above; the token is kept where the
   sync token was (Android Keystore, excluded from backups), with its scope.
   With Google, the ID token goes to `app-google.php` once and is dropped:
   the phone keeps no Google token, only the account token the server issues.
2. When the phone was paired before, the old token goes along as the bearer
   header of the sign-in, so the same row is upgraded. A sync still running
   with the old token gets `401`: the app forgets a token on `401` only if it
   is still the stored one, so that late answer cannot delete the account
   token just saved (the run starts again with the new one).
3. The account token is sent on every request, sync included.
4. On `401` anywhere for the stored token: forget it, stop syncing, ask to
   sign in again. On `403` from an account endpoint: the token is a sync
   token — ask to sign in.
5. Reopening the app restores the stored account token — whichever way it was
   signed in, Google is not asked again.
6. Signing out: forget the token and stop syncing, then `app-logout.php`,
   whatever it answers; Credential Manager is told too (`clearCredentialState`),
   so Google does not pick an account by itself next time.

## Testing

```bash
DB_NAME=<dev database with 013> DB_USER=root \
PRE13_DB=<a copy without 013> php tools/app-auth-test.php
```

Starts the app on PHP's built-in server with `tools/app-auth-router.php` and a
second server standing in for Google's key endpoint, with keys the test makes.
It registers, signs in, pairs, syncs, changes goals and signs out through the
real endpoints — scopes, the pilot both ways, revocation, lapsing, the limit
(including from a second loopback address), registration, Google with valid
and every kind of invalid token, the e-mail conflict, the website's login and
stay-signed-in, and, with `PRE13_DB`, everything that must keep working before
the migration. It removes its accounts afterwards. Never point it at a live
database.

For Google it also stands in for Google's token endpoint, so the website's
own redirect flow (`oauth.php` → `google-callback.php` → `google-username.php`)
runs too, and proves **one Google account is one JoLu account**: an account
made with Google on the website signs in to the app as that account (no
username step, no second user or identity), and one made in the app signs in
on the website as itself. `status` and the nonce's `client_id` (never the
secret) are checked, configured and not.

The app's side is tested in the Android project (`GoogleSignInTest`,
`GoogleSignInFlowTest`; HealthappAndroid/README.md, "Tests").

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
requests. The app keeps cookies for this conversation only: the nonce and a new
person's verified identity wait in the server session, exactly as on the
website, and the session is thrown away once the token is issued.

1. `{ "action": "nonce" }` → `{ ok, nonce, expires_in: 600 }`.
   The app passes it to `GetGoogleIdOption.Builder().setNonce(nonce)`, with
   `setServerClientId(<the Web client id>)`.
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
     (sign in with the password; link Google in Settings on the website)
   - no verified address, or an inactive account → `403`
   - anything wrong with the token → `401`, one message; the reason goes to
     the server log, never the token
   - no nonce in this conversation, or it expired or was used → `410`
3. `{ "action": "username", "username": "…", label, platform, app_version }`
   → `{ ok, status: "signed_in", token, … }`, the account made by
   `google_signin_create_account()`, already linked. `422` with the website's
   message for a bad or taken username; `410` when the ten minutes are up or
   the conversation was not started by step 2.
   `{ "action": "cancel" }` forgets the waiting identity.

Nothing the app says about the person is believed — only what comes out of an
ID token verified here.

`501` until the Android clients are configured (below).

## The pilot: `api/goals/update.php`

The first endpoint that takes both kinds of caller, through
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
Every other endpoint still takes the website's session only; each moves over
when the app needs it, by swapping its `api_require_csrf()` +
`api_require_user()` for `api_require_account_user()`.

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

In the Google Cloud project that has the **Web** client the website uses:

1. APIs & Services → Credentials → Create credentials → OAuth client ID →
   **Android**. Package name: the app's `applicationId`. SHA-1: the signing
   certificate's (`./gradlew signingReport`, or Play Console → App integrity
   for Play-signed builds). One client per signing key — debug and release
   each need one.
2. Put the client ids in `config/auth.local.php` on the server (never in the
   repository), or in the environment as a comma-separated list:

   ```php
   'google' => [
       // … client_id, client_secret, redirect_uri as they are …
       'android_client_ids' => ['…-android.apps.googleusercontent.com'],
   ],
   ```

   `GOOGLE_SIGNIN_ANDROID_CLIENT_IDS=id1,id2` does the same.
3. In the app: `GetGoogleIdOption` with `setServerClientId(<Web client id>)`
   and the nonce from step 1. The ID token then has `aud` = the Web client and
   `azp` = the Android client, which is exactly what the server checks.

These ids are not secret, but they belong to the deployment, not the code.

## What the app has to do (when it is built)

1. Sign in through one of the endpoints above; keep the token where the
   current sync token is kept (Android Keystore, excluded from backups).
2. When the phone was paired before, send the old token along as the bearer
   header of the sign-in, so the same row is upgraded; **stop the background
   sync first** — a sync still running with the old token gets `401`, and the
   current 401 handling would forget the token that was just saved.
3. Send the account token on every request, sync included.
4. On `401` anywhere: forget the token, stop syncing, show the opening screen.
   On `403` from an account endpoint: the token is a sync token — ask to sign in.
5. Signing out: `app-logout.php`, then forget the token whatever it answered.

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

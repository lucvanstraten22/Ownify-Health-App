# From JoLu / Healthapp to Ownify

The app was called JoLu (the website), "Healthapp Android" (the Android app,
package `com.healthapp.android`), and lived in a repository called
`Health-app`, a production database called `luc_healthapp` (a local checkout
defaulted to `jolu`) and a WampServer folder called `C:\wamp64\www\JoLu`. It
is now **Ownify** everywhere. This file is the record of that move and the checklist for the
parts that happen outside the repository — on Hestia, in WampServer, in
Google Cloud, on GitHub and on the phone. It is the one place in the
repository that still spells out the old names, on purpose.

The domain is **not** part of this move: `healthpreview.acits.nl` stays, and
so does everything tied to it (the OAuth redirect URI, the Android app's
server address, the Hestia web root). See *Deferred* at the end.

Nothing below deletes anything production depends on. The old production
database, the old Android app, the old Google OAuth client and the old
repository name (GitHub keeps redirecting it) stay until the new ones have
been checked; removing them is a separate, later step (G). The local
WampServer copy holds no data, so nothing is migrated there: it moves to a new
folder, and its old folder and empty `jolu` database are removed (B).

## What changed in the repository

| Old | New | Compatibility |
| --- | --- | --- |
| App name `NaamKomtNog!!` / JoLu | `Ownify` | — |
| `HealthappAndroid/` | `OwnifyAndroid/` | — |
| Android `applicationId` / `namespace` `com.healthapp.android` | `com.ownify.android` | a new app next to the old one; see *The phone* |
| Android launcher name "Healthapp Android" | "Ownify" | — |
| Kotlin `com.healthapp.android.jolu.*`, `Jolu*` classes | `com.ownify.android.connection.*`, `Ownify*` | internal only |
| Session cookie `jolu_session` | `ownify_session` | not needed: the stay-signed-in cookie restores the session |
| Stay-signed-in cookie `jolu_login` / `__Host-jolu_login` | `ownify_login` / `__Host-ownify_login` | **the old cookie is still accepted** once, then replaced (`includes/persistent-login.php`), so nobody is signed out |
| Environment variable `JOLU_APP_KEY` | `OWNIFY_APP_KEY` | **the old name is still read** after the new one (`includes/crypto.php`); `tools/check-config.php` warns |
| Production database `luc_healthapp`; a local checkout's default `jolu` | `luc_ownify`; default `ownify` | production: copied with `tools/copy-database.php` (A). Local: nothing to copy — the local database was empty (B) |
| Log prefix `[jolu]` | `[ownify]` | — |
| Workflow "Deploy Health Preview", group `health-preview-production` | "Deploy Ownify", group `ownify-production` | — |
| WAMP folder `C:\wamp64\www\JoLu` | `C:\wamp64\www\Ownify` | the old folder is no longer updated, and is deleted by hand (B) |

The API is unchanged: no route had the old brand in it (`/api/health/…` is
the Gezondheid feature, not the brand). The Android app talks to the same
endpoints, with the same tokens.

## The order

Do these in this order. Each step says whether it touches production.

1. **Before the push** — back up `luc_healthapp` (A1).
2. **Push** the migration to `main`. Hestia and your PC receive it (C).
3. **WampServer** — open the Android project from the new folder, remove the
   old folder and the empty `jolu` (B).
4. **Hestia** — copy `luc_healthapp` to `luc_ownify`, switch, check (A2–A6).
5. **Google Cloud** — the consent screen's name, the new Android client (D).
6. **The phone** — install Ownify next to the old app, sign in, check (E).
7. **GitHub** — rename the repository, point clones at the new URL (F).
8. **Later, once everything has run for a while** — clean up (G).

---

## A. Hestia (production)

`luc` below is your Hestia user — the prefix every database name there has.
The web root is `/home/luc/web/healthpreview.acits.nl/public_html`.

### A1. Back up `luc_healthapp` — before the push

1. Hestia panel → **DB** → on `luc_healthapp`, the **phpMyAdmin** icon (or
   open phpMyAdmin and sign in as `luc_healthapp`).
2. Left: click `luc_healthapp`. Top: **Export**. *Quick*, format *SQL* →
   **Export**. Keep the downloaded `luc_healthapp.sql` somewhere safe (not in
   the repository — it holds everybody's data).

No downtime. Nothing changes.

### A2. Create the new database `luc_ownify`

1. Hestia panel → **DB** → **Add Database**.
2. **Database:** `ownify` (Hestia shows the `luc_` in front; the full name is
   `luc_ownify`). **Username:** `ownify` (→ `luc_ownify`). **Password:** press
   the generate button and **copy it** into your password manager — you need
   it in A3 and A4. **Type:** mysql. **Host:** localhost. **Charset:**
   `utf8mb4`.
3. **Save.**

No downtime. The site keeps using `luc_healthapp`.

### A3. Rehearse the copy — no downtime

Over SSH (`ssh luc@<the server>`):

```bash
cd /home/luc/web/healthpreview.acits.nl/public_html
php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify
```

It asks for `luc_ownify`'s password (from A2; nothing is shown while you
type). It reads `luc_healthapp` with the credentials the site already uses
and never writes to it. It must end with:

```
The copy is the same: … tables, … rows. The source luc_healthapp was not changed.
```

Any `DIFF` line means: do not switch, and send me the output (it contains no
passwords).

*Without SSH:* in phpMyAdmin signed in as `luc_ownify`, select `luc_ownify`
→ **Import** → choose the `luc_healthapp.sql` from A1 → **Import**. That is
the same data as of A1. The check below can then only be done over SSH; the
next best thing is to run `CHECKSUM TABLE users, user_profiles, goals,
friendships, point_events, health_metrics;` in both databases (phpMyAdmin →
**SQL**) and compare the numbers.

### A4. Switch — about five minutes of downtime

The copy in A3 is already out of date as soon as anyone uses the site. For
the real switch, stop writes first, copy again, then point the site at the
copy:

1. **Stop the site.** Hestia panel → **Web** → `healthpreview.acits.nl` →
   **Suspend** (the ⏸ icon). The website and the app's API stop answering
   normally, so nothing can write to the database; the Android app's sync
   fails and tries again later. Nobody is signed out.
2. **Copy again**, replacing the rehearsal:

   ```bash
   php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify --replace=luc_ownify
   ```

   Again it must end with *The copy is the same*.
3. **Point the site at it.** Edit `config/database.local.php` (SSH: `nano
   config/database.local.php`; or Hestia's **File Manager**). Change exactly
   three values and nothing else:

   | Key | Old | New |
   | --- | --- | --- |
   | `database` | `luc_healthapp` | `luc_ownify` |
   | `username` | `luc_healthapp` | `luc_ownify` |
   | `password` | the old password | the password from A2 |

   Then:

   ```bash
   php -l config/database.local.php     # must say: No syntax errors detected
   php tools/check-config.php           # database connection ok, database luc_ownify
   ```
4. **Start the site.** Hestia panel → **Web** → `healthpreview.acits.nl` →
   **Unsuspend**.

**Rolling back** is putting the three old values back in
`config/database.local.php`: `luc_healthapp` is exactly as it was when the
site was stopped (anything written after the switch is only in `luc_ownify`).

### A5. Check production

- <https://healthpreview.acits.nl/> in a browser where you were signed in:
  you are **still signed in** (no login screen), and the title says *Ownify*.
- Gezondheid, Doelen, Overzicht, Community (Vrienden and Nederland), your
  points and friends: the same as before.
- Sign out, sign in with your password; sign out, **Doorgaan met Google**.
- In the old Android app (still installed): open it, pull a sync. It must
  still work — it talks to the same server with the same token.
- `php tools/check-config.php` over SSH: no `FAIL`. If it says `warn
  JOLU_APP_KEY … the old name`, see A6.

### A6. Only if check-config says so: rename `JOLU_APP_KEY`

The recommended setup keeps the key in `config/app.local.php` as `'app_key'`,
which has no name to change — then check-config says nothing about it and
there is nothing to do. Only if you set the key as an environment variable
(a PHP-FPM pool line `env[JOLU_APP_KEY] = "…"`): change the **name** to
`OWNIFY_APP_KEY`, keep the **value** exactly as it is (a new value makes every
stored Google Health token unreadable), and reload PHP-FPM. Until then the
old name keeps working.

---

## B. WampServer (your PC)

What the WAMP side does today, as the workflow runs show it (Update WAMP runs
98–102, the last one on the last JoLu commit):

- The self-hosted runner **WAMP-PC** — a Windows service on this PC, installed
  in `C:\actions-runner\actions-runner` — runs **Update WAMP** on every push
  to `main`. It mirrors the repository into the WAMP folder, leaving out
  `.git` and `uploads\`. It is not part of the production deploy: **Deploy
  Ownify** runs on GitHub's own machines and neither workflow waits for the
  other.
- The mirror is what WampServer serves on <http://localhost/…>, and it is
  where Android Studio opens the Android project: the runs keep finding
  Android Studio's `.idea` and Gradle's `build` in `HealthappAndroid\`.
- It holds no data. There is no `config\database.local.php` in it, so the
  local site uses the default database — the empty `jolu`. Nothing on this PC
  is migrated or backed up.

So the runner and the Update WAMP workflow stay; only the folder name changes.

### B1. After the push: the new folder

The Update WAMP run after the push mirrors into `C:\wamp64\www\Ownify`
(created by that run) and puts `uploads\.htaccess` in it, so nothing uploaded
there can ever be run. Check on GitHub → **Actions** → *Update WAMP* that the
run is green, and in Explorer that `C:\wamp64\www\Ownify` has
`OwnifyAndroid` and `uploads\.htaccess`.

`C:\wamp64\www\JoLu` is not touched: it simply stops being updated, frozen at
the last JoLu commit — including the old Android project, `HealthappAndroid`.

### B2. Android Studio: the new folder

**File → Close Project** (the old `C:\wamp64\www\JoLu\HealthappAndroid`),
then **File → Open** → `C:\wamp64\www\Ownify\OwnifyAndroid`, and let Gradle
sync. From now on build only from here: the old folder would build the old
app.

### B3. The local site — only if you use it

The address is now <http://localhost/Ownify/>. The new code looks for a
database called `ownify`; until there is one, the local site shows the
signed-out state and says the database is unreachable, which is harmless. To
have accounts locally: phpMyAdmin → **Databases** → create `ownify`
(collation `utf8mb4_unicode_ci`) → select it → **Import** →
`C:\wamp64\www\Ownify\database\schema.sql`. That is a new, empty
database, as `jolu` was — nothing is copied.

If you ever made a VirtualHost for the old folder (WampServer tray icon →
*Your VirtualHosts* → *VirtualHost Management*), point it at
`C:/wamp64/www/Ownify`. Without one there is nothing to change.

### B4. Remove the old WAMP leftovers

Once B1 is green and B2 is done:

- delete `C:\wamp64\www\JoLu` in Explorer (if Windows says a file is in
  use, close Android Studio's old project first);
- phpMyAdmin → `jolu` → **Operations** → *Drop the database*. It is empty.

After the push nothing refers to either: the workflow writes to
`C:\wamp64\www\Ownify`, and the runner's own folder
(`C:\actions-runner\…`) is separate and stays as it is.

---

## C. The push

Pushing to `main` runs both workflows. **Deploy Ownify** uploads to Hestia as
before (same secrets, same web root, the domain unchanged); **Update WAMP**
mirrors into the new WAMP folder (B1). On Hestia the deploy uploads
`OwnifyAndroid/.htaccess`; the old `HealthappAndroid/` folder on the server
is not removed by a deploy (it only holds a deny-all `.htaccess` and nothing
serves from it) — see G.

Nothing in the push changes what production connects to: the database name
on Hestia comes from the server's own `config/database.local.php`, which the
deploy never touches. Users stay signed in: their old stay-signed-in cookie
is accepted and replaced.

---

## D. Google Cloud

<https://console.cloud.google.com>, the project whose **Project number** is
`683455913655` (the start of the Web client's id).

### D1. The name people see — no downtime, no rebuild

Menu ☰ → **Google Auth Platform** → **Branding** (older consoles: *APIs &
Services* → *OAuth consent screen* → *Edit app*). **App name:** `Ownify`.
Save. This is the name on Google's "Continue to …" screen, on the website and
in the app. With only the basic scopes (openid, email, profile) there is no
review. The project's own display name (☰ → *IAM & Admin* → *Settings* →
*Project name*) can be changed too; its **project ID** cannot, and does not
need to.

### D2. The Web client — leave it exactly as it is

☰ → Google Auth Platform → **Clients**: the *Web application* client stays
as it is, with its redirect URI
`https://healthpreview.acits.nl/api/auth/google-callback.php`. Its id and
secret stay on the server in `config/auth.local.php`. Do not change or
delete it: the website's Google sign-in and the Android app's ID tokens both
depend on it.

### D3. A new Android client for `com.ownify.android` — needed before Google sign-in works in the new app

An Android OAuth client belongs to one package name + one signing key. The
existing one is for `com.healthapp.android`; Google will not give the new
package a token through it. So:

1. **The SHA-1 of your debug key** (the key hasn't changed, so it is the same
   SHA-1 the old client has): in Android Studio, with
   `C:\wamp64\www\Ownify\OwnifyAndroid` open (B2), **Terminal** tab:

   ```bat
   gradlew signingReport
   ```

   Under `Variant: debug` → `SHA1: AB:CD:…`. Copy it.
2. ☰ → Google Auth Platform → **Clients** → **+ Create client**.
3. **Application type:** Android. **Name:** `Ownify app — debug (<this
   PC>)`. **Package name:** `com.ownify.android`. **SHA-1:** the value from
   step 1. **Create.**
4. Copy the **Client ID** (`683455913655-….apps.googleusercontent.com`).
   There is no secret.
5. On the server, `config/auth.local.php`, in the `google` block, **add** it
   to `android_client_ids`, keeping the old one while the old app is still
   installed:

   ```php
   'android_client_ids' => [
       '683455913655-<old>.apps.googleusercontent.com',   // com.healthapp.android — remove in G
       '683455913655-<new>.apps.googleusercontent.com',   // com.ownify.android, debug, <this PC>
   ],
   ```

   Then `php -l config/auth.local.php` (must say *No syntax errors*) and
   `php tools/check-config.php` (must say `ok  Google in the app  2 Android
   clients`). A broken file no longer takes the site down, but it does turn
   Google sign-in off until it is fixed.

No downtime, no app rebuild: the app asks the server each time.

The **old Android client** stays until G. A Play Store release later needs
one more client for the Play app signing key's SHA-1 — `docs/APP-AUTH.md`,
*Setting it up in Google Cloud*.

---

## E. The phone

`com.ownify.android` is a different app to Android than
`com.healthapp.android`: it installs **next to** the old one, with its own
storage. Nothing carries over on the phone — the server has everything.

1. In Android Studio, with `C:\wamp64\www\Ownify\OwnifyAndroid` open (B2),
   **Run ▶** (or `gradlew installDebug`). The launcher now has **Ownify**
   next to **Healthapp Android**.
2. Open **Ownify**. Sign in with your password — and, after D3, with
   **Doorgaan met Google**. For a new Google account, the username step comes
   as before. The same account is the same account: no new user is made.
3. Health Connect asks again, because to Android this is a new app: allow the
   same data, and *Access data in the background*.
4. Sync. The website shows the phone under your devices as a new one. The old
   app may keep syncing in the meantime: each Health Connect record is stored
   once whichever app sends it, so nothing is doubled.
5. When Ownify has synced and shows your data: in the **old** app, sign out
   (that ends its token on the server); then long-press **Healthapp Android**
   → **Uninstall**. If you uninstall without signing out, remove the old
   phone on the website under your devices.

---

## F. GitHub

### F1. Rename the repository — after the push has deployed and been checked

1. <https://github.com/lucvanstraten22/Health-app> → **Settings** →
   **General** → **Repository name**: `Ownify-Health-app` → **Rename**.
   (GitHub turns spaces into hyphens: *Ownify Health app* is
   `Ownify-Health-app`.)
2. On the repository's front page, ⚙ next to **About**: description e.g.
   *Ownify — health app (website + Android)*.

GitHub redirects the old address for web, `git clone`, `git fetch` and `git
push`, so nothing breaks at the moment of renaming. The Actions workflows,
their secrets (`SFTP_*`) and the self-hosted runner move with the repository.

### F2. Point every clone at the new address

In every git clone of the repository you work in, in a terminal in its
folder. (The WAMP folder is not a clone — the mirror leaves `.git` out — so it
needs nothing.)

```bash
git remote set-url origin https://github.com/lucvanstraten22/Ownify-Health-app.git
git remote -v        # both lines show the new address
git fetch            # works
```

### F3. The self-hosted runner

Settings → **Actions** → **Runners**: **WAMP-PC** should still be **Idle**.
Its checkout folder, `C:\actions-runner\actions-runner\_work\Health-app\`,
becomes `…\_work\Ownify-Health-app\` by itself on the next run, and
`actions/checkout` marks it as a git *safe.directory* itself on every run (the
current runs show it doing so), so there is no git setting to change. Only if
the runner shows **Offline** after the rename and does not come back: in
`C:\actions-runner\actions-runner`, run `config.cmd remove`, then register it
again with the command GitHub shows under *New self-hosted runner* (it
contains the new URL). Its Windows service (in *Services*, the name starting
with `actions.runner.lucvanstraten22-Health-app`) keeps the old repository
name; that is only a label, and re-registering is what renames it.

---

## G. Later: cleaning up — only after all of the above has worked for a while

None of this is urgent, and each is a deletion, so each is your call:

- Hestia: drop `luc_healthapp` (panel → DB), keeping `luc_healthapp.sql`
  from A1; delete the `HealthappAndroid` folder in the web root (File
  Manager).
- Google Cloud: delete the Android client for `com.healthapp.android`, and
  remove its id from `android_client_ids`.
- Code: once nobody can still have the old stay-signed-in cookie (it lasts a
  year from its last use), remove `persistent_login_legacy_cookie_name()`;
  once no server sets `JOLU_APP_KEY`, remove `CRYPTO_KEY_VARIABLE_LEGACY`.

## Deferred: the domain

`healthpreview.acits.nl` is unchanged on purpose. When it moves, these all
move with it:

- `OwnifyAndroid/app/src/main/java/com/ownify/android/connection/OwnifyApi.kt`
  — `BASE_URL`, the server the app talks to (needs an app rebuild).
- Google Cloud — the Web client's authorised redirect URI, and the
  `redirect_uri` in the server's `config/auth.local.php` /
  `config/auth.local.php.example`.
- The Hestia web domain, its SSL certificate, and the `SFTP_REMOTE_PATH`
  secret the deploy uploads to.
- The Google Auth Platform branding page, if it lists the domain as the
  app's home page or authorised domain (`acits.nl`).
- The `SFTP_SERVER` / `SFTP_REMOTE_PATH` repository secrets, if they name the
  host or the web root.
- Files in the repository that name it: `docs/DATABASE.md`,
  `config/auth.local.php.example`, the job name in
  `.github/workflows/deploy.yml`, and this file.

Nothing else depends on it: the cookies are host-only (no `Domain`
attribute), there is no CORS configuration and no webhook, and every other
URL in the website is relative.

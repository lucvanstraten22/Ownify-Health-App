# From JoLu / Healthapp to Ownify

The app was called JoLu (the website), "Healthapp Android" (the Android app,
package `com.healthapp.android`), and lived in a repository called
`Health-app`, a production database called `luc_healthapp` (a local checkout
defaulted to `jolu`), a WampServer folder called `C:\wamp64\www\JoLu` and on
the domain `healthpreview.acits.nl`. It is now **Ownify** everywhere, and its
address becomes **`https://ownify.acits.nl`**. This file is the record of
that move and the checklist for the parts that happen outside the repository
— on Hestia, at STRATO, in Google Cloud, on GitHub and on the phone. It is
the one place in the repository that still spells out the old names, on
purpose.

Nothing below deletes anything production depends on. The old domain, the old
production database, the old Android app and the old Google OAuth client stay
until the new ones have been checked; removing them is a separate, later step
(G), and each removal is your call.

**Already done:** the rename is on `main` (503ab89) and deployed; the
production backup of `luc_healthapp` is made; WampServer moved to
`C:\wamp64\www\Ownify` and its old folder and empty `jolu` are gone (H); the
repository is renamed to `Ownify-Health-App` (I); the automatic commit task on
the PC is disabled; the stray `HealthappAndroid` folder on Hestia is gone.

## What changed in the repository

| Old | New | Compatibility |
| --- | --- | --- |
| App name `NaamKomtNog!!` / JoLu | `Ownify` | — |
| `HealthappAndroid/` | `OwnifyAndroid/` | — |
| Android `applicationId` / `namespace` `com.healthapp.android` | `com.ownify.android` | a new app next to the old one; see D |
| Android launcher name "Healthapp Android" | "Ownify" | — |
| Android server `BASE_URL` `https://healthpreview.acits.nl/` | `https://ownify.acits.nl/` | the new app only; the old app keeps the old domain, which stays up until G |
| Kotlin `com.healthapp.android.jolu.*`, `Jolu*` classes | `com.ownify.android.connection.*`, `Ownify*` | internal only |
| Session cookie `jolu_session` | `ownify_session` | not needed: the stay-signed-in cookie restores the session |
| Stay-signed-in cookie `jolu_login` / `__Host-jolu_login` | `ownify_login` / `__Host-ownify_login` | **the old cookie is still accepted** once, then replaced (`includes/persistent-login.php`) |
| Environment variable `JOLU_APP_KEY` | `OWNIFY_APP_KEY` | **the old name is still read** after the new one (`includes/crypto.php`); `tools/check-config.php` warns |
| Production database `luc_healthapp`; a local checkout's default `jolu` | `luc_ownify`; default `ownify` | production: copied with `tools/copy-database.php` (B, E). Local: nothing to copy |
| Google redirect URI in `config/auth.local.php.example` | `https://ownify.acits.nl/api/auth/google-callback.php` | the real one is in each server web root's own `config/auth.local.php` (C2) |
| Log prefix `[jolu]` | `[ownify]` | — |
| Workflow "Deploy Health Preview", group `health-preview-production` | "Deploy Ownify", group `ownify-production` | where it uploads to is the `SFTP_REMOTE_PATH` secret (A5) |
| WAMP folder `C:\wamp64\www\JoLu` | `C:\wamp64\www\Ownify` | done (H) |

The API is unchanged: no route had the old brand in it (`/api/health/…` is
the Gezondheid feature, not the brand).

## Where the domain lives

Everything that ties the app to `healthpreview.acits.nl`, checked against the
code, the workflows and the live server:

| Where | Today | For `ownify.acits.nl` |
| --- | --- | --- |
| **DNS** — at **STRATO** (the name servers of `acits.nl` are `ns3`/`ns4.stratoserver.net`), not in Hestia | a wildcard `*.acits.nl` → `acits.nl` → `82.165.247.184`, the server `healthpreview.acits.nl` runs on | **already resolves** to that server — nothing to do (A1) |
| **Hestia web domain** — nginx + Apache + PHP-FPM, one per domain | `healthpreview.acits.nl`, web root `/home/luc/web/healthpreview.acits.nl/public_html` | a new web domain `ownify.acits.nl`, web root `/home/luc/web/ownify.acits.nl/public_html` (A2) |
| **HTTPS certificate** — per Hestia web domain | covers `healthpreview.acits.nl` only; `https://ownify.acits.nl` gets a certificate for another name | Let's Encrypt for `ownify.acits.nl` in Hestia (A3) |
| **Server-only files** in the web root — never in the repository, never deployed | `config/database.local.php`, `config/app.local.php`, `config/auth.local.php`, possibly `config/integrations.local.php`, and `uploads/avatars/*` | copied into the new web root (A4) |
| **Deploy** — `.github/workflows/deploy.yml`, SFTP with the secrets `SFTP_SERVER`, `SFTP_USERNAME`, `SFTP_PRIVATE_KEY`, `SFTP_REMOTE_PATH` | `SFTP_REMOTE_PATH` is the old web root | only `SFTP_REMOTE_PATH` changes (A5); same server, same user, same key |
| **Google sign-in, website** — the Web client's *Authorised redirect URIs* in Google Cloud, and `redirect_uri` in the web root's `config/auth.local.php` | `https://healthpreview.acits.nl/api/auth/google-callback.php` | add `https://ownify.acits.nl/api/auth/google-callback.php` to the same client, keep the old one until G (C2) |
| **Google sign-in, app** — Android clients are bound to a package and a key, not a domain | the old client, `com.healthapp.android` | the new client, `com.ownify.android` (C3); its id in the new web root's `android_client_ids` |
| **Android app** — `BASE_URL` in `OwnifyApi.kt`, used for every API call and every "open the website" link | old app: `https://healthpreview.acits.nl/` | new app: `https://ownify.acits.nl/` (D) |
| **Signed-in browsers** — cookies are host-only (no `Domain` attribute) | signed in on `healthpreview.acits.nl` | everybody signs in once on `ownify.acits.nl`; accounts and data are the same |

Nothing else names it: every URL the website makes is relative, there is no
CORS configuration, no e-mail, no webhook and no host check. The app's API
refuses plain http (`api_require_https`), so the new app can only connect
once A3 is done.

Two things about the old web root decide how the new one is filled:

- **The deploy only uploads; it never deletes.** Files removed from the
  repository stay on the server: `diagnose.php` (a temporary diagnostic
  page), `pc-sync-test.txt` and others still answer on the old domain. So the
  new web root is filled by a fresh deploy, not by copying the old one — only
  the server-only files above are copied.
- **Both domains run on the same database** until the old one is retired.
  Whatever `config/database.local.php` says has to say it in both web roots,
  or the old app's syncs would land in a database the website no longer
  reads (E3).

## The order

**Preparation — no downtime, the old domain keeps working throughout:**

1. The new domain on Hestia: DNS (A1), web domain (A2), HTTPS (A3),
   server-only files (A4), deploy target (A5), check (A6).
2. The database: create `luc_ownify` (B2) and rehearse the copy (B3).
3. Google: name (C1), redirect URI (C2), Android client (C3).
4. The phone: build and test the new app against `ownify.acits.nl` (D).

Everything written in preparation goes to `luc_healthapp`, from either
domain: they share it.

**Cutover — about ten minutes of downtime for both domains (E).**

**Afterwards:** check (F); later, one by one, retire the old things (G).

---

## A. The new domain

`luc` below is your Hestia user — the prefix every database name there has.
Check the paths once in Hestia's **File Manager**, which shows them.

### A1. DNS — nothing to change

`acits.nl` is managed at STRATO, and a wildcard record there already sends
`ownify.acits.nl` to the same server as `healthpreview.acits.nl`
(`82.165.247.184`). Check it from any PC: `nslookup ownify.acits.nl` must
show that address.

- Do **not** create a DNS zone for it in Hestia: the internet asks STRATO's
  name servers, never Hestia's, so a Hestia zone would not be used.
- Optional, for robustness: in STRATO's DNS settings for `acits.nl`, an
  explicit record for `ownify` (the same kind as `healthpreview` has), so
  the site does not depend on the wildcard staying there.

### A2. Add `ownify.acits.nl` in Hestia

1. Hestia → **WEB** → `healthpreview.acits.nl` → **Edit**. Write down its
   **IP address** and, under **Advanced options**, its **Web template**,
   **Backend template** (the PHP version) and **Proxy template**. Change
   nothing there — **Cancel**.
2. **WEB** → **Add Web Domain**:
   - **Domain:** `ownify.acits.nl`;
   - **IP address:** the one from step 1;
   - if these are offered, **untick** *Create DNS zone* (A1) and *Enable mail
     for this domain*;
   - **Advanced options:** remove the alias `www.ownify.acits.nl` that
     Hestia fills in, and choose the same three templates as in step 1 — the
     same PHP version, and the same way nginx hands https over to Apache,
     which is how the app knows a request is https;
   - **Save**. (If the form did not offer the templates, open the new domain's
     **Edit** → **Advanced options** now and set them there.)
3. **File Manager** → `web` → `ownify.acits.nl` → `public_html`: delete the
   `index.html` Hestia put there (the "Success!" page), and a `robots.txt`
   if there is one. The old web root has neither; left in place, the
   placeholder can be served instead of the app.

No downtime; nothing about `healthpreview.acits.nl` changes.

### A3. HTTPS with Let's Encrypt

1. **WEB** → `ownify.acits.nl` → **Edit** → tick **Enable SSL for this
   domain** → tick **Use Let's Encrypt to obtain SSL certificate** →
   **Save**. Let's Encrypt checks the name over plain http — which works:
   A1 already resolves and `http://ownify.acits.nl/` answers.
2. When it has succeeded (Edit shows the certificate, issued by Let's
   Encrypt, for `ownify.acits.nl`): tick **Enable automatic HTTPS
   redirection** → **Save**. Leave **HSTS** off until G — a browser that has
   seen it refuses plain http for a long time, which is hard to take back.

Check: <https://ownify.acits.nl/> opens without a certificate warning. If
Hestia reports an error, send me its text.

### A4. The server-only files

The deploy never carries these, so the new web root needs its own copies. Over
SSH:

```bash
OLD=/home/luc/web/healthpreview.acits.nl/public_html
NEW=/home/luc/web/ownify.acits.nl/public_html

ls -la "$OLD"/config/*.local.php                 # what there is to copy
mkdir -p "$NEW/config" "$NEW/uploads/avatars"
cp -p "$OLD"/config/*.local.php "$NEW/config/"   # -p keeps the permissions (600 for the key)
cp -p "$OLD"/uploads/avatars/* "$NEW/uploads/avatars/"
ls "$OLD/uploads/avatars" | wc -l; ls "$NEW/uploads/avatars" | wc -l   # the same number
```

Without SSH: File Manager → select the files in the old web root →
**Copy** → the same folder under `ownify.acits.nl`.

- `config/app.local.php` must be this exact file, not a new key: a different
  key makes every stored token unreadable.
- Leave `config/database.local.php` as it is: until the cutover, both domains
  use `luc_healthapp`.
- `config/auth.local.php` gets its new `redirect_uri` in C2.
- Only if `config/integrations.local.php` exists and its `redirect_uri` names
  `healthpreview.acits.nl`: change that name to `ownify.acits.nl` in the
  **new** copy. (No code uses that callback yet; it is only kept consistent.)

### A5. Deploy to the new web root

1. GitHub → the repository → **Settings** → **Secrets and variables** →
   **Actions** → `SFTP_REMOTE_PATH` → **Update**. The new value is the new web
   root, written in the same form as the current value, with
   `ownify.acits.nl` instead of `healthpreview.acits.nl`. GitHub cannot show
   the current value; if you do not remember its form, use the path File
   Manager shows, `/home/luc/web/ownify.acits.nl/public_html` — and if the
   deploy then fails with *No such file or directory*, your SFTP login is
   locked into your home folder: use `/web/ownify.acits.nl/public_html`.
2. **Actions** → **Deploy Ownify** → the latest run → **Re-run all jobs**.

Check: <https://ownify.acits.nl/VERSION> shows the commit of that run.

`SFTP_SERVER`, `SFTP_USERNAME` and `SFTP_PRIVATE_KEY` stay as they are: the
same server and the same Hestia user. From now on every push deploys to
`ownify.acits.nl` only; `healthpreview.acits.nl` keeps the code it has, which
is exactly what a fallback should do.

### A6. Check the new site — still on `luc_healthapp`

```bash
cd "$NEW"
php tools/check-config.php
diff -rq "$OLD" "$NEW" | grep "^Only in $OLD"
```

- check-config: no `FAIL`; the database settings name `luc_healthapp`;
  `OWNIFY_APP_KEY` set and usable, from `config/app.local.php`. (If it says
  the key is missing while the old web root works, the key is set in the old
  domain's PHP-FPM settings rather than in a file — tell me before going on.)
- The `diff` lists what the old web root has and the new one does not. Expect
  only files the deploy left behind over time (`diagnose.php`,
  `pc-sync-test.txt`, `components/secondary-scores.php`,
  `tools/goal-verify.php`, …), which are deliberately not copied. Send me the
  list; anything under `config/` or `uploads/` needs copying after all.
- In a browser, <https://ownify.acits.nl/>: the Ownify opening screen. Sign in
  with your password — you are signed out on the new name at first, that is
  expected — and check Gezondheid, Doelen, Overzicht, Community and
  Instellingen: the same data as on the old domain, because it *is* the same
  database.

Optional, now: delete `diagnose.php` and `pc-sync-test.txt` from the **old**
web root. They are leftovers nothing uses, and `diagnose.php` is publicly
reachable.

---

## B. The database

### B1. Back up `luc_healthapp` — done

Hestia → **DB** → `luc_healthapp` → **phpMyAdmin** → **Export** → *Quick*,
*SQL*. Keep the file somewhere safe, not in the repository.

### B2. Create `luc_ownify` — no downtime

1. Hestia → **DB** → **Add Database**.
2. **Database:** `ownify` and **Username:** `ownify` (Hestia makes both
   `luc_ownify`). **Password:** press the generate button and store it in your
   password manager — it is needed in B3 and E. **Type:** mysql. **Host:**
   localhost. **Charset:** `utf8mb4`.
3. **Save.**

### B3. Rehearse the copy — no downtime, production unchanged

```bash
cd /home/luc/web/ownify.acits.nl/public_html      # or the old web root: both use luc_healthapp until E
php tools/check-config.php                          # database settings: luc_healthapp, reachable
php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify
```

It asks for `luc_ownify`'s password (nothing is shown while you type), reads
`luc_healthapp` with the site's own credentials and never writes to it. It
must end with:

```
The copy is the same: … tables, … rows. The source luc_healthapp was not changed.
```

Any `DIFF` line: do not go on; send me the output (it contains no
passwords). The rehearsal is out of date as soon as anybody uses the site —
E copies again.

---

## C. Google Cloud

<https://console.cloud.google.com>, the project whose **Project number** is
`683455913655` (the start of the Web client's id).

### C1. The name and the links people see

☰ → **Google Auth Platform** → **Branding** (older consoles: *APIs & Services*
→ *OAuth consent screen*). **App name:** `Ownify`. Under *App domain*, change
any link that names `healthpreview.acits.nl` to the same page on
`ownify.acits.nl`. **Authorised domains**: `acits.nl` covers both names —
leave it. Save. With only the basic scopes (openid, email, profile) there is
no review.

### C2. The Web client's redirect URI — the same client, one more address

1. ☰ → Google Auth Platform → **Clients** → the *Web application* client →
   **Authorised redirect URIs** → **+ Add URI**:

   ```
   https://ownify.acits.nl/api/auth/google-callback.php
   ```

   **Keep** `https://healthpreview.acits.nl/api/auth/google-callback.php`
   until G: the old domain still uses it. The client ID and secret do not
   change. **Save** (Google can take a few minutes).
2. In the **new** web root's `config/auth.local.php`, in the `google` block:

   ```php
   'redirect_uri'  => 'https://ownify.acits.nl/api/auth/google-callback.php',
   ```

   The old web root's copy keeps the old address. Then, in the new web root:

   ```bash
   php -l config/auth.local.php        # No syntax errors detected
   php tools/check-config.php          # Google redirect URI  https://ownify.acits.nl/api/auth/google-callback.php
   ```

Check: **Doorgaan met Google** on <https://ownify.acits.nl/> signs you in to
your own account (and on the old domain it still works as before).

### C3. A new Android client for `com.ownify.android`

An Android OAuth client belongs to one package name and one signing key, not
to a domain; the existing one is for `com.healthapp.android`. So:

1. **The SHA-1 of your debug key** (the same key as before): Android Studio
   with `C:\wamp64\www\Ownify\OwnifyAndroid` open → **Terminal**:
   `gradlew signingReport` → under `Variant: debug`, the `SHA1:` line.
2. ☰ → Google Auth Platform → **Clients** → **+ Create client** →
   **Application type:** Android; **Name:** `Ownify app — debug (<this PC>)`;
   **Package name:** `com.ownify.android`; **SHA-1:** from step 1 →
   **Create**. Copy its **Client ID**; there is no secret.
3. In the **new** web root's `config/auth.local.php`, **add** it to
   `android_client_ids`, keeping the old id:

   ```php
   'android_client_ids' => [
       '683455913655-<old>.apps.googleusercontent.com',   // com.healthapp.android — remove in G
       '683455913655-<new>.apps.googleusercontent.com',   // com.ownify.android, debug, <this PC>
   ],
   ```

   `php -l config/auth.local.php` and `php tools/check-config.php` (`Google in
   the app  2 Android clients`). The old web root needs only the old id, for
   the old app; adding the new one there does no harm.

The **old Android client** stays until G.

---

## D. The phone — the new app against the new domain

`com.ownify.android` is a different app to Android than
`com.healthapp.android`: it installs next to the old one, with its own
storage, and talks to `https://ownify.acits.nl/`. Nothing carries over on the
phone — the server has everything. Do this after A3–A6 and C3.

1. Android Studio with `C:\wamp64\www\Ownify\OwnifyAndroid` open (after the
   push with the new `BASE_URL` has reached that folder) → **Run ▶**. The
   launcher now has **Ownify** next to **Healthapp Android**.
2. In **Ownify**: sign in with your password, and with **Doorgaan met
   Google**. The same account is the same account: no new user is made.
3. Health Connect asks again, because to Android this is a new app: allow the
   same data, and *Access data in the background*.
4. Sync; <https://ownify.acits.nl/> shows the new data, and the phone as a new
   device. The old app keeps syncing through the old domain into the same
   database; each Health Connect record is stored once, whichever app sends
   it.

Keep the old app until F is done.

---

## E. Cutover — about ten minutes of downtime, both domains

Only when all of these hold:

- [ ] `nslookup ownify.acits.nl` gives the server's address (A1);
- [ ] <https://ownify.acits.nl/> opens without a certificate warning (A3);
- [ ] the site works there, including Google sign-in (A6, C2);
- [ ] `https://ownify.acits.nl/VERSION` is the latest commit (A5);
- [ ] the new app works against it (D);
- [ ] the rehearsal ended with *The copy is the same* (B3);
- [ ] the `luc_ownify` password is at hand (B2).

Then:

1. **Stop both sites.** Hestia → **WEB** → **Suspend** `ownify.acits.nl`
   *and* `healthpreview.acits.nl`. Nothing can write to the database now;
   the apps' syncs fail and try again later; nobody is signed out.
2. **Copy again**, from the new web root:

   ```bash
   cd /home/luc/web/ownify.acits.nl/public_html
   php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify --replace=luc_ownify
   ```

   It must end with *The copy is the same*. If not: stop here, unsuspend both
   sites, and nothing has changed.
3. **Point both web roots at it.** In `config/database.local.php` of the new
   **and** the old web root, change exactly three values:

   | Key | Old | New |
   | --- | --- | --- |
   | `database` | `luc_healthapp` | `luc_ownify` |
   | `username` | `luc_healthapp` | `luc_ownify` |
   | `password` | the old password | the password from B2 |

   Both, because the old domain still serves the old app until G: with only
   one switched, the old app's syncs would go on landing in `luc_healthapp`,
   where the website no longer looks.
4. **Check both.** In each web root:

   ```bash
   php -l config/database.local.php     # No syntax errors detected
   php tools/check-config.php           # database settings …/luc_ownify, connection reachable
   ```
5. **Start both sites.** **Unsuspend** `ownify.acits.nl` and
   `healthpreview.acits.nl`.

**Rolling back**, if F goes wrong: suspend both, put the three old values
back in both files, unsuspend both. `luc_healthapp` is exactly as it was at
step 1; anything written after the switch is only in `luc_ownify`.

---

## F. After the cutover — check everything

On <https://ownify.acits.nl/>:

- sign in with your password; close the browser, open it again: still signed
  in;
- **Doorgaan met Google** signs in to the same account;
- Gezondheid (scores, the three pillars), Doelen, Overzicht, Community
  (Vrienden and Nederland, points), Instellingen: the same as before;
- change something small (a rating, a goal) and see it stay after a reload.

On the phone:

- **Ownify** syncs, and its data appears on the website;
- **Healthapp Android**, still installed, still syncs through
  `healthpreview.acits.nl` — into `luc_ownify` now.

Over SSH, in the new web root: `php tools/check-config.php` — no `FAIL`. If
it warns about `JOLU_APP_KEY … the old name`: that key is set as an
environment variable; change its **name** to `OWNIFY_APP_KEY` there, keeping
the **value** exactly as it is, and reload PHP-FPM.

---

## G. Later — retiring the old things, one at a time

None of this is urgent, and each is your call. In this order:

1. **The old app.** When Ownify has worked on the phone for a while: in
   **Healthapp Android**, sign out (that ends its token on the server), then
   uninstall it. If it is uninstalled without signing out, remove the old
   phone on the website under your devices.
2. **The old domain** — only after 1, because the old app cannot follow a
   redirect: its sign-in and sync requests are POSTs, and a redirect turns
   those into something else. Then either:
   - **redirect** it: Hestia → **WEB** → `healthpreview.acits.nl` → **Edit**
     → enable domain redirection to `https://ownify.acits.nl` → **Save**
     (old bookmarks keep working); or
   - **remove** it: delete the web domain in Hestia, which also removes its
     web root with the leftover files and its certificate.
3. **Google Cloud:** remove
   `https://healthpreview.acits.nl/api/auth/google-callback.php` from the Web
   client; delete the Android client for `com.healthapp.android` and remove
   its id from `android_client_ids`.
4. **The old database:** drop `luc_healthapp` (Hestia → **DB**), keeping the
   export from B1.
5. **HSTS** on `ownify.acits.nl` (Hestia → Edit → *Enable HSTS*), once you are
   sure the site stays on https.
6. **Code:** once nobody can still have the old stay-signed-in cookie (it
   lasts a year from its last use), remove
   `persistent_login_legacy_cookie_name()`; once no server sets
   `JOLU_APP_KEY`, remove `CRYPTO_KEY_VARIABLE_LEGACY`.

---

## H. WampServer (your PC) — done

The self-hosted runner **WAMP-PC** (a Windows service in
`C:\actions-runner\actions-runner`) runs **Update WAMP** on every push to
`main` and mirrors the repository, without `.git` and `uploads\`, into
`C:\wamp64\www\Ownify`. That is the local site (<http://localhost/Ownify/>)
and the folder Android Studio builds the app from
(`C:\wamp64\www\Ownify\OwnifyAndroid`). It is not part of the production
deploy, holds no data, and is not affected by the domain: the local site is
on localhost. The old `C:\wamp64\www\JoLu` and the empty `jolu` database are
removed.

For accounts on the local site: phpMyAdmin → create `ownify`
(`utf8mb4_unicode_ci`) → **Import** `database\schema.sql` — a new, empty
database.

## I. GitHub — renamed

The repository is now `lucvanstraten22/Ownify-Health-App`. GitHub redirects the
old address for web, `git fetch` and `git push`. In every git clone you work
in (the WAMP folder is not one):

```bash
git remote set-url origin https://github.com/lucvanstraten22/Ownify-Health-App.git
git remote -v
git fetch
```

The runner: Settings → **Actions** → **Runners** → **WAMP-PC** should be
**Idle**. Its checkout folder takes the new name by itself on the next run.
Only if it stays **Offline**: in `C:\actions-runner\actions-runner`, run
`config.cmd remove`, then register it again with the command GitHub shows
under *New self-hosted runner*.

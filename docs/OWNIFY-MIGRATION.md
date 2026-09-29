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

`ownify.acits.nl` **replaces** `healthpreview.acits.nl`: the Hestia account
allows one web domain, so the old one is retired in the same window the new
one is created — no alias, no parked domain, no period with both. Everything
the old web domain holds that is not in the repository is preserved first
(A), and the old production database and the old Google OAuth client stay
until the new ones have been checked; removing those is a separate, later
step (G).

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
| Android server `BASE_URL` `https://healthpreview.acits.nl/` | `https://ownify.acits.nl/` | the new app only; the old app stops working at the cutover (E) |
| Kotlin `com.healthapp.android.jolu.*`, `Jolu*` classes | `com.ownify.android.connection.*`, `Ownify*` | internal only |
| Session cookie `jolu_session` | `ownify_session` | not needed: the stay-signed-in cookie restores the session |
| Stay-signed-in cookie `jolu_login` / `__Host-jolu_login` | `ownify_login` / `__Host-ownify_login` | **the old cookie is still accepted** once, then replaced (`includes/persistent-login.php`) |
| Environment variable `JOLU_APP_KEY` | `OWNIFY_APP_KEY` | **the old name is still read** after the new one (`includes/crypto.php`); `tools/check-config.php` warns |
| Production database `luc_healthapp`; a local checkout's default `jolu` | `luc_ownify`; default `ownify` | production: copied with `tools/copy-database.php` (B, D). Local: nothing to copy |
| Google redirect URI in `config/auth.local.php.example` | `https://ownify.acits.nl/api/auth/google-callback.php` | the real one is in the server's own `config/auth.local.php` (A3, D) |
| Log prefix `[jolu]` | `[ownify]` | — |
| Workflow "Deploy Health Preview", group `health-preview-production` | "Deploy Ownify", group `ownify-production` | where it uploads to is the `SFTP_REMOTE_PATH` secret (E) |
| WAMP folder `C:\wamp64\www\JoLu` | `C:\wamp64\www\Ownify` | done (H) |

The API is unchanged: no route had the old brand in it (`/api/health/…` is
the Gezondheid feature, not the brand).

## Where the domain lives

Everything that ties the app to `healthpreview.acits.nl`, checked against the
code, the workflows and the live server:

| Where | Today | After the cutover |
| --- | --- | --- |
| **DNS** — at **STRATO** (the name servers of `acits.nl` are `ns3`/`ns4.stratoserver.net`), not in Hestia | a wildcard `*.acits.nl` → `acits.nl` → `82.165.247.184`, the server the site runs on | nothing changes: `ownify.acits.nl` already resolves to that server |
| **Hestia web domain** — the account allows one | `healthpreview.acits.nl`, web root `/home/luc/web/healthpreview.acits.nl/public_html` | deleted, and `ownify.acits.nl` created, web root `/home/luc/web/ownify.acits.nl/public_html` |
| **HTTPS certificate** — belongs to the web domain | for `healthpreview.acits.nl` | deleted with it; a new Let's Encrypt certificate for `ownify.acits.nl` |
| **Server-only files** in the web root — never in the repository, never deployed | `config/database.local.php`, `config/app.local.php`, `config/auth.local.php`, possibly `config/integrations.local.php`, and `uploads/avatars/*` | preserved outside the web root beforehand (A2), put back into the new one (D) |
| **Deploy** — SFTP with the secrets `SFTP_SERVER`, `SFTP_USERNAME`, `SFTP_PRIVATE_KEY`, `SFTP_REMOTE_PATH` | `SFTP_REMOTE_PATH` is the old web root | only `SFTP_REMOTE_PATH` changes, during the cutover (D8) |
| **Google sign-in, website** — the Web client's *Authorised redirect URIs*, and `redirect_uri` in the server's `config/auth.local.php` | `https://healthpreview.acits.nl/api/auth/google-callback.php` | `https://ownify.acits.nl/api/auth/google-callback.php` — added to the same client beforehand (C2), used from the cutover, the old one removed afterwards (G) |
| **Google sign-in, app** — Android clients are bound to a package and a key, not a domain | the old client, `com.healthapp.android` | the new client, `com.ownify.android` (C3) |
| **Android app** — `BASE_URL` in `OwnifyApi.kt` | old app: `https://healthpreview.acits.nl/` | new app: `https://ownify.acits.nl/`; the old app can no longer connect |
| **Signed-in browsers** — cookies are host-only | signed in on `healthpreview.acits.nl` | everybody signs in once on `ownify.acits.nl`; accounts and data are the same |

Nothing else names it: every URL the website makes is relative, and there is
no CORS configuration, no e-mail, no webhook and no host check.

**Hestia cannot rename a web domain from the panel**: the domain name is
fixed on its Edit page, and a user account has no other way in. So the old
web domain is deleted and the new one created. Deleting a web domain in
Hestia removes its whole folder, `/home/luc/web/healthpreview.acits.nl` —
the web root with the server-only files and the avatars, its logs and its
certificate — but **not** its database, which is separate (the **DB** tab).
Hence A2 before anything is deleted.

The new web root is filled by a fresh deploy plus the preserved files, not by
a copy of the old web root: the deploy only uploads and never deletes, so the
old web root still carries files removed from the repository long ago
(`diagnose.php`, `pc-sync-test.txt`, …). They go with it.

## The order

**Preparation — no downtime, `healthpreview.acits.nl` keeps working:**

1. Check DNS (A1), preserve everything (A2), prepare the new config files
   (A3).
2. The database: create `luc_ownify` and rehearse the copy (B).
3. Google: name (C1), the new redirect URI (C2), the Android client (C3).

**Cutover — one downtime window (D):** stop the old site → final database
copy → preserve the latest uploads → delete the old web domain → create
`ownify.acits.nl` with HTTPS → put the files back → point the deploy at it
and deploy → check. The downtime **starts** when the old site is suspended
(D1) and **ends** when <https://ownify.acits.nl/> serves the app on
`luc_ownify` (D9). Plan for 30–45 minutes; most of it is waiting for Let's
Encrypt and the deploy. `healthpreview.acits.nl` does not come back.

**Straight after:** the new app on the phone (E), the checks (F).
**Later:** retire the rest (G).

Only one web domain means the new domain cannot be tried out before the old
one is gone, and the old app and the new app never run side by side. If the
package's limit can be raised to two for a few days (by whoever administers
the server: *Packages* → the package → *Web domains*), the new domain can be
set up and tested while the old one still runs, and the downtime shrinks to
the database switch. Tell me if that is possible and I will adjust the plan.

---

## A. Preparation

`luc` below is your Hestia user — the prefix every database name there has.
Check the paths once in Hestia's **File Manager**, which shows them.

### A1. DNS — nothing to change

`acits.nl` is managed at STRATO, and a wildcard record there already sends
`ownify.acits.nl` to the server (`82.165.247.184`). Check from any PC:
`nslookup ownify.acits.nl` shows that address. Do **not** create a DNS zone in
Hestia: the internet asks STRATO's name servers, never Hestia's.

After the cutover the same wildcard still sends `healthpreview.acits.nl` to
the server, where it then gets Hestia's default page instead of the app —
the old address has stopped working. (Removing that would mean removing the
wildcard at STRATO, which also affects every other `*.acits.nl` name; not
needed.)

### A2. Preserve everything the deploy cannot bring back

1. **A full Hestia backup** — the safety net for everything below: Hestia →
   **BACKUPS** → **Create backup**; when it has finished, **Download** it and
   keep it with the database export from B1. It contains the web domain, its
   files and its databases, and can be restored from the same page.
2. **Write down the web domain's settings:** **WEB** →
   `healthpreview.acits.nl` → **Edit**: its **IP address**, and under
   **Advanced options** the **Web template**, **Backend template** (the PHP
   version) and **Proxy template**, and any other option you set there.
   Change nothing — **Cancel**.
3. **Copy the server-only files out of the web root** — into your home
   folder, which deleting the web domain does not touch. Over SSH:

   ```bash
   OLD=/home/luc/web/healthpreview.acits.nl/public_html
   KEEP=/home/luc/ownify-preserve
   mkdir -p "$KEEP" && chmod 700 "$KEEP"
   ls -la "$OLD"/config/*.local.php                 # what there is
   cp -p "$OLD"/config/*.local.php "$KEEP"/
   cp -rp "$OLD"/uploads "$KEEP"/uploads
   ls -la "$KEEP"; ls "$OLD/uploads/avatars" | wc -l; ls "$KEEP/uploads/avatars" | wc -l
   php "$OLD"/tools/check-config.php > "$KEEP"/check-config-before.txt
   ```

   Without SSH: File Manager → make the folder `ownify-preserve` in your home
   folder → select the files in the old web root → **Copy** there. Also
   download a copy of `ownify-preserve` to your PC and keep it private: it
   holds the database password, the app key and the Google secret.
4. **Keep the whole old web root as well**, as one archive, so nothing that
   step 3 might miss is lost, and list what is there:

   ```bash
   tar -czf "$KEEP/public_html-before.tar.gz" -C /home/luc/web/healthpreview.acits.nl public_html
   ls -la "$OLD" "$OLD/config" "$OLD/uploads"
   find "$OLD" -name '*.local.php' -o -name '.user.ini' -o -name '*.env'
   ```

   Send me the listing. Expect the repository's folders, `VERSION`,
   `config/*.local.php`, `uploads/`, and files the deploy left behind over
   time; anything else I will tell you whether it needs a place in the new
   web root.
5. **Where the app key comes from:** `check-config-before.txt` must say
   `OWNIFY_APP_KEY set and usable, from config/app.local.php`. If it says the
   key is missing while the site works, the key is set in the domain's
   PHP-FPM settings, which are deleted with it — stop and tell me.

### A3. Prepare the config files for the new domain — nothing is switched yet

Still in `$KEEP`, make the new versions next to the originals:

```bash
cd "$KEEP"
cp -p auth.local.php      auth.local.php.ownify
cp -p database.local.php  database.local.php.ownify
```

- `auth.local.php.ownify`: set
  `'redirect_uri' => 'https://ownify.acits.nl/api/auth/google-callback.php',`
  and, after C3, add the new Android client id to `android_client_ids`.
- `database.local.php.ownify`: change `database` and `username` to
  `luc_ownify` and `password` to the password from B2.
- `integrations.local.php`, only if it exists and its `redirect_uri` names
  `healthpreview.acits.nl`: make an `.ownify` copy with `ownify.acits.nl`
  there (no code uses that callback yet; it is only kept consistent).
- `app.local.php` is used exactly as it is: a different key makes every
  stored token unreadable.

Then `php -l` each `.ownify` file — each must say *No syntax errors
detected*.

---

## B. The database

### B1. Back up `luc_healthapp` — done

Hestia → **DB** → `luc_healthapp` → **phpMyAdmin** → **Export** → *Quick*,
*SQL*. Keep the file somewhere safe, not in the repository.

### B2. Create `luc_ownify` — no downtime

1. Hestia → **DB** → **Add Database**.
2. **Database:** `ownify` and **Username:** `ownify` (Hestia makes both
   `luc_ownify`). **Password:** press the generate button and store it in your
   password manager — it is needed in A3, B3 and D2. **Type:** mysql.
   **Host:** localhost. **Charset:** `utf8mb4`.
3. **Save.** (If Hestia refuses because the package allows only one
   database, stop and tell me.)

### B3. Rehearse the copy — no downtime, production unchanged

```bash
cd /home/luc/web/healthpreview.acits.nl/public_html
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
D2 copies again.

---

## C. Google Cloud — before the cutover, no downtime

<https://console.cloud.google.com>, the project whose **Project number** is
`683455913655` (the start of the Web client's id).

### C1. The name and the links people see

☰ → **Google Auth Platform** → **Branding** (older consoles: *APIs & Services*
→ *OAuth consent screen*). **App name:** `Ownify`. Under *App domain*, change
any link that names `healthpreview.acits.nl` to the same page on
`ownify.acits.nl`. **Authorised domains**: `acits.nl` covers both — leave
it. Save.

### C2. The Web client's redirect URI — add the new one now

☰ → Google Auth Platform → **Clients** → the *Web application* client →
**Authorised redirect URIs** → **+ Add URI**:

```
https://ownify.acits.nl/api/auth/google-callback.php
```

**Save.** Keep the old URI for now: the live site uses it until the cutover,
and a client may list both. The client ID and secret do not change. The
server starts using the new URI at the cutover (D7, with
`auth.local.php.ownify`); the old one is removed in G.

### C3. A new Android client for `com.ownify.android`

1. **The SHA-1 of your debug key**: Android Studio with
   `C:\wamp64\www\Ownify\OwnifyAndroid` open → **Terminal**:
   `gradlew signingReport` → under `Variant: debug`, the `SHA1:` line.
2. ☰ → Google Auth Platform → **Clients** → **+ Create client** →
   **Application type:** Android; **Name:** `Ownify app — debug (<this PC>)`;
   **Package name:** `com.ownify.android`; **SHA-1:** from step 1 →
   **Create**. Copy its **Client ID**; there is no secret.
3. Add it to `android_client_ids` in `$KEEP/auth.local.php.ownify` (A3),
   keeping the old id until G, then `php -l` it.

---

## D. Cutover — one downtime window

Only when all of these hold:

- [ ] A2 is complete: Hestia backup downloaded, `ownify-preserve` filled and
      checked, the lists from A2.4 reviewed;
- [ ] the `.ownify` config files are ready and pass `php -l` (A3);
- [ ] the rehearsal ended with *The copy is the same* (B3);
- [ ] the new redirect URI is on the Web client (C2) and the new Android
      client exists (C3);
- [ ] nobody pushes to `main` during the window (a deploy would go to a web
      root that is being replaced);
- [ ] the `luc_ownify` password is at hand.

**Downtime starts here.**

1. **Stop the old site.** Hestia → **WEB** → `healthpreview.acits.nl` →
   **Suspend**. Nothing can write to the database now.
2. **Final database copy**, from the old web root (the tool is still there):

   ```bash
   cd /home/luc/web/healthpreview.acits.nl/public_html
   php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify --replace=luc_ownify
   ```

   It must end with *The copy is the same*. If not: **Unsuspend**, and
   nothing has changed.
3. **The latest uploads** (avatars added since A2):

   ```bash
   cp -rp /home/luc/web/healthpreview.acits.nl/public_html/uploads/. /home/luc/ownify-preserve/uploads/
   ```
4. **Delete the old web domain.** Hestia → **WEB** → `healthpreview.acits.nl`
   → **Delete** (unsuspend first if Hestia asks). This removes its web root,
   its logs and its certificate. The databases stay.
5. **Create the new one.** **WEB** → **Add Web Domain**:
   - **Domain:** `ownify.acits.nl`; **IP address:** as written down in A2.2;
   - untick *Create DNS zone* and *Enable mail for this domain* if offered;
   - **Advanced options:** remove the alias `www.ownify.acits.nl`; the same
     **Web**, **Backend** and **Proxy** templates as written down in A2.2
     (if the form does not offer them, set them under the new domain's
     **Edit** right after saving);
   - **Save**. Then **File Manager** → `web/ownify.acits.nl/public_html` →
     delete the placeholder `index.html` (and `robots.txt` if there is one).
6. **HTTPS.** **Edit** `ownify.acits.nl` → tick **Enable SSL for this
   domain** → tick **Use Let's Encrypt to obtain SSL certificate** →
   **Save**. When the certificate is there: tick **Enable automatic HTTPS
   redirection** → **Save**. Leave HSTS off until G.
7. **Put the files back:**

   ```bash
   KEEP=/home/luc/ownify-preserve
   NEW=/home/luc/web/ownify.acits.nl/public_html
   mkdir -p "$NEW/config" "$NEW/uploads"
   cp -p "$KEEP/app.local.php"              "$NEW/config/app.local.php"
   cp -p "$KEEP/auth.local.php.ownify"      "$NEW/config/auth.local.php"
   cp -p "$KEEP/database.local.php.ownify"  "$NEW/config/database.local.php"
   # only if they exist in $KEEP:
   cp -p "$KEEP/integrations.local.php.ownify" "$NEW/config/integrations.local.php"
   cp -rp "$KEEP/uploads/avatars" "$NEW/uploads/"
   ```
8. **Deploy to it.** GitHub → the repository → **Settings** → **Secrets and
   variables** → **Actions** → `SFTP_REMOTE_PATH` → **Update**: the new web
   root, in the same form as the old value with `ownify.acits.nl` instead of
   `healthpreview.acits.nl`. (GitHub cannot show the old value. If you do not
   remember its form, use `/home/luc/web/ownify.acits.nl/public_html`; if the
   deploy then fails with *No such file or directory*, your SFTP login is
   locked into your home folder: use `/web/ownify.acits.nl/public_html`.)
   Then **Actions** → **Deploy Ownify** → the latest run → **Re-run all
   jobs**, and wait for it to finish. `SFTP_SERVER`, `SFTP_USERNAME` and
   `SFTP_PRIVATE_KEY` stay as they are.
9. **Check.**

   ```bash
   cd /home/luc/web/ownify.acits.nl/public_html
   php -l config/database.local.php && php -l config/auth.local.php
   php tools/check-config.php
   ```

   check-config: no `FAIL`; database settings `…/luc_ownify`, reachable;
   `OWNIFY_APP_KEY` set and usable, from `config/app.local.php`; `Google
   redirect URI  https://ownify.acits.nl/api/auth/google-callback.php`;
   `Google in the app  2 Android clients`. And <https://ownify.acits.nl/VERSION>
   shows the commit of the deploy.

**Downtime ends** when <https://ownify.acits.nl/> opens without a certificate
warning and shows the Ownify opening screen.

**If it goes wrong before D4**, unsuspend `healthpreview.acits.nl`: nothing
has changed. **After D4** there is no quick way back to the old name: the way
forward is to fix what D5–D9 report (send me the error text). As a last
resort, restore the web domain from the Hestia backup of A2.1 (**BACKUPS** →
the backup → the web domain → **Restore**), which needs `ownify.acits.nl`
deleted first because of the one-domain limit; `luc_healthapp` is untouched
either way.

---

## E. The phone — right after the cutover

The old app (`com.healthapp.android`) talks to `healthpreview.acits.nl` and
cannot connect any more. The new app installs next to it, with its own
storage; the server has everything.

1. Android Studio with `C:\wamp64\www\Ownify\OwnifyAndroid` open → **Run ▶**.
   The launcher has **Ownify** next to **Healthapp Android**.
2. In **Ownify**: sign in with your password, and with **Doorgaan met
   Google**. The same account is the same account: no new user is made.
3. Allow Health Connect again (to Android this is a new app), including
   *Access data in the background*; sync; the data appears on the website.
4. Then remove the old app: long-press **Healthapp Android** → **Uninstall**,
   and on the website, under your devices, remove the old phone (the old app
   can no longer sign itself out).

---

## F. After the cutover — check everything

On <https://ownify.acits.nl/>:

- sign in with your password; close the browser, open it again: still signed
  in;
- **Doorgaan met Google** signs in to the same account;
- Gezondheid, Doelen, Overzicht, Community (Vrienden and Nederland, points),
  Instellingen: the same data as before;
- change something small (a rating, a goal) and see it stay after a reload;
- `http://ownify.acits.nl/` goes to https.

And: <http://healthpreview.acits.nl/> no longer shows the app; the phone
syncs through the new app (E).

---

## G. Later — retiring the rest, one at a time

1. **Google Cloud:** remove
   `https://healthpreview.acits.nl/api/auth/google-callback.php` from the Web
   client; delete the Android client for `com.healthapp.android` and remove
   its id from `android_client_ids` in the server's `config/auth.local.php`.
2. **The old database:** drop `luc_healthapp` (Hestia → **DB**) once you are
   sure, keeping the export from B1.
3. **The preserved files:** delete `/home/luc/ownify-preserve` (it holds
   secrets) once the new site has run for a while; keep the Hestia backup
   download.
4. **HSTS** on `ownify.acits.nl` (Hestia → Edit → *Enable HSTS*), once you are
   sure the site stays on https.
5. **Code:** once nobody can still have the old stay-signed-in cookie (it
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

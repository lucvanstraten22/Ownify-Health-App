# Account security

How Ownify signs people in, proves who they are again before something that
cannot be undone, and ends other sessions — on the website and in the Android
app, one backend. The approved plan (Phase 2, version 9) has two releases:

| | What | Status |
|---|---|---|
| **Release A — 28.0** | Works without mail: e-mail registration closed, the address rule, one password check, password rules and the repeat field, changing the password, ending other sessions, reauthentication before deleting the account (native Google on Android), verification status in Settings, Google linking paused, rate limits, PHPMailer and the outbox present but inert. | **Built** |
| Release B — 28.1 | Verification screen and links, Wachtwoord vergeten, adding/changing an address and the revert link, setting a password on a Google account, Google linking (web and native), removing Google, notices and e-mail 7, the registration switch. | Waits for a real Gmail test and your approval |

Code: `includes/account-security.php` (everything below unless named
otherwise), `includes/auth.php`, `includes/google-signin.php`,
`includes/mailer.php`. Schema: `database/migrations/027-account-security.sql`.

---

## 1. Ways in

- **Google, e-mail + password, or both**, with the same or different
  addresses. Accounts are never merged; identities never move between
  accounts.
- **Registering with e-mail is closed** in Release A
  (`account_email_registration_open()` is false). A new e-mail account has to
  wait on a verification screen until its link is used, and that needs mail.
  The website's and the app's Registreren show one line, *"Registreren met
  e-mail is tijdelijk niet beschikbaar."*, and Google stays. The endpoints
  (`api/auth/register.php`, `app-register.php`) answer `403` with those words.
  `config/mail.php`'s `email_registration` is read by Release B only.
  - Test servers only: `OWNIFY_TEST_EMAIL_REGISTRATION=1` in the environment
    of a **local** test server reopens today's immediate registration, so the
    test suites can make throwaway accounts. Never on a server people use.
- **New Google registrations** enter at once, only when Google's
  `email_verified` is exactly `true` (not the string `"true"`). Google's
  address becomes the account's verified address (`account_emails`,
  `via_google`). A Google sign-up whose address is another account's sign-in
  address, another account's verified address, or contested, is refused.
- **Existing unverified accounts** keep full access.

## 2. One address rule

`account_email_canonical()` is the only definition of an address: surrounding
whitespace (ASCII and Unicode) removed, nothing inside that is not printable
ASCII, lower-case, `FILTER_VALIDATE_EMAIL`, at most 191 characters. Gmail dots
and `+tags` are not folded. Sign-in, Google, and every new table use it.

The database holds the same rule for the stored **characters** in every new
address column (migration 027):

```sql
CHAR_LENGTH(email) BETWEEN 1 AND 191 AND NOT (CAST(email AS BINARY) REGEXP '[^!-@[-~]')
```

The regex literal is exactly 9 bytes, nothing escaped — `[ ^ ! - @ [ - ~ ]`,
hex `5B 5E 21 2D 40 5B 2D 7E 5D` — so `NO_BACKSLASH_ESCAPES` cannot change it.
`account_email_storable()` is its PHP twin. **The migration file is the one
source:** `tools/canonical-check.php` reads every constraint and literal from
it and checks the server stored the same bytes, in both sql_modes, with 240
direct inserts. Run `php tools/check-config.php --check-schema-rules` on a
server before importing 027.

## 3. One password check

`auth_password_matches(?string $hash, string $password)` is the only place a
password is compared — sign-in, the current password for a change, deletion.
An account without a password is compared against a real dummy hash (made
once per process) and fails generically, in the same time. Every sign-in
attempt makes exactly `AUTH_SIGNIN_PASSWORD_CHECKS` (4) comparisons, whichever
branch it takes; Release B uses the last three for pending registrations.
The old dummy string was not a valid bcrypt hash (≈195 ms vs ≈48 ms), which
could reveal which names have a password.

**Password rules** (`account_password_problem()`), wherever a password is
set: 8–200 characters, not the username, not one of the account's addresses
(either in any case), typed the same twice.

## 4. Changing the password

Settings › Account › Inloggen › **Wachtwoord** › *Wijzigen* — the field
editor's panel on the website, the same panel on Android (`PasswordEditor`):
current password, new password, the new one again.
`api/account/password.php` (website: session + CSRF; app: account token).

- A wrong current password counts against the sign-in limit (`login`, keyed
  on the username): 5 per quarter hour per name and network address.
- In one transaction, after `SELECT … FOR UPDATE` on the account:
  the new hash, then `account_end_other_sessions()`.

## 5. Ending other sessions — a number, not a time

`users.session_generation` (migration 027).

| Credential | Password change |
|---|---|
| Browser session | The number moves on. Every other session holds an older number and is signed out on its next request (`account_session_enforce()`, every request from `includes/bootstrap.php`). The browser that made the change gets the new number and a new session id. |
| Stay-signed-in cookie (`user_login_tokens`) | All of the account's rows deleted, except this browser's |
| App sign-in (`user_devices`, scope `account`) | All revoked, except the token that made the change |
| Health sync (scope `sync`) | Untouched |

- New sessions — a sign-in, or the stay-signed-in cookie putting one back —
  start at the account's current number (`account_session_stamp()`). Nothing
  else hands an existing session a newer number.
- **Fail-closed:** a session without a number (signed in before 28.0) counts
  as 0. It carries on while the account is at 0 and is ended once a security
  action has moved the account on. Its cookie row is gone by then, so it
  cannot come back.
- **Without migration 027** there is no way to end other sessions, so the
  password change is switched off: *"Dit kan even niet. Probeer het later
  opnieuw."* (`503`), and Settings says so in the row.

## 6. Proof before deleting the account

`api/profile/delete.php` keeps its two-step confirmation and now also asks
the account to prove it is itself again (`account_require_proof()`):

- **Has a password:** the password, in the last step (`password`). Counted
  like a wrong sign-in.
- **No password (Google only):**
  - *Website:* "Bevestig met Google" → `api/auth/reauth.php` (`action=google`)
    → Google with `prompt=login&max_age=0` → back in `google-callback.php`,
    which requires the account's own Google id and an `auth_time` under
    5 minutes old → one deletion may follow within 5 minutes
    (`account_reauth_grant()`; the grant is used once).
  - *Android:* native — `api/auth/reauth.php` (`action=nonce`, account token)
    hands a nonce, Credential Manager asks Google with it, and the deletion is
    sent with the ID token (`id_token`) in the same conversation (its session
    cookie). The server checks it like a sign-in (signature, audience, the
    app's Android client, expiry, that nonce — used once) and that it is the
    account's own Google id.
- Without the proof: `403` with `reauth: password|google`; nothing deleted.

## 7. Google linking — paused until Release B

Linking needs the account's own address verified, which needs mail. So
Release A offers no linking: the Google row in Settings says *"Bevestig eerst
je e-mailadres. Dat kan binnenkort."* on both apps (Android no longer opens
the website for it), and `oauth.php mode=link` answers `409` with the same
words. Accounts that already have Google keep signing in with it.

## 8. Limits

| What | Limit |
|---|---|
| Sign-in, the current password, the deletion password | 5 failures / 15 min per name + network address (existing) |
| Google: website start, app nonce, reauth | 20 / 15 min per network address (`google`) |
| Website registration (when open) | 5 / 15 min per address + network address; 20 / hour per network address |

## 9. Mail — present, inert

PHPMailer **v7.1.1** is vendored unmodified in `includes/vendor/phpmailer/`
(D5): `PHPMailer.php`, `SMTP.php`, `Exception.php`, the LGPL-2.1 `LICENSE`,
and `VERSION` with the tag's commit and each file's SHA-256 (the release
archive could not be downloaded from the build machine; the files come from
the tagged commit). `check-config.php` recomputes the checksums.

`includes/mailer.php` is the outbox: flows only enqueue (one encrypted row
with a fixed Message-ID); `mail_outbox_run()` is the one way out, with a
5-minute lease, at most 5 attempts (waits of 1, 5, 15, 60 minutes), decoys
that send nothing, and only the SMTP code logged. In Release A nothing
enqueues, and nothing is sent while `config/mail.php` is not configured
(the App Password goes only in the git-ignored `config/mail.local.php` on the
server — see `config/mail.local.php.example`). `php tools/mail-outbox.php` is
the cron command for Release B. `php tools/check-config.php --send-test=<you>`
sends one message directly, for the Gmail test.

## 10. Migration 027

`database/migrations/027-account-security.sql` — additive, re-runnable, no
`USE`. It adds `users.status 'pending'`, `users.session_generation`,
`pending_registrations`, `account_emails`, `account_email_conflicts`,
`auth_email_tokens`, `email_changes`, `email_change_google_baseline` and
`mail_outbox`, every address column with the CHECK above. The backfill
detects first: Google addresses on several accounts (`shared_google`),
non-canonical ones (`not_canonical`) and unverified password addresses that
are another account's verified one (`unverified_shadow`) are listed, never
assigned; only the unambiguous are inserted, with a plain `INSERT`. No
identity or Google id is rewritten. The last query shows every piece (all 1).

Order: deploy the code (it works with or without 027) → back up → run
`php tools/check-config.php --check-schema-rules` → import 027 → check-config
says it is complete.

`027-rollback.sql` is for before Release B is used: it refuses (`SIGNAL`)
once anything 027 did not derive exists — a password-verified address, a
change, a pending registration, a token, an outbox job, a pending user, a
baseline, a session number above 0, a resolved conflict. The normal way back
is the code only, keeping the schema.

## 11. Tests

| Command | What |
|---|---|
| `DB_NAME=… php tools/account-security-test.php` (`NO027_DB=…` too) | The rules, registration closed, password change and exactly what it ends, fail-closed sessions, deletion proof on web and app, Google rules and limits, the code without 027 |
| `DB_USER=root php tools/canonical-check.php` | The address rule in PHP and in the database, read from the migration file, both sql_modes |
| `DB_USER=root php tools/migration-027-test.php` | Import, re-import, an older draft, the backfill's hard cases, the rollback's refusals |
| `DB_NAME=… php tools/mail-outbox-test.php` | The outbox's lease, attempts, waits, decoys and expiry, with a stand-in for SMTP |
| `OWNIFY_TEST_EMAIL_REGISTRATION=1 DB_NAME=… php tools/app-auth-test.php` | The existing sign-in suite, deletion now with the password |
| Android `OwnifyAppFlowTest` | Registration closed, the repeat field, Inloggen and the password editor, deletion with the password and with Google |

# Mail (web backend)

Ownify sends e-mail through Gmail's SMTP as **Ownify
&lt;noreply.ownify@gmail.com&gt;**, with PHPMailer v7.1.1 (vendored in
`includes/vendor/phpmailer/`, no Composer — D5 in docs/ACCOUNT-SECURITY.md).
There is one mail service, `includes/mailer.php`; nothing else talks SMTP.

## The settings

`config/mail.php` holds everything public and is in Git: `smtp.gmail.com`,
port **587 with STARTTLS**, SMTP authentication, `noreply.ownify@gmail.com`
as the login and the sender, "Ownify" as the sender's name, and
`https://ownify.acits.nl` as the base of every link. The **only secret is the
Gmail App Password**, and it is never in the repository. It is read from,
later winning:

| Where | Notes |
|---|---|
| `config/mail.local.php` | Beside `config/mail.php`. Git-ignored; the deploy never uploads or removes it. Under the web root, so it returns 404 when requested directly (the example's first lines) — but prefer the next one. |
| `<web root>/../private/mail.local.php` | **Recommended on the server.** Outside the web root, so it can never be served. On Hestia: `/home/<user>/web/<domain>/private/mail.local.php`, Hestia's own per-domain private folder beside `public_html` (create it if it is not there). |
| `OWNIFY_SMTP_PASSWORD` (environment) | Also `OWNIFY_SMTP_HOST`, `_PORT`, `_USERNAME`, `OWNIFY_MAIL_FROM`, `OWNIFY_PUBLIC_URL`. On Hestia a PHP-FPM `env[…]` line is lost when Hestia rebuilds the pool (see docs/DATABASE.md), so the file is the safer choice. |

Spaces in the App Password (Google shows it as four groups of four) are
removed when it is read.

### Provisioning — the deploy puts it there (34.2)

The way it is meant to be done, with no SSH or SFTP client by hand:

1. **GitHub → the repository → Settings → Secrets and variables → Actions →
   New repository secret.** Name `OWNIFY_MAIL_APP_PASSWORD`, value the App
   Password (with or without its spaces).
2. **Actions → Deploy Ownify → Run workflow** (branch `main`), with your own
   address in *Send a test e-mail to this address* and *private* as the
   location. Every push to `main` deploys too; only a run by hand sends a
   test message.

What the deploy then does (`.github/workflows/deploy.yml`,
`.github/scripts/mail-provision.sh` and `mail-test.sh`; `.github/` is never
uploaded):

- **Put the mail password on the server** — over the deploy's own SFTP
  login (`SFTP_*` secrets). It checks before writing anything: the password
  has the shape of an App Password (16 letters), `SFTP_REMOTE_PATH` ends in
  `public_html` and holds Ownify's `config/mail.php`, and the folder above it
  can be listed and holds `public_html`. Then `private/` is created with 700,
  the file is uploaded as `mail.local.php.new`, set to 600 and renamed into
  place, and both permissions are read back. Any of these failing stops the
  deploy with the reason; **it never falls back to `config/` on its own**.
  The password, the key and the paths are masked in the log. Skipped with a
  notice while the secret is not set.
- **Send a test e-mail from the website** (by hand, with an address): the
  SFTP login has no shell, so `tools/mail-test.php` cannot be run there.
  Instead a one-time PHP file with a random name is put in the web root. It
  answers only to a random token in a request header (anything else gets
  404), deletes itself before doing anything, can send only the test message
  to the one address written into it, and is removed over SFTP afterwards
  whatever happened. Called once over HTTPS, it reads the settings exactly as
  the website does — PHP-FPM, `open_basedir` and all — connects to Gmail,
  logs in and sends. The log says where the password was read from, whether
  the login worked and whether Gmail accepted the message.

**If the folder above `public_html` cannot be written** (the deploy says
so), there are two choices, and the deploy will not pick one for you:
ask the host to let the SFTP account create `private/` beside `public_html`
(recommended), or run the workflow by hand with location *config*. The file
then goes to `config/mail.local.php` (600, git-ignored, never uploaded or
removed by the deploy) and the deploy checks that
`https://ownify.acits.nl/config/mail.local.php` gives a 404 and nothing else.

**If the test says the website found no password** while the file is in
`private/`: PHP on the server is not allowed into that folder
(`open_basedir`); use location *config*, or have the host add `private/`.

Changing the App Password: update the secret and deploy again.

### Entering the App Password on the server by hand

Over SSH (or the same steps in Hestia's File Manager):

```bash
cd /home/<hestia-user>/web/ownify.acits.nl
mkdir -p private && chmod 700 private
cp public_html/config/mail.local.php.example private/mail.local.php
nano private/mail.local.php        # replace xxxx xxxx xxxx xxxx with the App Password
chmod 600 private/mail.local.php
cd public_html
php tools/mail-test.php you@example.com
```

One thing to know: the command line usually runs without PHP-FPM's
`open_basedir`, the website with it. Hestia's standard PHP-FPM templates
include the domain's `private/` folder there; a custom template that leaves
it out would let `tools/mail-test.php` pass while the website still finds no
password. In that case put the same file at `config/mail.local.php` instead.

## The service

| Function | What |
|---|---|
| `mail_send($to, $subject, $text, $html = null, $options = [])` | Sends one message now. `$to` is one address or a list, checked with the account address rule first; `$text` is the whole message or the plain part beside `$html`. UTF-8, quoted-printable, multipart/alternative when there is HTML, Ownify as the sender. Options: `kind` (for the log), `message_id`, `reply_to`. Returns `['ok' => bool, 'stage' => …, 'code' => …]`. |
| `mail_smtp_check()` | Connects, starts TLS and logs in, sends nothing. |
| `mail_outbox_enqueue()` / `mail_outbox_run()` | The outbox (docs/ACCOUNT-SECURITY.md §9): for account mail with a single-use link, which must survive a failed attempt. `mail_outbox_run()` sends each job through `mail_send()`. |
| `mail_transport_reason()` / `mail_unavailable_reason()` | Why sending, or the outbox, is not possible here — which setting, never its value. |

Safety: certificate verification stays on; PHPMailer's debug output never
reaches a screen or a log (its debug channel goes to a closure that keeps
only the server's reply code); a header in an address or subject is refused
before anything connects; a failure is logged as one line —
`[ownify] mail: a <kind> message was not sent (<step>, <code>)` — never an
address, a subject, a link or the password. Nothing about mail is in any API
response.

## Testing

| Command | What |
|---|---|
| `php tools/mail-test.php you@example.com` | **On the server**: the real settings, a real connection to Gmail, the login, one real message. Says which settings files it read (never their contents) and, on failure, the step and Gmail's code. Exit 0 only when Gmail accepted it. Command line only. |
| `php tools/mail-service-test.php` | Anywhere: the service end to end against a stand-in for Gmail on this machine (`tools/fake-smtp.py`: STARTTLS with a certificate PHP verifies, a real login, DATA). 34 checks. |
| `DB_NAME=… php tools/mail-outbox-test.php` | The outbox's lease, attempts and decoys. |
| `php tools/check-config.php` | Says whether mail is configured, and from how many sources. |

## The account mail it is for (Release B)

Registration verification, Wachtwoord vergeten, changing an address (and its
revert link) and the account notices all use this service the same way,
without mail code of their own. Migration 027 already has their tables
(`auth_email_tokens` with `verify_signup`, `verify_change`, `password_reset`,
`revert_email`; `pending_registrations`; `email_changes`):

1. the flow, in its own transaction, creates the token row and calls
   `mail_outbox_enqueue($kind, $address, ['subject' => …, 'text' => …,
   'html' => …], $validMinutes)` — the link built from `public_url`, never
   from the request;
2. after the response is finished, `mail_outbox_run()` sends it (and
   `php tools/mail-outbox.php` from cron every minute catches the rest);
3. a notice without a link that may simply be lost can call `mail_send()`
   directly.

What each message says (Dutch and English, through `t()`) belongs with each
flow when Release B is built; e-mail registration stays closed until then
(`account_email_registration_open()`).

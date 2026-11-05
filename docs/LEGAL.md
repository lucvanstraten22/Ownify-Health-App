# Privacyverklaring, Voorwaarden and Licenties

Instellingen › Over de app › Juridisch opens three documents (39.0), the same
on the website and in the app:

| Where | What |
| --- | --- |
| `config/legal.php` | the Dutch text of all three, and the Licenties' labels |
| `lang/en/legal.php` | its English (added by hand: `i18n-build --check` only sees `t()` calls; `tools/legal-test.php` checks every line) |
| `lib/legal.php` | `legal_documents()` → `$data['legal']`: each section as `{title, text[], points[], link, licence}`; the Licenties' package sections built from the files below |
| `config/licenses-android.php` | every artifact of the Android release build: group, name, resolved version, licence, URL. Generated — do not edit |
| `config/licenses/` | the licence texts shown in full (Apache 2.0, BSD 3-Clause, MIT; LGPL 2.1 is PHPMailer's own `LICENSE`) |
| `pages/legal-detail.php` | the website's page, over Over de app (`data-detail-parent="settings-about"`) |
| `OwnifyAndroid/…/settings/Legal.kt` | the app's `LegalDocumentPage` (`Detail.LegalPage(id)`) |

## Written from the code

The Privacyverklaring and the Voorwaarden describe only what the code does
(checked when they were written, 39.0): what is stored, Health Connect
(read only, hourly, 7 days a sync), Polar (read only, 28 days back the first
time), OwnifyAI (Google Gemini, only with consent, what is and is never
sent), the community (what others see), e-mail (Gmail SMTP, the outbox
emptied after sending), cookies, how long things are kept, and the
settings that export, wipe and delete. A change to any of those is a change
to `config/legal.php` too.

Not known from the code, and so not stated (to be filled in by the owner):

- a contact address and who runs Ownify (an owner or organisation)
- the hosting provider and where the server stands; whether there are backups
- a minimum age (only the nutrition targets assume 18+)
- whether Polar is switched on in production

Things the documents leave out because the code does not do them yet:
"Profiel vindbaar" and "Meedoen aan ranglijsten" are placeholders that
change nothing; `ai_usage` (questions per day) is never cleaned up; queued
e-mails in `mail_outbox` are not linked to an account and so not removed
with it (they are deleted once sent).

## Updating the Licenties

After any change to the Android app's dependencies, on a machine that
builds the app:

    cd OwnifyAndroid
    ./gradlew -q :app:dependencies --configuration releaseRuntimeClasspath > /tmp/deps.txt
    php ../tools/licenses-build.php /tmp/deps.txt

It reads each artifact's licence from its POM in Gradle's cache (its
parent's when it has none) and names any it cannot place. A new project
needs a line in `legal_android_projects()` (`lib/legal.php`): its name,
source and copyright; a new licence its text in `config/licenses/` and its
name in `legal_licence_names()`. The website's one package is PHPMailer,
whose version is read from `includes/vendor/phpmailer/VERSION`.

    php tools/legal-test.php

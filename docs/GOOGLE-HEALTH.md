# Google Health — Fitbit and Pixel Watch through a Google account

Built in 44.0. The cloud way to a Fitbit's or Pixel Watch's data: the person
signs in with Google once, and Ownify's server reads the **Google Health
API** (v4) from then on — no phone app needed, so it works for iPhone users
with a Fitbit too. Health Connect (the phone, read by the Ownify Android
app) is a separate source: docs/HEALTH-CONNECT.md.

| | |
| --- | --- |
| The code | `includes/google-health.php` |
| Connecting | `api/integrations/google_health/start.php`, `callback.php` |
| A sync now (pull to sync, both apps) | `api/integrations/google_health/sync.php` |
| The scheduled sync | `tools/google-health-sync.php` (cron) |
| Disconnecting | `api/integrations/disconnect.php` → `google_health_disconnect()` |
| The test | `tools/google-health-test.php` (Google stood in for) |

It is built exactly like Polar (docs/POLAR.md) and shares its tables
(`integration_oauth_states`, `user_integrations`, migration 020): the same
one-time state, bound to the browser that started it, a confirmation page
naming the Ownify account when it was started in the app, tokens sealed with
`OWNIFY_APP_KEY`. Two differences: Google is asked for offline access (a
refresh token), and disconnecting also withdraws Ownify's access at Google.

## What is read

Every half hour (cron), right after connecting, and on a pull to sync: the
first time **30 days** back (steps, distance, active calories and heart rate,
which come minute by minute, **7 days**), after that from the last sync with
three days' overlap. Each data type is listed with Google's own filter
(`users/me/dataTypes/{type}/dataPoints`) and becomes the same records Health
Connect gives, through the same mapping (`health_connect_map()`), so a night
or a step means the same whichever way it arrived:

| Google Health data type | Becomes |
| --- | --- |
| `exercise` | a workout — with Google's distance, calories, average heart rate, speed and climb, so intensity and personal records work |
| `sleep` | a night with its stages (LIGHT, DEEP, REM, AWAKE; RESTLESS as Rusteloosheid; out of bed), filed under its morning; its sleeping heart rate from the heart-rate samples in it |
| `steps`, `distance`, `active-energy-burned` | the metrics, with their intervals, counted once against other apps |
| `heart-rate` | heart rate minute by minute (Training) |
| `daily-resting-heart-rate`, `daily-heart-rate-variability`, `daily-oxygen-saturation`, `daily-respiratory-rate` | the day's figure, filed in the morning |
| `weight`, `body-fat`, `height` | Lichaamsmaten |
| `hydration-log`, `nutrition-log` | water, and meals with their nutrients |

Every record keeps Google's own id: fetching a day again rewrites it. A
point Google got from Health Connect is left out for a person who also syncs
Health Connect with the Ownify app — it arrives that way already. The
connection is named after the watches and trackers the data came from.

## Setting it up (free)

1. **A Google Cloud project** (console.cloud.google.com): create one, then
   *APIs & Services › Library › Google Health API › Enable*.
2. **The OAuth consent screen** (*Google Auth Platform*): user type
   **External**, the app's name (Ownify), a support e-mail, and under *Data
   access* the four read-only scopes:
   `…/auth/googlehealth.activity_and_fitness.readonly`,
   `…/auth/googlehealth.health_metrics_and_measurements.readonly`,
   `…/auth/googlehealth.sleep.readonly`,
   `…/auth/googlehealth.nutrition.readonly`.
3. **Test users**: while the app is in *Testing*, add the Google account of
   every person who will connect (at most 100).
4. **An OAuth client ID**, type **Web application**, with this authorized
   redirect URI, exactly:
   `https://ownify.acits.nl/api/integrations/google_health/callback.php`
5. **On the server**, outside the web root and outside Git — the
   environment (`GOOGLE_HEALTH_CLIENT_ID`, `GOOGLE_HEALTH_CLIENT_SECRET`) or
   `config/integrations.local.php`:
   ```php
   return [
       'google_health' => [
           'client_id'     => '…apps.googleusercontent.com',
           'client_secret' => '…',
       ],
   ];
   ```
   `OWNIFY_APP_KEY` must be set (it is, for Polar).
6. **The scheduled sync**, in Hestia's cron beside Polar's:
   ```
   */30 * * * * cd /home/<hestia-user>/web/ownify.acits.nl/public_html && php tools/google-health-sync.php >/dev/null 2>&1
   ```

Until 5 is done, Instellingen says *Deze koppeling is op de server nog niet
ingesteld.* — never the old *nog niet beschikbaar in Ownify*.

## How a sync runs (44.2)

What the live site showed before 44.2 (`.github/workflows/google-health-probe.yml`,
a read-only look run by hand): Google answered every type, but no sync ever
finished. A page is up to 5,000 points, some 2–5 MB of JSON. A week of a
Fitbit's heart rate is about 120,000 samples, and a month several hundred
thousand. The sync held all of them at once and PHP stopped at its 128 MB:
nothing stored, and *Bezig met synchroniseren* left behind for a quarter of
an hour each time.

Now:

- **Page by page.** Each page is mapped and saved as it arrives, in chunks of
  at most 5,000 records. Heart rate goes straight into minutes and never
  keeps the samples. A month of a watch fits in about 40 MB.
- **A time limit of its own.** At most 150 seconds with Google per run. What
  is left comes next time. A first sync cut short starts over from its first
  day rather than skipping anything.
- **A pull answers within 8 seconds** (`sync.php`). It gives the result when
  the sync is done by then. Otherwise it gives `status: "running"` and the
  sync carries on on the server. The app waits at most 15 seconds, and a
  longer wait read as *Synchroniseren mislukt*. A pull while a sync is
  already running is not a failure either.
- **The status stays true.** The sync renews *Bezig met synchroniseren* as it
  goes. A run that dies anyway (out of memory, out of time) is caught by a
  shutdown guard: the status is cleared and the source says the sync was cut
  short.

Google Health and Health Connect (the Ownify Android app) stay separate. A
point Google got from Health Connect (`platform: HEALTH_CONNECT`) is left out
for a person who also syncs Health Connect.

## Testing mode, and what it means

A project in *Testing* costs nothing, but Google:

- shows an "unverified app" warning on the consent screen, which people can
  click past;
- allows only the test users added in step 3;
- **ends the refresh token after 7 days**. Ownify then marks the connection
  to be made again (*De toegang tot Google Health is verlopen of
  ingetrokken. Koppel Google Health opnieuw.*) and stops asking; one tap on
  *Opnieuw koppelen* in Instellingen and it carries on, nothing lost.

Publishing the project (*In production*) removes the 7 days and the list of
test users, but health scopes need Google's verification of the app first.
Whether that review asks for a paid security assessment depends on how
Google classes these scopes when you apply — check before starting it.

## Testing without Google

```bash
DB_NAME=ownify_dev DB_USER=root php tools/google-health-test.php
```

Connecting (the consent address, the state, the confirmation page, a used or
strange state, refused at Google), the first sync of every type, nothing
doubled, Health Connect's points left out, a refused type, a refreshed
token, a refused refresh (the 7 days), and disconnecting — against a Google
stood in for in the same process.

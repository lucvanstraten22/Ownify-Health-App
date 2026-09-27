# JoLu Android ↔ web parity

The native app is a copy of the JoLu web app (`index.php`, `pages/`,
`components/`, `assets/css/`, `assets/js/`). The web app is the source of
truth for everything on screen: layout, copy, tokens, states, motion and
gestures. This file is the inventory the rebuild is checked against, and the
list of places where the app cannot, or deliberately does not, copy it.

Nothing is worked out in the app. Scores, points, goal progress, chart
geometry, rankings, dates such as "Nog 3 dagen" and every label come from the
server, from the same hydrators that fill the web pages (see "Data" below).
The app formats and lays out what it is given.

## Data

The web app renders every page server-side in one request. There was no JSON
read endpoint, so the app had nothing to read its screens from. The one
addition on the server:

| Endpoint | What | Auth |
| --- | --- | --- |
| `api/app/state.php` | the data `index.php` renders — the same pipeline (`app_page_data()` in `lib/app-data.php`, which `index.php` now calls too), as JSON | account token (`api_require_account_user()`) |

The write endpoints the pages use take `api_require_account_user()` instead
of the session-only check, so the app can use them with its account token
and the website works exactly as before (without a bearer token it is the
same CSRF + session check). After every write the app reads the state again,
as the website reloads or re-fetches the page after one.

## Screens

| Web | Android | States |
| --- | --- | --- |
| `pages/welcome.php` | `WelcomeScreen` | ring draw-in, rise-in, two buttons; paired-only variant (see deviations) |
| `components/account-modal.php` (signed out) | `AccountPanel` login / register | fields, error box, Google mark (disabled + note when unavailable), working |
| `components/header.php` | `AppHeader` (shared, over the rail) | clear / scrolled (gradient + blur), devices dot |
| `components/devices-popup.php` | `DevicesPopup` | list, empty, "Apparaat koppelen" → Instellingen › Apparaten |
| `pages/overview.php` | `OverviewPage` | score ring (value / empty), legend, lock hint; goal card unset / active / reached; insights; patterns; recommendation; disclaimer |
| `pages/health.php` | `HealthPage` | three area cards (score / empty), combined trend Week / Maand |
| `pages/health-detail.php` ×3 | `HealthDetail` | hero ring in the area's accent, nutrition rating card, tiles, sleep timeline, groups (locked when device-only), area trend |
| `pages/goals.php` | `GoalsPage` | Actief / Behaald, primary slot, list, slots note, empty states, add button (disabled when full) |
| `pages/goal-detail.php` | `GoalDetail` | hero, chips, meter, facts, day calendar, manual entry, Verloop chart (scrub), sources, recent, Beheer (promote, pause/resume, delete + confirm) |
| `components/goal-wizard.php` | `GoalWizard` | six steps, per-step validation, source catalogue, targets, durations, summary, priority, done, errors |
| `pages/community.php` | `CommunityPage` | Vrienden / Nederland × Maand / Jaar / All-time, board, your sticky row, gap row, empty board |
| `pages/settings.php` | `SettingsPage` | identity card, five groups, logout, delete link |
| `pages/settings-detail.php` ×10 | `SettingsDetail` | identity hero, fields, sign-in block, integrations (expandable), choice, states, toggles, rows, notes, not-saved line |
| `components/settings-editor.php` | `FieldEditor` | text / date / choice / measure, once-warning, error |
| `components/settings-confirm.php` | `DeleteConfirm` | two steps, error, working |
| `components/settings-pairing.php` | `PairingPanel` | code, expiry, new code, error |
| `components/account-modal.php` (signed in) + `account-friends.php` | `AccountPanel` account + Vrienden views | identity, username, avatar, friends nav + badge, search, requests, sent, toggle, friends with in-place confirm |
| `pages/ai.php` | `AssistantSheet` | handle, close pill, orb, "Binnenkort beschikbaar", composer slot |

## Chrome and motion

- Rail of five pages, start on Overzicht; tab bar re-tap scrolls to top.
- Detail layer above the rail, below the dock; closes with a rightward swipe,
  the back pill, or system back (predictive).
- Assistant sheet above everything but the panels; opens by a swipe up from
  the dock or the handle, closes by a swipe down on its top or the pill.
- One gesture resolver for all three (`navigation-core.js`): axis lock at
  8 dp, ratio 1.15, force at 24 dp; a flick (0.3 dp/ms after 16 dp)
  completes, a quick swipe (≤ 300 ms, ≥ 32 dp) completes, otherwise past a
  quarter, or past half after holding still for 160 ms. A layer caught
  mid-way carries on from where it is.
- Durations: screen 280 ms `(.22, 1, .36, 1)`, fast 180 ms, slow 420 ms
  `(.22, .61, .36, 1)`; meters and count-ups 1000 ms ease-out-cubic, ring
  1100 ms; reveal 14 dp + fade, staggered 60 ms.

## Tokens

All from `assets/css/theme.css`, `components.css` and the page sheets; CSS
px are dp, rem is 16 sp. Font: the web's stack is the system sans-serif,
which on Android is Roboto — the app uses the platform default for the same
reason.

## Deviations

| What | Why |
| --- | --- |
| Pairing with a code lives in the sign-in panel ("Koppel met een code") and a paired-only phone sees the welcome screen with its sync status | the website has no pairing form (it hands out codes); pairing must keep working in the app |
| Health Connect card in Apparaten & Gezondheid shows this phone's permissions, background access, last sync and an enabled "Nu synchroniseren" | Android-only; the website's card is read-only for a phone source |
| OAuth sources (Google Health) open nothing in the app | their connect flow is a browser redirect bound to a website session |
| Google sign-in shows the website's "not available" state | needs Android OAuth clients configured on the server and Credential Manager; the ids belong to the deployment |
| After deleting an account with Google linked, the app shows the website's "revoke it yourself" notice instead of redirecting | the Google revoke is a browser redirect bound to a session the app does not have |
| Backdrop blur only on Android 12+ | `RenderEffect` does not exist before API 31; older phones get the same translucent surfaces without blur, as the website does without `backdrop-filter` |
| System back closes the frontmost layer | Android navigation; the website relies on Escape and the pills |

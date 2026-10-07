# Ownify Android ↔ web parity

The native app is a copy of the Ownify web app (`index.php`, `pages/`,
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

Ownify AI, the assistant in the sheet, has endpoints of its own —
`api/ai/state.php`, `chat.php`, `consent.php`, `action.php` and `delete.php`
(`docs/AI.md`) — and the app uses exactly those, with its account token, as
the website does with its session: the same conversations, the same daily
count, the same consent. Neither talks to Gemini; the key never leaves the
server. The sheet's copy comes with the pages (`data.ai`), and its answers
arrive as paragraphs, headings and lists of text spans that both sides draw
with bold only.

## Screens

| Web | Android | States |
| --- | --- | --- |
| `pages/welcome.php` | `WelcomeScreen` | the Ownify logo (icon-only, decorative — the name follows), rise-in, two buttons; paired-only variant (see deviations) |
| `components/account-modal.php` (signed out) | `AccountPanel` login / register | fields, error box, the Google mark (`.social`: 46 round soft glass, the G at 21, pressed 0.94, disabled at 50 % + "Google is nog niet gekoppeld." when unavailable), the Google username step ("Kies je gebruikersnaam", Annuleren), working |
| `pages/setup.php` | `SetupScreen` (instead of the shell while `setup.pending`, crossfading to it) | the app's name and Uitloggen; four bars and "Stap 2 van 4"; each step sliding in from the side it comes from; Focus (five choices as a radio group, the chosen one ringed in its colour, Verder disabled until one is chosen, error); Gegevens (Health Connect, its status and last sync, the manual line, the later line; Doorgaan zonder koppelen, or Verder once connected — see deviations); Over jou (birth date, height, weight, each with its reason; a birth date already given shown locked; Overslaan / Opslaan en verder; error; under 360 dp the three buttons on two rows); Doel (a suggested goal with what it is based on, Toevoegen / Niet nu; the added line; Zelf een doel instellen opening the goal wizard; Naar Ownify; error); working |
| `components/header.php` | `AppHeader` (shared, over the rail) | clear / scrolled (gradient + blur), devices dot |
| `components/devices-popup.php` | `DevicesPopup` | list, empty, "Apparaat koppelen" → Instellingen › Apparaten |
| `pages/overview.php` | `OverviewPage` | score ring (value / empty), legend, lock hint, the direction under the score (Stijgend / Stabiel / Dalend, or none); the whole score card opens the Scorekompas (pressed 0.97; for TalkBack the label is the button, as on the website); goal card unset / active / reached; insights; patterns; recommendation; disclaimer |
| `components/calibration.php` | `CalibrationCard` (first on `OverviewPage`, days 1 to 5 of a new account) | "Dag 2 van 3" / "Je basislijn"; building (a row per category in the focus's order: solid tile, "2 van 3 nachten", a step per day lit in the category's colour, what the days hold or where the data comes from; the day's fact); the first score (`components/compass-category.php` = `CategoryBox`, the Scorekompas's own category, and what the Health Score rests on); the starting point (each category with a score, its band's dot and one fact; what comes later); no starting point yet; the link to the Scorekompas |
| `pages/score-compass.php` + `components/compass-history.php` + `components/score-direction.php` | `ScoreCompassDetail` (`Detail.ScoreCompass`) | hero ring in Overzicht's gradient, direction; Waar je score uit bestaat (categories in their colour, scores with their band's dot, components with their weight now, 4 dp meters in the band's colour, "Telt nu niet mee", Voeding's one sentence); Wat er verandert (the wide range switch 7 dagen / 30 dagen / 90 dagen / 1 jaar, the period's direction chip, sentences and "Je geschiedenis begint op…", its line drawn on with a dot per day in a week and a ring for a carried day, a gap for a day without a score, dates under their days; a finger reads a day — crosshair, date and score above the line, linger 1.6 s — and the day panel under it shows the day's score, note and categories with their parts; today until a day is read and again after a period change; the arrow keys on the website are TalkBack's Vorige dag / Volgende dag actions in the app; collecting / empty; a server from before the periods: its 30-day card); Vergeleken met jezelf (four rows, "Nog niet genoeg gegevens", the difference); Grootste kans (component, what was measured, what goes with a higher score, room; empty / none); footnote |
| `pages/health.php` + `components/health-history.php` | `HealthPage`, `HistoryCard` | three area cards (score / empty); the Verloop: Slaap, Voeding and Training per day over the Scorekompas's 7 dagen / 30 dagen / 90 dagen / 1 jaar (the switch at the head's far end on a wide screen, a row of its own on a phone), each line in its category's colour, a dot for every day of a week (small ones in a month), a ring for a carried day, a gap for a day without a score, a date under every day of a week; a finger reads a day — crosshair, a dot on each line, the date and each category's score above the lines, linger 1.6 s; TalkBack's Vorige dag / Volgende dag; the legend and the hint; empty; a server from before it: the combined Week / Maand trend |
| `pages/health-detail.php` ×3 | `HealthDetail` | hero ring in the area's accent, nutrition rating card, tiles, sleep timeline, groups (locked when device-only), area trend |
| `pages/goals.php` | `GoalsPage` | Actief / Behaald, primary slot, list, slots note, empty states, add button (disabled when full) |
| `pages/goal-detail.php` | `GoalDetail` | hero, chips, meter, facts, day calendar, manual entry, Verloop chart (scrub), sources, recent, Beheer (promote, pause/resume, delete + confirm) |
| `components/goal-wizard.php` | `GoalWizard` | six steps, per-step validation, source catalogue, targets, durations, summary, priority, done, errors |
| `pages/community.php` | `CommunityPage` | Vrienden / Nederland × Maand / Jaar / All-time, board (profile picture over the initial, when its owner shows it), Vrienden toevoegen row (Vrienden boards only, above #1; opens Vriend toevoegen), your sticky row, gap row, empty board |
| `pages/settings.php` | `SettingsPage` | identity card, the groups below it (Gezondheid, Privacy, App, Voorkeuren, Over), logout, delete link |
| `pages/settings-detail.php` ×10 | `SettingsDetail` | identity hero, fields, sign-in block, integrations (expandable), choice (Thema's Systeem / Donker / Licht saves and switches at once; no not-saved line under it), states, toggles (Privacy's "Profielfoto op de ranglijst" and "Gegevens verwerken met Google Gemini" save), actions (Privacy's "AI-gesprekken wissen": Alles wissen, in-place confirm, done line, error), rows, notes (one under its own heading: Over de app's Hulp), not-saved line |
| `components/settings-editor.php` | `FieldEditor` | text / date / choice / measure, once-warning, error |
| `components/settings-confirm.php` | `DeleteConfirm` | two steps, error, working |
| `components/settings-pairing.php` | `PairingPanel` | code, expiry, new code, error |
| `components/account-modal.php` (signed in) + `account-friends.php` | `AccountPanel` account + Vrienden views | identity, username, avatar, friends nav + badge, search, requests, sent, toggle, friends with in-place confirm |
| `pages/ai.php` + `components/ai-consent.php`, `ai-empty-state.php`, `ai-composer.php` | `AssistantSheet` | handle, close pill, new-conversation and history buttons; consent (five points, Toestaan en beginnen / Niet nu, error), declined (Toestemming bekijken); empty (orb, name, no-data line, three suggestions); conversation (your bubble, the answer's paragraphs, headings and lists with bold, Ownify's own notes, a proposal card with its two buttons and then its outcome); thinking dots; notices (unavailable, free quota, today's limit, too slow, no connection — with Opnieuw proberen where it helps, the question kept in the field); composer (grows to five lines, send, "Nog 7 van 10 berichten vandaag"); history (list with Vandaag / Gisteren / date, open, delete with in-place confirm, empty) |

## Chrome and motion

- Rail of five pages, start on Overzicht; tab bar re-tap scrolls to top.
- Detail layer above the rail, below the dock; closes with a rightward swipe,
  the back pill, or system back (predictive).
- Assistant sheet above everything but the panels; opens by a swipe up from
  the dock or the handle, closes by a swipe down on its top or the pill.
  Inside it the conversation scrolls to its newest message, and the composer
  stays above the keyboard: the website follows `visualViewport`
  (`--ai-keyboard`), the app the keyboard's insets.
- One gesture resolver for all three (`navigation-core.js`): axis lock at
  8 dp, ratio 1.15, force at 24 dp; a flick (0.3 dp/ms after 16 dp)
  completes, a quick swipe (≤ 300 ms, ≥ 32 dp) completes, otherwise past a
  quarter, or past half after holding still for 160 ms. A layer caught
  mid-way carries on from where it is.
- The tab bar's chosen pane is one piece of glass on both: a tab sends it
  there, a swipe carries it under the finger. It moves on one damped spring
  in tab units — damping ratio 0.8, stiffness 340 (page-navigation.js, and
  `GlassSpring` in Dock.kt) — about 190 ms to arrive and 1.5 % past the mark,
  keeps its speed when sent somewhere else on the way, and stretches up to
  30 % along the bar with its speed (a quarter of that thinner). With reduced
  motion (the browser's setting, Android's "remove animations") it goes
  straight to the tab.
- Durations: screen 280 ms `(.22, 1, .36, 1)`, fast 180 ms, slow 420 ms
  `(.22, .61, .36, 1)`; meters and count-ups 1000 ms ease-out-cubic, ring
  1100 ms; reveal 14 dp + fade, staggered 60 ms.

## Tokens

All from `assets/css/theme.css`, `components.css` and the page sheets; CSS
px are dp, rem is 16 sp. Font: the web's stack is the system sans-serif,
which on Android is Roboto — the app uses the platform default for the same
reason. Icons are generated from `components/icons.php`
(`tools/gen_icons.py`), the solid ones for coloured tiles (`icon_solid()`)
included.

Colour has two separate meanings, identical on both sides:

- Category (`--sleep`, `--nutrition`, `--training` and their `-light`
  shades; `Ownify.Sleep` … / `Accent`): which part of health something is.
  Fixed per category, never changed by a score. `health` (`--health`) is the
  app's own green for positive states and for what has no category;
  `--attention` / `Ownify.Attention` is warnings; `--neutral` /
  `Ownify.Neutral` is the solid grey tile of a card head without a category.
- Score (`--score-high/-mid/-low`; `Ownify.ScoreHigh/Mid/Low` / `ScoreBand`):
  how high a score is — only the dot beside each pillar on Overzicht. The
  band (80–100, 60–79, 0–59) is decided once on the server
  (`score_colour_band()`, `config/scoring.php`) and sent as `score_band`;
  neither client works it out. `tools/health-score-test.php` and
  `ColourSystemTest` check the hex values match on both sides.

Two themes, one design (`docs/THEME.md`). Dark Mode (`:root` in theme.css)
and White Mode (`:root[data-theme="light"]`) are `OwnifyPalette.Dark` and
`OwnifyPalette.Light`, token for token, and the screens read them through
`Ownify` — so a change of theme redraws everything at once. Every colour a
component writes for itself is one of the website's roles, under the same
name: `Ownify.ink(a)` is `rgba(var(--ink), a)`, and so on for `fill`, `lift`,
`glint`, `shade`, `scrim`, `ground`, `paneBody` and `tint`. In Dark Mode each
role is exactly the white or black it replaced. `ColourSystemTest` reads both
blocks of theme.css and compares every token; the categories and the score
bands keep their meaning in both, and only `--score-mid`, `--score-low` and
`--attention` (text and icons; its washes keep the original gold) deepen in
White Mode.

Text is laid out the way the browser lays it out, not with Compose's
defaults:

- Letter spacing is inherited as CSS inherits it: body's `-0.01em` resolves
  once, to −0.15 px at every size below it (`LocalTracking`); inside a
  `<button>` or `<input>` it is `normal` (`InButton`).
- Glyphs advance linearly (`TextMotion.Animated`), as Chrome's do.
- A line box is lines × line-height, rounded to the pixel, and a line height
  below the font's own is honoured (`cssLineBox`).
- A line's width includes the letter-spacing after its last letter, as the
  browser's does and Android's does not: the "%" after a big tracked number
  sits where it does on the website, and a line with negative spacing fits
  as much before it breaks.
- `max-width` in `ch` measures the digit zero, as CSS does.
- A 1 px border takes room (CSS `border-box`): cards, panels, chips, pills,
  buttons, fields, switch options and board rows inset their content by it.
- Box edges are rounded where they fall, as the browser rounds them when it
  draws, not side by side (`cssPadding`): 12 dp above and below a row is
  63 px, not 32 + 32, so rows and cards do not gain a pixel each down a page.
- A settings card's rows are `min-height: 56px` in `border-box` with the
  hairline as the next row's top border (`settingsRow`), so a short row is 56
  with its line.
- `.settings-field` is its grid (`minmax(0, 1fr) auto auto`): the value takes
  what it needs at the right, the label the rest; `.metric-row` and
  `.card__head` put their value and badge at the end (`space-between`).

## Deviations

| What | Why |
| --- | --- |
| Pairing with a code lives in the sign-in panel ("Koppel met een code") and a paired-only phone sees the welcome screen with its sync status | the website has no pairing form (it hands out codes); pairing must keep working in the app |
| Health Connect card in Apparaten & Gezondheid shows this phone's permissions, background access, last sync and an enabled "Nu synchroniseren" | Android-only; the website's card is read-only for a phone source |
| A cloud source's Verbinden (Google Health) opens the website's `api/integrations/<provider>/start.php` in the browser | its consent screen is the provider's own page, a redirect bound to a website session; the browser must be signed in to Ownify |
| Koppel Google (Instellingen › Inloggen en beveiliging) opens the website in the browser | linking is the same session-bound redirect |
| Google's account chooser is the phone's own (Credential Manager, Sign in with Google) instead of Google's web page | the app asks Google on the phone for an ID token; the server verifies it, the phone believes nothing in it |
| After deleting an account with Google linked, the app shows the website's "revoke it yourself" notice instead of redirecting | the Google revoke is a browser redirect bound to a session the app does not have |
| Backdrop blur only on Android 12+ | `RenderEffect` does not exist before API 31; older phones get the same translucent surfaces without blur, as the website does without `backdrop-filter` |
| System back closes the frontmost layer | Android navigation; the website relies on Escape and the pills |
| A profile picture is decoded at a fraction of its size (never below 384 px on its shorter side), one download shared by every row showing it | the boards and friends lists get the server's 192 px copy (`avatar_small()`), which both apps show as is; the header and account still show your own picture as uploaded (up to 3 MB), and the server falls back to the original when it cannot make a copy. The browser scales an `<img>` itself; a phone decoding a full photo per row would run out of memory. Drawn the same size either way |
| The avatar is chosen with Android's photo picker (images only) | the website's `<input type="file" accept="image/jpeg,image/png,image/webp">`; the server checks the type and size as before |
| Dates (Geboortedatum, in Instellingen and in the setup) are picked in the system date dialog | the website's `<input type="date">`, which on an Android browser opens the same kind of dialog |
| The setup's Gegevens step asks this phone's Health Connect for access (the system screen), offers to install or update Health Connect when it is missing or old, and says "Verbonden", with the last sync, only once this phone may read something. The website says where to go (the Ownify app on an Android phone) and shows the server's status | the website cannot reach a phone. The server counts Health Connect as connected the moment a phone signs in (`includes/devices.php`), before Health Connect has allowed anything, so on the phone itself its own permissions are the truth; the server sends both words (`connected_label`, `not_connected_label`) |
| The setup's buttons stay at the bottom of the screen, above the keyboard and the system bars, and the step scrolls above them | the website's page is at least the screen's height with the buttons at its foot, so a short step has them at the bottom too, but a long one (Over jou with a phone's keyboard open) scrolls them along with the page; a phone browser's keyboard does not resize the layout the way an app's window insets do |
| The browser's own constraint bubbles (`required`, `minlength`) are not drawn | they are the browser's, not Ownify's; the form is sent and the server's message is shown in the panel's error box, as the website shows it when its checks fail. The website's own script messages ("Vul een waarde in.") are copied |
| Focus follows the website: a panel's first field is focused as it opens, the field in error after a failed check, and a tapped button takes focus from a field | what the website's script and the browser do; the keyboard therefore opens with the login and editor panels |
| The assistant's state is read the first time the sheet opens, also when it opens on the consent question | the website renders the pages, and so the consent, fresh on every visit and only reads the conversation when it opens on it; the app keeps its pages in memory for long, so a yes or no given on the website since is picked up this way |
| Enter in the assistant's field is a new line; the send button sends | as on a phone's browser: the website sends on Enter only with a mouse or trackpad (`pointer: fine`) |
| The theme is kept on the phone (`shared_prefs/ownify_theme.xml`); the website keeps the browser's in a cookie. Each remembers its own | a choice of the device, on both — Systeem, the default, follows the device on both; the app sends no cookie, so the server's state always names Systeem and the app puts its own choice there (`withTheme()`, as `settings_use_theme()` does on the website) |
| The system bars follow the theme — light icons over Dark Mode, dark ones over White — and the system's date picker and the Health Connect rationale open in the app's theme | the website sets the browser's `theme-color`, `color-scheme` and the iPhone status-bar style instead |
| On Android 12 and older a cold start shows the system's starting window in the phone's own appearance for a moment (the manifest's `Theme.Ownify.System`) — the other theme only when Donker or Licht is chosen against the phone | the platform draws it before the app runs; from Android 13 the next start's splash follows the choice (`setSplashScreenTheme`). The website is painted in its theme from the first frame |

## Verification

Every page, detail, panel and wizard step — 61 scenarios, the demo account
with friends, goals and a week of data — is captured on both sides at the
same size and pixel ratio: the website in Chromium (412 × 915, 2.625, reduced
motion, real taps) and the app in `ScreenshotCapture` (412 × 915 dp, 420 dpi,
hardware rendering, motion still), each from the same `api/app/state.php`
answer of the same day. Each text's position, width, line count, size,
weight and letter spacing is compared, then the shots are compared by eye
and by sampled colour.

Both themes. Dark Mode was captured on both sides before White Mode existed
and again once it was finished: all 67 app shots and 59 of the 61 website
shots are identical to the pixel — the two others are Thema & uiterlijk's new
copy and the opening screen's turning ring, which differs between any two
captures. White Mode (`-Downify.theme=light`, and the website with its
cookie) is compared side by side, scenario by scenario, as Dark Mode is.

What is left, and why:

| Difference | Why |
| --- | --- |
| A few long lines break one word earlier or later on one side (the Voeding detail's lede, one line of the overview) | the test browser's font measures text about −1 % to +0.5 % wider than Android does, depending on size; a phone's browser and the app share the same font and metrics |
| Weight 600 looks bolder in the browser shots and lighter in the app shots | the test machines' fonts are static Roboto files (600 is drawn as 700 by Chromium, as 500 by Robolectric); a phone's variable Roboto draws 600 on both |
| Texts sit on average 0.9 dp lower or higher than on the website, at most about 5 dp far down a long scrolled page | Compose lays out on whole pixels; box edges are rounded as the browser rounds them, but a line of text, a meter or a picture still rounds its own height |
| The page under the scrolled header is blurred in the app and sharp in the website shots | the app draws `.app-header.is-scrolled` as written (a 30 px backdrop blur, saturate 140 %, the tint); the test browser draws a 30 px backdrop blur there as none at all (an 8 px one it does draw). What a phone's browser draws was not checked |
| The website's detail layer throws a shadow band at the screen's right edge while closed | a website bug (the hidden layer keeps its `box-shadow`); the app does not copy it |
| Until 1.4.0 the website's trend lines stopped about a tenth short of their last point once drawn on | `health-trend.js` dashed a line by its length in the chart's 300 × 120 units while the stroke's dashes are screen pixels (`non-scaling-stroke`) on a stretched chart; it now measures the line as drawn. The app always drew the whole line |
| "calorieÃ«n" in a metric label in the local test data | the label was stored double-encoded by a test import; both sides show what the server sends |

Not verified here: nothing ran on a physical phone or an emulator (the
build machine has neither), so real Health Connect, the Keystore, the photo
picker, backdrop blur on a GPU and gesture feel were checked in Robolectric
and by reading, not on a device.

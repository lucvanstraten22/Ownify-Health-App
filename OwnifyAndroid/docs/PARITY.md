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
| `pages/overview.php` | `OverviewPage` | the score hero on the ground, no card (17.0 "Halo"): Vandaag at Large Title, the ring at 244 dp — 62 % of a narrower screen (`--hero-ring`, `Ownify.HeroRing`) — stroked 8 in Overzicht's green from its light to itself, a static glow behind it, the number at 1.32 × score size; the three pillars as capsules (value at Title 2, dot, name); value / empty, lock hint, the direction under the score (Stijgend / Stabiel / Dalend, or none); the whole score card opens the Scorekompas (pressed 0.97; for TalkBack the label is the button, as on the website); goal card unset / active / reached; insights and the recommendation Dimmed while they have nothing to show (a quieter fill, a hairline instead of the shadow and the top light, the eyebrow secondary, rows divided by hairlines) and a full card again once an insight is there; disclaimer |
| `components/calibration.php` | `CalibrationCard` (first on `OverviewPage`, days 1 to 5 of a new account) | "Dag 2 van 3" / "Je basislijn"; building (a row per category in the focus's order: solid tile, "2 van 3 nachten", a step per day lit in the category's colour, what the days hold or where the data comes from; the day's fact); the first score (`components/compass-category.php` = `CategoryBox`, the Scorekompas's own category, and what the Health Score rests on); the starting point (each category with a score, its band's dot and one fact; what comes later); no starting point yet; the link to the Scorekompas |
| `pages/score-compass.php` + `components/compass-history.php` + `components/score-direction.php` | `ScoreCompassDetail` (`Detail.ScoreCompass`) | the hero on the ground as Overzicht's, the ring in Overzicht's green, direction; the bar takes "Scorekompas" once the hero's name has gone under it (`.detail__top.is-titled`, `DetailHeader(title)`: its veil deeper, `--veil-deep-*`; hidden from screen readers, the heading below stays the one read); Waar je score uit bestaat (categories in their colour, scores with their band's dot, components with their weight now, 4 dp meters in the band's colour, "Telt nu niet mee", Voeding's one sentence); Wat er verandert (the wide range switch 7 dagen / 30 dagen / 90 dagen / 1 jaar, the period's direction chip, sentences and "Je geschiedenis begint op…", its line drawn on as the Verloop's on Ownify's time axis (docs/CHARTS.md: a young history from its first day at the left, the rest empty ahead, a full one rolling; a point a day over 7 and 30 days, a week over 90, a month over a year; 160 dp over the period's own range, levels named in a 26 dp gutter, no wash) with a dot per day in a week, per week and per month and where the line begins, a ring for a carried day, a gap for a point without a score, the period's dates centred under their days (two rows over 30 and 90 days, `TickAxis`/`TickLabel`); a finger reads a point — crosshair, date or days (with weekgemiddelde / maandgemiddelde beside them) and score above the line, linger 1.6 s — and the panel under it shows the point's score, note and categories with their parts (a week's or month's means, no parts); today until a day is read and again after a period change; the arrow keys on the website are TalkBack's Vorige dag / Volgende dag actions in the app; collecting / empty; a server from before the periods: its 30-day card); Vergeleken met jezelf (four rows, "Nog niet genoeg gegevens", the difference); Grootste kans (component, what was measured, what goes with a higher score, room; empty / none); footnote |
| `components/sleep-night.php` + `sleep.js` | `SleepNightCard` | Slaapverloop: the night's head (date, slept, efficiency), five rows named with their time (96 dp column, 34 dp rows), each period a block on its row from bedtime to wake time (lanes in `fill(0.035)`, 4 dp corners, at least 2 dp wide), the times under it; a finger reads the period (stage, began – ended) above the night, the block ringed and the others at 38%, linger 1.6 s; TalkBack's Volgende fase / Vorige fase / Fase verbergen; no stages: the note |
| `components/area-chart.php` (mini) + `area-charts-grid.php` + `area-charts.js` | `AreaChartsGrid`, `AreaChartMini` (`AreaCharts.kt`) | an area's charts (Slaap's four, Training's): two by two, each square, a row as tall as its taller card (a `SubcomposeLayout` measuring the heads, as the website's grid rows grow); name and chevron, the latest value (each series' key with its value, a pair wrapping whole — `FlowRow`), the week on `HistoryPlot` with its dates in two compact rows, only a baseline; in the area's colour (`Accent.of(area)`: bars, a line alone, a line beside bars in its lighter shade); a tap opens its page, a sideways drag reads (`readOnDrag`), an upright one scrolls |
| `pages/area-chart.php` | `AreaChartDetail` (`Detail.AreaChart(area, id)`) | the page over its area (`ShellState.under`, detail-layer.js's `parent`): its name, the switch, the chart 160 dp tall (levels named for a line alone, for bars alone and for a shared height; bars and a line on heights of their own: none), legend for two series, the hint; back, swipe and system back return to the area as it was; a tab closes both |
| `components/training-view.php` + `training-sessions.php` | `TrainingViewContent`, `SessionsCard` (`TrainingView.kt`) | Training drawn (docs/TRAINING.md): the latest four sessions (a Training-coloured bar, kind, day · time, duration; each opens its page) beside Trainingen per dag, the two halves 1.2 times as tall as wide or as the list needs (`minIntrinsicHeight`); then the four charts, the heart rate, HRV and Hartbelasting |
| `components/heart-chart.php` + `heart-plot.php` + `heart-zones.php` + `training.js` | `HeartCard`, `HeartPlotView`, `HeartZonesLegend` | the five-option switch (Vandaag … 1 jaar, tighter options); under Vandaag the day's name between ‹ and › (34 dp round, dimmed at the ends), a swipe over it or a quick one (≤ 350 ms) over the chart for another day — never consumed, so a slow slide still reads; the day 00:00–24:00 or a period on `HistoryPlot` 160 dp, the line in each zone's colour (`PlotLine.stops`, a vertical gradient with hard edges = the website's SVG gradient), zones 2–4 faint bands (`PlotBand`), a dot only where a day's point stands alone (`dotsAt`), each dot and reading in its zone's colour; the reading: time · zone, Hartslag and bpm; the zones named with their ranges and what they are based on |
| `pages/training-session.php` | `TrainingSessionDetail` (`Detail.TrainingSession(id)`) | the page over Training: kind, day · times, the heart rate during it with its zones, the figures it has as tiles (`MetricTiles(count = false)`: "1:12", "5:50" and "1.468" are not counted up) |
| `pages/health.php` + `components/health-history.php` | `HealthPage`, `HistoryCard` | three area cards (score / empty); the Verloop: Slaap, Voeding and Training over the Scorekompas's 7 dagen / 30 dagen (a point a day) / 90 dagen (a week) / 1 jaar (a month) on Ownify's time axis (docs/CHARTS.md: a young history from its first day at the left, the rest empty ahead, a full one rolling; each line from a point of its own; the dates centred, two rows over 30 and 90 days) (the switch at the head's far end on a wide screen, a row of its own on a phone), 160 dp tall over the period's own range with its levels named in a 26 dp gutter, each line in its category's colour, a dot for every point (small ones for a month's days), a ring for a carried day, a gap for a point without a score, a date under every day of a week; a finger reads a point — crosshair, a dot on each line, the date or days (and, for a week or month, that it is their mean) and each category's score above the lines, linger 1.6 s; TalkBack's Vorige dag / Volgende dag; the legend and the hint; empty; a server from before it: the combined Week / Maand trend |
| `pages/health-detail.php` ×3 | `HealthDetail` | the hero on the ground, its ring in the area's accent from its light to itself, the bar taking the area's name once the hero's name has gone under it (as the Scorekompas); nutrition rating card, tiles, sleep timeline (an area from a server that sends its `view`: Slaap's `SleepNightCard` and `AreaChartsGrid`, docs/SLEEP.md; Training's `TrainingViewContent`, docs/TRAINING.md), groups (locked when device-only), its own Verloop (`components/health-history.php` with `history_area` = `HistoryCard(only = …)`: its one line over its own height, no legend; a server from before it: the week/month trend) |
| `pages/goals.php` | `GoalsPage` | Actief / Behaald, primary slot, list, slots note, empty states, add button (disabled when full) |
| `pages/goal-detail.php` | `GoalDetail` | hero (a name over 40 characters at Title 3, wrapped balanced, never cut), chips, meter, facts (Periode in one row), manual entry, Verloop chart on Ownify's time axis (the shortest standard period that holds its history, its dates; scrub; each point's note; the source footer), day calendar, Aanpassen (Primair \| Secundair, pause/resume, delete + confirm) |
| `components/goal-wizard.php` | `GoalWizard` | six steps (each arriving from the side it comes from; a step that comes while the one before is still arriving picks up from where that one is), per-step validation, source catalogue, targets, durations, summary, priority, done, errors |
| `pages/community.php` | `CommunityPage` | Community (18.0, docs/CHALLENGES.md): the Ranglijst card (the top of the friends' board this month, the board's own rows 44 high, your row lit, the line about the one ahead; the head row opens the board) and the Uitdagingen card (summary, invitations dashed with Bekijken, the carousel of challenges on now snapping tile by tile and bleeding to the card's edge — every tile as tall as the tallest — and Nieuwe uitdaging as its last tile, the whole width when alone), the note that challenges are not points; no code field here |
| `pages/leaderboard.php` | `LeaderboardDetail` (`Detail.Leaderboard`) | the board as it always was, on a page of its own behind the card: back pill Community, Vrienden / Nederland × Maand / Jaar / All-time, board (profile picture over the initial, when its owner shows it), Vrienden toevoegen row (Vrienden boards only, above #1; opens Vriend toevoegen), your sticky row, gap row, empty board |
| `pages/challenges.php` | `ChallengesDetail` (`Detail.Challenges`) | Nieuwe uitdaging and Code invoeren, one under the other in the Health colour; Uitnodigingen (dashed, "Van … · Bekijken"), Bezig, Afgelopen; each with its kind · metric, name, bar (Samen: everybody's piece side by side, you in the colour, the others in four inks, 2 dp gaps; Wedstrijd: you against the leader), figures and faces; empty state; the bar's title beside the back pill once the page's has scrolled under it (`titleBeside`) |
| `pages/challenge-detail.php` | `ChallengeDetail` (`Detail.Challenge(id, overList)`) | over Uitdagingen (back pill Uitdagingen) or over Community from a tile (back pill Community); kind, name (over 32 characters at Title 2), period · what is left; the big figure, the big bar and the pace or gap with its dot; Wie draagt wat bij / Stand with keys or places and bars, invited people faded; Iemand erbij halen (the code, Kopiëren, Delen, Vrienden uitnodigen) while it runs; the note; Uitdaging verlaten |
| `components/challenge-sheet.php` + `challenges.js` | `ChallengeCreateSheet`, `ChallengeCodeSheet`, `ChallengeReviewSheet`, `ChallengeInviteSheet`, `ChallengeLeaveSheet` in `SheetFrame` | one bottom sheet: rising from the bottom edge, dragged down by its grip or head (a third, or a flick, closes it), a tap outside closes it, a next step in the same sheet growing or shrinking to its height; Nieuwe uitdaging (the preview, Soort, the metric chips, Samen naar's stepper, Hoe lang and from when, Naam with the suggested name, friends to invite in four columns, Uitdaging maken disabled until a metric is chosen; then the code, Kopiëren, Delen, Naar je uitdaging); Code invoeren (five boxes over one number field, the fifth digit looks it up, "Zoeken…", the found challenge to review in the same sheet, a wrong code under the boxes with a shake); Bekijken (facts, faces, what the others would see, that it is on already; Niet nu or Afwijzen, Meedoen; Je doet mee and Bekijken); Vrienden uitnodigen (Kiezen / Gekozen, n uitnodigen); Uitdaging verlaten (Annuleren, Verlaten) |
| `pages/settings.php` | `SettingsPage` | identity card, the groups below it (Gezondheid, Privacy, App, Over; Voorkeuren one row in App, one screen with its three settings), logout, delete link |
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
- Reduced motion — the browser's `prefers-reduced-motion`, Android's "Remove
  animations" (animator duration scale 0, followed live) — is the same on
  both: nothing slides, rises, counts up or loops. A page, a detail or the
  sheet is placed at once and what arrives, or what it uncovers, fades in
  over `--reduce-fade` (180 ms; `navigation-core.js` `fadeIn`,
  `ShellState.fadeIn` — run with the animator scale overridden, since
  Compose would otherwise finish it at once). Reveals fade without rising, a
  pressed surface dims to 72 % instead of shrinking, numbers show their
  value.
- Floating panels (account, devices, goal wizard, the editor, confirm and
  pairing panels) materialise: their frost (24 dp, saturate 130 %), their
  scale from .96 and their opacity arrive together in 240 ms and leave in
  180 ms on the strong ease-out `(.23, 1, .32, 1)` (`--panel-in`,
  `--panel-out`; `OverlayFrame`, `panelLook`, `glassBackdrop(amount)`); the
  devices panel grows from its button, top left. With reduced motion a panel
  only fades, its frost there from the start.
- A press goes in in 140 ms and comes back in 240 ms on the same curve
  (`--press-in`, `--press-out`; `Modifier.press`, the header buttons).
- Meters fill by a transform on the website (`--meter-fill`, never the
  width); the app draws its fill without layout already.
- Hover is the website's only, and only where there is a mouse: every
  `:hover` sits in `@media (hover: hover) and (pointer: fine)`, so a tap on a
  phone never leaves one behind. With a mouse a card that opens lifts and a
  row takes a faint fill; on a phone there is nothing to hover.

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
- Type sizes, letter spacing per size (`--tracking-small` 0 at 12 px and
  below, body −0.01em, titles −0.02em, the large title −0.025em), the tinted
  states (`--health-wash/fill/edge/strong`, `--attention-…`), the focus halo,
  `--radius-inner`, the scroll-edge fades and `--reduce-fade` are tokens on
  both sides; `TokenParityTest` reads theme.css and fails on any that differ.
- `.settings-field` is its grid (`minmax(0, 1fr) auto auto`): the value takes
  what it needs at the right, the label the rest; `.metric-row` and
  `.card__head` put their value and badge at the end (`space-between`).

## Deviations

| What | Why |
| --- | --- |
| Pairing with a code lives in the sign-in panel ("Koppel met een code") and a paired-only phone sees the welcome screen with its sync status | the website has no pairing form (it hands out codes); pairing must keep working in the app |
| Health Connect card in Apparaten & Gezondheid shows this phone's permissions, background access, last sync and how the last sync went | Android-only; the website's card is read-only for a phone source. Since 19.0 neither app has a "Nu synchroniseren" button: the phone syncs hourly and when access changes (WorkManager), a cloud source after connecting and hourly |
| Gezondheid's pull to sync also syncs this phone's Health Connect (OwnifySync.pull), and its tick is Android's own haptic (`GESTURE_THRESHOLD_ACTIVATE` / `CONFIRM`) | a browser cannot reach the phone's Health Connect, and has only `navigator.vibrate` (none in Safari): the website asks the cloud sources and refreshes, and says "Bijgewerkt" when nothing gave new data |
| Voorkeuren › Grotere tekst is the app's text size × 1.125 on top of the phone's own (`LocalDensity.fontScale`); Extra contrast and Animaties uit join the phone's own settings (either one turns them on) | the website's `font-size: 112.5%` and its `data-contrast` / `data-motion` twins of the media queries; the same three choices, kept on the account (OwnifyPreferences) |
| A cloud source's Koppelen (Polar) asks `api/integrations/<provider>/start.php` with the account token for the provider's address and opens it in the browser; coming back, the browser shows a page naming the Ownify account, with Koppelen and Annuleren (docs/POLAR.md) | its consent screen is the provider's own page, in the browser; the phone's browser has no Ownify session, so the connection is confirmed there instead of being bound to one — on the website the browser session that started it finishes it |
| Koppel Google (Instellingen › Inloggen en beveiliging) opens the website in the browser | linking is the same session-bound redirect |
| Google's account chooser is the phone's own (Credential Manager, Sign in with Google) instead of Google's web page | the app asks Google on the phone for an ID token; the server verifies it, the phone believes nothing in it |
| After deleting an account with Google linked, the app shows the website's "revoke it yourself" notice instead of redirecting | the Google revoke is a browser redirect bound to a session the app does not have |
| Backdrop blur only on Android 12+ | `RenderEffect` does not exist before API 31; older phones get the same translucent surfaces without blur, as the website does without `backdrop-filter` — and a panel arriving there scales and fades in without its frost ramping |
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
| Taal and Eenheden are kept on the account, as on the website, and also on the phone (`shared_prefs/ownify_language`), for the screens before signing in and after signing out; Automatisch follows the phone's language (docs/I18N.md) | the website keeps the browser's choice in a cookie for those screens; the app sends no cookie, so it sends its choice — or the phone's language — as `Accept-Language` and adopts the state's `i18n` |
| The app writes its own sentences (sync status, pairing, errors, a few screen-reader labels) from `assets/lang-en.json`, generated from the same catalogue as the website's (`tools/i18n-build.php`) | everything else arrives from the server already in the reader's language and units, as on the website |
| The Health Connect rationale (`PermissionsRationaleActivity`) is in Ownify's language | it was English only; it has no website counterpart |
| The smallest touch area is 48 dp in the app and 44 px on the website | each platform's own minimum (Android's 48 dp, Apple's 44 pt). Neither grows anything that is drawn: the website gives a smaller control an invisible `::after` margin (`--hit-min`), the app lays the control out at its drawn size and lets its clickable answer over 48 dp (`Modifier.hitArea`; Compose's own near-miss rule loses to the page scrolling underneath) |
| More contrast: the browser's `prefers-contrast: more`, Android's contrast level (Android 14+) or "High contrast text" | the same values on both (`OwnifyPalette.more`); each platform has its own setting |
| Reduced transparency is the website's only | Android has no such setting; the app's glass stays as designed |
| Uitdagingen: Kopiëren puts the code on the phone's clipboard and Delen opens the phone's share sheet, each confirmed by the same toast (`OwnifyToast`, `.ch-toast`); the carousel's snap is the app's own (a flick projected as a scroll decelerates, .99, then to the nearest tile) | the website uses the browser's clipboard, `navigator.share` (copying where there is none) and CSS `scroll-snap`; Compose's own snapping only works on a lazy list, which cannot make every tile as tall as the tallest |
| The phone's text size enlarges the app's text (sp), up to 200 %; the chrome's labels — the tab bar, buttons, pills, switch options, chart legends and the three pillar tiles on Gezondheid — stop at 130 % (`ChromeText`) | they sit in boxes of a set height or a third of the screen and would be cut off past it, as iOS keeps its bars' labels; the page's own text grows fully. A browser's zoom enlarges the whole website evenly, so it has no equivalent. Checked at 130, 150 and 200 % in `FontScaleTest` (Robolectric, not a device): nothing is cut off; a goal card's one-line name, a device's name in the devices popup and a chosen file's name are shortened with "…" at 200 %, as they are by design when long |

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

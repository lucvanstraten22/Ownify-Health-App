# Lens 5 — UX, flows, states, motion and accessibility

Reviewer 5 of 9. Read-only; evidence = `$A` (see BRIEF.md). Skills loaded:
apple-design (highest authority), mobile-native, improve-animations (audit
categories only, no plans/), break-ui (phase 1–2 reasoning only),
animation-vocabulary. improve-animations' `AUDIT.md` and break-ui's
`CATALOG.md` are referenced by the skills but are not shipped in this
session, so their categories were taken from the SKILL.md bodies only. The
review was stopped early by the coordinator to save usage; see "Not checked".

## Summary

1. Doelen's empty state ("Nog geen doelen · Eerste doel instellen") is shown
   to dev_jij, who has a goal: goal 1 is `status=ended`, `priority=primary`
   (April 2026, 3.14 %). The Actief panel ignores Afgerond; both apps say the
   same wrong thing. Overzicht's goal card likewise says "Nog geen doel
   ingesteld", and its "Doel instellen" only goes to Doelen (one step more).
2. The web has no history integration: a phone's system back / iOS edge swipe
   leaves the app instead of closing a detail, popup or the sheet (Android
   handles it). Android itself exits from any non-start tab on back.
3. The "double ring" on the back pill is the global `:focus-visible` outline
   on the pill that detail-layer.js focuses programmatically on every open; in
   the harness it shows because the open was a script click. On a real tap it
   most likely does not show in Chrome; iOS Safari unknown.
4. Keyboard focus has two languages: a white 2 px outline (5.97:1) and, on
   every `.glass` control, a green 3 px halo at 24 % that measures 1.41:1
   (Dark) / 1.29:1 (White) against the ground — practically invisible.
5. The "Animaties uit" account setting, switched on in-session, does not reach
   the JS-driven motion (rail, detail fade, tab lift) until a reload; on
   Android the chart draw-ons ignore it entirely.
6. The Overzicht arrival takes ≈ 3.5 s for the score ring and ≈ 5.2 s until
   the pillar rings are drawn, on the most-visited screen, every launch.
7. After a saved setting (password, e-mail, name…) the web reloads the page
   and shows no success message; Android shows the server's message as a
   toast.
8. Most "under 44 px" targets in a11y.json are measurement false positives:
   the real hit areas are extended by `::after` (range switch, AI handle) or
   cover the whole card (Gezondheidsscore, Primair doel).

## Findings

### 5.1 Doelen says "Nog geen doelen / Eerste doel" to someone who has had goals
- Status: VERIFIED · Kind: defect (misleading empty state)
- Where: Doelen, Actief — `$A/web/phone-dark/page-goals-0.png` (dev_jij) is
  pixel-for-pixel the same card as `$A/web/pop-phone-dark/empty-goals.png`
  (dev_lisa, empty account); Android `$A/android/dark/app-goals.png` the same.
  Web `pages/goals.php:20` (`$hasAny = $goals['active'] !== []`), `:65-73`;
  copy `config/goals.php:590-595`; Android `ui/screens/goals/GoalsPage.kt:153`
  (`if (!any) EmptyCard(goals.emptyActive …)`).
- Problem: `ownify_e2e.goals` for user 1 holds one row: "DEV — 300.000 stappen
  in april", `status=ended`, `priority=primary`, `final_pct=3.14`,
  `end_date=2026-04-30`. It sits under Afgerond; Actief shows the first-run
  copy "Stel je eerste doel in … Eerste doel instellen". Nothing says that a
  goal ended or that it can be seen under Afgerond.
- Why it matters: the user's history looks lost; "eerste" is false.
- Proposal: a second Actief empty copy when `completed !== []` (e.g. "Geen
  actieve doelen" + "Je afgeronde doelen staan onder Afgerond." + CTA "Nieuw
  doel instellen"), same card, same component; Android takes it from the same
  `empty` config. Copy needs `t()`/`lang/en`.
- Affects: Overzicht's goal card (5.2); Community's Uitdagingen card shows "1
  uitnodiging" with an invite row and right under it "Nog geen uitdagingen"
  (`$A/web/pop-phone-dark/empty-community.json`: `p.cm-none__title` at y 783
  under `span.cm-head__sub` "1 uitnodiging" at y 517) — same pattern.
- Complexity: S · Risk: goals.js toggles `[data-goals-empty]` after a delete;
  the variant must follow it there too.
- Priority: P1

### 5.2 Overzicht goal card: "Nog geen doel ingesteld", and "Doel instellen" only navigates
- Status: VERIFIED · Kind: usability
- Where: `$A/web/phone-dark/page-overview-0.png` / `-1.png`;
  `components/goal-progress.php:13` (only an active primary goal counts),
  `:62-72` (`data-nav="goals"` + note "Opent de Doelen-pagina").
- Problem: (a) with an ended primary goal the card claims none was ever set;
  (b) the button labelled "Doel instellen" lands on Doelen, where the user has
  to tap "+" or "Eerste doel instellen" again — the helper note exists only to
  explain the extra step; (c) the whole card is already a button to the same
  place (`card__open` with `data-nav="goals"`, `:25`), so keyboard users meet
  two consecutive stops with one destination (a11y.json focus: "Primair doel:
  naar Doelen" then "Doel instellen").
- Proposal: let "Doel instellen" open the goal wizard directly (the existing
  `[data-goal-add]` behaviour) and drop the note; or relabel it "Naar Doelen".
  Copy as in 5.1 when a goal has ended. Same on Android's Overzicht card.
- Complexity: S · Risk: the wizard must be available from Overzicht.
- Priority: P2

### 5.3 Web: system back / edge swipe leaves the app
- Status: VERIFIED (code) · Kind: usability defect
- Where: every detail, popup, the AI sheet. `grep pushState|popstate` in
  `assets/js/` → none (only `replaceState` in account.js:545/553,
  phone-sim.js:101). Android: `ui/app/AppShell.kt:185-194` (BackHandler +
  PredictiveBackHandler), `ui/app/ShellState.kt:384-393`.
- Problem: on Android Chrome the system back gesture, and in iOS Safari the
  left-edge swipe, navigate the browser away (or close the tab) instead of
  closing the open detail/panel. detail-layer.js's own "swipe right to close"
  competes with Safari's edge-swipe-back near the left edge.
- Why: the most common mobile "go back" does the most destructive thing.
- Proposal: push a history entry when a detail/overlay/sheet opens and close
  it on `popstate` (one entry per layer, matching the stack in
  detail-layer.js). NEW CONVENTION (web navigation model) — owner decision.
- Affects: detail-layer.js, account/devices/goal-wizard/challenge sheets,
  ai-sheet.js.
- Complexity: M · Risk: double-closing with the back pill; reload with a
  stale state.
- Priority: P1

### 5.4 Android: back from a non-start tab exits the app
- Status: VERIFIED (code) · Kind: usability
- Where: `ShellState.kt:384-393` — `back()` returns false unless a panel, the
  sheet or a detail is open; `AppShell.kt:185` enables BackHandler only then.
- Problem: on Community/Doelen/… system back closes Ownify instead of
  returning to Overzicht (the start page, PARITY.md "start on Overzicht").
  Android's navigation principle: the start destination is the last screen
  before exit.
- Proposal: when no layer is open and the page ≠ Overzicht, back goes to
  Overzicht (`goTo("overview")`). Web: n/a until 5.3.
- Complexity: S · Risk: low.
- Priority: P2

### 5.5 The double ring on the back pill = programmatic focus + global outline
- Status: VERIFIED (cause) / HYPOTHESIS (real devices) · Kind: defect in
  evidence, possibly on iOS
- Where: `$A/web/phone-dark/detail-sleep-0.png` (and every `detail-*-0.png`,
  `empty-detail-score-compass.png`); `assets/js/detail-layer.js:134-139`
  (focus to `[data-detail-close]` after every open, `open()` → `settle(1,
  true)` at :180); `assets/css/theme.css:608-612` (`:focus-visible { outline:
  2px solid rgba(var(--ink),.55); outline-offset: 3px; border-radius:
  var(--radius-sm) }`). The pill is `.pill`, not `.glass`, so it gets the
  global outline around its own glass rim → "double ring".
- Problem: it is `:focus-visible`, not `:focus` — correct for keyboard. The
  harness opened details with a script `click()` and no prior pointer, which
  Chrome treats as keyboard-like. After a real tap Chrome normally does not
  match `:focus-visible` on the scripted focus; iOS Safari does not focus a
  tapped button at all, so its heuristic for the scripted focus is unknown.
- Proposal: confirm on an iPhone and an Android phone by tapping a card. If
  the ring shows on touch: move focus only when the open came from the
  keyboard (`event.detail === 0` on the click), or focus the detail's heading
  (`tabindex="-1"`, which also tells a screen reader where it is) instead of
  the pill.
- Affects: every detail (88), close-pill focus of sheets.
- Complexity: S · Risk: keyboard users must still land inside the detail.
- Priority: P2

### 5.6 Two focus languages; the glass halo is nearly invisible
- Status: VERIFIED (CSS + computed contrast; no screenshot of a focused glass
  control) · Kind: accessibility defect
- Where: `theme.css:345` (`--focus-halo: 0 0 0 3px rgba(60,158,114,.24)`),
  `components.css:1946-1949` (`.glass:focus-visible { outline:none;
  box-shadow: …, var(--focus-halo) }`); outline offsets differ:
  `theme.css:610` 3 px, `challenges.css:32` 4 px, `challenges.css:192` 2 px,
  `compass.css:51` 0, `compass.css:139-141/165-168` 3 px on the card.
- Problem: the halo over the Dark ground (#1E1C1E) = rgb(37,59,50) → 1.41:1;
  White (#FAF8F8) → 1.29:1 (WCAG 1.4.11 asks 3:1). The white outline is
  5.97:1 (Dark) / 3.81:1 (White). So "Opslaan", Doelen's "+", glass chips and
  selects show almost no focus, while the pills next to them show a strong
  ring. Also `:focus-visible` sets `border-radius` on whatever is focused
  (theme.css:611), changing the shape of focusable elements without their own
  radius.
- Proposal: one indicator for all controls — the existing outline token
  (`rgba(var(--ink),.55)`, 2 px, offset 3 px) also on `.glass`, or the halo at
  a 3:1 strength (would be a token change → owner). Drop `border-radius` from
  the global rule. Android: check JInput/glass focus for hardware keyboards
  (not checked).
- Complexity: S · Risk: visual change on focus only.
- Priority: P2

### 5.7 Tab stops without a role or a visible ring
- Status: VERIFIED · Kind: accessibility
- Where: a11y.json `focus[11]` = `span.goal__track.insight-lock` "Nog 8
  dagen", outline none; `components/insight-progress.php:21` (`<span … tabindex="0">`
  with no role); `components.css:1408-1412` (`.goal__track { outline:none }`),
  `:1447` (focus only shows the tooltip). Same for Overzicht's goal bar
  (`goal-progress.php:40`).
- Problem: a generic focusable span (no role, no action) is a stop of its own
  between "Nuttige inzichten" and "Bekijk onderzoek"; on touch the "Nog 8
  dagen" is reachable only by a 280 ms hold (`dashboard.js:156-197`) with no
  visible hint. The bar-only design is a settled decision (38.0,
  docs/CORRELATIONS.md) — not re-litigated; only its semantics.
- Proposal: no own tab stop when the bar sits in a card that opens (the
  card's head is the stop; put the remaining days in its accessible name), or
  `role="img"` on the span with the reading in its label. PREFERENCE: a
  visible hint for the hold.
- Complexity: S · Priority: P3

### 5.8 "Animaties uit" switched on in-session does not reach the JS motion (web); chart draw-ons ignore it (Android)
- Status: VERIFIED (code, not tested) · Kind: accessibility defect
- Where (web): `navigation-core.js:56` (`reduceMotion: reduceQuery.matches`,
  read once), `:175-180` (updated only by the device's `change` event,
  `event.matches` = the device's value); `document-head.php:116,122-133` (the
  account flag sets `data-motion` and patches `matchMedia().matches` but fires
  no event); `settings.js:247-249` applies it without reload. Readers of the
  stale flag: `page-navigation.js:250,256,286,473` (tab lift spring, scroll),
  `navigation-core.js:68-71` (`fadeIn`), `ai-sheet.js:239`. Only
  `ai-sheet.js:177` and `pull-sync.js:103` also check `data-motion`.
- Problem: after switching it on, CSS stops transform transitions at once,
  but `fadeIn` does not run (hard cut instead of the 180 ms fade) and the tab
  glass still springs, until a reload. Also: a device-setting change while the
  account says "uit" overwrites it with the device's value.
- Where (Android): `ui/design/HistoryChart.kt:172-192`,
  `ui/screens/health/HealthPage.kt:439-448`, `ui/screens/goals/GoalChart.kt:292-303`
  check only `LocalStillMotion` (test/screenshot flag), not
  `LocalReducedMotion` (`Motion.kt:165`, which includes the account's
  `motionOff`, `OwnifyApp.kt:77`). With the phone's "Remove animations" they
  are instant anyway (scale 0); with the account's "Animaties uit" they draw
  over 900 ms. The web's draw-on honours both (`health-trend.js:62`).
- Proposal: make `nav.reduceMotion` a getter over the (patched) matchMedia, or
  update it from `ownifyAccess.apply`; Android: `|| LocalReducedMotion.current`
  in the three draw-ons (chevron nudges/colour fades are fine).
- Complexity: S · Risk: low.
- Priority: P2

### 5.9 Overzicht's arrival is long for the most-seen screen
- Status: VERIFIED (code + evidence) · Kind: usability (motion: purpose &
  frequency, duration)
- Where: `assets/js/hero-ring.js:19-22` (TRAVEL 2500, BLOOM 1000), `:287`,
  `:316-336` (pillars wait for the ring, then 90 ms stagger);
  `components.css:871` (pillar arc 1500 ms); Android `ui/design/HeroRing.kt:37`
  (same 2500). Evidence: `$A/web/phone-dark/page-overview-0.png` (captured
  ≈ 0.9 s after showing) has the ring mid-flight and all three pillar rings
  still empty; `reduced-motion-overview.png` shows the end state.
- Problem: score number counts for 2.5 s, ring settles at ≈ 3.5 s, pillars
  finish at ≈ 3.5 + 0.18 + 1.5 ≈ 5.2 s — every app launch / page load. The
  user cannot read their score or pillars for seconds; the sequence cannot
  be skipped.
- Proposal (PREFERENCE, owner's call on a branded moment): keep the effect
  but shorten it (e.g. travel ≈ 1.1 s like the other rings,
  `components.css:664`), start the pillars with the ring instead of after it,
  and play it once per day rather than per load. Same on Android.
- Complexity: S · Risk: brand feel.
- Priority: P2

### 5.10 No success feedback after saving a setting on the web; full reload
- Status: VERIFIED (code) · Kind: usability + parity
- Where: `assets/js/settings.js:891-923` (field editor: on ok → reopen via
  sessionStorage + `window.location.reload()`; `result.message` unused); more
  reloads at `:123, :346, :398, :481, :1099`. Android:
  `ui/screens/settings/SettingsPanels.kt:420, :552` (`OwnifyToast.show(message)`
  then close).
- Problem: a password or e-mail change on the web ends in a page flash and
  the same-looking row — no "gewijzigd" confirmation; Android confirms with a
  toast. Equivalent actions, different feedback.
- Proposal: carry `result.message` through the reload (sessionStorage, like
  `ownify-reopen`) and show it with the existing toast (`.ch-toast`,
  challenges.js:110) — reuse, not a new component; or show it inline in the
  card.
- Complexity: S–M · Priority: P2

### 5.11 Empty account: three different explanations, one without a way forward
- Status: VERIFIED · Kind: usability (copy/flow)
- Where: `$A/web/pop-phone-dark/empty-overview.png` ("Nog geen gegevens" +
  lock icon "Verbind een bron om je gezondheidsscore te berekenen." — no
  button, no link to the devices popup); `empty-health.png` ("Je eerste score
  volgt na 3 dagen met gegevens: nog 3 dagen."); `empty-goals.png` (an extra
  disclaimer "Je gegevens verschijnen hier zodra je ze vastlegt of een bron
  koppelt." under the goals empty state — goals are not "gegevens").
- Problem: the first screen a new user sees tells them to connect a source
  but gives no control to do it (the watch pill in the header is the way, not
  named); the lock icon reads as "forbidden". Gezondheid promises a countdown
  that will not run without a source.
- Proposal: one sentence for "no source yet" everywhere, with the existing
  text-link/ghost button opening Apparaten; Doelen without the data
  disclaimer while the user has no goals.
- Also seen (lens 4 owns charts): in `empty-health.png` the Verloop's empty
  line "Zodra er meetmomenten zijn…" overlaps the y-axis label "50".
- Complexity: S · Priority: P2

### 5.12 a11y.json's "under 44 px" list is mostly false positives
- Status: VERIFIED (CSS) · Kind: audit correction
- Where: `components.css:594-623` (`.range-switch__option::after`,
  `.ai-handle::after`, `.btn::after` … extend to `--hit-min` 44 px:
  `theme.css:490`); `compass.css:128-134` (`.card__open::after { inset:0 }`
  over `.card { position:relative }`, components.css:22) — the whole card is
  the target; `dashboard.css:238-239` lifts the goal bar/action above it.
- Result: range options 75×34 → 75×44 hit; Actief/Afgerond 177×34 → 177×44;
  AI handle 128×40 → 128×44; "Gezondheidsscore ›" 211×32 and "Primair doel ›"
  101×22 → the whole card. Android: `HitArea.kt` gives 48 dp
  (`OwnifyTheme.kt:626`), `RangeSwitch` uses it (`Components.kt:1019`).
- Remaining: the AI handle's extra 2 px reach into the tab bar's top edge
  (handle y 746–786, tabs from 786) — harmless. A script that measured
  pseudo-element hit areas would be needed for a full sweep (not done).
- Priority: P3 (no fix; correct the observation)

### 5.13 The AI grip is below 3:1 and ignores More contrast
- Status: VERIFIED (computed) · Kind: accessibility
- Where: `components.css:1589-1594` (`.ai-handle__grip` 38×4 px,
  `rgba(var(--ink),.18)`), visible on every page; not a token, so
  `prefers-contrast: more` / `data-contrast` (theme.css:1074-1111) leave it.
- Problem: 1.77:1 (Dark), 1.45:1 (White); pressed 4.02:1 / 2.61:1. It is the
  only visual sign of the assistant besides the gesture.
- Proposal: raise it under More contrast at least (a contrast twin of the
  rule); owner to decide the resting tone.
- Complexity: S · Priority: P3

### 5.14 The swipe-up hint repeats every 6–8 s for anyone who never opens the AI
- Status: VERIFIED (docs/AI.md:67-75, ai-sheet.js:151-205) · Kind: preference /
  accessibility (WCAG 2.2.2 pause/stop/hide is arguable)
- Problem: a deliberate, documented decision; but for a user who does not want
  the AI it is an endless idle animation with no off switch (reduced motion
  keeps a "lit" blink).
- Proposal (PREFERENCE): stop after N sessions or after the first dismissal.
- Priority: P3

### 5.15 Segmented controls are announced as toggle buttons
- Status: VERIFIED · Kind: accessibility (semantics)
- Where: `components/segmented.php:13-20`, `health-history.php:68`,
  `compass-history.php:57`, `area-chart.php:77`, `heart-chart.php:38`,
  `health-trend.php:40` (`role="group"` + `aria-pressed`); Android
  `Components.kt:1019-1023` (`Role.Button` + `selected`).
- Problem: single-choice controls read as independent toggles ("7 dagen,
  schakelknop, ingedrukt"). Actief/Afgerond swaps panels → tabs; period
  switches → radiogroup (as `settings-detail.php:270-277` and the wizard
  already do).
- Proposal: `role="tablist"/"tab"` + `aria-selected` + `aria-controls` for
  Actief/Afgerond; `radiogroup/radio` + `aria-checked` for periods; Android
  `Role.Tab` / `Role.RadioButton` with `selectableGroup()`.
- Complexity: S · Priority: P3

### 5.16 Rail and detail settle on a fixed 280 ms curve; only the sheet takes the finger's speed
- Status: VERIFIED (code) · Kind: preference (motion cohesion, apple-design §5)
- Where: web — CSS transition (`--screen-duration` 280 ms,
  `--screen-ease`, theme.css:421-426) after `place()` in
  `page-navigation.js:323-345` / `detail-layer.js:91-141`; Android
  `ShellState.kt:409, :425` (`tween(ScreenMs, ScreenEase)`); the sheet uses a
  velocity spring (`ShellState.kt:655-658`, docs/AI.md).
- Problem: interruptible (both read the live position — good), but a slow
  release gets the curve's fast start (a lurch) and a fast flick is slowed to
  the curve; the three layers answer the same gesture with two physics.
- Proposal (PREFERENCE): the sheet's critically damped spring with release
  velocity for the rail and detail too.
- Complexity: M · Risk: feel; needs a device feel-check.
- Priority: P3

### 5.17 Desktop
- Status: VERIFIED · ≥ 768 px is only the phone in an iPhone frame
  (`components/phone-simulator.php`); keyboard/mouse users on desktop get the
  touch layout at phone size. Limitation noted; no proposal (out of scope).

## DATA-SUSPECT (not my lens; no calculation change proposed)

- `$A/web/phone-dark/page-health-0.png`: Verloop says "Je geschiedenis begint
  op 11 november." with one point, for dev_jij who has 120 days of synced
  data. Lens 4 / data owner to check.
- `$A/web/a11y/more-contrast-health.png` shows 90/81/83 and a photo avatar,
  `page-health-0.png` 93/83/84 and no photo: the a11y run was captured at a
  different moment of the data fill, so the two are not a like-for-like
  contrast comparison.

## Not checked / could not verify

Stopped early by the coordinator. Not done:
- Mobile-native checklist for the web beyond the head: viewport has
  `viewport-fit=cover` (`document-head.php:14`); one theme-color meta switched
  by JS per theme (`:15-16, :79-80`, fine for a class-based theme); `button {
  touch-action: manipulation }` (`theme.css:603`). NOT checked: tap highlight,
  ungated `:hover` rules, `100vh` vs `dvh`, input font-size < 16 px (iOS zoom),
  overscroll-behavior, safe-area insets on header/dock/sheets,
  `user-select` on controls.
- break-ui: only limits found — username VARCHAR(30), `[A-Za-z0-9._-]`
  (`includes/friends.php:193`, schema.sql:137: a 30-char unbreakable string),
  first/last name VARCHAR(60) each (schema.sql:157-158). The CSS of the
  account popup, leaderboard rows, friend rows, challenge cards and goal cards
  (min-width:0, overflow-wrap, ellipsis, flex-shrink) was not read; initials
  come from the username ("D" for dev_lisa). All break-ui items remain open.
- Interaction states per component (hover gating, pressed, disabled, loading,
  error, success) beyond press/focus; popup/sheet open/close motion
  (`--panel-in/out`), toasts' timing, wizard steps, wheel picker, pull-sync.
- Screen-reader labels per view beyond a11y.json's `named` flag (all targets
  named); focus order outside Overzicht; focus trapping in popups.
- Android focus indication (hardware keyboard), TalkBack order, Android
  empty/error states (`OwnifyApp.kt` Unreachable seen only).
- Real-device behaviour of 5.3, 5.5, 5.8, 5.9 and 5.16 (needs an iPhone and
  an Android phone; the local server is down).

# Ownify final audit — Phase 2, consolidated findings

This merges the nine read-only reviews (`lens-1` … `lens-9` in this folder), my own gap-fill checks and the corrections
made while merging. Nothing in the app, the database or the server was changed.

- **Evidence root.** `$A` = the Phase 1 set (docs/audit/BRIEF.md), which is in the session scratchpad and not in git.
  Web measurements are CSS px from the metrics JSON. Android measurements are sp/dp from the layout dumps.
- **Full evidence.** Each finding below names its lens IDs (for example `L3.1`). The lens file holds the complete
  file:line list, the measurements and the screenshots. This file gives the deciding evidence in one line.
- **Status.** Kept separate throughout:
  - **VERIFIED**: seen in code, data or a screenshot.
  - **HYPOTHESIS**: likely, but not proven.
  - **PREFERENCE**: a design judgement, not a defect.
- **NEW CONVENTION.** This flag marks a fix that would add or change a rule, style, token or icon. Per CLAUDE.md, the
  owner must approve it first.
- **Priority.**
  - **P1**: wrong, misleading or a real usability or privacy problem. Fix first.
  - **P2**: a visible inconsistency or a clear improvement.
  - **P3**: polish, cleanup or documentation.
- **Both apps.** Unless a finding says "web only" or "Android only", the fix applies to both apps (CLAUDE.md).
- **Desktop is out of scope.** At ≥ 768 px the web is only the phone inside an iPhone frame
  (`components/phone-simulator.php`). This limitation is documented in L5.17 and L7.9. No proposals are made for it.

---

## 0. The owner's question (Part A): photo and real name

| | Web account popup | Android account sheet |
|---|---|---|
| **Photo** | **Shown.** The `phone-*` shots were taken before the upload (12:34–12:56 vs 13:08). `acc-main-withphoto.png` shows it. | **Harness bug, not an app bug.** `$A/android-run.sh` passed `-Downify.server=http://127.0.0.1:8080` with no trailing slash. `OwnifyApi.kt:373` then builds `…8080uploads/…`, which throws `MalformedURLException`. The error is caught at `:392-394`, so the app falls back to the placeholder. Reproduced: with the slash the same URL returns `200 image/png`. Data, state, parsing (`AppData.kt:2904-2913`) and the composable (`AccountPanel.kt:197`) are all correct. |
| **Real name** | **Not shown, by design.** `components/account-modal.php:6-7` says "Deliberately not a profile page". | **Not shown, by design**, the same as the web. `Auth.parse` doesn't even read `first_name`/`last_name`. |
| **Where the real name is shown** | Instellingen › Account hero (`pages/settings-detail.php:85-87`) | The same hero (`SettingsDetail.kt:154-161`) |

**Classification:**

- Android photo: **implementation of the test harness** (not data, not sync, not the app).
- Real name on both apps: **design**.

If the name should appear in the popup, add it as an `account__meta` line on both apps. That uses an existing style, but
it reverses a written decision, so it is the owner's call (L6 Part A).

**Harness fix (test-only, P1 for the audit):**

- re-capture with `…8080/`;
- add the trailing-slash rule to `OwnifyAndroid/README.md:276`, or let `OwnifyApi` add the slash itself (`send()` at
  `:469` joins URLs the same way).

---

## 1. Corrections and evidence artefacts (do not count these as app defects)

| Claim | Verdict | Evidence |
|---|---|---|
| Early observation 7: many touch targets are under 44 px | **Mostly false.** | `::after` extends the hit areas to `--hit-min` 44 (range switch 75×34 → 75×44, AI handle 128×40 → 128×44). Gezondheidsscore and Primair doel make the whole card the target (`.card__open::after { inset:0 }`). Android `HitArea` is 48 dp (L5.12). |
| Lens 8: the avatar files are committed to git | **False.** | Only `uploads/.htaccess` and `.gitkeep` are tracked (`git ls-files uploads`). |
| Lens 9.6: `g7` is not migrated | **True, with nuance.** | Re-checked: `g7` has 44 tables. It has 030 and 031 (`challenges.metric varchar(32)`) and the 025 backup tables, but none of the 027–029 tables. `docs/FINAL-AUDIT.md:52` ("both migrated to 031") is wrong for `g7`. Migrations were applied out of order and nothing noticed. |
| Android shows no photo anywhere (header, identity card, hero, podium, board, challenge faces) | **Harness.** | See §0. |
| Android "1 verbonden" and a device listed vs web "Geen verbonden" | **Harness.** | `state-fetch.php` signs in a temporary device, and the server then counts Health Connect as connected (PARITY.md:237) (L6.1). |
| Android goal pages `app-goal-300…303` show the Doelen page | **Harness.** | `ScreenshotCapture.kt:496-499` hard-codes demo ids 300–303. dev_jij's goal is id 1. **There is no Android evidence for goal detail.** |
| Web Overzicht ring pale, pillar rings empty | **Capture timing.** | `cap.js` waits 500 ms; the ring travels for 2.5 s (L6.1, L5.9). |
| Web lines that stop one segment short (SpO₂, HRV…) | **Capture timing.** | Shots are taken 350 ms after a scroll, but the line draws on for 900 ms. The data has all seven days (L4). |
| Score 86 in `pop-*` vs 87 elsewhere; `more-contrast` 90/81/83 vs 93/83/84 | **Capture moment.** | The data was still being filled. Not a like-for-like comparison. |
| Tijd in bed empty in 113 of 114 nights; `daily_scores` has 2 days; no "Totaal" kcal bars; goal 1 total from a single point | **Fixture.** | `fill.php` doesn't write `time_in_bed_minutes` or `total_energy`, and there was no score backfill. Score-history shapes over 30/90/365 days **could not be judged** (L4 DATA-SUSPECT). |
| "DEV â€" 300.000 stappen" mojibake | **Fixture.** | Double-encoded seed text, the same case as PARITY.md:288. |
| Thema: Android "Systeem" vs web "Donker" | **Documented.** | PARITY.md:244. |
| Early observation 6: no "Wachtwoord vergeten?" locally | **Harness.** | Mail is not configured locally. The live shots are the reference for the login. |

**Consequence:** before Phase 4 verification, re-capture both apps from one state at one moment, with:

- the server URL ending in `/`;
- no device token left in the state;
- real goal ids;
- the web captured with reduced motion;
- a fixture that fills `time_in_bed_minutes`, `total_energy` and a score backfill.

This is C-E1 below.

---

## 2. Findings by shared pattern

Each group names the **canonical implementation**: the existing part of the approved system that the others should
converge on.

### G1 · Hierarchy of titles and the one action a step leads to

Canonical:

- the dialog title is **Headline 17/600** (THEME: "a dialog's name");
- the one forward action is **`.btn--primary` / `BtnLook.Primary`**, with a **Body 15/600** label (as `.glass--cta` /
  `GlassCta` already have);
- the card head is one size.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C1 | **Login and register: no clear order.** On live, "Inloggen" is 15/600. The underlined "Wachtwoord vergeten?" is 13/600 in primary ink, only 2 px smaller. The submit button is a neutral `.btn` at 13/600, so the action is the weakest element. On the forgot step, the inner title (17/600) is larger than the panel title (15/600). | P1 | VERIFIED | L1.1 |
| C2 | **Account popup and signed-out steps have no green primary.** *Inloggen*, *Account aanmaken*, *Stuur link*, *Ik heb bevestigd*, *Wachtwoord opslaan* and Doelen's *Doel aanmaken* are all neutral `.btn`. `.btn--ghost` is used 6× and **defined nowhere** (grep: 0 rules), so *Uitloggen* looks exactly like *Opslaan*. | P1 | VERIFIED | L3.5 |
| C3 | **Dialog titles in three sizes for one role.** Account, Vrienden and both wizards are 15/600 (`.card__eyebrow`, `PanelHead`). The code sheet is 17/600 (`SheetHead`). AI consent is 22/600. In the 15/600 panels the first content line is larger than the panel title. | P1 | VERIFIED | L1.2 |
| C4 | **Card heads in two sizes for one anatomy.** Overzicht, Gezondheid and the details are 15/600; Community is 17/600. At 15/600 the head equals its item titles, so the hierarchy is flat. On Slaap, "Slaapverloop" (15/600) is outranked by its own date "11 nov" (15/700). THEME.md calls card titles Title 22 and eyebrows Caption caps, which the code doesn't match. The Overzicht goal card shows the same inversion. | P2 | VERIFIED | L1.3, L1.12 |
| C5 | **Primary actions in two label sizes** (13 and 15), and quiet destructive links at 11/600. | P2 | VERIFIED | L1.4 |

Decision needed (owner):

- One panel-title size: Headline 17, or a documented 15/600 "panel name" with the code sheet changed to match.
- One card-head size: Headline 17 everywhere, or 15/600 everywhere with Community changed.
- Button label sizes: not written in THEME.md today, so ask first.
- The web has no quiet recovery link class. Android has `QuietLink` (Subhead, muted, underlined). Accepting it as the
  shared definition is a **NEW CONVENTION** on the web.

### G2 · Destructive actions

Canonical:

- the trigger is the neutral `.btn` with the trash icon (as the goal's *Verwijderen*);
- the irreversible "yes" is the gold **`.btn--caution`**;
- there is one in-place confirm container, the attention Note `.goal-confirm`.

The alternative is THEME.md as written today: gold on every step.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C6 | **Five first-step treatments and five confirm containers. Loudness is inverted against the consequence.** *Gegevens wissen* is the loudest (raised gold glass). *Account verwijderen* is the quietest (a muted, underlined 13 px link). *Vriend verwijderen*, *Uitdaging verlaten* and *Ontkoppelen* are 11 px text. *Doel verwijderen* looks identical to *Pauzeren*. Android mirrors this (`BtnLook.Final`, `DeleteLink`). | P1 | VERIFIED | L3.1, L1.4 |
| C7 | **Example 5 (Je gegevens) is confirmed.** *Downloaden* is a flat `.btn` (fill .08, no shadow) at 113×36. *Wissen* is the floating gold glass at 79×36 (shadow 0 8 20 .26, backdrop blur). The row labels use `--text-secondary` (.74), the ink of the disabled switches above, so working actions look disabled. The buttons are 36 tall on web (`settings.css:1063`; confirm 38) and 44 on Android. | P1 | VERIFIED | L3.2 |
| C8 | **Disabled opacity** varies from .45 to .7. **`.btn` heights** are 44, 36, 38 and 42. | P3 | VERIFIED | L3.14 |

Decision needed: neutral trigger + gold confirm (recommended), or gold everywhere. Either is a wording change to
THEME.md's *cautious* row. Whichever is chosen, *Account verwijderen* must not be quieter than *Gegevens wissen*.

### G3 · Account and profile surfaces

Canonical:

- the settings row pattern (label Body, value Subhead secondary, 16 px chevron);
- the red notice dot for "this waits for you";
- `.status` (dot and word) for "what is the case";
- one home per edit.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C9 | **Example 1, the photo field.** A pill inside a pill, in three type sizes (11/13/13). The field is 53 tall beside a 44 button, and its columns sit 8 px out of line with the username row. "Geen bestand gekozen" gets 68 px and is clamped to "Geen bestand…". *Uploaden* is always enabled: web posts an empty file (`novalidate`), and Android sends `ByteArray(0)` on purpose. | P1 | VERIFIED | L3.3, L1.7, L6.7 |
| C10 | **Example 2, the Vrienden row.** It is the only flat Row among pill fields. Its eyebrow "VRIENDEN" repeats "2 vrienden". The requests badge is a one-off green pill, not a chip and not the notice dot. Its count is built without `tn()` (`account-modal.php:169,171`). "Verzoek verstuurd" (`.friend__done`) is a state drawn like a 42 px button. | P2 | VERIFIED | L3.4 |
| C11 | **Example 3, the profile header** (Instellingen › Account `.settings-hero`). Its photo, username and real name are all repeated about 100 px lower in the Profiel rows. The "thick elements" are **HYPOTHESIS**: most likely the empty card face either side of the centred content (362×185, content 64 px wide). I could not reproduce them in any capture, so **I need the owner's screenshot**. | P2 | VERIFIED (repetition) / HYPOTHESIS (the element) | L3.6 |
| C12 | **Two homes for username and photo, and two *Uitloggen*.** The Profielfoto and Gebruikersnaam rows open the popup (`config/settings.php:172-175`), while every other row opens the inline editor. *Uitloggen* is in the popup (no icon) and in Instellingen (with an icon). | P2 | VERIFIED | L3.9, L3.5 |
| C13 | **Privacy "Later instelbaar".** *Profiel vindbaar* and *Meedoen aan ranglijsten* are disabled switches drawn **on**. Findability already works through friend search. *Vriendverzoeken toestaan* lives in the popup, and *Meedoen* is two sections away from *Profielfoto op de ranglijst*. | P2 | VERIFIED | L3.8 |
| C14 | **The Vrienden switch label** "Vriendverzoeken toestaan" is `--text-secondary` on both apps; every Instellingen row label is primary. | P3 | VERIFIED | L1.12 |

Decision needed: what the popup is for (a summary of identity, Vrienden › and Uitloggen; or the editor) and which single
home each edit gets. For the photo: (A) the avatar itself is the control, picking uploads straight away and the field
goes; or (B) a "Foto kiezen" `.btn` with the file name as a hint line and *Uploaden* disabled until a file is picked.

### G4 · Copy, terminology and i18n

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C15 | **Example 4, the OwnifyAI block in Privacy.** It names Gemini four times and states the consent twice (lede and switch). A usage tip ("veeg omhoog") sits in a privacy section. Account › Lichaamsmaten says the same retention fact twice. This is consent text, so **the owner words it**. | P2 | VERIFIED / PREFERENCE (wording) | L3.7 |
| C16 | **One concept, three names.** Movement is "Activiteit" in `config/goals.php:62`, `config/challenges.php:225`, `pages/manual-entry.php:58` and `config/settings.php:570,578`. It is "Beweging" in `pages/dashboard.php:189`, `pages/health.php:55` and `pages/insights.php:194,300`, plus the unused `'move' => 'Beweging'` at `config/challenges.php:351`. It is "Training" for the category. | P2 | VERIFIED (grep) | gap-fill |
| C17 | **"dagscore"** still appears in `components/scroll-top.php:9` and `OverviewPage.kt:663`, while the product calls it Gezondheidsscore. | P3 | VERIFIED | gap-fill |
| C18 | **The research back pill says "Terug"** (`pages/research-detail.php:44-46`), while other details name their parent ("‹ Gezondheid", "Terug naar Uitdagingen"). | P3 | VERIFIED | gap-fill |
| C19 | **Hard-coded Dutch.** `account-modal.php:85` prints `'Account'` without `t()`, and `:169,171` build "vriend(en)" and "verzoek(en)" without `tn()`. Android uses `tr`/`trn`. | P2 | VERIFIED | L3.4, L6 Part C |
| C20 | **A sentence in caps** ("NOG NIEMAND GEKOZEN — JE KUNT OOK STRAKS DE CODE DELEN.", `challenges.js:827`, `ChallengeSheets.kt:630`). Licenties also has long caps eyebrows, and the calendar weekday heads are the only caps at weight 400. | P3 | VERIFIED | L1.8 |
| C21 | **The challenge wizard rows repeat a truncated "· Uit jullie gekoppelde bronnen"** (`config/challenges.php:353`, `$A/web/pop-phone-dark/chwiz-1.png`) on every metric row. The text adds no information per row. | P2 | VERIFIED | gap-fill |

Not done: the full term list (Gezondheidsscore, score, Trainingen, Workouts, OwnifyAI wording, capitalisation), and a
check that `lang-en.json` is generated from `lang/en` (L6 Part C). This goes to Phase 3.

### G5 · Empty states and first-run flow

Canonical: one empty card component, with copy that tells the truth about the account and offers a way forward.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C22 | **Doelen says "Nog geen doelen / Eerste doel instellen" to someone who has a goal.** dev_jij's goal 1 is `status=ended` and sits under Afgerond (`pages/goals.php:20`, `GoalsPage.kt:153`). The same pattern appears on Community, where "1 uitnodiging" sits above "Nog geen uitdagingen". | P1 | VERIFIED | L5.1 |
| C23 | **The Overzicht goal card** says "Nog geen doel ingesteld". Its *Doel instellen* only navigates to Doelen, with a helper note that exists to explain the extra step. The card and the button are two keyboard stops with one destination. | P2 | VERIFIED | L5.2 |
| C24 | **A new account gets three different explanations** and no control to connect a source. The lock icon reads as "forbidden". Gezondheid promises a countdown that won't run without a source. Doelen carries a data disclaimer for goals. The empty Verloop line overlaps the "50" label. | P2 | VERIFIED | L5.11 |

### G6 · Navigation

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C25 | **Web: system back and the iOS edge swipe leave the app** instead of closing a detail, popup or sheet. There is no `pushState`/`popstate` anywhere; Android handles back. Web only. **NEW CONVENTION** (the web's navigation model). | P1 | VERIFIED (code) | L5.3 |
| C26 | **Android: back on a non-start tab exits the app** instead of returning to Overzicht (`ShellState.kt:384-393`). Android only. | P2 | VERIFIED (code) | L5.4 |
| C27 | **The "double ring" on the back pill (observation 4)** is the global `:focus-visible` outline on the pill that `detail-layer.js:134-139` focuses on every open. In the harness it showed because the open was a scripted click. On a real tap it most likely doesn't show in Chrome; iOS is unknown. | P2 | VERIFIED (cause) / HYPOTHESIS (devices) | L5.5 |

### G7 · Focus, contrast and assistive semantics

Canonical: the existing outline token (`rgba(var(--ink),.55)`, 2 px, offset 3 px) as the single focus indicator.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C28 | **Two focus languages.** On every `.glass` control the green halo measures 1.41:1 (Dark) and 1.29:1 (White) against the ground; WCAG 1.4.11 asks 3:1. The pills' white outline is 5.97:1. The global rule also sets `border-radius` on whatever is focused. | P2 | VERIFIED (computed) | L5.6 |
| C29 | **Dark muted-text contrast is not guarded.** THEME.md documents Dark `--text-muted` at 4.2:1, but `theme-test.php` enforces 4.5 only in White. The real cards measured 5.7–6.3:1 and pass. Glass chips, the range-switch track and thick panels were not sampled. | P2 | VERIFIED (gap) / HYPOTHESIS (a failing surface) | L1.11 |
| C30 | **"Animaties uit" switched on in-session doesn't reach the JS motion** until a reload: `nav.reduceMotion` is read once (`navigation-core.js:56`). The **Android chart draw-ons ignore it entirely**: they check only `LocalStillMotion`, not `LocalReducedMotion`. | P2 | VERIFIED (code) | L5.8 |
| C31 | **A focusable span with no role** ("Nog 8 dagen", `insight-progress.php:21`) is a tab stop of its own, with no ring. | P3 | VERIFIED | L5.7 |
| C32 | **The AI grip** is 1.77:1 (Dark) and 1.45:1 (White). It isn't a token, so More contrast leaves it alone. | P3 | VERIFIED | L5.13 |
| C33 | **Segmented controls are announced as toggle buttons** (`aria-pressed`, Android `Role.Button`). They should be tabs (Actief/Afgerond) or a radiogroup (periods). | P3 | VERIFIED | L5.15 |

### G8 · Motion (apple-design: purpose and frequency, velocity, interruptibility)

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C34 | **The Overzicht arrival is about 3.5 s for the ring and about 5.2 s until the pillars are drawn,** on every load of the most-seen screen. It can't be skipped (`hero-ring.js:19-22`, `HeroRing.kt:37`). Owner's call on a branded moment: shorten it, start the pillars with the ring, or play it once a day. | P2 | VERIFIED / PREFERENCE (fix) | L5.9 |
| C35 | **The rail and detail settle on a fixed 280 ms curve,** while the sheet uses a velocity spring. Both are interruptible (good). Needs a device feel-check. | P3 | PREFERENCE | L5.16 |
| C36 | **The swipe-up hint repeats every 6–8 s forever** for someone who never opens the AI. This is a documented decision. | P3 | PREFERENCE | L5.14 |
| C37 | **Dots appear before the line draws to them** (900 ms draw-on). | P3 | PREFERENCE | L4.15 |

`review-animations` (user-only skill) has not run. A full motion review of the sheets, toasts, wizard steps, wheel and
pull-sync is still open (L5 Not checked).

### G9 · Spacing and alignment

Canonical:

- `components.css:16-23`: full-width cards pad `--space-5` (24), two-up cards `--space-4`;
- `.stack` 16 between blocks;
- `--page-intro-top` / the page-intro inset for every page title.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C38 | **"Vandaag" is not at the page-title position.** It sits at x 17 / y 98, against x 24 / y 93 on every other page, so the home title jumps 7 px sideways and 5 px down on every swipe. `theme.css:529-531` says this must not happen. | P2 | VERIFIED | L2.1 |
| C39 | **Full-width cards pad 16 instead of 24** on Community (`.cm-dest`), Uitdagingen (`.ch-card2`) and a lone mini chart. The text edge moves from x 41 to x 33 between tabs. | P2 | VERIFIED | L2.2 |
| C40 | **`.settings-rows` cards are 13 px at the top and 25–26 px at the bottom.** They also draw a second hairline style and 47 px rows against 56 px rows beside them. | P2 | VERIFIED | L2.4 |
| C41 | **Mini area-chart cards are 17 / 30.** Chart cards without a hint end in an empty 45 px band (25 with a hint). They keep 2.5 em for a second axis row they no longer have. | P2 | VERIFIED (metrics) / HYPOTHESIS (cause) | L2.5, L4.11 |
| C42 | **Card lists use three gaps:** 16, 12 and 8 (Uitdagingen). | P3 | VERIFIED / PREFERENCE (value) | L2.3 |
| C43 | **A text link as a card's last element** adds about 12 px of visual bottom padding. Only `.calibration__open` corrects for it. | P3 | VERIFIED / PREFERENCE | L2.6 |
| C44 | **Two small gaps:** Doelen intro → switch is 7 px (15–16 elsewhere); a label above a card is 8 px, or 12 px with a lede. | P3 | VERIFIED / PREFERENCE | L2.7, L2.8 |
| C45 | **The floating "+" and the AI grip over content.** At the end of the scroll every page clears both (28 px). They overlap only at rest and mid-scroll, for example the + over SpO₂'s "Di Wo". This is normal floating glass. The handle over scrolling text was seen in my composites too. | P3 | VERIFIED | L2.9, L4.13 |

Not measured: spacing on Android, 320 px, the popups and the off-scale px/dp grep (L2 Not checked). These go to the
Phase 3 prototype pass.

### G10 · Inputs and icons

Canonical:

- the **pill field** (`wizard__input` look) for every wheel-backed field;
- the two icon-tile sizes;
- one meaning per icon.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C46 | **Icon meaning conflicts.** `moon` is both Slaap and Thema. `chart` is the Doelen tab and also heads every Verloop on other tabs. `pulse` has four meanings (sleep insights, heart rate, Scorekompas, active minutes). `info` is used **for errors** (pull-sync, integration error). `lock` stands for empty, private and locked. `copy` is also Licenties. `chevron-down` means both close and select in one sheet. The two apps match exactly (OwnifyIcons is generated from `icons.php`). | P1 (moon, chart, pulse, info-as-error) / P2 (rest) | VERIFIED | L3.12 |
| C47 | **Wheel-backed fields drawn two ways on one form.** In *Training invoeren*, Activiteit is floating glass and the other four are pill fields. A date is a pill field in one place and a radius-14 glass button in the wizards. The pill field recipe is written five times, and `.ch-input::placeholder` drifts to `--text-faint`. | P2 | VERIFIED | L3.10 |
| C48 | **"Vriend toevoegen" has four treatments and two icons** (`plus` vs `user-plus`). | P2 | VERIFIED | L3.11 |
| C49 | **Icons are drawn at 11 sizes,** and identical 36 and 30 tiles hold 15–18 px glyphs. A glyph-size token per tile would be a **NEW CONVENTION**. | P3 | VERIFIED | L3.13 |
| C50 | **Five avatar sizes** (68/52/48/40/34). | P3 | VERIFIED | L3.14 |

New icons (an alert glyph, a non-category Thema icon) are a **NEW CONVENTION**. Rearranging existing icons is not.

### G11 · Text fitting, numbers and the type system

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C51 | **Bad wraps at 393 px.** Community's "Nieuwe uitdaging" and "Code invoeren" break to 2 lines (on Android only one does). "Tijd in bed + Regelmaat" wraps, so its value sits 17 px below its neighbour's. The challenge name beside "SAMEN" wraps. | P2 | VERIFIED | L1.7 |
| C52 | **At 320 px:** the "Nog onduidelijk" chip wraps; the challenge name in the Community fan runs to 4 lines; `ch-card2__name` and "Spierversterkend bewegen" wrap. Already at 393 px, **the devices popup cuts each provider's description to one line** with no way to read the rest (`.devices__source` nowrap + ellipsis, `assets/css/devices.css:91-100`; `$A/web/pop-phone-dark/devices.png`). | P2 | VERIFIED | gap-fill |
| C53 | **Units are treated three ways:** muted Subhead ("u", "/100"), the value's own size ("ms", "%"), and a Caption line ("van 1,8 L"). | P3 | VERIFIED / PREFERENCE | L1.9 |
| C54 | **Weights and leading outside the step table.** Title at 700 (hero labels, long challenge name); Caption at 400 (about 5,100 instances: axes, counters); title rules inheriting body leading 1.45 where Android uses 1.2/1.25. | P3 | VERIFIED | L1.10 |
| C55 | **Two small hierarchy points.** On Slaap, "Bekijk onderzoek" sits between the chart and its hint. The wizard step counter is 11/400, the faintest line in the panel. | P3 | VERIFIED / PREFERENCE | L1.12 |

break-ui (map and worst-case reasoning) found the rows themselves well protected:

- `board-row__name` and `friend__name` truncate with an ellipsis;
- the grids use `minmax(0,1fr)` and `min-width:0`;
- schema limits: username VARCHAR(30), `[A-Za-z0-9._-]`, so up to 30 unbreakable characters; first and last name
  VARCHAR(60) each.

The worst-case toggle itself needs a code or fixture change, so it moves to Phase 4.

### G12 · Charts over time

All charts follow `docs/CHARTS.md` / `lib/time-axis.php` (the binding standard). The dataviz palette validator was run;
it was not eyeballed.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C56 | **End dates collide with their neighbours.** On 1 jaar: "novdec", "oktnov", gaps of 1–2 px. On 90 dagen: "11 18". On the Android heart-rate day: "00:0003:00". `chart-axis.js:33-51` and `HistoryChart.kt:524-536` push an end tick inwards but never check the label beside it. | P1 | VERIFIED (Android, web 1 jaar) / HYPOTHESIS (web 90 d) | L4.1 |
| C57 | **The heart-rate day: web shows a time every 6 h, Android every 3 h (and they overlap).** CHARTS.md says every 3. | P1 | VERIFIED | L4.2 |
| C58 | **The line key in a small card's head reads as a minus sign or a range** ("— 93", "■ 9.956 – 7,5 km"). A line series is a dash in the head, a dot in the legend and a stroke in the reading. | P1 | VERIFIED | L4.3 |
| C59 | **The reading says "Vandaag"** on the Verloop and the Scorekompas, against CHARTS.md "never Vandaag"; the area charts say "11 november". On Android the reading's glass covers the period switch and its text shows through. | P1 (label) / P2 (overlap) | VERIFIED | L4.4 |
| C60 | **An empty chart shows next week** (today + 6 days) beside a neighbour showing the past week. CHARTS.md doesn't cover the no-history case yet: one line in the standard, a **NEW CONVENTION**. | P2 | VERIFIED | L4.5 |
| C61 | **Four marker policies, three dot sizes (8/7/5) and three horizontal insets** (0 %, 3 %, 0.3 slot) for the same 7-day axis. | P2 | VERIFIED | L4.6 |
| C62 | **Overlapping dots hide a category** (Voeding 83 under Training 84). | P2 | VERIFIED | L4.7 |
| C63 | **A goal that ended in April is drawn over a year,** apr → apr (`goal-chart.php:291` fits the window to today). | P2 | VERIFIED | L4.8 |
| C64 | **Two hidden y-scales in one plot** ("Stappen + Afstand", "Tijd in bed + Regelmaat"): the dataviz anti-pattern #1. **NEW CONVENTION** to change it. | P2 | VERIFIED / PREFERENCE (fix) | L4.9 |
| C65 | **The palette fails the CVD and contrast checks.** Voeding ↔ Training ΔE 5.3 (protan), below the floor. In Dark, the Slaap and Voeding lines are 2.7–2.8:1 against the card; the mixed-chart bars are 1.8–2.3:1. The heart zones fail as an ordered ramp. Brand colours stay; the proposal is a secondary encoding (direct labels, a **NEW CONVENTION**) and `--bar` at about 75 % (a token change). | P2 | VERIFIED (validator) | L4.10 |
| C66 | **Measurement scales magnify noise** (SpO₂ 96–98 fills the plot). A minimum span per measure would be a **NEW CONVENTION**. | P3 | VERIFIED / PREFERENCE | L4.12 |
| C67 | **Smaller chart points.** A legend item with no data stays unmuted. The y-range of a single score hides the 100 label. Only the goal chart has a wash. Trainingen bars have no headroom. A quiet heart-rate day leaves 60 % of the plot empty. | P3 | VERIFIED / PREFERENCE | L4.14, L4.15 |

Proposed convention table by data type: L4 "Shared conventions by data type". The owner should approve it as one
decision. Not covered: White Mode charts, 320 px, English/imperial axes, Voeding charts, insight charts, the training
session's irregular ticks, Slaap's stacked night bars, keyboard and TalkBack readings (L4 Not checked).

### G13 · Web ↔ Android parity

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C68 | **The same text in a different weight or size, in 7 places.** Pillar values 700 vs 600; nutrition grams; session duration 13 vs 11; wizard option meta 13 vs 11; "niet gelogd"; the neutral verdict bubble; "Uitdaging verlaten" 11 vs 13. None of these is in PARITY.md. | P2 | VERIFIED | L1.5 |
| C69 | **Wizard choice rows use two label sizes** (13/600 vs 15/600) in consecutive steps. Android's meta is 11. | P2 | VERIFIED | L1.6 |
| C70 | **Android's Overzicht "Primair doel" card has no chevron** (`OverviewPage.kt:321`). Android only. | P2 | VERIFIED | L6.2 |
| C71 | **The insight tier label is 13 px on the web and 11 sp on Android** (`OverviewPage.kt:561`). Android only. | P2 | VERIFIED | L6.3 |
| C72 | **An insight bar at 0 %** is dashed on the web and solid on Android (`Components.kt:562`). Android only. | P3 | VERIFIED | L6.4 |
| C73 | **PARITY.md is stale** on the pillars: it still says capsules and a dot, but both apps draw rings. It is missing C70–C72 and the harness's trailing-slash rule. | P3 | VERIFIED | L6.5 |
| C74 | **Server state Android never parses:** top-level `patterns` (273 B), `leaderboard` (370 B) and `disclaimers` (229 B) are in the state but absent from `AppData.kt` (grep: 0 hits). This is either dead payload or a missing Android feature; to be decided in Phase 3. | P3 | VERIFIED (grep) / HYPOTHESIS (intent) | gap-fill |
| C75 | **After a saved setting the web reloads the page and confirms nothing;** Android shows the server's message as a toast. | P2 | VERIFIED (code) | L5.10 |

Wording: 37 screen pairs were compared text by text, in both themes. Apart from the artefacts in §1, the copy is
identical (L6.6).

### G14 · Performance

Measure before changing anything (Phase 5). Every caching proposal must return identical results.

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C76 | **The score engine runs about 5× per request.** Global counters suggest **≈ 500–900 SELECTs per signed-in render**. The fix is to compute once and pass `$today` (the parameter exists), plus a request memo. | P1 | MEASURED (indirect) | L7.1 |
| C77 | **Uncached repeated lookups:** `health_metric_types` by code in 4 helpers; `user_account` (3 queries) about 6× per request; `research_claim_by_id`. | P1 | VERIFIED (code) | L7.2 |
| C78 | **`goal_user_auto_codes` runs on every view:** 26 measurements × 90 days, about 60–100 queries. | P1 | THEORETICAL | L7.3 |
| C79 | **One huge document.** 5 rail pages and 88 details are all laid out on load; closed details are only translated off-screen, and there's no `content-visibility`. Estimated ≥ 0.6–1.5 MB of HTML. `content-visibility` on closed details would be a **NEW CONVENTION**, and four measuring scripts would need checking. | P1 | THEORETICAL / VERIFIED (CSS) | L7.8 |
| C80 | **The fingerprint** reads every reading in the range on each call; it scales with Health Connect interval density. | P2 | THEORETICAL | L7.4 |
| C81 | **N+1 patterns** in challenge standings, inviters, usernames and research details (29 × 3). | P2 | THEORETICAL | L7.5 |
| C82 | **Indexes.** There is no `(user_id, metric_type_id, recorded_on)` index, so the optimizer picks `fk_hm_type` plus temp tables and filesort. `DATE(col) BETWEEN` causes full scans on `user_measurements` and `workouts`. | P2 | MEASURED (EXPLAIN, tiny tables) | L7.6 |
| C83 | **Assets.** 17 render-blocking stylesheets (86 KB gz) and 33 scripts (137 KB gz), unminified; no compression or cache config in the repo. The welcome logo is a 141 KB lossless WebP; a 44 KB PNG is shown at 30–64 px. | P2 | MEASURED | L7.10 |
| C84 | **The Android state is 486 KB** (95 KB gzipped), re-read on every `ON_RESUME` and after 8 kinds of write. Server compression is contract-neutral. A refresh interval is the owner's decision. | P2 | MEASURED | L7.11 |
| C85 | **JS:** five document-wide MutationObservers (two not batched), and `chart-axis.js settle()` alternates writes and reads (layout thrash). | P2 | VERIFIED (code) | L7.12 |
| C86 | **Render-time writes on every GET** (score store, goal refresh, insights day claim, last seen). | P3 | VERIFIED (code) | L7.7 |
| C87 | **Smaller performance risks:** the phone simulator renders twice at ≥ 768 px; about 6 backdrop-filter surfaces per chart page; long Android pages are a `verticalScroll` Column. | P3 | VERIFIED / HYPOTHESIS | L7.9, L7.13, L7.14 |

### G15 · Dead code and leftovers (Phase 6)

Verdicts: **SAFE-TO-REMOVE** (no caller, verified) / **TEST-ONLY** (keep) / **UNCERTAIN** (the owner or the live access
log decides).

| ID | Item | Verdict | P | Lenses |
|---|---|---|---|---|
| C88 | `api/friends/block.php` and `api/health/nutrition.php`: no caller in the whole history. With them go `health_record_nutrition`, `user_block_add/remove`, and therefore every write to `user_blocks`. | SAFE-TO-REMOVE after one check of the live access log | P2 | L8.1 |
| C89 | `api/health/sleep.php` and `training.php`: only `tools/ai-test.php` seeds through them. | TEST-ONLY → move the tool to `*-entry.php`, then remove | P2 | L8.1 |
| C90 | `api/integrations/status.php`: documented as "call it at startup", but no Android version ever has. | UNCERTAIN | P3 | L8.2 |
| C91 | 22 PHP functions with no caller, tests included (table in L8.3). Includes `challenge_suggest_target` from 49.1, which was superseded by 49.3–49.5; ask the owner since it is recent. `goals_slot_note` is still named as "the reference" in two comments and one test name. | SAFE-TO-REMOVE | P3 | L8.3, L9.11 |
| C92 | `components/community-badges.php` is never rendered, but its config copy is sent to Android and parsed there, and never shown. | UNCERTAIN ("deliberately not built") | P3 | L8.5 |
| C93 | About 30 CSS selectors match nothing (a whole old challenge-wizard block in `challenges.css:1053-1153`, old goals wizard and notice classes…). `.settings-field.is-derived` is a supported config state, so it is UNCERTAIN. | SAFE-TO-REMOVE | P3 | L8.6, L7.10 |
| C94 | 3 JS functions are never called; 14 window-object members are unused outside their own file. `OwnifyPullSync.state/offset` are UNCERTAIN (the harness may use them). | SAFE-TO-REMOVE | P3 | L8.7 |
| C95 | `sync-test.txt` ("github to laptop test", 22 B) sits in the deployed root. It dates from the import commit c0ac2a1. | SAFE-TO-REMOVE | P3 | L8.8, gap-fill |
| C96 | The `chat` icon is unused on both apps. | SAFE-TO-REMOVE (regenerate OwnifyIcons) | P3 | L3.12 |
| C97 | Android: the template tests and their androidTest dependencies; `ui-tooling` / `-preview` (0 `@Preview`); `keepRules/rules.keep`; `ic_launcher-playstore.png`. The last two are UNCERTAIN. | SAFE-TO-REMOVE (confirm with one build) | P3 | L9.12–9.14 |
| C98 | Docs that describe dead code: `docs/DATABASE.md:837,866,912,913,915,926`, `HEALTH-CONNECT.md:136-150,259`, `README.md:153`. | update with the removals | P3 | L8.9 |

Gap-fill: the docs are accurate about removed features (Apple Health, Gemini insights, VO2 max), and DESKTOP.md is
current.

### G16 · Database and privacy (migration 032; Phase 6 and 7)

| ID | Finding | P | Status | Lenses |
|---|---|---|---|---|
| C99 | **`goals_backup_024` / `goal_progress_backup_024` keep personal data with no FK.** Account deletion never reaches them. In `g7` they hold 15 goals from 4 users and 4 progress rows. This contradicts "Nothing is kept aside". 032 drops them (after a backup), and **the Phase 7 reset must include them**. Live and WAMP are unknown: check with `SHOW TABLES LIKE '%backup_024'`. | P1 | VERIFIED (g7) / HYPOTHESIS (live, WAMP) | L9.1 |
| C100 | **12 leftover Gemini-wording columns.** They need a code change first (`insights_installed()` requires 3 of them; the export reads `phrased_body`); then 032 drops them. `ai_insights_consent` is a personal answer kept for nothing. | P2 | VERIFIED | L9.2 |
| C101 | **`g7` lacks 027–029** but has 030/031 (see §1), and still has `apple_health`. Correct FINAL-AUDIT. Migrating `g7` is the owner's choice. | P2 | VERIFIED | L9.6 |
| C102 | **Write-only columns.** `user_integrations.external_account_id` is personal and never read; `point_rules.is_active`; `point_events.reference_type/_id`. The audit timestamps stay. | P3 | VERIFIED (grep) | L9.7 |
| C103 | **Two redundant indexes** (`workouts.idx_workout_user_time`, `goals.idx_goals_user_status`). 4–5 more are unused, pending EXPLAIN on live-sized data. | P3 | VERIFIED / HYPOTHESIS | L9.3, L9.4 |
| C104 | **Migrations 025, 026 and 031 lack `SET NAMES utf8mb4`;** their column comments are double-encoded. Every migration from 032 should start with it. | P3 | VERIFIED | L9.5 |
| C105 | **Smaller schema items:** the `data_sources 'wearable'` row is never used; `auth_email_tokens.change_id` has no FK; `mail_outbox.claimed_at` may exist on live (HYPOTHESIS). The PRIVACY MODEL list in `schema.sql:32-37` is incomplete. `data_wipe()` keeps `health_import_removed`: document it. | P3 | VERIFIED / HYPOTHESIS | L9.9–9.11 |

**Personal-data map for the Phase 7 reset:** L9 Part C.

- It covers 41 personal tables (CASCADE, or reached through a parent).
- `challenges` survives its creator (SET NULL), so it must be deleted explicitly.
- These tables have no FK to `users` and must be emptied by hand: `auth_attempts`, `mail_outbox`,
  `account_email_conflicts` and the two backup tables.
- The reference tables stay.
- Use DELETE, not TRUNCATE. The deletion order is given there.
- Outside the database: the avatar files on live and WAMP (phpMyAdmin can't reach them; SFTP or the file manager), the
  PHP sessions and the error log.

### G17 · Platform layer (mobile-native checklist, web)

Largely compliant (gap-fill, VERIFIED in CSS):

- `-webkit-tap-highlight-color: transparent` at `theme.css:577`;
- no ungated `:hover` rules;
- `dvh` with a `100vh` fallback;
- inputs at 16 px (no iOS zoom);
- `overscroll-behavior` contained on the scrollers;
- safe-area tokens with `viewport-fit=cover`;
- `touch-action: manipulation` on buttons;
- one theme-color meta, switched by JS per theme.

| ID | Finding | P | Status |
|---|---|---|---|
| C106 | `user-select: none` is missing on controls (buttons, chips, tabs, the pill field's labels). It is set only on a few (`compass.css:408`, `components.css:1417`). A long press on a button can select its label on iOS. | P3 | VERIFIED (CSS) / HYPOTHESIS (on device) |

Nothing here was checked on real hardware (mobile-native rule 5). The device checks of C25, C27, C30, C34 and C35 need
an iPhone and an Android phone.

### G18 · Audit reliability

| ID | Finding | P | Status |
|---|---|---|---|
| C-E1 | **Re-capture both apps from one state at one moment** before Phase 4 verification (§1): server URL with `/`, no device token, real goal ids (and an Android goal-detail shot), web with reduced motion, a fixture with `time_in_bed_minutes`, `total_energy` and a score backfill, and the 30/90/365-day chart views. | P1 | VERIFIED (cause) |

---

## 3. Priority view

**P1, 19 items:**

| Area | Items |
|---|---|
| Hierarchy and actions | C1 login, C2 no primary / `.btn--ghost`, C3 dialog title size |
| Destructive actions | C6 destructive rule, C7 Je gegevens |
| Account | C9 photo field |
| Flows and navigation | C22 Doelen empty state, C25 web back |
| Icons | C46 icon conflicts (moon / chart / pulse / info-as-error) |
| Charts | C56 date collisions, C57 heart-rate hours, C58 line key, C59 "Vandaag" label |
| Performance | C76 score engine ×5, C77 uncached lookups, C78 auto codes, C79 the huge document |
| Privacy and evidence | C99 backup tables, C-E1 re-capture |

**P2:**

| Area | Items |
|---|---|
| Titles and actions | C4, C5 |
| Account and profile | C10–C13 |
| Copy | C15, C16, C19, C21 |
| Empty states | C23, C24 |
| Navigation | C26, C27 |
| Focus and motion | C28–C30, C34 |
| Spacing | C38–C41 |
| Inputs and icons | C46 (rest), C47, C48 |
| Text fitting | C51, C52 |
| Charts | C59 overlap, C60–C65 |
| Parity | C68–C71, C75 |
| Performance | C80–C85 |
| Cleanup and database | C88, C89, C100, C101 |

**P3:** everything else (polish, docs, cleanup).

## 4. Owner decisions needed (Phase 3 asks these before anything is built)

1. **Panel and sheet title size** (C3), and **card head size** (C4): Headline 17 everywhere (recommended; Community
   already does it), or a documented 15/600.
2. **The destructive-action rule** (C6): neutral trigger + gold confirm + one Note container (recommended), or gold on
   every step as THEME.md says today.
3. **Button label sizes** (C5): Body 15/600 for full-width and primary buttons, Subhead 13/600 for inline buttons and
   links.
4. **The account popup's job** and one home per edit (C12); photo option A or B (C9); whether the popup shows the real
   name (§0).
5. **Example 3:** please send a screenshot of the "thick elements" from your device (C11).
6. **The privacy and consent wording** (C15): you word it; I can draft.
7. **Icons** (C46, C49): the re-mapping using existing icons, and whether new glyphs are allowed (an alert glyph, a
   Thema icon). New glyphs are a **NEW CONVENTION**.
8. **Chart conventions** (C56, C60–C66): the per-data-type table in L4, as one decision. It includes one extra line in
   CHARTS.md for the 1-year label thinning and for the no-history window.
9. **Web history and back** (C25): adopt `pushState` per layer (**NEW CONVENTION**, recommended).
10. **The Overzicht arrival** (C34): shorten it, start the pillars with the ring, or play it once a day.
11. **Terminology** (C16): one name for movement. I suggest "Activiteit" for the measure and "Training" for the
    category; "Beweging" goes.
12. **The list gap** (C42) and **unit treatment** (C53): small PREFERENCE picks.
13. **Phase 6 UNCERTAIN items** (C90, C92, C94; `challenge_suggest_target`; the `is-derived` state); whether to migrate
    `g7` (C101).
14. **Android refresh interval on resume** (C84), and server compression and caching config (C83, C84). These are config
    only, but they affect live.

## 5. NEW CONVENTION items (need explicit approval before Phase 4)

| Area | Items |
|---|---|
| Web components | the web quiet link (C1) |
| Rules | the destructive rule wording (C6) |
| Icons | new icons (C46); the glyph-size tokens (C49) |
| Charts | CHARTS.md additions (C56 thinning, C60 empty window); markers, insets and dot policy (C61); dot offset (C62); second-measure display (C64); direct labels and `--bar` strength (C65); per-measure minimum span (C66) |
| Navigation | the web navigation history model (C25) |
| Performance | `content-visibility` on closed details (C79); bundling or minification (C83, a build step; the README says "no build step") |
| Contrast tokens | if a real surface fails: Dark `--text-muted` (C29), the focus halo strength (C28) |

## 6. What is still not checked (and what closes it)

- **Android** spacing, 320 px and English/imperial overall (L1, L2), White Mode for components and charts (L3, L4).
  → The Phase 3 prototypes and the re-capture (C-E1).
- **Real devices:** iOS Safari and Android Chrome for back, focus rings, reduced motion, the arrival, long-press and the
  keyboard. → An owner check, or a later session with a device.
- **Live and WAMP databases:** the backup tables, `mail_outbox.claimed_at`, the `wearable`/`apple_health` references,
  the avatar files. → The pre-reset checks in the Phase 7 scripts (SELECT only, run by the owner in phpMyAdmin).
- **Exact query counts and HTML size per request** → Phase 5, with a PDO counter on a throwaway DB copy.
- **Not reviewed component by component:** the OwnifyAI sheet, the challenge sheet cards, the devices pairing, setup,
  research/legal, toasts, the wheel popup, the slider, tooltips (L3 Not checked). Also the Kotlin dead-declaration scan
  and the full state-key diff (L9).
- **Motion review** with `review-animations` (user-only skill) → Phase 4.
- **Example 3's element** → the owner's screenshot.

## 7. Recommended next steps

1. **Checkpoint 2:** the owner answers §4 and approves or rejects the §5 items.
2. **Phase 3, proposals.** For the decisions that change how things look, build `prototype` comparisons on the real CSS
   and Compose parts. `/anthropic-skills:prototype` can only be started by the user. The groups: G1+G2 (titles, actions,
   destructive), G3 (account), G12 (charts), G10 (icons). Each proposal is written as a self-contained plan per group,
   with web + Android, and both themes and both languages checked.
3. **Phase 4, implementation,** in this order:
   1. C-E1 (the harness);
   2. the P1 groups G1/G2/G3/G5/G6/G12;
   3. the P2 groups;
   4. one version bump per shipped commit;
   5. `review-animations` (user-started) over the motion changes.
4. **Phases 5–7:** C76–C87 measured first; migration 032 (C99–C105) after the code changes; the reset scripts per
   database from the L9 Part C map, tested locally first.

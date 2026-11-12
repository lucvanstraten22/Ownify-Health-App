# Ownify — final audit: plan and progress

Working document for the final audit (requested after 49.5). It holds the
plan, what each phase found and where it stopped, so a phase can be resumed
without redoing earlier work. Not shipped: `docs/` is stripped from the
deploy. Committed only on the branch `final-audit-progress` (Checkpoint 0).

## Ground rules for this audit (from the request, in force throughout)

- No commit, push, merge or deploy without the user's explicit approval.
- Visual changes, design-system changes and new interaction patterns:
  proposal first, approval before building. No new styles, tokens,
  variants, components or fonts without approval (CLAUDE.md).
- Allowed without asking: clear low-risk technical fixes, measured safe
  optimizations, removal of confidently unused code/DB structures, and the
  authorised user-data reset (after the target is verified).
- Never change health calculations, aggregation, auth, sync or API contracts
  as part of the audit; suspected data problems are documented separately.
- Never claim something was tested that was not.
- Standing security rules from earlier sessions still apply (no secrets in
  logs or commits, no `*.local.php`, no TLS tricks, no real e-mails to users,
  no new signing key, no Google Play, no paid services).
- Skills: `apple-design` (highest authority), `mobile-native`, `break-ui`,
  `improve-animations`, `animation-vocabulary` are available.
  **`prototype` and `review-animations` are not available in this
  environment**; their work is done by hand and said so.

## Phase 0 — reconnaissance (done)

State at the start: `main` clean at 0d646d4 (49.5), in step with origin.

| Area | Size |
|---|---|
| pages/ | 22 pages (overview, health + detail, area chart, score compass, goals + detail, community, leaderboard, challenges + detail, insights + detail, research detail, ai, manual entry, training session, settings + detail, legal detail, setup, welcome) |
| components/ | 60 files, ~5.1k lines |
| assets/js · assets/css | 35 files ~13.7k lines (644 KB) · 20 files ~13.9k lines (460 KB) |
| api/ · includes/ · lib/ · config/ | 75 · 68 · 21 · 36 files (~62k lines PHP) |
| database | 55 tables in schema.sql, migrations 001–031 (+ rollbacks) |
| Android | 89 Kotlin files (~38k lines), 51 test files (Robolectric + native-graphics screenshots via `ScreenshotCapture.kt`) |
| tools/ | 67 files, 47 test scripts |
| docs/ | 32 documents |

Environments:

- **Live**: ownify.acits.nl on Hestia, deployed by `.github/workflows/deploy.yml`
  (SFTP only, no shell; `database/`, `docs/`, the Android project stripped).
  A precedent exists for running something on the server: `.github/scripts/mail-test.sh`
  uploads a one-time, token-guarded, self-deleting PHP file and calls it once.
- **WAMP**: a self-hosted Windows runner mirrors `main` into
  `C:\wamp64\www\Ownify` (`update-wamp.yml`), with its own database.
- **This sandbox**: no access to either database. Local MariaDB with two dev
  databases (`g7`, `ownify_e2e`; correction from Phase 2: only `ownify_e2e`
  is fully migrated to 031 — `g7` has 030/031 but none of 027–029), a PHP dev server
  harness with fake Google/Polar (`scratchpad/up.sh`, 127.0.0.1:8080),
  Chromium + Playwright for the web, Gradle/Robolectric for Android
  (no emulator; screens can be rendered to PNG through Robolectric's native
  graphics). Gradle runs must never overlap; a full Android test run is long.

Stray item noticed already: `sync-test.txt` in the repository root
("github to laptop test") — a leftover of the WAMP sync test; it is deployed
to the live web root. Candidate for removal in Phase 6.

## Phase plan

Usage: low / medium / high is relative usage of the weekly limit.

| # | Phase | How | Effort · Ultracode | Usage | Ends with |
|---|---|---|---|---|---|
| 0 | Reconnaissance and plan | one agent | High · OFF | low | **Checkpoint 0** (this one) |
| 1 | Live inspection: run both apps, capture the evidence set | one agent, sequential (one dev server, Gradle cannot run twice) | High · OFF | medium | Checkpoint 1 |
| 2 | Parallel audit over the evidence + code | 8–9 read-only agents, one per lens, then consolidation | XHigh · **ON** | high | Checkpoint 2 |
| 3 | Coherent plan + design proposals (before/after) | one agent | Max · OFF | medium | **Checkpoint 3 — your approvals** |
| 4 | Build approved changes + low-risk fixes, both apps | one agent, sequential | Max · OFF | high | Checkpoint 4 |
| 5 | Performance: baseline, safe optimizations, re-measure | one agent | High · OFF | medium | Checkpoint 5 |
| 6 | Cleanup: dead code, assets, DB structures (migration 032) | one agent; one parallel cross-check of "unused" claims is worth it | Max · OFF (optionally ON for the cross-check only) | medium | Checkpoint 6 |
| 7 | User-data reset: rehearse locally, then the real target | one agent, strictly sequential | Max · OFF | medium | Checkpoint 7 |
| 8 | Final verification, report A–L, ideas | one agent | Max · OFF | medium | final report |

### Phase 1 — live inspection (evidence set)

- Web, local harness with the dev seed (realistic data) **and** an empty new
  account: every page, subpage, detail, sheet, popup, dropdown and wheel;
  every settings category; account popup (photo upload, friends, header);
  login / register / forgot-password; empty, loading, error, success states.
- Sizes: phone 393×852, small phone 320, tablet 768, desktop 1440 and 1920.
  Both themes, Dutch and a sample in English + imperial.
- Measured, not eyeballed: computed font sizes/weights/line heights, gaps and
  paddings per element (a script dumps them next to each screenshot), so
  "two cards 4 px further apart" is a number, not an impression.
- Android: Robolectric native-graphics screenshots of the same screens with
  the same fixtures, both themes; plus font-scale 1.3.
- Output: `scratchpad/audit/` (PNG + JSON metrics, index page), listed here.
  Nothing in the repository changes in this phase.

### Phase 2 — parallel audit (the one phase that clearly gains from agents)

Independent lenses, each reading the evidence set + the code, each writing
findings in one fixed format (location · problem · why · proposal ·
affected components · complexity · risk · objective/usability/preference):

1. Typography and hierarchy (incl. the login example, every heading/label/link ratio)
2. Spacing, alignment, rhythm (measured gaps vs. relationship)
3. Components: cards, glass, buttons/links/actions, inputs, uploads, icons (icon meaning map)
4. Charts (every chart vs. docs/CHARTS.md and each other)
5. UX, flows, states, motion, accessibility (keyboard, focus, contrast, targets, reduced motion)
6. Web ↔ Android parity and terminology
7. Performance, static (requests, payload, queries, N+1, caching, CSS/JS weight)
8. Dead code: web + backend + config + assets
9. Dead code: Android + database usage (tables/columns/indexes vs. every query)

Then one consolidation pass: duplicates merged, each pattern resolved
globally (which implementation is canonical), conflicts between lenses
settled. The five examples from the request are named explicitly in lenses
1–3 so none is missed.

### Phase 3 — plan and proposals (stop for approval)

One coherent improvement plan, grouped by shared pattern rather than by page.
Visual proposals as a before/after page (real screenshots, both themes).
Three lists for you to decide:
(a) fixes I would apply without asking (low-risk, no new convention),
(b) visual/design-system changes needing approval, one by one,
(c) things I recommend leaving alone, with the reason.

### Phase 4 — implementation

Only (a) and the approved part of (b). Both apps together, per pattern.
Targeted tests per change (not the whole slow Android suite each time),
screenshots before/after for every visual change.

### Phase 5 — performance

Baseline first (TTFB per page on the harness, request count/size, JS long
tasks, DB query count and time per page via a query log, Android state
payload size and parse time), then only changes with a clear, measured gain
and no visible change. Before/after numbers recorded here.

### Phase 6 — cleanup

Only what is proven unused across web, Android, API, cron, routes, dynamic
includes and config. DB removals through migration 032 + rollback, schema.sql
updated, migration history kept. Everything uncertain is listed, not removed.

### Phase 7 — the user-data reset (destructive; see Checkpoint 0 questions)

1. Verify the target: which database(s) — live Hestia, the WAMP machine, or
   both — confirmed by you, then identified on the server itself (database
   name, host, table counts) before anything is touched.
2. Map every table holding personal data (FKs, `user_id` columns, files in
   `uploads/avatars/`, sessions, tokens, outbox, throttle rows) vs.
   infrastructure (research, references, metric types, data sources,
   categories, config, migrations).
3. Rehearse the exact reset on a local copy of the schema with seeded users:
   counts before/after, FK check, orphan queries, then register/login/profile
   on the reset copy.
4. Real run: backup outside the web root (private/, 600), reset in one
   transaction, avatars removed, verification queries, disposable test
   account created and deleted, backup removed afterwards and its removal
   checked.
   On Hestia this needs the one-time-file route over SFTP (no shell), which
   means a manually dispatched workflow + script on `main` → your approval to
   push. Alternative: I hand you a tested SQL script + steps for phpMyAdmin.

### Phase 8 — verification and the report

Full pass over both apps again (screenshots compared with Phase 1), the
targeted test suites, `i18n --check`, PHP lint, Android build. Final report
A–L (as a document page), future ideas (short, prioritised, no
implementation), roadmap P1/P2/P3.

## Checkpoint 0 — decisions (from the user)

1. **Reset target: both** — the live Hestia database and the primary Ownify
   database on the WAMP machine. The two local test databases (`g7`,
   `ownify_e2e`) are **kept**. Exact database names verified and all
   user-related records mapped before anything is deleted; unrelated
   databases untouched.
2. **Route: phpMyAdmin SQL scripts**, tested locally first. No temporary PHP
   endpoint, no deployment workflow. One script per target, clearly named,
   each with pre-reset checks and post-reset verification, plus written
   phpMyAdmin instructions. The reset itself is already authorised.
3. **Progress document**: committed at every major checkpoint on the branch
   `final-audit-progress` and pushed there. Verified: every push-triggered
   workflow (`deploy.yml`, `update-wamp.yml`, `android-apk.yml`) runs on
   `main` only; the rest are manual. Never pushed to or merged into `main`
   without approval. No credentials, personal data or backups in it.
4. **Implementation commits**: one documented commit per completed phase,
   committed locally; a version bump only when the app itself changes.
   Pushing to `main` always needs explicit approval.
5. Phase 2 may use the parallel reviewers, kept clearly separate; mind the
   weekly limit, but never skip a needed quality check.

## Phase 1 — evidence set (done)

Where: `$SCRATCH/audit/` (session scratchpad, not in git — it holds a test
account's data; nothing personal). `$SCRATCH` =
`/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad`.

- `audit/cap.js` — every rail page and every detail (all 88 `[data-detail]`
  pages reached), scrolled screen by screen, PNG + a metrics JSON per shot
  (each visible text: font size/weight/line height/tracking/colour/box; each
  card/glass/button/field block: box, padding, margin, radius, background,
  border, shadow, backdrop filter, gap).
- `audit/pop.js` — popups, sheets, wizards, wheel, auth screens, empty account.
- `audit/live.js` — the live site's signed-out screens (welcome, login,
  forgot password, register), read-only, no login: the local harness has no
  mail or Google configured, so its login popup differs from the real one.
- Test data: `ownify_e2e` user dev_jij filled with 120 days through the app's
  own import (`audit/fill.php`: 1,531 records → 2,307 metrics, 114 nights
  with stages, 64 workouts, heart-rate minutes, nutrition, weight, caffeine).
  dev_lisa stays empty for the empty states. Dump before the fill:
  `audit/ownify_e2e-before-fill.sql` (local test DB only).

Sets (853 screenshots in all, each with a metrics/layout JSON):

| Set | What |
|---|---|
| `web/phone-dark`, `web/phone-light` | 393×852 @2x, all 5 rail pages + all 88 detail pages, scrolled (204 shots each) |
| `web/small-dark` | 320×640, 20 key views (66 shots) |
| `web/en-phone-dark` | English + imperial, 17 key views (45 shots); the account was set back to "follow the device" afterwards |
| `web/desktop-*`, `web/tablet-dark`, `web/wide-dark` | ≥768 px: the iPhone frame (one shot each; see observation 10) |
| `web/pop-phone-dark`, `web/pop-phone-light` | auth screens, account (+ photo picked/uploaded, + name), friends, add friend, devices, pairing, OwnifyAI, goal wizard 1–2, challenge wizard 1–5 + calendar, code sheet, clock wheel, empty account (dev_lisa) pages and details |
| `web/live` | live site signed out: welcome, login, forgot password, register — phone + desktop, both themes |
| `web/a11y` | `a11y.json` (touch targets + accessible names on 13 views, keyboard order), focus-ring crops, reduced-motion and more-contrast variants |
| `android/dark`, `android/light` | 111 screens each through `ScreenshotCapture` (412×915 dp @2.625) from dev_jij's real `api/app/state.php` (`android/state-dev_jij.json`; dev_lisa as the free/empty account), each with a layout dump in dp |

Test account changes (local `ownify_e2e` only, kept for Phase 2–8): dev_jij
got 120 days of data, the name "Jasper van der Veen" and a test photo.
`ownify_e2e-before-fill.sql` restores the database as it was.

Not testable here, and why: real Health Connect / Polar / Google sync
(no device, fake services only); real e-mail; the Android app on a device
(no emulator: Robolectric renders the real Compose UI, but touch feel,
system bars and real fonts rendering can differ slightly); the live site
signed in (no account to use there).

### Early observations (raw; Phase 2 verifies and widens them)

1. Login popup (live): heading "Inloggen" ≈ the underlined "Wachtwoord
   vergeten?" link in size and weight; the field labels are small caps,
   smaller than the link; the heading repeats the button text "Inloggen";
   the primary "Inloggen" button is the neutral glass, not the green action.
2. Account popup, photo row: native file input in a pill — "Bestand kiezen"
   chip + "Geen bestand..." wrapping to two lines in body size, larger than
   the "Uploaden" button text; Uploaden looks enabled before a file is picked.
3. Account popup, Vrienden: a flat filled row ("2 vrienden ›") unlike the
   glass rows and pills around it.
4. Detail pages, back pill: after opening, a thick double ring around the
   "‹ Gezondheid" pill (focus ring on the pill the detail focuses).
5. Slaap detail: the floating "+" overlaps the SpO₂ card's day labels
   ("Di Wo"); "Tijd in bed + Regelmaat" card shows one dot and "— 93".
6. Local harness differs from live where mail/Google are not configured
   (no "Wachtwoord vergeten?", "Google is nog niet gekoppeld."): live
   screenshots are the reference for those screens.
7. Touch targets under 44 px (a11y.json): the OwnifyAI handle "OwnifyAI
   openen — of veeg omhoog" 128×40 on every page; the range switch options
   (7/30/90 dagen, 1 jaar) 75×34; Doelen's Actief/Afgerond 177×34; "Primair
   doel ›" 101×22; the Gezondheidsscore heading link 211×32.
8. Keyboard order on Overzicht is sensible (skip link first), but "Nog 8
   dagen" (a locked insight's progress) is a tab stop of its own.
9. Doelen says "Nog geen doelen" while dev_jij has a goal page `goal-1`
   (finished/ended goal?) — check what the empty state means then.
10. ≥ 768 px the website is only ever the phone inside an iPhone frame
    (components/phone-simulator.php, an iframe of the page): desktop, tablet
    and wide shots are that frame; there is no separate desktop layout to
    audit, only the frame's own presentation.
12. Account popup (web): the real name (first/last name set) is not shown —
    only username and "Lid sinds"; the photo is. Android's account sheet
    showed neither photo nor name from the same state — check whether it
    reads them. Example 3's "thick elements" beside the header are not in
    the popup: likely Instellingen → Account's header (reviewer 3).
11. Goal wizard step 3 (dates) was not reached by script (step 2 needs a
    target typed); its calendar is the same component as the challenge
    wizard's, which was captured (`chwiz-3-calendar`).

## Phase 2 — reviewer briefs (ready to launch)

Every reviewer: read-only (no edits to the repository, no commits, no
database writes), works from the evidence set and the code, and writes its
findings to `docs/audit/lens-N-<name>.md` in this format, one finding per
block:

```
### N.k Title
- Where: page/component · file:line (web) · file:line (Android)
- Problem: what is wrong, with the measured numbers from the metrics JSON
- Why: objective defect / usability issue / design preference (say which)
- Proposal: the fix, using existing tokens/components; NEW CONVENTION if it needs one
- Affects: every other place with the same pattern
- Complexity: S / M / L · Risk: what could break
```

Shared context for all: CLAUDE.md, docs/THEME.md (the approved system),
the evidence set (paths above; `_index.json` per set; metrics JSON beside
each PNG), web code (pages/, components/, assets/css, assets/js), Android
code (OwnifyAndroid/app/src/main/java/com/ownify/android/ui). Never change
anything; never propose a new font. Distinguish platform-legitimate
differences from inconsistencies.

| Lens | Scope (and only this) | Must cover explicitly |
|---|---|---|
| 1 Typography & hierarchy | sizes/weights/line heights/tracking/colour roles of every heading, title, label, value, link, button text; hierarchy logic per screen | the login popup; every popup heading vs its links and labels; section titles vs card titles; `--fs-*` steps actually used vs THEME.md |
| 2 Spacing & alignment | gaps between headings/content, cards, sections; paddings inside components; icon–text gaps; edge insets; responsive at 320 | measured gap tables per page from the metrics; unexplained differences between equivalent stacks |
| 3 Components, actions & icons | cards/glass/wells/rows/notes/slots, buttons vs links vs bubbles vs glass actions, inputs, uploads, switches, icon set (meaning map, filled vs outline, sizes) | examples 1–5 from the request (photo upload, friends row, profile header, privacy repetition, data-management button hierarchy); destructive actions |
| 4 Charts | every chart on both apps vs docs/CHARTS.md and each other: lines, markers, grid, axes, fills, tooltips, empty data, labels, scales, widths, animation | which line charts show markers and why; the FAB overlapping chart labels; one-dot charts |
| 5 UX, states, motion, a11y | flows and destinations, feedback, empty/loading/error/success, hover/focus/pressed/disabled, motion timing/easing, reduced motion, contrast, targets, labels | `web/a11y/a11y.json`; the double ring on the back pill; empty account (`pop-*/empty-*`) |
| 6 Web ↔ Android parity & terminology | same screen side by side (`web/phone-*` vs `android/*`), wording, naming of the same concept, OwnifyAndroid/docs/PARITY.md accuracy | terms used for the same thing in different places (e.g. Gezondheidsscore/score, Instellingen names) |
| 7 Performance (static) | request count/size per page, duplicate fetches, payload of api/app/state.php, heavy PHP per request, N+1 queries, CSS/JS weight and unused rules, caching headers, images | measured numbers only where measured; theoretical items marked so (Phase 5 measures) |
| 8 Dead code — web/backend | unused PHP functions, endpoints, components, CSS selectors, JS, config keys, assets, tools, docs that describe removed features | proof of non-use per item (grep across web + Android + cron + routes + dynamic includes); uncertain items listed separately |
| 9 Dead code — Android + database | unused Kotlin/composables/resources; every table/column/index in schema.sql vs queries in PHP; orphan risks | which tables hold personal data (input for Phase 7's map); redundant indexes |

After the reviewers: one consolidation pass (me) into
`docs/audit/CONSOLIDATED.md` — duplicates merged, one canonical pattern per
component type, conflicts settled — which is the input for Phase 3.

## Progress log

- Phase 0 done (this document). Checkpoint 0 answered.
- Phase 1 started (Effort High, Ultracode OFF).
- Phase 1 done: evidence set complete (see the table above). **Checkpoint 1.**
- Phase 2 done (XHigh; Ultracode ON, then OFF after the usage limit): `docs/audit/CONSOLIDATED.md`. **Checkpoint 2.**
- Checkpoint 2 answered: `docs/audit/OWNER-DECISIONS.md`.
- Phase 3 done (Effort Max, Ultracode OFF, prototype skill started by the owner): `docs/audit/PHASE3-PLAN.md`,
  the web prototype diff, the popup picker and the approval page. **Checkpoint 3.**

## Checkpoint 1 — before Phase 2

Done: the evidence set for both apps; twelve early observations; the nine
reviewer briefs, ready to launch. Nothing in the app was changed.

Next: Phase 2, nine parallel read-only reviewers + one consolidation.
Settings: **Effort XHigh, Ultracode ON**.

## Phase 2 — running

Started with Effort XHigh, Ultracode ON (user's go, with extra rules: desktop
out of scope; investigate the missing name/photo on Android and the missing
name in the web popup, do not fix; concrete evidence per finding; VERIFIED /
HYPOTHESIS / PREFERENCE kept apart; deduplicate in the merge; strictly
read-only; use every relevant uploaded skill).

Skills check before Phase 2: available and used — apple-design (lenses
1–6), dataviz (4), mobile-native, improve-animations (audit only), break-ui
(map + worst-case reasoning only; its toggle needs code/data changes →
Phase 4), animation-vocabulary (5). **`review-animations` and `prototype`
are enabled on the account but can only be started by the user**
(`/anthropic-skills:review-animations`, `/anthropic-skills:prototype`);
Phase 2 does not depend on them; Phase 3 (prototype) and Phase 4
(review-animations) will.

Shared brief: `docs/audit/BRIEF.md`. Outputs: `docs/audit/lens-1-typography.md`,
`lens-2-spacing.md`, `lens-3-components.md`, `lens-4-charts.md`,
`lens-5-ux.md`, `lens-6-parity.md`, `lens-7-performance.md`,
`lens-8-deadcode-web.md`, `lens-9-android-db.md`; merge →
`docs/audit/CONSOLIDATED.md`.

Run note: the parallel run hit the usage limit twice. On the user's
instruction ("ultracode off") the rest of Phase 2 ran sequentially with
Effort XHigh and no agents. Every reviewer wrote at least partial findings,
each with its own "Not checked" list. The gaps that mattered were then filled
by hand: DB checks, the mobile-native checklist, break-ui limits, terminology,
Android state keys, 320 px wraps and visual composites.

## Phase 2 — done

Result: `docs/audit/CONSOLIDATED.md`, with 106 findings plus one evidence
task (C-E1), deduplicated and grouped by shared pattern (G1–G18). Each
finding names its canonical implementation, its status (VERIFIED /
HYPOTHESIS / PREFERENCE) and its lens references, and is ranked:

- **P1:** 19 items
- **P2:** 47, plus the P2 halves of C46 and C59
- **P3:** the rest

Owner's question, part A:

- **Android photo:** a test-harness bug. The server URL had no trailing
  slash, so the image URL was malformed and the app showed the placeholder.
  This is not the app, the data or sync.
- **Real name:** absent from the popup by design on both apps. It is shown
  in Instellingen › Account.

Corrections made while merging:

- **Touch targets:** observation 7 was mostly false positives (`::after`
  hit areas, and cards that are targets as a whole).
- **Avatars:** none are committed to git.
- **`g7`:** lacks 027–029.
- **Artefacts:** the capture and fixture artefacts are listed so they are not
  counted as defects.

Nothing in the app, the databases or the servers was changed.

## Checkpoint 2 — before Phase 3

Waiting for the owner on:

- the decisions in CONSOLIDATED.md §4 (title and card-head sizes, the
  destructive rule, button labels, the account popup's job, the privacy
  wording, the icons, the chart conventions, web back, the Overzicht
  arrival, terminology, the Phase 6 UNCERTAIN items);
- approval or rejection of the NEW CONVENTION items in §5;
- a screenshot of example 3's "thick elements".

Next: Phase 3, proposals and prototypes for the approved groups.

- It needs the user to start `/anthropic-skills:prototype`.
- Phase 4 will need `/anthropic-skills:review-animations`.

Recommended settings for Phase 3: **Effort Max, Ultracode OFF** (sequential,
usage-aware).

## Phase 3 — done

Input: `CONSOLIDATED.md` and the owner's answers to Checkpoint 2
(`docs/audit/OWNER-DECISIONS.md`; the decisions page
[Ownify Owner Decisions](https://claude.ai/artifact/5Br5nXpVsdE4dHuXWocw42)).

**The plan:** `docs/audit/PHASE3-PLAN.md`.

- It is grouped by 14 shared patterns, not by page. Each item says which
  list it is on.
- It contains:
  - nine interpretations found while prototyping, to confirm;
  - the icon re-map table;
  - what a build step (N15) involves;
  - the three lists:
    - **(a)** A1–A16, applied without asking;
    - **(b)** B1–B16, for approval one by one;
    - **(c)** eight items to leave alone, each with a reason.

**The web prototype:**

- Two scratch copies of the app ran side by side on the test account. They
  live outside the repository, in the session scratchpad.
- The approved changes went into the "after" copy only: 22 files, 45 diff
  hunks.
- The diff is kept as `docs/audit/PHASE3-WEB-PROTOTYPE.diff`. It is the draft
  of the Phase 4 web work; it has no English strings and no Android side yet.
- Real screenshots: 19 scenes, before and after, Dark and White, phone size,
  at rest. That is 76 images.

**The account-popup picker:**

- [Account Popup Variants](https://claude.ai/artifact/JvF48zNv71nyK2PGg5PPo9),
  published privately.
- It offers three directions for D4: Centered, List and Tiles.
- Each runs on the app's real stylesheets and icons, with working taps, a
  theme toggle and keyboard flipping.
- No direction is pre-picked.

**The approval page:**

- [Ownify Phase 3 Approvals](https://claude.ai/artifact/6CW7XvVX9SiA4Wneh6giA1),
  published privately.
- For each B item it shows before/after in both themes, with approve,
  change, discuss and reject.
- Every A and C item has an "object" or "change it anyway" flag.
- Answers save with the page, so they can be read here from any laptop, and
  can also be exported.

**Skills:**

- Used: `prototype` (started by the owner) and `apple-design` (the highest
  authority).
- The prototype skill's picker spec (`PICKER.md`) is not shipped in this
  session. The picker follows the skill's own description instead: neutral
  chrome, one variant at a time at full size, instant switching, keyboard
  flipping.

Nothing in the app, the databases or the servers was changed.

## Checkpoint 3 — before Phase 4

Waiting for the owner, on the approval page:

- **B1–B16**, one by one: approve, change, discuss or reject. The nine
  interpretations are part of the B items they touch.
- **B5:** the popup direction, Centered, List or Tiles, chosen after trying
  them in the picker.
- **B9:** one warning icon (W1–W3) and one theme icon (T1–T3).
- Any objection to an (a) item, or a "change it anyway" on a (c) item.
- Optional: a screenshot of example 3's "thick elements". It was never sent,
  and D5 went ahead without it.

The page saves the answers but cannot notify this session. The owner says
"done" in chat, and the answers are then read from the page and recorded in
`docs/audit/PHASE3-APPROVALS.md` on this branch.

Next: Phase 4, implementation on both apps of (a) and the approved part of
(b) only, pattern by pattern:

- targeted tests per change, with Android before/after screenshots;
- versioned as decided in B16;
- each step committed locally;
- nothing pushed to `main` without the owner's explicit approval.

After the B5 choice, the picker's scratch files are removed, as the prototype
skill requires.

- Phase 4 needs the owner to start `/anthropic-skills:review-animations`. It
  reviews the motion changes: the arrival in B12, the sheets, and back.

Recommended settings for Phase 4: **Effort High, Ultracode OFF**. The
decisions are made and the web draft exists, so the work is careful but
specified. It runs sequentially, usage-aware.

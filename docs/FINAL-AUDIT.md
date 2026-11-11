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
  databases (`g7`, `ownify_e2e`, both migrated to 031), a PHP dev server
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

## Progress log

- Phase 0 done (this document). Checkpoint 0 answered.
- Phase 1 started (Effort High, Ultracode OFF).

# Lens 7 — Performance (static analysis)

Reviewer 7 of 9. Strictly read-only: I made no logins and no DB writes, started nothing and ran no Gradle. Each item is marked
**MEASURED** (a read-only measurement: file sizes, the state fixture, EXPLAIN, global MariaDB
counters, one signed-out curl made before the server went down) or **THEORETICAL** (read from the code).
I did not change any health calculation or API contract. Every caching proposal must return exactly the same results.
The review was stopped early to save usage; see "Not checked".

## Summary

1. Every web page view and every Android state read runs the full `app_page_data()`. The Health Score engine runs
   about 5 times per request (`health_score_now` ×3, `health_score_data` ×2 more). MariaDB's global counters from the
   Phase 1 captures suggest roughly **500–900 SELECTs per signed-in render** (MEASURED, indirect).
2. N+1 query patterns: `goal_user_auto_codes` (26 measurements × 90 days on every view), challenge standings per
   member, research details (29 × 3 queries), `user_account` (3 queries) called uncached about 6 times, and the
   `health_metric_types` lookup uncached in 4 helpers.
3. The reconciled day totals compute a CRC32 fingerprint over every reading in the range on every call. The cost
   grows with the number of Health Connect interval records, which the test data does not have.
4. Indexes: there is no index on `(user_id, metric_type_id, recorded_on)`, so the optimizer picks `fk_hm_type` (all
   users' rows) plus a temporary table and filesort. `DATE(col) BETWEEN` filters cause a full scan on
   `user_measurements`.
5. One very large document: 5 rail pages and 88 detail pages, all laid out on load. Closed detail pages are only
   translated off-screen, and `content-visibility` is used nowhere. The phone simulator at ≥ 768 px renders
   everything twice.
6. Assets: 17 render-blocking stylesheets (421 KB raw / 86 KB gz) and 33 scripts (562 KB / 137 KB gz), unminified and
   about 28 % comments. Caching and compression are not configured in the repository.
7. The Android state is 485,873 B (gzip → 95,229 B). 26 % of it is static copy: Licenties alone is 49.6 KB and the
   research copy 64.5 KB. It is re-read on every `ON_RESUME`. Parsing runs off the main thread (good).
8. JS: five MutationObservers watch the whole document (`subtree`). `chart-axis.js settle()` alternates style writes
   and layout reads per tick (layout thrash).

## Findings

### 7.1 The score engine runs about 5× per request; a few hundred queries per render
- Status: MEASURED (indirect) + THEORETICAL · Kind: defect (waste)
- Where: `lib/hydrate-health.php:62` `health_score_now()`. `lib/hydrate-health.php:336` `health_score_trend(28)` →
  `includes/health-score.php:186` calls `health_score_now()` again because `$today` is not passed.
  `lib/hydrate-compass.php:34` `health_score_history($userId,$days)` → a third `health_score_now()`.
  `lib/hydrate-setup.php:174,229` call `health_score_data()` twice more.
  One `health_score_data()` (`includes/health-score.php:308–415`) is about 35–45 queries:
  - 9 nutrition codes × `health_daily_metric_days` (2 queries each);
  - workouts, nights, ratings, caffeine, goals;
  - `user_account()` ×2 (directly and through `nutrition_targets_for_user`), 3 queries each.
- Measured: MariaDB global status after the Phase 1 run (uptime 65 min):
  - `Com_select` 503,279 over 888 connections;
  - about 560 signed-in renders by count (each `fresh()` in cap.js, desktop ×2 for the iframe, plus the state
    fetches); `fill.php`'s import is included in the total, so per render ≈ **500–900 SELECTs**;
  - `Select_scan` 43,416, `Created_tmp_disk_tables` 13,015, `Handler_read_rnd_next` 4.99 M.

  Signed out, for contrast: about 1–2 queries, 20,380 B HTML, 3 ms.
- Proposal: compute `$scores` once per request and pass it into `health_score_history(..., $today)`. The parameter
  already exists. Also keep a request-scoped memo of `health_score_data` per `(user, from, to)`.
- Risk: the three calls are microseconds apart today. Reusing one moment gives the same results except at an exact
  window boundary, where it is arguably more consistent.
- Measurement plan: on a throwaway DB copy, wrap `db_run` with a counter and `hrtime` per section through the
  harness's `auto_prepend_file`, or turn on `general_log`. Count queries and time per hydrate step for dev_jij.
- Complexity: S · Priority: **P1**

### 7.2 Repeated identical lookups that are not cached
- Status: THEORETICAL (VERIFIED in code) · Kind: defect (waste)
- Where:
  - `SELECT id, aggregation FROM health_metric_types WHERE code = ?` runs uncached in
    `includes/health-data.php:295,324,365` and `includes/goal-progress.php:150`, while
    `health_metric_type_id()` (`health-data.php:41`) caches the same table.
  - `user_account()` (`includes/user.php:84`, 3 queries) is called about 6× per request: score data, nutrition
    targets, `insights/render.php:158`, facts, correlations frame, goal suggest.
  - `research_claim_by_id()` (`includes/research.php:318`) is not cached, unlike `research_claims()`.
- Proposal: static per-request caches keyed by code, user id and claim id. The rows do not change within a read
  request. Clear the `user_account` cache on profile writes.
- Complexity: S · Risk: a stale cache inside a write request. Scope it to reads, or clear it in the writers.
- Priority: **P1**

### 7.3 `goal_user_auto_codes` runs on every view: 26 measurements × 90 days
- Status: THEORETICAL · Kind: defect (waste)
- Where: `lib/hydrate-goals.php:50` → `includes/goal-progress.php:323–335` → `goal_source_by_day()` (`:140–220`), 2–4
  queries per measurement, about 60–100 queries, only to decide which wizard options are "auto".
- Proposal: memoize per request. Better: one existence query per source kind (metrics grouped by type, measurements,
  workouts, nights) with the same predicate (a non-null value in the last 90 days).
- Risk: the result must be identical, including sum metrics whose daily total is 0. Write a regression test that
  compares the old and new output for every fixture account.
- Complexity: M · Priority: **P1**

### 7.4 The day-total fingerprint reads every reading in the range on every call
- Status: THEORETICAL · Kind: performance risk at real data scale
- Where: `includes/health-totals.php:152–162` runs `BIT_XOR(CRC32(CONCAT_WS(...)))` over all readings of
  `[from, to+2)` on each call to `health_metric_totals()` for reconciled metrics.
- Called for steps, distance, active energy and floors from several places:
  - the score;
  - the goal auto codes (90 days);
  - the area charts (1 year);
  - challenges, per member;
  - goal suggest and insights.
- The test DB has 1 steps row per day; real Health Connect writes many interval records per day.
- Proposal: a request-scoped memo per `(user, type, from, to)`, and serve sub-ranges from the widest range already read.
  The kept totals stay the source of truth, so the results are unchanged.
- Measurement plan: seed a throwaway DB with realistic interval density (for example 100–300 step records per day for
  a year), then time `health_metric_totals` and count the calls per request.
- Complexity: M · Priority: P2

### 7.5 N+1 in Uitdagingen and research details
- Status: THEORETICAL · Kind: defect (waste)
- Where:
  - `includes/challenges.php:594` reads `challenge_day_values()` once per joined member per challenge (running + up
    to 10 ended);
  - `:1124` reads one inviter row per invitation;
  - `:979` and `:1040–1044` read one username per challenge;
  - `includes/insights/render.php:371–378` → `research_detail()` (`research.php:525–533`) does claim_by_id twice
    plus the source, for each of 29 details (fixture), about 87 queries.
- Proposal: batch the usernames and avatars into one `IN (...)` query. Cache research sources and claims per request.
  Leave the standings maths untouched.
- Complexity: S–M · Priority: P2

### 7.6 Missing or unused indexes on hot queries
- Status: MEASURED (EXPLAIN on `ownify_e2e`, tiny tables; production plans may differ) · Kind: defect
- Where and what:
  - **`health_daily_metric_days` / `goal_source_by_day`** (`recorded_on BETWEEN`, `health-data.php:382`): the plan is
    `key=fk_hm_type` (metric type only, every user's rows), "Using temporary; Using filesort". There is no
    `(user_id, metric_type_id, recorded_on)` index; `idx_hm_user_type_time` ends in `recorded_at`.
  - **`user_measurements` with `DATE(measured_at) BETWEEN`** (`goal-progress.php:183–186`): `type=ALL`, although
    `idx_meas_current(user_id, measurement_type, measured_at)` exists.
  - **`workouts` with `DATE(started_at) BETWEEN`** (`goal-progress.php:196–199`): an index scan plus a temporary
    table and filesort.
  - **Fingerprint query** (7.4): uses `idx_hm_user_type_time` correctly.
  - **`heart_rate_minutes`**: a PK range, fine.
- Proposal:
  - a migration adding `KEY (user_id, metric_type_id, recorded_on)` on `health_metrics` (a schema addition;
    the results are unchanged);
  - rewrite the `DATE(x) BETWEEN a AND b` filters as `x >= a AND x < b + 1 day`. This is equivalent for DATETIME,
    so the results are the same.
- Complexity: S · Risk: index build time on a big table, so run the migration off-peak. Priority: P2

### 7.7 Render-time writes on every GET, multiplied by refreshes
- Status: VERIFIED in code · Kind: performance note (behaviour by design)
- Where:
  - `health_score_store` (`lib/hydrate-health.php:65`);
  - `goal_refresh_row`: an UPDATE plus an upsert into `goal_progress`, per goal (`includes/goal-progress.php:609–626`);
  - the insights day claim (`insights/lifecycle.php:99–124`);
  - `auth_touch_last_seen` (`lib/app-data.php:286`).
- Each web view, each desktop iframe (7.9) and each Android `ON_RESUME` (7.11) writes.
- Proposal: no behaviour change. In Phase 5, measure the write count and lock time per request. Optionally skip an
  UPDATE when the values did not change (`goal_store_results` already does this). Same results.
- Complexity: S · Priority: P3

### 7.8 One huge document, fully laid out
- Status: THEORETICAL (HTML size); VERIFIED (CSS) · Kind: performance
- Where:
  - `index.php:148–247` renders 5 rail pages and 88 detail pages (fixture). `.detail` is `position:absolute;
    transform:translate3d(100%,0,0)` (`assets/css/health.css:512–522`), not `display:none`, and there is no
    `content-visibility` anywhere, so style and layout run for every screen on load.
  - Full area charts render all 4 periods (`components/area-chart.php:32,89`; inactive ones `display:none`,
    `health.css:156`).
  - `e(json_encode())` `data-x` and `data-y` attributes escape every quote to `&quot;`
    (`area-chart.php:101`, `heart-chart.php:52,70`, `health-history.php:85`).
  - The icons are inline SVG at each of 148 call sites (`components/icons.php:11`), for example a back-pill chevron
    ×88.
- Estimate: the same data as JSON is 485 KB, so the signed-in HTML is likely **≥ 0.6–1.5 MB uncompressed**.
- Proposal: `content-visibility: hidden` (or `auto` with `contain-intrinsic-size`) on closed `.detail`. This is a
  **NEW CONVENTION**-level CSS change, and the scripts that measure detail pages must be checked: `chart-axis.js`,
  `goal-chart.js`, `compass-history.js`, `sleep.js`. Lazy server rendering of details would be a larger change.
- Measurement plan (Playwright, test account on a throwaway DB):
  - `document.documentElement.outerHTML.length`;
  - transfer size;
  - `document.getElementsByTagName('*').length`;
  - the "Recalculate Style" and "Layout" time of the load trace.
- Complexity: M · Risk: scripts that measure closed details read 0. Priority: **P1**

### 7.9 Phone simulator: the whole app twice at ≥ 768 px
- Status: VERIFIED in code · Kind: performance (desktop frame; design is out of scope)
- Where: `assets/js/phone-sim.js:100` sets `screen.src = location.href` after the outer document has been fully
  rendered and has run all its scripts. The outer app stays in the DOM, inert.
- Cost: twice the server work, including the writes in 7.7, twice the HTML and twice the JS initialisation.
- Proposal: skip the heavy initialisers in the outer document when `data-layout="phone-frame"`. The server cannot know
  the width; client hints would be a NEW CONVENTION.
- Complexity: M · Priority: P3 (limitation)

### 7.10 Assets: many requests, unminified, no cache or compression config in the repo
- Status: MEASURED (sizes) / THEORETICAL (headers) · Kind: performance
- Where:
  - `index.php:94–97`: 17 render-blocking stylesheets, 421,282 B raw / 85,787 B gzip-9;
  - `index.php:279–310` plus the head's `i18n.js`: 33 deferred scripts, 561,673 B / 137,437 B gzip;
  - `lib/render.php:142`: `asset()` adds `?v=filemtime`, which allows immutable caching;
  - there is no root `.htaccess`, and `api_json` (`api/bootstrap.php:72–90`) and `index.php` set no compression.
  - All CSS without comments and whitespace: 286,636 B / 42,309 B gz (against 90,456 B gz).
  - JS without comments: about 470 KB / 99 KB gz.
- Unused CSS (sample: 21 of 1,264 classes have no reference anywhere, about 1.7 %):
  - `ch-pick*`, `ch-preview*`, `ch-stepper__value`, `ch-hint--tight` (`challenges.css:841,1053,1093,1117`);
  - `goals-notice*` (`goals.css:35`), `goal-manage__state*` (`goals.css:547`);
  - `wizard__field`, `wizard__input--unit`, `wizard__row--spaced` (`goals.css:775–786`);
  - `wizard-tile` (`theme.css:813`);
  - `pill--close` (`components.css:524`), `chips--centered` (`components.css:1042`);
  - `board-extra` (`community.css:331`), `compass-empty` (`compass.css:204`).
- Images:
  - the welcome logo `assets/brand/ownify-icon.webp` is 141,618 B, lossless 512×512 (`pages/welcome.php:37`), the
    largest asset on the signed-out page;
  - `ownify-icon-192.png` (44,304 B) is shown at 30–64 px (`components/ai-consent.php:16,39`,
    `components/ai-empty-state.php:22`, `pages/ai.php:74`).
  - No web fonts are shipped, so there is nothing to preload.
- Proposal:
  - server gzip/brotli and `Cache-Control: max-age=31536000, immutable` for `?v=` assets (config only);
  - a lossy WebP or AVIF logo at 2× its display size;
  - delete the dead rules after confirming them;
  - minification or bundling would be a build step: **NEW CONVENTION** (the README says "no build step").
- Measurement plan: the live response headers (`content-encoding`, `cache-control`) and a cold-versus-warm load waterfall.
- Complexity: S (config) · Priority: P2

### 7.11 Android state payload and refresh frequency
- Status: MEASURED (`$A/android/state-dev_jij.json`) · Kind: performance
- Size: 485,873 B, of which:

  | Key | Size | Share | Notes |
  |---|---|---|---|
  | `health` | 212 KB | 43.8 % | `training.view` 114 KB (heart 51 KB, of which one day 36 KB: 576 points 19 KB + an 11.7 KB path; charts 47 KB); `sleep.view` 63 KB; `nutrition.view` 22 KB |
  | `research` | 64.5 KB | 13.3 % | 29 details |
  | `legal` | 60.5 KB | 12.5 % | Licenties 49.6 KB |
  | `correlations` | 44 KB | 9.2 % | |
  | `goals` | 37.6 KB | 7.8 % | `measurements` 21 KB |
  | `challenges` | 18.6 KB | 3.8 % | |
  | `settings` | 16.7 KB | 3.4 % | |
  | `compass` | 12.3 KB | 2.5 % | |

- gzip -6 of the fixture gives 95,229 B (−80 %).
- Static per-locale copy (legal and the research copy) is about 125 KB, re-sent on every read.
- Parsing (VERIFIED good): network and `JSONObject` run on `Dispatchers.IO` (`connection/OwnifyApi.kt:428–438`),
  `AppData.parse` on `Dispatchers.Default` (`data/OwnifyAppState.kt:115`). The 4 MB cap
  (`OwnifyApi.kt:572`) leaves 8× headroom.
- Refresh triggers: every `ON_RESUME` (`ui/app/OwnifyApp.kt:138`), after each sync that wrote something (`:147`),
  and a reload after writes at 8 call sites. Each one is a full server render plus writes plus 486 KB.
- Proposal:
  - transport compression on the server (contract-neutral; `HttpURLConnection` asks for gzip by default), verified
    live;
  - a minimum interval between `ON_RESUME` refreshes (behaviour; the owner decides);
  - lazy loading of legal or research text would be an **API contract change** (the owner decides; not proposed here).
- Complexity: S (compression) · Priority: P2

### 7.12 JS: MutationObservers over the whole document and layout thrash
- Status: THEORETICAL (VERIFIED in code) · Kind: performance
- Observers on the whole document with `childList+subtree` (each DOM insertion runs all of them; AI chat messages,
  editors):
  - `wheel-picker.js:449` (on `documentElement`, not batched);
  - `notices.js:160` (rAF-batched);
  - `password-reveal.js:95` (not batched);
  - `chart-axis.js:68` (rAF, then `querySelectorAll` over all axes);
  - `interactions.js:73` (body subtree, `aria-hidden` attribute).
- `chart-axis.js:34–51` `settle()`: per tick it removes a property (a write), then calls `getBoundingClientRect`
  (a read), which forces style and layout on every tick. `ResizeObserver` settles every active axis on load: about
  40 axes × 7–13 ticks.
- No problem found here:
  - the scroll listeners are passive and rAF-gated (`interactions.js:135,207`, `ai-chat.js:152`,
    `pull-sync.js:442`);
  - the rAF loops stop (`hero-ring.js:245–248`, `page-navigation.js:243`);
  - the timers are cleared (`ai-chat.js:212/225`, `phone-sim.js:94/109`).
- Proposal: in `settle()`, do all writes, then all reads, then all writes (same result). Batch the two unbatched
  observers into one rAF.
- Measurement plan: a DevTools Performance trace on load and while an AI answer arrives ("Forced reflow" warnings,
  Long Tasks).
- Complexity: S · Priority: P2

### 7.13 Backdrop-filter surfaces
- Status: MEASURED (partly) · Kind: performance risk (design unchanged)
- Measured: in the metrics (phone-dark, scroll 0, 93 screens) the median is 2 and the maximum 4 surfaces: `nav.tabbar`
  on 93, `span.glass` 48, `button.fab` 23, `a.btn` 6.
- What the metrics miss, because their class filter does not match:
  - `.pill` (`components.css:345`), on every back pill;
  - `.app-header::before` (`components.css:239–249`), one per detail header;
  - `.range-switch` (`health.css:91`), which scrolls with the content;
  - `.pull-sync__pill`.
- So a chart page has about 6 surfaces. The CSS holds 85 backdrop-filter declarations, and the 88 off-screen detail
  headers and pills may each hold compositor layers.
- Proposal: measure before deciding; do not change the glass. Containment for closed details (7.8) would also drop
  their layers.
- Measurement plan: the Layers panel layer count; scroll frame timing on a mid-range Android Chrome.
- Priority: P3

### 7.14 Android long pages are a Column with `verticalScroll`
- Status: HYPOTHESIS · Kind: performance
- Where: lazy lists exist only in `ui/design/Wheel.kt`, `ui/screens/research/ResearchPdf.kt` and
  `ui/screens/assistant/AssistantSheet.kt`. Pages use `verticalScroll` (`ui/app/AppShell.kt:464`,
  `ui/app/BottomSheet.kt:199`, `ui/app/Overlays.kt:237`, 13 places). Licenties (49.6 KB of text, 20 sections),
  research and Inzichten are composed all at once.
- Confirm with: Macrobenchmark frame timing and Layout Inspector recomposition counts on Licenties and Training.
- Priority: P3

## Not checked / could not verify
- The signed-in HTML size and DOM node count. Logging in was not allowed and the server went down; 7.8 is an estimate.
- The exact query count per request. There is no `general_log` or performance_schema; 7.1 is a global-counter
  estimate. Phase 5 needs a PDO counter on a throwaway DB.
- EXPLAIN for leaderboard, points, challenges, insights and correlation queries. MariaDB went down before I got to
  them.
- Not read in detail:
  - `hydrate_training`, `hydrate_sleep` and `hydrate_nutrition` (1-year reads, `area-charts.php:174`);
  - `goal_suggest_for`, `hydrate_setup` / `hydrate_calibration`, `notices_for`, `settings_prepare`, `ai_summary`;
  - the cost of parsing `legal_documents()` / config.
- Live cache and compression headers. I did not contact any remote host.
- Duplicated CSS rules, which I did not check.
- Android:
  - Compose stability of the `AppData` classes and recomposition hot spots (state read high in the tree, lambdas);
  - `Avatar.kt` image decoding;
  - real-device startup and frame timing.
- The scale of `heart_rate_minutes` and `health_metrics` on real data (all local DBs are tiny).

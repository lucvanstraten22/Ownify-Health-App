# Lens 8 — Dead code: web app + backend

Reviewer 8 of 9. Strictly read-only; nothing in the repository was changed
except this file. The review was **stopped early by the coordinator** (usage),
so the scope below is partial: see "Not checked" at the end.

## Summary

- **Four write endpoints have never been called by any client** in this repo's history (checked back to the import commit c0ac2a1): `api/friends/block.php` and `api/health/nutrition.php` (no caller at all), and `api/health/sleep.php` / `api/health/training.php` (only `tools/ai-test.php` uses them to seed data). With them, `health_record_nutrition()` and `user_block_add()/user_block_remove()` have no other caller.
- `api/integrations/status.php` is a documented Health Connect contract endpoint ("call it at startup") that no Android version has ever called. Only the tests use it → UNCERTAIN.
- **22 PHP functions have no caller anywhere**, tests included. Seven lost their caller in a known version (28.0, 30.0, 30.1 — the removed Gemini insight path, 15.0); six were added and never called (1.2.0, 14.0, 29.0, 49.1); the rest have been unused since the import.
- 16 more functions are only used by `tools/` (tests or the diagnostic CLIs). They are TEST-ONLY: keep them.
- `components/community-badges.php` is never rendered. Its config copy is still sent to Android and parsed there, but never shown.
- About 30 CSS class selectors match nothing in any PHP or JS. Most are in `challenges.css` (a whole preview/chips/stepper/pick block) and `goals.css` (old wizard and notice classes).
- 3 JS functions are never called, and 14 members of the window-level JS objects are never used outside their own file. All 35 JS files and all 23 pages are loaded. All other components are included, two of them dynamically.

## Method (applies to every "no caller" claim below)

- **PHP functions:** I collected all 1,615 `function name(` definitions in 356 tracked PHP files (vendor excluded). For each name I searched every non-comment PHP line in the repo as a bare token. Method calls (`->`, `::`) and `function_exists('name')` guards were excluded; string callables were counted (a name inside a string counts as a use). I also searched JS, Kotlin, Markdown, YAML and shell. Each zero result was then confirmed with `git grep -n -w <name>`. That returns only the definition, plus the comment mentions listed. No PHP classes exist outside vendor, so there is no method dispatch to miss.
- **Endpoints:** I searched for each path relative to `api/` in `assets/js`, every PHP file (data-endpoint / action / config `endpoint`), `OwnifyAndroid/app/src`, `.github` and docs. Dynamic builders were checked:
  - `pull-sync.js:181` and `PullSyncIndicator.kt:100` (`<provider>/sync.php`)
  - `settings.js:421-423` and `SettingsDetail.kt:738` (`<provider>/start.php`)
  - OAuth redirect URIs in `config/auth.php:34` and `config/integrations.php:41`
- **History:** `git log -S` per name or path, and `git grep <c0ac2a1>` for the state at import.

## Findings

### 8.1 Four write endpoints that no client calls
- Status: VERIFIED · Kind: defect (dead code, needless write surface)
- Where:
  - `api/friends/block.php` (33 lines)
  - `api/health/nutrition.php` (68)
  - `api/health/sleep.php` (64)
  - `api/health/training.php` (67)
- Evidence:
  - `grep -rnF "friends/block.php"` → only `docs/DATABASE.md:926`.
  - `"health/nutrition.php"` → only `docs/DATABASE.md:913`.
  - `"health/sleep.php"` → `tools/ai-test.php:353` and `docs/DATABASE.md:912`.
  - `"health/training.php"` → `tools/ai-test.php:360`, `docs/DATABASE.md:915,989` and `docs/HEALTH-CONNECT.md:259`.
  - None of the four is in the Android list of `"api/…\.php"` literals, and there is no dynamic JS builder for them.
  - `git grep … c0ac2a1 -- assets/js pages components OwnifyAndroid/app/src/main lib includes` is empty: they had no caller at import either.
  - Manual entry goes through `sleep-entry.php` / `training-entry.php` (`pages/manual-entry.php:112`, `ManualEntry.kt:148`). Nutrition goes through `api/health/rating.php`.
- Functions that only these endpoints keep alive:
  - `health_record_nutrition()` (`includes/health-data.php:176`; sole caller `api/health/nutrition.php:45`)
  - `user_block_add()` / `user_block_remove()` (`includes/friends.php:501`, `:521`; sole caller `block.php:26-27`)
  - Without `block.php`, nothing writes `user_blocks`. The reads in `includes/friends.php:97,116,437-444,528` would then always find nothing (the table itself is reviewer 9's).
- Why it matters: these are live, session- and CSRF-guarded write paths that nothing uses. That is surface area and confusing documentation.
- Proposal:
  - `block.php` and `nutrition.php` (with `health_record_nutrition`, `user_block_add/remove`) → **SAFE-TO-REMOVE**, after one check of the live access log for POSTs to them.
  - `sleep.php` and `training.php` → **TEST-ONLY**: move `tools/ai-test.php:353,360` to `sleep-entry.php` / `training-entry.php`, then remove.
  - Update the `docs/DATABASE.md` rows and `HEALTH-CONNECT.md:259`.
- Complexity: S · Risk: an outside script posting to them (none known). · Priority: P2

### 8.2 `api/integrations/status.php`: a documented app endpoint the app never calls
- Status: VERIFIED (no caller) / HYPOTHESIS (intent) · Kind: defect (doc ≠ code)
- Where: `api/integrations/status.php`; `docs/HEALTH-CONNECT.md:48,136-150` ("Call it at startup")
- Evidence:
  - Callers: `tools/app-auth-test.php:444,616,669` and `tools/health-connect-test.sh:185,192,251,271,274` only.
  - `git log -S"status.php" -- OwnifyAndroid` and `git grep "status.php" c0ac2a1 -- OwnifyAndroid` are both empty: no Android version in this history ever called it.
- Proposal: **UNCERTAIN** — the owner decides whether the app should call it (as the doc says) or whether it and its doc section go. Settle it by checking the live access log for `POST /api/integrations/status.php`.
- Complexity: S · Risk: an older external companion build, if one exists. · Priority: P3

### 8.3 PHP functions with no caller at all (production or test)
- Status: VERIFIED · Kind: dead code · Verdict: **SAFE-TO-REMOVE** unless noted · Complexity: S each · Priority: P3

| Function | Where | Last caller / history |
|---|---|---|
| `auth_normalise_email` | includes/auth.php:117 | callers removed in 28.0 (4c567a9, moved to `account_email_canonical`) |
| `auth_validate_password` | includes/auth.php:128 | caller removed in 28.0 (4c567a9) |
| `ai_message_count` | includes/ai/store.php:134 | added in 1.2.0 (b476f36), never called |
| `challenge_suggest_target` | includes/challenges.php:264 | added in 49.1 (10d02b5), never called; superseded by 49.3–49.5 ("the number the group chooses"). Ask the owner, since it is recent. |
| `goal_chart_month` | lib/goal-chart.php:124 | caller removed in 15.0 (e5a7fe5) |
| `goals_slot_note` | lib/goals.php:399 | caller removed in 30.0 (a711f45, pages/goals.php). Still named as "the reference" in the comments at assets/js/goals.js:128 and GoalsPage.kt:170, and in the test name at PresentationHelpersTest.kt:78: update those. |
| `health_sleep_sessions` | includes/health-data.php:689 | none since import |
| `health_workouts` | includes/health-data.php:697 | none since import |
| `health_nutrition_entries` | includes/health-data.php:705 | none since import |
| `health_daily_scores` | includes/health-data.php:719 | none since import; reads `daily_scores`, which "nothing writes yet" (table → reviewer 9) |
| `integrations_connected_count` | includes/integrations.php:249 | none since import |
| `leaderboard_friends` | includes/leaderboard.php:197 | none since import; callers use `leaderboard_friends_group()`. Fix `docs/DATABASE.md:866` and the comment at :214. |
| `points_history` | includes/points.php:792 | none since import; named in `docs/DATABASE.md:837` |
| `polar_days` | includes/polar.php:711 | added in 14.0 (25b831f), never called |
| `research_band` | includes/research.php:464 | added in 29.0 (74395af), never called |
| `settings_drop_toggle` | lib/settings.php:664 | caller removed in 30.1 (45ccb1c, the Gemini insight consent) |
| `settings_use_state_variant` | lib/settings.php:683 | caller removed in 30.1 (45ccb1c) |
| `today_label` | lib/render.php:120 | none since import (`today_parts()` stays in use) |
| `user_blocked_ids` | includes/friends.php:528 | none since import |
| `user_search_by_username` | includes/user.php:134 | none since import (friends search uses its own query) |
| `health_record_nutrition`, `user_block_add`, `user_block_remove` | see 8.1 | dead once 8.1's endpoints go |

### 8.4 Functions used only by tools/tests — keep
- Status: VERIFIED · Verdict: **TEST-ONLY** (keep)
- Used by tests:
  - `i18n_force` (includes/i18n.php:282; 35 test uses)
  - `score_overall` (includes/scoring.php:138 ← tools/score-history-test.php:222)
  - `setup_order_rows` (includes/setup.php:176 ← tools/first-days-test.php:319)
  - `time_axis_rows` (lib/time-axis.php:202 ← tools/time-axis-test.php:117)
  - `units_in` (includes/i18n.php:560 ← tools/i18n-test.php:133,152)
  - `units_pace` (includes/i18n.php:660 ← tools/i18n-test.php:147)
  - `research_config` / `research_source_row` / `research_claim_row` / `research_config_problems` (includes/research.php:35,77,114,143 ← tools/research-registry.php, migration-028-test.php, insights-test.php)
- Used by the diagnostic CLI `tools/check-config.php`:
  - `crypto_generate_key` (includes/crypto.php:292 ← :46)
  - `ai_key_source` (includes/ai/config.php:35 ← :70,426)
  - `google_signin_config_error` (includes/google-signin.php:186 ← :578)
- Used by the mail tools:
  - `mail_send_test`, `mail_smtp_check` (includes/mailer.php:438,457 ← tools/mail-test.php:68,75 and tools/mail-service-test.php:42)
- Priority: —

### 8.5 `components/community-badges.php` is never rendered
- Status: VERIFIED · Kind: dead code (reserved placeholder)
- Evidence: `git grep community-badges` → only README.md:153 ("reserved space for badges"). The only dynamic `component()` call is `pages/health-detail.php:96` (`<area>-view`).
- Things that hang on it:
  - CSS `.card--badges` (community.css:336,341) and `.board__scroll > .card--badges` (:290)
  - config `community.badges` (config/community.php:72-75), sent in the state and parsed by Android (`AppData.kt:2458-2485`). `badgesTitle` and `badgesBody` are never read: grep finds only AppData.kt.
  - lang/en/goals.php:18-19
- Proposal: **UNCERTAIN**. The file says "deliberately not built", so the owner decides. Removing the config key changes the `state.php` payload, so it has to be coordinated with reviewer 9 (Android).
- Complexity: S · Priority: P3

### 8.6 CSS selectors that match nothing
- Status: VERIFIED · Kind: dead code · Verdict: **SAFE-TO-REMOVE** (in a grouped selector, remove only the dead selector) · Priority: P3
- Evidence: the class name occurs as no token in any non-tools PHP or JS. Dynamic prefixes were checked (string fragments ending in `-`, `--` or `__` that are concatenated).
- Classes:
  - `challenges.css`:
    - :197 `.ch-line`
    - :215 `.ch-dot--behind`, :216 `.ch-dot--neutral`
    - :822 `.ch-label`
    - :841 `.ch-hint--tight`
    - the whole block at :1053–1153: `.ch-preview-host`, `.ch-preview`, `__name`, `__line`, `.ch-chips`, `.ch-chip`, `.ch-stepper`, `__value`, `.ch-input`, `.ch-pick`, `__person`, `__check`, `__name` (most likely the earlier challenge wizard, not verified)
  - `community.css`: :331 `.board-extra`, plus `.card--badges` (8.5)
  - `compass.css`: :204 `.compass-empty`
  - `components.css`: :524 `.pill--close`, :1042 `.chips--centered`
  - `goals.css`:
    - :35 `.goals-notice`, :45 `.goals-notice__icon`
    - :547 `.goal-manage__state`, :569 `.goal-manage__state-icon`
    - :775 `.wizard__input--unit`, :784 `.wizard__field`, :786 `.wizard__row--spaced`
  - `theme.css`: :812 `.wizard-tile`, `.wizard-duration`
- Confirmed used through dynamic builders (keep):
  - `ai-msg--system`/`--user` (ai-chat.js:442)
  - `is-tone-1…4` (lib/challenges-ui.php:71,195,236)
  - `ch-icon--sm` (challenges-ui.php:131,164,220)
  - `notice-dot--corner`/`--beside` (lib/notices.php:174)
  - `wheel--list` (wheel-picker.js:207 with :37)
  - `goal-card--completed` (goal-card.php:19, pages/goals.php:113)
  - `.day-block`/`.goal-period` `is-met|missed|unknown|future|pending|skipped` (pages/goal-detail.php:419,460; includes/goal-progress.php:507-858)
- `.settings-field.is-derived` (settings.css:329): the state is supported (lib/settings.php:454,506; config/settings.php:41), but no field in config/settings.php uses `'edit' => 'derived'` (only `true` and `'locked'`). → **UNCERTAIN**: it is a supported config option.
- Limitation: a class mentioned only in a comment counts as used, so the list may be short.

### 8.7 JS that is never called
- Status: VERIFIED · Kind: dead code · Priority: P3
- Functions never called (**SAFE-TO-REMOVE**). Comments were stripped and all JS was searched:
  - `assets/js/community.js:27` `activeBoard()`
  - `assets/js/goal-wizard.js:159` `countUnit()` (added in 22.0, 016c3b6, never used)
  - `assets/js/i18n.js:171` `units.parseFeetInches` (added in 15.0, e5a7fe5; no JS caller). Its Android twin `OwnifyI18n.kt:261` `parseFeetInches` also has no caller → reviewer 9.
- Members of window-level objects never used outside their file (drop the member from the object only; the functions stay, as they are used inside):
  - `window.AppChallenges` as a whole (challenges.js:1170; "AppChallenges" occurs nowhere else)
  - `GoalWizard.close` (goal-wizard.js:1436)
  - `OwnifyRefresh.replace` / `.settle` (health-rating.js:23)
  - `OwnifyNotices.paint` / `.on` (notices.js:177; `.on()` is named in docs/NOTICES.md:80)
  - `OwnifyWheel.scan` / `columns` / `written` / `close` / `ROW` (wheel-picker.js:467-468)
  - `OwnifyPullSync.state` / `.offset` (pull-sync.js:470-472, "for the browser tests"). No browser test is in the repo, so → **UNCERTAIN** (the audit harness may use them).
- Loaded and used: all 35 JS files are loaded:
  - `index.php:279-310`
  - `components/document-head.php:193-195`
  - `pages/setup.php:243-246`
  - `pages/welcome.php:63-65`

### 8.8 Stray file: `sync-test.txt`
- Status: VERIFIED (exists, 22 bytes, "github to laptop test", in the repo root, which is deployed) · Verdict: **SAFE-TO-REMOVE** (also noted in docs/FINAL-AUDIT.md:58) · Priority: P3

### 8.9 Guards and docs noticed in passing
- `if (is_file(…/includes/insights/notify.php))` is a deploy-order guard in the dead endpoints (`api/health/nutrition.php`, `sleep.php:55`, `training.php:58`). I did not count it in other endpoints. Because notify.php is now always present → **UNCERTAIN**: enumerate every occurrence first.
- The `if (!function_exists('x'))` wrapper around each helper (e.g. lib/goals.php:393, lib/render.php:118) is the codebase-wide convention, not schema detection. Real schema-detection guards were **not enumerated** (see below).
- Docs that describe dead code:
  - `docs/DATABASE.md:912,913,915,926,837,866`
  - `docs/HEALTH-CONNECT.md:136-150,259`
  - `README.md:153`

## Not checked / could not verify (stopped early)

- Config keys never read (`config/*.php`, `config_copy()` consumers, the Android state copy). Only `community.badges` was checked.
- `lang/en` entries whose Dutch key no longer occurs. `tools/i18n-build.php` was not read.
- Assets not referenced: `assets/brand/*`, `docs/brand/*`, `favicon.ico`, `assets/research/*.pdf`.
- `uploads/avatars/u256-9d6e22d90a1b89a5{.png,-s.webp}` are committed to git. **HYPOTHESIS**: a dev-seed avatar. Check `database/seed-dev.sql` and whether deploy.yml ships `uploads/`.
- Tools that test removed features, e.g. `tools/insights-gemini-leftovers.php` (Gemini path removed in 30.1), `hc-verify.php`, `polar-fake.php`. Also test helpers defined in `tools/` that are never called (the first pass only shows that each has at least one mention, which may be a comment).
- Docs vs removed features: `docs/DESKTOP.md` vs the phone-simulator-only behaviour, Apple Health, Gemini insights, VO2 max (migration 023).
- Schema-detection / compatibility guards (`SHOW COLUMNS`, `information_schema`, table-exists checks) and the migration each one waits for. Duplicate implementations.
- `.github/scripts/*.sh` (e.g. mail-test.sh uploads a PHP file) were not read for which PHP functions they call. The functions in 8.4 are kept regardless.
- `git log` history of `sync-test.txt`. The history of the dead CSS classes was started but not read.
- Members of non-window JS objects (e.g. the other `units.*` keys in i18n.js) and inline `on…=` handlers.
- What would settle the UNCERTAIN items: the live web server's access log (8.1, 8.2), the owner's intent (8.5, `challenge_suggest_target`, `is-derived`), and the scratchpad harness scripts (OwnifyPullSync).

# Lens 9 — Dead code (Android) · database usage · personal-data map

Reviewer 9 of 9. Read-only. Evidence: `database/schema.sql`, migrations 001–031,
PHP grep (production = everything outside `tools/`), and `information_schema`
of `ownify_e2e` and `g7`. I captured that before MariaDB went down mid-review.
The column, index, FK and CHECK dumps are in scratchpad `l9/`. No screenshots
apply to this lens. The review was stopped early on the coordinator's
instruction, so Part A is only partly done (see "Not checked").

## Summary

1. **Personal data that survives account deletion.** Migration 025 copies the
   old goals into `goals_backup_024` / `goal_progress_backup_024`. These are
   plain copies with `user_id`, but with no keys and no FKs, and no migration
   ever drops them. In `g7` they hold 15 goals of 4 users and 4 progress rows.
   `DELETE FROM users` never reaches them. Live and WAMP are unknown.
2. **Leftovers from the Gemini wording of insights** (29.0, unused since 30.1):
   9 columns plus 3 consent columns. None is written. Only
   `insights_installed()` checks that they exist, and only the export reads
   `phrased_body`. They can only be removed after a code change.
3. **Redundant indexes.** Two are proven:
   - `workouts.idx_workout_user_time` has the same columns as
     `uq_workout_session`.
   - `goals.idx_goals_user_status` is a left-prefix of
     `idx_goals_user_priority`.

   Four or five more are never used by any query.
4. **schema.sql matches ownify_e2e**: 55 tables, 511 columns (names, order,
   types, nullability), every index, 68 FKs and 15 CHECKs. Only two comments
   differ. Migrations 025, 026 and 031 have no `SET NAMES utf8mb4`, and that
   left double-encoded column comments in both databases.
5. **`g7` is not migrated to 031**, although `docs/FINAL-AUDIT.md` says it is.
   It lacks everything from 027–029 and still has the `apple_health` source
   row and the backup tables.
6. **Write-only columns**: about 15. Most are harmless audit timestamps. Three
   are worth removing: `point_rules.is_active`,
   `point_events.reference_type` / `reference_id`, and
   `user_integrations.external_account_id` (personal data that is never read).
7. **Personal-data map (Part C)**: 41 tables hold account data, through
   `CASCADE` or through a parent row. The reset must also handle these by
   hand:
   - Rows outside the FKs: `auth_attempts`, `mail_outbox`,
     `account_email_conflicts`, the backup tables.
   - `challenges`: these rows survive their creator (`SET NULL`).
   - Avatar files on disk: phpMyAdmin cannot reach them.
8. **Android**: template tests, plus build entries with no use (UI tooling;
   all androidTest dependencies once the template test goes), and two
   template/leftover files. All `res/` resources are used. The Kotlin
   dead-declaration scan and the comparison of state fields were not finished.

---

## Part B — Database

### 9.1 The goal backup tables from 025 keep personal data with no FK
- Status: VERIFIED (g7) / HYPOTHESIS (live, WAMP) · Kind: defect (privacy, retention)
- Where: `database/migrations/025-goals-rebuild.sql` step 1 (`CREATE TABLE goals_backup_024 AS SELECT * FROM goals`, the same for `goal_progress`). `docs/GOALS.md:304` and `docs/DATABASE.md:505` say "to look back at or drop". No later migration, tool or code drops them (grep `backup_024`: only these 3 files). g7: 15 rows from 4 `user_id`s, and 4 progress rows.
- Problem: they hold old goals (name, targets, user_id) with no FK. `user_delete_account()` (`includes/user.php:621`, a single `DELETE FROM users`) and `data_wipe()` never reach them. A deleted account's goals stay there for good.
- Why: this contradicts "Nothing is kept aside" (`includes/user.php` docblock) and the privacy model.
- Proposal: migration 032 does `DROP TABLE IF EXISTS goals_backup_024, goal_progress_backup_024`. The rollback cannot bring them back, so take a backup first. Before the drop, check on live and WAMP: `SHOW TABLES LIKE '%backup_024'`.
- Affects: every database that ran 025 on the old goals shape (live and WAMP are likely).
- Complexity: S · Risk: none for the app (never read).
- Priority: P1 (and the Phase 7 reset must include it)

### 9.2 Leftovers from the Gemini wording of insights (29.0) — unused since 30.1
- Status: VERIFIED · Kind: dead schema (COMPATIBILITY)
- Where: the leftover columns:
  - `insight_days`: `phrase_state`, `phrase_note`, `gemini_calls`, `input_tokens`, `output_tokens`, `cached_tokens`. Zero production references to the table's columns.
  - `user_insights`: `phrased_locale` and `phrased_model` (0 references); `phrased_body` is read only by `includes/data-export.php:102`.
  - `user_profiles`: `ai_insights_consent`, `_version`, `_at`. Only the existence check in `includes/insights/lifecycle.php:48-53` (`insights_installed()` needs all 3 columns) and the 410 answer in `api/profile/privacy.php:56`.
  - Also kept alive by `tools/insights-gemini-leftovers.php`, `tools/insights-test.php`, `tools/migration-028-test.php` and `028-rollback.sql`. The schema header (lines 43–45) says "kept but no longer written".
- Problem: 12 columns of an abandoned feature. `ai_insights_consent` is a personal answer, kept with no use.
- Why: dead structure, a misleading data model, and a personal value kept for nothing.
- Proposal:
  1. First a code change: `insights_installed()` stops requiring the 3 columns, the export drops `phrased_body`, and the tests are updated.
  2. Then migration 032: `ALTER TABLE insight_days DROP phrase_state, phrase_note, gemini_calls, input_tokens, output_tokens, cached_tokens`; `ALTER TABLE user_insights DROP phrased_locale, phrased_body, phrased_model`; `ALTER TABLE user_profiles DROP ai_insights_consent, ai_insights_consent_version, ai_insights_consent_at`.
  3. Run `php tools/insights-gemini-leftovers.php` (count only) on live first.
  4. The rollback re-adds the columns empty, with the 028 definitions.
  5. `028-rollback.sql` must then stop checking `ai_insights_consent`.
- Affects: `docs/INSIGHTS.md` and `docs/DATABASE.md`, the schema header, `tools/insights-gemini-leftovers.php` (deleted with them).
- Complexity: M · Risk: if the migration runs before the code change, the insights stop (`insights_installed()` returns false).
- Priority: P2

### 9.3 Redundant indexes
- Status: VERIFIED (columns, from `information_schema.statistics` on ownify_e2e; the same in g7) · Kind: dead schema
- Where (schema.sql):
  - `workouts.idx_workout_user_time (user_id, started_at)` — `:565`; it has the same columns as `UNIQUE uq_workout_session (user_id, started_at)` (`:563`). **SAFE-TO-REMOVE.**
  - `goals.idx_goals_user_status (user_id, status)` — `:927`; it is a left-prefix of `idx_goals_user_priority (user_id, status, priority)` (`:928`), and the FK on user_id is also covered by `uq_goal_create_token`. **SAFE-TO-REMOVE.**
- Problem: double write cost on the two busiest per-user tables, for no read benefit.
- Proposal: migration 032 does `ALTER TABLE workouts DROP INDEX idx_workout_user_time; ALTER TABLE goals DROP INDEX idx_goals_user_status;` The rollback re-adds both (`ADD KEY …`).
- Complexity: S · Risk: none (the optimizer uses the covering index instead).
- Priority: P3

### 9.4 Indexes that no query uses
- Status: HYPOTHESIS (grep of every query against the table; confirm with EXPLAIN on live-sized data) · Kind: dead schema
- Where:
  - `daily_scores.idx_scores_domain_date (domain, score_date)` (`:682`): every query carries `user_id`. This index only serves queries across users, which the privacy model forbids (schema header line 57).
  - `user_insights.idx_user_insight_rule (user_id, rule_key, local_date)` (`:1434`): no WHERE on `rule_key`. Every query (`includes/insights/lifecycle.php:292-343`) uses `user_id, local_date[, slot]`, which the unique key covers.
  - `challenges.idx_challenge_end (end_date)` (`:1076`): no query filters challenges by `end_date` alone. `includes/challenges.php:524` reaches it through `challenge_members`.
  - `goal_units.idx_goal_units_canonical` (`:820`): the table is never queried at runtime (see 9.8).
  - `point_events.idx_points_user_time (user_id, awarded_at)` (`:1145`): only `points_history()` (`includes/points.php:792`) would use it, and that function is never called (see 9.11). `idx_points_user_day (user_id, awarded_on)` is likewise used only for its `user_id` prefix, which `uq_points_award` covers. UNCERTAIN.
- Proposal: drop the first four in 032 once EXPLAIN agrees, and the point_events ones only after EXPLAIN on live. The rollback re-adds each.
- Keep: `users.idx_users_status` (used in joins, low value), `user_integrations.idx_integration_status` (the cron sync in `tools/polar-sync.php` and `google-health-sync.php`), `ai_usage.idx_ai_usage_date` (`includes/ai/store.php:331`), and every FK-supporting index.
- Complexity: S · Risk: a slower query on a path grep missed.
- Priority: P3

### 9.5 schema.sql vs the migrations: comment drift and missing `SET NAMES`
- Status: VERIFIED · Kind: defect (minor)
- Where:
  - `information_schema` comparison (column types, nullability, comments, indexes, FKs, CHECKs): ownify_e2e equals schema.sql except for 2 comments.
  - (a) `sleep_stages.stage`: schema.sql `:493` adds "; Ownify: 8 restless (46.0)". No migration changes that comment.
  - (b) `challenges.metric`: in both databases the comment reads `A key of config/challenges.php â†’ metrics` (hex `C3A2E280A0E28099`, double-encoded `→`). schema.sql adds " (031)".
  - g7 has the same damage on `goals.target_value` (`â€”`, from 025) and `user_profiles.rating_notice_hour` (`â€º`, from 026).
  - Only 028 and 029 contain `SET NAMES utf8mb4`.
- Problem: a migration with non-ASCII text, run through a latin1 client, stores it garbled. Today that only hit comments. A future migration that seeds non-ASCII data (labels with ë, –) would garble that data.
- Proposal: begin every migration from 032 with `SET NAMES utf8mb4;`. 032 can restate the two comments (`MODIFY … COMMENT '…'`). Keep schema.sql's stage comment and give it a migration line.
- Affects: 025, 026, 031 (already run; no data damage found in the rows I checked — UNCERTAIN, see Not checked).
- Complexity: S · Risk: none.
- Priority: P3

### 9.6 `g7` is not migrated to 031, and still has removed-feature rows
- Status: VERIFIED · Kind: environment / documentation defect
- Where:
  - g7 has 44 tables. It lacks all 027 tables (`pending_registrations`, `account_emails`, `account_email_conflicts`, `auth_email_tokens`, `email_changes`, `email_change_google_baseline`, `mail_outbox`), the 028 tables (`research_sources`, `research_claims`, `insight_days`, `user_insights`) and the 029 tables (`correlation_runs`, `user_correlations`). It also lacks `users.session_generation` and the 3 `ai_insights_consent*` columns.
  - It does have 030 (`health_import_removed`, `corrected_at`) and 031.
  - Its `data_sources` still holds `apple_health`, which 028 removes.
  - It has the 025 backup tables.
  - `docs/FINAL-AUDIT.md:52` says "both migrated to 031".
- Why: tests or rehearsals run on g7 do not represent live. It also shows that migrations can be applied out of order without anything noticing.
- Proposal: correct FINAL-AUDIT. If g7 is meant to rehearse live, run 027–029 on it (owner's decision; not done here).
- Complexity: S · Risk: none.
- Priority: P2

### 9.7 Write-only columns (written, never read by production code)
- Status: VERIFIED by grep (production code outside `tools/`) · Kind: dead schema
- **Candidates for 032** (SAFE-TO-REMOVE after a code change):
  - `point_rules.is_active`: always written 1 (`includes/points.php:710`), never read.
  - `point_events.reference_type`, `reference_id`: written at `includes/points.php:634-639`, never read; `award_key` carries the same.
  - `user_integrations.external_account_id`: written at `includes/integrations.php:285-302`, never read. It is personal data (the Google/Polar account id).
- **Also write-only**:
  - `point_rules.domain`, `points`, `cadence`: `config/points.php` is the truth. App-created rows insert `points = 0`, while the seed has 45/50/75, so the data is inconsistent (UNCERTAIN; harmless).
- **Write-only audit timestamps** (keep; cheap, useful for support and cleanup):
  - `goals.progress_at`
  - `user_login_tokens.created_at`, `last_used_at`
  - `email_changes.requested_at`
  - `mail_outbox.created_at`
  - `health_import_removed.removed_at`
  - `research_sources.synced_at` and `research_claims.retired_at` (tools only)
  - `correlation_runs.duration_ms`
  - `account_email_conflicts.reason`, `user_ids`, `found_at` (read by a person in phpMyAdmin only)
- Proposal: 032 drops the 4 candidates above, and the code stops writing them in the same release. The rollback re-adds them empty.
- Complexity: S · Risk: low.
- Priority: P3

### 9.8 Goal vocabulary tables mirror `config/goals.php` and are never queried at runtime
- Status: VERIFIED · Kind: duplicate implementation (keep)
- Where: `goal_categories`, `goal_units` and `goal_measurements` have no production SELECT (grep). `includes/goal-catalogue.php` reads `config/goals.php`. Only `tools/goal-engine-test.php:158-182` compares the database with config. The `allows_*`, `sort_order`, `factor`, `canonical`, `direction`, `source_*` columns are therefore test-only.
- Proposal: keep them. They are the FK targets that keep `goals.category_code`, `measurement_code` and `unit_code` honest. Document that config is the truth (already in `config/goals.php:24`).
- Priority: P3 (informational)

### 9.9 Seed rows of removed or never-used features
- Status: VERIFIED (grep) / UNCERTAIN (row references on live) · Kind: dead data
- `data_sources 'wearable'` (`schema.sql:1274`): no code names it, and no import path can resolve it (sources come from `user_devices.provider`, `'google_health'`, `'polar'`, `'manual'` or `'derived'`). Proposal: 032 deletes it, guarded by NOT EXISTS over the 6 `source_id` tables, as 028 did for `apple_health`.
- Already clean: VO2 max (023 removed the type; 0 references in PHP or Kotlin) and Apple Health (028 removed the row; schema.sql has neither).
- Still used: `readiness`, `training_load` (`config/health.php:94-95`, `lib/hydrate-health.php:292`).
- Priority: P3

### 9.10 Missing or loose foreign keys
- Status: VERIFIED (schema) · Kind: integrity
- Missing:
  - `auth_email_tokens.change_id` has no FK to `email_changes.id`. Both cascade from users, so only stale links are possible.
  - `account_email_conflicts.user_ids` is a text list with no FK: it survives the accounts it names (see Part C).
- Intentional (keep):
  - `email_change_google_baseline.identity_id` (by design, `027` §7b).
  - The `provider` columns of `user_integrations`, `user_devices`, `device_pairing_codes` and `integration_oauth_states` (match `data_sources.code` without an FK).
  - `point_events.reference_id` (by its comment) and `challenges.metric` (a config key).
- Possible leftover, HYPOTHESIS: 027 §8b mentions a `mail_outbox.claimed_at` column "from an older draft… left alone". It is not in ownify_e2e or g7. Check live with `SHOW COLUMNS FROM mail_outbox`; 032 could drop it, guarded.
- Priority: P3

### 9.11 Related dead code found in passing (for lens 8)
- `points_history()` (`includes/points.php:792`) has no caller, not even in tests. It is the only reader of `point_rules.label` alongside the feedback path, and the only user of `idx_points_user_time`. SAFE-TO-REMOVE (lens 8 to confirm).
- `data_wipe()` (`includes/data-export.php` `data_wipe_tables()`) leaves `health_import_removed`. That may be deliberate (removed trainings must not come back), but it should be documented.
- The schema's PRIVACY MODEL list (`schema.sql:32-37`) leaves out `heart_rate_minutes`, `sleep_stages`, `health_metric_day_totals`, `health_import_removed` and the identity/security tables.

### What a migration 032 (+ rollback) would contain
Each step in 032, with what the rollback does:

1. `SET NAMES utf8mb4;` at the top.
   - Rollback: nothing.
2. Drop the backup tables (9.1), guarded with IF EXISTS, after a backup.
   - Rollback: cannot restore them; it says so.
3. Drop `workouts.idx_workout_user_time` and `goals.idx_goals_user_status` (9.3).
   - Rollback: `ADD KEY` both.
4. Optional: drop the unused indexes from 9.4 after EXPLAIN.
   - Rollback: re-add them.
5. Delete the `wearable` row (9.9), guarded by NOT EXISTS.
   - Rollback: `INSERT IGNORE` it.
6. Optional: drop the 4 write-only columns (9.7).
   - Rollback: re-add them nullable.
7. Restate the two column comments (9.5).
8. Gemini columns (9.2): only in a release whose code no longer needs them.
   - Rollback: re-add them empty, and loosen the check in `028-rollback.sql`.

The style is that of 030 and 031: each step checks first, re-running is safe, and there is no `USE`.

---

## Part C — Personal-data map (input for the Phase 7 reset; no SQL that changes data)

Account deletion today: `user_delete_account()` (`includes/user.php:621-660`)
runs one `DELETE FROM users` and relies on CASCADE. It then deletes the avatar
files `uploads/avatars/u{id}-*`, which includes the `-s.webp` small copy.

**Personal tables, directly on `users` (ON DELETE CASCADE):**

| Group | Tables |
|---|---|
| Identity and profile | `user_profiles`, `user_auth_identities`, `user_login_tokens` |
| Account security | `pending_registrations`, `account_emails`, `auth_email_tokens`, `email_changes` |
| Health records | `user_measurements`, `sleep_sessions`, `heart_rate_minutes`, `nutrition_entries`, `workouts`, `health_import_removed`, `health_metrics`, `health_metric_day_totals`, `daily_scores` |
| Connections and devices | `user_integrations` (encrypted tokens), `integration_oauth_states`, `device_pairing_codes`, `user_devices` |
| Goals | `goals` |
| Community | `friendships` (`user_low_id`, `user_high_id` and `requested_by` all CASCADE), `user_blocks` (both columns), `challenge_members.user_id` |
| Points and boards | `point_events`, `user_period_points`, `leaderboard_best_positions` |
| OwnifyAI | `ai_conversations`, `ai_messages` (also through its conversation), `ai_usage` |
| Insights | `insight_days`, `user_insights`, `correlation_runs`, `user_correlations` (also through its run) |

**Personal tables reached through a parent row (CASCADE):**

| Table | Parent |
|---|---|
| `email_change_google_baseline` | `email_changes` |
| `sleep_stages` | `sleep_sessions` |
| `workout_hr_zones` | `workouts` |
| `goal_entries`, `goal_day_results`, `goal_period_results`, `goal_progress` | `goals` |
| `health_metrics` (its context columns) | `sleep_sessions`, `workouts`, `nutrition_entries` |

**SET NULL — the row stays when its user goes:**
- `challenges.creator_id`. The challenge, with its user-written `name` and `code`, outlives everybody.
- `challenge_members.invited_by`.

Orphan risk after a full reset: every `challenges` row is left with no members.

**Personal data with no `user_id` FK** (CASCADE never reaches it; delete it explicitly):

| Table | What it holds, and why it is personal |
|---|---|
| `auth_attempts` | SHA-256 of the typed name or e-mail plus the IP. No link to an account, but personal by IP. |
| `mail_outbox` | `recipient` (an e-mail address) and an encrypted `payload` holding the username and a link. |
| `account_email_conflicts` | `email` and `user_ids` as text. |
| `goals_backup_024`, `goal_progress_backup_024` | Where present (9.1). |
| `users` | `username` (public). |
| `users` with `status = 'pending'` | Unfinished registrations, together with `pending_registrations`. |

**Infrastructure and reference data — keep:**
- `data_sources`, `health_metric_types`
- `goal_categories`, `goal_units`, `goal_measurements`
- `point_rules`
- `research_sources`, `research_claims` (`user_insights.claim_id` is RESTRICT, so the insights go first)
- `ai_service_state`: facts about Gemini's quota, about nobody; it may be kept or emptied.
- There is no migration bookkeeping table.

**Safe deletion order** (leaves first, so it also works without relying on CASCADE; a plain `DELETE FROM users` does steps 1–10 by itself):
1. `email_change_google_baseline`, then `auth_email_tokens`, then `email_changes`.
2. `user_correlations`, then `correlation_runs`. Then `user_insights`, then `insight_days`.
3. `ai_messages`, then `ai_conversations`. Then `ai_usage`.
4. `goal_entries`, `goal_day_results`, `goal_period_results`, `goal_progress`, then `goals`.
5. `health_metric_day_totals`, then `health_metrics` (before its parents). Then `sleep_stages`, then `sleep_sessions`. Then `workout_hr_zones`, then `workouts`. Then `nutrition_entries`, `heart_rate_minutes`, `user_measurements`, `health_import_removed`, `daily_scores`.
6. `point_events`, `user_period_points`, `leaderboard_best_positions`.
7. `challenge_members`, then **all** `challenges`, explicitly (because of SET NULL).
8. `friendships`, `user_blocks`.
9. `user_devices`, `device_pairing_codes`, `integration_oauth_states`, `user_integrations`.
10. `user_login_tokens`, `pending_registrations`, `account_emails`, `user_auth_identities`, `user_profiles`, then `users`.
11. The tables with no FK: `auth_attempts`, `mail_outbox`, `account_email_conflicts`, and the backup tables (empty or drop).
12. Reference tables: untouched.

Use DELETE, not TRUNCATE: TRUNCATE fails on tables that an FK references. AUTO_INCREMENT keeps counting, so avatar names `u{id}-` never collide.

**Outside the database (phpMyAdmin cannot reach these):**
- `uploads/avatars/u*-*` (`.png`/`.jpg`, plus the `-s.webp` small copy) on live (Hestia web root) and on WAMP (`C:\wamp64\www\Ownify\uploads\avatars`). `deploy.yml` never deletes them, and `.gitignore` keeps them out of git. Remove them by SFTP or the file manager, and keep `.gitkeep`.
- PHP session files and the server error log (`error_log` lines carry user ids, e.g. `data wipe failed for user N`).
- `ownify-ai-diagnostic.log` if it was switched on; by design it holds no ids or text.

On the phones, the Android app's account tokens stop working once `user_devices` is gone. Health Connect data on the phone is not touched.

---

## Part A — Android (partial)

### 9.12 Template tests and dependencies that only they need
- Status: VERIFIED · Kind: dead code (TEST-ONLY)
- Where:
  - `app/src/test/java/com/ownify/android/ExampleUnitTest.kt` (asserts `2 + 2 = 4`).
  - `app/src/androidTest/java/com/ownify/android/ExampleInstrumentedTest.kt`, the only androidTest source.
  - `app/build.gradle.kts`: `testInstrumentationRunner`, and the `androidTestImplementation` entries `compose.ui.test.junit4`, `espresso-core` and `androidx.junit`.
  - The matching catalog entries in `gradle/libs.versions.toml` (`espressoCore`, `junitVersion`).
- Proposal: delete both template tests and the androidTest dependencies. **SAFE-TO-REMOVE.**
- Complexity: S · Risk: none (no emulator tests exist).
- Priority: P3

### 9.13 Unused UI tooling dependencies
- Status: VERIFIED by grep (0 `@Preview`, 0 `androidx.compose.ui.tooling` imports in main or test) · Kind: unused dependency
- Where: `app/build.gradle.kts` has `implementation(libs.androidx.compose.ui.tooling.preview)` and `debugImplementation(libs.androidx.compose.ui.tooling)`. Keep `ui-test-manifest`, which the Robolectric Compose tests need.
- Proposal: remove both. **SAFE-TO-REMOVE** (confirm with one build in Phase 6).
- Also: `lifecycle-runtime-ktx` has no `lifecycleScope` or `repeatOnLifecycle`. The `Lifecycle` types arrive transitively. **UNCERTAIN** (cosmetic).
- Priority: P3

### 9.14 Template and leftover files
- Status: VERIFIED · Kind: dead file
- `app/src/main/keepRules/rules.keep`: the AGP template, comments only, while `optimization { enable = false }`. **SAFE-TO-REMOVE** (UNCERTAIN whether AGP 9 wants the folder).
- `app/src/main/ic_launcher-playstore.png`: outside `res/`, so never packaged. It is the Image Asset wizard's leftover, and `tools/brand-icons.py` makes the icons. **UNCERTAIN** (it may be wanted as source art).
- Resources checked and all used: `strings.app_name`, the 3 colours, the 3 themes, `drawable-nodpi/ownify_logo.webp` (`Welcome.kt:212`, `AssistantSheet.kt:399`), the mipmaps, both backup XMLs, `assets/lang-en.json` (`OwnifyI18n.kt:60`).
- Priority: P3

---

## Not checked / could not verify

- **MariaDB went down mid-review** (socket refused; I did not start it). So none of these were checked:
  - Row counts per table in ownify_e2e.
  - EXPLAIN for the indexes in 9.4.
  - Live use of the `wearable` row.
  - Values left in the Gemini columns.
  - A byte-aligned check of the research rows: a rough HEX check flagged 4 sources and 5 claims, possibly false positives.

  These need the database back up, SELECT/EXPLAIN only.
- **Live and WAMP databases**: no access. Not checked: whether the backup tables exist there, `mail_outbox.claimed_at`, `apple_health` / `wearable` references, and the actual avatar files.
- **Android, not finished**:
  - The scan for unreferenced Kotlin functions, composables and classes was started but not read.
  - Screens, overlays and details that can no longer be reached.
  - The comparison of the parsing keys in `data/*.kt` (`AppData.kt`, 3,213 lines) with `lib/hydrate-*.php` / `api/app/state.php` and `$A/android/state-dev_jij.json`.
  - Compatibility code for older servers.
  - Duplicate implementations.
  - Manifest permissions against the record types read (e.g. `READ_BODY_TEMPERATURE`).

  What is needed: one more pass of the declaration scan, and a key diff of the state JSON against the parsers.
- No Gradle build was run (forbidden), so 9.12–9.14 are not proven by a build.

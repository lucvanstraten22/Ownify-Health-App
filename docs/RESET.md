# The user-data reset (Phase 7)

Every account and everything that belongs to one is deleted from **two**
databases, the same way: the live database on Hestia and the primary
database on the WAMP machine. Everything else stays: the tables themselves
and their foreign keys, the research sources and claims, the metric types,
the goal vocabulary, the point rules, the data sources, the AI pace window,
and every file of the app — configuration, sign-in with Google, mail
(`config/*.local.php` are not touched; the reset is SQL only). The two test
databases, `g7` and `ownify_e2e`, are never touched: every script stops
at its first line in any database but its own.

The scripts are in `database/reset/`, one folder per database, four files
each, imported in order:

| Step | File | Changes |
| --- | --- | --- |
| 1 | `1-precheck.sql` | nothing — shows the database, the migration status, every table and its rows |
| 2 | `2-migration-032.sql` | drops the two old goal copies (`goals_backup_024`, `goal_progress_backup_024`) |
| 3 | `3-reset.sql` | empties the 46 personal tables, in one transaction |
| 4 | `4-verify.sql` | nothing — proves the database is clean, complete and ready |

`database/reset/luc_ownify/` is for Hestia, `database/reset/ownify/` for the
WAMP machine. They differ only in the name they insist on.

## What is deleted, and what stays

**Deleted — every row of these 46 tables:** `users` and everything hung on
an account: `user_profiles`, `user_auth_identities` (Google sign-in links),
`account_emails`, `pending_registrations`, `auth_email_tokens`,
`email_changes`, `email_change_google_baseline`, `account_email_conflicts`,
`user_login_tokens` (stay-signed-in), `user_devices` (the app's tokens and
phones), `device_pairing_codes`, `auth_attempts` (sign-in throttle),
`mail_outbox` (unsent mail, with addresses), `user_integrations`,
`integration_oauth_states`; all health data — `health_metrics`,
`health_metric_day_totals`, `heart_rate_minutes`, `sleep_sessions`,
`sleep_stages`, `workouts`, `workout_hr_zones`, `nutrition_entries`,
`user_measurements`, `health_import_removed`; what was worked out from it —
`daily_scores`, `insight_days`, `user_insights`, `correlation_runs`,
`user_correlations`, `point_events`, `user_period_points`,
`leaderboard_best_positions`; goals — `goals`, `goal_entries`,
`goal_day_results`, `goal_period_results`, `goal_progress`; the social
side — `friendships`, `user_blocks`, `challenges`, `challenge_members`;
OwnifyAI — `ai_conversations`, `ai_messages`, `ai_usage`.

**Kept — these 9 tables, row for row:** `research_sources`,
`research_claims`, `health_metric_types`, `data_sources`, `goal_categories`,
`goal_units`, `goal_measurements`, `point_rules`, `ai_service_state` (the
shared five-a-minute window; timestamps only).

**Files:** profile pictures are files, not rows — `uploads/avatars/` is
emptied by hand (below). Nothing else of a person's is kept in a file: the
data export is made on request and never stored.

**The account numbers go on where they were.** The reset deletes rows and
leaves the counter of `users` as it is, so the first new account does not
get the number of an old one. That is what makes a browser that was signed
in before the reset simply signed out afterwards (tested), never signed in
as somebody new.

## Before you start

1. **Which database.** In phpMyAdmin the database is in the left sidebar.
   - Hestia: **`luc_ownify`** (from `config/database.local.php.example` and
     the migration notes).
   - WAMP: **`ownify`** is the default, but this could not be checked from
     here: open `C:\wamp64\www\Ownify\config\database.local.php` and look at
     `'database' =>`. If it is not `ownify`, stop and tell me the name — the
     scripts would refuse to run anyway.
2. **A quiet moment.** Everybody is signed out, on the website and in the
   app, the moment step 3 commits.

## The backup — one per database, before anything else

In phpMyAdmin, with the database selected in the sidebar:

1. **Export** → Export method **Custom**.
2. Format **SQL**. Under *Output*: **Save output to a file**, compression
   **gzipped**.
3. Under *Object creation options*: tick **Add DROP TABLE / VIEW / PROCEDURE
   / FUNCTION / EVENT / TRIGGER statement** (so the backup can be imported
   back over the database), and keep **structure and data**.
4. **Export.** Name it after the database and the date
   (`luc_ownify-2026-11-13.sql.gz`).
5. **Protect it at once:** it holds every account's personal data. Keep it
   on your own computer only — never on the server or anywhere under a web
   root — and put it in an encrypted archive (7-Zip → *Add to archive* →
   format 7z, **AES-256**, *Encrypt file names*, a long password kept in
   your password manager). Delete the unencrypted `.sql.gz` (and empty the
   recycle bin).
6. **Check it:** 7-Zip → *Test archive* must say no errors, and the file must
   not be tiny (a few kilobytes means it is empty).

Do the same for the other database. Both backups exist before step 2 is
imported anywhere.

## The reset, per database

phpMyAdmin, the database selected in the sidebar first, then **Import** →
choose the file → leave everything as it is → **Import** (phpMyAdmin stops
at the first error, which is what the guards rely on).

1. **`1-precheck.sql`** — read what it shows:
   - `database` is the one you mean;
   - every migration says **imported**, and 032 says **not yet: step 2** (or
     *imported*);
   - the list *unknown_table* is **empty** and *missing_table* is **empty**.
     If either lists anything, stop and send me that list (table names
     only); step 3 would refuse anyway.
2. **`2-migration-032.sql`** — shows "dropping" (or "not there") for the two
   old goal copies.
3. **`3-reset.sql`** — ends with **reset done**, `personal_rows_left` **0**,
   and `next_user_id` equal to `next_user_id_before`.
4. **`4-verify.sql`** — ends with **verified: clean, complete and ready for
   new accounts**.

**If an import stops with an error**, the error names the reason in its
column, for example `Unknown column 'STOP_this_is_not_luc_ownify'` (the
wrong database is selected) or `STOP_import_step_2_migration_032_first`.
Nothing has changed then: the guards run before anything is deleted, and
step 3 deletes inside one transaction that is only kept once its own checks
pass (every personal table empty, every global count unchanged).

## Afterwards

1. **Profile pictures.** Empty the folder, keep the folder and its
   `.gitkeep`:
   - Hestia: File Manager → `/home/luc/web/ownify.acits.nl/public_html/uploads/avatars/`
     → select every file except `.gitkeep` → Delete.
   - WAMP: `C:\wamp64\www\Ownify\uploads\avatars\` → the same.
   Keep `uploads/.htaccess`.
2. **Only if you ever switched on the AI diagnostic log**
   (`diagnostic_log` in `config/ai.local.php`): delete
   `ownify-ai-diagnostic.log` from the server's temporary folder.
3. **A disposable account, on each site:** register a new account with an
   address of your own, check that the setup opens, sign out, sign in again,
   then delete it under Instellingen → Account verwijderen. On the website
   the address gets the usual verification mail if verification is on there.
4. **Import `4-verify.sql` once more** — it must say *verified* again (the
   disposable account was deleted with everything it had).
5. **The backups.** Keep the encrypted archives until you are satisfied the
   sites work — a day is a reasonable window to notice a problem — then
   delete them and empty the recycle bin. There is no way back after that:
   that is the point of the reset.

## Also on Hestia: the old database

The migration notes (`docs/OWNIFY-MIGRATION.md`) left the database of before
the rename, `luc_healthapp`, untouched, to be dropped later. If it still
exists under Hestia → **DB**, it holds every account of before the rename.
This reset does not touch it (no DROP DATABASE, as agreed); deleting it
there is the way to have no copy left.

## Rehearsed

On copies of the development database (5 accounts, 39 tables with rows,
both goal copies added) named `ownify` and `luc_ownify`, in MariaDB 10.11:

- the guards: the Hestia scripts in the WAMP-named copy, step 3 before
  step 2, an unknown table, and every script in `ownify_e2e` — each stopped
  at its first check, nothing changed;
- the backup: a full dump, restored into a third database — 57 tables,
  every row back;
- steps 1–4 on both copies: 46 personal tables empty, 55 tables and 68
  foreign keys left, the 9 global tables byte for byte the same as before
  (CHECKSUM TABLE), `mysqlcheck` clean; steps 2–4 again: no change;
- the app on the reset copy: a browser signed in before is signed out; the
  app's token from before is refused (401); an old account cannot sign in;
  a new account registers (number 148, the counter kept), opens on its
  setup, signs in again, and the app signs it in and loads its state.

Not tested here: the live servers themselves (MySQL on WAMP may be MySQL
rather than MariaDB — the scripts use nothing either lacks), and phpMyAdmin
itself; the mysql command line was used, which stops at the first error in
the same way.

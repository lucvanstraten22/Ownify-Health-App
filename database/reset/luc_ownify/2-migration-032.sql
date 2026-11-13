-- ============================================================================
--  Ownify user-data reset — luc_ownify — step 2: migration 032 (the two old goal copies)
-- ----------------------------------------------------------------------------
--  For the live Ownify database on Hestia (ownify.acits.nl) ONLY. The first statement stops the import in any
--  other database (the test databases g7 and ownify_e2e included).
--  phpMyAdmin: select `luc_ownify` in the sidebar FIRST, then Import this file.
--  Instructions: docs/RESET.md. NO `USE` statement and no DROP DATABASE.
-- ============================================================================

-- 0. The right database, or nothing happens.
SET @stmt := IF(DATABASE() = 'luc_ownify', 'SELECT 1 AS ok', 'SELECT `STOP_this_is_not_luc_ownify`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
--  032 — The old goals' copies go (50.0)
-- ----------------------------------------------------------------------------
--  Migration 025 (22.0) copied the goals of before the goal rebuild, and their
--  progress, to goals_backup_024 and goal_progress_backup_024: plain copies
--  "to look back at or drop". Nothing reads them — no page, endpoint, cron,
--  tool, test or app — and the old goals cannot be carried into the new model
--  (their types and sources mean other things now). They only keep old
--  personal data, so they are dropped.
--
--  ONLY these two tables are touched. A database that never had them (one
--  made from schema.sql, or 025 run on an empty `goals`) is left as it is.
--  Re-running it is safe.
--
--  Take a backup first: what these tables hold cannot be made again
--  (032-rollback.sql brings the tables back empty, not their rows).
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
-- ============================================================================

-- What is there before (shown, not kept): the rows each copy holds.
SET @g := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals_backup_024');
SET @p := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_progress_backup_024');
SELECT IF(@g = 1, 'goals_backup_024: dropping', 'goals_backup_024: not there') AS note
UNION ALL
SELECT IF(@p = 1, 'goal_progress_backup_024: dropping', 'goal_progress_backup_024: not there');

DROP TABLE IF EXISTS `goal_progress_backup_024`;
DROP TABLE IF EXISTS `goals_backup_024`;

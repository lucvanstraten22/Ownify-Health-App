-- ============================================================================
--  001 — goal priority, and activity level on the profile
-- ----------------------------------------------------------------------------
--  Run this against a database created before these columns existed. A fresh
--  import of database/schema.sql already contains them, so this file is only
--  for upgrading in place.
--
--  Both statements are safe to run twice: each checks for its own column
--  first, so re-running does nothing rather than failing.
-- ============================================================================
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
-- ----------------------------------------------------------------------------
--  The database is whichever one you have selected. On shared hosting the
--  name is not ours to choose — Hestia prefixes it with the account, so it is
--  `luc_ownify` there and something else on the next server. Naming one
--  here would make this file work in exactly one place.
--
--  phpMyAdmin:  select the database in the sidebar FIRST, then Import.
--  Command line: name it as an argument, e.g.
--      mysql -u USER -p DATABASE < database/migrations/001-priority-and-activity-level.sql
-- ----------------------------------------------------------------------------


-- Goals carry a priority. The board allows one primary and two secondaries,
-- which is a rule the application enforces on write; the column only records
-- which one a goal is.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals' AND COLUMN_NAME = 'priority') > 0,
    'SELECT "goals.priority already present" AS note',
    'ALTER TABLE `goals`
        ADD COLUMN `priority` ENUM(''primary'',''secondary'') NOT NULL DEFAULT ''secondary''
            COMMENT ''One primary per user; the app enforces the limit'' AFTER `goal_type`,
        ADD KEY `idx_goals_user_priority` (`user_id`, `status`, `priority`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Activity level belongs to the person, not to a measurement: it is a
-- self-declared setting, so it sits on the profile rather than in
-- user_measurements.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'activity_level') > 0,
    'SELECT "user_profiles.activity_level already present" AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `activity_level` ENUM(''sedentary'',''light'',''moderate'',''active'',''athlete'')
            NULL COMMENT ''Self-declared; drives targets, not a measurement'' AFTER `gender`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

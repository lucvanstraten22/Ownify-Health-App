-- ============================================================================
--  030 ROLLBACK — sleep and training entered by hand
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: deploy the previous code and keep
--  the schema. Older code never reads the column or the table.
--
--  This file drops them. The nights and trainings entered by hand stay (they
--  are ordinary rows); a corrected night may get the device's times back on
--  the next sync, and a removed Health Connect training may come back.
--
--  Take a backup first.
-- ============================================================================

DROP TABLE IF EXISTS `health_import_removed`;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'sleep_sessions' AND column_name = 'corrected_at');
SET @sql := IF(@has = 1, 'ALTER TABLE `sleep_sessions` DROP COLUMN `corrected_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
--  032 ROLLBACK — the old goals' copies
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: no code reads these tables, so the
--  previous code runs on a database without them exactly as before.
--
--  The rows cannot come back from here: 032 dropped them. To have them again,
--  restore the two tables from the backup taken before 032 was imported.
--  This file only puts the two names back, empty, for a tool that expects
--  them to exist (none in this repository does).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `goals_backup_024` (
    `id` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_progress_backup_024` (
    `id` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

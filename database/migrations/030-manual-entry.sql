-- ============================================================================
--  030 — sleep and training entered by hand, and a training removed (40.0)
-- ----------------------------------------------------------------------------
--  Additive only (docs/MANUAL-ENTRY.md):
--
--    sleep_sessions.corrected_at
--                        when the person entered this night's bedtime and
--                        wake-up time by hand (Slaap's +). A sync keeps
--                        those two times and what follows from them; it
--                        still brings the device's own measurements (the
--                        stages). NULL: the times are the device's.
--    health_import_removed
--                        a record from outside Ownify (Health Connect,
--                        Polar) the person removed in Ownify: its source and
--                        id, so the next sync, which sends the last days
--                        again, does not bring it back. Only Ownify's copy
--                        was removed; the record itself stays where it came
--                        from.
--
--  Until this is imported the + buttons stay away and removing a training is
--  not offered (health_manual_ready()); nothing else changes.
--
--  NO `USE` STATEMENT. phpMyAdmin: select the database first. Back up first.
--  Command line: mysql -u USER -p DATABASE < this file. Re-running it is safe.
-- ============================================================================

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'sleep_sessions' AND column_name = 'corrected_at');
SET @sql := IF(@has = 0,
    'ALTER TABLE `sleep_sessions` ADD COLUMN `corrected_at` DATETIME NULL
        COMMENT ''When the bedtime and wake-up time were entered by hand; a sync keeps them'' AFTER `rem_minutes`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `health_import_removed` (
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `source_id`   SMALLINT UNSIGNED NOT NULL,
    `kind`        ENUM('workout') NOT NULL,
    `external_id` VARCHAR(191) NOT NULL COMMENT 'Its id in the system it came from',
    `removed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `source_id`, `kind`, `external_id`),
    CONSTRAINT `fk_removed_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_removed_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

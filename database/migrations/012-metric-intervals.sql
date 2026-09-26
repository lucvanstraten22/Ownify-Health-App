-- ============================================================================
--  012 — which app a reading came from, and the time it covers
-- ----------------------------------------------------------------------------
--  Health Connect gives every step, distance and calorie record a start and
--  an end, and the app that wrote it (its package name, `dataOrigin`). JoLu
--  kept only the end, so when two apps recorded the same walk — the phone
--  and a watch — both records were added up: 5.000 + 4.900 = 9.900 steps.
--
--  These two columns keep what the phone already sends:
--
--    started_at   the start of the interval the value covers (recorded_at is
--                 its end). NULL for a reading at one moment — a weight, a
--                 resting heart rate, anything typed in by hand.
--    data_origin  the Health Connect app that wrote it, e.g.
--                 com.google.android.apps.fitness. NULL for anything that did
--                 not come from Health Connect.
--
--  With them, a day's total counts overlapping records once, the way Health
--  Connect itself does (includes/health-totals.php, health_metric_totals()).
--  Nothing is deleted: every record stays as it arrived.
--
--  And one table, health_metric_day_totals: each day's total as that rule
--  worked it out, kept so a year-long goal does not work out a year of
--  minute-by-minute records again on every page. Every row carries a
--  fingerprint of the readings it came from; when they change, the total is
--  worked out again the next time it is read. It holds nothing that cannot be
--  rebuilt — emptying it only costs time.
--
--  Rows imported before this keep working exactly as they did, and the
--  phone's next sync fills both columns in for the days it sends again.
--
--  Until this is imported the app runs as before: totals are plain sums.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'health_metrics'
        AND column_name = 'data_origin') > 0,
    'SELECT ''health_metrics.data_origin already there'' AS note',
    'ALTER TABLE `health_metrics`
        ADD COLUMN `data_origin` VARCHAR(191) NULL DEFAULT NULL
            COMMENT ''The Health Connect app that wrote it (package name); NULL when not from Health Connect''
            AFTER `source_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'health_metrics'
        AND column_name = 'started_at') > 0,
    'SELECT ''health_metrics.started_at already there'' AS note',
    'ALTER TABLE `health_metrics`
        ADD COLUMN `started_at` DATETIME NULL DEFAULT NULL
            COMMENT ''Start of the interval the value covers (recorded_at is its end); NULL for a reading at one moment''
            AFTER `value`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `health_metric_day_totals` (
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `metric_type_id` SMALLINT UNSIGNED NOT NULL,
    `day`            DATE NOT NULL,
    `total`          DECIMAL(14,4) NULL COMMENT 'NULL: nothing counted on this day',
    `readings_hash`  CHAR(32) NOT NULL COMMENT 'Fingerprint of the readings and the rule it was worked out from',
    `computed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `metric_type_id`, `day`),
    CONSTRAINT `fk_hmdt_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hmdt_type` FOREIGN KEY (`metric_type_id`)
        REFERENCES `health_metric_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

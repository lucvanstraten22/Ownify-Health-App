-- ============================================================================
--  010 — the Health Score and leaderboard points
-- ----------------------------------------------------------------------------
--  Two separate systems, and this prepares the database for both:
--
--    Health Score   how healthy the last 90 days were. Calculated from the
--                   health records themselves; daily_scores keeps what each
--                   calculation found, and what it was based on.
--
--    Points         what somebody did — a night, a rating, a workout, a step
--                   count, three workouts in a week. point_events is the
--                   ledger, and from now on every award names the event it
--                   pays for in `award_key`, which is unique per person. A
--                   sync that sends the same workout three times therefore
--                   pays once: the database refuses a second row.
--
--  Until this is imported the app runs as before: scores are calculated and
--  shown, points are not awarded, and the server log says why.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe. It needs nothing newer than schema.sql.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  1. point_events: one row per event and rule, never two
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'point_events'
        AND column_name = 'award_key') > 0,
    'SELECT ''point_events.award_key already there'' AS note',
    'ALTER TABLE `point_events`
        ADD COLUMN `award_key` VARCHAR(120) NULL
            COMMENT ''The event and rule this pays for, e.g. workout:123 — unique per person''
            AFTER `rule_id`,
        ADD COLUMN `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            COMMENT ''Set when the award was re-evaluated, e.g. a step tier reached later''
            AFTER `created_at`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A step count belongs to a day and the weekly bonus to a week, not to a row.
ALTER TABLE `point_events`
    MODIFY COLUMN `reference_type`
        ENUM('sleep_session','workout','nutrition_entry','metric','manual','day','week') NULL;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'point_events'
        AND index_name = 'uq_points_award') > 0,
    'SELECT ''uq_points_award already there'' AS note',
    'ALTER TABLE `point_events` ADD UNIQUE KEY `uq_points_award` (`user_id`, `award_key`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Rebuilding a month or a year reads a range of days.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'point_events'
        AND index_name = 'idx_points_day') > 0,
    'SELECT ''idx_points_day already there'' AS note',
    'ALTER TABLE `point_events` ADD KEY `idx_points_day` (`awarded_on`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
--  2. point_rules: what can earn points. The values themselves live in
--     config/points.php, so they can be tuned without a migration; this is
--     the catalogue every award in the ledger points back to. `points` is
--     the most one event can earn under that rule.
-- ---------------------------------------------------------------------------
INSERT INTO `point_rules` (`code`, `label`, `domain`, `points`, `cadence`, `is_active`) VALUES
    ('sleep_duration',    'Nachtrust',              'sleep',     45, 'daily',     1),
    ('sleep_regularity',  'Regelmatig geslapen',    'sleep',     10, 'daily',     1),
    ('sleep_quality',     'Goed geslapen',          'sleep',     10, 'daily',     1),
    ('nutrition_rating',  'Voeding beoordeeld',     'nutrition', 50, 'daily',     1),
    ('workout',           'Training',               'training',  45, 'per_event', 1),
    ('workout_intensity', 'Intensieve training',    'training',  15, 'per_event', 1),
    ('workout_record',    'Persoonlijk record',     'training',  25, 'per_event', 1),
    ('steps',             'Stappen',                'training',  45, 'daily',     1),
    ('weekly_workouts',   '3 trainingen deze week', 'training',  75, 'weekly',    1)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`), `domain` = VALUES(`domain`), `points` = VALUES(`points`),
    `cadence` = VALUES(`cadence`), `is_active` = VALUES(`is_active`);

-- ---------------------------------------------------------------------------
--  3. daily_scores: what each Health Score was based on
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'daily_scores'
        AND column_name = 'data_days') > 0,
    'SELECT ''daily_scores.data_days already there'' AS note',
    'ALTER TABLE `daily_scores`
        ADD COLUMN `data_days` SMALLINT UNSIGNED NULL
            COMMENT ''Days with data in the 90-day window the score was calculated over''
            AFTER `score`,
        ADD COLUMN `inputs` TEXT NULL
            COMMENT ''JSON: each component of the score, null where there was no data''
            AFTER `data_days`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

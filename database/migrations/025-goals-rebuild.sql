-- ============================================================================
--  025 — Goals, rebuilt (22.0)
-- ----------------------------------------------------------------------------
--  The goal model is rebuilt for the new three-step goal wizard
--  (docs/GOALS.md). ONLY goal tables are touched: goals, goal_progress, and
--  the new goal_* tables below. Accounts, profiles, preferences, health data,
--  integrations and everything else are not read, changed or dropped. The
--  only foreign key that pointed at `goals` was goal_progress's.
--
--  What happens, in order:
--
--   1. The old goals and their progress rows are COPIED to goals_backup_024
--      and goal_progress_backup_024 (no keys, no constraints: a plain copy
--      to look back at or drop). The old model cannot be carried over —
--      its types, categories and sources mean other things now — so the
--      goals themselves start over, as agreed for the beta.
--   2. The old goal_progress and goals are dropped.
--   3. The vocabulary is created and filled from config/goals.php:
--        goal_categories     Slaap, Voeding, Training, Activiteit, Lichaam,
--                            Herstel & balans
--        goal_units          every unit a goal is shown in, with what it is
--                            stored as (stored = typed x factor)
--        goal_measurements   what a goal can be about, where its figures come
--                            from, its stored unit, which way is better and
--                            which types it allows
--   4. The goal tables are created:
--        goals               one row per goal: what, how, which period, the
--                            baseline, where it stands, and — once finished —
--                            its frozen final percentage
--        goal_entries        what a person logged by hand on the goal's page
--        goal_day_results    a daily streak's days: met, missed, no data, open
--        goal_period_results a per-period streak's weeks/months/…: how many,
--                            of how many, met or missed
--        goal_progress       one snapshot per goal per day, for the chart
--
--  Re-running it is safe: a database that already has the new `goals`
--  (with category_code) keeps it and its rows; the vocabulary is updated in
--  place.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
-- ============================================================================

-- Is `goals` still the old shape (it has `category`, the new one has
-- `category_code`)? Everything in 1 and 2 only happens then.
SET @old := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals' AND COLUMN_NAME = 'category');

-- 1. A copy of what was there.
SET @sql := IF(@old > 0 AND (SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals_backup_024') = 0,
  'CREATE TABLE `goals_backup_024` AS SELECT * FROM `goals`',
  'SELECT "goals_backup_024: nothing to copy, or copied already" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(@old > 0
               AND (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_progress') > 0
               AND (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_progress_backup_024') = 0,
  'CREATE TABLE `goal_progress_backup_024` AS SELECT * FROM `goal_progress`',
  'SELECT "goal_progress_backup_024: nothing to copy, or copied already" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. The old tables go: the progress first, it points at the goals.
SET @sql := IF(@old > 0, 'DROP TABLE IF EXISTS `goal_progress`', 'SELECT "goal_progress kept" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(@old > 0, 'DROP TABLE IF EXISTS `goals`', 'SELECT "goals kept: already the new shape" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. The vocabulary ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `goal_categories` (
    `code`       VARCHAR(20) NOT NULL,
    `sort_order` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_categories` (`code`, `sort_order`) VALUES
    ('sleep', 1), ('nutrition', 2), ('training', 3), ('activity', 4), ('body', 5), ('recovery', 6)
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

CREATE TABLE IF NOT EXISTS `goal_units` (
    `code`      VARCHAR(20) NOT NULL,
    `canonical` VARCHAR(20) NOT NULL COMMENT 'the unit a figure typed in this one is stored in',
    `factor`    DECIMAL(20,12) NOT NULL COMMENT 'stored = typed x factor',
    PRIMARY KEY (`code`),
    KEY `idx_goal_units_canonical` (`canonical`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_units` (`code`, `canonical`, `factor`) VALUES
    ('kg', 'kg', 1), ('lb', 'kg', 0.45359237),
    ('cm', 'cm', 1), ('in', 'cm', 2.54),
    ('km', 'km', 1), ('mi', 'km', 1.609344),
    ('min', 'min', 1), ('h', 'min', 60),
    ('l', 'l', 1), ('ml', 'l', 0.001), ('floz', 'l', 0.0295735295625),
    ('kcal', 'kcal', 1), ('kj', 'kcal', 0.239005736137667),
    ('g', 'g', 1), ('mg', 'mg', 1), ('pct', 'pct', 1), ('bpm', 'bpm', 1), ('ms', 'ms', 1),
    ('score10', 'score10', 1), ('steps', 'steps', 1), ('floors', 'floors', 1),
    ('sessions', 'sessions', 1), ('times', 'times', 1), ('pages', 'pages', 1),
    ('glasses', 'glasses', 1), ('reps', 'reps', 1)
ON DUPLICATE KEY UPDATE `canonical` = VALUES(`canonical`), `factor` = VALUES(`factor`);

CREATE TABLE IF NOT EXISTS `goal_measurements` (
    `code`              VARCHAR(40) NOT NULL,
    `category_code`     VARCHAR(20) NOT NULL,
    `source_kind`       ENUM('metric','measurement','workout','manual') NOT NULL
                        COMMENT 'which table its figures come from; manual = kept by hand',
    `source_key`        VARCHAR(60) NULL COMMENT 'metric code, measurement type, or workout aspect',
    `unit_code`         VARCHAR(20) NOT NULL COMMENT 'the unit its figures are stored in',
    `direction`         ENUM('increase','decrease','both') NOT NULL COMMENT 'which way is better; both = the person says',
    `allows_milestone`  TINYINT(1) NOT NULL DEFAULT 0,
    `allows_daily`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'streak: a target every day',
    `allows_period`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'streak: a number of times per period',
    `allows_accumulate` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`        SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`code`),
    KEY `idx_goal_measurements_category` (`category_code`),
    KEY `idx_goal_measurements_unit` (`unit_code`),
    CONSTRAINT `fk_goal_measurement_category` FOREIGN KEY (`category_code`) REFERENCES `goal_categories` (`code`),
    CONSTRAINT `fk_goal_measurement_unit` FOREIGN KEY (`unit_code`) REFERENCES `goal_units` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_measurements`
    (`code`, `category_code`, `source_kind`, `source_key`, `unit_code`, `direction`,
     `allows_milestone`, `allows_daily`, `allows_period`, `allows_accumulate`, `sort_order`) VALUES
    ('sleep_duration',   'sleep',     'metric',      'sleep_duration',   'min',      'both',     1, 1, 0, 0, 1),
    ('sleep_efficiency', 'sleep',     'metric',      'sleep_efficiency', 'pct',      'increase', 1, 1, 0, 0, 2),
    ('water',            'nutrition', 'metric',      'water',            'l',        'increase', 0, 1, 0, 1, 3),
    ('energy',           'nutrition', 'metric',      'energy',           'kcal',     'both',     0, 1, 0, 0, 4),
    ('protein',          'nutrition', 'metric',      'protein',          'g',        'increase', 0, 1, 0, 0, 5),
    ('carbs',            'nutrition', 'metric',      'carbs',            'g',        'both',     0, 1, 0, 0, 6),
    ('fat',              'nutrition', 'metric',      'fat',              'g',        'both',     0, 1, 0, 0, 7),
    ('fibre',            'nutrition', 'metric',      'fibre',            'g',        'increase', 0, 1, 0, 0, 8),
    ('sugar',            'nutrition', 'metric',      'sugar',            'g',        'decrease', 0, 1, 0, 0, 9),
    ('saturated_fat',    'nutrition', 'metric',      'saturated_fat',    'g',        'decrease', 0, 1, 0, 0, 10),
    ('sodium',           'nutrition', 'metric',      'sodium',           'mg',       'decrease', 0, 1, 0, 0, 11),
    ('nutrition_rating', 'nutrition', 'metric',      'nutrition_rating', 'score10',  'increase', 1, 1, 0, 0, 12),
    ('workouts',         'training',  'workout',     'sessions',         'sessions', 'increase', 0, 1, 1, 1, 13),
    ('workout_minutes',  'training',  'workout',     'minutes',          'min',      'increase', 0, 1, 0, 1, 14),
    ('steps',            'activity',  'metric',      'steps',            'steps',    'increase', 1, 1, 0, 1, 15),
    ('distance',         'activity',  'metric',      'distance',         'km',       'increase', 1, 1, 0, 1, 16),
    ('active_energy',    'activity',  'metric',      'active_energy',    'kcal',     'increase', 0, 1, 0, 1, 17),
    ('floors',           'activity',  'metric',      'floors',           'floors',   'increase', 1, 1, 0, 1, 18),
    ('weight',           'body',      'measurement', 'weight',           'kg',       'both',     1, 0, 0, 0, 19),
    ('body_fat_pct',     'body',      'measurement', 'body_fat_pct',     'pct',      'both',     1, 0, 0, 0, 20),
    ('waist_cm',         'body',      'measurement', 'waist_cm',         'cm',       'both',     1, 0, 0, 0, 21),
    ('lean_mass',        'body',      'measurement', 'lean_mass',        'kg',       'both',     1, 0, 0, 0, 22),
    ('resting_hr',       'recovery',  'metric',      'resting_hr',       'bpm',      'decrease', 1, 1, 0, 0, 23),
    ('hrv',              'recovery',  'metric',      'hrv',              'ms',       'increase', 1, 1, 0, 0, 24),
    ('sleeping_hr',      'recovery',  'metric',      'sleeping_hr',      'bpm',      'decrease', 1, 1, 0, 0, 25),
    ('meditation',       'recovery',  'manual',      NULL,               'min',      'increase', 0, 1, 1, 1, 26)
ON DUPLICATE KEY UPDATE
    `category_code` = VALUES(`category_code`), `source_kind` = VALUES(`source_kind`),
    `source_key` = VALUES(`source_key`), `unit_code` = VALUES(`unit_code`), `direction` = VALUES(`direction`),
    `allows_milestone` = VALUES(`allows_milestone`), `allows_daily` = VALUES(`allows_daily`),
    `allows_period` = VALUES(`allows_period`), `allows_accumulate` = VALUES(`allows_accumulate`),
    `sort_order` = VALUES(`sort_order`);

-- 4. The goals -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `goals` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `category_code`    VARCHAR(20) NOT NULL,
    `measurement_code` VARCHAR(40) NULL COMMENT 'NULL: an Eigen gewoonte, kept by hand',
    `goal_type`        ENUM('milestone','streak','accumulate') NOT NULL,
    `streak_variant`   ENUM('daily','period') NULL COMMENT 'a streak only',
    `tracking`         ENUM('auto','manual') NOT NULL COMMENT 'auto = read from the person''s own health data',
    `unit_code`        VARCHAR(20) NULL COMMENT 'the unit it is shown and typed in (the goal''s own choice)',
    `custom_unit`      VARCHAR(20) NULL COMMENT 'an Eigen gewoonte''s own unit word, counted as typed',
    `direction`        ENUM('increase','decrease') NOT NULL DEFAULT 'increase'
                       COMMENT 'milestone: which way is better; daily streak: at least / at most',
    `target_value`     DECIMAL(14,4) NULL COMMENT 'stored unit — milestone: to reach; daily: each day; accumulate: the total',
    `frequency`        SMALLINT UNSIGNED NULL COMMENT 'period streak: times per period',
    `period_unit`      ENUM('week','month','quarter','year') NULL COMMENT 'period streak: counted from start_date',
    `baseline_value`   DECIMAL(14,4) NULL COMMENT 'milestone: where it started, fixed when it was made',
    `baseline_on`      DATE NULL COMMENT 'the day that value was measured; NULL when typed',
    `baseline_source`  ENUM('start_day','before_start','manual') NULL,
    `start_date`       DATE NOT NULL,
    `end_date`         DATE NOT NULL COMMENT 'the last day of the period, included',
    `status`           ENUM('active','paused','completed','ended') NOT NULL DEFAULT 'active'
                       COMMENT 'completed = reached 100%; ended = its period ended below 100%',
    `priority`         ENUM('primary','secondary') NOT NULL DEFAULT 'secondary',
    `current_value`    DECIMAL(14,4) NULL COMMENT 'the figure the percentage is worked out from (stored unit)',
    `progress_pct`     DECIMAL(5,2) NULL,
    `progress_at`      DATETIME NULL,
    `final_pct`        DECIMAL(5,2) NULL COMMENT 'frozen when it finished; never changed after',
    `completed_at`     DATETIME NULL COMMENT 'when it finished: reached, or its period over',
    `create_token`     CHAR(36) NULL COMMENT 'the wizard''s one-time key: the same goal is never made twice',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_goal_create_token` (`user_id`, `create_token`),
    KEY `idx_goals_user_status` (`user_id`, `status`),
    KEY `idx_goals_user_priority` (`user_id`, `status`, `priority`),
    KEY `idx_goals_category` (`category_code`),
    KEY `idx_goals_measurement` (`measurement_code`),
    KEY `idx_goals_unit` (`unit_code`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_goals_category` FOREIGN KEY (`category_code`) REFERENCES `goal_categories` (`code`),
    CONSTRAINT `fk_goals_measurement` FOREIGN KEY (`measurement_code`) REFERENCES `goal_measurements` (`code`),
    CONSTRAINT `fk_goals_unit` FOREIGN KEY (`unit_code`) REFERENCES `goal_units` (`code`),
    CONSTRAINT `chk_goal_period_dates` CHECK (`end_date` > `start_date`),
    CONSTRAINT `chk_goal_streak_variant` CHECK ((`goal_type` = 'streak') = (`streak_variant` IS NOT NULL)),
    CONSTRAINT `chk_goal_frequency` CHECK ((COALESCE(`streak_variant`, '') = 'period')
                                           = (`frequency` IS NOT NULL AND `frequency` > 0 AND `period_unit` IS NOT NULL)),
    CONSTRAINT `chk_goal_target` CHECK (COALESCE(`streak_variant`, '') = 'period'
                                        OR (`target_value` IS NOT NULL AND `target_value` > 0)),
    CONSTRAINT `chk_goal_unit` CHECK ((`unit_code` IS NOT NULL) <> (`custom_unit` IS NOT NULL)),
    CONSTRAINT `chk_goal_baseline` CHECK (`goal_type` <> 'milestone' OR `baseline_value` IS NOT NULL),
    CONSTRAINT `chk_goal_finished` CHECK ((`status` IN ('completed','ended'))
                                          = (`final_pct` IS NOT NULL AND `completed_at` IS NOT NULL)),
    CONSTRAINT `chk_goal_percent` CHECK ((`progress_pct` IS NULL OR `progress_pct` BETWEEN 0 AND 100)
                                         AND (`final_pct` IS NULL OR `final_pct` BETWEEN 0 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a person logged on the goal's page, one row per entry: a new
-- measurement (Mijlpaal: the latest counts), an amount added (Optellen), or
-- a day ticked off (Streak: `value` NULL, one per day).
CREATE TABLE IF NOT EXISTS `goal_entries` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id`    BIGINT UNSIGNED NOT NULL,
    `entry_date` DATE NOT NULL,
    `value`      DECIMAL(14,4) NULL COMMENT 'stored unit; NULL for a day ticked off',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_goal_entries_day` (`goal_id`, `entry_date`),
    CONSTRAINT `fk_goal_entries_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A daily streak's days as last worked out (includes/goal-progress.php).
CREATE TABLE IF NOT EXISTS `goal_day_results` (
    `goal_id` BIGINT UNSIGNED NOT NULL,
    `day`     DATE NOT NULL,
    `value`   DECIMAL(14,4) NULL COMMENT 'the day''s figure, stored unit; NULL: nothing recorded',
    `state`   ENUM('met','missed','unknown','pending') NOT NULL,
    PRIMARY KEY (`goal_id`, `day`),
    CONSTRAINT `fk_goal_day_results_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A per-period streak's periods as last worked out. `required` is the
-- frequency, or for a last period cut short by the end date its share,
-- rounded (a half up); a period that comes to 0 is `skipped`.
CREATE TABLE IF NOT EXISTS `goal_period_results` (
    `goal_id`      BIGINT UNSIGNED NOT NULL,
    `period_index` SMALLINT UNSIGNED NOT NULL,
    `starts_on`    DATE NOT NULL,
    `ends_on`      DATE NOT NULL,
    `required`     SMALLINT UNSIGNED NOT NULL,
    `achieved`     SMALLINT UNSIGNED NOT NULL,
    `state`        ENUM('met','missed','pending','skipped') NOT NULL,
    PRIMARY KEY (`goal_id`, `period_index`),
    CONSTRAINT `fk_goal_period_results_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where a goal stood at the end of each day it was worked out on: the line
-- and the "that day" of the Verloop chart.
CREATE TABLE IF NOT EXISTS `goal_progress` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id`          BIGINT UNSIGNED NOT NULL,
    `recorded_on`      DATE NOT NULL,
    `current_value`    DECIMAL(14,4) NULL,
    `percent_complete` DECIMAL(5,2) NULL,
    `computed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_goal_day` (`goal_id`, `recorded_on`),
    CONSTRAINT `fk_progress_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

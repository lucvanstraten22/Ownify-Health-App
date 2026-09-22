-- ---------------------------------------------------------------------------
-- 006 — what a goal needs before its progress can be real
-- ---------------------------------------------------------------------------
-- Run this against the database the app uses. It names none, so it runs
-- against whichever one is selected — in phpMyAdmin, click the database in the
-- left-hand list FIRST, then Import. Re-running it is safe.
--
-- Goals could already be created, and could already hold a dated snapshot in
-- goal_progress. What they could not do was say where their progress should
-- come from, so metric_type_id was always NULL and every goal was effectively
-- manual with no way to enter anything.
--
-- Five things were missing, and each one is a wrong answer without it:
--
--   tracking_mode   whether this goal reads itself from health data or the
--                   person keeps it by hand. "Geen data mogelijk" is a real
--                   answer, not a failure to configure something.
--
--   source_kind     a source is not always a metric. Weight lives in
--                   user_measurements, training in workouts, steps in
--                   health_metrics — three different tables, so the column
--                   that names one has to say which kind it is.
--   source_key      and which one: 'steps', 'weight', 'minutes'.
--
--   start_value     the baseline, captured when the goal is made. Without it
--                   a losing-weight goal cannot be measured at all: 75 / 82
--                   is 91%, which reads as nearly finished to somebody who has
--                   lost nothing. What matters is how far you have come from
--                   where you started, and nothing recorded where that was.
--
--   daily_target    "10.000 steps every day for a month" is not one number to
--                   reach, it is a number to clear repeatedly. Progress is the
--                   share of days that made it, and that needs the per-day
--                   figure stored separately from target_value.
--
--   completed_at    when it was finished. updated_at was standing in for this
--                   and it moves every time anything about the row changes.
-- ---------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'goals'
        AND column_name = 'tracking_mode') > 0,
    'SELECT ''goals.tracking_mode already present'' AS note',
    'ALTER TABLE `goals`
        ADD COLUMN `tracking_mode` ENUM(''auto'',''manual'') NOT NULL DEFAULT ''manual''
            COMMENT ''auto = read from the user''''s own health data; manual = they keep it''
            AFTER `goal_type`,
        ADD COLUMN `source_kind` ENUM(''metric'',''measurement'',''workout'',''manual'')
            NOT NULL DEFAULT ''manual''
            COMMENT ''which table the value comes from'' AFTER `tracking_mode`,
        ADD COLUMN `source_key` VARCHAR(60) NULL
            COMMENT ''metric code, measurement type, or workout aspect'' AFTER `source_kind`,
        ADD COLUMN `start_value` DECIMAL(14,4) NULL
            COMMENT ''the baseline when the goal was made'' AFTER `target_value`,
        ADD COLUMN `daily_target` DECIMAL(14,4) NULL
            COMMENT ''for goals that repeat a target every day'' AFTER `start_value`,
        ADD COLUMN `completed_at` DATETIME NULL AFTER `status`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Goals that already exist were all manual in practice, which is what the
-- defaults above say. Nothing to backfill.

-- A day's worth of progress is read per goal, oldest first, on every render of
-- the detail page. Worth an index once a goal has a month of days in it.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'goal_progress'
        AND index_name = 'idx_progress_goal_day') > 0,
    'SELECT ''idx_progress_goal_day already present'' AS note',
    'CREATE INDEX `idx_progress_goal_day` ON `goal_progress` (`goal_id`, `recorded_on`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

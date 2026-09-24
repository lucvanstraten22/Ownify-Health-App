-- ---------------------------------------------------------------------------
-- 007 — three goal types, each measured its own way
-- ---------------------------------------------------------------------------
-- Run this against the database the app uses. It names none, so it runs
-- against whichever one is selected — in phpMyAdmin, click the database in the
-- left-hand list FIRST, then Import. Re-running it is safe. It needs 006.
--
-- Goals had four types — Doelwaarde, Gewoonte, Reeks, Mijlpaal — and none of
-- them changed how progress was worked out. The data source decided that: a
-- source that adds up each day was always summed, a standing one always took
-- the newest reading, and a manual goal always took whatever was entered last.
-- So a bench press of 85 followed by 70 read 70, and a "streak" counted every
-- ticked day whether they were in a row or not.
--
-- There are now three, and the type decides the arithmetic:
--
--   milestone    Mijlpaal. One result to reach. Progress is the BEST result so
--                far — 60, 75, 85, 70 is 85, never 290 and never 70.
--   streak       Streak. Consecutive successful days. A miss breaks it.
--   accumulate   Optellen. Every contribution is added to a total.
--
-- How the old four map, chosen so no existing goal changes what it means:
--
--   event         -> milestone    (it was already "one result")
--   target_value  -> milestone    (the newest value, which for a single figure
--                                  is the same question as the best one)
--                 -> accumulate   when its source was an automatic one that
--                                  was ALREADY being summed — steps, distance,
--                                  workouts. "50 km this month" stays a total.
--                 -> accumulate   counting DAYS, when it had a daily target
--                                  ("10.000 steps every day"). Its percentage
--                                  was always the days met out of the days in
--                                  its period, so its target becomes exactly
--                                  that number of days.
--   habit         -> accumulate   (it counted days, and still does)
--   streak        -> streak
-- ---------------------------------------------------------------------------

-- 1. Allow both vocabularies for a moment, so no row is ever invalid mid-way.
ALTER TABLE `goals`
    MODIFY COLUMN `goal_type`
        ENUM('target_value','habit','streak','event','milestone','accumulate')
        NOT NULL DEFAULT 'milestone';

-- 2. Move every row across. The refined rule for target_value needs the
--    source columns from 006; without them, everything becomes a milestone.
SET @has006 := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'goals'
                   AND column_name = 'source_kind');

-- A daily-target Doelwaarde first, while it can still be told apart: its
-- target was the per-day figure, and what it measured was days.
SET @sql := IF(@has006 > 0,
    'UPDATE `goals`
        SET target_value = COALESCE(DATEDIFF(end_date, start_date) + 1, 30),
            target_unit  = ''dagen''
      WHERE goal_type = ''target_value''
        AND tracking_mode = ''auto''
        AND daily_target IS NOT NULL',
    'SELECT ''006 not present: no daily targets to carry over'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has006 > 0,
    'UPDATE `goals` g
        SET g.goal_type = ''accumulate''
      WHERE g.goal_type = ''target_value''
        AND g.tracking_mode = ''auto''
        AND (g.source_kind = ''workout''
             OR (g.source_kind = ''metric''
                 AND EXISTS (SELECT 1 FROM `health_metric_types` t
                              WHERE t.code = g.source_key AND t.aggregation = ''sum'')))',
    'SELECT ''006 not present: every Doelwaarde becomes a Mijlpaal'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `goals` SET goal_type = 'milestone'  WHERE goal_type IN ('target_value', 'event');
UPDATE `goals` SET goal_type = 'accumulate' WHERE goal_type = 'habit';

-- direction is left exactly as it was. On a Mijlpaal it says which way is
-- better; on a goal with a daily target it says whether that target is a
-- floor or a ceiling ("at least 10.000 steps", "at most 2.000 kcal"), as it
-- did in 006. A plain total ignores it.

-- 3. And only the new vocabulary from here on.
ALTER TABLE `goals`
    MODIFY COLUMN `goal_type`
        ENUM('milestone','streak','accumulate') NOT NULL DEFAULT 'milestone'
        COMMENT 'milestone = best result; streak = consecutive days; accumulate = running total';

-- 4. Where each goal stands, stored with it.
--    Always recalculated from the underlying rows — the health data, or the
--    person's own entries — and never the other way round. Kept here so the
--    state is visible in phpMyAdmin, so a completion is decided on a stored
--    figure, and so each type's own number has a column that means one thing:
--
--      best_value      Mijlpaal: the best result so far
--      total_value     Optellen: the running total
--      streak_current  Streak:   the run that is still going
--      streak_best     Streak:   the longest run since the goal began
--
--    Only the column for the goal's own type is filled. NULL means nothing has
--    been recorded yet, which is not the same as zero.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'goals'
        AND column_name = 'progress_pct') > 0,
    'SELECT ''goals.progress_pct already present'' AS note',
    'ALTER TABLE `goals`
        ADD COLUMN `best_value`     DECIMAL(14,4)     NULL AFTER `daily_target`,
        ADD COLUMN `total_value`    DECIMAL(14,4)     NULL AFTER `best_value`,
        ADD COLUMN `streak_current` SMALLINT UNSIGNED NULL AFTER `total_value`,
        ADD COLUMN `streak_best`    SMALLINT UNSIGNED NULL AFTER `streak_current`,
        ADD COLUMN `progress_pct`   DECIMAL(5,2)      NULL AFTER `streak_best`,
        ADD COLUMN `progress_at`    DATETIME          NULL AFTER `progress_pct`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Manual progress needs no new table. goal_progress already holds one row per
-- goal per day, and each type now writes its day in its own way: a Mijlpaal
-- keeps the day's best result, Optellen adds to the day, and a Streak records
-- the day as done. Rows written before this migration already mean exactly
-- that — the newest value, or a ticked day — so nothing there is rewritten.

-- ============================================================================
--  017 — the Health Score over 168 hours, and its history
-- ----------------------------------------------------------------------------
--  The Health Score is now calculated over the last 168 hours (7 days)
--  instead of 90 days (config/scoring.php, includes/health-score.php), and
--  the Scorekompas shows its history over 7, 30, 90 and 365 days. That
--  history is daily_scores: one row per person, day and category, written
--  whenever the score is calculated that day and never again after the day
--  has passed (docs/SCORE-COMPASS.md).
--
--  daily_scores.valid_until   the last day a category's score holds without
--                             new input: until it expires (3 days without a
--                             new night or cijfer) or until the window has
--                             dropped too many of its days. A day with no
--                             row of its own shows the last recorded score
--                             only up to this day — never longer, and never
--                             a zero.
--
--  Rows written before this keep their score: they are the genuine record of
--  those days. They have no valid_until, so nothing is carried from them.
--  Nothing is recalculated or backfilled.
--
--  Until this is imported the score is calculated over 168 hours all the
--  same; days without a row of their own are just empty in the history.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'daily_scores'
        AND column_name = 'valid_until') > 0,
    'SELECT ''daily_scores.valid_until already there'' AS note',
    'ALTER TABLE `daily_scores`
        ADD COLUMN `valid_until` DATE NULL
            COMMENT ''The last day this score holds without new input; null: not carried''
            AFTER `inputs`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `daily_scores`
    MODIFY COLUMN `data_days` SMALLINT UNSIGNED NULL
        COMMENT 'Days with data in the window the score was calculated over';

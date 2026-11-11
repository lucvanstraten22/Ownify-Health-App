-- ============================================================================
--  031 — more to count in a challenge (49.1)
-- ----------------------------------------------------------------------------
--  Additive only (docs/CHALLENGES.md):
--
--    challenges.metric   from a fixed list of five to a key of up to 32
--                        characters, so a challenge can count what a goal
--                        can (config/challenges.php → metrics). Every
--                        challenge keeps the metric it has.
--
--  Until this is imported Nieuwe uitdaging offers the first five only
--  (challenge_metrics_ready()); nothing else changes.
--
--  NO `USE` STATEMENT. phpMyAdmin: select the database first. Back up first.
--  Command line: mysql -u USER -p DATABASE < this file. Re-running it is safe.
-- ============================================================================

ALTER TABLE `challenges`
    MODIFY COLUMN `metric` VARCHAR(32) NOT NULL COMMENT 'A key of config/challenges.php → metrics';

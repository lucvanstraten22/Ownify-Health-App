-- ============================================================================
--  023 — VO2max is no longer an Ownify metric (18.6)
-- ----------------------------------------------------------------------------
--  Ownify stopped reading, showing, scoring and offering VO2max. This takes
--  out what was stored for it, and only that:
--
--  goals                 a goal tracked from VO2max keeps its name, target,
--                        dates and the days it already recorded
--                        (goal_progress), and is kept by hand from now on —
--                        source_kind 'manual', as if "Geen data mogelijk" had
--                        been chosen. No goal is deleted.
--  health_metrics        the VO2max readings (Health Connect's Vo2Max
--                        records) are deleted. No other metric is touched.
--  health_metric_day_totals  its day totals, if any, go with the type
--                        (ON DELETE CASCADE).
--  health_metric_types   the 'vo2max' row is deleted, so it is no longer a
--                        source the goal wizard offers.
--
--  SpO2 ('spo2'), the heart rate, readiness and every other metric stay as
--  they are. daily_scores is not touched: the scores already recorded stay
--  as they were recorded (docs/DATABASE.md, "only for today").
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe: once the row is gone every statement does nothing.
-- ============================================================================

SET @vo2 := (SELECT `id` FROM `health_metric_types` WHERE `code` = 'vo2max');

UPDATE `goals`
   SET `source_kind`    = 'manual',
       `source_key`     = NULL,
       `tracking_mode`  = 'manual',
       `metric_type_id` = NULL
 WHERE (`source_kind` = 'metric' AND `source_key` = 'vo2max')
    OR (@vo2 IS NOT NULL AND `metric_type_id` = @vo2);

DELETE FROM `health_metrics` WHERE @vo2 IS NOT NULL AND `metric_type_id` = @vo2;

DELETE FROM `health_metric_types` WHERE `code` = 'vo2max';

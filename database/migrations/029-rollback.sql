-- ============================================================================
--  029 ROLLBACK — combined insights and the caffeine metric
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: deploy the previous code and keep
--  the schema. Older code never reads these tables, so nothing breaks and
--  nothing is lost.
--
--  This file removes the two tables 029 added. Combined insights are worked
--  out again from the health data whenever they are needed, so dropping them
--  loses nothing that cannot come back.
--
--  The 'caffeine' metric type goes only when no reading uses it: a person's
--  caffeine readings are their data and are never deleted by a rollback.
--
--  The research 029 added stays: research is reference data about nobody,
--  insights may point at it, and older code reads only the claims it knows.
--  php tools/research-registry.php --sync with the older config/research.php
--  puts that registry back.
--
--  Take a backup first.
-- ============================================================================

DROP TABLE IF EXISTS `user_correlations`;
DROP TABLE IF EXISTS `correlation_runs`;

DELETE FROM `health_metric_types`
 WHERE `code` = 'caffeine'
   AND NOT EXISTS (SELECT 1 FROM `health_metrics` m WHERE m.`metric_type_id` = `health_metric_types`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `health_metric_day_totals` t WHERE t.`metric_type_id` = `health_metric_types`.`id`);

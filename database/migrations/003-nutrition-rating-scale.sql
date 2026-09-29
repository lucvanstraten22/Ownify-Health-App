-- ============================================================================
--  003 — the nutrition rating runs 1-10, and several a day average out
-- ----------------------------------------------------------------------------
--  The catalogue row was created as a 1-5 rating that took the last value of
--  the day. The app asks for 1-10, and a day with two honest ratings is better
--  described by their average than by whichever was entered last.
--
--  A fresh import of database/schema.sql already has this. Run this file only
--  when upgrading a database created before it.
--
--  Any ratings already stored were on the 1-5 scale, so they are rescaled to
--  match. Doing it in the same statement keeps old and new entries comparable.
-- ============================================================================
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
-- ----------------------------------------------------------------------------
--  The database is whichever one you have selected. On shared hosting the
--  name is not ours to choose — Hestia prefixes it with the account, so it is
--  `luc_ownify` there and something else on the next server. Naming one
--  here would make this file work in exactly one place.
--
--  phpMyAdmin:  select the database in the sidebar FIRST, then Import.
--  Command line: name it as an argument, e.g.
--      mysql -u USER -p DATABASE < database/migrations/003-nutrition-rating-scale.sql
-- ----------------------------------------------------------------------------


-- Rescale what is already there, but only if the row still says /5 — so
-- running this twice cannot double the values.
UPDATE `health_metrics` hm
   JOIN `health_metric_types` t ON t.id = hm.metric_type_id
    SET hm.value = LEAST(10, hm.value * 2)
  WHERE t.code = 'nutrition_rating'
    AND t.unit = '/5';

UPDATE `health_metric_types`
   SET `unit` = '/10', `aggregation` = 'avg'
 WHERE `code` = 'nutrition_rating';

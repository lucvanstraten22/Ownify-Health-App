-- ============================================================================
--  026 — When the Voeding dot appears (26.0)
-- ----------------------------------------------------------------------------
--  user_profiles.rating_notice_hour   the hour from which a red dot asks for
--                                     today's Voeding cijfer when it is not
--                                     given yet (Instellingen › Meldingen ›
--                                     Stip bij Voeding). NULL is 20:00, the
--                                     default. The dots themselves are
--                                     worked out from what is already stored
--                                     (lib/notices.php); nothing else is new.
--
--  Nothing that exists is changed: the new column starts empty, which is
--  20:00.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'rating_notice_hour') > 0,
  'SELECT "user_profiles.rating_notice_hour already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `rating_notice_hour` TINYINT UNSIGNED NULL DEFAULT NULL
       COMMENT ''Meldingen › Stip bij Voeding: the hour the dot appears; NULL is 20'' AFTER `motion_off`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
--  021 — the language and the units a person chose
-- ----------------------------------------------------------------------------
--  user_profiles.locale    'nl' or 'en' when the person chose a language in
--                          Instellingen › Taal; NULL when they did not, and
--                          the app follows the language of their browser or
--                          phone (Dutch when that is neither).
--  user_profiles.units     'metric' or 'imperial' (Instellingen › Voorkeuren
--                          › Eenheden); NULL is metric.
--
--  The `locale` column has existed since the beginning as NOT NULL DEFAULT
--  'nl', but nothing ever let anybody choose it: every 'nl' in it is the
--  default, not a choice. They become NULL — "follow the device" — so an
--  English phone gets English and a Dutch one Dutch, as for a new account.
--
--  No measurement changes: weights stay in kg, lengths in cm, distances in
--  metres or km, temperatures in °C. The units are only how they are shown
--  and typed in (includes/i18n.php).
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'locale') = 'YES',
  'SELECT "user_profiles.locale already nullable" AS note',
  'ALTER TABLE `user_profiles`
     MODIFY COLUMN `locale` VARCHAR(10) NULL DEFAULT NULL
       COMMENT ''nl or en when chosen in Instellingen › Taal; NULL follows the device''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Only on the first run: afterwards an 'nl' is somebody's choice.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'units') > 0,
  'SELECT "user_profiles.units already present" AS note',
  'UPDATE `user_profiles` SET `locale` = NULL WHERE `locale` = ''nl''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'units') > 0,
  'SELECT "user_profiles.units already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `units` ENUM(''metric'',''imperial'') NULL DEFAULT NULL
       COMMENT ''Instellingen › Eenheden; NULL is metric. Only how values are shown and typed'' AFTER `locale`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

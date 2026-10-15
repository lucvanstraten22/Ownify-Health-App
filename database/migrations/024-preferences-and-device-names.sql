-- ============================================================================
--  024 — Voorkeuren that are kept, and names for connected phones (19.0)
-- ----------------------------------------------------------------------------
--  user_profiles.week_start     'sunday' when the week starts on Sunday
--                               (Instellingen › Voorkeuren › Eerste dag van
--                               de week); NULL is Monday. It decides where a
--                               week begins in the goal's day calendar and in
--                               Ownify AI's weekly totals.
--  user_profiles.text_large     1: larger text, whatever the device says
--  user_profiles.contrast_more  1: stronger borders and text, as the device's
--                               "more contrast" gives them
--  user_profiles.motion_off     1: no animations, as the device's "reduce
--                               motion" takes them away
--                               (Voorkeuren › Toegankelijkheid; kept on the
--                               account, so the website and the app agree)
--  user_devices.custom_label    the name the owner gave a connected phone in
--                               Instellingen › Apparaten & Gezondheid. Shown
--                               instead of `label` (what the phone calls
--                               itself), which the app may rewrite when it
--                               signs in again; this one only the owner
--                               changes. NULL: the phone's own name.
--
--  Nothing that exists is changed: every new column starts empty or 0, which
--  is how the app behaved before.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'week_start') > 0,
  'SELECT "user_profiles.week_start already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `week_start` ENUM(''monday'',''sunday'') NULL DEFAULT NULL
       COMMENT ''Voorkeuren › Eerste dag van de week; NULL is Monday'' AFTER `units`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'text_large') > 0,
  'SELECT "user_profiles.text_large already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `text_large` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''Toegankelijkheid › Grotere tekst: 1 = larger text, whatever the device says'' AFTER `week_start`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'contrast_more') > 0,
  'SELECT "user_profiles.contrast_more already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `contrast_more` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''Toegankelijkheid › Extra contrast: 1 = stronger borders and text'' AFTER `text_large`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'
                   AND COLUMN_NAME = 'motion_off') > 0,
  'SELECT "user_profiles.motion_off already present" AS note',
  'ALTER TABLE `user_profiles`
     ADD COLUMN `motion_off` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''Toegankelijkheid › Animaties uit: 1 = no animations, whatever the device says'' AFTER `contrast_more`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices'
                   AND COLUMN_NAME = 'custom_label') > 0,
  'SELECT "user_devices.custom_label already present" AS note',
  'ALTER TABLE `user_devices`
     ADD COLUMN `custom_label` VARCHAR(80) NULL DEFAULT NULL
       COMMENT ''The name the owner gave it; shown instead of label, never rewritten by the app'' AFTER `label`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

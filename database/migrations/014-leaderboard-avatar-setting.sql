-- ============================================================================
--  014 — your profile picture on the leaderboards
-- ----------------------------------------------------------------------------
--  The boards show each person's profile picture beside their name (the
--  picture is already public, like the username: see schema.sql). This adds
--  the person's own say in it: Instellingen → Privacy → "Profielfoto op de
--  ranglijst". On by default. Off, every board shows their initial instead of
--  their picture — to everybody, themselves included. The picture itself, and
--  where else it is shown (the account, the friends list), are not touched.
--
--  Until this is imported the app runs as it did: every picture shows on the
--  boards, and the switch says it cannot be saved yet.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'leaderboard_avatar') > 0,
    'SELECT ''user_profiles.leaderboard_avatar already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `leaderboard_avatar` TINYINT(1) NOT NULL DEFAULT 1
            COMMENT ''Profielfoto op de ranglijst: 0 = the boards show the initial, not the picture'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

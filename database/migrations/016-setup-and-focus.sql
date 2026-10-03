-- ============================================================================
--  016 — the first days: personal setup, focus and the baseline
-- ----------------------------------------------------------------------------
--  A new account starts with a short setup — "Hoe moet Ownify voor jou
--  werken?" — before the app: what the person most wants to understand, their
--  health data, a few profile facts and an optional first goal. After it the
--  Overzicht spends the first days building their baseline, up to their first
--  score (docs/FIRST-DAYS.md).
--
--  user_profiles.focus           what the person most wants to understand,
--                                chosen in the setup and changeable in
--                                Instellingen → Account:
--                                general (Alles) | sleep | energy | fitness | weight
--                                NULL  never chosen: shown as general
--  user_profiles.setup_state     NULL     an account from before the setup
--                                         existed: it never sees it
--                                pending  a new account that has not finished
--                                         its setup: the app opens on it, on
--                                         the website and on the phone, until
--                                         it is finished
--                                done     finished
--  user_profiles.setup_done_at   when it was finished: day 1 of the baseline
--
--  Only registration writes `pending` (includes/auth.php,
--  includes/google-signin.php), so every account that already exists keeps
--  NULL and goes on exactly as it did — no backfill is needed, and a profile
--  row created later for an old account (INSERT IGNORE) is NULL as well.
--
--  Until this is imported nothing changes: no setup, no baseline, and the
--  focus is "Alles" for everybody, as before.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'focus') > 0,
    'SELECT ''user_profiles.focus already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `focus` ENUM(''general'',''sleep'',''energy'',''fitness'',''weight'') NULL DEFAULT NULL
            COMMENT ''What the person most wants to understand; NULL = never chosen (general)'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'setup_state') > 0,
    'SELECT ''user_profiles.setup_state already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `setup_state` ENUM(''pending'',''done'') NULL DEFAULT NULL
            COMMENT ''First setup: NULL = account from before it existed, pending = not finished, done'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'setup_done_at') > 0,
    'SELECT ''user_profiles.setup_done_at already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `setup_done_at` DATETIME NULL DEFAULT NULL
            COMMENT ''When the first setup was finished: day 1 of the baseline'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

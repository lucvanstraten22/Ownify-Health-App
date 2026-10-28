-- ============================================================================
--  028 ROLLBACK — the research registry, the insights and their consent
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: deploy the previous code and keep
--  the schema. Older code never reads these tables or columns, so nothing
--  breaks and nothing is lost.
--
--  This file removes what 028 added. Insights are worked out again from the
--  health data whenever they are needed, so dropping them loses nothing that
--  cannot come back. A person's ANSWER to the insight-wording question
--  cannot, so the file REFUSES to run (SIGNAL) once anybody has answered it
--  (user_profiles.ai_insights_consent is not NULL).
--
--  The 'apple_health' catalogue row that 028 deleted (only when nothing
--  referred to it) is put back as it was before, so the schema is the
--  pre-028 one again. Nothing ever refers to it.
--
--  Requires MariaDB 10.1+ (BEGIN NOT ATOMIC). Take a backup first. The
--  DELIMITER lines keep the guarded block in one piece: the command line and
--  phpMyAdmin's import both read them.
-- ============================================================================

DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'ai_insights_consent') > 0 THEN
        IF (SELECT COUNT(*) FROM `user_profiles` WHERE `ai_insights_consent` IS NOT NULL) > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'People have answered the insight-wording consent: keep the 028 schema and roll back the code only.';
        END IF;
    END IF;

    DROP TABLE IF EXISTS `user_insights`;
    DROP TABLE IF EXISTS `insight_days`;
    DROP TABLE IF EXISTS `research_claims`;
    DROP TABLE IF EXISTS `research_sources`;
    ALTER TABLE `user_profiles` DROP COLUMN IF EXISTS `ai_insights_consent_at`;
    ALTER TABLE `user_profiles` DROP COLUMN IF EXISTS `ai_insights_consent_version`;
    ALTER TABLE `user_profiles` DROP COLUMN IF EXISTS `ai_insights_consent`;
    INSERT IGNORE INTO `data_sources` (`code`, `label`, `kind`) VALUES ('apple_health', 'Apple Health', 'platform');
END$$
DELIMITER ;

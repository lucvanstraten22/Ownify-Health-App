-- ============================================================================
--  015 — Ownify AI: the assistant's consent, conversations and usage
-- ----------------------------------------------------------------------------
--  The assistant (the sheet you swipe up) answers with Google Gemini. To do
--  that, Ownify sends the parts of a person's own data that fit the question
--  to Gemini — so nothing is sent until the person has said yes, and they can
--  say no again at any time (Instellingen → Privacy).
--
--  user_profiles.ai_consent           NULL  never asked
--                                     1     allowed
--                                     0     declined, or withdrawn later
--  user_profiles.ai_consent_version   which wording they said yes to
--                                     (config/ai.php, consent_version). When
--                                     the terms change — another Gemini tier,
--                                     another provider — the version changes
--                                     and everybody is asked again.
--  user_profiles.ai_consent_at        when they last answered
--
--  ai_conversations   one row per conversation, always the owner's
--  ai_messages        what was said: role user | assistant | system (a note
--                     from Ownify itself, e.g. "Doel toegevoegd"). An
--                     assistant message can carry one proposed change — a goal
--                     to add, a goal to pause — that only happens once its
--                     owner confirms it (action_json, action_state).
--  ai_usage           per person per day: messages sent (the daily limit),
--                     requests made to Gemini (the project's free quota is
--                     shared by everybody), tokens, errors, times the limit
--                     was hit.
--  ai_service_state   the few facts about Gemini itself, not about anyone:
--                     until when its free quota is known to be used up.
--
--  Nothing is kept about the health data itself: what was sent to Gemini is
--  worked out again for every question and never stored.
--
--  Every table hangs off users with ON DELETE CASCADE, so deleting an account
--  deletes its conversations and usage with it.
--
--  Until this is imported the assistant says it cannot be used yet; nothing
--  else changes.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'ai_consent') > 0,
    'SELECT ''user_profiles.ai_consent already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `ai_consent` TINYINT(1) NULL DEFAULT NULL
            COMMENT ''Ownify AI: NULL never asked, 1 allowed, 0 declined or withdrawn'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'ai_consent_version') > 0,
    'SELECT ''user_profiles.ai_consent_version already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `ai_consent_version` VARCHAR(32) NULL DEFAULT NULL
            COMMENT ''The consent wording that was answered (config/ai.php consent_version)'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'ai_consent_at') > 0,
    'SELECT ''user_profiles.ai_consent_at already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `ai_consent_at` DATETIME NULL DEFAULT NULL
            COMMENT ''When the Ownify AI consent was last answered'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `title`      VARCHAR(120) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_conversations_user` (`user_id`, `updated_at`),
    CONSTRAINT `fk_ai_conversations_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `user_id`         BIGINT UNSIGNED NOT NULL,
    `role`            ENUM('system','user','assistant') NOT NULL,
    `content`         MEDIUMTEXT NOT NULL,
    `action_json`     TEXT NULL COMMENT 'A proposed change, done only once the owner confirms it',
    `action_state`    ENUM('pending','running','done','declined','failed','expired') NULL,
    `meta_json`       TEXT NULL COMMENT 'Model, token counts and the tools used — never health data',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_messages_conversation` (`conversation_id`, `id`),
    KEY `idx_ai_messages_user` (`user_id`),
    CONSTRAINT `fk_ai_messages_conversation` FOREIGN KEY (`conversation_id`)
        REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ai_messages_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_usage` (
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `usage_date`    DATE NOT NULL,
    `messages`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Questions answered by Gemini: what the daily limit counts',
    `gemini_calls`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requests made to Gemini, a question with tools taking several',
    `input_tokens`  INT UNSIGNED NOT NULL DEFAULT 0,
    `output_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `errors`        INT UNSIGNED NOT NULL DEFAULT 0,
    `limit_hits`    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `usage_date`),
    KEY `idx_ai_usage_date` (`usage_date`),
    CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_service_state` (
    `name`       VARCHAR(40) NOT NULL,
    `value`      VARCHAR(255) NULL,
    `until`      DATETIME NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

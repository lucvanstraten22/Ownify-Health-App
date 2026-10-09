-- ============================================================================
--  020 — Polar (AccessLink v4): the source, the sign-in in between, the sync
-- ----------------------------------------------------------------------------
--  Polar's data is read server to server: the person connects their Polar
--  account once (OAuth 2.0 at auth.polar.com), and the server fetches their
--  trainings, sleep, steps, heart rate and Nightly Recharge from Polar's API
--  (docs/POLAR.md). The connection itself — status, encrypted tokens, last
--  sync — lives in user_integrations (migration 004) like every source.
--
--    data_sources                 the source `polar`
--    integration_oauth_states     one connection being made: the state sent
--                                 to Polar (only its hash is kept), whose it
--                                 is, and where it may be finished. Single
--                                 use, ten minutes. Removed with the account.
--    user_integrations.sync_started_at
--                                 a sync running now, so the screen can say
--                                 so and a second one does not start
--
--  Nothing that is calculated changes. Polar's records arrive through the
--  same importer as Health Connect's (includes/health-import.php) and count
--  the same way; where both sent the same training, night or minute, the
--  existing rules count it once.
--
--  Until this is imported, Polar shows in Instellingen as not yet set up.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

INSERT INTO `data_sources` (`code`, `label`, `kind`) VALUES
    ('polar', 'Polar', 'platform')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `kind` = VALUES(`kind`);

CREATE TABLE IF NOT EXISTS `integration_oauth_states` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Whose connection this is: from the session or account token that started it, never from the callback',
    `provider`     VARCHAR(40) NOT NULL,
    `state_hash`   CHAR(64) NOT NULL COMMENT 'sha256 of the state sent to the provider; the state itself is never stored',
    `client`       ENUM('web','app') NOT NULL,
    `session_hash` CHAR(64) NULL COMMENT 'web: sha256 of the browser session that started it — only that session may finish it',
    `confirm_hash` CHAR(64) NULL COMMENT 'app: sha256 of the one-time token on the confirmation page',
    `pending_code` BLOB NULL COMMENT 'app: the authorization code, sealed, until the person confirms',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_oauth_state` (`state_hash`),
    KEY `idx_oauth_state_user` (`user_id`, `provider`),
    KEY `idx_oauth_state_expires` (`expires_at`),
    CONSTRAINT `fk_oauth_state_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_integrations'
                   AND COLUMN_NAME = 'sync_started_at') > 0,
  'SELECT "user_integrations.sync_started_at already present" AS note',
  'ALTER TABLE `user_integrations`
     ADD COLUMN `sync_started_at` DATETIME NULL COMMENT ''A sync running now; cleared when it ends'' AFTER `last_sync_at`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

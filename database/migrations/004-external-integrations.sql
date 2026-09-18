-- ============================================================================
--  004 — connections to outside health platforms, and import de-duplication
-- ----------------------------------------------------------------------------
--  Two things, both needed by any external source — Google Health, a Health
--  Connect companion app, Apple Health, a watch — rather than by one of them:
--
--    user_integrations   what a user has connected, and the tokens for it
--    external_id         which outside record a row came from, so importing
--                        the same day twice does not store it twice
--
--  Nothing here is specific to one provider. Adding another is a row in
--  data_sources and a value in the provider enum.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  The database is whichever one you have selected — the name differs per
--  host. phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
-- ============================================================================

-- ---------------------------------------------------------------------------
--  1. THE CONNECTION
-- ---------------------------------------------------------------------------
--  One row per user per provider. Tokens are stored encrypted by the
--  application (see includes/crypto.php) — the column holds ciphertext, so a
--  database dump does not hand over anybody's health account.
--
--  This table is PRIVATE. It names the account someone connected and carries
--  the keys to it; nothing about it is ever shown to another user.
CREATE TABLE IF NOT EXISTS `user_integrations` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `provider`            VARCHAR(40) NOT NULL
                          COMMENT 'Matches data_sources.code, e.g. google_health',
    `status`              ENUM('connected','disconnected','revoked','error')
                          NOT NULL DEFAULT 'disconnected',

    -- Who the provider says this is. Stored so a reconnect can be recognised
    -- as the same account, and so the settings screen can name it.
    `external_account_id` VARCHAR(191) NULL,
    `external_account_label` VARCHAR(191) NULL COMMENT 'What to show the owner, e.g. an e-mail',

    -- What the user actually granted. A provider may give fewer scopes than
    -- were asked for, and the importer has to know which.
    `scopes`              TEXT NULL,

    -- Ciphertext. Never a readable token, and never sent to the browser.
    `access_token`        BLOB NULL,
    `refresh_token`       BLOB NULL,
    `token_expires_at`    DATETIME NULL,

    `connected_at`        DATETIME NULL,
    `last_sync_at`        DATETIME NULL COMMENT 'Last run that finished without error',
    `last_sync_status`    ENUM('never','ok','partial','failed') NOT NULL DEFAULT 'never',
    `last_error`          VARCHAR(255) NULL COMMENT 'For the owner, never a raw API body',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- One connection per provider per user: connecting again updates.
    UNIQUE KEY `uq_integration_user_provider` (`user_id`, `provider`),
    KEY `idx_integration_status` (`status`),
    CONSTRAINT `fk_integration_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  2. DE-DUPLICATION
-- ---------------------------------------------------------------------------
--  Every importable table gets the id the outside system knows the record by,
--  plus a unique key on (user, source, external id). An import is then an
--  INSERT .. ON DUPLICATE KEY UPDATE and re-running a sync corrects rows
--  instead of multiplying them.
--
--  NULL is the point of the design: MySQL allows many NULLs in a unique key,
--  so everything entered by hand keeps colliding with nothing.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_sessions'
                   AND COLUMN_NAME = 'external_id') > 0,
  'SELECT "sleep_sessions.external_id already present" AS note',
  'ALTER TABLE `sleep_sessions`
     ADD COLUMN `external_id` VARCHAR(191) NULL COMMENT ''Id in the system it came from'' AFTER `source_id`,
     ADD UNIQUE KEY `uq_sleep_external` (`user_id`, `source_id`, `external_id`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts'
                   AND COLUMN_NAME = 'external_id') > 0,
  'SELECT "workouts.external_id already present" AS note',
  'ALTER TABLE `workouts`
     ADD COLUMN `external_id` VARCHAR(191) NULL COMMENT ''Id in the system it came from'' AFTER `source_id`,
     ADD UNIQUE KEY `uq_workout_external` (`user_id`, `source_id`, `external_id`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nutrition_entries'
                   AND COLUMN_NAME = 'external_id') > 0,
  'SELECT "nutrition_entries.external_id already present" AS note',
  'ALTER TABLE `nutrition_entries`
     ADD COLUMN `external_id` VARCHAR(191) NULL COMMENT ''Id in the system it came from'' AFTER `source_id`,
     ADD UNIQUE KEY `uq_nutrition_external` (`user_id`, `source_id`, `external_id`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_measurements'
                   AND COLUMN_NAME = 'external_id') > 0,
  'SELECT "user_measurements.external_id already present" AS note',
  'ALTER TABLE `user_measurements`
     ADD COLUMN `external_id` VARCHAR(191) NULL COMMENT ''Id in the system it came from'' AFTER `source_id`,
     ADD UNIQUE KEY `uq_measurement_external` (`user_id`, `source_id`, `external_id`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_metrics'
                   AND COLUMN_NAME = 'external_id') > 0,
  'SELECT "health_metrics.external_id already present" AS note',
  'ALTER TABLE `health_metrics`
     ADD COLUMN `external_id` VARCHAR(191) NULL COMMENT ''Id in the system it came from'' AFTER `source_id`,
     ADD UNIQUE KEY `uq_metric_external` (`user_id`, `source_id`, `external_id`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------------
--  3. THE NEW SOURCE
-- ---------------------------------------------------------------------------
--  google_health_connect already exists (a phone, via a companion app).
--  google_health is the cloud one: a Google account, read server to server.
INSERT INTO `data_sources` (`code`, `label`, `kind`) VALUES
    ('google_health', 'Google Health', 'platform')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `kind` = VALUES(`kind`);

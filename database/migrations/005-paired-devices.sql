-- ============================================================================
--  005 — paired phones, for sources whose data lives on the device
-- ----------------------------------------------------------------------------
--  Health Connect and Apple Health cannot be read from a server. An app on the
--  phone reads them and sends the records here, which means that app has to be
--  able to prove which JoLu account it is sending for.
--
--  It cannot use the session cookie — it is not a browser — and it must not
--  hold the account password. So it holds a token of its own:
--
--    1. the website mints a short pairing code for the signed-in user
--    2. the app exchanges that code, once, for a long-lived device token
--    3. the app sends records with that token
--    4. revoking the device kills the token and nothing else
--
--  A token per device rather than per account, so losing a phone costs you
--  that phone and not every phone.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
-- ============================================================================

-- ---------------------------------------------------------------------------
--  The pairing code
-- ---------------------------------------------------------------------------
--  Short-lived and single-use. Only the hash is stored: a code is a bearer
--  credential for the few minutes it lives, and a leaked backup should not
--  hand anybody an account.
CREATE TABLE IF NOT EXISTS `device_pairing_codes` (
    `code_hash`  CHAR(64) NOT NULL COMMENT 'sha256 of the code; the code itself is shown once',
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `provider`   VARCHAR(40) NOT NULL COMMENT 'Which source this pairing is for',
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME NULL COMMENT 'Set the moment it is exchanged; a code works once',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`code_hash`),
    KEY `idx_pairing_user` (`user_id`, `provider`),
    KEY `idx_pairing_expiry` (`expires_at`),
    CONSTRAINT `fk_pairing_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  The device
-- ---------------------------------------------------------------------------
--  Hashed, not encrypted. We never need the token back — only to recognise one
--  that is presented — so it is stored the way a password is, and a database
--  dump yields nothing that can be replayed.
CREATE TABLE IF NOT EXISTS `user_devices` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `provider`     VARCHAR(40) NOT NULL COMMENT 'Matches data_sources.code',
    `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256 of the device token',
    `label`        VARCHAR(80) NULL COMMENT 'What to call it on screen, e.g. Pixel 8',
    `platform`     VARCHAR(40) NULL COMMENT 'android, ios',
    `app_version`  VARCHAR(40) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NULL COMMENT 'Any authenticated request',
    `last_sync_at` DATETIME NULL COMMENT 'Last request that actually carried records',
    `revoked_at`   DATETIME NULL COMMENT 'Set on disconnect; the row stays as a record',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_token` (`token_hash`),
    KEY `idx_device_user` (`user_id`, `provider`, `revoked_at`),
    CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

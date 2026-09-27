-- ============================================================================
--  013 — the JoLu app signs in as an account; sign-in attempts are counted
-- ----------------------------------------------------------------------------
--  Until now a phone had one kind of credential: the device token it gets by
--  typing a pairing code from the website (005). That token may upload health
--  records and read the few things a sync needs, and nothing more — it is not
--  an account sign-in, and it must never become one.
--
--  The full JoLu app needs to act as the account: set goals, see friends,
--  change the profile. It signs in with the account's own password or Google,
--  and gets a token of its own for that. Both kinds live in user_devices, so a
--  phone is one row in Settings > Apparaten, revoked with the same button:
--
--    scope = 'sync'      what pairing issues, exactly as before
--    scope = 'account'   what signing in in the app issues
--
--  Every row that exists when this runs is a paired phone, so every row is
--  'sync' — the column's default. Nothing is rewritten and no phone stops
--  syncing.
--
--  An account token lapses after a year without use. That needs no column of
--  its own: last_seen_at is already set on every authenticated request, so
--  "unused for a year" is last_seen_at older than a year
--  (includes/devices.php, DEVICE_ACCOUNT_TOKEN_DAYS). Sync tokens keep working
--  until they are revoked, as they always have.
--
--  auth_attempts: failed sign-ins, so that password guessing is slowed down
--  (includes/auth-throttle.php). One row per failure, keyed by a SHA-256 of
--  the name or e-mail that was typed together with the address it came from —
--  neither is stored as text. Rows older than a day are deleted as new ones
--  arrive; nothing here is kept for long.
--
--  Until this is imported the app runs as before: every token is a sync
--  token, signing in in the app is not offered yet, and sign-ins are not
--  counted.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_devices'
        AND column_name = 'scope') > 0,
    'SELECT ''user_devices.scope already there'' AS note',
    'ALTER TABLE `user_devices`
        ADD COLUMN `scope` ENUM(''sync'',''account'') NOT NULL DEFAULT ''sync''
            COMMENT ''sync: pairing code, uploads only. account: signed in in the app, acts as the account''
            AFTER `token_hash`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `auth_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`       VARCHAR(16) NOT NULL COMMENT 'login, register',
    `key_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the typed name or e-mail and the source address; neither is stored as text',
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_key` (`action`, `key_hash`, `attempted_at`),
    KEY `idx_attempt_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

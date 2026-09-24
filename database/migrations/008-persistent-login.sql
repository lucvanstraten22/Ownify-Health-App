-- ============================================================================
--  008 — staying signed in
-- ----------------------------------------------------------------------------
--  Signing in used to last as long as the PHP session, and the PHP session
--  does not last: its cookie is gone when the browser closes, and the server
--  throws the session away after a short idle spell (session.gc_maxlifetime,
--  24 minutes by default, and the host's own clean-up). Either one sent a
--  person who had signed in back to the opening screen.
--
--  A sign-in now has a second, long-lived half: one row here per browser that
--  signed in and has not signed out, and a cookie on that browser naming it.
--  When the session is gone, the cookie puts it back. Signing out deletes the
--  row; deleting the account deletes all of them.
--
--  The cookie is `selector.validator`. The selector finds the row and proves
--  nothing. The validator is 256 bits of random and only its SHA-256 is kept
--  here — the same way paired phones' tokens are kept (005) — so a dump of
--  this table signs nobody in. See includes/persistent-login.php.
--
--  Until this is imported, signing in works exactly as before: for as long as
--  the session lasts.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_login_tokens` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `selector`      CHAR(24) NOT NULL COMMENT 'Public half of the cookie: finds the row, proves nothing',
    `token_hash`    CHAR(64) NOT NULL COMMENT 'sha256 of the secret half; the secret is only ever in the cookie',
    `previous_hash` CHAR(64) NULL COMMENT 'The secret before the last rotation, until the browser shows it has the new one',
    `csrf_token`    CHAR(64) NOT NULL COMMENT 'The form token of this sign-in, so a session put back keeps it',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rotated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`  DATETIME NULL COMMENT 'Last time it put a session back',
    `expires_at`    DATETIME NOT NULL COMMENT 'Moves forward every time it is used',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_login_selector` (`selector`),
    KEY `idx_login_user` (`user_id`),
    KEY `idx_login_expiry` (`expires_at`),
    CONSTRAINT `fk_login_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

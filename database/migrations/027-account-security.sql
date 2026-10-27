-- ============================================================================
--  027 — account security: verified addresses, mailed links, pending
--        registrations, the mail outbox, ending other sessions
--                                                     (28.0, Release A)
-- ----------------------------------------------------------------------------
--  Additive only. Nothing existing is rewritten, merged or deleted:
--    * every existing account stays 'active' and keeps signing in;
--    * existing password addresses keep email_verified_at = NULL (unverified);
--    * existing Google identities are never changed. Their verified address
--      becomes the account's verified address ONLY when exactly one account
--      has it; an address shared by several accounts, or not in canonical
--      form, is reported in account_email_conflicts for a person to resolve,
--      and nobody owns it automatically.
--
--  THE CANONICAL ADDRESS (docs/ACCOUNT-SECURITY.md, account_email_canonical()):
--    surrounding whitespace removed, lower-case, printable ASCII only, no
--    whitespace inside, 1–191 characters. Every address column below holds
--    only that form, and a CHECK constraint says so, byte for byte and with
--    NO BACKSLASH anywhere (so the server's sql_mode, NO_BACKSLASH_ESCAPES
--    included, cannot change its meaning):
--        CHAR_LENGTH(x) BETWEEN 1 AND 191
--        AND NOT (CAST(x AS BINARY) REGEXP '[^!-@[-~]')
--    The regex literal is exactly these 9 bytes, nothing escaped:
--        [  ^  !  -  @  [  -  ~  ]      (hex 5B 5E 21 2D 40 5B 2D 7E 5D)
--    a negated class of two ranges, !-@ (0x21–0x40) and [-~ (0x5B–0x7E);
--    the second '[' is an ordinary character inside the class.
--    i.e. no byte outside 0x21–0x7E and none in 'A'–'Z' — a trailing newline,
--    a space, a capital or a non-ASCII byte anywhere is refused. Tested with
--    direct inserts on MariaDB 10.11.14 in both sql_modes (tools/
--    canonical-check.php, which reads the constraints from THIS file): it equals the PHP stored-value rule on every case,
--    and the stored constraint text (information_schema.CHECK_CONSTRAINTS)
--    holds the same 9 bytes in both modes, with no backslash.
--    The anchored form '^…\z' was rejected: created under NO_BACKSLASH_ESCAPES
--    it refused every address. The database checks the CHARACTERS of a stored
--    value; address syntax (FILTER_VALIDATE_EMAIL) is the PHP normaliser's job.
--    CHECK is enforced from MariaDB 10.2.1; tools/check-config.php reports the
--    server's version and runs the same cases on TEMPORARY tables before 027
--    is imported. The columns keep utf8mb4_unicode_ci so they compare with the
--    existing identity table without a collation clash.
--
--  Every step checks first: each TABLE is created if missing and each COLUMN
--  added if missing — also on a table an older draft already created. The
--  code checks the same (account_security_schema()) and leaves a feature off
--  until all it needs is there.
--
--  NO `USE` STATEMENT. phpMyAdmin: select the database first. Back up first.
-- ============================================================================

-- 1. users.status gains 'pending' (a registration waiting for its link, or a
--    privacy placeholder). Every existing `status = 'active'` check excludes it.
SET @sql := IF(
    (SELECT COLUMN_TYPE FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'status') LIKE '%''pending''%',
    'SELECT ''users.status already has pending'' AS note',
    'ALTER TABLE `users` MODIFY `status` ENUM(''active'',''suspended'',''deleted'',''pending'') NOT NULL DEFAULT ''active'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. users.session_generation: a counter, not a time. Every browser session
--    keeps the generation it was signed in under; when the counter moves on,
--    every session holding an older number is signed out on its next request
--    — whatever second it began in. Only ONE existing session is ever moved
--    to a newer number: the session explicitly kept by a successful password
--    change (or Google removal), in that change's own transaction. A reset or
--    revert moves nobody; their session is signed out too. New sessions (a
--    sign-in, a verification, a stay-signed-in restore) start at the current
--    number. FAIL-CLOSED: a session that carries no number (signed in before
--    this mechanism) counts as generation 0 and is never given a newer one —
--    it carries on while the account is at 0, and is ended on its next
--    request once any security action has moved the account above 0.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_generation') > 0,
    'SELECT ''users.session_generation already there'' AS note',
    'ALTER TABLE `users` ADD COLUMN `session_generation` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT ''Browser sessions signed in under an older generation are ended'' AFTER `last_seen_at`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. A pending registration: address and password hash wait ONLY here until
--    the link is used. No sign-in identity exists, so the address is
--    reserved by nobody. address_taken = 1: the privacy placeholder (holds
--    only the chosen username and the registrant's own password hash; no
--    link, no identity; never becomes active). Deleted after 7 days.
--    AT MOST 3 rows per canonical address, real and placeholder counted
--    together. The code checks the count under a per-address named lock in
--    the transaction that would insert — GET_LOCK('ownify:pending:' + the
--    first 48 hex characters of sha256(address), 5): always 63 ASCII bytes,
--    within MySQL's 64 and MariaDB's 192; released with RELEASE_LOCK in a
--    `finally` after every successful GET_LOCK, since COMMIT and ROLLBACK
--    do not release it (lib/account-security.php, account_pending_register(); Release B). At 3 it inserts
--    NOTHING (no users row, no pending row, no link, no outbox job) and
--    answers the neutral "try later". An existing row is NEVER deleted to
--    make room: only its own 7-day expiry or "Ander adres gebruiken" (D2)
--    removes it.
CREATE TABLE IF NOT EXISTS `pending_registrations` (
    `user_id`        BIGINT UNSIGNED NOT NULL COMMENT 'The users row, status = pending',
    `email`          VARCHAR(191) NOT NULL COMMENT 'Canonical; NOT a sign-in address until verified',
    `password_hash`  VARCHAR(255) NOT NULL COMMENT 'password_hash() of the registrant''s chosen password',
    `address_taken`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATETIME NOT NULL COMMENT 'created_at + 7 days: then the users row is deleted',
    PRIMARY KEY (`user_id`),
    KEY `idx_pending_email` (`email`),
    KEY `idx_pending_expiry` (`expires_at`),
    CONSTRAINT `fk_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_pending_email_canonical` CHECK (CHAR_LENGTH(`email`) BETWEEN 1 AND 191 AND NOT (CAST(`email` AS BINARY) REGEXP '[^!-@[-~]'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. One account per verified address. The primary key IS the rule.
CREATE TABLE IF NOT EXISTS `account_emails` (
    `email`        VARCHAR(191) NOT NULL COMMENT 'Canonical',
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `via_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ownify link, completed reset or revert',
    `via_google`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'The linked Google account, email_verified = true',
    `verified_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`email`),
    KEY `idx_account_emails_user` (`user_id`),
    CONSTRAINT `fk_account_emails_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_account_emails_canonical` CHECK (CHAR_LENGTH(`email`) BETWEEN 1 AND 191 AND NOT (CAST(`email` AS BINARY) REGEXP '[^!-@[-~]'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Addresses nobody may claim automatically: found ambiguous or
--    non-canonical by this migration. Listed for a person; while an address is
--    here, no registration, addition, change or link can take it, and the
--    accounts involved keep signing in exactly as before.
CREATE TABLE IF NOT EXISTS `account_email_conflicts` (
    `email`        VARCHAR(191) NOT NULL COMMENT 'The address as canonicalised by LOWER(TRIM())',
    `reason`       VARCHAR(32)  NOT NULL COMMENT 'shared_google | not_canonical | already_owned | unverified_shadow',
    `user_ids`     VARCHAR(255) NOT NULL COMMENT 'The accounts involved, for the person resolving it',
    `found_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`  DATETIME NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Every mailed link: SHA-256 only, single use, time-limited.
CREATE TABLE IF NOT EXISTS `auth_email_tokens` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `purpose`        ENUM('verify_signup','verify_link','verify_change','password_reset','revert_email') NOT NULL,
    `email`          VARCHAR(191) NOT NULL COMMENT 'Canonical address the link was sent to',
    `token_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the token',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATETIME NOT NULL,
    `used_at`        DATETIME NULL COMMENT 'Set once, atomically; also when superseded',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_token_hash` (`token_hash`),
    KEY `idx_email_token_user` (`user_id`, `purpose`),
    KEY `idx_email_token_expiry` (`expires_at`),
    CONSTRAINT `fk_email_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_token_email_canonical` CHECK (CHAR_LENGTH(`email`) BETWEEN 1 AND 191 AND NOT (CAST(`email` AS BINARY) REGEXP '[^!-@[-~]'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'auth_email_tokens' AND column_name = 'change_id') > 0,
    'SELECT ''auth_email_tokens.change_id already there'' AS note',
    'ALTER TABLE `auth_email_tokens` ADD COLUMN `change_id` BIGINT UNSIGNED NULL
        COMMENT ''verify_change and revert_email: the address change it belongs to'' AFTER `email`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Address changes, kept so one can be reverted exactly.
CREATE TABLE IF NOT EXISTS `email_changes` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `old_email`     VARCHAR(191) NOT NULL COMMENT 'Canonical',
    `old_verified`  TINYINT(1) NOT NULL,
    `new_email`     VARCHAR(191) NOT NULL COMMENT 'Canonical',
    `requested_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `changed_at`    DATETIME NULL COMMENT 'When confirmed (for people); a revert uses email_change_google_baseline, not this time',
    `reverted_at`   DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_email_changes_user` (`user_id`, `changed_at`),
    CONSTRAINT `fk_email_changes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_change_canonical` CHECK (CHAR_LENGTH(`old_email`) BETWEEN 1 AND 191 AND NOT (CAST(`old_email` AS BINARY) REGEXP '[^!-@[-~]') AND CHAR_LENGTH(`new_email`) BETWEEN 1 AND 191 AND NOT (CAST(`new_email` AS BINARY) REGEXP '[^!-@[-~]'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7b. Which Google identities the account had when an address change was
--     confirmed (by id, never by time). A revert removes only the account's
--     Google identities whose id is NOT in this list — so one linked in the
--     same second, or unlinked and linked again (a new row, a new id), is
--     removed; one that was there all along stays.
CREATE TABLE IF NOT EXISTS `email_change_google_baseline` (
    `change_id`    BIGINT UNSIGNED NOT NULL,
    `identity_id`  BIGINT UNSIGNED NOT NULL COMMENT 'user_auth_identities.id of a Google identity present at confirmation',
    PRIMARY KEY (`change_id`, `identity_id`),
    CONSTRAINT `fk_baseline_change` FOREIGN KEY (`change_id`) REFERENCES `email_changes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. The mail outbox: sent after the response, by a worker holding a LEASE.
--    A worker claims a job by setting claim_token + lease_until (5 minutes);
--    a worker that dies leaves the lease to run out, and the job becomes
--    claimable again. On SMTP acceptance the row is deleted (only by the
--    worker whose claim_token still matches). At most 5 claims per job
--    (attempts); a job at 5, or past expires_at, is deleted unsent. Delivery is at-least-once while the job
--    lives: a crash after SMTP accepted but before the delete can send it
--    twice — the same message, the same Message-ID, the same single-use link.
CREATE TABLE IF NOT EXISTS `mail_outbox` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind`        VARCHAR(24) NOT NULL COMMENT 'signup, link, change, reset, password, email_changed, google_removed, already, none',
    `recipient`   VARCHAR(191) NULL COMMENT 'Canonical; NULL for kind = none (a decoy)',
    `payload`     TEXT NULL COMMENT 'Encrypted JSON (app key): username, link, locale, facts. Never a password',
    `message_id`  VARCHAR(128) NULL COMMENT 'Fixed per job, so a duplicate send carries the same Message-ID',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `send_after`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  DATETIME NOT NULL COMMENT 'Deleted unsent after this (its link is dead anyway)',
    PRIMARY KEY (`id`),
    KEY `idx_outbox_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8b. The lease columns: added if missing. (An older draft had a
--     claimed_at column; it is not used by anything and is left alone.)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND column_name = 'claim_token') > 0,
    'SELECT ''mail_outbox.claim_token already there'' AS note',
    'ALTER TABLE `mail_outbox` ADD COLUMN `claim_token` CHAR(32) NULL COMMENT ''Random per claim; only its holder may finish the job'' AFTER `send_after`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND column_name = 'lease_until') > 0,
    'SELECT ''mail_outbox.lease_until already there'' AS note',
    'ALTER TABLE `mail_outbox` ADD COLUMN `lease_until` DATETIME NULL COMMENT ''Claimable again once this has passed (a worker that died)'' AFTER `claim_token`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND column_name = 'attempts') > 0,
    'SELECT ''mail_outbox.attempts already there'' AS note',
    'ALTER TABLE `mail_outbox` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Claims so far; at 5 the job is deleted'' AFTER `lease_until`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND index_name = 'idx_outbox_due') > 0,
    'SELECT ''mail_outbox.idx_outbox_due already there'' AS note',
    'ALTER TABLE `mail_outbox` ADD KEY `idx_outbox_due` (`lease_until`, `send_after`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. Backfill — DETECT FIRST, then assign only what is unambiguous.
--    9a. Verified Google addresses shared by more than one account: reported,
--        never assigned.
INSERT INTO `account_email_conflicts` (`email`, `reason`, `user_ids`)
SELECT LOWER(TRIM(i.email)), 'shared_google', GROUP_CONCAT(DISTINCT i.user_id ORDER BY i.user_id)
  FROM `user_auth_identities` i
 WHERE i.provider = 'google' AND i.email IS NOT NULL AND i.email_verified_at IS NOT NULL
 GROUP BY LOWER(TRIM(i.email))
HAVING COUNT(DISTINCT i.user_id) > 1
ON DUPLICATE KEY UPDATE `user_ids` = VALUES(`user_ids`);

--    9b. Addresses that are not canonical even after LOWER(TRIM()) (a space or
--        a non-ASCII character inside): reported, never assigned.
INSERT INTO `account_email_conflicts` (`email`, `reason`, `user_ids`)
SELECT LEFT(LOWER(TRIM(i.email)), 191), 'not_canonical', GROUP_CONCAT(DISTINCT i.user_id ORDER BY i.user_id)
  FROM `user_auth_identities` i
 WHERE i.email IS NOT NULL AND (CAST(LOWER(TRIM(i.email)) AS BINARY) REGEXP '[^!-@[-~]')
 GROUP BY LEFT(LOWER(TRIM(i.email)), 191)
ON DUPLICATE KEY UPDATE `user_ids` = VALUES(`user_ids`);

--    9c. A Google address already owned by ANOTHER account in account_emails
--        (only possible on a re-run after Release B has been used): reported.
INSERT INTO `account_email_conflicts` (`email`, `reason`, `user_ids`)
SELECT LOWER(TRIM(i.email)), 'already_owned', CONCAT(ae.user_id, ',', i.user_id)
  FROM `user_auth_identities` i
  JOIN `account_emails` ae ON ae.email = LOWER(TRIM(i.email)) AND ae.user_id <> i.user_id
 WHERE i.provider = 'google' AND i.email IS NOT NULL AND i.email_verified_at IS NOT NULL
ON DUPLICATE KEY UPDATE `user_ids` = VALUES(`user_ids`);

--    9d. The rest: exactly one account, canonical, not yet in account_emails,
--        not listed above. A plain INSERT — no IGNORE: anything unexpected
--        stops the import loudly instead of being skipped silently.
INSERT INTO `account_emails` (`email`, `user_id`, `via_google`, `verified_at`)
SELECT LOWER(TRIM(i.email)), i.user_id, 1, MIN(i.email_verified_at)
  FROM `user_auth_identities` i
 WHERE i.provider = 'google' AND i.email IS NOT NULL AND i.email_verified_at IS NOT NULL
   AND NOT (CAST(LOWER(TRIM(i.email)) AS BINARY) REGEXP '[^!-@[-~]')
   AND LOWER(TRIM(i.email)) NOT IN (SELECT `email` FROM `account_email_conflicts` WHERE `resolved_at` IS NULL)
   AND NOT EXISTS (SELECT 1 FROM `account_emails` ae WHERE ae.email = LOWER(TRIM(i.email)))
 GROUP BY LOWER(TRIM(i.email)), i.user_id;

--    9e. Password sign-in addresses that are not already canonical (the code
--        has always stored them lower-cased and trimmed; this lists any that
--        are not, for a person — nothing is rewritten).
SELECT i.id AS identity_id, i.user_id, i.provider_subject AS address_as_stored
  FROM `user_auth_identities` i
 WHERE i.provider = 'email'
   AND (CAST(i.provider_subject AS BINARY) REGEXP '[^!-@[-~]');

--    9f. Legacy: an UNVERIFIED password sign-in address on one account that is
--        another account's verified address (possible before this release:
--        registration only compared password accounts). Listed as
--        unverified_shadow. Nothing changes for either account: the password
--        account keeps signing in, but that address can never be verified,
--        reset or used to set a password on it while the conflict stands.
INSERT INTO `account_email_conflicts` (`email`, `reason`, `user_ids`)
SELECT i.provider_subject, 'unverified_shadow', CONCAT(i.user_id, ',', ae.user_id)
  FROM `user_auth_identities` i
  JOIN `account_emails` ae ON ae.email = i.provider_subject AND ae.user_id <> i.user_id
 WHERE i.provider = 'email' AND i.email_verified_at IS NULL
ON DUPLICATE KEY UPDATE `user_ids` = VALUES(`user_ids`);

-- 10. What needs a person: every unresolved conflict.
SELECT `email`, `reason`, `user_ids`, `found_at` FROM `account_email_conflicts` WHERE `resolved_at` IS NULL;

-- 11. What is there now (every value should be 1).
SELECT
  (SELECT COLUMN_TYPE LIKE '%''pending''%' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'status') AS users_status_pending,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_generation') AS users_session_generation,
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = 'email_change_google_baseline') AS email_change_google_baseline,
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = 'pending_registrations') AS pending_registrations,
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = 'account_emails') AS account_emails,
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = 'account_email_conflicts') AS account_email_conflicts,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'auth_email_tokens' AND column_name = 'change_id') AS auth_email_tokens_change_id,
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = 'email_changes') AS email_changes,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND column_name = 'lease_until') AS mail_outbox_lease,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_outbox' AND column_name = 'claim_token') AS mail_outbox_claim;

-- auth_attempts.action is VARCHAR(16): the new limit names (register_ip,
-- mail_to, google, reset, reset_ip, mail, reauth, link) need no change.
-- Rollback: 027-rollback.sql — read its warning first.

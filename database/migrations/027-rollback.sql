-- ============================================================================
--  027 ROLLBACK — ONLY BEFORE RELEASE B HAS BEEN USED
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: deploy the previous code and keep the
--  schema. Old code ignores these tables and the extra column, and 'pending'
--  users have no sign-in identity, so it cannot sign them in. Nothing is lost.
--
--  This file removes the schema, and with it account-security data. It
--  REFUSES to run (SIGNAL) once any data exists that Release B — or a person
--  resolving a conflict — created:
--    a password-verified address, an address change (or its Google
--    baseline), a pending registration or placeholder, a link, an outbox job,
--    a pending user, a session generation that has moved (a password change
--    happened), or a conflict marked resolved.
--  What it may drop is only what 027 itself derived and can derive again by
--  being re-imported: the Google backfill in account_emails and the
--  unresolved conflict listing.
--
--  Requires MariaDB 10.1+ (BEGIN NOT ATOMIC). Take a backup first. The
--  DELIMITER lines keep the guarded block in one piece: the command line and
--  phpMyAdmin's import both read them.
-- ============================================================================

DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COUNT(*) FROM `account_emails` WHERE `via_password` = 1) > 0
       OR (SELECT COUNT(*) FROM `email_changes`) > 0
       OR (SELECT COUNT(*) FROM `email_change_google_baseline`) > 0
       OR (SELECT COUNT(*) FROM `users` WHERE `session_generation` > 0) > 0
       OR (SELECT COUNT(*) FROM `pending_registrations`) > 0
       OR (SELECT COUNT(*) FROM `auth_email_tokens`) > 0
       OR (SELECT COUNT(*) FROM `mail_outbox`) > 0
       OR (SELECT COUNT(*) FROM `users` WHERE `status` = 'pending') > 0
       OR (SELECT COUNT(*) FROM `account_email_conflicts` WHERE `resolved_at` IS NOT NULL) > 0
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Account-security data exists: keep the 027 schema and roll back the code only.';
    END IF;

    DROP TABLE IF EXISTS `mail_outbox`;
    DROP TABLE IF EXISTS `email_change_google_baseline`;
    DROP TABLE IF EXISTS `email_changes`;
    DROP TABLE IF EXISTS `auth_email_tokens`;
    DROP TABLE IF EXISTS `pending_registrations`;
    DROP TABLE IF EXISTS `account_email_conflicts`;   -- only the migration's own listing
    DROP TABLE IF EXISTS `account_emails`;            -- only the Google backfill
    ALTER TABLE `users` DROP COLUMN IF EXISTS `session_generation`;
    ALTER TABLE `users` MODIFY `status` ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active';
END$$
DELIMITER ;

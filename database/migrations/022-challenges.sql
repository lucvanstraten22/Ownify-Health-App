-- ============================================================================
--  022 — Uitdagingen: challenges with friends, joined by invitation or code
-- ----------------------------------------------------------------------------
--  challenges          One challenge: Samen (together towards one target) or
--                      Wedstrijd (who gets the most), what it counts, the
--                      period and the five-digit code that lets anybody who
--                      has it look at it and then join it themselves.
--  challenge_members   Who is in it, and how: invited (by a friend), joined,
--                      declined or left. Nobody is ever joined for them: a
--                      row only becomes `joined` when that person taps
--                      Meedoen.
--
--  What a participant sees of the others is their username, their picture as
--  the leaderboards show it and their total for this challenge — nothing else
--  (docs/CHALLENGES.md). The totals are worked out from the synced data when
--  they are shown; nothing about anybody's health is copied in here.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `challenges` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        CHAR(5) NULL COMMENT 'Five digits; works until the challenge is over. NULL once handed to a newer challenge',
    `creator_id`  BIGINT UNSIGNED NULL COMMENT 'NULL once that account is deleted; the challenge goes on for the others',
    `kind`        ENUM('together','versus') NOT NULL COMMENT 'Samen: one shared target. Wedstrijd: the most wins',
    `metric`      ENUM('workouts','steps','active_minutes','distance','sleep_nights') NOT NULL,
    `target`      DECIMAL(12,3) NULL COMMENT 'Samen only, stored units: workouts, steps, minutes, km, nights',
    `name`        VARCHAR(60) NOT NULL,
    `start_date`  DATE NOT NULL,
    `end_date`    DATE NOT NULL COMMENT 'The last day, inclusive. A Wedstrijd tied on it goes on until somebody leads',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_challenge_code` (`code`),
    KEY `idx_challenge_creator` (`creator_id`),
    KEY `idx_challenge_end` (`end_date`),
    CONSTRAINT `chk_challenge_dates` CHECK (`end_date` >= `start_date`),
    CONSTRAINT `fk_challenge_creator` FOREIGN KEY (`creator_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `challenge_members` (
    `challenge_id` BIGINT UNSIGNED NOT NULL,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `status`       ENUM('invited','joined','declined','left') NOT NULL,
    `invited_by`   BIGINT UNSIGNED NULL,
    `joined_at`    DATETIME NULL COMMENT 'Counts from this day (or the start, when that is later)',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`challenge_id`, `user_id`),
    KEY `idx_member_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_member_challenge` FOREIGN KEY (`challenge_id`)
        REFERENCES `challenges` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_inviter` FOREIGN KEY (`invited_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  AppName — database schema
-- ----------------------------------------------------------------------------
--  Target:  MySQL 5.7+ / 8.x and MariaDB 10.4+ (WampServer, XAMPP, phpMyAdmin)
--  Engine:  InnoDB throughout, utf8mb4 / utf8mb4_unicode_ci
--
--  Import:  phpMyAdmin → SELECT THE DATABASE IN THE SIDEBAR FIRST → Import
--     or:   mysql -u USER -p DATABASE < database/schema.sql
--
--  There is deliberately no CREATE DATABASE and no USE here. On shared
--  hosting the database already exists and its name is not ours to pick —
--  Hestia prefixes it with the account name — so this file works against
--  whichever database you point it at. Importing it with none selected is the
--  one way to get "No database selected".
--
--  The file is repeatable: it drops the tables it owns before creating them,
--  so re-importing rebuilds a clean database.
--
-- ----------------------------------------------------------------------------
--  PRIVACY MODEL — read this before adding tables
-- ----------------------------------------------------------------------------
--  Two classes of data live here, and the boundary is deliberate:
--
--  PUBLIC (may be shown to other users)
--      users.username, user_profiles.avatar_path,
--      user_period_points.points / .position
--
--  SHARED WITH THE PEOPLE YOU CHOSE (a challenge you joined yourself)
--      your total for that one challenge — worked out from your synced data
--      for its own period and metric only, shown to its participants
--
--  PRIVATE (the owner and authorised services only)
--      user_measurements, health_metrics, sleep_sessions, nutrition_entries,
--      workouts, workout_hr_zones, daily_scores, goals, goal_progress,
--      goal_entries, goal_day_results, goal_period_results,
--      ai_conversations, ai_messages, ai_usage, user_insights, insight_days,
--      correlation_runs, user_correlations
--
--  The one authorised outside service is Google Gemini, for Ownify AI: it is
--  sent the parts of a person's own data that fit their question, and only
--  once they have allowed it (user_profiles.ai_consent). See includes/ai/.
--  Insights and combined insights are Ownify's own sentences and statistics
--  (30.1, 31.0): nothing about them goes to Gemini. The 29.0 columns for
--  Gemini's wording (user_insights.phrased_*, insight_days.gemini_calls …,
--  user_profiles.ai_insights_consent …) are kept but no longer written.
--
--  The research registry (research_sources, research_claims) is reference
--  data about publications, about nobody.
--
--  Every private table carries user_id and every query against one MUST be
--  scoped to the authenticated user's id. The database cannot enforce that on
--  its own — MySQL grants are per-connection, not per-end-user, and a database
--  administrator can always read the tables. Privacy here is an APPLICATION
--  boundary: see includes/health-data.php, where every read takes the
--  authenticated user id as its first argument, and docs/DATABASE.md.
--
--  Do not add an admin feature that selects from a PRIVATE table across users.
-- ============================================================================


-- The catalogue rows and the research registry hold letters and dashes
-- outside ASCII (ë, –, …): read this file as UTF-8 whatever the client's
-- default is. A command-line client defaulting to latin1 would otherwise
-- store them garbled.
SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `user_correlations`;
DROP TABLE IF EXISTS `correlation_runs`;
DROP TABLE IF EXISTS `user_insights`;
DROP TABLE IF EXISTS `insight_days`;
DROP TABLE IF EXISTS `research_claims`;
DROP TABLE IF EXISTS `research_sources`;
DROP TABLE IF EXISTS `ai_service_state`;
DROP TABLE IF EXISTS `ai_usage`;
DROP TABLE IF EXISTS `ai_messages`;
DROP TABLE IF EXISTS `ai_conversations`;
DROP TABLE IF EXISTS `leaderboard_best_positions`;
DROP TABLE IF EXISTS `user_period_points`;
DROP TABLE IF EXISTS `point_events`;
DROP TABLE IF EXISTS `point_rules`;
DROP TABLE IF EXISTS `challenge_members`;
DROP TABLE IF EXISTS `challenges`;
DROP TABLE IF EXISTS `user_blocks`;
DROP TABLE IF EXISTS `friendships`;
DROP TABLE IF EXISTS `user_devices`;
DROP TABLE IF EXISTS `device_pairing_codes`;
DROP TABLE IF EXISTS `integration_oauth_states`;
DROP TABLE IF EXISTS `user_integrations`;
DROP TABLE IF EXISTS `goal_progress`;
DROP TABLE IF EXISTS `goal_period_results`;
DROP TABLE IF EXISTS `goal_day_results`;
DROP TABLE IF EXISTS `goal_entries`;
DROP TABLE IF EXISTS `goals`;
DROP TABLE IF EXISTS `goal_measurements`;
DROP TABLE IF EXISTS `goal_units`;
DROP TABLE IF EXISTS `goal_categories`;
DROP TABLE IF EXISTS `daily_scores`;
DROP TABLE IF EXISTS `workout_hr_zones`;
DROP TABLE IF EXISTS `workouts`;
DROP TABLE IF EXISTS `nutrition_entries`;
DROP TABLE IF EXISTS `heart_rate_minutes`;
DROP TABLE IF EXISTS `sleep_stages`;
DROP TABLE IF EXISTS `sleep_sessions`;
DROP TABLE IF EXISTS `health_metric_day_totals`;
DROP TABLE IF EXISTS `health_metrics`;
DROP TABLE IF EXISTS `health_metric_types`;
DROP TABLE IF EXISTS `user_measurements`;
DROP TABLE IF EXISTS `data_sources`;
DROP TABLE IF EXISTS `mail_outbox`;
DROP TABLE IF EXISTS `email_change_google_baseline`;
DROP TABLE IF EXISTS `email_changes`;
DROP TABLE IF EXISTS `auth_email_tokens`;
DROP TABLE IF EXISTS `account_email_conflicts`;
DROP TABLE IF EXISTS `account_emails`;
DROP TABLE IF EXISTS `pending_registrations`;
DROP TABLE IF EXISTS `auth_attempts`;
DROP TABLE IF EXISTS `user_login_tokens`;
DROP TABLE IF EXISTS `user_auth_identities`;
DROP TABLE IF EXISTS `user_profiles`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
--  1. IDENTITY
-- ============================================================================

-- The account itself. Deliberately thin: it is the thing every other table
-- points at, and it holds nothing private.
CREATE TABLE `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- utf8mb4_unicode_ci is case-insensitive, so this unique key already
    -- prevents "Lisa" and "lisa" from both existing.
    `username`      VARCHAR(30)  NOT NULL COMMENT 'Public handle, used to find and add friends',
    -- 'pending': a registration waiting for its link, or a privacy placeholder (027).
    `status`        ENUM('active','suspended','deleted','pending') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_seen_at`  DATETIME     NULL COMMENT 'Touched on each authenticated request',
    `session_generation` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Browser sessions signed in under an older generation are ended',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Everything about the person rather than the account.
-- Age is NOT stored: it is derived from date_of_birth, so there is one source
-- of truth and it can never drift (see user_age() in includes/user.php).
-- Height and weight are NOT stored here either — they change over time and
-- live in user_measurements so history survives an update.
CREATE TABLE `user_profiles` (
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `first_name`    VARCHAR(60)  NULL,
    `last_name`     VARCHAR(60)  NULL,
    `date_of_birth` DATE         NULL,
    `gender`        ENUM('female','male','non_binary','other','undisclosed')
                    NOT NULL DEFAULT 'undisclosed',
    -- Self-declared, so it belongs to the person rather than to
    -- user_measurements: it is a setting that drives targets, not a reading.
    `activity_level` ENUM('sedentary','light','moderate','active','athlete')
                    NULL COMMENT 'Self-declared; drives targets, not a measurement',
    `avatar_path`   VARCHAR(255) NULL COMMENT 'Relative path under uploads/, never a client filename',
    `locale`        VARCHAR(10)  NULL DEFAULT NULL
                    COMMENT 'nl or en when chosen in Instellingen › Taal; NULL follows the device',
    `units`         ENUM('metric','imperial') NULL DEFAULT NULL
                    COMMENT 'Instellingen › Eenheden; NULL is metric. Only how values are shown and typed',
    -- Voorkeuren (migration 024): where a week starts, and the three
    -- accessibility choices that hold whatever the device says. On the
    -- account, so the website and the app agree.
    `week_start`    ENUM('monday','sunday') NULL DEFAULT NULL
                    COMMENT 'Voorkeuren › Eerste dag van de week; NULL is Monday',
    `text_large`    TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Toegankelijkheid › Grotere tekst: 1 = larger text, whatever the device says',
    `contrast_more` TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Toegankelijkheid › Extra contrast: 1 = stronger borders and text',
    `motion_off`    TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Toegankelijkheid › Animaties uit: 1 = no animations, whatever the device says',
    -- Meldingen (migration 026): the hour from which a red dot asks for
    -- today's Voeding cijfer when it is not given yet.
    `rating_notice_hour` TINYINT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Meldingen › Stip bij Voeding: the hour the dot appears; NULL is 20',
    `allow_friend_requests` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'Vriendverzoeken toestaan: 0 = nobody can send this account a new request',
    `leaderboard_avatar` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'Profielfoto op de ranglijst: 0 = the boards show the initial, not the picture',
    -- Ownify AI (migration 015): nothing goes to Google Gemini until this is 1
    -- for the wording in config/ai.php (consent_version).
    `ai_consent`    TINYINT(1)   NULL DEFAULT NULL
                    COMMENT 'Ownify AI: NULL never asked, 1 allowed, 0 declined or withdrawn',
    `ai_consent_version` VARCHAR(32) NULL DEFAULT NULL
                    COMMENT 'The consent wording that was answered (config/ai.php consent_version)',
    `ai_consent_at` DATETIME     NULL DEFAULT NULL
                    COMMENT 'When the Ownify AI consent was last answered',
    -- Insights (migration 028): Gemini may reword them only when this is 1
    -- for the wording in config/insights.php (consent_version). A separate
    -- question from ai_consent, which is about the chat.
    `ai_insights_consent` TINYINT(1) NULL DEFAULT NULL
                    COMMENT 'Gemini may reword insights: NULL never asked, 1 allowed, 0 declined or withdrawn',
    `ai_insights_consent_version` VARCHAR(32) NULL DEFAULT NULL
                    COMMENT 'The wording that was answered (config/insights.php consent_version)',
    `ai_insights_consent_at` DATETIME NULL DEFAULT NULL
                    COMMENT 'When the insight-wording consent was last answered',
    -- The first days (migration 016, docs/FIRST-DAYS.md): what the person
    -- most wants to understand, and the setup a new account starts with.
    -- Only registration writes 'pending'; an account from before the setup
    -- existed keeps NULL and never sees it.
    `focus`         ENUM('general','sleep','energy','fitness','weight') NULL DEFAULT NULL
                    COMMENT 'What the person most wants to understand; NULL = never chosen (general)',
    `setup_state`   ENUM('pending','done') NULL DEFAULT NULL
                    COMMENT 'First setup: NULL = account from before it existed, pending = not finished, done',
    `setup_done_at` DATETIME     NULL DEFAULT NULL
                    COMMENT 'When the first setup was finished: day 1 of the baseline',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One row per way of signing in. A user may hold several, which is why
-- authentication is separate from the account: connecting Google later must
-- not create a second user.
--
--   provider = 'email'   provider_subject = the normalised email address
--                        password_hash    = password_hash(), never plain text
--   provider = 'google'  provider_subject = the Google 'sub' claim
--
-- No provider password or token is ever stored here.
CREATE TABLE `user_auth_identities` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `provider`          ENUM('email','google') NOT NULL,
    `provider_subject`  VARCHAR(191) NOT NULL COMMENT 'Email address, or the provider subject id',
    `email`             VARCHAR(191) NULL,
    `email_verified_at` DATETIME     NULL,
    `password_hash`     VARCHAR(255) NULL COMMENT 'email provider only; bcrypt/argon2 via password_hash()',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at`     DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_auth_provider_subject` (`provider`, `provider_subject`),
    KEY `idx_auth_user` (`user_id`),
    CONSTRAINT `fk_auth_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Staying signed in: one row per browser that signed in and has not signed
-- out. The PHP session does not outlive the browser or a short idle spell on
-- the server; this does, and puts the session back when it is gone. The
-- cookie is `selector.validator` and only the validator's SHA-256 is kept, so
-- a dump signs nobody in. It is rotated on every use; signing out deletes the
-- row. See includes/persistent-login.php.
CREATE TABLE `user_login_tokens` (
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


-- Failed sign-ins, so password guessing is slowed down: a name or e-mail
-- from one address gets a limited number of tries per quarter of an hour.
-- Keyed by a SHA-256 of both — neither is stored as text — and no link to an
-- account, because most keys belong to none. Rows older than a day are
-- deleted as new ones arrive. See includes/auth-throttle.php.
CREATE TABLE `auth_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`       VARCHAR(16) NOT NULL COMMENT 'login, register',
    `key_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the typed name or e-mail and the source address; neither is stored as text',
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_key` (`action`, `key_hash`, `attempted_at`),
    KEY `idx_attempt_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  1b. ACCOUNT SECURITY (migration 027, 28.0) — docs/ACCOUNT-SECURITY.md
-- ============================================================================
-- Every address column holds the canonical form only, and a CHECK says so:
-- the rule and its tests are in database/migrations/027-account-security.sql
-- and tools/canonical-check.php (which reads that file, not this one).

CREATE TABLE `pending_registrations` (
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

CREATE TABLE `account_emails` (
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

CREATE TABLE `account_email_conflicts` (
    `email`        VARCHAR(191) NOT NULL COMMENT 'The address as canonicalised by LOWER(TRIM())',
    `reason`       VARCHAR(32)  NOT NULL COMMENT 'shared_google | not_canonical | already_owned | unverified_shadow',
    `user_ids`     VARCHAR(255) NOT NULL COMMENT 'The accounts involved, for the person resolving it',
    `found_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`  DATETIME NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_email_tokens` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `purpose`        ENUM('verify_signup','verify_link','verify_change','password_reset','revert_email') NOT NULL,
    `email`          VARCHAR(191) NOT NULL COMMENT 'Canonical address the link was sent to',
    `change_id`      BIGINT UNSIGNED NULL COMMENT 'verify_change and revert_email: the address change it belongs to',
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

CREATE TABLE `email_changes` (
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

CREATE TABLE `email_change_google_baseline` (
    `change_id`    BIGINT UNSIGNED NOT NULL,
    `identity_id`  BIGINT UNSIGNED NOT NULL COMMENT 'user_auth_identities.id of a Google identity present at confirmation',
    PRIMARY KEY (`change_id`, `identity_id`),
    CONSTRAINT `fk_baseline_change` FOREIGN KEY (`change_id`) REFERENCES `email_changes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mail_outbox` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind`        VARCHAR(24) NOT NULL COMMENT 'signup, link, change, reset, password, email_changed, google_removed, already, none',
    `recipient`   VARCHAR(191) NULL COMMENT 'Canonical; NULL for kind = none (a decoy)',
    `payload`     TEXT NULL COMMENT 'Encrypted JSON (app key): username, link, locale, facts. Never a password',
    `message_id`  VARCHAR(128) NULL COMMENT 'Fixed per job, so a duplicate send carries the same Message-ID',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `send_after`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `claim_token` CHAR(32) NULL COMMENT 'Random per claim; only its holder may finish the job',
    `lease_until` DATETIME NULL COMMENT 'Claimable again once this has passed (a worker that died)',
    `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Claims so far; at 5 the job is deleted',
    `expires_at`  DATETIME NOT NULL COMMENT 'Deleted unsent after this (its link is dead anyway)',
    PRIMARY KEY (`id`),
    KEY `idx_outbox_expiry` (`expires_at`),
    KEY `idx_outbox_due` (`lease_until`, `send_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  2. SOURCES AND THE METRIC CATALOGUE
-- ============================================================================

-- Where a record came from. Rows are seeded below; adding Health Connect or a
-- new ring later is one INSERT, not a migration.
CREATE TABLE `data_sources` (
    `id`    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`  VARCHAR(40) NOT NULL,
    `label` VARCHAR(80) NOT NULL,
    `kind`  ENUM('manual','platform','wearable','derived') NOT NULL DEFAULT 'manual',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_source_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- What a measurement means. The catalogue is what keeps the schema open:
-- a new nutrient or a new wearable metric is a row here, not a new column.
CREATE TABLE `health_metric_types` (
    `code`        VARCHAR(60)  NOT NULL,
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`       VARCHAR(100) NOT NULL,
    `unit`        VARCHAR(20)  NULL,
    `domain`      ENUM('sleep','nutrition','training','body','vital') NOT NULL,
    `aggregation` ENUM('sum','avg','last','min','max') NOT NULL DEFAULT 'last'
                  COMMENT 'How to roll several readings up into a day',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_metric_code` (`code`),
    KEY `idx_metric_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  3. HEALTH DATA  — PRIVATE
-- ============================================================================

-- Body measurements over time. Height and weight live here rather than on the
-- profile so that updating them keeps the history intact; the current value is
-- simply the newest row (see user_current_measurement()).
CREATE TABLE `user_measurements` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `measurement_type` ENUM('height','weight','body_fat_pct','waist_cm','lean_mass') NOT NULL,
    `value`            DECIMAL(8,3) NOT NULL,
    `unit`             VARCHAR(12)  NOT NULL,
    `source_id`        SMALLINT UNSIGNED NULL,
    `external_id`      VARCHAR(191) NULL COMMENT 'Id in the system it came from',
    `measured_at`      DATETIME     NOT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_meas_current` (`user_id`, `measurement_type`, `measured_at`),
    UNIQUE KEY `uq_measurement_external` (`user_id`, `source_id`, `external_id`),
    CONSTRAINT `fk_meas_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_meas_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One sleep session. Stage totals sit on the session because that is the grain
-- the app reads them at; anything measured DURING the night (heart rate, HRV,
-- SpO2, temperature) is a health_metrics row pointing back here.
CREATE TABLE `sleep_sessions` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `source_id`           SMALLINT UNSIGNED NULL,
    `external_id`         VARCHAR(191) NULL COMMENT 'Id in the system it came from',
    `night_of`            DATE     NOT NULL COMMENT 'The date the night is filed under',
    `started_at`          DATETIME NOT NULL,
    `ended_at`            DATETIME NOT NULL,
    `duration_minutes`    SMALLINT UNSIGNED NULL,
    `time_in_bed_minutes` SMALLINT UNSIGNED NULL,
    `efficiency_pct`      DECIMAL(5,2) NULL,
    `awakenings`          SMALLINT UNSIGNED NULL,
    `awake_minutes`       SMALLINT UNSIGNED NULL,
    `light_minutes`       SMALLINT UNSIGNED NULL,
    `deep_minutes`        SMALLINT UNSIGNED NULL,
    `rem_minutes`         SMALLINT UNSIGNED NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sleep_session` (`user_id`, `started_at`),
    UNIQUE KEY `uq_sleep_external` (`user_id`, `source_id`, `external_id`),
    KEY `idx_sleep_user_night` (`user_id`, `night_of`),
    CONSTRAINT `fk_sleep_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sleep_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A night's sleep stages, period by period, as the device recorded them — the
-- periods sleep_sessions' minutes per stage were added up from, for the Slaap
-- timeline (migration 018, docs/SLEEP.md). Replaced whenever the session is
-- synced again.
CREATE TABLE `sleep_stages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sleep_session_id` BIGINT UNSIGNED NOT NULL,
    `stage`            TINYINT UNSIGNED NOT NULL
                       COMMENT 'Health Connect: 1 awake, 2 sleeping, 3 out of bed, 4 light, 5 deep, 6 REM, 7 awake in bed',
    `started_at`       DATETIME NOT NULL,
    `ended_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_stage_session` (`sleep_session_id`, `started_at`),
    CONSTRAINT `fk_stage_session` FOREIGN KEY (`sleep_session_id`)
        REFERENCES `sleep_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Heart rate through the day, minute by minute: the mean of the samples one
-- app recorded in each minute (migration 019, docs/TRAINING.md) — for the
-- Training page's day, week and session charts and its daily averages.
CREATE TABLE `heart_rate_minutes` (
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `minute_at`   DATETIME NOT NULL COMMENT 'The minute, on the clock the samples were recorded on',
    `data_origin` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'The app that recorded it (package name), or source:<id>',
    `bpm`         DECIMAL(5,1) UNSIGNED NOT NULL COMMENT 'Mean of the samples in this minute',
    `samples`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `minute_at`, `data_origin`),
    CONSTRAINT `fk_hrm_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A meal or a drink. The nutrients themselves are health_metrics rows pointing
-- at the entry, so adding "omega 3" later needs no change to this table.
CREATE TABLE `nutrition_entries` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `source_id`   SMALLINT UNSIGNED NULL,
    `external_id` VARCHAR(191) NULL COMMENT 'Id in the system it came from',
    `meal_type`   ENUM('breakfast','lunch','dinner','snack','drink','other') NOT NULL DEFAULT 'other',
    `label`       VARCHAR(120) NULL,
    `consumed_at` DATETIME NOT NULL,
    `notes`       VARCHAR(255) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nutrition_user_time` (`user_id`, `consumed_at`),
    UNIQUE KEY `uq_nutrition_external` (`user_id`, `source_id`, `external_id`),
    CONSTRAINT `fk_nutrition_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nutrition_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One training session. Every column is nullable on purpose: a phone gives
-- steps and duration, a watch adds heart rate, a bike computer adds cadence.
CREATE TABLE `workouts` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `source_id`        SMALLINT UNSIGNED NULL,
    `external_id`      VARCHAR(191) NULL COMMENT 'Id in the system it came from',
    `activity_type`    VARCHAR(40) NOT NULL DEFAULT 'other',
    `started_at`       DATETIME NOT NULL,
    `ended_at`         DATETIME NULL,
    `duration_seconds` INT UNSIGNED NULL,
    `distance_m`       DECIMAL(10,2) NULL,
    `active_kcal`      SMALLINT UNSIGNED NULL,
    `total_kcal`       SMALLINT UNSIGNED NULL,
    `avg_hr`           SMALLINT UNSIGNED NULL,
    `max_hr`           SMALLINT UNSIGNED NULL,
    `avg_speed_kmh`    DECIMAL(6,2) NULL,
    `avg_cadence`      SMALLINT UNSIGNED NULL,
    `elevation_gain_m` SMALLINT UNSIGNED NULL,
    `perceived_effort` TINYINT UNSIGNED NULL COMMENT 'Manual 1-10 if the user adds it',
    `notes`            VARCHAR(255) NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_workout_session` (`user_id`, `started_at`),
    UNIQUE KEY `uq_workout_external` (`user_id`, `source_id`, `external_id`),
    KEY `idx_workout_user_time` (`user_id`, `started_at`),
    CONSTRAINT `fk_workout_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_workout_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Time in each heart-rate zone. Its own table because not every device reports
-- zones, and the number of zones is not fixed.
CREATE TABLE `workout_hr_zones` (
    `workout_id` BIGINT UNSIGNED NOT NULL,
    `zone`       TINYINT UNSIGNED NOT NULL,
    `seconds`    INT UNSIGNED NOT NULL,
    PRIMARY KEY (`workout_id`, `zone`),
    CONSTRAINT `fk_zone_workout` FOREIGN KEY (`workout_id`)
        REFERENCES `workouts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Every scalar reading, whatever its frequency: a daily step count, a nutrient
-- on one meal, a heart rate sampled through the night. The context columns say
-- what a reading belongs to; all three are null for a standalone daily value.
CREATE TABLE `health_metrics` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`            BIGINT UNSIGNED NOT NULL,
    `metric_type_id`     SMALLINT UNSIGNED NOT NULL,
    `source_id`          SMALLINT UNSIGNED NULL,
    `data_origin`        VARCHAR(191) NULL COMMENT 'The Health Connect app that wrote it (package name); NULL when not from Health Connect',
    `external_id`        VARCHAR(191) NULL COMMENT 'Id in the system it came from',
    `value`              DECIMAL(14,4) NOT NULL,
    -- The interval a value covers runs from started_at to recorded_at; a
    -- reading at one moment has no started_at (migration 012).
    `started_at`         DATETIME NULL COMMENT 'Start of the interval the value covers (recorded_at is its end); NULL for a reading at one moment',
    `recorded_at`        DATETIME NOT NULL,
    -- Stored generated column so "everything on this day" stays an index hit.
    `recorded_on`        DATE AS (DATE(`recorded_at`)) STORED,
    `sleep_session_id`   BIGINT UNSIGNED NULL,
    `workout_id`         BIGINT UNSIGNED NULL,
    `nutrition_entry_id` BIGINT UNSIGNED NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hm_user_type_time` (`user_id`, `metric_type_id`, `recorded_at`),
    KEY `idx_hm_user_day` (`user_id`, `recorded_on`),
    UNIQUE KEY `uq_metric_external` (`user_id`, `source_id`, `external_id`),
    KEY `idx_hm_sleep` (`sleep_session_id`),
    KEY `idx_hm_workout` (`workout_id`),
    KEY `idx_hm_meal` (`nutrition_entry_id`),
    CONSTRAINT `fk_hm_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hm_type` FOREIGN KEY (`metric_type_id`)
        REFERENCES `health_metric_types` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_hm_source` FOREIGN KEY (`source_id`)
        REFERENCES `data_sources` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_hm_sleep` FOREIGN KEY (`sleep_session_id`)
        REFERENCES `sleep_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hm_workout` FOREIGN KEY (`workout_id`)
        REFERENCES `workouts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hm_meal` FOREIGN KEY (`nutrition_entry_id`)
        REFERENCES `nutrition_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A day's total of a reconciled metric (steps, distance, calories), as
-- health_metric_totals() worked it out from health_metrics — kept so a long
-- goal window is not worked out again on every page. The fingerprint says
-- which readings it came from; when they change it is worked out again on the
-- next read. Nothing here is a source: emptying it only costs time.
CREATE TABLE `health_metric_day_totals` (
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `metric_type_id` SMALLINT UNSIGNED NOT NULL,
    `day`            DATE NOT NULL,
    `total`          DECIMAL(14,4) NULL COMMENT 'NULL: nothing counted on this day',
    `readings_hash`  CHAR(32) NOT NULL COMMENT 'Fingerprint of the readings and the rule it was worked out from',
    `computed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `metric_type_id`, `day`),
    CONSTRAINT `fk_hmdt_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hmdt_type` FOREIGN KEY (`metric_type_id`)
        REFERENCES `health_metric_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- What the Health Score was on a day, and what it was calculated from. The
-- score itself is always calculated from the health records over the rolling
-- 168-hour window (includes/health-score.php); this is the record of each
-- day's result — one row per person, day and category, written whenever the
-- score is calculated that day and never again once the day has passed. It is
-- the history the Scorekompas shows. algorithm_version lets the formula change
-- without invalidating what is already stored.
CREATE TABLE `daily_scores` (
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `score_date`        DATE NOT NULL,
    `domain`            ENUM('overall','sleep','nutrition','training') NOT NULL,
    `score`             TINYINT UNSIGNED NULL COMMENT '0-100, null while there is not enough data',
    `data_days`         SMALLINT UNSIGNED NULL COMMENT 'Days with data in the window the score was calculated over',
    `inputs`            TEXT NULL COMMENT 'JSON: each component of the score, null where there was no data',
    `valid_until`       DATE NULL COMMENT 'The last day this score holds without new input; null: not carried',
    `algorithm_version` VARCHAR(20) NULL,
    `computed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `score_date`, `domain`),
    KEY `idx_scores_domain_date` (`domain`, `score_date`),
    CONSTRAINT `fk_scores_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  3b. OUTSIDE CONNECTIONS  — PRIVATE
-- ============================================================================

-- One row per user per external health platform. Tokens are stored as
-- ciphertext by the application (includes/crypto.php), so a database dump does
-- not hand over anybody's health account, and nothing here reaches a browser.
CREATE TABLE `user_integrations` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `provider`            VARCHAR(40) NOT NULL COMMENT 'Matches data_sources.code',
    `status`              ENUM('connected','disconnected','revoked','error') NOT NULL DEFAULT 'disconnected',
    `external_account_id` VARCHAR(191) NULL,
    `external_account_label` VARCHAR(191) NULL COMMENT 'What to show the owner, e.g. an e-mail',
    `scopes`              TEXT NULL COMMENT 'What was actually granted, which may be less than was asked',
    `access_token`        BLOB NULL COMMENT 'Ciphertext, never a readable token',
    `refresh_token`       BLOB NULL COMMENT 'Ciphertext, never a readable token',
    `token_expires_at`    DATETIME NULL,
    `connected_at`        DATETIME NULL,
    `last_sync_at`        DATETIME NULL COMMENT 'Last run that finished without error',
    `sync_started_at`     DATETIME NULL COMMENT 'A sync running now; cleared when it ends',
    `last_sync_status`    ENUM('never','ok','partial','failed') NOT NULL DEFAULT 'never',
    `last_error`          VARCHAR(255) NULL COMMENT 'For the owner, never a raw API body',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_integration_user_provider` (`user_id`, `provider`),
    KEY `idx_integration_status` (`status`),
    CONSTRAINT `fk_integration_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A connection being made with a cloud source's sign-in (Polar): the state
-- sent to the provider (only its hash), whose it is, and where it may be
-- finished — the browser session that started it, or, from the app, a
-- confirmation page. Single use, ten minutes.
CREATE TABLE `integration_oauth_states` (
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


-- ============================================================================
--  3c. PAIRED DEVICES  — PRIVATE
-- ============================================================================
--  Health Connect cannot be read from a server; an app on the phone reads
--  it and posts here, so that app needs to prove which account it sends
--  for. It cannot use the session cookie and must not hold the password, so
--  it holds a token of its own, minted by exchanging a short pairing code
--  the website shows once. One token per device, so losing a
--  phone costs you that phone rather than every phone.

CREATE TABLE `device_pairing_codes` (
    `code_hash`   CHAR(64) NOT NULL COMMENT 'sha256 of the code; the code itself is shown once',
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `provider`    VARCHAR(40) NOT NULL,
    `expires_at`  DATETIME NOT NULL,
    `consumed_at` DATETIME NULL COMMENT 'A code works once',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`code_hash`),
    KEY `idx_pairing_user` (`user_id`, `provider`),
    KEY `idx_pairing_expiry` (`expires_at`),
    CONSTRAINT `fk_pairing_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hashed rather than encrypted: the token is never needed back, only
-- recognised, so a dump yields nothing replayable.
-- scope: 'sync' is what a pairing code gets (upload records, read what a sync
-- needs); 'account' is what signing in in the Ownify app gets (acts as the
-- account). An account token lapses after a year unused — last_seen_at, set
-- on every authenticated request, says when that is (includes/devices.php).
CREATE TABLE `user_devices` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `provider`     VARCHAR(40) NOT NULL COMMENT 'Matches data_sources.code',
    `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256 of the device token',
    `scope`        ENUM('sync','account') NOT NULL DEFAULT 'sync' COMMENT 'sync: pairing code, uploads only. account: signed in in the app, acts as the account',
    `label`        VARCHAR(80) NULL COMMENT 'What to call it on screen',
    `custom_label` VARCHAR(80) NULL DEFAULT NULL COMMENT 'The name the owner gave it; shown instead of label, never rewritten by the app',
    `platform`     VARCHAR(40) NULL COMMENT 'android, ios',
    `app_version`  VARCHAR(40) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NULL,
    `last_sync_at` DATETIME NULL,
    `revoked_at`   DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_token` (`token_hash`),
    KEY `idx_device_user` (`user_id`, `provider`, `revoked_at`),
    CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  4. GOALS  — PRIVATE (goals and what hangs off them; the vocabulary is shared)
-- ============================================================================
--  Migration 025 and docs/GOALS.md: six categories, the measurements a goal
--  can be about, the units it is shown in, and the goals themselves. The
--  vocabulary rows are the codes in config/goals.php.

CREATE TABLE `goal_categories` (
    `code`       VARCHAR(20) NOT NULL,
    `sort_order` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_categories` (`code`, `sort_order`) VALUES
    ('sleep', 1), ('nutrition', 2), ('training', 3), ('activity', 4), ('body', 5), ('recovery', 6)
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

CREATE TABLE `goal_units` (
    `code`      VARCHAR(20) NOT NULL,
    `canonical` VARCHAR(20) NOT NULL COMMENT 'the unit a figure typed in this one is stored in',
    `factor`    DECIMAL(20,12) NOT NULL COMMENT 'stored = typed x factor',
    PRIMARY KEY (`code`),
    KEY `idx_goal_units_canonical` (`canonical`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_units` (`code`, `canonical`, `factor`) VALUES
    ('kg', 'kg', 1), ('lb', 'kg', 0.45359237),
    ('cm', 'cm', 1), ('in', 'cm', 2.54),
    ('km', 'km', 1), ('mi', 'km', 1.609344),
    ('min', 'min', 1), ('h', 'min', 60),
    ('l', 'l', 1), ('ml', 'l', 0.001), ('floz', 'l', 0.0295735295625),
    ('kcal', 'kcal', 1), ('kj', 'kcal', 0.239005736137667),
    ('g', 'g', 1), ('mg', 'mg', 1), ('pct', 'pct', 1), ('bpm', 'bpm', 1), ('ms', 'ms', 1),
    ('score10', 'score10', 1), ('steps', 'steps', 1), ('floors', 'floors', 1),
    ('sessions', 'sessions', 1), ('times', 'times', 1), ('pages', 'pages', 1),
    ('glasses', 'glasses', 1), ('reps', 'reps', 1)
ON DUPLICATE KEY UPDATE `canonical` = VALUES(`canonical`), `factor` = VALUES(`factor`);

CREATE TABLE `goal_measurements` (
    `code`              VARCHAR(40) NOT NULL,
    `category_code`     VARCHAR(20) NOT NULL,
    `source_kind`       ENUM('metric','measurement','workout','manual') NOT NULL
                        COMMENT 'which table its figures come from; manual = kept by hand',
    `source_key`        VARCHAR(60) NULL COMMENT 'metric code, measurement type, or workout aspect',
    `unit_code`         VARCHAR(20) NOT NULL COMMENT 'the unit its figures are stored in',
    `direction`         ENUM('increase','decrease','both') NOT NULL COMMENT 'which way is better; both = the person says',
    `allows_milestone`  TINYINT(1) NOT NULL DEFAULT 0,
    `allows_daily`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'streak: a target every day',
    `allows_period`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'streak: a number of times per period',
    `allows_accumulate` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`        SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`code`),
    KEY `idx_goal_measurements_category` (`category_code`),
    KEY `idx_goal_measurements_unit` (`unit_code`),
    CONSTRAINT `fk_goal_measurement_category` FOREIGN KEY (`category_code`) REFERENCES `goal_categories` (`code`),
    CONSTRAINT `fk_goal_measurement_unit` FOREIGN KEY (`unit_code`) REFERENCES `goal_units` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `goal_measurements`
    (`code`, `category_code`, `source_kind`, `source_key`, `unit_code`, `direction`,
     `allows_milestone`, `allows_daily`, `allows_period`, `allows_accumulate`, `sort_order`) VALUES
    ('sleep_duration',   'sleep',     'metric',      'sleep_duration',   'min',      'both',     1, 1, 0, 0, 1),
    ('sleep_efficiency', 'sleep',     'metric',      'sleep_efficiency', 'pct',      'increase', 1, 1, 0, 0, 2),
    ('water',            'nutrition', 'metric',      'water',            'l',        'increase', 0, 1, 0, 1, 3),
    ('energy',           'nutrition', 'metric',      'energy',           'kcal',     'both',     0, 1, 0, 0, 4),
    ('protein',          'nutrition', 'metric',      'protein',          'g',        'increase', 0, 1, 0, 0, 5),
    ('carbs',            'nutrition', 'metric',      'carbs',            'g',        'both',     0, 1, 0, 0, 6),
    ('fat',              'nutrition', 'metric',      'fat',              'g',        'both',     0, 1, 0, 0, 7),
    ('fibre',            'nutrition', 'metric',      'fibre',            'g',        'increase', 0, 1, 0, 0, 8),
    ('sugar',            'nutrition', 'metric',      'sugar',            'g',        'decrease', 0, 1, 0, 0, 9),
    ('saturated_fat',    'nutrition', 'metric',      'saturated_fat',    'g',        'decrease', 0, 1, 0, 0, 10),
    ('sodium',           'nutrition', 'metric',      'sodium',           'mg',       'decrease', 0, 1, 0, 0, 11),
    ('nutrition_rating', 'nutrition', 'metric',      'nutrition_rating', 'score10',  'increase', 1, 1, 0, 0, 12),
    ('workouts',         'training',  'workout',     'sessions',         'sessions', 'increase', 0, 1, 1, 1, 13),
    ('workout_minutes',  'training',  'workout',     'minutes',          'min',      'increase', 0, 1, 0, 1, 14),
    ('steps',            'activity',  'metric',      'steps',            'steps',    'increase', 1, 1, 0, 1, 15),
    ('distance',         'activity',  'metric',      'distance',         'km',       'increase', 1, 1, 0, 1, 16),
    ('active_energy',    'activity',  'metric',      'active_energy',    'kcal',     'increase', 0, 1, 0, 1, 17),
    ('floors',           'activity',  'metric',      'floors',           'floors',   'increase', 1, 1, 0, 1, 18),
    ('weight',           'body',      'measurement', 'weight',           'kg',       'both',     1, 0, 0, 0, 19),
    ('body_fat_pct',     'body',      'measurement', 'body_fat_pct',     'pct',      'both',     1, 0, 0, 0, 20),
    ('waist_cm',         'body',      'measurement', 'waist_cm',         'cm',       'both',     1, 0, 0, 0, 21),
    ('lean_mass',        'body',      'measurement', 'lean_mass',        'kg',       'both',     1, 0, 0, 0, 22),
    ('resting_hr',       'recovery',  'metric',      'resting_hr',       'bpm',      'decrease', 1, 1, 0, 0, 23),
    ('hrv',              'recovery',  'metric',      'hrv',              'ms',       'increase', 1, 1, 0, 0, 24),
    ('sleeping_hr',      'recovery',  'metric',      'sleeping_hr',      'bpm',      'decrease', 1, 1, 0, 0, 25),
    ('meditation',       'recovery',  'manual',      NULL,               'min',      'increase', 0, 1, 1, 1, 26)
ON DUPLICATE KEY UPDATE
    `category_code` = VALUES(`category_code`), `source_kind` = VALUES(`source_kind`),
    `source_key` = VALUES(`source_key`), `unit_code` = VALUES(`unit_code`), `direction` = VALUES(`direction`),
    `allows_milestone` = VALUES(`allows_milestone`), `allows_daily` = VALUES(`allows_daily`),
    `allows_period` = VALUES(`allows_period`), `allows_accumulate` = VALUES(`allows_accumulate`),
    `sort_order` = VALUES(`sort_order`);


CREATE TABLE `goals` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `category_code`    VARCHAR(20) NOT NULL,
    `measurement_code` VARCHAR(40) NULL COMMENT 'NULL: an Eigen gewoonte, kept by hand',
    `goal_type`        ENUM('milestone','streak','accumulate') NOT NULL,
    `streak_variant`   ENUM('daily','period') NULL COMMENT 'a streak only',
    `tracking`         ENUM('auto','manual') NOT NULL COMMENT 'auto = read from the person''s own health data',
    `unit_code`        VARCHAR(20) NULL COMMENT 'the unit it is shown and typed in (the goal''s own choice)',
    `custom_unit`      VARCHAR(20) NULL COMMENT 'an Eigen gewoonte''s own unit word, counted as typed',
    `direction`        ENUM('increase','decrease') NOT NULL DEFAULT 'increase'
                       COMMENT 'milestone: which way is better; daily streak: at least / at most',
    `target_value`     DECIMAL(14,4) NULL COMMENT 'stored unit — milestone: to reach; daily: each day; accumulate: the total',
    `frequency`        SMALLINT UNSIGNED NULL COMMENT 'period streak: times per period',
    `period_unit`      ENUM('week','month','quarter','year') NULL COMMENT 'period streak: counted from start_date',
    `baseline_value`   DECIMAL(14,4) NULL COMMENT 'milestone: where it started, fixed when it was made',
    `baseline_on`      DATE NULL COMMENT 'the day that value was measured; NULL when typed',
    `baseline_source`  ENUM('start_day','before_start','manual') NULL,
    `start_date`       DATE NOT NULL,
    `end_date`         DATE NOT NULL COMMENT 'the last day of the period, included',
    `status`           ENUM('active','paused','completed','ended') NOT NULL DEFAULT 'active'
                       COMMENT 'completed = reached 100%; ended = its period ended below 100%',
    `priority`         ENUM('primary','secondary') NOT NULL DEFAULT 'secondary',
    `current_value`    DECIMAL(14,4) NULL COMMENT 'the figure the percentage is worked out from (stored unit)',
    `progress_pct`     DECIMAL(5,2) NULL,
    `progress_at`      DATETIME NULL,
    `final_pct`        DECIMAL(5,2) NULL COMMENT 'frozen when it finished; never changed after',
    `completed_at`     DATETIME NULL COMMENT 'when it finished: reached, or its period over',
    `create_token`     CHAR(36) NULL COMMENT 'the wizard''s one-time key: the same goal is never made twice',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_goal_create_token` (`user_id`, `create_token`),
    KEY `idx_goals_user_status` (`user_id`, `status`),
    KEY `idx_goals_user_priority` (`user_id`, `status`, `priority`),
    KEY `idx_goals_category` (`category_code`),
    KEY `idx_goals_measurement` (`measurement_code`),
    KEY `idx_goals_unit` (`unit_code`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_goals_category` FOREIGN KEY (`category_code`) REFERENCES `goal_categories` (`code`),
    CONSTRAINT `fk_goals_measurement` FOREIGN KEY (`measurement_code`) REFERENCES `goal_measurements` (`code`),
    CONSTRAINT `fk_goals_unit` FOREIGN KEY (`unit_code`) REFERENCES `goal_units` (`code`),
    CONSTRAINT `chk_goal_period_dates` CHECK (`end_date` > `start_date`),
    CONSTRAINT `chk_goal_streak_variant` CHECK ((`goal_type` = 'streak') = (`streak_variant` IS NOT NULL)),
    CONSTRAINT `chk_goal_frequency` CHECK ((COALESCE(`streak_variant`, '') = 'period')
                                           = (`frequency` IS NOT NULL AND `frequency` > 0 AND `period_unit` IS NOT NULL)),
    CONSTRAINT `chk_goal_target` CHECK (COALESCE(`streak_variant`, '') = 'period'
                                        OR (`target_value` IS NOT NULL AND `target_value` > 0)),
    CONSTRAINT `chk_goal_unit` CHECK ((`unit_code` IS NOT NULL) <> (`custom_unit` IS NOT NULL)),
    CONSTRAINT `chk_goal_baseline` CHECK (`goal_type` <> 'milestone' OR `baseline_value` IS NOT NULL),
    CONSTRAINT `chk_goal_finished` CHECK ((`status` IN ('completed','ended'))
                                          = (`final_pct` IS NOT NULL AND `completed_at` IS NOT NULL)),
    CONSTRAINT `chk_goal_percent` CHECK ((`progress_pct` IS NULL OR `progress_pct` BETWEEN 0 AND 100)
                                         AND (`final_pct` IS NULL OR `final_pct` BETWEEN 0 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a person logged on the goal's page, one row per entry: a new
-- measurement (Mijlpaal: the latest counts), an amount added (Optellen), or
-- a day ticked off (Streak: `value` NULL, one per day).
CREATE TABLE `goal_entries` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id`    BIGINT UNSIGNED NOT NULL,
    `entry_date` DATE NOT NULL,
    `value`      DECIMAL(14,4) NULL COMMENT 'stored unit; NULL for a day ticked off',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_goal_entries_day` (`goal_id`, `entry_date`),
    CONSTRAINT `fk_goal_entries_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A daily streak's days as last worked out (includes/goal-progress.php).
CREATE TABLE `goal_day_results` (
    `goal_id` BIGINT UNSIGNED NOT NULL,
    `day`     DATE NOT NULL,
    `value`   DECIMAL(14,4) NULL COMMENT 'the day''s figure, stored unit; NULL: nothing recorded',
    `state`   ENUM('met','missed','unknown','pending') NOT NULL,
    PRIMARY KEY (`goal_id`, `day`),
    CONSTRAINT `fk_goal_day_results_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A per-period streak's periods as last worked out. `required` is the
-- frequency, or for a last period cut short by the end date its share,
-- rounded (a half up); a period that comes to 0 is `skipped`.
CREATE TABLE `goal_period_results` (
    `goal_id`      BIGINT UNSIGNED NOT NULL,
    `period_index` SMALLINT UNSIGNED NOT NULL,
    `starts_on`    DATE NOT NULL,
    `ends_on`      DATE NOT NULL,
    `required`     SMALLINT UNSIGNED NOT NULL,
    `achieved`     SMALLINT UNSIGNED NOT NULL,
    `state`        ENUM('met','missed','pending','skipped') NOT NULL,
    PRIMARY KEY (`goal_id`, `period_index`),
    CONSTRAINT `fk_goal_period_results_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where a goal stood at the end of each day it was worked out on: the line
-- and the "that day" of the Verloop chart.
CREATE TABLE `goal_progress` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id`          BIGINT UNSIGNED NOT NULL,
    `recorded_on`      DATE NOT NULL,
    `current_value`    DECIMAL(14,4) NULL,
    `percent_complete` DECIMAL(5,2) NULL,
    `computed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_goal_day` (`goal_id`, `recorded_on`),
    CONSTRAINT `fk_progress_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  5. COMMUNITY
-- ============================================================================

-- Friendship is symmetric — there is no follow. One row per pair, with the
-- pair stored in a fixed order so the unique key makes a duplicate or a
-- mirrored request impossible; requested_by records who asked.
--
-- A friend request is a `pending` row: created_at is when it was sent.
-- Accepting makes it `accepted`, declining closes it as `declined` (both set
-- responded_at), and removing a friend deletes the row. Whether somebody can
-- be sent a request at all is user_profiles.allow_friend_requests.
CREATE TABLE `friendships` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_low_id`  BIGINT UNSIGNED NOT NULL COMMENT 'Always the smaller of the two ids',
    `user_high_id` BIGINT UNSIGNED NOT NULL,
    `requested_by` BIGINT UNSIGNED NOT NULL,
    `status`       ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `responded_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_friend_pair` (`user_low_id`, `user_high_id`),
    KEY `idx_friend_low_status` (`user_low_id`, `status`),
    KEY `idx_friend_high_status` (`user_high_id`, `status`),
    CONSTRAINT `chk_friend_order` CHECK (`user_low_id` < `user_high_id`),
    CONSTRAINT `fk_friend_low` FOREIGN KEY (`user_low_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_friend_high` FOREIGN KEY (`user_high_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_friend_requester` FOREIGN KEY (`requested_by`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Blocking is one-directional, which is why it is not a friendship status:
-- A can block B without B blocking A. A block overrides any friendship row.
CREATE TABLE `user_blocks` (
    `blocker_id` BIGINT UNSIGNED NOT NULL,
    `blocked_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`blocker_id`, `blocked_id`),
    KEY `idx_block_blocked` (`blocked_id`),
    CONSTRAINT `chk_block_self` CHECK (`blocker_id` <> `blocked_id`),
    CONSTRAINT `fk_block_blocker` FOREIGN KEY (`blocker_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_block_blocked` FOREIGN KEY (`blocked_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Uitdagingen (docs/CHALLENGES.md). A challenge is Samen — everybody adds up
-- towards one target — or Wedstrijd — the most wins, and a tie on the last
-- day goes on until somebody leads. Anybody with its five-digit code can look
-- at it and then join it themselves until it is over; friends can also be
-- invited, and an invitation is only ever accepted by the person invited.
-- A participant sees the others' usernames, pictures (as the boards show
-- them) and totals for the challenge — the totals are worked out from the
-- synced data when shown, never copied in here.
CREATE TABLE `challenges` (
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

CREATE TABLE `challenge_members` (
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


-- ============================================================================
--  6. POINTS AND LEADERBOARDS
-- ============================================================================

-- What can earn points: the catalogue every award in the ledger points back
-- to. The values and thresholds live in config/points.php so they can be tuned
-- without a migration; `points` here is the most one event can earn.
CREATE TABLE `point_rules` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(60) NOT NULL,
    `label`      VARCHAR(120) NOT NULL,
    `domain`     ENUM('sleep','nutrition','training','general') NOT NULL DEFAULT 'general',
    `points`     INT NOT NULL DEFAULT 0,
    `cadence`    ENUM('per_event','daily','weekly') NOT NULL DEFAULT 'per_event',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rule_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The ledger: one row per award. Everything a leaderboard shows can be rebuilt
-- from this table, which is why the rollups below are caches and not truth.
-- `award_key` names the event and rule an award pays for — workout:123,
-- steps:2026-09-25, weekly_workouts:2026-09-21 — and is unique per person, so
-- the same event can never pay out twice however often it is synced.
-- `awarded_at` is when the activity happened, so a point lands in the month
-- the effort was made.
CREATE TABLE `point_events` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `rule_id`        SMALLINT UNSIGNED NULL,
    `award_key`      VARCHAR(120) NULL COMMENT 'The event and rule this pays for, e.g. workout:123 — unique per person',
    `points`         INT NOT NULL,
    `awarded_at`     DATETIME NOT NULL,
    `awarded_on`     DATE AS (DATE(`awarded_at`)) STORED,
    `reference_type` ENUM('sleep_session','workout','nutrition_entry','metric','manual','day','week') NULL,
    `reference_id`   BIGINT UNSIGNED NULL COMMENT 'Id within reference_type; intentionally not a FK',
    `note`           VARCHAR(160) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                     COMMENT 'Set when the award was re-evaluated, e.g. a step tier reached later',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_points_award` (`user_id`, `award_key`),
    KEY `idx_points_user_time` (`user_id`, `awarded_at`),
    KEY `idx_points_user_day` (`user_id`, `awarded_on`),
    KEY `idx_points_day` (`awarded_on`),
    CONSTRAINT `fk_points_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_points_rule` FOREIGN KEY (`rule_id`)
        REFERENCES `point_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Points rolled up per period, plus the national position.
--
--   period_type = 'month'    period_key = '2026-04'
--   period_type = 'year'     period_key = '2026'
--   period_type = 'alltime'  period_key = 'all'
--
-- `position` is the NATIONAL rank only. A friends ranking is not stored: it
-- depends on who is asking, so it is derived at query time by joining this
-- table to the asker's accepted friendships — cheap, and always correct.
CREATE TABLE `user_period_points` (
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `period_type` ENUM('month','year','alltime') NOT NULL,
    `period_key`  CHAR(7) NOT NULL,
    `points`      INT UNSIGNED NOT NULL DEFAULT 0,
    `position`    INT UNSIGNED NULL COMMENT 'National rank; 1 is best',
    `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `period_type`, `period_key`),
    -- Top 50 of a period, by points.
    KEY `idx_board_points` (`period_type`, `period_key`, `points`),
    -- Top 50 of a period, by an already computed position.
    KEY `idx_board_position` (`period_type`, `period_key`, `position`),
    CONSTRAINT `fk_period_points_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Best rank ever reached, per scope and period type. A separate concept from
-- the current position and from points: lower is better, and it never
-- decreases on its own.
CREATE TABLE `leaderboard_best_positions` (
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `scope`               ENUM('national','friends') NOT NULL,
    `period_type`         ENUM('month','year','alltime') NOT NULL,
    `best_position`       INT UNSIGNED NOT NULL,
    `achieved_period_key` CHAR(7) NOT NULL,
    `achieved_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `scope`, `period_type`),
    CONSTRAINT `fk_best_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  7. OWNIFY AI — the assistant (migration 015)
--  PRIVATE, like the health tables: every row is one person's, every query is
--  scoped to the signed-in user. What was sent to Gemini is not stored — only
--  what was said in the conversation.
-- ============================================================================

CREATE TABLE `ai_conversations` (
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

-- system = a note from Ownify itself ("Doel toegevoegd"). An assistant message
-- can carry one proposed change that happens only once its owner confirms it.
CREATE TABLE `ai_messages` (
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

-- Per person per day. `messages` is what the daily limit counts; the sum of
-- `gemini_calls` over everybody is what the project's shared free quota sees.
CREATE TABLE `ai_usage` (
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

-- Facts about Gemini itself, about nobody: until when its free quota is used up.
CREATE TABLE `ai_service_state` (
    `name`       VARCHAR(40) NOT NULL,
    `value`      VARCHAR(255) NULL,
    `until`      DATETIME NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  8. CATALOGUE ROWS
--  Reference data, not user data: the app needs these to exist.
-- ============================================================================

INSERT INTO `data_sources` (`code`, `label`, `kind`) VALUES
    ('manual',               'Handmatig ingevoerd',   'manual'),
    ('google_health_connect','Google Health Connect', 'platform'),
    ('google_health',        'Google Health',         'platform'),
    ('polar',                'Polar',                 'platform'),
    ('wearable',             'Wearable',              'wearable'),
    ('derived',              'Berekend',              'derived');

-- Codes match the metric keys the interface already uses in config/health.php,
-- so wiring the pages to real data later is a lookup, not a rename.
INSERT INTO `health_metric_types` (`code`, `label`, `unit`, `domain`, `aggregation`) VALUES
    ('sleep_duration',    'Slaapduur',            'min',  'sleep',     'sum'),
    ('sleep_efficiency',  'Slaapefficiëntie',     '%',    'sleep',     'avg'),
    ('sleep_regularity',  'Slaapregelmaat',       '%',    'sleep',     'avg'),
    ('sleeping_hr',       'Hartslag in slaap',    'bpm',  'vital',     'avg'),
    ('resting_hr',        'Rusthartslag',         'bpm',  'vital',     'avg'),
    ('hrv',               'HRV',                  'ms',   'vital',     'avg'),
    ('respiratory_rate',  'Ademhalingsfrequentie','/min', 'vital',     'avg'),
    ('skin_temp',         'Huidtemperatuur',      '°C',   'vital',     'avg'),
    ('spo2',              'Zuurstofsaturatie',    '%',    'vital',     'avg'),
    ('water',             'Water',                'l',    'nutrition', 'sum'),
    ('energy',            'Energie',              'kcal', 'nutrition', 'sum'),
    ('protein',           'Eiwit',                'g',    'nutrition', 'sum'),
    ('carbs',             'Koolhydraten',         'g',    'nutrition', 'sum'),
    ('fat',               'Vetten',               'g',    'nutrition', 'sum'),
    ('saturated_fat',     'Verzadigd vet',        'g',    'nutrition', 'sum'),
    ('fibre',             'Vezels',               'g',    'nutrition', 'sum'),
    ('sugar',             'Suikers',              'g',    'nutrition', 'sum'),
    ('sodium',            'Natrium',              'mg',   'nutrition', 'sum'),
    ('nutrition_rating',  'Eigen beoordeling',    '/10',  'nutrition', 'avg'),
    ('caffeine',          'Cafeïne',              'mg',   'nutrition', 'sum'),
    ('steps',             'Stappen',              '',     'training',  'sum'),
    ('distance',          'Afstand',              'km',   'training',  'sum'),
    ('active_energy',     'Actieve calorieën',    'kcal', 'training',  'sum'),
    ('total_energy',      'Totale calorieën',     'kcal', 'training',  'sum'),
    ('active_minutes',    'Actieve minuten',      'min',  'training',  'sum'),
    ('floors',            'Verdiepingen',         '',     'training',  'sum'),
    ('readiness',         'Herstel',              '/100', 'training',  'last'),
    ('training_load',     'Belasting',            '',     'training',  'last');

-- The rules that can earn points. Values live in config/points.php; `points` is
-- the most one event can earn. Kept in step with that file by the app.
INSERT INTO `point_rules` (`code`, `label`, `domain`, `points`, `cadence`, `is_active`) VALUES
    ('sleep_duration',    'Nachtrust',              'sleep',     45, 'daily',     1),
    ('sleep_regularity',  'Regelmatig geslapen',    'sleep',     10, 'daily',     1),
    ('sleep_quality',     'Goed geslapen',          'sleep',     10, 'daily',     1),
    ('nutrition_rating',  'Voeding beoordeeld',     'nutrition', 50, 'daily',     1),
    ('workout',           'Training',               'training',  45, 'per_event', 1),
    ('workout_intensity', 'Intensieve training',    'training',  15, 'per_event', 1),
    ('workout_record',    'Persoonlijk record',     'training',  25, 'per_event', 1),
    ('steps',             'Stappen',                'training',  45, 'daily',     1),
    ('weekly_workouts',   '3 trainingen deze week', 'training',  75, 'weekly',    1);


-- ============================================================================
--  9. RESEARCH AND INSIGHTS (migration 028, 29.0) — docs/INSIGHTS.md,
--     docs/RESEARCH.md
-- ============================================================================
--  The approved research registry (reference data, about nobody) and each
--  person's daily insights (PRIVATE). config/research.php is the reviewed
--  text of the registry; tools/research-registry.php writes it into the
--  database and checks that the two agree.

-- One row per approved publication.
CREATE TABLE `research_sources` (
    `code`          VARCHAR(16)  NOT NULL COMMENT 'Stable id, e.g. SLP-01 (config/research.php)',
    `status`        ENUM('approved','withdrawn') NOT NULL DEFAULT 'approved' COMMENT 'Only approved sources support an insight',
    `kind`          VARCHAR(32)  NOT NULL COMMENT 'guideline, consensus, meta_analysis … (config/research.php kinds)',
    `language`      CHAR(2)      NOT NULL DEFAULT 'en' COMMENT 'The language the publication is written in: nl or en',
    `title`         VARCHAR(400) NOT NULL COMMENT 'Exactly as published, in that language',
    `authors`       VARCHAR(255) NULL,
    `organisation`  VARCHAR(191) NULL,
    `published`     VARCHAR(10)  NOT NULL COMMENT 'YYYY or YYYY-MM-DD, as the source dates itself',
    `citation`      VARCHAR(255) NOT NULL,
    `url`           VARCHAR(500) NOT NULL COMMENT 'The original publication',
    `doi`           VARCHAR(100) NULL,
    `pmid`          VARCHAR(16)  NULL,
    `pmcid`         VARCHAR(16)  NULL,
    `other_id`      VARCHAR(100) NULL COMMENT 'ISBN or publication number',
    `pdf_mode`      ENUM('hosted','official_pdf','link') NOT NULL COMMENT 'hosted: CC BY, served by Ownify; official_pdf: fetched by the device from the publisher; link: the publisher only',
    `pdf_url`       VARCHAR(500) NULL COMMENT 'The official PDF or reading page',
    `pdf_licence`   VARCHAR(100) NOT NULL,
    `pdf_hosted`    VARCHAR(100) NULL COMMENT 'hosted only: the unmodified copy under assets/research/',
    `pdf_embed`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'official_pdf: the publisher allows it inside a page',
    `findings`      TEXT         NOT NULL COMMENT 'What the source found, in Dutch (English: lang/en/research.php)',
    `approved_on`   DATE         NOT NULL,
    `verified_on`   DATE         NOT NULL,
    `content_hash`  CHAR(64)     NOT NULL COMMENT 'sha256 of the reviewed record (tools/research-registry.php)',
    `synced_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per version of each claim Ownify uses. Retired, never deleted.
CREATE TABLE `research_claims` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `claim_key`        VARCHAR(64)  NOT NULL COMMENT 'Stable, e.g. sleep.duration.adults',
    `version`          SMALLINT UNSIGNED NOT NULL COMMENT 'Raised when the claim''s meaning, numbers or text change',
    `source_code`      VARCHAR(16)  NOT NULL,
    `status`           ENUM('active','retired') NOT NULL DEFAULT 'active' COMMENT 'One active version per claim; retired ones stay for the insights that used them',
    `topic`            VARCHAR(64)  NOT NULL,
    `metric`           VARCHAR(64)  NULL,
    `age_min`          TINYINT UNSIGNED NULL COMMENT 'Years, inclusive; NULL: no lower bound',
    `age_max`          TINYINT UNSIGNED NULL COMMENT 'Years, inclusive; NULL: no upper bound',
    `strength`         ENUM('zeer_betrouwbaar','betrouwbaar','beperkt') NOT NULL COMMENT 'The evidence for this claim itself',
    `finding_json`     TEXT NOT NULL COMMENT 'The numbers with their units, machine-readable; age bands as the source draws them',
    `statement`        TEXT NOT NULL COMMENT 'The claim Ownify uses, in Dutch',
    `population`       VARCHAR(255) NOT NULL,
    `limitations_json` TEXT NOT NULL COMMENT 'JSON list of Dutch sentences',
    `approved_on`      DATE NOT NULL,
    `content_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the reviewed record',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `retired_at`       DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_claim_version` (`claim_key`, `version`),
    KEY `idx_claim_status` (`status`, `topic`),
    KEY `idx_claim_source` (`source_code`),
    CONSTRAINT `fk_claim_source` FOREIGN KEY (`source_code`) REFERENCES `research_sources` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PRIVATE. Each person's local day: the lock that makes one request work it
-- out, when it was, whether data changed since, and Gemini's one call.
CREATE TABLE `insight_days` (
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `local_date`       DATE NOT NULL COMMENT 'The day in Europe/Amsterdam, Ownify''s one clock',
    `state`            ENUM('running','done') NOT NULL DEFAULT 'running',
    `started_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the running claim was taken',
    `evaluated_at`     DATETIME NULL COMMENT 'The last full evaluation or reassessment',
    `data_changed_at`  DATETIME NULL COMMENT 'A save or sync after evaluated_at: reassess on the next load',
    `evaluations`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Today''s evaluations, the first and each reassessment',
    `facts_hash`       CHAR(64) NULL COMMENT 'The derived facts the day was worked out from',
    `engine_version`   VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'config/insights.php engine_version',
    `phrase_state`     ENUM('none','done','skipped','failed') NOT NULL DEFAULT 'none' COMMENT 'Gemini''s wording: only with ai_insights_consent',
    `phrase_note`      VARCHAR(24) NULL COMMENT 'Why skipped or failed: consent, budget, pace, quota, invalid, timeout …',
    `gemini_calls`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'At most one a day',
    `input_tokens`     INT UNSIGNED NOT NULL DEFAULT 0,
    `output_tokens`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Thinking included, as Gemini counts it',
    `cached_tokens`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'usageMetadata.cachedContentTokenCount, when Gemini reports it',
    PRIMARY KEY (`user_id`, `local_date`),
    CONSTRAINT `fk_insight_days_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PRIVATE. The insights: one per slot per local day, the same for the
-- website and the Android app.
CREATE TABLE `user_insights` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `local_date`     DATE NOT NULL,
    `slot`           VARCHAR(16) NOT NULL COMMENT 'sleep, nutrition, training, body; suggestion (Eén kleine suggestie, at most one a day)',
    `rule_key`       VARCHAR(64) NOT NULL COMMENT 'config/insights.php',
    `outcome`        VARCHAR(32) NOT NULL COMMENT 'below, within, above … : which sentence',
    `claim_id`       INT UNSIGNED NOT NULL COMMENT 'The exact claim version used',
    `strength`       ENUM('zeer_betrouwbaar','betrouwbaar','beperkt') NOT NULL COMMENT 'As shown: the claim''s, lowered where Ownify''s data only approximates it',
    `applicability`  ENUM('direct','proxy','weak') NOT NULL,
    `facts_json`     TEXT NOT NULL COMMENT 'The derived facts the sentence is written from: numbers and units, never raw records, never a birth date',
    `fingerprint`    CHAR(64) NOT NULL COMMENT 'sha256 of rule, outcome, claim and the rounded facts',
    `rank_score`     SMALLINT NOT NULL DEFAULT 0,
    `phrased_locale` CHAR(2) NULL COMMENT 'The language Gemini wrote in; the template is shown in any other',
    `phrased_body`   VARCHAR(600) NULL COMMENT 'Gemini''s validated wording of the body; NULL: the template',
    `phrased_model`  VARCHAR(80) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `valid_until`    DATETIME NOT NULL COMMENT 'The end of its local day',
    `withdrawn_at`   DATETIME NULL COMMENT 'A reassessment found it no longer holds',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_insight_slot` (`user_id`, `local_date`, `slot`),
    KEY `idx_user_insight_rule` (`user_id`, `rule_key`, `local_date`),
    KEY `idx_user_insight_claim` (`claim_id`),
    CONSTRAINT `fk_user_insights_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_insights_claim` FOREIGN KEY (`claim_id`) REFERENCES `research_claims` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Combined insights (migration 029, 31.0) — docs/CORRELATIONS.md. One run
-- per person per local day, and each pair's result in it: numbers only.
CREATE TABLE `correlation_runs` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `local_date`     DATE NOT NULL COMMENT 'The day in Europe/Amsterdam, Ownify''s one clock',
    `state`          ENUM('running','done') NOT NULL DEFAULT 'running',
    `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the running claim was taken',
    `computed_at`    DATETIME NULL COMMENT 'When the stored result was worked out; NULL: none yet',
    `engine_version` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'config/correlations.php engine_version',
    `window_from`    DATE NULL COMMENT 'The first day looked at',
    `window_to`      DATE NULL COMMENT 'The last day looked at (yesterday)',
    `tested`         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pairs with enough days to test: the false-discovery correction counts them',
    `duration_ms`    INT UNSIGNED NULL,
    `meta_json`      TEXT NULL COMMENT 'The person''s own cut-offs the sentences name, e.g. what a short night was',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_correlation_run_day` (`user_id`, `local_date`),
    CONSTRAINT `fk_correlation_runs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_correlations` (
    `run_id`      BIGINT UNSIGNED NOT NULL,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `pair_code`   VARCHAR(8) NOT NULL COMMENT 'C01 … (config/correlations.php)',
    `tier`        ENUM('clear','pattern','possible','none','unclear') NOT NULL
                  COMMENT 'Duidelijk patroon, Patroon, Mogelijk patroon, Geen patroon, Nog onduidelijk',
    `days`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Paired days (weeks for a weekly pair)',
    `rho`         DECIMAL(6,4) NULL COMMENT 'Spearman, after trend and weekdays are taken out',
    `p_value`     DECIMAL(8,6) NULL,
    `q_value`     DECIMAL(8,6) NULL COMMENT 'Benjamini–Hochberg over the run''s tested pairs',
    `effect`      DECIMAL(12,4) NULL COMMENT 'The difference between the day groups, in the outcome''s shown unit',
    `detail_json` TEXT NOT NULL COMMENT 'Groups, interval, threshold, chart values, what is missing: numbers only',
    PRIMARY KEY (`run_id`, `pair_code`),
    KEY `idx_user_correlations_user` (`user_id`),
    CONSTRAINT `fk_user_correlations_run` FOREIGN KEY (`run_id`) REFERENCES `correlation_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_correlations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The approved registry, as migrations 028 and 029 carry it.
-- >>> REGISTRY SEED: generated by php tools/research-registry.php --write. Do not edit by hand.
-- 57 approved sources and 58 claims (config/research.php).
INSERT IGNORE INTO `research_sources` (`code`, `status`, `kind`, `language`, `title`, `authors`, `organisation`, `published`, `citation`, `url`, `doi`, `pmid`, `pmcid`, `other_id`, `pdf_mode`, `pdf_url`, `pdf_licence`, `pdf_hosted`, `pdf_embed`, `findings`, `approved_on`, `verified_on`, `content_hash`) VALUES
    ('SLP-01', 'approved', 'health_info', 'en', 'About Sleep', NULL, 'Centers for Disease Control and Prevention (CDC)', '2024-05-15', 'CDC, Sleep, About Sleep (15 mei 2024)', 'https://www.cdc.gov/sleep/about/', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Aanbevolen slaap per dag: 6–12 jaar 9–12 uur, 13–17 jaar 8–10 uur, 18–60 jaar 7 uur of meer, 61–64 jaar 7–9 uur en vanaf 65 jaar 7–8 uur.', '2026-10-27', '2026-10-27', 'adf97249fe11bd1480fd11ba7d20c2d5f985dc3e83afb7bde780d0db14117c62'),
    ('SLP-02', 'approved', 'consensus', 'en', 'Recommended Amount of Sleep for a Healthy Adult: A Joint Consensus Statement of the American Academy of Sleep Medicine and Sleep Research Society', 'Watson NF, Badr MS, Belenky G, et al.', 'American Academy of Sleep Medicine en Sleep Research Society', '2015', 'Sleep 2015;38(6):843–844', 'https://doi.org/10.5665/sleep.4716', '10.5665/sleep.4716', '26039963', 'PMC4434546', NULL, 'link', 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4434546/', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Volwassenen horen regelmatig 7 uur of meer per nacht te slapen. Regelmatig korter dan 7 uur slapen hangt samen met onder meer gewichtstoename, diabetes, hoge bloeddruk, hart- en vaatziekten, depressie en een hoger sterfterisico. Meer dan 9 uur kan passend zijn voor jonge volwassenen, bij het inhalen van slaaptekort of bij ziekte; voor anderen is onzeker of dat een risico geeft.', '2026-10-27', '2026-10-27', '4ee06bef2d78ae467f90aafcab4967b243fe60626e9dd134cc1c2c81e5b0c747'),
    ('SLP-03', 'approved', 'consensus', 'en', 'National Sleep Foundation''s sleep time duration recommendations: methodology and results summary', 'Hirshkowitz M, Whiton K, Albert SM, et al.', 'National Sleep Foundation', '2015', 'Sleep Health 2015;1(1):40–43', 'https://doi.org/10.1016/j.sleh.2014.12.010', '10.1016/j.sleh.2014.12.010', '29073412', NULL, NULL, 'link', 'https://www.sleephealthjournal.org/article/S2352721815000157/pdf', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Een panel van deskundigen noemt als passende slaapduur: schoolkinderen 9–11 uur, tieners (14–17) 8–10 uur, jongvolwassenen en volwassenen (18–64) 7–9 uur en ouderen (65+) 7–8 uur. Wat daarbuiten valt kan passend zijn, maar grote afwijkingen zijn zeldzaam.', '2026-10-27', '2026-10-27', 'cc85e70e1228b7aa3d67531940917a5fba5c2a5ce276c784abca0bd03e730927'),
    ('SLP-04', 'approved', 'consensus', 'en', 'Recommended Amount of Sleep for Pediatric Populations: A Consensus Statement of the American Academy of Sleep Medicine', 'Paruthi S, Brooks LJ, D''Ambrosio C, et al.', 'American Academy of Sleep Medicine', '2016', 'J Clin Sleep Med 2016;12(6):785–786', 'https://doi.org/10.5664/jcsm.5866', '10.5664/jcsm.5866', '27250809', 'PMC4877308', NULL, 'link', 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4877308/', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Kinderen van 6–12 jaar horen 9–12 uur per 24 uur te slapen en tieners van 13–18 jaar 8–10 uur. Regelmatig korter slapen hangt samen met problemen met aandacht, gedrag en leren, en met meer ongelukken, hoge bloeddruk, overgewicht, diabetes en depressie.', '2026-10-27', '2026-10-27', 'b9f848958ebe2351033af6a2a6a02b8e754220fdbbe4730e96b5959fc9bd0110'),
    ('SLP-05', 'approved', 'consensus', 'en', 'The importance of sleep regularity: a consensus statement of the National Sleep Foundation sleep timing and variability panel', 'Sletten TL, Weaver MD, Foster RG, et al.', 'National Sleep Foundation', '2023', 'Sleep Health 2023;9(6):801–820', 'https://doi.org/10.1016/j.sleh.2023.07.016', '10.1016/j.sleh.2023.07.016', '37684151', NULL, NULL, 'link', 'https://www.sleephealthjournal.org/article/S2352721823001663/pdf', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Na een literatuuronderzoek concludeerde een panel van slaap- en ritmedeskundigen dat regelmatige tijden van inslapen en opstaan belangrijk zijn voor gezondheid, veiligheid en prestaties. Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.', '2026-10-27', '2026-10-27', '22b398f9d3acfa7730628adc2a466482c81beac21f9671a42fde6aed2603a463'),
    ('SLP-06', 'approved', 'validation_study', 'en', 'Performance of seven consumer sleep-tracking devices compared with polysomnography', 'Chinoy ED, Cuellar JA, Huwa KE, et al.', NULL, '2021', 'Sleep 2021;44(5):zsaa291', 'https://doi.org/10.1093/sleep/zsaa291', '10.1093/sleep/zsaa291', '33378539', 'PMC8120339', NULL, 'link', 'https://academic.oup.com/sleep/article-pdf/44/5/zsaa291/37933402/zsaa291.pdf', 'CC BY-NC 4.0', NULL, 0, 'In een slaaplaboratorium (34 gezonde jonge volwassenen, 3 nachten) herkenden zeven consumentenapparaten slaap goed, maar wakker zijn veel minder goed. Slaapfasen kwamen wisselend overeen en op onrustige nachten werkten de apparaten slechter.', '2026-10-27', '2026-10-27', '7b30b39f9a6bc0cf9f6398f0eb416ca3856e30d8a8ae05a2cb361a95d0edd719'),
    ('ACT-01', 'approved', 'guideline', 'en', 'WHO guidelines on physical activity and sedentary behaviour', NULL, 'Wereldgezondheidsorganisatie (WHO)', '2020-11-25', 'WHO, Genève 2020, 104 pagina''s', 'https://www.who.int/publications/i/item/9789240015128', NULL, NULL, NULL, 'ISBN 9789240015128', 'official_pdf', 'https://iris.who.int/server/api/core/bitstreams/faa83413-d89e-4be9-bb01-b24671aef7ca/content', 'CC BY-NC-SA 3.0 IGO', NULL, 0, 'Volwassenen (18–64): per week minstens 150–300 minuten matig of 75–150 minuten zwaar intensieve aerobe activiteit, plus spierversterkende activiteiten op 2 of meer dagen (sterke aanbeveling, matige zekerheid). Vanaf 65: hetzelfde, plus gevarieerde activiteit met nadruk op balans en kracht op 3 of meer dagen. 5–17 jaar: gemiddeld 60 minuten per dag. Beperk zitten; een beetje bewegen is beter dan niets.', '2026-10-27', '2026-10-27', '33c18ff96c3f52c9f7855aa610bc4945a08faa9adead92da73d96687bcf7829b'),
    ('ACT-02', 'approved', 'guideline', 'nl', 'Beweegrichtlijnen 2017', NULL, 'Gezondheidsraad', '2017-08-22', 'Gezondheidsraad, Den Haag 2017, publicatienr. 2017/08', 'https://www.gezondheidsraad.nl/documenten/adviezen/2017/08/22/beweegrichtlijnen-2017', NULL, NULL, NULL, 'Gezondheidsraad nr. 2017/08', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2017/08/22/beweegrichtlijnen-2017/kernadvies-Beweegrichtlijnen-2017.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Bewegen is goed, meer bewegen is beter. Volwassenen en ouderen: minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, minstens twee keer per week spier- en botversterkende activiteiten (ouderen met balansoefeningen) en voorkom veel stilzitten. Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.', '2026-10-27', '2026-10-27', '87a589ee44e18620ca9efc1da5190a0a6e6afdaf2fbc6666ebbcc27b7cfa7c8a'),
    ('ACT-03', 'approved', 'systematic_review', 'en', 'Daily steps and health outcomes in adults: a systematic review and dose-response meta-analysis', 'Ding D, Nguyen B, Nau T, et al.', NULL, '2025', 'Lancet Public Health 2025;10(8):e668–e681', 'https://doi.org/10.1016/S2468-2667(25)00164-1', '10.1016/S2468-2667(25)00164-1', '40713949', NULL, NULL, 'link', 'https://www.thelancet.com/pdfs/journals/lanpub/PIIS2468-2667(25)00164-1.pdf', 'CC BY-NC 4.0', NULL, 0, 'In 57 studies uit 35 cohorten hing 7.000 stappen per dag, vergeleken met 2.000, samen met 47% minder sterfte, 25% minder hart- en vaatziekten, 38% minder dementie en 22% minder depressieve klachten. Voor sterfte vlakte het verband af rond 5.000–7.000 stappen. De zekerheid van het bewijs was voor de meeste uitkomsten matig.', '2026-10-27', '2026-10-27', '5430795da8d32be65b37edd2e8c9ecaa419bc71b8397bb0a28b33a603ffa7e42'),
    ('ACT-04', 'approved', 'meta_analysis', 'en', 'Daily steps and all-cause mortality: a meta-analysis of 15 international cohorts', 'Paluch AE, Bajpai S, Bassett DR, et al.', 'Steps for Health Collaborative', '2022', 'Lancet Public Health 2022;7(3):e219–e228', 'https://doi.org/10.1016/S2468-2667(21)00302-9', '10.1016/S2468-2667(21)00302-9', '35247352', 'PMC9289978', NULL, 'link', 'https://www.thelancet.com/article/S2468266721003029/pdf', 'CC BY-NC-ND 4.0', NULL, 0, 'In 15 cohorten (47.471 volwassenen) hing meer stappen per dag samen met een steeds lager sterfterisico, tot het afvlakte rond 6.000–8.000 stappen bij 60-plussers en rond 8.000–10.000 bij volwassenen onder de 60.', '2026-10-27', '2026-10-27', '5b1b9a2c2f9517aa17c442da4de7588ebd4fb73584dca13bfdd76aa969ba671d'),
    ('MEA-01', 'approved', 'umbrella_review', 'en', 'Keeping Pace with Wearables: A Living Umbrella Review of Systematic Reviews Evaluating the Accuracy of Consumer Wearable Technologies in Health Measurement', 'Doherty C, Baldwin M, Keogh A, et al.', NULL, '2024', 'Sports Med 2024;54(11):2907–2926', 'https://doi.org/10.1007/s40279-024-02077-2', '10.1007/s40279-024-02077-2', '39080098', 'PMC11560992', NULL, 'hosted', 'https://link.springer.com/content/pdf/10.1007/s40279-024-02077-2.pdf', 'CC BY 4.0', 'assets/research/MEA-01.pdf', 0, 'Een overzicht van 24 systematische reviews (249 validatiestudies): hartslag wijkt gemiddeld ongeveer 3% af, stappen worden meestal iets ondergeschat (−9% tot 12%), energieverbruik kan flink afwijken (−21% tot +15%), zuurstofsaturatie tot 2 procentpunt, en slaaptijd wordt meestal overschat, vaak met meer dan 10%. Ongeveer 11% van de apparaten is voor minstens één meting gevalideerd.', '2026-10-27', '2026-10-27', 'e909ddbbae61a3560405654e79e4fbeb2e8d9d821c5f456a3d88cc06c4a99199'),
    ('NUT-01', 'approved', 'reference_values', 'nl', 'Voedingsnormen voor vetten, vetzuren, verteerbare koolhydraten en voedingsvezels', NULL, 'Gezondheidsraad', '2026-07-07', 'Gezondheidsraad, Den Haag 2026, publicatienr. 2026/14 (gecorrigeerde pdf 7 september 2026)', 'https://www.gezondheidsraad.nl/documenten/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels', NULL, NULL, NULL, 'Gezondheidsraad nr. 2026/14', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels/14-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels-erratum.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Totaal vet: 20–40% van de energie (vanaf 4 jaar); de kwaliteit van het voedingspatroon telt meer dan de hoeveelheid vet. Verzadigd vet en vrije suikers: zo laag mogelijk, met 10% van de energie als tussenstap. Koolhydraten: geen percentage, wel vooral uit volkoren, groente, fruit, peulvruchten en zuivel. Vezels: 3,0–3,5 gram per megajoule, ofwel 12,6–14,6 gram per 1.000 kcal.', '2026-10-27', '2026-10-27', '5a09324afbd361321c07d4f0f453eb949640b902461fbc78b5f6e4c2eb09957d'),
    ('NUT-02', 'approved', 'reference_values', 'nl', 'Voedingsnormen voor eiwitten', NULL, 'Gezondheidsraad', '2021-03-02', 'Gezondheidsraad, Den Haag 2021, publicatienr. 2021/10', 'https://www.gezondheidsraad.nl/documenten/2021/03/02/voedingsnormen-voor-eiwitten', NULL, NULL, NULL, 'Gezondheidsraad nr. 2021/10', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2021/03/02/voedingsnormen-voor-eiwitten/Advies-Voedingsnormen-voor-eiwitten-referentiewaarden-voor-de-inname-van-eiwitten.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Volwassenen hebben gemiddeld 0,66 gram eiwit per kilogram lichaamsgewicht per dag nodig; de aanbevolen hoeveelheid is 0,83 gram per kilogram per dag. Er is geen overtuigend bewijs dat alle ouderen meer nodig hebben; sommige groepen, zoals kwetsbare ouderen, mogelijk wel.', '2026-10-27', '2026-10-27', '4901044d9921ea27f18fe884f4eb5750edcc2732d94a275f9cacbeb246833c3f'),
    ('NUT-03', 'approved', 'guideline', 'nl', 'Richtlijnen goede voeding 2015', NULL, 'Gezondheidsraad', '2015-11-04', 'Gezondheidsraad, Den Haag 2015, publicatienr. 2015/24, ISBN 978-94-6281-089-1', 'https://www.gezondheidsraad.nl/documenten/adviezen/2015/11/04/richtlijnen-goede-voeding-2015', NULL, NULL, NULL, 'Gezondheidsraad nr. 2015/24', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2015/11/04/richtlijnen-goede-voeding-2015/201524_Richtlijnen+goede+voeding+2015.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Eet meer plantaardig en minder dierlijk: dagelijks minstens 200 gram groente en 200 gram fruit, minstens 90 gram volkoren, wekelijks peulvruchten, 15 gram ongezouten noten, enkele porties zuivel en wekelijks vis. Beperk rood en vooral bewerkt vlees, drink zo min mogelijk suikerhoudende dranken, geen of hooguit één glas alcohol per dag, en maximaal 6 gram zout per dag.', '2026-10-27', '2026-10-27', 'd4b8e3e614ad827cffbbe81b129c9089bcd015fc6d6a7a88601a2a2fc0e869d7'),
    ('NUT-04', 'approved', 'systematic_review', 'en', 'Comparison of predictive equations for resting metabolic rate in healthy nonobese and obese adults: a systematic review', 'Frankenfield D, Roth-Yousey L, Compher C', 'American Dietetic Association', '2005', 'J Am Diet Assoc 2005;105(5):775–789', 'https://doi.org/10.1016/j.jada.2005.02.005', '10.1016/j.jada.2005.02.005', '15883556', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Van de vier meest gebruikte formules voorspelde Mifflin-St Jeor het rustmetabolisme het vaakst binnen 10% van de gemeten waarde, bij mensen met en zonder obesitas. Voor individuen blijven er opvallende afwijkingen.', '2026-10-27', '2026-10-27', '9c6561716d7653816381b5a54f4abfa08123c462d603d042ede57e1e12e94a0c'),
    ('NUT-05', 'approved', 'position_stand', 'en', 'International Society of Sports Nutrition Position Stand: protein and exercise', 'Jäger R, Kerksick CM, Campbell BI, et al.', 'International Society of Sports Nutrition', '2017', 'J Int Soc Sports Nutr 2017;14:20', 'https://doi.org/10.1186/s12970-017-0177-8', '10.1186/s12970-017-0177-8', '28642676', 'PMC5477153', NULL, 'hosted', 'https://jissn.biomedcentral.com/track/pdf/10.1186/s12970-017-0177-8', 'CC BY 4.0', 'assets/research/NUT-05.pdf', 0, 'Voor het opbouwen en behouden van spiermassa is 1,4–2,0 gram eiwit per kilogram lichaamsgewicht per dag voldoende voor de meeste mensen die sporten. Bij krachtsporters die afvallen kan meer nodig zijn om spiermassa te behouden.', '2026-10-27', '2026-10-27', '196181096b929753d9f09cf3bd9a65a670e13ce82df3702bfd68a55edf376cc9'),
    ('BOD-01', 'approved', 'fact_sheet', 'en', 'Obesity and overweight', NULL, 'Wereldgezondheidsorganisatie (WHO)', '2026-10-07', 'WHO-feitenblad, bijgewerkt 7 oktober 2026', 'https://www.who.int/news-room/fact-sheets/detail/obesity-and-overweight', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Bij volwassenen (18+) noemt de WHO een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas. Voor 5–19 jaar gelden groeicurves. Ook buikomvang kan worden gebruikt.', '2026-10-27', '2026-10-27', '1962faca69ebefc11a2aeab92a00ab735f0d63444ee9c1c2376887efd6b07f0e'),
    ('XSA-01', 'approved', 'systematic_review', 'en', 'The effects of physical activity on sleep: a meta-analytic review', 'Kredlow MA, Capozzoli MC, Hearon BA, Calkins AW, Otto MW', NULL, '2015', 'J Behav Med 2015;38(3):427–449', 'https://doi.org/10.1007/s10865-015-9617-6', '10.1007/s10865-015-9617-6', '25596964', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 66 studies had één keer bewegen een klein gunstig effect op totale slaaptijd, inslaaptijd, slaapefficiëntie en diepe slaap, en een matig effect op wakker liggen na het inslapen. Regelmatig bewegen had kleine gunstige effecten op slaaptijd en efficiëntie, klein tot matig op inslaaptijd en matig op slaapkwaliteit. De effecten hingen af van geslacht, leeftijd, fitheid, soort beweging, tijdstip, duur en volhouden, niet van de intensiteit.', '2026-10-28', '2026-10-28', '8aa5447978b12963327c4195fbff31175d1d4fcc85e3920954fbc3b5a6de3279'),
    ('XSA-02', 'approved', 'umbrella_review', 'en', 'Physical activity and sleep: An updated umbrella review of the 2018 Physical Activity Guidelines Advisory Committee report', 'Kline CE, Hillman CH, Bloodgood Sheppard B, et al.', NULL, '2021', 'Sleep Med Rev 2021;58:101489', 'https://doi.org/10.1016/j.smrv.2021.101489', '10.1016/j.smrv.2021.101489', '33934046', 'PMC8338757', NULL, 'link', NULL, 'Gratis te lezen in PMC, geen hergebruiklicentie', NULL, 0, 'Sterk bewijs dat zowel één keer bewegen als regelmatig bewegen de slaap verbetert. Matig bewijs dat langere sessies meer helpen, dat het effect geldt voor volwassenen van alle leeftijden en beide geslachten, en dat bewegen de slaap verbetert bij volwassenen met slapeloosheidsklachten of obstructieve slaapapneu.', '2026-10-28', '2026-10-28', '5082510a3dbb588888ab311df5e4e4fcdca285b92161c8d0c0780a015297d9dd'),
    ('XSA-03', 'approved', 'systematic_review', 'en', 'Daily associations between sleep and physical activity: A systematic review and meta-analysis', 'Atoui S, Chevance G, Romain AJ, et al.', NULL, '2021', 'Sleep Med Rev 2021;57:101426', 'https://doi.org/10.1016/j.smrv.2021.101426', '10.1016/j.smrv.2021.101426', '33571893', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 33 studies bij volwassenen was er geen steun voor een verband in twee richtingen van dag tot dag. Slaapkwaliteit, slaapefficiëntie en wakker liggen in de nacht hingen samen met bewegen de dag erna, maar die verbanden waren klein en verschilden in richting en niveau (tussen mensen of binnen één persoon). Tussen mensen ging meer bewegen overdag samen met iets korter slapen de nacht erna (een klein effect).', '2026-10-28', '2026-10-28', '2740394c865b0d5043d7b40a8515073700ecdfdbed68479266163d1362eb94d8'),
    ('XSA-04', 'approved', 'systematic_review', 'en', 'Effects of Evening Exercise on Sleep in Healthy Participants: A Systematic Review and Meta-Analysis', 'Stutz J, Eiholzer R, Spengler CM', NULL, '2019', 'Sports Med 2019;49(2):269–287', 'https://doi.org/10.1007/s40279-018-1015-0', '10.1007/s40279-018-1015-0', '30374942', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 23 studies maakte bewegen in de avond de slaap niet slechter, eerder het omgekeerde: de REM-slaap begon 7,7 minuten later, diepe slaap nam 1,3 procentpunt toe en de lichtste slaap 0,9 procentpunt af. Zware inspanning die een uur of minder voor bedtijd eindigde, kan het inslapen verlengen en de slaaptijd en efficiëntie verlagen.', '2026-10-28', '2026-10-28', '25d1eb855cd26a96ef435a732cd7d2b75229998a265757c336cae6cbe5d3868d'),
    ('XSA-05', 'approved', 'systematic_review', 'en', 'The effects of evening high-intensity exercise on sleep in healthy adults: A systematic review and meta-analysis', 'Frimpong E, Mograss M, Zvionow T, Dang-Vu TT', NULL, '2021', 'Sleep Med Rev 2021;60:101535', 'https://doi.org/10.1016/j.smrv.2021.101535', '10.1016/j.smrv.2021.101535', '34416428', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 15 studies (194 deelnemers) verminderde zware inspanning die 0,5 tot 4 uur voor bedtijd eindigde de REM-slaap licht (−2,34%); andere slaapmaten veranderden niet. Regelmatig zwaar trainen in de avond verstoorde de slaap niet, en inspanning 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen van 18 tot 50 jaar niet.', '2026-10-28', '2026-10-28', 'f107a65352e779516bf90eaa443d0a0bb41754b8850bb4e0b613983c4bee084a'),
    ('XSA-06', 'approved', 'observational', 'en', 'Dose-response relationship between evening exercise and sleep', 'Leota J, Presby DM, Le F, et al.', NULL, '2025', 'Nat Commun 2025;16(1):3297', 'https://doi.org/10.1038/s41467-025-58271-x', '10.1038/s41467-025-58271-x', '40234380', 'PMC12000559', NULL, 'link', NULL, 'CC BY-NC-ND 4.0', NULL, 0, 'Bij 14.689 dragers van een wearable (ruim 4 miljoen nachten in een jaar) ging later en zwaarder trainen samen met later inslapen, korter en minder goed slapen, een hogere hartslag in de nacht en een lagere HRV. Training die minstens 4 uur voor het inslapen eindigde, ging niet samen met een andere slaap, hoe zwaar ook. Voorbeeld: maximale in plaats van lichte inspanning die 2 uur voor de gewone inslaaptijd eindigde, ging samen met 36 minuten later inslapen. De gewone inslaaptijd werd per persoon per maand bepaald, doordeweeks en in het weekend apart.', '2026-10-28', '2026-10-28', 'c6c3449e75fa1bc2a12f36fcb8940356ec47a86717f83efc235188cfa8660c20'),
    ('XSA-08', 'approved', 'systematic_review', 'en', 'The effect of resistance exercise on sleep: A systematic review of randomized controlled trials', 'Kovacevic A, Mavros Y, Heisz JJ, Fiatarone Singh MA', NULL, '2018', 'Sleep Med Rev 2018;39:52–68', 'https://doi.org/10.1016/j.smrv.2017.07.002', '10.1016/j.smrv.2017.07.002', '28919335', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 13 gerandomiseerde studies verbeterde regelmatige krachttraining alle aspecten van slaap, vooral de slaapkwaliteit; samen met duurtraining was het voordeel kleiner. Het effect van één krachttraining is weinig onderzocht en wisselend.', '2026-10-28', '2026-10-28', '67c9a544f1fc398acae5d31e29bd5f44b2b86251f41f8ff054b9f7ecde0bf1ef'),
    ('XSP-01', 'approved', 'systematic_review', 'en', 'Effects of Acute Sleep Loss on Physical Performance: A Systematic and Meta-Analytical Review', 'Craven J, McCartney D, Desbrow B, et al.', NULL, '2022', 'Sports Med 2022;52(11):2669–2690', 'https://doi.org/10.1007/s40279-022-01706-y', '10.1007/s40279-022-01706-y', '35708888', 'PMC9584849', NULL, 'link', NULL, 'Gratis te lezen in PMC, licentie nog te bevestigen', NULL, 0, 'In 69 publicaties verminderde slaaptekort (6 uur of minder in 24 uur) de sportprestatie gemiddeld met 7,56% (95%-interval −11,9 tot −3,13), in elke soort inspanning. Het effect was er na een nacht zonder slaap en na eerder opstaan dan gewoonlijk.', '2026-10-28', '2026-10-28', '8a2dc055e1482e5a5fda4ff93a30fcf17c4e43ce69be4969fa5e8c58e5474dc7'),
    ('XSP-03', 'approved', 'consensus', 'en', 'Sleep and the athlete: narrative review and 2021 expert consensus recommendations', 'Walsh NP, Halson SL, Sargent C, et al.', NULL, '2021', 'Br J Sports Med 2021;55(7):356–368', 'https://doi.org/10.1136/bjsports-2020-102025', '10.1136/bjsports-2020-102025', '33144349', NULL, NULL, 'link', NULL, 'Licentie niet bevestigd', NULL, 0, 'Sporters slapen vaak korter dan 7 uur. Een nacht of meer zonder slaap vermindert de prestatie; het effect van 1 tot 3 nachten gedeeltelijk slaaptekort is onduidelijk. In de bredere bevolking maakt gewoonlijk korter dan 7 uur slapen vatbaarder voor luchtweginfecties. Eén advies van 7–9 uur voor iedereen is waarschijnlijk niet ideaal; de deskundigen raden een persoonlijke aanpak aan.', '2026-10-28', '2026-10-28', '204f6ad1644b23bcd316a8463223a8f44204aabf551046222854f98e5e14adb8'),
    ('XSN-01', 'approved', 'systematic_review', 'en', 'The effects of partial sleep deprivation on energy balance: a systematic review and meta-analysis', 'Al Khatib HK, Harding SV, Darzi J, Pot GK', NULL, '2017', 'Eur J Clin Nutr 2017;71(5):614–624', 'https://doi.org/10.1038/ejcn.2016.201', '10.1038/ejcn.2016.201', '27804960', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Gedeeltelijk slaaptekort verhoogde de energie-inname met 385 kcal per dag (95%-interval 252 tot 517), zonder effect op het totale energieverbruik: per saldo meer energie binnen dan eruit.', '2026-10-28', '2026-10-28', '3892622bcdbeb90411e3254718fa63f4745472423362d2bc65a8a1d76448eceb'),
    ('XSN-02', 'approved', 'systematic_review', 'en', 'The influence of sleep health on dietary intake: a systematic review and meta-analysis of intervention studies', 'Fenton S, Burrows TL, Skinner JA, Duncan MJ', NULL, '2021', 'J Hum Nutr Diet 2021;34(2):273–285', 'https://doi.org/10.1111/jhn.12813', '10.1111/jhn.12813', '33001515', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Gedeeltelijk slaaptekort tot 5,5 uur of minder per nacht verhoogde de energie-inname per dag (gestandaardiseerd verschil 0,37, 95%-interval 0,21 tot 0,52), en ook de inname van vet, eiwit en koolhydraten.', '2026-10-28', '2026-10-28', '807bfae394d58db5731a8c236e4639baae7f2151c2f8aa03f60e17474a56314d'),
    ('XSN-05', 'approved', 'systematic_review', 'en', 'The effect of caffeine on subsequent sleep: A systematic review and meta-analysis', 'Gardiner C, Weakley J, Burke LM, et al.', NULL, '2023', 'Sleep Med Rev 2023;69:101764', 'https://doi.org/10.1016/j.smrv.2023.101764', '10.1016/j.smrv.2023.101764', '36870101', NULL, NULL, 'link', NULL, 'CC BY volgens Europe PMC, nog te bevestigen', NULL, 0, 'In 24 studies verkortte cafeïne de totale slaap met 45 minuten en de slaapefficiëntie met 7%, en verlengde het inslapen met 9 minuten en het wakker liggen met 12 minuten. Om niet korter te slapen zou koffie (107 mg per 250 ml) minstens 8,8 uur voor bedtijd moeten worden gedronken, en een gangbaar pre-workoutsupplement (217,5 mg) minstens 13,2 uur ervoor.', '2026-10-28', '2026-10-28', '1eddb0053807b9ec9e2c2de24c0f050459bea669c67f090195cb0cb39af823c3'),
    ('XTH-01', 'approved', 'review', 'en', 'Cardiac parasympathetic reactivation following exercise: implications for training prescription', 'Stanley J, Peake JM, Buchheit M', NULL, '2013', 'Sports Med 2013;43(12):1259–1277', 'https://doi.org/10.1007/s40279-013-0083-4', '10.1007/s40279-013-0083-4', '23912805', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Volledig herstel van het autonome zenuwstelsel van het hart na één duurtraining duurt tot 24 uur na lichte inspanning, 24 tot 48 uur na inspanning rond de drempel en minstens 48 uur na zware inspanning. Fittere mensen herstellen sneller, en het verschilt sterk per persoon.', '2026-10-28', '2026-10-28', 'db7b921253e67e7f88436b0f6ab7de97e28ea5b02e59d0884c9bb3243eff05c5'),
    ('XTH-02', 'approved', 'systematic_review', 'en', 'Monitoring Athletic Training Status Through Autonomic Heart Rate Regulation: A Systematic Review and Meta-Analysis', 'Bellenger CR, Fuller JT, Thomson RL, et al.', NULL, '2016', 'Sports Med 2016;46(10):1461–1486', 'https://doi.org/10.1007/s40279-016-0484-2', '10.1007/s40279-016-0484-2', '26888648', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Als training tot een betere prestatie leidde, steeg de HRV in rust licht (gestandaardiseerd verschil 0,58), net als het herstel van de hartslag na inspanning. Bij overbelasting veranderde de HRV in rust nauwelijks, dus HRV alleen kan goede en slechte aanpassing niet van elkaar onderscheiden.', '2026-10-28', '2026-10-28', '76346686037c6ccc23a4ca366f65eef0c5115bd054f5c3a1daf7f56c2a9e9040'),
    ('XTH-03', 'approved', 'review', 'en', 'Training adaptation and heart rate variability in elite endurance athletes: opening the door to effective monitoring', 'Plews DJ, Laursen PB, Stanley J, Kilding AE, Buchheit M', NULL, '2013', 'Sports Med 2013;43(9):773–781', 'https://doi.org/10.1007/s40279-013-0071-8', '10.1007/s40279-013-0071-8', '23852425', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Bij topsporters zijn zowel een stijging als een daling van de HRV in verband gebracht met slechte aanpassing aan training. Volg HRV van dag tot dag met gemiddelden (lopende gemiddelden van logaritmisch omgerekende waarden), niet met losse metingen.', '2026-10-28', '2026-10-28', '92e01bdead7e1a51c8c84b06970997776a6723e7817ee8ffc13da92d4126c8fc'),
    ('XTH-04', 'approved', 'review', 'en', 'Monitoring training status with HR measures: do all roads lead to Rome?', 'Buchheit M', NULL, '2014', 'Front Physiol 2014;5:73', 'https://doi.org/10.3389/fphys.2014.00073', '10.3389/fphys.2014.00073', '24578692', 'PMC3936188', NULL, 'link', NULL, 'CC BY', NULL, 0, 'De meeste tegenstrijdige uitkomsten komen door verschillen in methode en verkeerd lezen. Bij één persoon moet je een verandering in een hartslagmaat lezen tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van de training.', '2026-10-28', '2026-10-28', '9160d7f7a3520708ca372d762d4527c345ef3a772fd19ee9245cfe2a0c1746d6'),
    ('XTH-05', 'approved', 'observational', 'en', 'What Is behind Changes in Resting Heart Rate and Heart Rate Variability? A Large-Scale Analysis of Longitudinal Measurements Acquired in Free-Living', 'Altini M, Plews D', NULL, '2021', 'Sensors 2021;21(23):7932', 'https://doi.org/10.3390/s21237932', '10.3390/s21237932', '34883936', 'PMC8659706', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Bij 28.175 mensen (9 miljoen ochtendmetingen in 5 jaar) was de HRV (rMSSD) na zware training 3% lager en de hartslag 0,7% hoger; na lichte training was de HRV 1,6% hoger. Na veel alcohol: HRV 12% lager, hartslag 6% hoger. Bij ziekte: HRV 10% lager, hartslag 6% hoger. De HRV daalde met de leeftijd (r = −0,35). HRV is gevoelig maar niet specifiek: veel dingen verlagen haar.', '2026-10-28', '2026-10-28', 'b5bf7a0f73cba82650916b0b7c0d3cbd910491de611c8bad4b50a66ea087517b'),
    ('XNT-01', 'approved', 'position_stand', 'en', 'International society of sports nutrition position stand: nutrient timing', 'Kerksick CM, Arent S, Schoenfeld BJ, et al.', 'International Society of Sports Nutrition', '2017', 'J Int Soc Sports Nutr 2017;14:33', 'https://doi.org/10.1186/s12970-017-0189-4', '10.1186/s12970-017-0189-4', '28919842', 'PMC5596471', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Het moment van eten en de verhouding van de voedingsstoffen kunnen herstel en spieropbouw helpen na veel of zware training. Glycogeenvoorraden zijn het grootst bij 8 tot 12 gram koolhydraten per kilogram per dag; koolhydraten (0,8 g/kg per uur) samen met eiwit (0,2–0,4 g/kg per uur) helpen ze snel aan te vullen.', '2026-10-28', '2026-10-28', '6a99d9d6ed6f411303c24c0e9fe2d085811b2a33b1d0e60c40d782f80022b796'),
    ('TRN-01', 'approved', 'methods', 'en', 'Acute:Chronic Workload Ratio: Conceptual Issues and Fundamental Pitfalls', 'Impellizzeri FM, Tenan MS, Kempton T, Novak A, Coutts AJ', NULL, '2020', 'Int J Sports Physiol Perform 2020;15(6):907–913', 'https://doi.org/10.1123/ijspp.2019-0864', '10.1123/ijspp.2019-0864', '32502973', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Er is geen bewijs dat de verhouding tussen recente en langere trainingsbelasting (acute:chronic workload ratio) helpt om blessures te voorkomen; de verhouding is statistisch onnauwkeurig en voegt ruis en schijnverbanden toe.', '2026-10-28', '2026-10-28', 'f44aa8ba02e53bcdaec2cb99e61013ffc32cb5f33beb0b5a23d7b3331ae768e0'),
    ('TRN-02', 'approved', 'consensus', 'en', 'Recovery and Performance in Sport: Consensus Statement', 'Kellmann M, Bertollo M, Bosquet L, et al.', NULL, '2018', 'Int J Sports Physiol Perform 2018;13(2):240–245', 'https://doi.org/10.1123/ijspp.2017-0759', '10.1123/ijspp.2017-0759', '29345524', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Een balans tussen belasting (training, wedstrijden en wat het leven verder vraagt) en herstel is essentieel. Hoe iemand reageert verschilt tussen mensen en bij één persoon van moment tot moment, dus herstel volg je het best per persoon.', '2026-10-28', '2026-10-28', 'd87c9de94be1b1882fa041abc15cbf5cae1a3d02d32a999e2faab2244a497929'),
    ('BOD-02', 'approved', 'consensus', 'en', 'Definition and diagnostic criteria of clinical obesity', 'Rubino F, Cummings DE, Eckel RH, et al.', 'Lancet Diabetes & Endocrinology Commission', '2025', 'Lancet Diabetes Endocrinol 2025;13(3):221–262', 'https://doi.org/10.1016/S2213-8587(24)00316-4', '10.1016/S2213-8587(24)00316-4', '39824205', 'PMC11870235', NULL, 'link', NULL, 'Gratis te lezen in PMC, geen hergebruiklicentie', NULL, 0, 'Een wereldwijde commissie van 58 deskundigen: gebruik BMI alleen als ruwe maat voor gezondheidsrisico in groepen, voor onderzoek of screening, niet als maat voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een directe meting van het vetpercentage of met minstens één andere lichaamsmaat (buikomvang, taille-heupverhouding of taille-lengteverhouding) naast BMI, of met twee zulke maten ongeacht de BMI; boven een BMI van 40 mag je het aannemen.', '2026-10-28', '2026-10-28', 'a23f678ceee380fb552fdf1ea9851ed10890f531e95759587eed933549732153'),
    ('BOD-03', 'approved', 'observational', 'en', 'Weekly, seasonal and holiday body weight fluctuation patterns among individuals engaged in a European multi-centre behavioural weight loss maintenance intervention', 'Turicchi J, O''Driscoll R, Horgan G, et al.', NULL, '2020', 'PLoS One 2020;15(4):e0232152', 'https://doi.org/10.1371/journal.pone.0232152', '10.1371/journal.pone.0232152', '32353079', 'PMC7192384', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Bij 1.421 mensen met een slimme weegschaal schommelde het gewicht binnen een week met 0,35%: hoger na het weekend, lager doordeweeks. Rond kerst nam het gewicht gemiddeld 1,35% toe, en dat was in de maanden erna niet helemaal weer verdwenen.', '2026-10-28', '2026-10-28', '8e66b44195ce1f4c66879ba4769bf87cde3d4032e6b8d1fc189878966c9ec7ab'),
    ('ACT-05', 'approved', 'guideline', 'en', 'World Health Organization 2020 guidelines on physical activity and sedentary behaviour', 'Bull FC, Al-Ansari SS, Biddle S, et al.', 'Wereldgezondheidsorganisatie (WHO)', '2020', 'Br J Sports Med 2020;54(24):1451–1462', 'https://doi.org/10.1136/bjsports-2020-102955', '10.1136/bjsports-2020-102955', '33239350', 'PMC7719906', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Volwassenen: per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een gelijkwaardige mix; spierversterkende activiteiten voor alle leeftijden; kinderen en jongeren gemiddeld 60 minuten per dag; minder stilzitten; een beetje bewegen is beter dan niets.', '2026-10-28', '2026-10-28', 'd1b0cc4772b4ff809b11d58f18bff39818a133a7f07c0ea41961a37ebcba5baa'),
    ('ACT-07', 'approved', 'definitions', 'nl', 'Bewegen: Verantwoording, definities', NULL, 'RIVM, VZinfo.nl', '2026', 'RIVM, VZinfo.nl, Bewegen – Verantwoording, definities (geraadpleegd 28 oktober 2026)', 'https://www.vzinfo.nl/bewegen/verantwoording-definities', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Herhaalt de Beweegrichtlijnen 2017 en geeft de intensiteiten: licht 1,6–2,9 MET, matig 3,0–5,9 MET, zwaar 6 MET of meer; zittend gedrag is 1,5 MET of minder, zittend of liggend en niet slapend.', '2026-10-28', '2026-10-28', '2514b75914331b8a661682e403b8a09cd7c72d8b2a55f3f9acd701077e03332c'),
    ('NUT-07', 'approved', 'guideline', 'nl', 'Richtlijnen goede voeding: eiwitbronnen en voedingspatronen 2025', NULL, 'Gezondheidsraad', '2025-12-04', 'Gezondheidsraad, Den Haag 2025, publicatienr. 2025/19', 'https://zoek.officielebekendmakingen.nl/blg-1226497.pdf', NULL, NULL, NULL, 'Gezondheidsraad nr. 2025/19', 'link', 'https://zoek.officielebekendmakingen.nl/blg-1226497.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 0, 'Werkt een deel van de Richtlijnen goede voeding 2015 bij, voor eiwitbronnen (peulvruchten, noten, zuivel, vis, vlees, eieren en vervangers): een meer plantaardig en minder dierlijk voedingspatroon, met hooguit ongeveer 200 gram rood vlees per week en ongeveer 250 gram (bereide) peulvruchten per week. Groente, fruit en granen volgen in een later advies; tot dan gelden daarvoor de richtlijnen van 2015.', '2026-10-28', '2026-10-28', 'a93bd995c3e24384f33a43347d364a4e3f6da18b26999ff22baa4ca5fc45fe0f'),
    ('NUT-08', 'approved', 'public_info', 'nl', 'Vocht', NULL, 'Voedingscentrum', '2026', 'Voedingscentrum, encyclopedie: Vocht (geraadpleegd 28 oktober 2026)', 'https://www.voedingscentrum.nl/encyclopedie/vocht.aspx', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Vrouwen en meisjes vanaf 13 jaar: drink minstens 1,4 liter per dag; mannen en jongens vanaf 13 jaar: minstens 1,4 tot 1,8 liter. Alle dranken tellen mee. Bij flink sporten en zweten meer drinken (per uur sporten kun je 1 tot 2 liter verliezen). Op een heel warme dag minstens 2 liter, en niet meer dan 3 liter per dag.', '2026-10-28', '2026-10-28', 'b3b5568354eb01bde75833af24870ce619645e4ba13c17a590b2dbe2d1b78001'),
    ('HRT-01', 'approved', 'meta_analysis', 'en', 'Resting heart rate and all-cause and cardiovascular mortality in the general population: a meta-analysis', 'Zhang D, Shen X, Qi X', NULL, '2016', 'CMAJ 2016;188(3):E53–E63', 'https://doi.org/10.1503/cmaj.150535', '10.1503/cmaj.150535', '26598376', 'PMC4754196', NULL, 'link', NULL, 'Gratis te lezen in PMC, geen hergebruiklicentie', NULL, 0, 'In 46 cohortstudies ging elke 10 slagen per minuut hogere rusthartslag samen met een relatief risico van 1,09 op overlijden door alle oorzaken en 1,08 door hart- en vaatziekten. Boven 80 slagen per minuut was dat 1,45 en 1,33, vergeleken met de laagste groep.', '2026-10-28', '2026-10-28', '87632a496a7bc86a52083a7380fb23bd36a66a91c4a5d957b5108266a439d400'),
    ('HRT-02', 'approved', 'review', 'en', 'An Overview of Heart Rate Variability Metrics and Norms', 'Shaffer F, Ginsberg JP', NULL, '2017', 'Front Public Health 2017;5:258', 'https://doi.org/10.3389/fpubh.2017.00258', '10.3389/fpubh.2017.00258', '29034226', 'PMC5624990', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht; waarden over 24 uur, over enkele minuten en over minder dan een minuut zijn niet uitwisselbaar. Gepubliceerde normen moeten worden aangevuld met waarden uit de eigen groep.', '2026-10-28', '2026-10-28', '24320c43e5c454ec00722f26830786f33d29b9ef20ec91a4b48d556530a9c397'),
    ('MEA-02', 'approved', 'device_validation', 'en', 'Assessing the Accuracy of Popular Commercial Technologies That Measure Resting Heart Rate and Heart Rate Variability', 'Stone JD, Ulman HK, Tran K, et al.', NULL, '2021', 'Front Sports Act Living 2021;3:585870', 'https://doi.org/10.3389/fspor.2021.585870', '10.3389/fspor.2021.585870', '33733234', 'PMC7956986', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Bij 5 volwassenen (148 metingen tegen een ecg) maten alle zeven apparaten de gemiddelde rusthartslag nauwkeurig. Voor HRV (rMSSD) liep de nauwkeurigheid sterk uiteen: een gemiddelde afwijking van 4,1% bij het beste apparaat en 112% bij een camera-app.', '2026-10-28', '2026-10-28', '20c0b8298f1f862a2d41dce1b9e8f570c9713a605475462423817ba4b8131cf4'),
    ('MTH-01', 'approved', 'methods', 'en', 'Controlling the false discovery rate: a practical and powerful approach to multiple testing', 'Benjamini Y, Hochberg Y', NULL, '1995', 'J R Stat Soc Series B 1995;57(1):289–300', 'https://doi.org/10.1111/j.2517-6161.1995.tb02031.x', '10.1111/j.2517-6161.1995.tb02031.x', NULL, NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Een methode die, als je veel dingen tegelijk toetst, het verwachte aandeel valse vondsten onder de gerapporteerde vondsten onder een gekozen grens houdt.', '2026-10-28', '2026-10-28', '4accff038a8910f8cf3548d6db1842660df45078e9491aaefde93d77ff130b46'),
    ('MTH-02', 'approved', 'methods', 'en', 'Causal Analysis of Self-tracked Time Series Data Using a Counterfactual Framework for N-of-1 Trials', 'Daza EJ', NULL, '2018', 'Methods Inf Med 2018;57(1):e10–e21', 'https://doi.org/10.3414/ME16-02-0044', '10.3414/ME16-02-0044', '29621835', 'PMC6087468', NULL, 'link', NULL, 'CC BY-NC-ND', NULL, 0, 'Verbanden in de zelf bijgehouden gegevens van één persoon kunnen wijzen op mogelijke oorzaak en gevolg, maar om ze zo te lezen moet je expliciet rekening houden met hoe dagen op elkaar lijken, met trends in de tijd, met doorwerking en met vertraagde effecten; een gerandomiseerde n-van-1-studie is de echte toets.', '2026-10-28', '2026-10-28', 'c8c191fe01ea9efcf167d79a0864517abe6f5f8b3fc90f8352270c31b76bf34b'),
    ('MTH-03', 'approved', 'methods', 'en', 'The state of the art and future opportunities for using longitudinal n-of-1 methods in health behaviour research: a systematic literature overview', 'McDonald S, Quinn F, Vieira R, et al.', NULL, '2017', 'Health Psychol Rev 2017;11(4):307–323', 'https://doi.org/10.1080/17437199.2017.1316672', '10.1080/17437199.2017.1316672', '28406349', NULL, NULL, 'link', NULL, 'CC BY volgens Europe PMC, nog te bevestigen', NULL, 0, 'In 39 n-van-1-studies konden effecten binnen één persoon anders zijn dan die tussen mensen. Herhaald meten bij één persoon kan inzichten over gezondheidsgedrag persoonlijk maken, ook voor slapen, bewegen en eten.', '2026-10-28', '2026-10-28', '29da1350544b76222852e7c9487d66f485ebadaa4a23698ea9b56b284674064e'),
    ('AGE-01', 'approved', 'systematic_review', 'en', 'Age-predicted maximal heart rate revisited', 'Tanaka H, Monahan KD, Seals DR', NULL, '2001', 'J Am Coll Cardiol 2001;37(1):153–156', 'https://doi.org/10.1016/s0735-1097(00)01054-8', '10.1016/s0735-1097(00)01054-8', '11153730', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 351 studies (18.712 mensen) en een eigen laboratoriumonderzoek (514 mensen) was de maximale hartslag te voorspellen met 208 − 0,7 × leeftijd (r = −0,90 met de leeftijd); het laboratorium gaf vrijwel hetzelfde: 209 − 0,7 × leeftijd. Er was geen verschil tussen mannen en vrouwen of naar hoeveel iemand bewoog. De bekende 220 − leeftijd schat de maximale hartslag van ouderen te laag.', '2026-10-28', '2026-10-28', 'a1cbe4a9d8a905fc5ec33b62578105e0f16c82f086b1e3f5d06891ef7f6eb896'),
    ('AGE-02', 'approved', 'position_stand', 'en', 'American College of Sports Medicine position stand. Quantity and quality of exercise for developing and maintaining cardiorespiratory, musculoskeletal, and neuromotor fitness in apparently healthy adults: guidance for prescribing exercise', 'Garber CE, Blissmer B, Deschenes MR, et al.', 'American College of Sports Medicine', '2011', 'Med Sci Sports Exerc 2011;43(7):1334–1359', 'https://doi.org/10.1249/MSS.0b013e318213fefb', '10.1249/MSS.0b013e318213fefb', '21694556', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Voor de meeste volwassenen: matige duurinspanning minstens 30 minuten op minstens 5 dagen (150 minuten of meer per week), of zware minstens 20 minuten op minstens 3 dagen (75 minuten of meer), of een combinatie; krachtoefeningen voor elke grote spiergroep op 2 tot 3 dagen; oefeningen voor balans, behendigheid en coördinatie op 2 tot 3 dagen. De indeling naar intensiteit legt matig bij 64–76% en zwaar bij 77–95% van de maximale hartslag.', '2026-10-28', '2026-10-28', '82d7dfe77a4fc924958fdf5fbbd9c07e9844a00264b27aed2dd5bfedf1e1d2da'),
    ('AGE-03', 'approved', 'systematic_review', 'en', 'Meta-analysis of quantitative sleep parameters from childhood to old age in healthy individuals: developing normative sleep values across the human lifespan', 'Ohayon MM, Carskadon MA, Guilleminault C, Vitiello MV', NULL, '2004', 'Sleep 2004;27(7):1255–1273', 'https://doi.org/10.1093/sleep/27.7.1255', '10.1093/sleep/27.7.1255', '15586779', NULL, NULL, 'link', NULL, 'Geen open licentie', NULL, 0, 'In 65 studies (3.577 gezonde mensen van 5 tot 102 jaar) namen bij volwassenen de totale slaaptijd, de slaapefficiëntie, het aandeel diepe slaap, het aandeel REM-slaap en de tijd tot de eerste REM-slaap af met de leeftijd, terwijl de inslaaptijd, het aandeel lichte slaap en het wakker liggen in de nacht toenamen. Na je 60e bleef alleen de slaapefficiëntie verder dalen. Hoe sterk de veranderingen leken, hing sterk af van hoe zorgvuldig de deelnemers waren geselecteerd.', '2026-10-28', '2026-10-28', '32fedc838434e49eafdfb72260974075bf5c5c291c51ca2967722b3dacd89b1c'),
    ('AGE-04', 'approved', 'consensus', 'en', 'Evidence-based recommendations for optimal dietary protein intake in older people: a position paper from the PROT-AGE Study Group', 'Bauer J, Biolo G, Cederholm T, et al.', 'PROT-AGE Study Group', '2013', 'J Am Med Dir Assoc 2013;14(8):542–559', 'https://doi.org/10.1016/j.jamda.2013.05.021', '10.1016/j.jamda.2013.05.021', '23867520', NULL, NULL, 'link', NULL, 'CC BY-NC-ND', NULL, 0, 'Ouderen hebben meer eiwit nodig dan jongere volwassenen. Boven de 65: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag; 1,2 g/kg of meer voor wie sport of anderszins actief is; 1,2–1,5 g/kg voor de meeste mensen met een acute of chronische ziekte. Uitzondering: ernstige nierziekte zonder dialyse.', '2026-10-28', '2026-10-28', 'a393e9ff3034a9a8b7bcbc51d2fa0c258871085c0e25a882633bfeb93c15db9f'),
    ('AGE-05', 'approved', 'consensus', 'en', 'Protein intake and exercise for optimal muscle function with aging: recommendations from the ESPEN Expert Group', 'Deutz NE, Bauer JM, Barazzoni R, et al.', 'ESPEN Expert Group', '2014', 'Clin Nutr 2014;33(6):929–936', 'https://doi.org/10.1016/j.clnu.2014.04.007', '10.1016/j.clnu.2014.04.007', '24814383', 'PMC4208946', NULL, 'link', NULL, 'Gratis te lezen in PMC, licentie nog te bevestigen', NULL, 0, 'Gezonde ouderen: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag. Ouderen die ondervoed zijn of dat dreigen te worden door een acute of chronische ziekte: 1,2–1,5 g/kg, meer bij ernstige ziekte of letsel. Dagelijks bewegen of sporten (kracht en duur) voor alle ouderen, zo lang het kan.', '2026-10-28', '2026-10-28', '84b4d8f1b4f09ffbf8f159b86f89263dc8bf7fb3746f3aac60bba6bb63af5059'),
    ('AGE-06', 'approved', 'pooled_analysis', 'en', 'Protein ingestion to stimulate myofibrillar protein synthesis requires greater relative protein intakes in healthy older versus younger men', 'Moore DR, Churchward-Venne TA, Witard O, et al.', NULL, '2015', 'J Gerontol A Biol Sci Med Sci 2015;70(1):57–62', 'https://doi.org/10.1093/gerona/glu103', '10.1093/gerona/glu103', '25056502', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'De spiereiwitopbouw na één maaltijd vlakte af bij ongeveer 0,24 gram eiwit per kilogram lichaamsgewicht bij jonge mannen (rond 22 jaar) en bij ongeveer 0,40 g/kg bij oudere mannen (rond 71 jaar); het verschil was net niet significant (p = 0,055). Per kilogram vetvrije massa was het 0,25 tegenover 0,60 g/kg (p < 0,01). Oudere mannen reageren minder op kleine porties eiwit en hebben per maaltijd meer nodig.', '2026-10-28', '2026-10-28', '352bd07a23153fc370a4e12c906e38447dd63f3edd73b7e201486cc01f092b7a'),
    ('AGE-07', 'approved', 'observational', 'en', 'Short-term heart rate variability—influence of gender and age in healthy subjects', 'Voss A, Schroeder R, Heitmann A, Peters A, Perz S', NULL, '2015', 'PLoS One 2015;10(3):e0118308', 'https://doi.org/10.1371/journal.pone.0118308', '10.1371/journal.pone.0118308', '25822720', 'PMC4378923', NULL, 'link', NULL, 'CC BY 4.0', NULL, 0, 'Bij 1.906 gezonde volwassenen (metingen van 5 minuten) veranderde de HRV in vrijwel al haar maten duidelijk met de leeftijd, over de decennia 25–34, 35–44, 45–54, 55–64 en 65–74. Verschillen tussen mannen en vrouwen verdwenen in de laatste twee decennia, en de leeftijdseffecten in het laatste.', '2026-10-28', '2026-10-28', 'd1ed18839d3290dae38cac69bcc69be6ee9105073bc6df0bf3515866172469a1'),
    ('AGE-08', 'approved', 'systematic_review', 'en', 'Advancing Age Is Not Associated With Greater Exercise-Induced Muscle Damage: A Systematic Review, Meta-Analysis, and Meta-Regression', 'Fernandes JFT, Wilson LJ, Dingley AF, et al.', NULL, '2025', 'J Aging Phys Act 2025;33(6):606–624', 'https://doi.org/10.1123/japa.2024-0165', '10.1123/japa.2024-0165', '40174882', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'In 36 studies die oudere met jongere volwassenen vergeleken, was de daling van de spierfunctie 24 en 72 uur na inspanning, en de piek ervan, bij ouderen niet anders dan bij jongeren. Spierpijn en creatinekinase waren bij jongeren juist hoger. Conclusie: een hogere leeftijd gaat niet samen met meer spierschade door inspanning.', '2026-10-28', '2026-10-28', 'd44d5592f434fef8d389c787fa6f05f125054872b4de4e63be4084585806e6b1');

INSERT IGNORE INTO `research_claims` (`claim_key`, `version`, `source_code`, `topic`, `metric`, `age_min`, `age_max`, `strength`, `finding_json`, `statement`, `population`, `limitations_json`, `approved_on`, `content_hash`) VALUES
    ('sleep.duration.cdc', 1, 'SLP-01', 'sleep.duration', 'sleep_minutes', 6, NULL, 'betrouwbaar', '{"bands":[[6,12,{"max":12,"min":9}],[13,17,{"max":10,"min":8}],[18,60,{"max":null,"min":7}],[61,64,{"max":9,"min":7}],[65,null,{"max":8,"min":7}]],"unit":"hours"}', 'Per leeftijd een aanbevolen slaapduur: 7 uur of meer van 18 tot 60 jaar, 7–9 uur van 61 tot 64, 7–8 uur vanaf 65, en 8–10 uur voor tieners van 13 tot 17.', 'Iedereen vanaf 6 jaar, zonder slaapstoornis.', '["Een samenvatting van twee panels met iets andere leeftijdsgrenzen.","Iedereen heeft een eigen slaapbehoefte.","Een wearable schat slaaptijd vaak te hoog in."]', '2026-10-27', '5d8a00365ec3542271dba7a30c9553096f1601f5b18aed46638531478b136846'),
    ('sleep.duration.adults', 1, 'SLP-02', 'sleep.duration', 'sleep_minutes', 18, 60, 'betrouwbaar', '{"min":7,"unit":"hours"}', 'Volwassenen van 18 tot 60 jaar: regelmatig minstens 7 uur slaap per nacht. Korter slapen hangt samen met meer gezondheidsrisico''s.', 'Gezonde volwassenen van 18 tot 60 jaar.', '["Gebaseerd op verbanden, niet op bewezen oorzaken.","Gaat alleen over duur, niet over kwaliteit of tijdstip.","Niet voor mensen met een slaapstoornis."]', '2026-10-27', '24245c0d0357306097b0216d4204106828e08351c2ec9e1c317793d8cd74837d'),
    ('sleep.duration.nsf', 1, 'SLP-03', 'sleep.duration', 'sleep_minutes', 14, NULL, 'betrouwbaar', '{"bands":[[14,17,{"max":10,"min":8}],[18,64,{"max":9,"min":7}],[65,null,{"max":8,"min":7}]],"unit":"hours"}', 'Bij volwassenen past 7–9 uur slaap, bij 65-plussers 7–8 uur en bij tieners 8–10 uur.', 'Gezonde mensen zonder slaapstoornis.', '["Een consensus van deskundigen; de bereiken zijn geen diagnose.","Daarbuiten slapen kan passend zijn."]', '2026-10-27', 'f91d282c9baa218926fb6612a8f25f34e13e2099f9968976f37b3ad376681f4b'),
    ('sleep.duration.youth', 1, 'SLP-04', 'sleep.duration', 'sleep_minutes', 6, 18, 'betrouwbaar', '{"bands":[[6,12,{"max":12,"min":9}],[13,18,{"max":10,"min":8}]],"unit":"hours"}', 'Kinderen van 6 tot 12 jaar: 9–12 uur slaap per 24 uur; tieners van 13 tot 18 jaar: 8–10 uur.', 'Kinderen en tieners.', '["Gebaseerd op verbanden, niet op bewezen oorzaken.","Iedereen heeft een eigen slaapbehoefte."]', '2026-10-27', '2b9a2b40f98da136530d1b3bf7229762d10a4793a6ebf6591163393e38195a46'),
    ('sleep.regularity', 1, 'SLP-05', 'sleep.regularity', 'sleep_timing', NULL, NULL, 'betrouwbaar', '{"consistent_timing":true,"threshold_minutes":null}', 'Regelmatige tijden van inslapen en opstaan zijn belangrijk voor gezondheid, veiligheid en prestaties.', 'Volwassenen en jongeren.', '["Vooral observationeel onderzoek.","Er is geen aanbevolen aantal minuten speling.","Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen."]', '2026-10-27', 'c89364b26d9fbbd1a5c55438373bc4dc855fb2cfe183204019d1beb50f364150'),
    ('sleep.tracking', 1, 'SLP-06', 'measurement.sleep', 'sleep_stages', NULL, NULL, 'beperkt', '{"sleep_sensitivity_min":0.93,"stages":"inconsistent","wake_specificity":[0.18,0.54]}', 'Wearables herkennen slaap goed, maar wakker liggen en slaapfasen zoals diep en REM veel minder betrouwbaar.', 'Gezonde jonge volwassenen, apparaten uit 2019.', '["Eén kleine studie.","Oudere apparaten dan die van nu."]', '2026-10-27', '9da1f5f48d1a4674ba6e6ff9ca9ecc442b54f96c2f55abd808442f386b509783'),
    ('activity.who.adults', 2, 'ACT-05', 'activity.aerobic', 'activity_minutes_week', 18, 64, 'zeer_betrouwbaar', '{"moderate_minutes_week":[150,300],"strength_days_week":2,"vigorous_counts_double":true,"vigorous_minutes_week":[75,150]}', 'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een mix waarin een zware minuut telt als twee matige, plus spierversterkende activiteiten op minstens 2 dagen.', 'Volwassenen van 18 tot 64 jaar, ook met een chronische aandoening.', '["Alle beweging telt mee, ook wandelen, fietsen en klussen.","Ownify ziet alleen de trainingen die je vastlegt.","Of een training zwaar was, leidt Ownify af uit je hartslag tegen de maximale hartslag voor je leeftijd."]', '2026-10-28', '7b51abbcc8f9813f2ca7118835ff7747067b384d9c27d62a5a3b4593b5264779'),
    ('activity.who.older', 1, 'ACT-01', 'activity.aerobic', 'activity_minutes_week', 65, NULL, 'zeer_betrouwbaar', '{"balance_strength_days_week":3,"moderate_minutes_week":[150,300],"strength_days_week":2,"vigorous_minutes_week":[75,150]}', 'Vanaf 65 jaar dezelfde hoeveelheid beweging, plus op minstens 3 dagen per week activiteiten voor balans en kracht.', 'Volwassenen vanaf 65 jaar.', '["Alle beweging telt mee, ook wandelen, fietsen en klussen.","Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', 'd99372f410a1ee8665abb96139c112c90c207906a74b56f438e81aaea103c591'),
    ('activity.who.some', 1, 'ACT-01', 'activity.any', NULL, 5, NULL, 'zeer_betrouwbaar', '{"any_activity_better_than_none":true}', 'Een beetje bewegen is beter dan niets: zitten vervangen door bewegen van elke intensiteit, ook licht, levert gezondheidswinst op.', 'Iedereen vanaf 5 jaar.', '["Een algemene aanbeveling, geen voorspelling voor één persoon."]', '2026-10-27', 'ba82753189ca6f67f6aa5c3531c16d94072d94b47f11456a4132d3a6555d01a8'),
    ('activity.gr.adults', 1, 'ACT-02', 'activity.aerobic', 'activity_minutes_week', 18, NULL, 'zeer_betrouwbaar', '{"moderate_minutes_week":150,"strength_bone_per_week":2}', 'Minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, en minstens twee keer per week spier- en botversterkende activiteiten.', 'Volwassenen en ouderen (ouderen ook balansoefeningen).', '["Alle beweging telt mee, ook wandelen en fietsen.","Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', '16d7f32007a89e29f0c9748f3f9ebfd3a67748c2aacdc29c2ce5b34e97e7b9ac'),
    ('activity.gr.youth', 1, 'ACT-02', 'activity.aerobic', 'activity_minutes_day', 4, 17, 'zeer_betrouwbaar', '{"moderate_minutes_day":60,"strength_bone_per_week":3}', 'Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.', 'Kinderen en jongeren van 4 tot 18 jaar.', '["Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', '2e22344702a47771534578efaf4b6223613df7b136f50d6621afc84040be21cb'),
    ('activity.steps.ding', 1, 'ACT-03', 'activity.steps', 'steps_day', 18, NULL, 'betrouwbaar', '{"comparison_steps":2000,"levelling_steps":[5000,7000],"reference_steps":7000}', 'Meer stappen per dag hangt samen met minder gezondheidsrisico''s; rond 7.000 stappen per dag was dat verband al duidelijk.', 'Volwassenen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Voor de meeste uitkomsten maar enkele studies.","Stappentellers tellen meestal iets te weinig."]', '2026-10-27', '099a012b6b7807de646097a6cee88256069ffc7f899b99aacdc69ec9fae0ff38'),
    ('activity.steps.paluch', 1, 'ACT-04', 'activity.steps', 'steps_day', 18, NULL, 'betrouwbaar', '{"bands":[[18,59,{"plateau":[8000,10000]}],[60,null,{"plateau":[6000,8000]}]]}', 'Het verband met een lager sterfterisico vlakt af rond 8.000–10.000 stappen per dag onder de 60 en rond 6.000–8.000 vanaf 60 jaar.', 'Volwassenen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Gaat alleen over sterfte."]', '2026-10-27', '599589ad38d771895af6f72b4f4067dbfffa44f5b683fa105c07d2a5eee74849'),
    ('measurement.wearables', 1, 'MEA-01', 'measurement.accuracy', NULL, NULL, NULL, 'betrouwbaar', '{"energy_error_pct":[-21,15],"heart_rate_bias_pct":3,"sleep_time":"overestimated","spo2_difference_max":2,"steps_error_pct":[-9,12]}', 'Wearables meten hartslag en stappen redelijk nauwkeurig, maar energieverbruik en slaaptijd veel minder: calorieën kunnen ruim 20% afwijken en slaaptijd wordt meestal overschat.', 'Consumentenwearables, vooral bij gezonde volwassenen.', '["Studies gebruiken verschillende methoden.","Apparaten veranderen sneller dan het onderzoek."]', '2026-10-27', 'd444c356f3b97ea7973aaba07974e26489a6160f9a763a35ce73355ccf6e8f00'),
    ('nutrition.fat', 1, 'NUT-01', 'nutrition.fat', 'fat_energy_pct', 4, NULL, 'zeer_betrouwbaar', '{"energy_pct":[20,40]}', 'Totaal vet: 20–40% van je energie. De kwaliteit van wat je eet telt meer dan de hoeveelheid vet.', 'Iedereen vanaf 4 jaar.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', 'fdcdf4bf6dcdba0ebac419ffbfc5d0d3ab79aef9dd49901730fbaee7dcfcb790'),
    ('nutrition.saturated_fat', 1, 'NUT-01', 'nutrition.saturated_fat', 'saturated_fat_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"as_low_as_possible":true,"interim_energy_pct_max":10}', 'Verzadigd vet: zo laag mogelijk, met 10% van je energie als tussenstap.', 'Iedereen.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', '98cafa493cae7666a1c533b11624de4c6a8d0be0b07c05dca1c49d3f89d27f6f'),
    ('nutrition.carbohydrates', 1, 'NUT-01', 'nutrition.carbohydrates', 'carbs_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"percentage_norm":false}', 'Koolhydraten hebben geen percentagenorm: het gaat om de bron, vooral volkoren, groente, fruit, peulvruchten en zuivel.', 'Iedereen.', '["Ownify ziet niet uit welke producten je koolhydraten komen."]', '2026-10-27', '25dc365d79c2890168c81e8b93d53baf8bb76c5820d23a5d0e4ecb635bfdfc0d'),
    ('nutrition.free_sugars', 1, 'NUT-01', 'nutrition.sugars', 'free_sugars_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"as_low_as_possible":true,"interim_energy_pct_max":10}', 'Vrije suikers: zo laag mogelijk, met 10% van je energie als tussenstap.', 'Iedereen.', '["Je apps loggen alle suikers samen, ook die in fruit en melk; vrije suikers zijn daaruit niet af te lezen."]', '2026-10-27', 'fc2ba7b820ecd8bb08c74c39ce7bab6147b15f4be367d3ceb3e86af906fc69ee'),
    ('nutrition.fibre', 1, 'NUT-01', 'nutrition.fibre', 'fibre_per_1000_kcal', 2, NULL, 'zeer_betrouwbaar', '{"g_per_1000_kcal":[12.6,14.6],"g_per_mj":[3.0,3.5]}', 'Vezels: 3,0–3,5 gram per megajoule, dat is 12,6–14,6 gram per 1.000 kcal, uit volkoren, groente, fruit, peulvruchten en noten.', 'Iedereen vanaf 2 jaar.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', 'acb476216e7bf0b862ddb2afb032d45a5c4e4eb3540ee164394c9db7b67ff5de'),
    ('nutrition.protein', 1, 'NUT-02', 'nutrition.protein', 'protein_g_per_kg', 18, NULL, 'zeer_betrouwbaar', '{"average_requirement_g_per_kg":0.66,"g_per_kg_day":0.83}', 'Volwassenen: aanbevolen 0,83 gram eiwit per kilogram lichaamsgewicht per dag.', 'Volwassenen, ook ouderen.', '["Ownify ziet wat je logt, niet alles wat je eet.","Je eiwitdoel in Ownify hangt af van je activiteit en kan hoger liggen."]', '2026-10-27', '3fc1e73f33173c71ac4c5d4b7468de4df11c610b78879b807690c9d6847d7a44'),
    ('nutrition.protein.exercise', 2, 'NUT-05', 'nutrition.protein', 'protein_g_per_kg', 18, 64, 'beperkt', '{"applies_to":"exercising","g_per_kg_day":[1.4,2.0]}', 'Voor mensen die sporten noemt een sportvoedingsvereniging 1,4–2,0 gram eiwit per kilogram per dag voldoende om spiermassa op te bouwen en te behouden.', 'Gezonde mensen van 18 tot 64 jaar die sporten.', '["Het standpunt van een vereniging, geen officiële norm.","Voor de algemene bevolking geldt 0,83 gram per kilogram."]', '2026-10-27', '506ff80d65264f27a6b64a808284c01a453eaeb439ca3f345572fb3000be5ee0'),
    ('nutrition.salt', 1, 'NUT-03', 'nutrition.salt', 'salt_g_day', 18, NULL, 'zeer_betrouwbaar', '{"salt_g_day_max":6,"salt_per_sodium":2.5}', 'Maximaal 6 gram zout per dag.', 'Volwassenen.', '["Apps loggen zout dat je zelf toevoegt meestal niet."]', '2026-10-27', '7282ed7b7e605457ed0672431d3d75e99997664b119b9d6f7a523835bf797ba7'),
    ('nutrition.pattern', 2, 'NUT-07', 'nutrition.pattern', NULL, NULL, NULL, 'zeer_betrouwbaar', '{"legumes_g_week":250,"plant_based":true,"red_meat_g_week_max":200}', 'Gezond eten is meer plantaardig en minder dierlijk. Het advies van 2025 over eiwitbronnen noemt per week ongeveer 250 gram peulvruchten en hooguit ongeveer 200 gram rood vlees; voor groente, fruit en granen gelden de richtlijnen van 2015.', 'De algemene bevolking.', '["Groente, fruit en granen krijgen een nieuw advies; tot dan gelden de richtlijnen van 2015.","Ownify ziet niet welke producten je eet."]', '2026-10-28', 'b5f121c31a215984df42e166473f258846d13175b86082cf211e81a2cf54ce22'),
    ('nutrition.energy_estimate', 1, 'NUT-04', 'nutrition.energy', 'energy_target', 18, NULL, 'betrouwbaar', '{"within_pct":10}', 'Je energiedoel is een schatting met de Mifflin-St Jeor-formule. Die zit bij de meeste mensen binnen 10% van het gemeten rustverbruik, maar kan per persoon meer afwijken.', 'Gezonde volwassenen, met en zonder obesitas.', '["Gaat over rustverbruik; de factor voor je activiteit is een tweede schatting.","Onderzoek uit 2005."]', '2026-10-27', '2295540cc48f33ffaf14cb44892af77123d74cb0a2abae87f0f0309f00cdc334'),
    ('body.bmi', 1, 'BOD-01', 'body.bmi', 'bmi', 18, NULL, 'zeer_betrouwbaar', '{"obesity_min":30,"overweight_min":25}', 'De WHO noemt bij volwassenen een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas.', 'Volwassenen vanaf 18 jaar.', '["BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.","Een indeling voor groepen, geen oordeel over één persoon."]', '2026-10-27', '546aa5643ef389f01368f92612f142487ad703a90f2d4685bf5f2e799cacb871'),
    ('cross.activity.sleep', 1, 'XSA-02', 'cross.activity_sleep', NULL, 18, NULL, 'betrouwbaar', '{"acute":"improves","longer_bouts":"more","regular":"improves"}', 'Bewegen, één keer of regelmatig, gaat gemiddeld samen met beter slapen; langere sessies helpen meer.', 'Volwassenen van alle leeftijden, ook met slapeloosheidsklachten of slaapapneu.', '["Gebaseerd op reviews van wisselende kwaliteit.","Gemiddelden over groepen, geen voorspelling voor één persoon.","Het beste tijdstip en de beste hoeveelheid zijn nog onzeker."]', '2026-10-28', '9556a3ca831b5684f53e016c065c62353ab97229c1fe58a1d51dbc6e427d4043'),
    ('cross.exercise.sleep', 1, 'XSA-01', 'cross.activity_sleep', NULL, NULL, NULL, 'betrouwbaar', '{"acute_effect":"small","intensity_mattered":false,"regular_effect":"small_to_moderate"}', 'Eén keer bewegen gaat gemiddeld samen met iets langer en efficiënter slapen, regelmatig bewegen ook met een betere slaapkwaliteit. De effecten zijn klein tot matig.', 'Gemengde groepen, ook mensen met slapeloosheid.', '["Studies tot 2013, vooral klein en in een laboratorium.","Gemiddelden over groepen, geen voorspelling voor één persoon."]', '2026-10-28', '8726e8b56cdcbfa7cfeaf74cedf53e4ef898b8c7b9e0dbf194669437bfe07a93'),
    ('cross.daily.sleep_activity', 1, 'XSA-03', 'cross.activity_sleep', NULL, 18, NULL, 'betrouwbaar', '{"day_to_day":"small","direction":"varies"}', 'Van dag tot dag hangen slapen en bewegen maar weinig samen: de verbanden zijn klein en verschillen per persoon en per richting.', 'Volwassenen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Weinig studies keken binnen één persoon."]', '2026-10-28', '752b592f9688ba47b42c37230f8741c0eb37274d51df3e4d70a42d34a7d115b6'),
    ('cross.strength.sleep', 1, 'XSA-08', 'cross.activity_sleep', NULL, 18, NULL, 'betrouwbaar', '{"regular":"improves","single_session":"unclear"}', 'Regelmatige krachttraining verbetert de slaap, vooral de slaapkwaliteit; over één losse training is weinig bekend.', 'Volwassenen in gerandomiseerde studies.', '["Geen samengevoegde getallen.","Gemengde groepen deelnemers."]', '2026-10-28', 'cef051ab17135b7f80fadc3630cf84bdb8decc6f16213cdb6b3470f9a5a94ddc'),
    ('cross.evening_exercise.sleep', 1, 'XSA-06', 'cross.exercise_timing', NULL, 18, NULL, 'beperkt', '{"example_onset_delay_minutes":36,"safe_hours_before_onset":4}', 'Later en zwaarder trainen ging samen met later inslapen, korter slapen, een hogere hartslag en een lagere HRV in de nacht; training die minstens 4 uur voor het inslapen eindigde, niet.', 'Volwassen dragers van één merk wearable, vooral actieve mensen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Eén studie, met gebruikers van één merk.","De wearable mat niet wanneer mensen probeerden te gaan slapen."]', '2026-10-28', 'c96c3298614546976ee4db8779406dd7def2e59cba217ed93e151c0f02fa9cc4'),
    ('cross.evening_exercise.review', 1, 'XSA-04', 'cross.exercise_timing', NULL, 18, NULL, 'betrouwbaar', '{"evening_exercise":"no_harm","vigorous_within_hours":1}', 'Bewegen in de avond maakt de slaap gemiddeld niet slechter; alleen zware inspanning die een uur of minder voor bedtijd eindigt, kan het inslapen vertragen.', 'Gezonde, vooral jonge volwassenen.', '["Losse sessies in een laboratorium.","De effecten van tijdstip en zwaarte waren niet stevig."]', '2026-10-28', '74e724014881a320bab35a1ded7a79ad5a230d538d7b0e7f0fbc08ab892496f0'),
    ('cross.evening_hie.sleep', 1, 'XSA-05', 'cross.exercise_timing', NULL, 18, 50, 'betrouwbaar', '{"no_disruption_hours":[2,4],"rem_pct_change":-2.34}', 'Zware training 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen niet; dichter op bedtijd verminderde ze de REM-slaap licht.', 'Gezonde volwassenen van 18 tot 50 jaar die goed slapen.', '["Kleine studies.","Alleen goede slapers."]', '2026-10-28', '0aa66c593cdb792e5a01f1d03d03cefbd09a825665a39ef71f723cbbfb2d7a5a'),
    ('cross.training.hrv', 1, 'XTH-05', 'cross.training_heart', 'hrv', 18, NULL, 'beperkt', '{"after_hard_pct":-3,"after_light_pct":1.6,"alcohol_pct":-12,"sickness_pct":-10}', 'Na zware training was de HRV de volgende ochtend gemiddeld ongeveer 3% lager, na lichte training ongeveer 1,6% hoger. Ook alcohol en ziekte verlagen de HRV, dus een lage HRV zegt niet waardoor.', 'Volwassen gebruikers van een HRV-app.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Een meting van 1 minuut bij het wakker worden; Ownify gebruikt de waarden uit de nacht.","Gezondheidsbewuste gebruikers van één app."]', '2026-10-28', '85ee32a81529299e39c62aab6010e579be907f5573e5ae72834df569a79a363a'),
    ('cross.training.hr', 1, 'XTH-05', 'cross.training_heart', 'resting_hr', 18, NULL, 'beperkt', '{"after_hard_pct":0.7,"alcohol_pct":6,"sickness_pct":6}', 'Na zware training was de hartslag in rust de volgende ochtend gemiddeld ongeveer 0,7% hoger. Ook alcohol en ziekte verhogen hem, dus een hogere hartslag zegt niet waardoor.', 'Volwassen gebruikers van een HRV-app.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Een meting van 1 minuut bij het wakker worden; Ownify gebruikt de waarden uit de nacht."]', '2026-10-28', 'd79b148f63b229c8846759b258d0070905b50c2cde746cb76926ea256b24bfef'),
    ('cross.training.recovery', 1, 'XTH-01', 'recovery', 'hrv', 18, NULL, 'beperkt', '{"hours_hard_min":48,"hours_light":24,"hours_threshold":[24,48]}', 'Het hart herstelt na lichte duurtraining binnen ongeveer 24 uur, na training rond de drempel in 24 tot 48 uur en na zware training in minstens 48 uur. Fittere mensen herstellen sneller.', 'Volwassenen na duurtraining.', '["Vooral duurtraining; over krachttraining is weinig bekend.","Verschilt sterk per persoon."]', '2026-10-28', '17ae6228745e10851553d5b2c364dddaf625e53748207fcc216452af61e8a278'),
    ('hrv.monitoring', 1, 'XTH-02', 'hrv', 'hrv', 18, NULL, 'betrouwbaar', '{"better_performance_smd":0.58,"overreaching":"little_change"}', 'Een hogere HRV in rust kan bij goede aanpassing aan training horen, maar bij overbelasting verandert de HRV nauwelijks: één HRV-waarde zegt niet of training goed of slecht uitpakt.', 'Duursporters.', '["Vooral getrainde duursporters.","Veel kleine studies."]', '2026-10-28', '233dc3e6d5e9565296225d817b27fe64e13dfa8ddb0c63f8b82158befd0b3818'),
    ('hrv.averaging', 1, 'XTH-03', 'hrv', 'hrv', 18, NULL, 'beperkt', '{"use":"rolling_mean_of_log"}', 'Volg HRV met gemiddelden over meerdere dagen, niet met losse metingen: zowel een stijging als een daling kan bij slechte aanpassing horen.', 'Topsporters in duursport.', '["Een overzichtsartikel over topsporters."]', '2026-10-28', '451869e6baecb8001fed3dc9ddb7b27d9da26431aa607675a74dac23e693b8cb'),
    ('hrv.change', 1, 'XTH-04', 'hrv', 'hrv', 18, NULL, 'beperkt', '{"read_against":["measurement_error","smallest_worthwhile_change"]}', 'Lees een verandering in je HRV of hartslag tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van je training.', 'Sporters.', '["Een overzichtsartikel met een mening."]', '2026-10-28', '4256876edde18cc60e7e715e0b5b0ad6ad5dae905c856bf1b36b5a16c95265a0'),
    ('hrv.norms', 1, 'HRT-02', 'hrv', 'hrv', NULL, NULL, 'beperkt', '{"depends_on":["recording_length","age","sex"]}', 'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht, dus vergelijk je HRV het best met je eigen waarden.', 'Iedereen.', '["Een verhalend overzichtsartikel."]', '2026-10-28', '80a966b48ef0ec9dca947dd7956db5fdc68af43ed0f62d5608404182b5c12c38'),
    ('measurement.hrv', 1, 'MEA-02', 'measurement.accuracy', 'hrv', NULL, NULL, 'beperkt', '{"hrv_error_pct":[4.1,112],"resting_hr":"accurate"}', 'Wearables meten de rusthartslag nauwkeurig, maar hoe goed ze HRV meten verschilt sterk per apparaat.', 'Volwassenen, met apparaten van 2020.', '["Een heel kleine studie.","Oudere apparaten dan die van nu."]', '2026-10-28', '409baa4ec12bd347705676fd545b41d8135a888f9d432e4cc645e6dd824e917f'),
    ('heart.resting.risk', 1, 'HRT-01', 'heart.resting', 'resting_hr', 18, NULL, 'betrouwbaar', '{"rr_above_80":{"all_cause":1.45,"cardiovascular":1.33},"rr_per_10_bpm":{"all_cause":1.09,"cardiovascular":1.08}}', 'In bevolkingsonderzoek ging elke 10 slagen per minuut hogere rusthartslag samen met 9% meer risico op overlijden en 8% meer op overlijden door hart- en vaatziekten; boven 80 slagen per minuut was dat 45% en 33% meer dan in de laagste groep. Ownify volgt je rusthartslag alleen tegen je eigen basis.', 'De algemene volwassen bevolking.', '["Een risico voor groepen, geen voorspelling voor één persoon.","De studies verschilden sterk, en er is kans op publicatiebias.","Observationeel onderzoek: een verband, geen bewezen oorzaak."]', '2026-10-28', 'e96040009ca4ab004773cfd75dd50478717bf9d597dda1076f424aff1970d83c'),
    ('cross.sleep.energy', 1, 'XSN-01', 'cross.sleep_nutrition', 'energy_kcal', 18, NULL, 'betrouwbaar', '{"ci":[252,517],"expenditure":"unchanged","extra_kcal_day":385}', 'Na korter slapen aten mensen in studies gemiddeld 385 kcal per dag meer, zonder meer te verbruiken.', 'Volwassenen in korte laboratoriumstudies.', '["Korte studies, met vrij toegang tot eten.","Kleine groepen."]', '2026-10-28', '2a0098b31d16d39b9a5f426dffc551fe2d05c3f2253f326118c0161c74df9598'),
    ('cross.sleep.diet', 1, 'XSN-02', 'cross.sleep_nutrition', 'energy_kcal', 18, NULL, 'betrouwbaar', '{"short_night_hours_max":5.5,"smd":0.37}', 'Slapen tot 5,5 uur of minder per nacht verhoogde in studies de energie-inname, en ook de inname van vet, eiwit en koolhydraten.', 'Volwassenen in interventiestudies.', '["Korte interventies.","Weinig studies naar langer slapen."]', '2026-10-28', '6529dcbc581f28e42054eb42e5ec777eda8fdfa35cf9fe9961f76de4fdf0adaa'),
    ('cross.sleep.performance', 1, 'XSP-01', 'cross.sleep_training', NULL, 18, NULL, 'betrouwbaar', '{"ci":[-11.9,-3.13],"performance_pct":-7.56,"short_night_hours_max":6}', 'Na een nacht van 6 uur of minder was de sportprestatie gemiddeld ongeveer 7,6% lager, bij elke soort inspanning.', 'Volwassenen, vaak sporters.', '["Gaat over prestatie, niet over of iemand traint.","Vooral korte laboratoriumstudies."]', '2026-10-28', '8ad622f0204a4b5a3a2858c103e01a7149e0da66040b9f4600750f9975242cd0'),
    ('sleep.athletes', 1, 'XSP-03', 'cross.sleep_training', NULL, 18, NULL, 'betrouwbaar', '{"approach":"individual"}', 'Hoeveel slaap iemand nodig heeft verschilt; deskundigen raden sporters een persoonlijke aanpak aan in plaats van één advies van 7–9 uur.', 'Sporters.', '["Gaat over topsporters.","Een verhalend overzicht met een consensus."]', '2026-10-28', 'e6b2a73f0bc469ac372f135098fc9851b03c6e1ea6a12dbc6bacdcde99f94b23'),
    ('cross.caffeine.sleep', 1, 'XSN-05', 'cross.caffeine_sleep', 'caffeine', 18, NULL, 'betrouwbaar', '{"coffee_hours_before_bed":8.8,"efficiency_pct":-7,"preworkout_hours_before_bed":13.2,"total_sleep_minutes":-45}', 'Cafeïne verkortte de slaap in studies gemiddeld met 45 minuten. Om dat te voorkomen zou koffie minstens 8,8 uur voor bedtijd gedronken moeten worden.', 'Volwassenen in laboratoriumstudies.', '["Laboratoriumstudies met vaste doses.","Ownify ziet alleen cafeïne die je logt."]', '2026-10-28', 'de0b370c0cddbd8c821aaf2b4d09f59d09271424a58c24cd710e0544c8e9a75b'),
    ('nutrition.fluid', 1, 'NUT-08', 'nutrition.fluid', 'water_l', 13, NULL, 'betrouwbaar', '{"exercise_loss_l_per_hour":[1,2],"hot_day_min_l":2,"max_l":3,"men_l":[1.4,1.8],"women_min_l":1.4}', 'Vanaf 13 jaar: drink minstens 1,4 liter per dag (mannen en jongens 1,4 tot 1,8 liter), en meer als je flink sport en zweet.', 'Iedereen vanaf 13 jaar.', '["Een algemeen advies.","Ownify ziet alleen wat je logt."]', '2026-10-28', '06148e2ffd55156672495ef265f0f8038c70a4177a177f054f619485792c38bd'),
    ('nutrition.timing', 1, 'XNT-01', 'nutrition.timing', NULL, 18, NULL, 'beperkt', '{"carbs_g_per_kg_h":0.8,"protein_g_per_kg_h":[0.2,0.4]}', 'Na veel of zware training kunnen het moment van eten en de verhouding tussen koolhydraten en eiwit het herstel helpen.', 'Getrainde mensen.', '["Het standpunt van een vereniging, gericht op getrainde mensen."]', '2026-10-28', 'e98e73e874b27d8a21bce46e46b980732da4dbec91d33f7b2430487c397ba4a4'),
    ('recovery.individual', 1, 'TRN-02', 'recovery', NULL, NULL, NULL, 'betrouwbaar', '{"monitoring":"individual"}', 'Herstel na belasting verschilt tussen mensen en bij één persoon van moment tot moment; je volgt het daarom het best bij jezelf.', 'Sporters.', '["Een consensus over begrippen, met weinig getallen."]', '2026-10-28', '304be92c97191e5bbc4afed4f65e7f701c368ce683c297b7d15a1b1901160748'),
    ('body.bmi.individual', 1, 'BOD-02', 'body.bmi', 'bmi', 18, NULL, 'betrouwbaar', '{"bmi_individual_measure":false,"confirm_with":["waist","waist_hip","waist_height","body_fat"]}', 'BMI is een maat voor groepen, niet voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een andere maat, zoals je buikomvang.', 'Volwassenen.', '["Een klinisch kader; Ownify meet geen buikomvang."]', '2026-10-28', '77f564300e91cb07c360ccd32ebacff36491fd95f882970b6f8b2b221554b705'),
    ('activity.intensity.age', 1, 'AGE-01', 'activity.intensity', 'hr_max', 18, NULL, 'betrouwbaar', '{"hr_max":{"base":208,"per_year":0.7}}', 'Je maximale hartslag is ongeveer 208 − 0,7 × je leeftijd. Ownify gebruikt je eigen hoogst gemeten hartslag als die hoger is.', 'Gezonde volwassenen, mannen en vrouwen.', '["Een formule voor groepen: de echte maximale hartslag van één persoon kan ervan afwijken."]', '2026-10-28', '3802bfc5f6ed5c21a94d723ff2c548e540b218346b1c790627ec0279b447c7fa'),
    ('activity.intensity.bands', 1, 'AGE-02', 'activity.intensity', 'hr_max_pct', 18, NULL, 'beperkt', '{"moderate_pct":[64,76],"vigorous_pct":[77,95]}', 'Matig intensief is vanaf ongeveer 64% van je maximale hartslag, zwaar vanaf 77%.', 'Gezonde volwassenen van alle leeftijden.', '["Het standpunt van een vereniging.","De grenzen zijn een afspraak, geen gemeten omslagpunt."]', '2026-10-28', '8f2faea8fe87e8232b5ce2b15e02af69fae5687f3ad97808b377756defc869cb'),
    ('sleep.architecture.age', 1, 'AGE-03', 'sleep.architecture', 'sleep_stages', 18, NULL, 'betrouwbaar', '{"after_60":["efficiency"],"decrease_with_age":["total_sleep","efficiency","deep_pct","rem_pct"]}', 'Met de jaren nemen slaapefficiëntie en diepe slaap af; na je 60e daalt vooral de efficiëntie nog. Een lagere waarde dan op je 25e is op latere leeftijd gewoon.', 'Gezonde mensen van 5 tot 102 jaar.', '["Onderzoek uit 1960–2003, in een slaaplaboratorium.","Weinig deelnemers van middelbare leeftijd."]', '2026-10-28', '932f3e7454158ec08a6c382207fb7c03e30960d762f3b2389e0888d8c5cde8a8'),
    ('hrv.age', 1, 'AGE-07', 'hrv', 'hrv', 18, NULL, 'beperkt', '{"sex_difference_fades_from":55,"with_age":"lower"}', 'HRV is gemiddeld lager naarmate je ouder bent, en verschillen tussen mannen en vrouwen verdwijnen rond je 55e. Daarom vergelijkt Ownify je HRV alleen met je eigen waarden.', 'Gezonde volwassenen van 25 tot 74 jaar.', '["Korte metingen overdag, geen waarden uit de nacht.","Eén Duitse groep."]', '2026-10-28', '871d30152aa895380a30064010d9951718a359871cd203d3b6e8437ee819a784'),
    ('recovery.age', 1, 'AGE-08', 'recovery', NULL, 18, NULL, 'betrouwbaar', '{"older_more_damage":false}', 'Oudere volwassenen kregen na inspanning niet meer spierschade dan jongere: hun spierfunctie na 24 en 72 uur was hetzelfde. Ownify gebruikt daarom bij elke leeftijd dezelfde hersteltijden.', 'Oudere en jongere volwassenen.', '["Inspanning in een laboratorium.","Gaat niet over het herstel van de hartslag."]', '2026-10-28', 'd6441dcf9b65d756bd6c36f342bd9d9803ffdacab73b2a5589c024c751e98805'),
    ('nutrition.protein.older', 1, 'AGE-04', 'nutrition.protein', 'protein_g_per_kg', 65, NULL, 'betrouwbaar', '{"active_g_per_kg_min":1.2,"dutch_norm_g_per_kg":0.83,"g_per_kg_day":[1.0,1.2]}', 'Vanaf 65 jaar: minstens 1,0–1,2 gram eiwit per kilogram per dag, en 1,2 of meer als je actief bent. De Nederlandse norm blijft 0,83 gram per kilogram.', 'Ouderen vanaf 65 jaar, zonder ernstige nierziekte.', '["Advies van deskundigen, op basis van het bewijs van 2013.","Niet bij ernstige nierziekte zonder dialyse."]', '2026-10-28', 'baecea3e0401245b6eade6b77353e33a19e0a1307596d62893cd1a3492d6404a'),
    ('nutrition.protein.older.espen', 1, 'AGE-05', 'nutrition.protein', 'protein_g_per_kg', 65, NULL, 'betrouwbaar', '{"g_per_kg_day":[1.0,1.2],"ill_g_per_kg_day":[1.2,1.5]}', 'Een tweede groep deskundigen: gezonde ouderen minstens 1,0–1,2 gram eiwit per kilogram per dag, en elke dag bewegen of sporten zolang het kan.', 'Ouderen.', '["Advies van deskundigen, zonder precieze leeftijdsgrens voor ouderen."]', '2026-10-28', '7c4fe797c3011911e182f77c3ca873c5d040875a9d90872ee8bcb03e699cf6b1'),
    ('nutrition.protein.meal.age', 1, 'AGE-06', 'nutrition.protein', 'protein_g_per_kg_meal', 18, NULL, 'beperkt', '{"bands":[[18,39,{"per_meal_g_per_kg":0.24}],[65,null,{"per_meal_g_per_kg":0.4}]]}', 'Per maaltijd vlakte de spieropbouw af bij ongeveer 0,24 gram eiwit per kilogram bij jonge mannen en bij ongeveer 0,40 gram per kilogram bij oudere mannen.', 'Gezonde mannen van rond de 22 en rond de 71 jaar.', '["Alleen mannen, en niets over 40 tot 64 jaar.","Porties eiwit in een laboratorium."]', '2026-10-28', 'a4d64c3989d2d6dcd33227e5a366db69c2f0fb8016d6d41bba2b020d03a0b1f9');
-- <<< REGISTRY SEED

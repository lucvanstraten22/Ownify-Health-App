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
--  PRIVATE (the owner and authorised services only)
--      user_measurements, health_metrics, sleep_sessions, nutrition_entries,
--      workouts, workout_hr_zones, daily_scores, goals, goal_progress,
--      ai_conversations, ai_messages, ai_usage
--
--  The one authorised outside service is Google Gemini, for Ownify AI: it is
--  sent the parts of a person's own data that fit their question, and only
--  once they have allowed it (user_profiles.ai_consent). See includes/ai/.
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


SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ai_service_state`;
DROP TABLE IF EXISTS `ai_usage`;
DROP TABLE IF EXISTS `ai_messages`;
DROP TABLE IF EXISTS `ai_conversations`;
DROP TABLE IF EXISTS `leaderboard_best_positions`;
DROP TABLE IF EXISTS `user_period_points`;
DROP TABLE IF EXISTS `point_events`;
DROP TABLE IF EXISTS `point_rules`;
DROP TABLE IF EXISTS `user_blocks`;
DROP TABLE IF EXISTS `friendships`;
DROP TABLE IF EXISTS `user_devices`;
DROP TABLE IF EXISTS `device_pairing_codes`;
DROP TABLE IF EXISTS `user_integrations`;
DROP TABLE IF EXISTS `goal_progress`;
DROP TABLE IF EXISTS `goals`;
DROP TABLE IF EXISTS `daily_scores`;
DROP TABLE IF EXISTS `workout_hr_zones`;
DROP TABLE IF EXISTS `workouts`;
DROP TABLE IF EXISTS `nutrition_entries`;
DROP TABLE IF EXISTS `sleep_sessions`;
DROP TABLE IF EXISTS `health_metric_day_totals`;
DROP TABLE IF EXISTS `health_metrics`;
DROP TABLE IF EXISTS `health_metric_types`;
DROP TABLE IF EXISTS `user_measurements`;
DROP TABLE IF EXISTS `data_sources`;
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
    `status`        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_seen_at`  DATETIME     NULL COMMENT 'Touched on each authenticated request',
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
    `locale`        VARCHAR(10)  NOT NULL DEFAULT 'nl',
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


-- ============================================================================
--  3c. PAIRED DEVICES  — PRIVATE
-- ============================================================================
--  Health Connect and Apple Health cannot be read from a server; an app on the
--  phone reads them and posts here, so that app needs to prove which account
--  it sends for. It cannot use the session cookie and must not hold the
--  password, so it holds a token of its own, minted by exchanging a short
--  pairing code the website shows once. One token per device, so losing a
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
--  4. GOALS  — PRIVATE
-- ============================================================================

-- metric_type_id is what lets a goal be measured from health data later
-- instead of by hand. Duration is start_date..end_date; it is not stored twice.
CREATE TABLE `goals` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `name`           VARCHAR(120) NOT NULL,
    -- The eight the interface offers, each with its own icon and accent in
    -- config/goals.php, plus the four this column originally held so a
    -- database created before migration 002 stays readable.
    `category`       ENUM('health','weight','strength','activity','nutrition','habit','performance','other',
                          'sleep','training','body','general') NOT NULL DEFAULT 'other',
    -- How progress is worked out, and the one thing that decides it
    -- (migration 007):
    --   milestone   Mijlpaal — the best result so far, never a sum
    --   streak      Streak   — consecutive successful days; a miss breaks it
    --   accumulate  Optellen — every contribution added to a total
    `goal_type`      ENUM('milestone','streak','accumulate') NOT NULL DEFAULT 'milestone'
                     COMMENT 'milestone = best result; streak = consecutive days; accumulate = running total',
    -- Where progress comes from, chosen by the person (migration 006). A
    -- source is not always a metric, so the kind names the table.
    `tracking_mode`  ENUM('auto','manual') NOT NULL DEFAULT 'manual'
                     COMMENT 'auto = read from the user''s own health data; manual = they keep it',
    `source_kind`    ENUM('metric','measurement','workout','manual') NOT NULL DEFAULT 'manual'
                     COMMENT 'which table the value comes from',
    `source_key`     VARCHAR(60) NULL COMMENT 'metric code, measurement type, or workout aspect',
    -- One active goal is primary and the rest are secondary, up to the limit
    -- in config/goals.php (`limits` → `active`). That limit is a rule the
    -- application enforces on write; this column only records which a goal
    -- is, so the ordering survives a reload.
    `priority`       ENUM('primary','secondary') NOT NULL DEFAULT 'secondary',
    `metric_type_id` SMALLINT UNSIGNED NULL COMMENT 'Set when progress can be read from health data',
    `target_value`   DECIMAL(14,4) NULL,
    `start_value`    DECIMAL(14,4) NULL COMMENT 'the baseline when the goal was made',
    `daily_target`   DECIMAL(14,4) NULL COMMENT 'what a single day has to reach to count',
    -- Where the goal stands, recalculated from the underlying rows and stored
    -- with it (migration 007). Only the column for the goal's own type is
    -- filled; NULL means nothing recorded yet, which is not zero.
    `best_value`     DECIMAL(14,4)     NULL,
    `total_value`    DECIMAL(14,4)     NULL,
    `streak_current` SMALLINT UNSIGNED NULL,
    `streak_best`    SMALLINT UNSIGNED NULL,
    `progress_pct`   DECIMAL(5,2)      NULL,
    `progress_at`    DATETIME          NULL,
    `target_unit`    VARCHAR(20) NULL,
    `direction`      ENUM('increase','decrease','maintain') NOT NULL DEFAULT 'increase',
    `start_date`     DATE NOT NULL,
    `end_date`       DATE NULL,
    `status`         ENUM('active','completed','paused','abandoned') NOT NULL DEFAULT 'active',
    `completed_at`   DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_goals_user_status` (`user_id`, `status`),
    KEY `idx_goals_user_priority` (`user_id`, `status`, `priority`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_goals_metric` FOREIGN KEY (`metric_type_id`)
        REFERENCES `health_metric_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One row per goal per day.
--
-- For a goal read from health data it is a snapshot: where the goal stood that
-- day. The live figure is always recalculated from the data itself.
--
-- For a goal the person keeps by hand it IS the data, and each type writes its
-- day its own way (migration 007):
--   milestone   the day's best result — a worse one later that day never
--               replaces a better one
--   accumulate  the day's contributions added together
--   streak      the day was done; the value is not read, only that it exists
-- An Optellen goal counted in days works like a streak row: one per day done.
CREATE TABLE `goal_progress` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id`          BIGINT UNSIGNED NOT NULL,
    `recorded_on`      DATE NOT NULL,
    `current_value`    DECIMAL(14,4) NULL,
    `percent_complete` DECIMAL(5,2) NULL,
    `computed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_goal_day` (`goal_id`, `recorded_on`),
    KEY `idx_progress_goal_day` (`goal_id`, `recorded_on`),
    CONSTRAINT `fk_progress_goal` FOREIGN KEY (`goal_id`)
        REFERENCES `goals` (`id`) ON DELETE CASCADE
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
    ('apple_health',         'Apple Health',          'platform'),
    ('google_health_connect','Google Health Connect', 'platform'),
    ('google_health',        'Google Health',         'platform'),
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
    ('steps',             'Stappen',              '',     'training',  'sum'),
    ('distance',          'Afstand',              'km',   'training',  'sum'),
    ('active_energy',     'Actieve calorieën',    'kcal', 'training',  'sum'),
    ('total_energy',      'Totale calorieën',     'kcal', 'training',  'sum'),
    ('active_minutes',    'Actieve minuten',      'min',  'training',  'sum'),
    ('floors',            'Verdiepingen',         '',     'training',  'sum'),
    ('vo2max',            'VO2max',               'ml/kg/min', 'training', 'last'),
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

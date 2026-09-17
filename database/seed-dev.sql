-- ============================================================================
--  AppName — DEVELOPMENT SEED DATA
-- ----------------------------------------------------------------------------
--  *** EVERY ROW IN THIS FILE IS FAKE. ***
--
--  These are not people and these are not health measurements. The accounts
--  are named dev_* and the email addresses use the reserved example.invalid
--  domain so they can never reach anybody. The numbers exist so that the
--  leaderboard, the friend system and the health tables can be exercised
--  locally — nothing here is a real reading and nothing here should ever be
--  loaded on a production database.
--
--  Import AFTER schema.sql:
--      mysql -u USER -p DATABASE < database/seed-dev.sql
--
--  Passwords: every dev account uses "devpassword" (hash below). Fine for a
--  local WampServer, never anywhere else.
-- ============================================================================

-- No USE: run this against whichever database you have selected.

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `point_events`;
DELETE FROM `user_period_points`;
DELETE FROM `leaderboard_best_positions`;
DELETE FROM `friendships`;
DELETE FROM `user_blocks`;
DELETE FROM `goal_progress`;
DELETE FROM `goals`;
DELETE FROM `health_metrics`;
DELETE FROM `sleep_sessions`;
DELETE FROM `workouts`;
DELETE FROM `nutrition_entries`;
DELETE FROM `user_measurements`;
DELETE FROM `user_auth_identities`;
DELETE FROM `user_profiles`;
DELETE FROM `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- --- accounts ---------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `created_at`) VALUES
    (1, 'dev_jij',   '2026-01-04 09:00:00'),
    (2, 'dev_lisa',  '2026-01-06 09:00:00'),
    (3, 'dev_noah',  '2026-01-09 09:00:00'),
    (4, 'dev_sam',   '2026-01-11 09:00:00'),
    (5, 'dev_emma',  '2026-01-14 09:00:00');

INSERT INTO `user_profiles` (`user_id`, `first_name`, `date_of_birth`, `gender`) VALUES
    (1, 'Dev',  '1996-04-12', 'undisclosed'),
    (2, 'Dev',  '1994-08-02', 'undisclosed'),
    (3, 'Dev',  '1999-02-18', 'undisclosed'),
    (4, 'Dev',  '1991-11-30', 'undisclosed'),
    (5, 'Dev',  '2000-06-21', 'undisclosed');

-- password_hash('devpassword', PASSWORD_DEFAULT)
INSERT INTO `user_auth_identities` (`user_id`, `provider`, `provider_subject`, `email`, `password_hash`) VALUES
    (1, 'email', 'dev_jij@example.invalid',  'dev_jij@example.invalid',
        '$2y$12$Qw3vYXqkq9dGm7hVYyO2XORY6hVwTmRr2j4kQ4bqWpQ8cYy1ZK5nq'),
    (2, 'email', 'dev_lisa@example.invalid', 'dev_lisa@example.invalid',
        '$2y$12$Qw3vYXqkq9dGm7hVYyO2XORY6hVwTmRr2j4kQ4bqWpQ8cYy1ZK5nq');

-- --- body measurements, with history ---------------------------------------
INSERT INTO `user_measurements` (`user_id`, `measurement_type`, `value`, `unit`, `measured_at`) VALUES
    (1, 'height', 182.000, 'cm', '2026-01-04 09:00:00'),
    (1, 'weight',  76.400, 'kg', '2026-01-04 09:00:00'),
    (1, 'weight',  75.100, 'kg', '2026-03-01 08:10:00'),
    (1, 'weight',  74.300, 'kg', '2026-04-10 08:05:00');

-- --- one night and one workout ---------------------------------------------
INSERT INTO `sleep_sessions`
    (`id`, `user_id`, `source_id`, `night_of`, `started_at`, `ended_at`, `duration_minutes`,
     `time_in_bed_minutes`, `efficiency_pct`, `awakenings`, `awake_minutes`,
     `light_minutes`, `deep_minutes`, `rem_minutes`)
VALUES
    (1, 1, (SELECT id FROM data_sources WHERE code = 'manual'), '2026-04-11',
     '2026-04-11 23:10:00', '2026-04-12 06:42:00', 444, 485, 91.55, 2, 14, 231, 98, 101);

INSERT INTO `workouts`
    (`id`, `user_id`, `source_id`, `activity_type`, `started_at`, `ended_at`,
     `duration_seconds`, `distance_m`, `active_kcal`, `avg_hr`, `max_hr`)
VALUES
    (1, 1, (SELECT id FROM data_sources WHERE code = 'manual'), 'running',
     '2026-04-12 18:05:00', '2026-04-12 18:53:00', 2880, 7100.00, 612, 138, 171);

INSERT INTO `workout_hr_zones` (`workout_id`, `zone`, `seconds`) VALUES
    (1, 2, 1450), (1, 3, 1120), (1, 4, 310);

-- --- a few readings, including one hanging off the night --------------------
INSERT INTO `health_metrics` (`user_id`, `metric_type_id`, `source_id`, `value`, `recorded_at`, `sleep_session_id`)
VALUES
    (1, (SELECT id FROM health_metric_types WHERE code = 'hrv'),
        (SELECT id FROM data_sources WHERE code = 'wearable'), 58.0, '2026-04-12 04:10:00', 1),
    (1, (SELECT id FROM health_metric_types WHERE code = 'sleeping_hr'),
        (SELECT id FROM data_sources WHERE code = 'wearable'), 52.0, '2026-04-12 04:10:00', 1);

INSERT INTO `health_metrics` (`user_id`, `metric_type_id`, `source_id`, `value`, `recorded_at`)
VALUES
    (1, (SELECT id FROM health_metric_types WHERE code = 'steps'),
        (SELECT id FROM data_sources WHERE code = 'manual'), 9420, '2026-04-12 21:00:00'),
    (1, (SELECT id FROM health_metric_types WHERE code = 'water'),
        (SELECT id FROM data_sources WHERE code = 'manual'), 1.8, '2026-04-12 20:00:00');

-- --- one goal ---------------------------------------------------------------
INSERT INTO `goals`
    (`id`, `user_id`, `name`, `category`, `goal_type`, `metric_type_id`,
     `target_value`, `target_unit`, `direction`, `start_date`, `end_date`)
VALUES
    (1, 1, 'DEV — 10.000 stappen per dag', 'training', 'target_value',
     (SELECT id FROM health_metric_types WHERE code = 'steps'),
     10000, 'stappen', 'increase', '2026-04-01', '2026-04-30');

-- --- friendships: 1 is friends with 2 and 3, asked 4, blocked 5 -------------
INSERT INTO `friendships` (`user_low_id`, `user_high_id`, `requested_by`, `status`, `responded_at`) VALUES
    (1, 2, 1, 'accepted', '2026-01-07 10:00:00'),
    (1, 3, 3, 'accepted', '2026-01-10 10:00:00'),
    (1, 4, 1, 'pending',  NULL);

INSERT INTO `user_blocks` (`blocker_id`, `blocked_id`) VALUES (1, 5);

-- --- point ledger -----------------------------------------------------------
-- Amounts are arbitrary: no scoring rule exists yet, so point_rules stays
-- empty and these events reference nothing.
INSERT INTO `point_events` (`user_id`, `points`, `awarded_at`, `reference_type`, `note`) VALUES
    (1, 1250, '2026-04-05 20:00:00', 'manual', 'DEV seed'),
    (2, 5320, '2026-04-05 20:00:00', 'manual', 'DEV seed'),
    (3, 5280, '2026-04-05 20:00:00', 'manual', 'DEV seed'),
    (4, 4900, '2026-04-05 20:00:00', 'manual', 'DEV seed'),
    (5, 4100, '2026-04-05 20:00:00', 'manual', 'DEV seed');

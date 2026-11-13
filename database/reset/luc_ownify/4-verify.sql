-- ============================================================================
--  Ownify user-data reset — luc_ownify — step 4: verification (reads only, changes nothing)
-- ----------------------------------------------------------------------------
--  For the live Ownify database on Hestia (ownify.acits.nl) ONLY. The first statement stops the import in any
--  other database (the test databases g7 and ownify_e2e included).
--  phpMyAdmin: select `luc_ownify` in the sidebar FIRST, then Import this file.
--  Instructions: docs/RESET.md. NO `USE` statement and no DROP DATABASE.
-- ============================================================================

-- 0. The right database, or nothing happens.
SET @stmt := IF(DATABASE() = 'luc_ownify', 'SELECT 1 AS ok', 'SELECT `STOP_this_is_not_luc_ownify`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Every table is one this reset knows: an unknown one stops it (see step 1's list).
SET @unknown := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME NOT IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources', 'account_email_conflicts', 'account_emails', 'ai_conversations', 'ai_messages', 'ai_usage', 'auth_attempts', 'auth_email_tokens', 'challenge_members', 'challenges', 'correlation_runs', 'daily_scores', 'device_pairing_codes', 'email_change_google_baseline', 'email_changes', 'friendships', 'goal_day_results', 'goal_entries', 'goal_period_results', 'goal_progress', 'goals', 'health_import_removed', 'health_metric_day_totals', 'health_metrics', 'heart_rate_minutes', 'insight_days', 'integration_oauth_states', 'leaderboard_best_positions', 'mail_outbox', 'nutrition_entries', 'pending_registrations', 'point_events', 'sleep_stages', 'sleep_sessions', 'user_auth_identities', 'user_blocks', 'user_correlations', 'user_devices', 'user_insights', 'user_integrations', 'user_login_tokens', 'user_measurements', 'user_period_points', 'user_profiles', 'workout_hr_zones', 'workouts', 'users', 'goals_backup_024', 'goal_progress_backup_024'));
SET @stmt := IF(@unknown = 0, 'SELECT 1 AS ok', 'SELECT `STOP_unknown_tables_run_step_1_and_send_the_list`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Every table there.
SET @have := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources', 'account_email_conflicts', 'account_emails', 'ai_conversations', 'ai_messages', 'ai_usage', 'auth_attempts', 'auth_email_tokens', 'challenge_members', 'challenges', 'correlation_runs', 'daily_scores', 'device_pairing_codes', 'email_change_google_baseline', 'email_changes', 'friendships', 'goal_day_results', 'goal_entries', 'goal_period_results', 'goal_progress', 'goals', 'health_import_removed', 'health_metric_day_totals', 'health_metrics', 'heart_rate_minutes', 'insight_days', 'integration_oauth_states', 'leaderboard_best_positions', 'mail_outbox', 'nutrition_entries', 'pending_registrations', 'point_events', 'sleep_stages', 'sleep_sessions', 'user_auth_identities', 'user_blocks', 'user_correlations', 'user_devices', 'user_insights', 'user_integrations', 'user_login_tokens', 'user_measurements', 'user_period_points', 'user_profiles', 'workout_hr_zones', 'workouts', 'users'));
SET @stmt := IF(@have = 55, 'SELECT 1 AS ok', 'SELECT `STOP_tables_missing_see_step_1`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Every foreign key there (68 in database/schema.sql).
SET @fks := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE());
SET @stmt := IF(@fks = 68, 'SELECT 1 AS ok', 'SELECT `STOP_foreign_keys_differ_from_schema`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Every personal table empty.
SET @left := 0;
SET @left := @left + (SELECT COUNT(*) FROM `account_email_conflicts`);
SET @left := @left + (SELECT COUNT(*) FROM `account_emails`);
SET @left := @left + (SELECT COUNT(*) FROM `ai_conversations`);
SET @left := @left + (SELECT COUNT(*) FROM `ai_messages`);
SET @left := @left + (SELECT COUNT(*) FROM `ai_usage`);
SET @left := @left + (SELECT COUNT(*) FROM `auth_attempts`);
SET @left := @left + (SELECT COUNT(*) FROM `auth_email_tokens`);
SET @left := @left + (SELECT COUNT(*) FROM `challenge_members`);
SET @left := @left + (SELECT COUNT(*) FROM `challenges`);
SET @left := @left + (SELECT COUNT(*) FROM `correlation_runs`);
SET @left := @left + (SELECT COUNT(*) FROM `daily_scores`);
SET @left := @left + (SELECT COUNT(*) FROM `device_pairing_codes`);
SET @left := @left + (SELECT COUNT(*) FROM `email_change_google_baseline`);
SET @left := @left + (SELECT COUNT(*) FROM `email_changes`);
SET @left := @left + (SELECT COUNT(*) FROM `friendships`);
SET @left := @left + (SELECT COUNT(*) FROM `goal_day_results`);
SET @left := @left + (SELECT COUNT(*) FROM `goal_entries`);
SET @left := @left + (SELECT COUNT(*) FROM `goal_period_results`);
SET @left := @left + (SELECT COUNT(*) FROM `goal_progress`);
SET @left := @left + (SELECT COUNT(*) FROM `goals`);
SET @left := @left + (SELECT COUNT(*) FROM `health_import_removed`);
SET @left := @left + (SELECT COUNT(*) FROM `health_metric_day_totals`);
SET @left := @left + (SELECT COUNT(*) FROM `health_metrics`);
SET @left := @left + (SELECT COUNT(*) FROM `heart_rate_minutes`);
SET @left := @left + (SELECT COUNT(*) FROM `insight_days`);
SET @left := @left + (SELECT COUNT(*) FROM `integration_oauth_states`);
SET @left := @left + (SELECT COUNT(*) FROM `leaderboard_best_positions`);
SET @left := @left + (SELECT COUNT(*) FROM `mail_outbox`);
SET @left := @left + (SELECT COUNT(*) FROM `nutrition_entries`);
SET @left := @left + (SELECT COUNT(*) FROM `pending_registrations`);
SET @left := @left + (SELECT COUNT(*) FROM `point_events`);
SET @left := @left + (SELECT COUNT(*) FROM `sleep_stages`);
SET @left := @left + (SELECT COUNT(*) FROM `sleep_sessions`);
SET @left := @left + (SELECT COUNT(*) FROM `user_auth_identities`);
SET @left := @left + (SELECT COUNT(*) FROM `user_blocks`);
SET @left := @left + (SELECT COUNT(*) FROM `user_correlations`);
SET @left := @left + (SELECT COUNT(*) FROM `user_devices`);
SET @left := @left + (SELECT COUNT(*) FROM `user_insights`);
SET @left := @left + (SELECT COUNT(*) FROM `user_integrations`);
SET @left := @left + (SELECT COUNT(*) FROM `user_login_tokens`);
SET @left := @left + (SELECT COUNT(*) FROM `user_measurements`);
SET @left := @left + (SELECT COUNT(*) FROM `user_period_points`);
SET @left := @left + (SELECT COUNT(*) FROM `user_profiles`);
SET @left := @left + (SELECT COUNT(*) FROM `workout_hr_zones`);
SET @left := @left + (SELECT COUNT(*) FROM `workouts`);
SET @left := @left + (SELECT COUNT(*) FROM `users`);
SET @stmt := IF(@left = 0, 'SELECT 1 AS ok', 'SELECT `STOP_personal_rows_left`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- What registration, goals, the score and the research need is there.
SET @stmt := IF((SELECT COUNT(*) FROM `data_sources`) > 0 AND (SELECT COUNT(*) FROM `goal_categories`) > 0 AND (SELECT COUNT(*) FROM `goal_measurements`) > 0 AND (SELECT COUNT(*) FROM `goal_units`) > 0 AND (SELECT COUNT(*) FROM `health_metric_types`) > 0 AND (SELECT COUNT(*) FROM `point_rules`) > 0 AND (SELECT COUNT(*) FROM `research_claims`) > 0 AND (SELECT COUNT(*) FROM `research_sources`) > 0, 'SELECT 1 AS ok', 'SELECT `STOP_global_data_missing`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- The schema is current.
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenges' AND COLUMN_NAME = 'metric' AND DATA_TYPE = 'varchar') AND EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_generation'), 'SELECT 1 AS ok', 'SELECT `STOP_schema_not_current_see_step_1`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Global data, for the record.
SET SESSION group_concat_max_len = 1000000;
SET @stmt := (SELECT GROUP_CONCAT(CONCAT('SELECT ''', TABLE_NAME, ''' AS `table`, COUNT(*) AS `rows` FROM `', TABLE_NAME, '`')
                ORDER BY TABLE_NAME SEPARATOR ' UNION ALL ')
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources'));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'verified: clean, complete and ready for new accounts' AS result,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users') AS next_user_id;

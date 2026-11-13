-- ============================================================================
--  Ownify user-data reset — ownify — step 1: precheck (reads only, changes nothing)
-- ----------------------------------------------------------------------------
--  For the primary Ownify database on the WAMP machine ONLY. The first statement stops the import in any
--  other database (the test databases g7 and ownify_e2e included).
--  phpMyAdmin: select `ownify` in the sidebar FIRST, then Import this file.
--  Instructions: docs/RESET.md. NO `USE` statement and no DROP DATABASE.
-- ============================================================================

-- 0. The right database, or nothing happens.
SET @stmt := IF(DATABASE() = 'ownify', 'SELECT 1 AS ok', 'SELECT `STOP_this_is_not_ownify`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Which database, on which server.
SELECT DATABASE() AS `database`, VERSION() AS server_version, NOW() AS checked_at;

-- Migration status, read from what each migration leaves behind.
SELECT '024' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices' AND COLUMN_NAME = 'custom_label'), 'imported', 'MISSING') AS status
UNION ALL SELECT '025' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals' AND COLUMN_NAME = 'category_code'), 'imported', 'MISSING') AS status
UNION ALL SELECT '026' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'rating_notice_hour'), 'imported', 'MISSING') AS status
UNION ALL SELECT '027' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_generation'), 'imported', 'MISSING') AS status
UNION ALL SELECT '028' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'research_sources'), 'imported', 'MISSING') AS status
UNION ALL SELECT '029' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correlation_runs'), 'imported', 'MISSING') AS status
UNION ALL SELECT '030' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_import_removed'), 'imported', 'MISSING') AS status
UNION ALL SELECT '031' AS migration, IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenges' AND COLUMN_NAME = 'metric' AND DATA_TYPE = 'varchar'), 'imported', 'MISSING') AS status
UNION ALL SELECT '032', IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('goals_backup_024', 'goal_progress_backup_024')) = 0, 'imported (or never needed)', 'not yet: step 2');

-- Tables this reset does not know (must be none; send the list if there are any).
SELECT TABLE_NAME AS unknown_table, TABLE_ROWS AS approx_rows FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources', 'account_email_conflicts', 'account_emails', 'ai_conversations', 'ai_messages', 'ai_usage', 'auth_attempts', 'auth_email_tokens', 'challenge_members', 'challenges', 'correlation_runs', 'daily_scores', 'device_pairing_codes', 'email_change_google_baseline', 'email_changes', 'friendships', 'goal_day_results', 'goal_entries', 'goal_period_results', 'goal_progress', 'goals', 'health_import_removed', 'health_metric_day_totals', 'health_metrics', 'heart_rate_minutes', 'insight_days', 'integration_oauth_states', 'leaderboard_best_positions', 'mail_outbox', 'nutrition_entries', 'pending_registrations', 'point_events', 'sleep_stages', 'sleep_sessions', 'user_auth_identities', 'user_blocks', 'user_correlations', 'user_devices', 'user_insights', 'user_integrations', 'user_login_tokens', 'user_measurements', 'user_period_points', 'user_profiles', 'workout_hr_zones', 'workouts', 'users', 'goals_backup_024', 'goal_progress_backup_024');

-- Tables this reset expects that are missing (must be none before step 3's last check).
SELECT t.name AS missing_table FROM (SELECT 'ai_service_state' AS name UNION ALL SELECT 'data_sources' AS name UNION ALL SELECT 'goal_categories' AS name UNION ALL SELECT 'goal_measurements' AS name UNION ALL SELECT 'goal_units' AS name UNION ALL SELECT 'health_metric_types' AS name UNION ALL SELECT 'point_rules' AS name UNION ALL SELECT 'research_claims' AS name UNION ALL SELECT 'research_sources' AS name UNION ALL SELECT 'account_email_conflicts' AS name UNION ALL SELECT 'account_emails' AS name UNION ALL SELECT 'ai_conversations' AS name UNION ALL SELECT 'ai_messages' AS name UNION ALL SELECT 'ai_usage' AS name UNION ALL SELECT 'auth_attempts' AS name UNION ALL SELECT 'auth_email_tokens' AS name UNION ALL SELECT 'challenge_members' AS name UNION ALL SELECT 'challenges' AS name UNION ALL SELECT 'correlation_runs' AS name UNION ALL SELECT 'daily_scores' AS name UNION ALL SELECT 'device_pairing_codes' AS name UNION ALL SELECT 'email_change_google_baseline' AS name UNION ALL SELECT 'email_changes' AS name UNION ALL SELECT 'friendships' AS name UNION ALL SELECT 'goal_day_results' AS name UNION ALL SELECT 'goal_entries' AS name UNION ALL SELECT 'goal_period_results' AS name UNION ALL SELECT 'goal_progress' AS name UNION ALL SELECT 'goals' AS name UNION ALL SELECT 'health_import_removed' AS name UNION ALL SELECT 'health_metric_day_totals' AS name UNION ALL SELECT 'health_metrics' AS name UNION ALL SELECT 'heart_rate_minutes' AS name UNION ALL SELECT 'insight_days' AS name UNION ALL SELECT 'integration_oauth_states' AS name UNION ALL SELECT 'leaderboard_best_positions' AS name UNION ALL SELECT 'mail_outbox' AS name UNION ALL SELECT 'nutrition_entries' AS name UNION ALL SELECT 'pending_registrations' AS name UNION ALL SELECT 'point_events' AS name UNION ALL SELECT 'sleep_stages' AS name UNION ALL SELECT 'sleep_sessions' AS name UNION ALL SELECT 'user_auth_identities' AS name UNION ALL SELECT 'user_blocks' AS name UNION ALL SELECT 'user_correlations' AS name UNION ALL SELECT 'user_devices' AS name UNION ALL SELECT 'user_insights' AS name UNION ALL SELECT 'user_integrations' AS name UNION ALL SELECT 'user_login_tokens' AS name UNION ALL SELECT 'user_measurements' AS name UNION ALL SELECT 'user_period_points' AS name UNION ALL SELECT 'user_profiles' AS name UNION ALL SELECT 'workout_hr_zones' AS name UNION ALL SELECT 'workouts' AS name UNION ALL SELECT 'users' AS name) t
 WHERE t.name NOT IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE());

-- Personal data: emptied by step 3 (the two goal copies by step 2).
SET SESSION group_concat_max_len = 1000000;
SET @stmt := (SELECT GROUP_CONCAT(CONCAT('SELECT ''', TABLE_NAME, ''' AS `table`, COUNT(*) AS `rows` FROM `', TABLE_NAME, '`')
                ORDER BY TABLE_NAME SEPARATOR ' UNION ALL ')
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('account_email_conflicts', 'account_emails', 'ai_conversations', 'ai_messages', 'ai_usage', 'auth_attempts', 'auth_email_tokens', 'challenge_members', 'challenges', 'correlation_runs', 'daily_scores', 'device_pairing_codes', 'email_change_google_baseline', 'email_changes', 'friendships', 'goal_day_results', 'goal_entries', 'goal_period_results', 'goal_progress', 'goals', 'health_import_removed', 'health_metric_day_totals', 'health_metrics', 'heart_rate_minutes', 'insight_days', 'integration_oauth_states', 'leaderboard_best_positions', 'mail_outbox', 'nutrition_entries', 'pending_registrations', 'point_events', 'sleep_stages', 'sleep_sessions', 'user_auth_identities', 'user_blocks', 'user_correlations', 'user_devices', 'user_insights', 'user_integrations', 'user_login_tokens', 'user_measurements', 'user_period_points', 'user_profiles', 'workout_hr_zones', 'workouts', 'users', 'goals_backup_024', 'goal_progress_backup_024'));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Global data: kept as it is.
SET SESSION group_concat_max_len = 1000000;
SET @stmt := (SELECT GROUP_CONCAT(CONCAT('SELECT ''', TABLE_NAME, ''' AS `table`, COUNT(*) AS `rows` FROM `', TABLE_NAME, '`')
                ORDER BY TABLE_NAME SEPARATOR ' UNION ALL ')
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources'));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- The next account number (kept by the reset, so no new account reuses an old one).
SELECT AUTO_INCREMENT AS next_user_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users';

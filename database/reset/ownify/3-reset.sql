-- ============================================================================
--  Ownify user-data reset — ownify — step 3: the reset — deletes every account and its data
-- ----------------------------------------------------------------------------
--  For the primary Ownify database on the WAMP machine ONLY. The first statement stops the import in any
--  other database (the test databases g7 and ownify_e2e included).
--  phpMyAdmin: select `ownify` in the sidebar FIRST, then Import this file.
--  Instructions: docs/RESET.md. NO `USE` statement and no DROP DATABASE.
-- ============================================================================

-- 0. The right database, or nothing happens.
SET @stmt := IF(DATABASE() = 'ownify', 'SELECT 1 AS ok', 'SELECT `STOP_this_is_not_ownify`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Every table is one this reset knows: an unknown one stops it (see step 1's list).
SET @unknown := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME NOT IN ('ai_service_state', 'data_sources', 'goal_categories', 'goal_measurements', 'goal_units', 'health_metric_types', 'point_rules', 'research_claims', 'research_sources', 'account_email_conflicts', 'account_emails', 'ai_conversations', 'ai_messages', 'ai_usage', 'auth_attempts', 'auth_email_tokens', 'challenge_members', 'challenges', 'correlation_runs', 'daily_scores', 'device_pairing_codes', 'email_change_google_baseline', 'email_changes', 'friendships', 'goal_day_results', 'goal_entries', 'goal_period_results', 'goal_progress', 'goals', 'health_import_removed', 'health_metric_day_totals', 'health_metrics', 'heart_rate_minutes', 'insight_days', 'integration_oauth_states', 'leaderboard_best_positions', 'mail_outbox', 'nutrition_entries', 'pending_registrations', 'point_events', 'sleep_stages', 'sleep_sessions', 'user_auth_identities', 'user_blocks', 'user_correlations', 'user_devices', 'user_insights', 'user_integrations', 'user_login_tokens', 'user_measurements', 'user_period_points', 'user_profiles', 'workout_hr_zones', 'workouts', 'users', 'goals_backup_024', 'goal_progress_backup_024'));
SET @stmt := IF(@unknown = 0, 'SELECT 1 AS ok', 'SELECT `STOP_unknown_tables_run_step_1_and_send_the_list`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Step 2 first: the two old goal copies are gone.
SET @copies := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('goals_backup_024', 'goal_progress_backup_024'));
SET @stmt := IF(@copies = 0, 'SELECT 1 AS ok', 'SELECT `STOP_import_step_2_migration_032_first`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- The global rows now, to prove afterwards that not one changed.
SET @g_ai_service_state := (SELECT COUNT(*) FROM `ai_service_state`);
SET @g_data_sources := (SELECT COUNT(*) FROM `data_sources`);
SET @g_goal_categories := (SELECT COUNT(*) FROM `goal_categories`);
SET @g_goal_measurements := (SELECT COUNT(*) FROM `goal_measurements`);
SET @g_goal_units := (SELECT COUNT(*) FROM `goal_units`);
SET @g_health_metric_types := (SELECT COUNT(*) FROM `health_metric_types`);
SET @g_point_rules := (SELECT COUNT(*) FROM `point_rules`);
SET @g_research_claims := (SELECT COUNT(*) FROM `research_claims`);
SET @g_research_sources := (SELECT COUNT(*) FROM `research_sources`);
SET @next_user := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users');

-- One transaction: everything goes, or nothing does. Foreign keys are
-- off for this session only, so the order cannot stop it; every personal
-- table is emptied whole, so nothing can be left pointing at nothing.
SET @old_fk := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_email_conflicts'), 'DELETE FROM `account_email_conflicts`', 'SELECT ''account_email_conflicts: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_emails'), 'DELETE FROM `account_emails`', 'SELECT ''account_emails: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations'), 'DELETE FROM `ai_conversations`', 'SELECT ''ai_conversations: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_messages'), 'DELETE FROM `ai_messages`', 'SELECT ''ai_messages: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage'), 'DELETE FROM `ai_usage`', 'SELECT ''ai_usage: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_attempts'), 'DELETE FROM `auth_attempts`', 'SELECT ''auth_attempts: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_email_tokens'), 'DELETE FROM `auth_email_tokens`', 'SELECT ''auth_email_tokens: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenge_members'), 'DELETE FROM `challenge_members`', 'SELECT ''challenge_members: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenges'), 'DELETE FROM `challenges`', 'SELECT ''challenges: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correlation_runs'), 'DELETE FROM `correlation_runs`', 'SELECT ''correlation_runs: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_scores'), 'DELETE FROM `daily_scores`', 'SELECT ''daily_scores: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_pairing_codes'), 'DELETE FROM `device_pairing_codes`', 'SELECT ''device_pairing_codes: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_change_google_baseline'), 'DELETE FROM `email_change_google_baseline`', 'SELECT ''email_change_google_baseline: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_changes'), 'DELETE FROM `email_changes`', 'SELECT ''email_changes: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friendships'), 'DELETE FROM `friendships`', 'SELECT ''friendships: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_day_results'), 'DELETE FROM `goal_day_results`', 'SELECT ''goal_day_results: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_entries'), 'DELETE FROM `goal_entries`', 'SELECT ''goal_entries: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_period_results'), 'DELETE FROM `goal_period_results`', 'SELECT ''goal_period_results: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_progress'), 'DELETE FROM `goal_progress`', 'SELECT ''goal_progress: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals'), 'DELETE FROM `goals`', 'SELECT ''goals: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_import_removed'), 'DELETE FROM `health_import_removed`', 'SELECT ''health_import_removed: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_metric_day_totals'), 'DELETE FROM `health_metric_day_totals`', 'SELECT ''health_metric_day_totals: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_metrics'), 'DELETE FROM `health_metrics`', 'SELECT ''health_metrics: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'heart_rate_minutes'), 'DELETE FROM `heart_rate_minutes`', 'SELECT ''heart_rate_minutes: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insight_days'), 'DELETE FROM `insight_days`', 'SELECT ''insight_days: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_oauth_states'), 'DELETE FROM `integration_oauth_states`', 'SELECT ''integration_oauth_states: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leaderboard_best_positions'), 'DELETE FROM `leaderboard_best_positions`', 'SELECT ''leaderboard_best_positions: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_outbox'), 'DELETE FROM `mail_outbox`', 'SELECT ''mail_outbox: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nutrition_entries'), 'DELETE FROM `nutrition_entries`', 'SELECT ''nutrition_entries: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pending_registrations'), 'DELETE FROM `pending_registrations`', 'SELECT ''pending_registrations: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_events'), 'DELETE FROM `point_events`', 'SELECT ''point_events: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_stages'), 'DELETE FROM `sleep_stages`', 'SELECT ''sleep_stages: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_sessions'), 'DELETE FROM `sleep_sessions`', 'SELECT ''sleep_sessions: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_auth_identities'), 'DELETE FROM `user_auth_identities`', 'SELECT ''user_auth_identities: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_blocks'), 'DELETE FROM `user_blocks`', 'SELECT ''user_blocks: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_correlations'), 'DELETE FROM `user_correlations`', 'SELECT ''user_correlations: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices'), 'DELETE FROM `user_devices`', 'SELECT ''user_devices: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_insights'), 'DELETE FROM `user_insights`', 'SELECT ''user_insights: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_integrations'), 'DELETE FROM `user_integrations`', 'SELECT ''user_integrations: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_login_tokens'), 'DELETE FROM `user_login_tokens`', 'SELECT ''user_login_tokens: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_measurements'), 'DELETE FROM `user_measurements`', 'SELECT ''user_measurements: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_period_points'), 'DELETE FROM `user_period_points`', 'SELECT ''user_period_points: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'), 'DELETE FROM `user_profiles`', 'SELECT ''user_profiles: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workout_hr_zones'), 'DELETE FROM `workout_hr_zones`', 'SELECT ''workout_hr_zones: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts'), 'DELETE FROM `workouts`', 'SELECT ''workouts: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'), 'DELETE FROM `users`', 'SELECT ''users: not there'' AS note');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Checked before it is kept: every personal table empty, every global count unchanged.
SET @left := 0;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_email_conflicts'), 'SET @left := @left + (SELECT COUNT(*) FROM `account_email_conflicts`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_emails'), 'SET @left := @left + (SELECT COUNT(*) FROM `account_emails`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations'), 'SET @left := @left + (SELECT COUNT(*) FROM `ai_conversations`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_messages'), 'SET @left := @left + (SELECT COUNT(*) FROM `ai_messages`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage'), 'SET @left := @left + (SELECT COUNT(*) FROM `ai_usage`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_attempts'), 'SET @left := @left + (SELECT COUNT(*) FROM `auth_attempts`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_email_tokens'), 'SET @left := @left + (SELECT COUNT(*) FROM `auth_email_tokens`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenge_members'), 'SET @left := @left + (SELECT COUNT(*) FROM `challenge_members`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'challenges'), 'SET @left := @left + (SELECT COUNT(*) FROM `challenges`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correlation_runs'), 'SET @left := @left + (SELECT COUNT(*) FROM `correlation_runs`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_scores'), 'SET @left := @left + (SELECT COUNT(*) FROM `daily_scores`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_pairing_codes'), 'SET @left := @left + (SELECT COUNT(*) FROM `device_pairing_codes`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_change_google_baseline'), 'SET @left := @left + (SELECT COUNT(*) FROM `email_change_google_baseline`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_changes'), 'SET @left := @left + (SELECT COUNT(*) FROM `email_changes`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friendships'), 'SET @left := @left + (SELECT COUNT(*) FROM `friendships`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_day_results'), 'SET @left := @left + (SELECT COUNT(*) FROM `goal_day_results`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_entries'), 'SET @left := @left + (SELECT COUNT(*) FROM `goal_entries`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_period_results'), 'SET @left := @left + (SELECT COUNT(*) FROM `goal_period_results`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goal_progress'), 'SET @left := @left + (SELECT COUNT(*) FROM `goal_progress`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals'), 'SET @left := @left + (SELECT COUNT(*) FROM `goals`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_import_removed'), 'SET @left := @left + (SELECT COUNT(*) FROM `health_import_removed`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_metric_day_totals'), 'SET @left := @left + (SELECT COUNT(*) FROM `health_metric_day_totals`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'health_metrics'), 'SET @left := @left + (SELECT COUNT(*) FROM `health_metrics`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'heart_rate_minutes'), 'SET @left := @left + (SELECT COUNT(*) FROM `heart_rate_minutes`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insight_days'), 'SET @left := @left + (SELECT COUNT(*) FROM `insight_days`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_oauth_states'), 'SET @left := @left + (SELECT COUNT(*) FROM `integration_oauth_states`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leaderboard_best_positions'), 'SET @left := @left + (SELECT COUNT(*) FROM `leaderboard_best_positions`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_outbox'), 'SET @left := @left + (SELECT COUNT(*) FROM `mail_outbox`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nutrition_entries'), 'SET @left := @left + (SELECT COUNT(*) FROM `nutrition_entries`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pending_registrations'), 'SET @left := @left + (SELECT COUNT(*) FROM `pending_registrations`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_events'), 'SET @left := @left + (SELECT COUNT(*) FROM `point_events`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_stages'), 'SET @left := @left + (SELECT COUNT(*) FROM `sleep_stages`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sleep_sessions'), 'SET @left := @left + (SELECT COUNT(*) FROM `sleep_sessions`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_auth_identities'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_auth_identities`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_blocks'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_blocks`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_correlations'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_correlations`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_devices`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_insights'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_insights`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_integrations'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_integrations`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_login_tokens'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_login_tokens`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_measurements'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_measurements`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_period_points'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_period_points`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'), 'SET @left := @left + (SELECT COUNT(*) FROM `user_profiles`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workout_hr_zones'), 'SET @left := @left + (SELECT COUNT(*) FROM `workout_hr_zones`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts'), 'SET @left := @left + (SELECT COUNT(*) FROM `workouts`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'), 'SET @left := @left + (SELECT COUNT(*) FROM `users`)', 'DO 0');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(@left = 0, 'SELECT 1 AS ok', 'SELECT `STOP_rows_left_nothing_was_kept`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt := IF(@g_ai_service_state = (SELECT COUNT(*) FROM `ai_service_state`) AND @g_data_sources = (SELECT COUNT(*) FROM `data_sources`) AND @g_goal_categories = (SELECT COUNT(*) FROM `goal_categories`) AND @g_goal_measurements = (SELECT COUNT(*) FROM `goal_measurements`) AND @g_goal_units = (SELECT COUNT(*) FROM `goal_units`) AND @g_health_metric_types = (SELECT COUNT(*) FROM `health_metric_types`) AND @g_point_rules = (SELECT COUNT(*) FROM `point_rules`) AND @g_research_claims = (SELECT COUNT(*) FROM `research_claims`) AND @g_research_sources = (SELECT COUNT(*) FROM `research_sources`), 'SELECT 1 AS ok', 'SELECT `STOP_global_rows_changed_nothing_was_kept`');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

COMMIT;
SET FOREIGN_KEY_CHECKS = @old_fk;

SELECT 'reset done' AS result, @left AS personal_rows_left,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users') AS next_user_id,
       @next_user AS next_user_id_before;

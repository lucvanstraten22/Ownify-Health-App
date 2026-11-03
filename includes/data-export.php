<?php
/**
 * Je gegevens (Instellingen › Privacy): downloading everything Ownify keeps
 * about an account, and wiping its health data while the account stays.
 *
 *   data_export_stream()  every row that is the account's, as one JSON file,
 *                         written in pieces so a long heart-rate history
 *                         never has to fit in memory at once
 *   data_wipe()           the health data and everything worked out from it;
 *                         the account itself, its sign-in, profile, friends,
 *                         challenges, connections and OwnifyAI conversations
 *                         stay (those have their own buttons)
 *
 * Every statement carries the account's own id; nothing here ever takes an
 * id from a request. Secrets are never exported: no password hash, no
 * Google subject, no token or token hash, no connection keys.
 */
declare(strict_types=1);

require_once __DIR__ . '/leaderboard.php';

if (!function_exists('data_export_filename')) {
    /** The file's name: ownify-gegevens-2026-11-03.json. */
    function data_export_filename(): string
    {
        return 'ownify-' . strtolower(t('gegevens')) . '-' . date('Y-m-d') . '.json';
    }
}

if (!function_exists('data_export_sections')) {
    /**
     * The export's sections, in order: [name, SQL, chunked]. Every SQL takes
     * the account id as its first parameter. A chunked section is read in
     * pieces of DATA_EXPORT_CHUNK rows ordered by its `k` column (keyset),
     * so its whole history is never in memory at once.
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    function data_export_sections(): array
    {
        return [
            ['account', 'SELECT username, status, created_at, last_seen_at FROM users WHERE id = ?', null],
            ['profile', 'SELECT first_name, last_name, date_of_birth, gender, activity_level, avatar_path, locale, units, week_start,
                                text_large, contrast_more, motion_off, rating_notice_hour, allow_friend_requests, leaderboard_avatar,
                                ai_consent, ai_consent_at, focus, setup_state, setup_done_at, created_at, updated_at
                           FROM user_profiles WHERE user_id = ?', null],
            ['sign_in', 'SELECT provider, email, email_verified_at, created_at, last_login_at
                           FROM user_auth_identities WHERE user_id = ? ORDER BY id', null],
            ['email_addresses', 'SELECT email, via_password, via_google, verified_at FROM account_emails WHERE user_id = ?', null],
            ['measurements', 'SELECT m.measurement_type, m.value, m.unit, m.measured_at, s.code AS source
                                FROM user_measurements m LEFT JOIN data_sources s ON s.id = m.source_id
                               WHERE m.user_id = ? ORDER BY m.measured_at, m.id', null],
            ['sleep_sessions', 'SELECT z.id, z.night_of, z.started_at, z.ended_at, z.duration_minutes, z.time_in_bed_minutes,
                                       z.efficiency_pct, z.awakenings, z.awake_minutes, z.light_minutes, z.deep_minutes,
                                       z.rem_minutes, s.code AS source
                                  FROM sleep_sessions z LEFT JOIN data_sources s ON s.id = z.source_id
                                 WHERE z.user_id = ? ORDER BY z.started_at', null],
            ['sleep_stages', 'SELECT g.id AS k, g.sleep_session_id, g.stage, g.started_at, g.ended_at
                                FROM sleep_stages g JOIN sleep_sessions z ON z.id = g.sleep_session_id
                               WHERE z.user_id = ? AND g.id > ? ORDER BY g.id LIMIT ' . DATA_EXPORT_CHUNK, 'k'],
            ['workouts', 'SELECT w.id, w.activity_type, w.started_at, w.ended_at, w.duration_seconds, w.distance_m,
                                 w.active_kcal, w.total_kcal, w.avg_hr, w.max_hr, w.avg_speed_kmh, w.avg_cadence,
                                 w.elevation_gain_m, w.perceived_effort, w.notes, s.code AS source
                            FROM workouts w LEFT JOIN data_sources s ON s.id = w.source_id
                           WHERE w.user_id = ? ORDER BY w.started_at', null],
            ['workout_heart_rate_zones', 'SELECT h.workout_id, h.zone, h.seconds
                                            FROM workout_hr_zones h JOIN workouts w ON w.id = h.workout_id
                                           WHERE w.user_id = ? ORDER BY h.workout_id, h.zone', null],
            ['nutrition_entries', 'SELECT n.id, n.meal_type, n.label, n.consumed_at, n.notes, s.code AS source
                                     FROM nutrition_entries n LEFT JOIN data_sources s ON s.id = n.source_id
                                    WHERE n.user_id = ? ORDER BY n.consumed_at', null],
            ['health_metrics', 'SELECT h.id AS k, t.code AS metric, t.unit, h.value, h.started_at, h.recorded_at,
                                       h.data_origin, s.code AS source, h.sleep_session_id, h.workout_id, h.nutrition_entry_id
                                  FROM health_metrics h
                                  JOIN health_metric_types t ON t.id = h.metric_type_id
                             LEFT JOIN data_sources s ON s.id = h.source_id
                                 WHERE h.user_id = ? AND h.id > ? ORDER BY h.id LIMIT ' . DATA_EXPORT_CHUNK, 'k'],
            ['heart_rate_minutes', 'SELECT minute_at AS k, data_origin, bpm, samples
                                      FROM heart_rate_minutes
                                     WHERE user_id = ? AND minute_at > ? ORDER BY minute_at, data_origin LIMIT ' . DATA_EXPORT_CHUNK, 'k'],
            ['daily_scores', 'SELECT score_date, domain, score, data_days, algorithm_version, computed_at
                                FROM daily_scores WHERE user_id = ? ORDER BY score_date, domain', null],
            ['goals', 'SELECT id, name, category_code, measurement_code, goal_type, streak_variant, tracking, unit_code,
                              custom_unit, direction, target_value, frequency, period_unit, baseline_value, baseline_on,
                              start_date, end_date, status, priority, current_value, progress_pct, final_pct,
                              completed_at, created_at, updated_at
                         FROM goals WHERE user_id = ? ORDER BY id', null],
            ['goal_entries', 'SELECT e.goal_id, e.entry_date, e.value, e.created_at
                                FROM goal_entries e JOIN goals g ON g.id = e.goal_id
                               WHERE g.user_id = ? ORDER BY e.goal_id, e.entry_date', null],
            ['goal_days', 'SELECT r.goal_id, r.day, r.value, r.state
                             FROM goal_day_results r JOIN goals g ON g.id = r.goal_id
                            WHERE g.user_id = ? ORDER BY r.goal_id, r.day', null],
            ['goal_periods', 'SELECT r.goal_id, r.period_index, r.starts_on, r.ends_on, r.required, r.achieved, r.state
                                FROM goal_period_results r JOIN goals g ON g.id = r.goal_id
                               WHERE g.user_id = ? ORDER BY r.goal_id, r.period_index', null],
            ['points', 'SELECT p.points, p.awarded_on, p.awarded_at, r.code AS rule, p.note
                          FROM point_events p LEFT JOIN point_rules r ON r.id = p.rule_id
                         WHERE p.user_id = ? ORDER BY p.awarded_at, p.id', null],
            ['leaderboard_totals', 'SELECT period_type, period_key, points, position FROM user_period_points
                                     WHERE user_id = ? ORDER BY period_type, period_key', null],
            ['insights', 'SELECT local_date, slot, rule_key, outcome, strength, phrased_body, created_at, withdrawn_at
                            FROM user_insights WHERE user_id = ? ORDER BY local_date, slot', null],
            ['correlations', 'SELECT r.local_date, c.pair_code, c.tier, c.days, c.rho, c.p_value, c.q_value, c.effect
                                FROM user_correlations c JOIN correlation_runs r ON r.id = c.run_id
                               WHERE c.user_id = ? ORDER BY r.local_date, c.pair_code', null],
            ['friends', 'SELECT u.username, f.status, f.created_at, f.responded_at,
                                IF(f.requested_by = ?, 1, 0) AS requested_by_you
                           FROM friendships f
                           JOIN users u ON u.id = IF(f.user_low_id = ?, f.user_high_id, f.user_low_id)
                          WHERE ? IN (f.user_low_id, f.user_high_id) ORDER BY f.created_at', null],
            ['challenges', 'SELECT c.name, c.kind, c.metric, c.target, c.start_date, c.end_date, m.status, m.joined_at,
                                   IF(c.creator_id = m.user_id, 1, 0) AS created_by_you
                              FROM challenge_members m JOIN challenges c ON c.id = m.challenge_id
                             WHERE m.user_id = ? ORDER BY c.start_date', null],
            ['connections', 'SELECT provider, status, external_account_label, connected_at, last_sync_at, last_sync_status
                               FROM user_integrations WHERE user_id = ? ORDER BY provider', null],
            ['devices', 'SELECT provider, scope, COALESCE(custom_label, label) AS name, platform, app_version,
                                created_at, last_seen_at, last_sync_at, revoked_at
                           FROM user_devices WHERE user_id = ? ORDER BY created_at', null],
            ['ownifyai_conversations', 'SELECT id, title, created_at, updated_at FROM ai_conversations
                                         WHERE user_id = ? ORDER BY created_at', null],
            ['ownifyai_messages', 'SELECT id AS k, conversation_id, role, content, created_at FROM ai_messages
                                    WHERE user_id = ? AND id > ? ORDER BY id LIMIT ' . DATA_EXPORT_CHUNK, 'k'],
        ];
    }
}

if (!defined('DATA_EXPORT_CHUNK')) {
    define('DATA_EXPORT_CHUNK', 5000);
}

if (!function_exists('data_export_stream')) {
    /**
     * Writes the whole export through $write, piece by piece:
     * { format, version, exported_at, <section>: [rows…], … }.
     * The account id appears where a statement needs it, never in the file.
     */
    function data_export_stream(int $userId, callable $write): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        $write('{"format":"ownify-export","version":1,"exported_at":' . json_encode(gmdate('c')));

        foreach (data_export_sections() as [$name, $sql, $chunked]) {
            $write(',' . json_encode($name) . ':[');
            $first = true;
            /* The statement's own number of ? before the cursor: the friends
               query names the account three times. */
            $ids = array_fill(0, max(1, substr_count($sql, '?') - ($chunked === null ? 0 : 1)), $userId);

            if ($chunked === null) {
                foreach (db_all($sql, $ids) as $row) {
                    $write(($first ? '' : ',') . json_encode($row, $flags));
                    $first = false;
                }
            } else {
                /* heart_rate_minutes is keyed by the minute, which several
                   origins can share: a page that ends inside a minute reads
                   the rest of that minute before it moves on. */
                $minutes = $name === 'heart_rate_minutes';
                $cursor  = $minutes ? '1000-01-01 00:00:00' : 0;
                while (true) {
                    $rows = db_all($sql, [...$ids, $cursor]);
                    foreach ($rows as $row) {
                        $cursor = $row['k'];
                        unset($row['k']);
                        if ($minutes) {
                            $row = ['minute_at' => $cursor] + $row;
                        }
                        $write(($first ? '' : ',') . json_encode($row, $flags));
                        $first = false;
                    }
                    if (count($rows) < DATA_EXPORT_CHUNK) {
                        break;
                    }
                    if ($minutes) {
                        $done = array_column(array_filter($rows, static fn ($r) => $r['k'] === $cursor), 'data_origin');
                        $rest = db_all('SELECT data_origin, bpm, samples FROM heart_rate_minutes
                                         WHERE user_id = ? AND minute_at = ? ORDER BY data_origin', [$userId, $cursor]);
                        foreach ($rest as $row) {
                            if (!in_array($row['data_origin'], $done, true)) {
                                $write(',' . json_encode(['minute_at' => $cursor] + $row, $flags));
                            }
                        }
                    }
                }
            }

            $write(']');
        }

        $write('}');
    }
}

if (!function_exists('data_wipe_tables')) {
    /**
     * What "Gegevens wissen" deletes, in this order — each by the account's
     * own user_id. Sleep stages, workout zones, the health_metrics of a
     * session and a goal's entries and results go with their rows (ON DELETE
     * CASCADE).
     */
    function data_wipe_tables(): array
    {
        return [
            'health_metrics', 'health_metric_day_totals', 'heart_rate_minutes',
            'sleep_sessions', 'workouts', 'nutrition_entries', 'user_measurements',
            'daily_scores', 'point_events', 'user_period_points', 'leaderboard_best_positions',
            'user_insights', 'insight_days', 'user_correlations', 'correlation_runs',
            'goals',
        ];
    }
}

if (!function_exists('data_wipe')) {
    /**
     * Wipes the account's health data and everything worked out from it, in
     * one transaction: all of it or nothing. The board periods the account
     * had points in are re-ranked after, as when an account is deleted, so
     * nobody below it is left a place short.
     *
     * @return array{ok: bool, deleted: int, error: ?string}
     */
    function data_wipe(int $userId): array
    {
        $pdo = db();
        if ($pdo === null) {
            return ['ok' => false, 'deleted' => 0, 'error' => t('Geen databaseverbinding.')];
        }

        $periods = db_all(
            "SELECT DISTINCT DATE_FORMAT(awarded_on, '%Y-%m') AS month, DATE_FORMAT(awarded_on, '%Y') AS year
               FROM point_events WHERE user_id = ?",
            [$userId]
        );

        /* What is counted: the person's own data, not what the app works
           out again from nothing (a day's empty score, an insight day). */
        $own     = ['health_metrics', 'heart_rate_minutes', 'sleep_sessions', 'workouts', 'nutrition_entries',
                    'user_measurements', 'point_events', 'goals'];
        $deleted = 0;

        try {
            $pdo->beginTransaction();
            foreach (data_wipe_tables() as $table) {
                $statement = db_run('DELETE FROM `' . $table . '` WHERE user_id = ?', [$userId]);
                if (in_array($table, $own, true)) {
                    $deleted += $statement === null ? 0 : $statement->rowCount();
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] data wipe failed for user ' . $userId . ': ' . $e->getMessage());

            return ['ok' => false, 'deleted' => 0, 'error' => t('Je gegevens konden niet worden gewist. Er is niets verwijderd.')];
        }

        if ($periods !== []) {
            foreach (array_unique(array_column($periods, 'month')) as $month) {
                leaderboard_rebuild('month', $month);
            }
            foreach (array_unique(array_column($periods, 'year')) as $year) {
                leaderboard_rebuild('year', $year);
            }
            leaderboard_rebuild('alltime', 'all');
        }

        return ['ok' => true, 'deleted' => $deleted, 'error' => null];
    }
}

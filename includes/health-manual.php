<?php
/**
 * Sleep and training entered by hand, and a training removed (40.0,
 * docs/MANUAL-ENTRY.md) — PRIVATE.
 *
 * Nothing here has calculations of its own. A night is a sleep_sessions row
 * and a training a workouts row like any other, written through the same
 * functions a watch's data goes through (includes/health-data.php), and
 * afterwards the same things run as after a sync: goals, points, the Health
 * Score and the day's insights. Everything that reads the data — the Slaap
 * and Training pages, their charts, the Verloop, the correlations — reads it
 * as it reads the rest.
 *
 *   A night       filed, as every night, under the morning it ended. The
 *                 date the person picks is that morning: a bedtime later in
 *                 the day than the wake-up time was the evening before
 *                 (23:30 → 07:00), an earlier one the same day (01:15 →
 *                 08:30, or a nap 14:00 → 14:40). A night already there for
 *                 that date keeps its row and its measurements (stages,
 *                 minutes per stage); only its bedtime and wake-up time and
 *                 what follows from them change, and corrected_at marks them
 *                 as the person's, so a sync keeps them (health-import.php).
 *   A training    the manual source, an activity type of Health Connect's
 *                 own names (config/manual-entry.php). One that overlaps a
 *                 training already recorded is refused, never merged.
 *   Heart rate    only what was recorded: heart_rate_minutes in the entered
 *                 period. A training gets its mean and highest, a night its
 *                 mean as 'sleeping_hr' (a health_metrics row on the night,
 *                 where the schema keeps what is measured during it). No
 *                 minutes, no heart rate — nothing is estimated.
 *   Removing      only Ownify's copy. A training from Health Connect or
 *                 Polar is remembered as removed (health_import_removed), so
 *                 the next sync, which sends the last days again, leaves it
 *                 out; it stays where it came from.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/goal-progress.php';
require_once __DIR__ . '/health-score.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/i18n.php';
if (is_file(__DIR__ . '/insights/notify.php')) {
    require_once __DIR__ . '/insights/notify.php';
}

if (!function_exists('health_manual_ready')) {

    /** Whether migration 030 is in: without it nothing here is offered. */
    function health_manual_ready(): bool
    {
        static $ready = null;

        return $ready ??= db_available()
            && (int) db_value(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'sleep_sessions' AND column_name = 'corrected_at'"
            ) > 0
            && (int) db_value(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'health_import_removed'"
            ) > 0;
    }

    /** config/manual-entry.php, in the reader's language. */
    function health_manual_config(): array
    {
        return config_copy('manual-entry');
    }

    /** The activity types a training can be entered as: Health Connect's names. */
    function health_manual_activity_types(): array
    {
        return array_keys((array) health_manual_config()['training']['types']);
    }

    /* ==================================================================
       PARSING WHAT WAS TYPED
       ================================================================== */

    /** A date as Y-m-d, or null. */
    function health_manual_date(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** A time of day as HH:MM, in minutes since midnight, or null. */
    function health_manual_clock(mixed $value): ?int
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * The night a bedtime and a wake-up time make on the morning $date: the
     * wake-up on that date, the bedtime the evening before when it is later
     * in the day than the wake-up, else that same day.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    function health_manual_night_span(DateTimeImmutable $date, int $bed, int $wake): array
    {
        $end   = $date->setTime(intdiv($wake, 60), $wake % 60);
        $start = $date->setTime(intdiv($bed, 60), $bed % 60);
        if ($bed >= $wake) {
            $start = $start->modify('-1 day');
        }

        return [$start, $end];
    }

    /* ==================================================================
       HEART RATE — only what was recorded
       ================================================================== */

    /**
     * The heart rate recorded from $from up to $to (heart_rate_minutes):
     * how many minutes, their mean and the highest minute. Null when nothing
     * was recorded then.
     *
     * @return array{minutes: int, avg: float, max: float}|null
     */
    function health_manual_heart(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        if (!health_heart_minutes_stored()) {
            return null;
        }

        $bpm = array_values(health_heart_minutes($userId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')));
        if ($bpm === []) {
            return null;
        }

        return ['minutes' => count($bpm), 'avg' => array_sum($bpm) / count($bpm), 'max' => max($bpm)];
    }

    /* ==================================================================
       A NIGHT
       ================================================================== */

    /**
     * Enters or corrects the night of the morning $date.
     *
     * @return array{ok: bool, error: ?string, session_id?: int, updated?: bool, night?: string,
     *               start?: string, end?: string, heart_minutes?: int}
     */
    function health_manual_sleep(int $userId, mixed $date, mixed $bedtime, mixed $wakeup, ?DateTimeImmutable $now = null): array
    {
        $copy = health_manual_config()['errors'];
        $now ??= new DateTimeImmutable();

        $day  = health_manual_date($date);
        $bed  = health_manual_clock($bedtime);
        $wake = health_manual_clock($wakeup);

        if ($day === null) {
            return ['ok' => false, 'error' => (string) $copy['date']];
        }
        if ($bed === null || $wake === null) {
            return ['ok' => false, 'error' => (string) $copy['time']];
        }
        if ($bed === $wake) {
            return ['ok' => false, 'error' => (string) $copy['same_time']];
        }

        [$start, $end] = health_manual_night_span($day, $bed, $wake);
        if ($end > $now) {
            return ['ok' => false, 'error' => (string) $copy['future']];
        }

        $night    = $day->format('Y-m-d');
        $interval = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        $existing = health_night_on($userId, $night);
        $row      = $existing === null ? null : db_one(
            'SELECT id, awake_minutes, efficiency_pct FROM sleep_sessions WHERE id = ? AND user_id = ?',
            [(int) $existing['session_id'], $userId]
        );

        /* Another of the person's sleeps already starting at this moment is
           the one unique key a correction can run into. */
        $clash = db_value(
            'SELECT id FROM sleep_sessions WHERE user_id = ? AND started_at = ? AND id <> ?',
            [$userId, $start->format('Y-m-d H:i:s'), $row === null ? 0 : (int) $row['id']]
        );
        if ($clash !== null) {
            return ['ok' => false, 'error' => (string) $copy['sleep_clash']];
        }

        if ($row !== null) {
            /* The night that was there keeps its row and its measurements;
               its times and what follows from them are the person's now.
               Asleep is the time in bed less the time awake the device
               measured; its efficiency follows where it had one. */
            $awake   = $row['awake_minutes'] === null ? null : (int) $row['awake_minutes'];
            $asleep  = max(1, $interval - min($awake ?? 0, $interval - 1));
            $efficiency = $row['efficiency_pct'] === null ? null : round($asleep / max(1, $interval) * 100, 2);

            db_run(
                'UPDATE sleep_sessions
                    SET night_of = ?, started_at = ?, ended_at = ?, duration_minutes = ?,
                        time_in_bed_minutes = ?, efficiency_pct = ?, corrected_at = NOW()
                  WHERE id = ? AND user_id = ?',
                [$night, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $asleep,
                 $interval, $efficiency, (int) $row['id'], $userId]
            );
            $sessionId = (int) $row['id'];
            $updated   = true;
        } else {
            $sessionId = health_record_sleep($userId, [
                'night_of'            => $night,
                'started_at'          => $start->format('Y-m-d H:i:s'),
                'ended_at'            => $end->format('Y-m-d H:i:s'),
                'time_in_bed_minutes' => $interval,
            ], 'manual');
            if ($sessionId === null) {
                return ['ok' => false, 'error' => (string) $copy['save']];
            }
            db_run('UPDATE sleep_sessions SET corrected_at = NOW() WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
            $updated = false;
        }

        /* The heart rate recorded while asleep, on the night, or none. */
        $heart = health_manual_heart($userId, $start, $end);
        health_manual_sleeping_heart($userId, $sessionId, $start, $end, $heart);

        health_manual_refresh($userId, ['nights' => [$night]], $night);

        return [
            'ok'            => true,
            'error'         => null,
            'session_id'    => $sessionId,
            'updated'       => $updated,
            'night'         => $night,
            'start'         => $start->format('Y-m-d H:i'),
            'end'           => $end->format('Y-m-d H:i'),
            'heart_minutes' => $heart['minutes'] ?? 0,
        ];
    }

    /**
     * The night's mean heart rate as a 'sleeping_hr' reading on it — worked
     * out by Ownify ('derived') from the recorded minutes, replaced when the
     * night is corrected again, and removed when no minutes fall in it any
     * more.
     */
    function health_manual_sleeping_heart(int $userId, int $sessionId, DateTimeImmutable $start, DateTimeImmutable $end, ?array $heart): void
    {
        $type   = health_metric_type_id('sleeping_hr');
        $source = health_source_id('derived');
        if ($type === null || $source === null) {
            return;
        }

        db_run('DELETE FROM health_metrics WHERE user_id = ? AND source_id = ? AND external_id = ?',
            [$userId, $source, 'sleep:' . $sessionId]);

        if ($heart === null) {
            return;
        }

        db_run(
            'INSERT INTO health_metrics
                (user_id, metric_type_id, source_id, external_id, value, started_at, recorded_at, sleep_session_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $type, $source, 'sleep:' . $sessionId, round($heart['avg'], 1),
             $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $sessionId]
        );
    }

    /* ==================================================================
       A TRAINING
       ================================================================== */

    /**
     * Enters a training.
     *
     * @return array{ok: bool, error: ?string, workout_id?: int, heart_minutes?: int}
     */
    function health_manual_workout(int $userId, array $input, ?DateTimeImmutable $now = null): array
    {
        $copy = health_manual_config()['errors'];
        $now ??= new DateTimeImmutable();

        $day   = health_manual_date($input['date'] ?? '');
        $at    = health_manual_clock($input['start'] ?? '');
        $type  = trim((string) ($input['type'] ?? ''));
        $mins  = filter_var($input['minutes'] ?? null, FILTER_VALIDATE_INT);
        $kcal  = trim((string) ($input['kcal'] ?? ''));

        if ($day === null) {
            return ['ok' => false, 'error' => (string) $copy['date']];
        }
        if ($at === null) {
            return ['ok' => false, 'error' => (string) $copy['time']];
        }
        if (!in_array($type, health_manual_activity_types(), true)) {
            return ['ok' => false, 'error' => (string) $copy['type']];
        }
        if ($mins === false || $mins < 1 || $mins > 1440) {
            return ['ok' => false, 'error' => (string) $copy['duration']];
        }

        $energy = null;
        if ($kcal !== '') {
            $energy = filter_var($kcal, FILTER_VALIDATE_INT);
            if ($energy === false || $energy < 0 || $energy > 20000) {
                return ['ok' => false, 'error' => (string) $copy['kcal']];
            }
        }

        $start = $day->setTime(intdiv($at, 60), $at % 60);
        $end   = $start->modify('+' . $mins . ' minutes');
        if ($start > $now) {
            return ['ok' => false, 'error' => (string) $copy['future']];
        }

        /* Any training recorded in this time, from anywhere: refused, never
           merged or overwritten. */
        $overlap = db_value(
            'SELECT id FROM workouts
              WHERE user_id = ? AND started_at < ?
                AND COALESCE(ended_at, DATE_ADD(started_at, INTERVAL COALESCE(duration_seconds, 0) SECOND)) > ?
              LIMIT 1',
            [$userId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]
        );
        $same = db_value('SELECT id FROM workouts WHERE user_id = ? AND started_at = ?', [$userId, $start->format('Y-m-d H:i:s')]);
        if ($overlap !== null || $same !== null) {
            return ['ok' => false, 'error' => (string) $copy['overlap'], 'code' => 'overlap'];
        }

        $heart = health_manual_heart($userId, $start, $end);

        $workoutId = health_record_workout($userId, [
            'activity_type'    => $type,
            'started_at'       => $start->format('Y-m-d H:i:s'),
            'ended_at'         => $end->format('Y-m-d H:i:s'),
            'duration_seconds' => $mins * 60,
            'active_kcal'      => $energy,
            'avg_hr'           => $heart === null ? null : (int) round($heart['avg']),
            'max_hr'           => $heart === null ? null : (int) round($heart['max']),
        ], 'manual');

        if ($workoutId === null) {
            return ['ok' => false, 'error' => (string) $copy['save']];
        }

        health_manual_refresh($userId, ['workouts' => [$workoutId]], $start->format('Y-m-d'));

        return ['ok' => true, 'error' => null, 'workout_id' => $workoutId, 'heart_minutes' => $heart['minutes'] ?? 0];
    }

    /**
     * Removes a training from Ownify — the person's own, by id — and works
     * out again what it counted towards.
     *
     * @return array{ok: bool, error: ?string, imported?: bool}
     */
    function health_manual_remove_workout(int $userId, int $workoutId): array
    {
        $copy = health_manual_config()['errors'];
        $row  = db_one(
            'SELECT w.id, w.started_at, w.external_id, w.source_id, s.code
               FROM workouts w LEFT JOIN data_sources s ON s.id = w.source_id
              WHERE w.id = ? AND w.user_id = ?',
            [$workoutId, $userId]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => (string) $copy['gone']];
        }

        $imported = $row['source_id'] !== null && $row['code'] !== 'manual' && (string) ($row['external_id'] ?? '') !== '';

        /* The other trainings of its week: the weekly bonus is worked out
           again from what is left. */
        $start = new DateTimeImmutable((string) $row['started_at']);
        $week  = points_week_start($userId, $start->getTimestamp());
        $from  = new DateTimeImmutable($week);
        $mates = array_map('intval', array_column(db_all(
            'SELECT id FROM workouts WHERE user_id = ? AND id <> ? AND started_at >= ? AND started_at < ?',
            [$userId, $workoutId, $from->format('Y-m-d H:i:s'), $from->modify('+7 days')->format('Y-m-d H:i:s')]
        ), 'id'));

        $pdo = db();
        try {
            $pdo?->beginTransaction();
            if ($imported) {
                db_run(
                    "INSERT IGNORE INTO health_import_removed (user_id, source_id, kind, external_id) VALUES (?, ?, 'workout', ?)",
                    [$userId, (int) $row['source_id'], (string) $row['external_id']]
                );
            }
            db_run('DELETE FROM workouts WHERE id = ? AND user_id = ?', [$workoutId, $userId]);
            $pdo?->commit();
        } catch (Throwable $e) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] removing a training failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => (string) $copy['save']];
        }

        health_manual_refresh($userId, ['workouts' => array_merge([$workoutId], $mates)], $start->format('Y-m-d'));

        return ['ok' => true, 'error' => null, 'imported' => $imported];
    }

    /**
     * After a change: what a sync sets going (health_import_records) —
     * goals, points, the Health Score and the day's insights — and, for a
     * day that has passed, that day's recorded scores and those of the days
     * whose window reaches back over it (40.0, health_score_restore_days()),
     * so the Scorekompas, the Verloop and the history show them.
     */
    function health_manual_refresh(int $userId, array $touched, ?string $date = null): void
    {
        goal_refresh_all($userId);
        points_process($userId, $touched);
        health_score_refresh($userId);
        if ($date !== null) {
            health_score_restore_days($userId, $date);
        }
        if (function_exists('insights_note_change')) {
            insights_note_change($userId);
        }
    }

    /* ==================================================================
       WHAT THE PAGES SHOW
       ================================================================== */

    /**
     * The two entry pages and the remove action, for $data (both apps):
     * null before migration 030.
     */
    function health_manual_page_data(string $today): ?array
    {
        if (!health_manual_ready()) {
            return null;
        }

        $copy  = health_manual_config();
        $types = [];
        foreach ((array) $copy['training']['types'] as $value => $label) {
            $types[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return [
            'today'    => $today,
            'common'   => $copy['common'],
            'sleep'    => $copy['sleep'],
            'training' => ['types' => $types] + array_diff_key((array) $copy['training'], ['types' => true]),
            'remove'   => $copy['remove'],
        ];
    }
}

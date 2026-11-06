<?php
/**
 * A Health Connect workout, filled in from the records around it — 43.0.
 *
 * Health Connect keeps an exercise session apart from what was measured
 * during it: the session says "running, 07:10 to 07:52", and the distance,
 * the calories and the heart rate are their own records over the same time,
 * often from other apps. Ownify's workout row has columns for them, and the
 * points (intensity, a personal record) and the Training page read those
 * columns — so here, after every batch, each Health Connect workout on the
 * days the batch touched gets them from what is stored:
 *
 *   distance_m           Distance over the workout, for sports that cover
 *                        distance only (a gym session does not get the
 *                        steps walked between sets as a distance record)
 *   active_kcal          ActiveCaloriesBurned over the workout
 *   total_kcal           TotalCaloriesBurned over the workout
 *   avg_hr, max_hr       the heart rate measured then (heart_rate_minutes,
 *                        as a workout entered by hand gets it), when it
 *                        covers at least half of the workout
 *
 * One app per value, never added up across apps: where a phone and a watch
 * both recorded the run, the app with the most of it counts, as a day's
 * total counts each moment once (health-totals.php). A record that only
 * partly overlaps the workout counts for the share of its time inside it.
 *
 * Nothing is estimated: what was not measured stays empty, and the points
 * then do what they always did with a workout without them. Only filled
 * in, never emptied: a value the workout carries itself stays, and other
 * sources are not touched at all — Polar sends them itself. A Health
 * Connect workout sent again starts empty (its upsert writes nulls) and is
 * worked out afresh, so a late record or a deleted one is reflected the
 * next time the workout is synced (at the latest the daily full sync).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/health-totals.php';

/** The source whose workouts are filled in. */
if (!defined('HEALTH_ENRICH_SOURCE')) {
    define('HEALTH_ENRICH_SOURCE', 'google_health_connect');
}

/**
 * Activity types (Health Connect's names, as health_connect_exercise_name()
 * stores them) whose distance means something: a record of the longest or
 * fastest one is a real record.
 */
if (!defined('HEALTH_ENRICH_DISTANCE_TYPES')) {
    define('HEALTH_ENRICH_DISTANCE_TYPES', [
        'running', 'running_treadmill', 'walking', 'hiking', 'biking', 'biking_stationary',
        'swimming_pool', 'swimming_open_water', 'rowing', 'rowing_machine', 'paddling',
        'skating', 'ice_skating', 'skiing', 'snowshoeing', 'wheelchair', 'elliptical',
    ]);
}

if (!function_exists('health_workouts_enrich')) {

    /**
     * Fills in the Health Connect workouts that started on $days (or the day
     * either side, for a workout across midnight or a record that arrives
     * on the next day's batch).
     *
     * @param list<string> $days Y-m-d
     * @return list<int> the workouts whose values changed — their points are looked at again
     */
    function health_workouts_enrich(int $userId, string $sourceCode, array $days): array
    {
        if ($sourceCode !== HEALTH_ENRICH_SOURCE || $days === [] || !health_metric_intervals_available()) {
            return [];
        }

        $sourceId = health_source_id($sourceCode);
        if ($sourceId === null) {
            return [];
        }

        sort($days);
        try {
            $from = (new DateTimeImmutable((string) reset($days)))->modify('-1 day')->format('Y-m-d 00:00:00');
            $to   = (new DateTimeImmutable((string) end($days)))->modify('+2 days')->format('Y-m-d 00:00:00');
        } catch (Exception $e) {
            return [];
        }

        $workouts = db_all(
            'SELECT id, activity_type, started_at, ended_at, duration_seconds,
                    distance_m, active_kcal, total_kcal, avg_hr, max_hr
               FROM workouts
              WHERE user_id = ? AND source_id = ? AND started_at >= ? AND started_at < ?',
            [$userId, $sourceId, $from, $to]
        );

        $changed = [];

        foreach ($workouts as $workout) {
            $start = strtotime((string) $workout['started_at']);
            $end   = $workout['ended_at'] !== null ? strtotime((string) $workout['ended_at']) : false;
            if ($end === false && $workout['duration_seconds'] !== null && $start !== false) {
                $end = $start + (int) $workout['duration_seconds'];
            }
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }

            /* Only filled in, never emptied: a value the workout itself
               carries stays (a resend of a Health Connect workout empties
               its columns first, so they are worked out afresh). */
            $values = health_workout_enrichment($userId, $sourceId, (string) $workout['activity_type'], $start, $end);
            foreach ($values as $column => $value) {
                $values[$column] = $value ?? ($workout[$column] === null ? null : $workout[$column]);
            }

            $same = true;
            foreach ($values as $column => $value) {
                $old = $workout[$column];
                if (($old === null) !== ($value === null) || ($old !== null && abs((float) $old - (float) $value) > 0.01)) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                continue;
            }

            db_run(
                'UPDATE workouts SET distance_m = ?, active_kcal = ?, total_kcal = ?, avg_hr = ?, max_hr = ?
                  WHERE id = ? AND user_id = ?',
                [$values['distance_m'], $values['active_kcal'], $values['total_kcal'],
                 $values['avg_hr'], $values['max_hr'], (int) $workout['id'], $userId]
            );
            $changed[] = (int) $workout['id'];
        }

        return $changed;
    }

    /**
     * What was measured from $start to $end (Unix seconds, on the stored
     * clock), for one workout of $type.
     *
     * @return array{distance_m: ?float, active_kcal: ?int, total_kcal: ?int, avg_hr: ?int, max_hr: ?int}
     */
    function health_workout_enrichment(int $userId, int $sourceId, string $type, int $start, int $end): array
    {
        $from = date('Y-m-d H:i:s', $start);
        $to   = date('Y-m-d H:i:s', $end);

        /* Every interval record of the three kinds that overlaps the workout:
           its share inside it, per app. A reading without a start counts
           where it was recorded. */
        $sums = [];
        foreach (db_all(
            "SELECT t.code, m.value, m.started_at, m.recorded_at, COALESCE(m.data_origin, '') AS origin
               FROM health_metrics m
               JOIN health_metric_types t ON t.id = m.metric_type_id
              WHERE m.user_id = ? AND m.source_id = ?
                AND t.code IN ('distance', 'active_energy', 'total_energy')
                AND m.recorded_at > ? AND COALESCE(m.started_at, m.recorded_at) < ?",
            [$userId, $sourceId, $from, $to]
        ) as $row) {
            $recorded = strtotime((string) $row['recorded_at']);
            $began    = $row['started_at'] !== null ? strtotime((string) $row['started_at']) : $recorded;
            if ($recorded === false || $began === false) {
                continue;
            }

            $span    = $recorded - $began;
            $overlap = min($recorded, $end) - max($began, $start);
            $share   = $span > 0 ? max(0, $overlap) / $span : (($recorded > $start && $recorded <= $end) ? 1.0 : 0.0);
            if ($share <= 0) {
                continue;
            }

            $code   = (string) $row['code'];
            $origin = (string) $row['origin'];
            $sums[$code][$origin] = ($sums[$code][$origin] ?? 0.0) + (float) $row['value'] * $share;
        }

        /* One app per value: the one that recorded the most of it. */
        $pick = static function (string $code) use ($sums): ?float {
            if (empty($sums[$code])) {
                return null;
            }
            $best = max($sums[$code]);
            return $best > 0 ? $best : null;
        };

        $km       = in_array(mb_strtolower($type), HEALTH_ENRICH_DISTANCE_TYPES, true) ? $pick('distance') : null;
        $active   = $pick('active_energy');
        $total    = $pick('total_energy');

        /* The heart rate measured during it, minute by minute — only when
           it covers at least half the workout, or a few minutes caught at
           its start would stand for all of it. */
        $avgHr = null;
        $maxHr = null;
        $bpm   = array_values(health_heart_minutes($userId, $from, $to));
        $needed = max(5, (int) floor(($end - $start) / 60 / 2));
        if (count($bpm) >= $needed) {
            $avgHr = (int) round(array_sum($bpm) / count($bpm));
            $maxHr = (int) round(max($bpm));
        }

        return [
            'distance_m'  => $km === null ? null : round($km * 1000, 1),
            'active_kcal' => $active === null ? null : (int) round($active),
            'total_kcal'  => $total === null ? null : (int) round($total),
            'avg_hr'      => $avgHr,
            'max_hr'      => $maxHr,
        ];
    }
}

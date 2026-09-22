<?php
/**
 * Importing health data from an outside source — PRIVATE.
 *
 * ---------------------------------------------------------------------------
 * ONE DOOR IN
 * ---------------------------------------------------------------------------
 * Everything from outside JoLu arrives here, in one normalised shape, whatever
 * fetched it. A cloud API the server polls and a phone app posting what it
 * read from Health Connect produce the same records and take the same path, so
 * the mapping, the de-duplication and the privacy rule are written once.
 *
 * ---------------------------------------------------------------------------
 * RUNNING TWICE MUST NOT DOUBLE ANYTHING
 * ---------------------------------------------------------------------------
 * A sync overlaps the last one by design — you re-fetch a few days because
 * data arrives late and gets corrected. So every record carries the id its
 * own system knows it by, and every table has a unique key on
 * (user_id, source_id, external_id). An import is an upsert: the second run
 * corrects the first rather than adding to it.
 *
 * Records with no external id are rejected rather than inserted blind. A
 * source that cannot name its records cannot be synced safely, and finding
 * that out at import time is better than discovering it as duplicates.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS INVENTED
 * ---------------------------------------------------------------------------
 * A field the source did not send stays null. Importing is not the place to
 * estimate: a missing heart rate is missing, and the score engine already
 * knows what to do with that.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/goal-progress.php';
require_once __DIR__ . '/scoring.php';

if (!function_exists('health_import_records')) {

    /**
     * Imports a batch for one user from one source.
     *
     * @param int    $userId     the authenticated user — from the session, never a request
     * @param string $sourceCode a data_sources.code, e.g. 'google_health'
     * @param array  $records    normalised records; see health_import_one()
     *
     * @return array counts and per-record problems
     */
    function health_import_records(int $userId, string $sourceCode, array $records): array
    {
        $sourceId = health_source_id($sourceCode);

        if ($sourceId === null) {
            return ['ok' => false, 'error' => 'Onbekende bron.', 'written' => 0, 'skipped' => 0, 'problems' => []];
        }

        $written  = 0;
        $skipped  = 0;
        $problems = [];
        $days     = [];         // which dates to re-score afterwards

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $problems[] = ['index' => $index, 'error' => 'Geen geldig record.'];
                $skipped++;
                continue;
            }

            $result = health_import_one($userId, $sourceCode, $sourceId, $record);

            if ($result['ok']) {
                $written++;

                if ($result['date'] !== null) {
                    $days[$result['date']] = true;
                }
            } else {
                $skipped++;
                $problems[] = ['index' => $index, 'error' => $result['error']];
            }
        }

        /* A day whose data changed has a stale stored score, if it has one at
           all. Clearing it makes the engine derive from what is now there. */
        foreach (array_keys($days) as $date) {
            health_import_invalidate_scores($userId, (string) $date);
        }

        /* And the goals that read this data are recomputed in the same breath.
           This is what makes "new steps arrive, the steps goal moves" true
           rather than something the user has to trigger by opening the goal
           and pressing something. A sync that finishes a goal says so. */
        $goalsCompleted = $written > 0 ? goal_refresh_all($userId) : 0;

        return [
            'ok'              => true,
            'error'           => null,
            'written'         => $written,
            'skipped'         => $skipped,
            'days'            => array_keys($days),
            'problems'        => array_slice($problems, 0, 20),
            'goals_completed' => $goalsCompleted,
        ];
    }

    /**
     * One record.
     *
     * The shapes, all of which need `external_id` and a time:
     *
     *   sleep        started_at, ended_at, and any of duration_minutes,
     *                time_in_bed_minutes, efficiency_pct, awakenings,
     *                awake_minutes, light_minutes, deep_minutes, rem_minutes
     *   workout      started_at, and any of ended_at, duration_seconds,
     *                activity_type, distance_m, active_kcal, total_kcal,
     *                avg_hr, max_hr, avg_speed_kmh, avg_cadence,
     *                elevation_gain_m
     *   nutrition    consumed_at, meal_type, label, rating, and nutrients
     *   measurement  measurement_type (height|weight|body_fat_pct|waist_cm|
     *                lean_mass), value, unit, measured_at
     *   metric       metric_code (a health_metric_types.code), value,
     *                recorded_at
     */
    function health_import_one(int $userId, string $sourceCode, int $sourceId, array $record): array
    {
        $type       = (string) ($record['type'] ?? '');
        $externalId = trim((string) ($record['external_id'] ?? ''));

        if ($externalId === '') {
            return ['ok' => false, 'error' => 'Record zonder external_id kan niet veilig worden gesynchroniseerd.', 'date' => null];
        }

        if (mb_strlen($externalId) > 191) {
            return ['ok' => false, 'error' => 'external_id is te lang.', 'date' => null];
        }

        return match ($type) {
            'sleep'       => health_import_sleep($userId, $sourceId, $externalId, $record),
            'workout'     => health_import_workout($userId, $sourceId, $externalId, $record),
            'nutrition'   => health_import_nutrition($userId, $sourceCode, $sourceId, $externalId, $record),
            'measurement' => health_import_measurement($userId, $sourceId, $externalId, $record),
            'metric'      => health_import_metric($userId, $sourceId, $externalId, $record),
            default       => ['ok' => false, 'error' => 'Onbekend recordtype: ' . $type, 'date' => null],
        };
    }

    /* --------------------------------------------------------------- sleep */

    function health_import_sleep(int $userId, int $sourceId, string $externalId, array $r): array
    {
        $start = health_import_time($r['started_at'] ?? null);
        $end   = health_import_time($r['ended_at'] ?? null);

        if ($start === null || $end === null) {
            return ['ok' => false, 'error' => 'Slaap zonder begin- of eindtijd.', 'date' => null];
        }

        if ($end <= $start) {
            return ['ok' => false, 'error' => 'Slaap die eindigt voor hij begint.', 'date' => null];
        }

        /* A night is filed under the morning it ended, which is what the rest
           of the app already assumes. */
        $nightOf  = $r['night_of'] ?? $end->format('Y-m-d');
        $duration = $r['duration_minutes'] ?? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

        db_run(
            'INSERT INTO sleep_sessions
                (user_id, source_id, external_id, night_of, started_at, ended_at,
                 duration_minutes, time_in_bed_minutes, efficiency_pct, awakenings,
                 awake_minutes, light_minutes, deep_minutes, rem_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                external_id = VALUES(external_id), night_of = VALUES(night_of),
                started_at = VALUES(started_at), ended_at = VALUES(ended_at),
                duration_minutes = VALUES(duration_minutes),
                time_in_bed_minutes = VALUES(time_in_bed_minutes),
                efficiency_pct = VALUES(efficiency_pct), awakenings = VALUES(awakenings),
                awake_minutes = VALUES(awake_minutes), light_minutes = VALUES(light_minutes),
                deep_minutes = VALUES(deep_minutes), rem_minutes = VALUES(rem_minutes)',
            [
                $userId, $sourceId, $externalId, $nightOf,
                $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $duration,
                health_import_int($r['time_in_bed_minutes'] ?? null),
                health_import_number($r['efficiency_pct'] ?? null),
                health_import_int($r['awakenings'] ?? null),
                health_import_int($r['awake_minutes'] ?? null),
                health_import_int($r['light_minutes'] ?? null),
                health_import_int($r['deep_minutes'] ?? null),
                health_import_int($r['rem_minutes'] ?? null),
            ]
        );

        return ['ok' => true, 'error' => null, 'date' => $nightOf];
    }

    /* ------------------------------------------------------------- workout */

    function health_import_workout(int $userId, int $sourceId, string $externalId, array $r): array
    {
        $start = health_import_time($r['started_at'] ?? null);

        if ($start === null) {
            return ['ok' => false, 'error' => 'Training zonder begintijd.', 'date' => null];
        }

        $end     = health_import_time($r['ended_at'] ?? null);
        $seconds = health_import_int($r['duration_seconds'] ?? null);

        if ($seconds === null && $end !== null) {
            $seconds = $end->getTimestamp() - $start->getTimestamp();
        }

        if ($seconds !== null && $seconds <= 0) {
            return ['ok' => false, 'error' => 'Training met een duur van nul.', 'date' => null];
        }

        db_run(
            'INSERT INTO workouts
                (user_id, source_id, external_id, activity_type, started_at, ended_at,
                 duration_seconds, distance_m, active_kcal, total_kcal, avg_hr, max_hr,
                 avg_speed_kmh, avg_cadence, elevation_gain_m, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                external_id = VALUES(external_id), activity_type = VALUES(activity_type),
                started_at = VALUES(started_at), ended_at = VALUES(ended_at),
                duration_seconds = VALUES(duration_seconds), distance_m = VALUES(distance_m),
                active_kcal = VALUES(active_kcal), total_kcal = VALUES(total_kcal),
                avg_hr = VALUES(avg_hr), max_hr = VALUES(max_hr),
                avg_speed_kmh = VALUES(avg_speed_kmh), avg_cadence = VALUES(avg_cadence),
                elevation_gain_m = VALUES(elevation_gain_m), notes = VALUES(notes)',
            [
                $userId, $sourceId, $externalId,
                mb_substr((string) ($r['activity_type'] ?? 'other'), 0, 40),
                $start->format('Y-m-d H:i:s'), $end?->format('Y-m-d H:i:s'), $seconds,
                health_import_number($r['distance_m'] ?? null),
                health_import_int($r['active_kcal'] ?? null),
                health_import_int($r['total_kcal'] ?? null),
                health_import_int($r['avg_hr'] ?? null),
                health_import_int($r['max_hr'] ?? null),
                health_import_number($r['avg_speed_kmh'] ?? null),
                health_import_int($r['avg_cadence'] ?? null),
                health_import_int($r['elevation_gain_m'] ?? null),
                isset($r['notes']) ? mb_substr((string) $r['notes'], 0, 255) : null,
            ]
        );

        return ['ok' => true, 'error' => null, 'date' => $start->format('Y-m-d')];
    }

    /* ----------------------------------------------------------- nutrition */

    function health_import_nutrition(int $userId, string $sourceCode, int $sourceId, string $externalId, array $r): array
    {
        $when = health_import_time($r['consumed_at'] ?? null);

        if ($when === null) {
            return ['ok' => false, 'error' => 'Voeding zonder tijdstip.', 'date' => null];
        }

        $mealType = (string) ($r['meal_type'] ?? 'other');

        if (!in_array($mealType, ['breakfast', 'lunch', 'dinner', 'snack', 'drink', 'other'], true)) {
            $mealType = 'other';
        }

        db_run(
            'INSERT INTO nutrition_entries
                (user_id, source_id, external_id, meal_type, label, consumed_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                external_id = VALUES(external_id), meal_type = VALUES(meal_type),
                label = VALUES(label), consumed_at = VALUES(consumed_at), notes = VALUES(notes)',
            [
                $userId, $sourceId, $externalId, $mealType,
                isset($r['label']) ? mb_substr((string) $r['label'], 0, 120) : null,
                $when->format('Y-m-d H:i:s'),
                isset($r['notes']) ? mb_substr((string) $r['notes'], 0, 255) : null,
            ]
        );

        /* The entry may already have existed, so its id is looked up rather
           than taken from lastInsertId, which is 0 on a pure update. */
        $entryId = db_value(
            'SELECT id FROM nutrition_entries WHERE user_id = ? AND source_id = ? AND external_id = ?',
            [$userId, $sourceId, $externalId]
        );

        if ($entryId !== null) {
            $nutrients = ['water', 'energy', 'protein', 'carbs', 'fat',
                          'saturated_fat', 'fibre', 'sugar', 'sodium'];

            foreach ($nutrients as $nutrient) {
                if (!isset($r[$nutrient]) || $r[$nutrient] === null || $r[$nutrient] === '') {
                    continue;
                }

                health_import_metric($userId, $sourceId, $externalId . ':' . $nutrient, [
                    'metric_code' => $nutrient,
                    'value'       => $r[$nutrient],
                    'recorded_at' => $when->format('Y-m-d H:i:s'),
                    'context'     => ['nutrition_entry_id' => (int) $entryId],
                ]);
            }

            if (isset($r['rating']) && $r['rating'] !== null && $r['rating'] !== '') {
                health_import_metric($userId, $sourceId, $externalId . ':rating', [
                    'metric_code' => 'nutrition_rating',
                    'value'       => max(1.0, min(10.0, (float) $r['rating'])),
                    'recorded_at' => $when->format('Y-m-d H:i:s'),
                    'context'     => ['nutrition_entry_id' => (int) $entryId],
                ]);
            }
        }

        return ['ok' => true, 'error' => null, 'date' => $when->format('Y-m-d')];
    }

    /* --------------------------------------------------------- measurement */

    function health_import_measurement(int $userId, int $sourceId, string $externalId, array $r): array
    {
        $type  = (string) ($r['measurement_type'] ?? '');
        $value = health_import_number($r['value'] ?? null);
        $when  = health_import_time($r['measured_at'] ?? null);

        if (!in_array($type, ['height', 'weight', 'body_fat_pct', 'waist_cm', 'lean_mass'], true)) {
            return ['ok' => false, 'error' => 'Onbekend meettype: ' . $type, 'date' => null];
        }

        if ($value === null || $when === null) {
            return ['ok' => false, 'error' => 'Meting zonder waarde of tijdstip.', 'date' => null];
        }

        db_run(
            'INSERT INTO user_measurements
                (user_id, measurement_type, value, unit, source_id, external_id, measured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                measurement_type = VALUES(measurement_type), value = VALUES(value),
                unit = VALUES(unit), measured_at = VALUES(measured_at)',
            [
                $userId, $type, $value,
                mb_substr((string) ($r['unit'] ?? ''), 0, 12),
                $sourceId, $externalId, $when->format('Y-m-d H:i:s'),
            ]
        );

        return ['ok' => true, 'error' => null, 'date' => $when->format('Y-m-d')];
    }

    /* -------------------------------------------------------------- metric */

    function health_import_metric(int $userId, int $sourceId, string $externalId, array $r): array
    {
        $code  = (string) ($r['metric_code'] ?? '');
        $value = health_import_number($r['value'] ?? null);
        $when  = health_import_time($r['recorded_at'] ?? null);

        $typeId = $code === '' ? null : health_metric_type_id($code);

        if ($typeId === null) {
            return ['ok' => false, 'error' => 'Onbekende meetwaarde: ' . $code, 'date' => null];
        }

        if ($value === null || $when === null) {
            return ['ok' => false, 'error' => 'Meetwaarde zonder getal of tijdstip.', 'date' => null];
        }

        $context = $r['context'] ?? [];

        db_run(
            'INSERT INTO health_metrics
                (user_id, metric_type_id, source_id, external_id, value, recorded_at,
                 sleep_session_id, workout_id, nutrition_entry_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                metric_type_id = VALUES(metric_type_id), value = VALUES(value),
                recorded_at = VALUES(recorded_at),
                sleep_session_id = VALUES(sleep_session_id),
                workout_id = VALUES(workout_id),
                nutrition_entry_id = VALUES(nutrition_entry_id)',
            [
                $userId, $typeId, $sourceId, $externalId, $value,
                $when->format('Y-m-d H:i:s'),
                $context['sleep_session_id'] ?? null,
                $context['workout_id'] ?? null,
                $context['nutrition_entry_id'] ?? null,
            ]
        );

        return ['ok' => true, 'error' => null, 'date' => $when->format('Y-m-d')];
    }

    /* ------------------------------------------------------------- scores */

    /**
     * Drops any stored score for a day whose data just changed.
     *
     * daily_scores holds recorded scores, which win over derived ones. After an
     * import the recorded one describes yesterday's data, so it is removed and
     * the engine derives from what is actually there now. Days nobody imported
     * are untouched.
     */
    function health_import_invalidate_scores(int $userId, string $date): void
    {
        db_run(
            "DELETE FROM daily_scores
              WHERE user_id = ? AND score_date = ? AND algorithm_version = 'v1'",
            [$userId, $date]
        );
    }

    /* -------------------------------------------------------------- casts */

    /** Accepts an ISO 8601 string, a DateTimeInterface or epoch seconds. */
    function health_import_time(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new DateTimeImmutable())->setTimestamp((int) $value);
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception $e) {
            return null;
        }
    }

    function health_import_int(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) round((float) $value);
    }

    function health_import_number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? (float) str_replace(',', '.', $value) : (float) $value;
    }
}

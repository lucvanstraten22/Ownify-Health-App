<?php
/**
 * Importing health data from an outside source — PRIVATE.
 *
 * ---------------------------------------------------------------------------
 * ONE DOOR IN
 * ---------------------------------------------------------------------------
 * Everything from outside Ownify arrives here, in one normalised shape, whatever
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
require_once __DIR__ . '/health-score.php';
require_once __DIR__ . '/points.php';

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
        $days     = [];         // which dates the batch touched
        $touched  = [];         // what may earn points: nights, workouts, rated days, step days

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

                foreach ($result['touch'] ?? [] as [$kind, $what]) {
                    $touched[$kind][] = $what;
                }
            } else {
                $skipped++;
                $problems[] = ['index' => $index, 'error' => $result['error']];
            }
        }

        /* The goals that read this data are recomputed in the same breath.
           This is what makes "new steps arrive, the steps goal moves" true
           rather than something the user has to trigger by opening the goal
           and pressing something. A sync that finishes a goal says so. */
        $goalsCompleted = $written > 0 ? goal_refresh_all($userId) : 0;

        /* What the batch earned, the moment it arrived — each night, workout,
           rating and day of steps once, however often it is sent — and the
           Health Score recalculated over the 168 hours that now include it.
           Two separate things: the score does not pay out. */
        $awards = $written > 0 ? points_process($userId, $touched) : [];
        $scores = $written > 0 ? health_score_refresh($userId) : null;

        return [
            'ok'              => true,
            'error'           => null,
            'written'         => $written,
            'skipped'         => $skipped,
            'days'            => array_keys($days),
            'problems'        => array_slice($problems, 0, 20),
            'goals_completed' => $goalsCompleted,
            'awards'          => $awards,
            'scores'          => $scores,
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
            'heart_rate'  => health_import_heart_rate($userId, $sourceId, $record),
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

        if (isset($r['stages']) && is_array($r['stages'])) {
            health_import_sleep_stages($userId, $start, $r['stages']);
        }

        return ['ok' => true, 'error' => null, 'date' => $nightOf, 'touch' => [['nights', $nightOf]]];
    }

    /**
     * The session's stages, period by period (sleep_stages, migration 018):
     * replaced as a whole, as the session itself is. Each period on the
     * session's own clock, as its start and end are stored. Nothing is
     * worked out from them here — the minutes per stage on the session are
     * what the night, the score and the points read.
     *
     * @param array<int,array{stage: int, started_at: string, ended_at: string}> $stages
     */
    function health_import_sleep_stages(int $userId, DateTimeImmutable $start, array $stages): void
    {
        if (!function_exists('health_sleep_stages_stored') || !health_sleep_stages_stored()) {
            return;                       // migration 018 not imported yet: the minutes are kept all the same
        }

        $session = db_value(
            'SELECT id FROM sleep_sessions WHERE user_id = ? AND started_at = ?',
            [$userId, $start->format('Y-m-d H:i:s')]
        );

        if ($session === null) {
            return;
        }

        db_run('DELETE FROM sleep_stages WHERE sleep_session_id = ?', [(int) $session]);

        foreach ($stages as $stage) {
            $from = health_import_time($stage['started_at'] ?? null);
            $to   = health_import_time($stage['ended_at'] ?? null);
            $kind = (int) ($stage['stage'] ?? 0);

            if ($from === null || $to === null || $to <= $from || $kind < 1 || $kind > 7) {
                continue;
            }

            db_run(
                'INSERT INTO sleep_stages (sleep_session_id, stage, started_at, ended_at) VALUES (?, ?, ?, ?)',
                [(int) $session, $kind, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
            );
        }
    }

    /* ---------------------------------------------------------- heart rate */

    /**
     * A heart-rate record's minutes (heart_rate_minutes, migration 019): the
     * mean of each minute's samples, per app — written again, the same, when
     * the record is synced again. Nothing is worked out from them here.
     *
     * @param array{minutes?: list<array{at: string, bpm: float, n: int}>, data_origin?: ?string} $r
     */
    function health_import_heart_rate(int $userId, int $sourceId, array $r): array
    {
        $minutes = (array) ($r['minutes'] ?? []);
        if ($minutes === []) {
            return ['ok' => false, 'error' => 'Hartslag zonder metingen.', 'date' => null];
        }

        if (!function_exists('health_heart_minutes_stored') || !health_heart_minutes_stored()) {
            return ['ok' => true, 'error' => null, 'date' => null];     // migration 019 not imported: nothing to keep it in
        }

        $origin = trim((string) ($r['data_origin'] ?? '')) !== '' ? mb_substr((string) $r['data_origin'], 0, 191) : 'source:' . $sourceId;
        $first  = null;

        foreach (array_chunk($minutes, 200) as $chunk) {
            $rows   = [];
            $params = [];
            foreach ($chunk as $minute) {
                $at  = health_import_time($minute['at'] ?? null);
                $bpm = health_import_number($minute['bpm'] ?? null);
                if ($at === null || $bpm === null || $bpm <= 0 || $bpm > 260) {
                    continue;
                }
                $first ??= $at->format('Y-m-d');
                $rows[] = '(?, ?, ?, ?, ?)';
                array_push($params, $userId, $at->format('Y-m-d H:i:00'), $origin, round($bpm, 1), max(1, min(65535, (int) ($minute['n'] ?? 1))));
            }
            if ($rows === []) {
                continue;
            }
            db_run(
                'INSERT INTO heart_rate_minutes (user_id, minute_at, data_origin, bpm, samples) VALUES ' . implode(', ', $rows) . '
                 ON DUPLICATE KEY UPDATE bpm = VALUES(bpm), samples = VALUES(samples)',
                $params
            );
        }

        return ['ok' => true, 'error' => null, 'date' => $first];
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

        $workoutId = db_value(
            'SELECT id FROM workouts WHERE user_id = ? AND source_id = ? AND external_id = ?',
            [$userId, $sourceId, $externalId]
        );

        return [
            'ok'    => true,
            'error' => null,
            'date'  => $start->format('Y-m-d'),
            'touch' => $workoutId === null ? [] : [['workouts', (int) $workoutId]],
        ];
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

        $rated = isset($r['rating']) && $r['rating'] !== null && $r['rating'] !== '';

        return [
            'ok'    => true,
            'error' => null,
            'date'  => $when->format('Y-m-d'),
            'touch' => $rated ? [['nutrition_days', $when->format('Y-m-d')]] : [],
        ];
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
        $end     = $when->format('Y-m-d H:i:s');

        /* The time a value covers, when it covers one: steps from 10:00 to
           10:15 are stored with both ends, so a day's total can count two
           apps' records of the same quarter hour once (health_metric_totals()).
           A start that is not before the end is no span at all. */
        $start = health_import_time($r['started_at'] ?? null)?->format('Y-m-d H:i:s');
        $start = ($start !== null && $start < $end) ? $start : null;

        $origin = is_string($r['data_origin'] ?? null) && trim($r['data_origin']) !== ''
            ? mb_substr(trim($r['data_origin']), 0, 191)
            : null;

        if (health_metric_intervals_available()) {
            /* Same key, same upsert: sending a record again rewrites it. The
               app that wrote a record never changes, so a copy sent without
               one keeps the one already known. */
            db_run(
                'INSERT INTO health_metrics
                    (user_id, metric_type_id, source_id, data_origin, external_id, value,
                     started_at, recorded_at, sleep_session_id, workout_id, nutrition_entry_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    metric_type_id = VALUES(metric_type_id),
                    data_origin = COALESCE(VALUES(data_origin), data_origin),
                    value = VALUES(value),
                    started_at = VALUES(started_at), recorded_at = VALUES(recorded_at),
                    sleep_session_id = VALUES(sleep_session_id),
                    workout_id = VALUES(workout_id),
                    nutrition_entry_id = VALUES(nutrition_entry_id)',
                [
                    $userId, $typeId, $sourceId, $origin, $externalId, $value,
                    $start, $end,
                    $context['sleep_session_id'] ?? null,
                    $context['workout_id'] ?? null,
                    $context['nutrition_entry_id'] ?? null,
                ]
            );
        } else {
            /* Before migration 012: the columns are not there yet. */
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
                    $userId, $typeId, $sourceId, $externalId, $value, $end,
                    $context['sleep_session_id'] ?? null,
                    $context['workout_id'] ?? null,
                    $context['nutrition_entry_id'] ?? null,
                ]
            );
        }

        /* Steps from 23:50 to 00:10 count on both days, so both days' awards
           are looked at again. */
        $stepDays = array_unique(array_filter([$start === null ? null : substr($start, 0, 10), $when->format('Y-m-d')]));

        $touch = match ($code) {
            'steps'            => array_map(fn (string $day): array => ['step_days', $day], array_values($stepDays)),
            'nutrition_rating' => [['nutrition_days', $when->format('Y-m-d')]],
            default            => [],
        };

        return ['ok' => true, 'error' => null, 'date' => $when->format('Y-m-d'), 'touch' => $touch];
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

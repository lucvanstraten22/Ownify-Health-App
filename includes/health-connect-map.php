<?php
/**
 * Health Connect records -> JoLu's normalised import shape.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ON THE SERVER
 * ---------------------------------------------------------------------------
 * Health Connect has no wire format. Its records are Kotlin objects, so
 * somebody has to decide what they look like as JSON, and that decision could
 * live in the phone app or here.
 *
 * Here, because an app is a release and a review and a week of people not
 * updating. A metric we map wrongly, a unit we misread, a record type we want
 * to start accepting — all of that is a deploy if the mapping is on the
 * server, and an app update if it is not. The app stays thin on purpose: read
 * the records, serialise them, post them, forget them.
 *
 * ---------------------------------------------------------------------------
 * THE CONTRACT
 * ---------------------------------------------------------------------------
 * One JSON object per record, mirroring Health Connect's own class and field
 * names so the app's mapping is obvious rather than clever:
 *
 *   {
 *     "recordType": "SleepSession",
 *     "metadata":   { "id": "<uuid>", "dataOrigin": "com.google.android.apps.fitness" },
 *     "startTime":  "2026-09-17T23:10:00Z",
 *     "endTime":    "2026-09-18T06:42:00Z",
 *     "stages":     [ { "startTime": "...", "endTime": "...", "stage": 5 } ]
 *   }
 *
 * metadata.id is Health Connect's own record id. It is what makes a re-sync
 * safe: the same night sent twice is the same id twice, and the importer
 * upserts on it. A record without one is refused rather than guessed at.
 *
 * Units are Health Connect's: kilograms, meters, kilocalories, litres.
 * Converting on the phone would be one more thing to get wrong in a place we
 * cannot fix quickly.
 */

declare(strict_types=1);

/**
 * Sleep stage constants, as Health Connect numbers them.
 * @see androidx.health.connect.client.records.SleepSessionRecord
 */
if (!defined('HC_STAGE_AWAKE')) {
    define('HC_STAGE_AWAKE',        1);
    define('HC_STAGE_SLEEPING',     2);
    define('HC_STAGE_OUT_OF_BED',   3);
    define('HC_STAGE_LIGHT',        4);
    define('HC_STAGE_DEEP',         5);
    define('HC_STAGE_REM',          6);
    define('HC_STAGE_AWAKE_IN_BED', 7);
}

if (!function_exists('health_connect_map')) {

    /**
     * Maps a batch. Anything unrecognised is reported rather than dropped
     * silently — a record type we do not handle is something to find out
     * about, not something to lose.
     *
     * @return array{records: array, unmapped: array}
     */
    function health_connect_map(array $records): array
    {
        $out      = [];
        $unmapped = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $mapped = health_connect_map_one($record);

            if ($mapped === []) {
                $type = (string) ($record['recordType'] ?? 'onbekend');
                $unmapped[$type] = ($unmapped[$type] ?? 0) + 1;
                continue;
            }

            foreach ($mapped as $one) {
                $out[] = $one;
            }
        }

        return ['records' => $out, 'unmapped' => $unmapped];
    }

    /**
     * One Health Connect record becomes zero, one or several JoLu records.
     *
     * Several, because some Health Connect records carry more than one fact: a
     * nutrition record is a meal plus its nutrients, and each nutrient is its
     * own metric row.
     */
    function health_connect_map_one(array $r): array
    {
        $id = (string) ($r['metadata']['id'] ?? '');

        if ($id === '') {
            return [];      // the importer would refuse it anyway, and say so
        }

        $type  = (string) ($r['recordType'] ?? '');
        $start = $r['startTime'] ?? $r['time'] ?? null;
        $end   = $r['endTime'] ?? null;

        return match ($type) {
            'SleepSession'         => health_connect_sleep($id, $r),
            'ExerciseSession'      => health_connect_exercise($id, $r),
            'Nutrition'            => health_connect_nutrition($id, $r),

            /* Body measurements. Health Connect is SI, the app stores what
               people use: kilograms as kilograms, metres as centimetres. */
            'Weight'               => health_connect_measure($id, 'weight', 'kg',
                                        health_connect_number($r['weight']['kilograms'] ?? null), $start),
            'Height'               => health_connect_measure($id, 'height', 'cm',
                                        health_connect_scale($r['height']['meters'] ?? null, 100), $start),
            'BodyFat'              => health_connect_measure($id, 'body_fat_pct', '%',
                                        health_connect_number($r['percentage'] ?? null), $start),
            'LeanBodyMass'         => health_connect_measure($id, 'lean_mass', 'kg',
                                        health_connect_number($r['mass']['kilograms'] ?? null), $start),

            /* Everything scalar. The end of an interval is when it counted. */
            'Steps'                => health_connect_metric($id, 'steps',
                                        health_connect_number($r['count'] ?? null), $end ?? $start),
            'Distance'             => health_connect_metric($id, 'distance',
                                        health_connect_scale($r['distance']['meters'] ?? null, 0.001), $end ?? $start),
            'FloorsClimbed'        => health_connect_metric($id, 'floors',
                                        health_connect_number($r['floors'] ?? null), $end ?? $start),
            'ActiveCaloriesBurned' => health_connect_metric($id, 'active_energy',
                                        health_connect_number($r['energy']['kilocalories'] ?? null), $end ?? $start),
            'TotalCaloriesBurned'  => health_connect_metric($id, 'total_energy',
                                        health_connect_number($r['energy']['kilocalories'] ?? null), $end ?? $start),
            'RestingHeartRate'     => health_connect_metric($id, 'resting_hr',
                                        health_connect_number($r['beatsPerMinute'] ?? null), $start),
            'HeartRateVariabilityRmssd' => health_connect_metric($id, 'hrv',
                                        health_connect_number($r['heartRateVariabilityMillis'] ?? null), $start),
            'OxygenSaturation'     => health_connect_metric($id, 'spo2',
                                        health_connect_number($r['percentage'] ?? null), $start),
            'RespiratoryRate'      => health_connect_metric($id, 'respiratory_rate',
                                        health_connect_number($r['rate'] ?? null), $start),
            'Vo2Max'               => health_connect_metric($id, 'vo2max',
                                        health_connect_number($r['vo2MillilitersPerMinuteKilogram'] ?? null), $start),
            'SkinTemperature', 'BodyTemperature' => health_connect_metric($id, 'skin_temp',
                                        health_connect_number($r['temperature']['celsius'] ?? null), $start),
            'Hydration'            => health_connect_metric($id, 'water',
                                        health_connect_number($r['volume']['liters'] ?? null), $end ?? $start),

            /* A heart-rate record is a series of samples. The average over the
               night is what the sleep card shows, so that is what is kept —
               storing every sample would be a row per few seconds for a figure
               nothing reads. */
            'HeartRate'            => health_connect_heart_rate($id, $r),

            default                => [],
        };
    }

    /* --------------------------------------------------------------- sleep */

    function health_connect_sleep(string $id, array $r): array
    {
        $start = $r['startTime'] ?? null;
        $end   = $r['endTime'] ?? null;

        if ($start === null || $end === null) {
            return [];
        }

        $record = [
            'type'        => 'sleep',
            'external_id' => $id,
            'started_at'  => $start,
            'ended_at'    => $end,
        ];

        /* Stages are optional: a phone without a watch reports a session and
           no breakdown, and the app already draws that as an empty timeline
           rather than as zeroes. */
        $minutes = [
            HC_STAGE_LIGHT        => 0,
            HC_STAGE_DEEP         => 0,
            HC_STAGE_REM          => 0,
            HC_STAGE_AWAKE        => 0,
            HC_STAGE_AWAKE_IN_BED => 0,
        ];

        $awakenings = 0;
        $hasStages  = false;

        foreach ($r['stages'] ?? [] as $stage) {
            $from = strtotime((string) ($stage['startTime'] ?? ''));
            $to   = strtotime((string) ($stage['endTime'] ?? ''));
            $kind = (int) ($stage['stage'] ?? 0);

            if ($from === false || $to === false || $to <= $from || !array_key_exists($kind, $minutes)) {
                continue;
            }

            $hasStages = true;
            $minutes[$kind] += (int) round(($to - $from) / 60);

            if ($kind === HC_STAGE_AWAKE) {
                $awakenings++;
            }
        }

        if ($hasStages) {
            $awake = $minutes[HC_STAGE_AWAKE] + $minutes[HC_STAGE_AWAKE_IN_BED];

            $record['light_minutes'] = $minutes[HC_STAGE_LIGHT];
            $record['deep_minutes']  = $minutes[HC_STAGE_DEEP];
            $record['rem_minutes']   = $minutes[HC_STAGE_REM];
            $record['awake_minutes'] = $awake;
            $record['awakenings']    = $awakenings;

            $asleep = $minutes[HC_STAGE_LIGHT] + $minutes[HC_STAGE_DEEP] + $minutes[HC_STAGE_REM];
            $inBed  = $asleep + $awake;

            if ($inBed > 0) {
                $record['duration_minutes']    = $asleep;
                $record['time_in_bed_minutes'] = $inBed;
                /* Efficiency is time asleep over time in bed — derived from
                   two measured numbers, not invented. */
                $record['efficiency_pct'] = round($asleep / $inBed * 100, 2);
            }
        }

        return [$record];
    }

    /* ------------------------------------------------------------ exercise */

    function health_connect_exercise(string $id, array $r): array
    {
        $start = $r['startTime'] ?? null;

        if ($start === null) {
            return [];
        }

        return [[
            'type'          => 'workout',
            'external_id'   => $id,
            'started_at'    => $start,
            'ended_at'      => $r['endTime'] ?? null,
            'activity_type' => health_connect_exercise_name($r),
            'notes'         => isset($r['notes']) ? (string) $r['notes'] : null,
        ]];
    }

    /**
     * What to call the activity.
     *
     * Health Connect numbers its exercise types. The app may send the number,
     * the constant name, or the session title; any of the three is more use
     * than "other", and the column is a free string so none of them needs a
     * lookup table that would go stale the moment Google adds a sport.
     */
    function health_connect_exercise_name(array $r): string
    {
        foreach (['exerciseTypeName', 'title', 'exerciseType'] as $key) {
            if (isset($r[$key]) && $r[$key] !== '' && $r[$key] !== null) {
                return mb_substr((string) $r[$key], 0, 40);
            }
        }

        return 'other';
    }

    /* ----------------------------------------------------------- nutrition */

    function health_connect_nutrition(string $id, array $r): array
    {
        $when = $r['startTime'] ?? $r['time'] ?? null;

        if ($when === null) {
            return [];
        }

        $entry = [
            'type'        => 'nutrition',
            'external_id' => $id,
            'consumed_at' => $when,
            'label'       => isset($r['name']) ? (string) $r['name'] : null,
            'meal_type'   => health_connect_meal_type($r['mealType'] ?? null),
        ];

        /* Health Connect's nutrient names, onto the catalogue's. Grams and
           kilocalories on both sides, so nothing is converted. */
        $nutrients = [
            'energy'            => ['energy', 'kilocalories'],
            'protein'           => ['protein', 'grams'],
            'totalCarbohydrate' => ['carbs', 'grams'],
            'totalFat'          => ['fat', 'grams'],
            'saturatedFat'      => ['saturated_fat', 'grams'],
            'dietaryFiber'      => ['fibre', 'grams'],
            'sugar'             => ['sugar', 'grams'],
            'sodium'            => ['sodium', 'grams'],
        ];

        foreach ($nutrients as $from => [$to, $unit]) {
            if (!isset($r[$from])) {
                continue;
            }

            $value = is_array($r[$from])
                ? health_connect_number($r[$from][$unit] ?? null)
                : health_connect_number($r[$from]);

            if ($value === null) {
                continue;
            }

            /* The catalogue keeps sodium in milligrams; Health Connect gives
               grams, and a thousandfold error here would be a silent one. */
            $entry[$to] = $to === 'sodium' ? $value * 1000 : $value;
        }

        return [$entry];
    }

    /** Health Connect's meal type numbers, onto the column's words. */
    function health_connect_meal_type(mixed $value): string
    {
        return match ((int) $value) {
            1       => 'breakfast',
            2       => 'lunch',
            3       => 'dinner',
            4       => 'snack',
            default => 'other',
        };
    }

    /* ---------------------------------------------------------- heart rate */

    function health_connect_heart_rate(string $id, array $r): array
    {
        $samples = $r['samples'] ?? [];
        $total   = 0;
        $count   = 0;
        $last    = null;

        foreach ($samples as $sample) {
            $bpm = health_connect_number($sample['beatsPerMinute'] ?? null);

            if ($bpm === null || $bpm <= 0) {
                continue;
            }

            $total += $bpm;
            $count++;
            $last = $sample['time'] ?? $last;
        }

        if ($count === 0) {
            return [];
        }

        return health_connect_metric($id, 'sleeping_hr', $total / $count, $last ?? ($r['startTime'] ?? null));
    }

    /* --------------------------------------------------------- small parts */

    function health_connect_metric(string $id, string $code, ?float $value, mixed $at): array
    {
        if ($value === null || $at === null) {
            return [];
        }

        return [[
            'type'        => 'metric',
            'external_id' => $id,
            'metric_code' => $code,
            'value'       => $value,
            'recorded_at' => $at,
        ]];
    }

    function health_connect_measure(string $id, string $type, string $unit, ?float $value, mixed $at): array
    {
        if ($value === null || $at === null) {
            return [];
        }

        return [[
            'type'             => 'measurement',
            'external_id'      => $id,
            'measurement_type' => $type,
            'value'            => $value,
            'unit'             => $unit,
            'measured_at'      => $at,
        ]];
    }

    function health_connect_number(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    function health_connect_scale(mixed $value, float $factor): ?float
    {
        $number = health_connect_number($value);

        return $number === null ? null : $number * $factor;
    }
}

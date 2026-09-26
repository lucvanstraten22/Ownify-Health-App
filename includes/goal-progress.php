<?php
/**
 * What a goal is actually at — PRIVATE, scoped to the owner.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * A goal used to be a name, a number and a bar that never moved. It could name
 * a metric_type, but nothing ever set one, so every goal was manual in
 * practice with no way to enter anything by hand either.
 *
 * This is the one place that answers "where is this goal now", for every kind
 * of goal, from the user's own rows. Nothing else calculates a percentage, so
 * the bar, the figure, the day blocks and the completion check can never
 * disagree with each other.
 *
 * ---------------------------------------------------------------------------
 * THE TYPE DECIDES THE ARITHMETIC
 * ---------------------------------------------------------------------------
 * Three types, three different questions asked of the same rows:
 *
 *   Mijlpaal   what is the best result so far?            (never a sum)
 *   Streak     how many successful days in a row, now?    (a gap breaks it)
 *   Optellen   what does everything add up to?            (never a best)
 *
 * goal_kind() says which a goal is; goal_compute_milestone(), _streak() and
 * _accumulate() answer it. The data source only says where the rows are.
 *
 * ---------------------------------------------------------------------------
 * A SOURCE IS NOT ALWAYS A METRIC
 * ---------------------------------------------------------------------------
 * Weight lives in user_measurements, training in workouts, steps and sleep in
 * health_metrics. Three tables, so a column naming one has to say which kind
 * it is — hence source_kind and source_key rather than metric_type_id alone.
 *
 * ---------------------------------------------------------------------------
 * MISSING IS NOT ZERO
 * ---------------------------------------------------------------------------
 * null all the way through means "nothing recorded", and 0.0 means "recorded,
 * and it was nothing". A day with no step count is not a day of no steps: it
 * is a day we know nothing about, and it is drawn differently, counted
 * differently, and never quietly turned into a failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/goals.php';

/** Six weeks of calendar. Longer goals show their most recent six. */
if (!defined('GOAL_CALENDAR_DAYS')) {
    define('GOAL_CALENDAR_DAYS', 42);
}

if (!function_exists('goal_sources_available')) {

    /**
     * Whether migration 006 has been imported.
     *
     * Checked rather than assumed, because a deploy reaches the server before
     * anybody opens phpMyAdmin, and on 18 September a migration that had not
     * been imported took the whole site down. Until the columns exist, goals
     * behave exactly as they did before — manual, with no source step — rather
     * than erroring.
     */
    function goal_sources_available(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!db_available()) {
            return $available = false;
        }

        $available = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'goals'
                AND column_name IN ('tracking_mode','source_kind','source_key','start_value','daily_target','completed_at')"
        ) === 6;

        return $available;
    }

    /* ==================================================================
       THE SOURCES SOMEBODY CAN CHOOSE
       ================================================================== */

    /**
     * Every source a goal can be tracked from, as the wizard offers them.
     *
     * Read out of the metric catalogue rather than written here, so a metric
     * added to health_metric_types is offerable without touching this file and
     * nothing can be offered that the app cannot actually read.
     *
     * `daily` marks the ones where "every day" is a sensible thing to ask —
     * a total that resets each day. Nobody sets a daily target for VO2max.
     */
    function goal_source_catalogue(): array
    {
        static $catalogue = null;

        if ($catalogue !== null) {
            return $catalogue;
        }

        $catalogue = [];

        /* Body measurements. Not metrics: they are a standing figure rather
           than something accumulated over a day. */
        foreach ([
            'weight'       => ['Gewicht', 'kg'],
            'body_fat_pct' => ['Vetpercentage', '%'],
            'waist_cm'     => ['Taille', 'cm'],
            'lean_mass'    => ['Vetvrije massa', 'kg'],
        ] as $key => [$label, $unit]) {
            $catalogue[] = [
                'kind'   => 'measurement',
                'key'    => $key,
                'label'  => $label,
                'unit'   => $unit,
                'domain' => 'body',
                'daily'  => false,
            ];
        }

        /* Workouts, which are rows rather than readings. */
        $catalogue[] = ['kind' => 'workout', 'key' => 'minutes',  'label' => 'Trainingsminuten', 'unit' => 'min', 'domain' => 'training', 'daily' => true];
        $catalogue[] = ['kind' => 'workout', 'key' => 'sessions', 'label' => 'Trainingen',       'unit' => 'x',   'domain' => 'training', 'daily' => true];

        /* Everything in the metric catalogue. The aggregation column already
           says whether a metric accumulates over a day, which is exactly the
           question "can this have a daily target". */
        if (db_available()) {
            foreach (db_all('SELECT code, label, unit, domain, aggregation FROM health_metric_types ORDER BY domain, label') as $row) {
                $catalogue[] = [
                    'kind'   => 'metric',
                    'key'    => (string) $row['code'],
                    'label'  => (string) $row['label'],
                    'unit'   => (string) ($row['unit'] ?? ''),
                    'domain' => (string) $row['domain'],
                    'daily'  => $row['aggregation'] === 'sum',
                ];
            }
        }

        return $catalogue;
    }

    /** One source, or null when the key is not one this app can read. */
    function goal_source_find(?string $kind, ?string $key): ?array
    {
        if ($kind === null || $kind === 'manual' || $key === null || $key === '') {
            return null;
        }

        foreach (goal_source_catalogue() as $source) {
            if ($source['kind'] === $kind && $source['key'] === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * How a source's figures are written, and what a person types them in.
     *
     * Most sources say their own unit. A few are stored in a form nobody
     * thinks in: sleep is kept in minutes and read in hours, steps have no
     * unit symbol at all and are simply "stappen". `scale` turns a stored
     * figure into the one shown (480 minutes * 1/60 = 8 uur), and dividing
     * by it turns what somebody typed back into what is stored — so "8 uur
     * slaap" is compared against 480 minutes, not against 8.
     *
     * @return array{word: string, one: string, scale: float, decimals: int, attached: bool}
     */
    function goal_source_unit(?string $kind, ?string $key): array
    {
        $make = static fn (string $word, int $decimals = 0, float $scale = 1.0, ?string $one = null, bool $attached = false): array => [
            'word'     => $word,
            'one'      => $one ?? $word,
            'scale'    => $scale,
            'decimals' => $decimals,
            'attached' => $attached,   // "85%" rather than "85 %"
        ];

        $key = (string) $key;

        if ($kind === 'measurement') {
            return match ($key) {
                'body_fat_pct' => $make('%', 1, 1.0, null, true),
                'waist_cm'     => $make('cm'),
                default        => $make('kg', 1),
            };
        }

        if ($kind === 'workout') {
            return $key === 'sessions'
                ? $make('trainingen', 0, 1.0, 'training')
                : $make('min');
        }

        return match ($key) {
            'steps'            => $make('stappen', 0, 1.0, 'stap'),
            'floors'           => $make('verdiepingen', 0, 1.0, 'verdieping'),
            'sleep_duration'   => $make('uur', 1, 1 / 60),
            'sleep_efficiency', 'sleep_regularity', 'spo2' => $make('%', 0, 1.0, null, true),
            'distance'         => $make('km', 1),
            'water'            => $make('l', 1),
            'energy', 'active_energy', 'total_energy' => $make('kcal'),
            'protein', 'carbs', 'fat', 'fibre', 'sugar', 'saturated_fat' => $make('g'),
            'sodium'           => $make('mg'),
            'sleeping_hr', 'resting_hr' => $make('bpm'),
            'hrv'              => $make('ms'),
            'respiratory_rate' => $make('/min', 0, 1.0, null, true),
            'skin_temp'        => $make('°C', 1),
            'active_minutes'   => $make('min'),
            'vo2max'           => $make('ml/kg/min', 1),
            'nutrition_rating' => $make('/10', 1, 1.0, null, true),
            'readiness'        => $make('/100', 0, 1.0, null, true),
            default            => $make(''),
        };
    }

    /** "8 uur", "10.425 stappen", "85%": a stored figure in a source's own words. */
    function goal_unit_text(float $stored, array $unit): string
    {
        $shown = $stored * $unit['scale'];
        $text  = number_format($shown, $unit['decimals'], ',', '.');

        if ($unit['decimals'] > 0 && str_contains($text, ',')) {
            $text = rtrim(rtrim($text, '0'), ',');
        }

        if ($text === '-0') {
            $text = '0';
        }

        if ($unit['word'] === '') {
            return $text;
        }

        $word = round($shown, $unit['decimals']) == 1.0 ? $unit['one'] : $unit['word'];

        return $unit['attached'] ? $text . $word : $text . ' ' . $word;
    }

    /** "1 dag", "12 dagen". */
    function goal_days_text(int|float $days): string
    {
        $days = (int) round($days);

        return $days === 1 ? '1 dag' : number_format($days, 0, ',', '.') . ' dagen';
    }

    /* ==================================================================
       READING ONE USER'S OWN DATA
       ================================================================== */

    /** The newest figure of any date, for goals that track a standing value. */
    function goal_source_latest(int $userId, string $kind, string $key): ?float
    {
        if ($kind === 'metric' && goal_is_sleep_key($key)) {
            $night = db_value(
                'SELECT MAX(night_of) FROM sleep_sessions WHERE user_id = ?',
                [$userId]
            );

            if ($night !== null) {
                return goal_sleep_by_day($userId, $key, (string) $night, (string) $night)[(string) $night] ?? null;
            }
        }

        switch ($kind) {
            case 'measurement':
                /* id breaks the tie. Two readings can share a timestamp —
                   a scale and a phone syncing the same morning, or two rows
                   written in the same second — and without a tiebreak "the
                   newest" is whichever the engine happens to return, which
                   makes a weight goal flicker between two values. */
                $value = db_value(
                    'SELECT value FROM user_measurements
                      WHERE user_id = ? AND measurement_type = ?
                   ORDER BY measured_at DESC, id DESC LIMIT 1',
                    [$userId, $key]
                );

                return $value === null ? null : (float) $value;

            case 'metric':
                $value = db_value(
                    'SELECT m.value FROM health_metrics m
                       JOIN health_metric_types t ON t.id = m.metric_type_id
                      WHERE m.user_id = ? AND t.code = ?
                   ORDER BY m.recorded_at DESC, m.id DESC LIMIT 1',
                    [$userId, $key]
                );

                return $value === null ? null : (float) $value;

            case 'workout':
                /* The most recent session's length. A count of sessions is not
                   a standing figure, so it has no "latest". */
                if ($key !== 'minutes') {
                    return null;
                }

                $value = db_value(
                    'SELECT duration_seconds / 60 FROM workouts
                      WHERE user_id = ? AND duration_seconds IS NOT NULL
                   ORDER BY started_at DESC, id DESC LIMIT 1',
                    [$userId]
                );

                return $value === null ? null : (float) $value;
        }

        return null;
    }

    /* ==================================================================
       THE THREE TYPES
       ================================================================== */

    /**
     * Which of the three a goal is. This decides the arithmetic, and nothing
     * else does:
     *
     *   milestone    Mijlpaal — the BEST result so far. 60, 75, 85, 70 is 85:
     *                never 290 (that would be adding), never 70 (that would
     *                be the latest), and a worse result never lowers it.
     *   streak       Streak — consecutive successful days. A day that does
     *                not count ends the run; thirty good days spread over two
     *                months are not a thirty-day streak.
     *   accumulate   Optellen — every contribution added to a total, or every
     *                qualifying day counted.
     *
     * The four old names are read as migration 007 maps them, so a database
     * that has not had 007 yet still gets the right calculation.
     */
    function goal_kind(array $goal): string
    {
        $type = (string) ($goal['goal_type'] ?? 'milestone');

        switch ($type) {
            case 'milestone':
            case 'streak':
            case 'accumulate':
                return $type;

            case 'habit':
                return 'accumulate';

            case 'target_value':
                /* A Doelwaarde read from a source that was already being summed
                   — "50 km this month" — was always a total. */
                if (($goal['tracking_mode'] ?? 'manual') === 'auto') {
                    $source = goal_source_find($goal['source_kind'] ?? null, $goal['source_key'] ?? null);

                    if ($source !== null && (($goal['source_kind'] ?? '') === 'workout' || $source['daily'])) {
                        return 'accumulate';
                    }
                }

                return 'milestone';
        }

        return 'milestone';     // 'event', and anything unexpected
    }

    /** Whether goal_type speaks the new vocabulary yet (migration 007, step 3). */
    function goal_types_renamed(): bool
    {
        static $renamed = null;

        if ($renamed !== null) {
            return $renamed;
        }

        if (!db_available()) {
            return $renamed = false;
        }

        $column = db_value(
            "SELECT column_type FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'goals' AND column_name = 'goal_type'"
        );

        return $renamed = is_string($column) && str_contains($column, "'accumulate'");
    }

    /** Whether the stored-progress columns exist (migration 007, step 4). */
    function goal_progress_stored(): bool
    {
        static $stored = null;

        if ($stored !== null) {
            return $stored;
        }

        if (!db_available()) {
            return $stored = false;
        }

        return $stored = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'goals'
                AND column_name IN ('best_value','total_value','streak_current','streak_best','progress_pct','progress_at')"
        ) === 6;
    }

    /**
     * The goal_type to write, in whichever vocabulary the column has.
     *
     * Until 007 is imported the column only knows the old four, and writing a
     * new name into it fails outright — which is how an un-imported migration
     * would have broken creating a goal. So the old name that maps back to the
     * same type is written instead, and goal_kind() reads it correctly either
     * way. 'event' rather than 'target_value' for a milestone, because
     * target_value can read back as a total.
     */
    function goal_kind_to_db(string $kind): string
    {
        if (goal_types_renamed()) {
            return in_array($kind, ['milestone', 'streak', 'accumulate'], true) ? $kind : 'milestone';
        }

        return match ($kind) {
            'streak'     => 'streak',
            'accumulate' => 'habit',
            default      => 'event',
        };
    }

    /** "dagen", in whatever form somebody typed it. */
    function goal_unit_is_days(?string $unit): bool
    {
        return in_array(mb_strtolower(trim((string) $unit)), ['dag', 'dagen', 'day', 'days'], true);
    }

    /**
     * Whether a goal is counted in days rather than in amounts.
     *
     * A Streak always is. An Optellen goal is when a day has to reach
     * something to count ("days with at least 10.000 steps"), or, kept by
     * hand, when it is counted in days ("30 healthy days this month").
     */
    function goal_counts_days(array $goal): bool
    {
        $kind = goal_kind($goal);

        if ($kind === 'streak') {
            return true;
        }

        if ($kind !== 'accumulate') {
            return false;
        }

        if (($goal['tracking_mode'] ?? 'manual') === 'auto' && goal_sources_available()) {
            return $goal['daily_target'] !== null;
        }

        return goal_unit_is_days($goal['target_unit'] ?? null);
    }

    function goal_is_manual(array $goal): bool
    {
        return !goal_sources_available() || ($goal['tracking_mode'] ?? 'manual') !== 'auto';
    }

    /**
     * The days a goal is measured over: from its start to the earliest of
     * today, its deadline, and — once finished — the day it was finished.
     *
     * The last one matters most for a Streak. A goal completed at seven days
     * in a row is a completed seven-day streak; a missed day next week must
     * not quietly drop it back to 1 of 7.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    function goal_window(array $goal, DateTimeImmutable $today): array
    {
        $start = new DateTimeImmutable((string) $goal['start_date']);
        $end   = $today;

        if (!empty($goal['end_date'])) {
            $deadline = new DateTimeImmutable((string) $goal['end_date']);
            if ($deadline < $end) {
                $end = $deadline;
            }
        }

        if (($goal['status'] ?? '') === 'completed' && !empty($goal['completed_at'])) {
            $done = new DateTimeImmutable(substr((string) $goal['completed_at'], 0, 10));
            if ($done < $end) {
                $end = $done;
            }
        }

        return [$start, $end < $start ? $start : $end];
    }

    /**
     * One figure per day inside the goal's window, oldest first. A day with
     * nothing recorded is absent — never a zero.
     *
     * Kept by hand, these are the goal's own rows, which each type wrote its
     * own way (see goal_record_entry). Read from health data, it is the
     * source's figure for the day — except that a Mijlpaal takes the day's
     * BEST reading rather than its last, because two weigh-ins on one day are
     * two results and the better one is the one that counts.
     *
     * @return array<string, float>
     */
    function goal_day_values(int $userId, array $goal, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        if (goal_is_manual($goal)) {
            $out = [];

            foreach (db_all(
                'SELECT p.recorded_on AS d, p.current_value AS v
                   FROM goal_progress p
                   JOIN goals g ON g.id = p.goal_id
                  WHERE p.goal_id = ? AND g.user_id = ? AND p.recorded_on BETWEEN ? AND ?
               ORDER BY p.recorded_on',
                [(int) $goal['id'], $userId, $f, $t]
            ) as $row) {
                /* A day-counting row means "done" whatever it holds — rows
                   written before 007 hold a running count (1, 2, 3), and
                   adding those up would turn three days into six. */
                if (goal_counts_days($goal)) {
                    $out[(string) $row['d']] = 1.0;
                } elseif ($row['v'] !== null) {
                    $out[(string) $row['d']] = (float) $row['v'];
                }
            }

            return $out;
        }

        $pick = goal_kind($goal) === 'milestone'
            ? (($goal['direction'] ?? 'increase') === 'decrease' ? 'min' : 'max')
            : null;

        $kind = (string) $goal['source_kind'];
        $key  = (string) $goal['source_key'];
        $days = goal_source_by_day($userId, $kind, $key, $f, $t, $pick);

        /* A day without a workout is a day with zero workouts — a fact, not a
           gap — so a training streak really does break on it. */
        if ($kind === 'workout' && $key === 'sessions') {
            for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
                $days[$d->format('Y-m-d')] ??= 0.0;
            }
            ksort($days);
        }

        return $days;
    }

    /**
     * Whether one day cleared its daily threshold.
     *
     * 10.000 steps is a floor and 2.000 kcal a ceiling; direction says which.
     * Without a threshold (only goals made before one was asked for), any
     * recorded activity counts.
     */
    function goal_day_met(array $goal, float $value, ?float $threshold): bool
    {
        if ($threshold === null) {
            return $value > 0;
        }

        return ($goal['direction'] ?? 'increase') === 'decrease'
            ? $value <= $threshold
            : $value >= $threshold;
    }

    /**
     * Each day of a day-counting goal, from its start to its window's end.
     *
     *   met      the day counted
     *   missed   something was recorded and it fell short
     *   unknown  nothing was recorded. Never drawn or counted as a failure —
     *            but it is not a success either, so it does end a streak
     *   pending  today, not yet counted: a low step count at nine in the
     *            morning is not a missed day, it is a day still going
     *
     * @return array<string, string>  date => state, oldest first
     */
    function goal_day_states(int $userId, array $goal, DateTimeImmutable $today): array
    {
        [$from, $to] = goal_window($goal, $today);

        $values    = goal_day_values($userId, $goal, $from, $to);
        $manual    = goal_is_manual($goal);
        $threshold = $goal['daily_target'] === null ? null : (float) $goal['daily_target'];
        $todayKey  = $today->format('Y-m-d');
        $states    = [];

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');

            if (!array_key_exists($date, $values)) {
                $state = 'unknown';
            } elseif ($manual) {
                $state = 'met';               // a hand-kept day exists only if it was done
            } else {
                $state = goal_day_met($goal, $values[$date], $threshold) ? 'met' : 'missed';
            }

            if ($date === $todayKey && $state !== 'met') {
                $state = 'pending';
            }

            $states[$date] = $state;
        }

        return $states;
    }

    /* ==================================================================
       WHERE A GOAL STANDS — one calculation per type
       ================================================================== */

    /**
     * Mijlpaal: the best result reached.
     *
     * Higher-is-better takes the maximum, lower-is-better the minimum, and
     * nothing is ever added. The percentage is the best against the target —
     * 85 of 100 kg is 85% — except when lower is better, where the distance
     * covered from the starting point is the only honest measure: 84 kg on
     * the way from 90 to 80 is 60%, not 80 / 84 = 95%.
     */
    function goal_compute_milestone(int $userId, array $goal, DateTimeImmutable $today): array
    {
        [$from, $to] = goal_window($goal, $today);

        $values   = goal_day_values($userId, $goal, $from, $to);
        $target   = $goal['target_value'] === null ? null : (float) $goal['target_value'];
        $decrease = ($goal['direction'] ?? 'increase') === 'decrease';

        $out = ['best' => null, 'latest' => null, 'start' => null, 'current' => null, 'percent' => null];

        if ($values === []) {
            return $out;          // nothing achieved yet is not "0 kg"
        }

        $best   = $decrease ? min($values) : max($values);
        $latest = $values[array_key_last($values)];

        /* Where a lower-is-better goal started: the baseline read when it was
           made, or else the first result recorded for it. */
        $start = ($goal['start_value'] ?? null) !== null
            ? (float) $goal['start_value']
            : $values[array_key_first($values)];

        $percent = null;

        if ($target !== null) {
            if ($decrease ? $best <= $target : $best >= $target) {
                $percent = 100.0;
            } elseif ($decrease) {
                $percent = $start > $target
                    ? round(max(0.0, min(100.0, ($start - $best) / ($start - $target) * 100)), 2)
                    : null;
            } elseif ($target > 0) {
                $percent = round(max(0.0, min(100.0, $best / $target * 100)), 2);
            }
        }

        return [
            'best'    => $best,
            'latest'  => $latest,
            'start'   => $start,
            'current' => $best,
            'percent' => $percent,
        ];
    }

    /**
     * Streak: the run of successful days still going.
     *
     * Walks the days in order. A met day extends the run; anything else —
     * a miss, or a day with nothing recorded — ends it. Today does neither
     * until it is met, so a streak is not broken at breakfast.
     *
     * The percentage is the current run against the target. The longest run
     * decides completion: a seven-day streak that happened is achieved, even
     * if the day the app was next opened came after it had broken.
     */
    function goal_compute_streak(int $userId, array $goal, DateTimeImmutable $today): array
    {
        $states  = goal_day_states($userId, $goal, $today);
        $target  = $goal['target_value'] === null ? null : (float) $goal['target_value'];
        $run     = 0;
        $longest = 0;
        $seen    = false;

        foreach ($states as $state) {
            if ($state === 'met') {
                $run++;
                $longest = max($longest, $run);
                $seen = true;
                continue;
            }

            if ($state === 'pending') {
                continue;         // today, still going
            }

            if ($state === 'missed') {
                $seen = true;
            }

            $run = 0;
        }

        if (!$seen) {
            /* Nothing recorded at all: no streak, rather than a streak of 0. */
            return ['streak' => null, 'longest' => null, 'current' => null, 'percent' => null, 'states' => $states];
        }

        $percent = null;
        if ($target !== null && $target > 0) {
            $percent = $longest >= $target
                ? 100.0
                : round(min(100.0, $run / $target * 100), 2);
        }

        return [
            'streak'  => $run,
            'longest' => $longest,
            'current' => (float) $run,
            'percent' => $percent,
            'states'  => $states,
        ];
    }

    /**
     * Optellen: everything added together.
     *
     * Amounts are summed across the window — 8.000 + 11.000 + 9.000 steps is
     * 28.000. A day-counting goal adds one for every day that counted. Only
     * the goal's own period counts; steps from before it began, or after its
     * deadline, are not part of "this month".
     */
    function goal_compute_accumulate(int $userId, array $goal, DateTimeImmutable $today): array
    {
        $target = $goal['target_value'] === null ? null : (float) $goal['target_value'];
        $states = null;

        if (goal_counts_days($goal)) {
            $states = goal_day_states($userId, $goal, $today);
            $met    = 0;
            $known  = 0;

            foreach ($states as $state) {
                if ($state === 'met') {
                    $met++;
                }
                if ($state === 'met' || $state === 'missed') {
                    $known++;
                }
            }

            /* No day recorded at all is no total, not a total of zero. */
            $total = $known === 0 ? null : (float) $met;
        } else {
            [$from, $to] = goal_window($goal, $today);
            $values = goal_day_values($userId, $goal, $from, $to);
            $total  = $values === [] ? null : array_sum($values);
        }

        $percent = ($total === null || $target === null || $target <= 0)
            ? null
            : round(min(100.0, $total / $target * 100), 2);

        return ['total' => $total, 'current' => $total, 'percent' => $percent, 'states' => $states];
    }

    /**
     * Everything the page needs about one goal, from real rows, by type.
     *
     * @return array{kind: string, current: ?float, percent: ?float, best: ?float, latest: ?float,
     *               start: ?float, total: ?float, streak: ?int, longest: ?int, counts_days: bool,
     *               states: ?array, days: list<array>, met: int, days_total: int, mode: string}
     */
    function goal_progress_compute(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $kind  = goal_kind($goal);

        $base = [
            'kind' => $kind, 'current' => null, 'percent' => null,
            'best' => null, 'latest' => null, 'start' => null,
            'total' => null, 'streak' => null, 'longest' => null,
            'counts_days' => goal_counts_days($goal), 'states' => null,
            'days' => [], 'met' => 0, 'days_total' => 0,
            'mode' => goal_is_manual($goal) ? 'manual' : 'auto',
        ];

        /* An automatic goal pointed at a source this app cannot read has
           nothing to report, and says so rather than guessing. */
        if (!goal_is_manual($goal)
            && goal_source_find($goal['source_kind'] ?? null, $goal['source_key'] ?? null) === null) {
            return $base;
        }

        $result = match ($kind) {
            'streak'     => goal_compute_streak($userId, $goal, $today),
            'accumulate' => goal_compute_accumulate($userId, $goal, $today),
            default      => goal_compute_milestone($userId, $goal, $today),
        };

        $progress = array_replace($base, $result);

        if ($progress['counts_days']) {
            $progress['days'] = goal_days($userId, $goal, $today, $progress['states']);

            foreach ($progress['days'] as $day) {
                if ($day['state'] === 'met') {
                    $progress['met']++;
                }
            }

            $start = new DateTimeImmutable((string) $goal['start_date']);
            $end   = empty($goal['end_date']) ? $today : new DateTimeImmutable((string) $goal['end_date']);
            $progress['days_total'] = max(1, (int) $start->diff($end)->days + 1);
        }

        return $progress;
    }

    /**
     * The calendar: each day of a day-counting goal, squared off to whole
     * weeks starting on Monday so a column is always the same weekday.
     *
     * A goal of up to six weeks shows all of it. A longer one shows the six
     * weeks up to the end of this week — not the six before its deadline,
     * which for a half-year goal would be six weeks that have not happened.
     * The totals always count the whole period; the card says when the
     * calendar shows less.
     *
     * @return list<array{date: string, state: string}>
     */
    function goal_days(int $userId, array $goal, ?DateTimeImmutable $today = null, ?array $states = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $states = $states ?? goal_day_states($userId, $goal, $today);

        $start = new DateTimeImmutable((string) $goal['start_date']);
        $end   = empty($goal['end_date']) ? $today : new DateTimeImmutable((string) $goal['end_date']);

        if ((int) $start->diff($end)->days >= GOAL_CALENDAR_DAYS) {
            $sunday = $today->modify('sunday this week');
            $end    = $sunday < $end ? $sunday : $end;
            $start  = max($start, $end->modify('-' . (GOAL_CALENDAR_DAYS - 1) . ' day'));
        }

        $first = $start->modify('-' . ((int) $start->format('N') - 1) . ' day');
        $days  = [];

        for ($d = $first; $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');

            $days[] = [
                'date'  => $date,
                'state' => $d < $start ? 'before' : ($states[$date] ?? ($d > $today ? 'future' : 'unknown')),
            ];
        }

        return $days;
    }

    /* ==================================================================
       WRITING WHAT THE PERSON DID
       ================================================================== */

    /**
     * Records a hand-kept goal's progress, the way its type needs it.
     *
     * One row per goal per day, so each type decides what a second entry on
     * the same day does:
     *
     *   Mijlpaal   keeps the better of the two. 85 then 70 on one day is 85 —
     *              a worse attempt never replaces a better one.
     *   Optellen   adds it. 8.000 then 3.000 steps is 11.000.
     *   Streak, or Optellen in days
     *              marks the day done; ticking twice is still one day.
     *
     * @return array{ok: bool, error: ?string}
     */
    function goal_record_entry(int $userId, array $goal, ?float $value, ?DateTimeImmutable $on = null): array
    {
        if ((int) $goal['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Onbekend doel.'];
        }

        $day    = ($on ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $goalId = (int) $goal['id'];

        if (goal_counts_days($goal)) {
            db_run(
                'INSERT INTO goal_progress (goal_id, recorded_on, current_value) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE computed_at = NOW()',
                [$goalId, $day]
            );

            return ['ok' => true, 'error' => null];
        }

        if ($value === null) {
            return ['ok' => false, 'error' => 'Vul een waarde in.'];
        }

        if (goal_kind($goal) === 'accumulate') {
            if ($value <= 0) {
                return ['ok' => false, 'error' => 'Vul in hoeveel er bij komt.'];
            }

            db_run(
                'INSERT INTO goal_progress (goal_id, recorded_on, current_value) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE current_value = COALESCE(current_value, 0) + VALUES(current_value),
                                         computed_at = NOW()',
                [$goalId, $day, $value]
            );

            return ['ok' => true, 'error' => null];
        }

        $keep = ($goal['direction'] ?? 'increase') === 'decrease' ? 'LEAST' : 'GREATEST';

        db_run(
            'INSERT INTO goal_progress (goal_id, recorded_on, current_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE current_value = ' . $keep . '(COALESCE(current_value, VALUES(current_value)), VALUES(current_value)),
                                     computed_at = NOW()',
            [$goalId, $day, $value]
        );

        return ['ok' => true, 'error' => null];
    }

    /* ==================================================================
       KEEPING IT UP TO DATE
       ================================================================== */

    /**
     * Recomputes one goal, stores where it stands, and finishes it if it is
     * finished.
     *
     * Called after anything that can change the answer: a page render, a
     * manual entry, a health import. That is what makes an automatic goal
     * immediate — new steps arrive and the bar has already moved by the time
     * the page is drawn, with nobody pressing anything.
     */
    function goal_refresh(int $userId, int $goalId, ?DateTimeImmutable $today = null): ?array
    {
        $goal = goal_get($userId, $goalId);

        return $goal === null ? null : goal_refresh_row($userId, $goal, $today);
    }

    /**
     * Where a lower-is-better Mijlpaal started, read once when it first can
     * be.
     *
     * "Get to 80 kg" is measured from where you were, and plenty of goals are
     * made before the first weigh-in. The first reading after the goal exists
     * becomes the starting point — written once and never revised, because
     * the second reading is progress, not a new beginning.
     */
    function goal_capture_baseline(int $userId, array $goal, ?float $current): void
    {
        if (!goal_sources_available()
            || goal_is_manual($goal)
            || goal_kind($goal) !== 'milestone'
            || ($goal['start_value'] ?? null) !== null
            || $current === null) {
            return;
        }

        db_run(
            'UPDATE goals SET start_value = ? WHERE id = ? AND user_id = ? AND start_value IS NULL',
            [$current, (int) $goal['id'], $userId]
        );
    }

    /**
     * Stores the figure each type is about in the column that means it.
     *
     * Only the goal's own type's column is filled; the others are cleared, so
     * phpMyAdmin never shows a streak length on a Mijlpaal.
     */
    function goal_store_progress(int $userId, array $goal, array $progress): void
    {
        if (!goal_progress_stored()) {
            return;
        }

        $kind = $progress['kind'];

        db_run(
            'UPDATE goals
                SET best_value = ?, total_value = ?, streak_current = ?, streak_best = ?,
                    progress_pct = ?, progress_at = NOW()
              WHERE id = ? AND user_id = ?',
            [
                $kind === 'milestone'  ? $progress['best']    : null,
                $kind === 'accumulate' ? $progress['total']   : null,
                $kind === 'streak'     ? $progress['streak']  : null,
                $kind === 'streak'     ? $progress['longest'] : null,
                $progress['percent'],
                (int) $goal['id'],
                $userId,
            ]
        );
    }

    /** The same, when the row is already in hand. */
    function goal_refresh_row(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');

        if (($goal['start_value'] ?? null) === null
            && !goal_is_manual($goal)
            && goal_kind($goal) === 'milestone'
            && $goal['status'] !== 'completed') {
            goal_capture_baseline(
                $userId,
                $goal,
                goal_source_latest($userId, (string) $goal['source_kind'], (string) $goal['source_key'])
            );

            $goal = goal_get($userId, (int) $goal['id']) ?? $goal;
        }

        $progress = goal_progress_compute($userId, $goal, $today);

        goal_store_progress($userId, $goal, $progress);

        /* A finished goal is measured up to the day it finished (goal_window),
           so what is stored above is the figure it finished on. */
        if ($goal['status'] === 'completed') {
            return $progress;
        }

        /* The history snapshot, for a goal read from health data. A hand-kept
           goal's rows are its data and are never overwritten with a summary —
           only told where the goal stood after that day's entry, for the
           Recent list. */
        if (!goal_is_manual($goal)) {
            if ($progress['percent'] !== null) {
                goal_record_progress($userId, (int) $goal['id'], $progress['current'], $progress['percent']);
            }
        } else {
            db_run(
                'UPDATE goal_progress p
                   JOIN goals g ON g.id = p.goal_id
                    SET p.percent_complete = ?
                  WHERE p.goal_id = ? AND g.user_id = ? AND p.recorded_on = ?',
                [$progress['percent'], (int) $goal['id'], $userId, $today->format('Y-m-d')]
            );
        }

        /* Reaching the target finishes it, without anybody having to say so. */
        if ($progress['percent'] !== null && $progress['percent'] >= 100.0 && $goal['status'] === 'active') {
            goal_complete($userId, (int) $goal['id']);
        }

        return $progress;
    }

    /**
     * Marks a goal achieved, and records when.
     *
     * completed_at rather than updated_at, which moves whenever anything about
     * the row changes — rename a finished goal and it would claim to have been
     * achieved today.
     */
    function goal_complete(int $userId, int $goalId): bool
    {
        if (!goal_sources_available()) {
            return goal_set_status($userId, $goalId, 'completed');
        }

        $statement = db_run(
            "UPDATE goals SET status = 'completed', completed_at = COALESCE(completed_at, NOW())
              WHERE id = ? AND user_id = ? AND status <> 'completed'",
            [$goalId, $userId]
        );

        if ($statement === null || $statement->rowCount() === 0) {
            return false;
        }

        goal_ensure_primary($userId);

        return true;
    }

    /**
     * Recomputes every active automatic goal for one user.
     *
     * includes/health-import.php calls this after a sync, so a phone uploading
     * last night's sleep has already moved the sleep streak before anybody
     * looks. Returns how many goals it finished.
     */
    function goal_refresh_all(int $userId, ?DateTimeImmutable $today = null): int
    {
        if (!goal_sources_available()) {
            return 0;
        }

        $completed = 0;

        foreach (goals_for_user($userId, 'active') as $goal) {
            if (goal_is_manual($goal)) {
                continue;               // a hand-kept goal is nobody else's to move
            }

            goal_refresh_row($userId, $goal, $today);

            if (goal_get($userId, (int) $goal['id'])['status'] === 'completed') {
                $completed++;
            }
        }

        return $completed;
    }

    /* ==================================================================
       SLEEP, WHICH IS STORED AS NIGHTS
       ================================================================== */

    /** The two sleep codes that live in sleep_sessions rather than readings. */
    function goal_is_sleep_key(string $key): bool
    {
        return $key === 'sleep_duration' || $key === 'sleep_efficiency';
    }

    /**
     * Sleep per night: the night of each date by the one rule the Slaap
     * card, the sleep score and the sleep points use (health_nights_on() in
     * health-signals.php) — filed under the morning it ended, the main sleep
     * only. A nap does not add to it and a night recorded by two devices
     * counts once; a night broken by getting up for a while is still one.
     *
     * @return array<string, float>  night => value, only nights with a session
     */
    function goal_sleep_by_day(int $userId, string $key, string $from, string $to): array
    {
        $out = [];

        foreach (health_nights_on($userId, $from, $to) as $date => $night) {
            $value = $key === 'sleep_efficiency' ? $night['efficiency'] : $night['minutes'];

            if ($value !== null) {
                $out[(string) $date] = (float) $value;
            }
        }

        return $out;
    }

    /* ==================================================================
       A SOURCE, DAY BY DAY
       ================================================================== */

    /**
     * One figure per day between two dates, in one query rather than one per
     * day — a month of steps is thirty readings, and the chart draws on every
     * render of the detail screen.
     *
     * Only days with data come back. A missing key is a day nothing was
     * recorded, never a zero; the one exception is a count of workouts, where
     * a day without one really is zero, and that is the caller's to fill in.
     *
     * `$pick` ('max' or 'min') asks for the day's best single result instead
     * of its usual figure — what a Mijlpaal needs. Two weigh-ins on one day
     * are two results and the better one counts; the longest session is the
     * day's result for "train 60 minutes". A figure that only exists for the
     * whole day — steps, an average heart rate — is the day's result as it
     * is, so there is nothing to pick from.
     *
     * @return array<string, float>  date => value, oldest first
     */
    function goal_source_by_day(int $userId, string $kind, string $key, string $from, string $to, ?string $pick = null): array
    {
        $pick = in_array($pick, ['max', 'min'], true) ? strtoupper($pick) : null;

        $out = [];

        switch ($kind) {
            case 'metric':
                $type = db_one('SELECT id, aggregation FROM health_metric_types WHERE code = ?', [$key]);

                if ($type !== null) {
                    /* The catalogue decides how a day rolls up, exactly as
                       health_daily_metric() does, so the chart and the day
                       calendar can never disagree about what a day was. */
                    $aggregate = match (true) {
                        $type['aggregation'] === 'sum' => 'SUM(value)',
                        $type['aggregation'] === 'avg' => 'AVG(value)',
                        $type['aggregation'] === 'min' => 'MIN(value)',
                        $type['aggregation'] === 'max' => 'MAX(value)',
                        $pick !== null                 => $pick . '(value)',
                        default => 'SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY recorded_at DESC, id DESC), ",", 1)',
                    };

                    foreach (db_all(
                        'SELECT recorded_on AS d, ' . $aggregate . ' AS v FROM health_metrics
                          WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ?
                       GROUP BY recorded_on',
                        [$userId, (int) $type['id'], $from, $to]
                    ) as $row) {
                        if ($row['v'] !== null) {
                            $out[(string) $row['d']] = (float) $row['v'];
                        }
                    }
                }

                /* Sleep nights win over readings for the same date. */
                if (goal_is_sleep_key($key)) {
                    $out = goal_sleep_by_day($userId, $key, $from, $to) + $out;
                }
                break;

            case 'measurement':
                $reading = $pick !== null
                    ? $pick . '(value)'
                    : 'SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY measured_at DESC, id DESC), ",", 1)';

                foreach (db_all(
                    'SELECT DATE(measured_at) AS d, ' . $reading . ' AS v
                       FROM user_measurements
                      WHERE user_id = ? AND measurement_type = ? AND DATE(measured_at) BETWEEN ? AND ?
                   GROUP BY DATE(measured_at)',
                    [$userId, $key, $from, $to]
                ) as $row) {
                    $out[(string) $row['d']] = (float) $row['v'];
                }
                break;

            case 'workout':
                $column = match (true) {
                    $key === 'sessions' => 'COUNT(*)',
                    $pick !== null      => $pick . '(duration_seconds) / 60',
                    default             => 'SUM(duration_seconds) / 60',
                };

                foreach (db_all(
                    'SELECT DATE(started_at) AS d, ' . $column . ' AS v FROM workouts
                      WHERE user_id = ? AND DATE(started_at) BETWEEN ? AND ?
                   GROUP BY DATE(started_at)',
                    [$userId, $from, $to]
                ) as $row) {
                    if ($row['v'] !== null) {
                        $out[(string) $row['d']] = (float) $row['v'];
                    }
                }
                break;
        }

        ksort($out);

        return $out;
    }

    /* ==================================================================
       THE SERIES THE CHART DRAWS
       ================================================================== */

    /**
     * Every real value behind a goal, dated, oldest first, in the terms of its
     * type — so the line is the same story the percentage tells.
     *
     *   best    Mijlpaal: each day's result. The chart marks the best of
     *           them, because that is the one the goal is measured by; a worse
     *           day after it is drawn, and changes nothing.
     *   streak  Streak: how long the run was at the end of each day. It falls
     *           to zero on a day that did not count — that is not a made-up
     *           point, it is what happened to the streak.
     *   total   Optellen: the running total since the start.
     *   count   Optellen in days: the running number of days that counted.
     *
     * No point is ever made up. A day without data is absent, not zero, and a
     * goal with no data at all has an empty series, which the card shows as
     * its existing empty state. Today, while it is still going, is left off
     * a Streak's line rather than drawn as the end of it.
     *
     * @return array{points: list<array{date: string, value: float}>, mode: string, target: ?float,
     *               breaks: bool, best: ?string}
     */
    function goal_series(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $kind   = goal_kind($goal);
        $target = $goal['target_value'] === null ? null : (float) $goal['target_value'];

        $mode = match (true) {
            $kind === 'streak'      => 'streak',
            goal_counts_days($goal) => 'count',
            $kind === 'accumulate'  => 'total',
            default                 => 'best',
        };

        $out = ['points' => [], 'mode' => $mode, 'target' => $target, 'breaks' => false, 'best' => null];

        if (!goal_is_manual($goal)
            && goal_source_find($goal['source_kind'] ?? null, $goal['source_key'] ?? null) === null) {
            return $out;
        }

        if ($mode === 'streak' || $mode === 'count') {
            $run     = 0;
            $count   = 0;
            $started = false;

            foreach (goal_day_states($userId, $goal, $today) as $date => $state) {
                if ($state === 'pending') {
                    continue;
                }

                if ($state === 'met' || $state === 'missed') {
                    $started = true;
                }

                if (!$started) {
                    continue;             // before anything was recorded
                }

                if ($mode === 'count') {
                    if ($state === 'unknown') {
                        continue;         // a gap, not a day that added nothing
                    }

                    $count += $state === 'met' ? 1 : 0;
                    $out['points'][] = ['date' => $date, 'value' => (float) $count];
                    continue;
                }

                $run = $state === 'met' ? $run + 1 : 0;
                $out['points'][] = ['date' => $date, 'value' => (float) $run];
            }

            return $out;
        }

        [$from, $to] = goal_window($goal, $today);
        $values = goal_day_values($userId, $goal, $from, $to);

        if ($mode === 'total') {
            /* The line starts at the first day with anything in it — not at an
               invented zero on the goal's first day. */
            $running = 0.0;

            foreach ($values as $date => $value) {
                $running += $value;
                $out['points'][] = ['date' => $date, 'value' => $running];
            }

            return $out;
        }

        $decrease = ($goal['direction'] ?? 'increase') === 'decrease';
        $best     = null;

        foreach ($values as $date => $value) {
            $out['points'][] = ['date' => $date, 'value' => $value];

            /* The first day it was reached, if it was reached twice. */
            if ($best === null || ($decrease ? $value < $values[$best] : $value > $values[$best])) {
                $best = $date;
            }
        }

        $out['best'] = $best;

        return $out;
    }

}

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
require_once __DIR__ . '/goals.php';

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

    /* ==================================================================
       READING ONE USER'S OWN DATA
       ================================================================== */

    /**
     * One day's figure for a source, or null when that day has no data.
     *
     * Every query filters on the user id passed in. A goal id in a request can
     * name a goal, never somebody else's rows behind it.
     */
    function goal_source_day(int $userId, string $kind, string $key, string $date): ?float
    {
        switch ($kind) {
            case 'metric':
                return health_daily_metric($userId, $key, $date);

            case 'measurement':
                $value = db_value(
                    'SELECT value FROM user_measurements
                      WHERE user_id = ? AND measurement_type = ? AND DATE(measured_at) = ?
                   ORDER BY measured_at DESC LIMIT 1',
                    [$userId, $key, $date]
                );

                return $value === null ? null : (float) $value;

            case 'workout':
                $column = $key === 'sessions' ? 'COUNT(*)' : 'SUM(duration_seconds) / 60';

                $value = db_value(
                    'SELECT ' . $column . ' FROM workouts
                      WHERE user_id = ? AND DATE(started_at) = ?',
                    [$userId, $date]
                );

                /* COUNT never returns null, so a day with no workouts comes
                   back as 0 — which here means "no workouts", and that is a
                   fact rather than a gap. SUM does return null, and that is a
                   gap, so the two are kept apart deliberately. */
                if ($key === 'sessions') {
                    return $value === null ? 0.0 : (float) $value;
                }

                return $value === null ? null : (float) $value;
        }

        return null;
    }

    /** The newest figure of any date, for goals that track a standing value. */
    function goal_source_latest(int $userId, string $kind, string $key): ?float
    {
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
                return goal_source_total($userId, $kind, $key, '1970-01-01', date('Y-m-d'));
        }

        return null;
    }

    /** Everything between two dates, added up. Null when there is nothing. */
    function goal_source_total(int $userId, string $kind, string $key, string $from, string $to): ?float
    {
        switch ($kind) {
            case 'metric':
                $value = db_value(
                    'SELECT SUM(m.value) FROM health_metrics m
                       JOIN health_metric_types t ON t.id = m.metric_type_id
                      WHERE m.user_id = ? AND t.code = ? AND m.recorded_on BETWEEN ? AND ?',
                    [$userId, $key, $from, $to]
                );

                return $value === null ? null : (float) $value;

            case 'workout':
                $column = $key === 'sessions' ? 'COUNT(*)' : 'SUM(duration_seconds) / 60';

                $value = db_value(
                    'SELECT ' . $column . ' FROM workouts
                      WHERE user_id = ? AND DATE(started_at) BETWEEN ? AND ?',
                    [$userId, $from, $to]
                );

                return $value === null ? null : (float) $value;

            case 'measurement':
                return goal_source_latest($userId, $kind, $key);
        }

        return null;
    }

    /* ==================================================================
       WHERE A GOAL STANDS
       ================================================================== */

    /**
     * Each day of a repeated goal: met, missed, or not known.
     *
     * "10.000 steps every day for a month" is not one number to reach, it is a
     * number to clear repeatedly, so the honest unit of progress is the day.
     *
     *   met      there is data for that day and it cleared the target
     *   missed   there is data and it did not
     *   unknown  no data — NOT a failure. A phone that was not syncing yet
     *            says nothing about whether somebody walked.
     *   future   the day has not happened
     *
     * @return list<array{date: string, value: ?float, state: string}>
     */
    function goal_days(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $target = $goal['daily_target'] === null ? null : (float) $goal['daily_target'];

        if ($target === null || ($goal['source_kind'] ?? 'manual') === 'manual') {
            return [];
        }

        $today = $today ?? new DateTimeImmutable('today');
        $start = new DateTimeImmutable((string) $goal['start_date']);
        $end   = $goal['end_date'] === null ? $today : new DateTimeImmutable((string) $goal['end_date']);

        /* A year of blocks is not a visual, it is a wall. Long goals show the
           last stretch; the percentage still counts the whole period. */
        if ((int) $start->diff($end)->days > 120) {
            $start = $end->modify('-119 day');
        }

        $days = [];
        $kind = (string) $goal['source_kind'];
        $key  = (string) $goal['source_key'];

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');

            if ($day > $today) {
                $days[] = ['date' => $date, 'value' => null, 'state' => 'future'];
                continue;
            }

            $value = goal_source_day($userId, $kind, $key, $date);

            $days[] = [
                'date'  => $date,
                'value' => $value,
                'state' => $value === null
                    ? 'unknown'
                    : (goal_day_met($goal, $value, $target) ? 'met' : 'missed'),
            ];
        }

        return $days;
    }

    /**
     * Whether one day cleared its target.
     *
     * Direction decides the comparison: 10.000 steps is a floor, 2.000 kcal is
     * a ceiling, and reading one as the other would mark every good day bad.
     */
    function goal_day_met(array $goal, float $value, float $target): bool
    {
        return ($goal['direction'] ?? 'increase') === 'decrease'
            ? $value <= $target
            : $value >= $target;
    }

    /**
     * The percentage, from real values.
     *
     * ---------------------------------------------------------------------
     * THE ONE THAT WAS WRONG
     * ---------------------------------------------------------------------
     * goal_percent() measured a decreasing goal as target / current. For
     * somebody at 82 kg aiming for 75 that is 91%, which reads as nearly
     * finished to a person who has lost nothing at all — and it would have
     * read 91% on the day they created the goal.
     *
     * What matters is how far they have come from where they started, so a
     * decreasing goal needs a baseline. start_value records it when the goal
     * is made. 82 -> 75 with 2 kg lost is 2/7, which is 29%, and on day one it
     * is 0%.
     *
     * Without a baseline there is no honest answer, so it returns null and the
     * card shows the empty state it already knows how to draw. An invented
     * number would be worse than no number.
     */
    function goal_percent_from(array $goal, ?float $current): ?float
    {
        $target = $goal['target_value'] === null ? null : (float) $goal['target_value'];
        $start  = ($goal['start_value'] ?? null) === null ? null : (float) $goal['start_value'];

        if ($target === null || $current === null) {
            return null;
        }

        if (($goal['direction'] ?? 'increase') === 'decrease') {
            if ($start === null || $start <= $target) {
                return null;            // no baseline, or already there when it began
            }

            return round(max(0.0, min(100.0, ($start - $current) / ($start - $target) * 100)), 2);
        }

        /* Rising towards a target from a baseline that was not zero — a bench
           press going 60 -> 100 — is the same shape of sum the other way up.
           Without a baseline it falls back to current / target, which is what
           it has always done and is right when starting from nothing. */
        if ($start !== null && $start < $target) {
            return round(max(0.0, min(100.0, ($current - $start) / ($target - $start) * 100)), 2);
        }

        if ($target == 0.0) {
            return null;
        }

        return round(max(0.0, min(100.0, $current / $target * 100)), 2);
    }

    /**
     * Everything the page needs about one goal, computed from real rows.
     *
     * @return array{current: ?float, percent: ?float, days: list<array>, met: int, total: int, mode: string}
     */
    function goal_progress_compute(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $blank = ['current' => null, 'percent' => null, 'days' => [], 'met' => 0, 'total' => 0, 'mode' => 'manual'];

        if (!goal_sources_available() || ($goal['tracking_mode'] ?? 'manual') !== 'auto') {
            /* Manual: whatever the person last entered, and nothing invented
               around it. */
            $latest  = goal_latest_progress($userId, (int) $goal['id']);
            $current = $latest === null || $latest['current_value'] === null ? null : (float) $latest['current_value'];

            return [
                'current' => $current,
                'percent' => goal_percent_from($goal, $current),
                'days'    => [],
                'met'     => 0,
                'total'   => 0,
                'mode'    => 'manual',
            ];
        }

        $kind = (string) ($goal['source_kind'] ?? 'manual');
        $key  = (string) ($goal['source_key'] ?? '');

        if (goal_source_find($kind, $key) === null) {
            return $blank;              // a source this app cannot read
        }

        /* A repeated goal is measured in days, not in one figure. */
        if ($goal['daily_target'] !== null) {
            $days = goal_days($userId, $goal, $today);
            $met  = 0;

            foreach ($days as $day) {
                if ($day['state'] === 'met') {
                    $met++;
                }
            }

            /* The denominator is the whole period, not the days so far: the
               goal is a month of good days, and being four for four on day
               four is not a finished month. */
            $start = new DateTimeImmutable((string) $goal['start_date']);
            $end   = $goal['end_date'] === null ? $today : new DateTimeImmutable((string) $goal['end_date']);
            $total = (int) $start->diff($end)->days + 1;

            return [
                'current' => (float) $met,
                'percent' => $total > 0 ? round(min(100.0, $met / $total * 100), 2) : null,
                'days'    => $days,
                'met'     => $met,
                'total'   => $total,
                'mode'    => 'daily',
            ];
        }

        /* One figure. A metric that accumulates over a day (steps, energy) is
           summed across the goal's period; a standing value (weight, VO2max)
           is simply the newest reading. The catalogue already knows which is
           which, so this is read rather than guessed. */
        $source     = goal_source_find($kind, $key);
        $cumulative = $kind === 'workout' || ($source['daily'] ?? false);

        $current = $cumulative
            ? goal_source_total(
                $userId,
                $kind,
                $key,
                (string) $goal['start_date'],
                (string) ($goal['end_date'] ?? $today->format('Y-m-d'))
            )
            : goal_source_latest($userId, $kind, $key);

        return [
            'current' => $current,
            'percent' => goal_percent_from($goal, $current),
            'days'    => [],
            'met'     => 0,
            'total'   => 0,
            'mode'    => $cumulative ? 'total' : 'value',
        ];
    }

    /* ==================================================================
       KEEPING IT UP TO DATE
       ================================================================== */

    /**
     * Recomputes one goal, snapshots it, and finishes it if it is finished.
     *
     * Called after anything that can change the answer: a page render, a
     * manual entry, a health import. Cheap enough to run on a render, which is
     * what makes an automatic goal feel immediate — new steps arrive and the
     * bar has already moved by the time the page is drawn, with nobody having
     * to open the goal and press anything.
     *
     * The snapshot in goal_progress is history, not the source of truth: the
     * percentage is always recalculated from the underlying rows, so it can
     * never drift from them. What the snapshot buys is the trend chart, and a
     * record of where a completed goal finished after its data moves on.
     */
    function goal_refresh(int $userId, int $goalId, ?DateTimeImmutable $today = null): ?array
    {
        $goal = goal_get($userId, $goalId);

        if ($goal === null) {
            return null;
        }

        return goal_refresh_row($userId, $goal, $today);
    }

    /**
     * Records where a goal started, the first time there is anything to record.
     *
     * A baseline is usually captured when the goal is made — but plenty of
     * goals are made before the first reading exists. Somebody sets "lose
     * weight to 75 kg" and only steps on the scale afterwards, and without
     * this that goal could never be measured at all: a decreasing goal with no
     * baseline has no honest percentage, so the bar would stay empty forever
     * however much weight came off.
     *
     * So the first reading after the goal exists becomes the starting point,
     * which is what it is. Written once and never revised — the second reading
     * is progress, not a new beginning.
     */
    function goal_capture_baseline(int $userId, array $goal, ?float $current): void
    {
        if (!goal_sources_available()
            || ($goal['tracking_mode'] ?? 'manual') !== 'auto'
            || ($goal['start_value'] ?? null) !== null
            || $goal['daily_target'] !== null
            || $current === null) {
            return;
        }

        /* Only for a standing value. A total accumulating over the goal's own
           period already starts at zero by definition, and writing today's
           total down as "where you started" would erase it. */
        $source = goal_source_find($goal['source_kind'] ?? null, $goal['source_key'] ?? null);

        if ($source === null || $goal['source_kind'] === 'workout' || ($source['daily'] ?? false)) {
            return;
        }

        db_run(
            'UPDATE goals SET start_value = ? WHERE id = ? AND user_id = ? AND start_value IS NULL',
            [$current, (int) $goal['id'], $userId]
        );
    }

    /** The same, when the row is already in hand. */
    function goal_refresh_row(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        /* The baseline has to exist before the percentage is worked out, or
           the first render after the first reading would still show nothing. */
        if (($goal['start_value'] ?? null) === null
            && ($goal['tracking_mode'] ?? 'manual') === 'auto'
            && $goal['status'] !== 'completed') {
            $kind = (string) ($goal['source_kind'] ?? 'manual');
            $key  = (string) ($goal['source_key'] ?? '');

            goal_capture_baseline($userId, $goal, goal_source_latest($userId, $kind, $key));

            $fresh = goal_get($userId, (int) $goal['id']);
            if ($fresh !== null) {
                $goal = $fresh;
            }
        }

        $progress = goal_progress_compute($userId, $goal, $today);

        /* A completed goal keeps the figure it finished on. Recomputing it
           would rewrite history every time the underlying data moved on —
           lose more weight after hitting the target and the goal you already
           achieved should not start reading differently. */
        if ($goal['status'] === 'completed') {
            return $progress;
        }

        if (($goal['tracking_mode'] ?? 'manual') === 'auto' && $progress['percent'] !== null) {
            goal_record_progress($userId, (int) $goal['id'], $progress['current'], $progress['percent']);
        }

        /* Reaching the target finishes it, without anybody having to say so. */
        if ($progress['percent'] !== null
            && $progress['percent'] >= 100.0
            && $goal['status'] === 'active') {
            goal_complete($userId, (int) $goal['id']);
        }

        return $progress;
    }

    /**
     * Marks a goal achieved, and records when.
     *
     * completed_at rather than leaning on updated_at, which moves whenever
     * anything about the row changes — rename a finished goal and it would
     * claim to have been achieved today.
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
     * Recomputes every active goal for one user.
     *
     * This is what makes "new data arrives, the goal moves" true rather than
     * aspirational: includes/health-import.php calls it after a sync, so a
     * phone uploading last night's sleep has already updated the sleep goal
     * before anybody looks at it.
     *
     * Returns how many goals it finished, which the importer reports back.
     */
    function goal_refresh_all(int $userId, ?DateTimeImmutable $today = null): int
    {
        if (!goal_sources_available()) {
            return 0;
        }

        $completed = 0;

        foreach (goals_for_user($userId, 'active') as $goal) {
            if (($goal['tracking_mode'] ?? 'manual') !== 'auto') {
                continue;               // a manual goal is nobody else's to move
            }

            $before = $goal['status'];
            goal_refresh_row($userId, $goal, $today);

            if ($before === 'active' && goal_get($userId, (int) $goal['id'])['status'] === 'completed') {
                $completed++;
            }
        }

        return $completed;
    }
}

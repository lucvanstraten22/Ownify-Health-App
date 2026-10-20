<?php
/**
 * Where a goal stands — PRIVATE, scoped to the owner (docs/GOALS.md).
 *
 * The one place a goal's percentage is worked out. The website and the app
 * both show what this file stored, so the two can never disagree, and
 * nothing else — not a page, not a script, not the app — calculates one.
 *
 * ---------------------------------------------------------------------------
 * THE THREE TYPES
 * ---------------------------------------------------------------------------
 *   Mijlpaal   from the baseline, fixed when the goal was made, to the target,
 *              by the LATEST value: 80 → 100 kg at 90 is 50%, and at 85 a
 *              week later 25%. Never the best ever. 100% finishes it at once.
 *   Streak     daily: each day of the period met or not; the share of all
 *              its days that made it. A missed day is just a missed day.
 *              per period: each week (month, kwartaal, jaar — counted from
 *              the start date) met or not; the share of its periods. Either
 *              finishes when its end date has passed, never before.
 *   Optellen   everything inside the period added up from zero; 100%
 *              finishes it at once.
 *
 * A goal whose end date has passed finishes with what it had. Finished —
 * `completed` at 100%, `ended` below — its percentage is frozen in
 * `final_pct` with the moment in `completed_at`, and never worked out again.
 *
 * ---------------------------------------------------------------------------
 * ONE CALCULATION, TWO KINDS OF INPUT
 * ---------------------------------------------------------------------------
 * goal_day_values() turns either kind of input into the same thing — one
 * figure per day — and the calculations only ever see that: a goal read from
 * health data (Health Connect, Polar, the profile's weigh-ins) and a goal kept
 * by hand on its page (goal_entries) are worked out by the same code.
 *
 * Missing is not zero: a day with nothing recorded is absent, never 0 — except
 * workouts, where a day without one really is a day with none.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/goal-catalogue.php';
require_once __DIR__ . '/goals.php';

/** Six weeks of calendar. Longer goals show their most recent six. */
if (!defined('GOAL_CALENDAR_DAYS')) {
    define('GOAL_CALENDAR_DAYS', 42);
}

if (!function_exists('goal_today')) {

    /** Today, on Ownify's clock (Europe/Amsterdam, includes/bootstrap.php). */
    function goal_today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    /** Whether migration 025 is in: the goals table has its new shape. */
    function goal_model_available(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!db_available()) {
            return $available = false;
        }

        return $available = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'goals' AND column_name = 'category_code'"
        ) === 1;
    }

    /** The measurement a goal row is about — an Eigen gewoonte's rules for none. */
    function goal_measurement_of(array $goal): array
    {
        return goal_measurement($goal['measurement_code'] ?? null)
            ?? goal_measurement(null);
    }

    function goal_is_manual(array $goal): bool
    {
        return ($goal['tracking'] ?? 'manual') !== 'auto';
    }

    function goal_is_finished(array $goal): bool
    {
        return in_array($goal['status'] ?? '', ['completed', 'ended'], true);
    }

    /* ==================================================================
       READING ONE USER'S OWN DATA, DAY BY DAY
       ================================================================== */

    /** The two sleep codes that live in sleep_sessions rather than readings. */
    function goal_is_sleep_key(string $key): bool
    {
        return $key === 'sleep_duration' || $key === 'sleep_efficiency';
    }

    /**
     * Sleep per night, by the one rule the Slaap card, the sleep score and
     * the points use (health_nights_on()): filed under the morning it ended,
     * the main sleep only.
     *
     * @return array<string, float>
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

    /**
     * One figure per day for a source, in one query: the day's total for
     * what builds up over a day (steps — with two apps that counted the same
     * walk counted once, health_metric_totals()), the day's average for a
     * rate (rusthartslag), the day's latest for a reading (a weigh-in), a
     * count of workouts or their minutes. Days without data are absent;
     * workouts fill theirs in with 0 when $fill.
     *
     * @return array<string, float>  date => value, oldest first
     */
    function goal_source_by_day(int $userId, string $kind, string $key, string $from, string $to, bool $fill = false): array
    {
        $out = [];

        if ($from > $to) {
            return $out;
        }

        switch ($kind) {
            case 'metric':
                $type = db_one('SELECT id, aggregation FROM health_metric_types WHERE code = ?', [$key]);

                if ($type !== null && $type['aggregation'] === 'sum') {
                    $out = health_metric_totals($userId, $key, $from, $to);
                } elseif ($type !== null) {
                    $aggregate = match ($type['aggregation']) {
                        'avg'   => 'AVG(value)',
                        'min'   => 'MIN(value)',
                        'max'   => 'MAX(value)',
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
                foreach (db_all(
                    'SELECT DATE(measured_at) AS d,
                            SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY measured_at DESC, id DESC), ",", 1) AS v
                       FROM user_measurements
                      WHERE user_id = ? AND measurement_type = ? AND DATE(measured_at) BETWEEN ? AND ?
                   GROUP BY DATE(measured_at)',
                    [$userId, $key, $from, $to]
                ) as $row) {
                    $out[(string) $row['d']] = (float) $row['v'];
                }
                break;

            case 'workout':
                $column = $key === 'sessions' ? 'COUNT(*)' : 'SUM(duration_seconds) / 60';

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

                /* A day without a workout is a day with none — a fact. */
                if ($fill) {
                    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                        $out[$d->format('Y-m-d')] ??= 0.0;
                    }
                }
                break;
        }

        ksort($out);

        return $out;
    }

    /**
     * What somebody logged on a goal's page, per day: the day's sum, its
     * latest entry, and how many entries it had.
     *
     * @return array<string, array{sum: float, latest: ?float, count: int}>
     */
    function goal_entries_by_day(int $userId, array $goal, string $from, string $to): array
    {
        $out = [];

        foreach (db_all(
            'SELECT e.entry_date AS d, e.value AS v
               FROM goal_entries e
               JOIN goals g ON g.id = e.goal_id
              WHERE e.goal_id = ? AND g.user_id = ? AND e.entry_date BETWEEN ? AND ?
           ORDER BY e.entry_date, e.created_at, e.id',
            [(int) $goal['id'], $userId, $from, $to]
        ) as $row) {
            $day = (string) $row['d'];
            $out[$day] ??= ['sum' => 0.0, 'latest' => null, 'count' => 0];
            $out[$day]['count']++;

            if ($row['v'] !== null) {
                $out[$day]['sum'] += (float) $row['v'];
                $out[$day]['latest'] = (float) $row['v'];
            }
        }

        return $out;
    }

    /**
     * One figure per day for a goal, whichever way it is kept, in its stored
     * unit — the only input the calculations below ever see.
     *
     *   Mijlpaal   the day's measurement (its latest)
     *   Optellen   the day's amount (logged entries added up)
     *   Streak     daily: the day's figure, or 1 for a day ticked off;
     *              per period: how many times that day (workouts, ticks)
     *
     * @return array<string, float>
     */
    function goal_day_values(int $userId, array $goal, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        if ($f > $t) {
            return [];
        }

        $type = (string) $goal['goal_type'];

        if (goal_is_manual($goal)) {
            $out = [];

            foreach (goal_entries_by_day($userId, $goal, $f, $t) as $day => $entry) {
                $out[$day] = match (true) {
                    $type === 'streak'     => 1.0,
                    $type === 'accumulate' => $entry['sum'],
                    default                => $entry['latest'],
                };
            }

            return array_filter($out, static fn ($v): bool => $v !== null);
        }

        $measurement = goal_measurement_of($goal);

        return goal_source_by_day(
            $userId,
            (string) $measurement['source_kind'],
            (string) $measurement['source_key'],
            $f,
            $t,
            $type === 'streak'
        );
    }

    /**
     * Whether a measurement really has data for this person — anything in
     * the last 90 days. Only then is a new goal on it read automatically;
     * otherwise it is kept by hand on its page.
     */
    function goal_user_has_data(int $userId, array $measurement, ?DateTimeImmutable $today = null): bool
    {
        if (!goal_measurement_auto($measurement)) {
            return false;
        }

        $today = $today ?? goal_today();

        return goal_source_by_day(
            $userId,
            (string) $measurement['source_kind'],
            (string) $measurement['source_key'],
            $today->modify('-90 day')->format('Y-m-d'),
            $today->format('Y-m-d')
        ) !== [];
    }

    /** The measurements this person's data can already read, by code. */
    function goal_user_auto_codes(int $userId, ?DateTimeImmutable $today = null): array
    {
        $codes = [];

        foreach (goal_measurements() as $code => $measurement) {
            if (goal_user_has_data($userId, $measurement, $today)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * A Mijlpaal's baseline from health data: the measurement on the start
     * date, else the most recent one before it (up to a year back), else
     * null — then the person types it. For a day's total that is still
     * building (today's steps) the start date is not a measurement yet, so
     * the last whole day before it is.
     *
     * @return ?array{value: float, on: string, source: string}
     */
    function goal_baseline_for(int $userId, array $measurement, DateTimeImmutable $start, ?DateTimeImmutable $today = null): ?array
    {
        if (!goal_measurement_auto($measurement)) {
            return null;
        }

        $today = $today ?? goal_today();
        $kind  = (string) $measurement['source_kind'];
        $key   = (string) $measurement['source_key'];
        $day   = $start->format('Y-m-d');

        $building = !empty($measurement['daily']) && $day >= $today->format('Y-m-d');

        if (!$building) {
            $on = goal_source_by_day($userId, $kind, $key, $day, $day);
            if (isset($on[$day])) {
                return ['value' => $on[$day], 'on' => $day, 'source' => 'start_day'];
            }
        }

        $before = goal_source_by_day($userId, $kind, $key, $start->modify('-365 day')->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d'));

        if ($before === []) {
            return null;
        }

        $last = (string) array_key_last($before);

        return ['value' => $before[$last], 'on' => $last, 'source' => 'before_start'];
    }

    /* ==================================================================
       WHERE A GOAL STANDS
       ================================================================== */

    /**
     * The last day a goal is measured over: today, its end date, or — once
     * finished — the day it finished, whichever comes first.
     */
    function goal_until(array $goal, DateTimeImmutable $today): DateTimeImmutable
    {
        $until = $today;
        $end   = new DateTimeImmutable((string) $goal['end_date']);

        if ($end < $until) {
            $until = $end;
        }

        if (goal_is_finished($goal) && !empty($goal['completed_at'])) {
            $done = new DateTimeImmutable(substr((string) $goal['completed_at'], 0, 10));
            if ($done < $until) {
                $until = $done;
            }
        }

        return $until;
    }

    /**
     * Everything about where one goal stands, from its rows, by its type.
     *
     * @return array{type: string, variant: ?string, percent: float, current: ?float, current_on: ?string,
     *               total: ?float, met: int, counted: int, days: list<array>, periods: list<array>,
     *               values: array<string, float>, until: string}
     */
    function goal_compute(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today   = $today ?? goal_today();
        $start   = new DateTimeImmutable((string) $goal['start_date']);
        $end     = new DateTimeImmutable((string) $goal['end_date']);
        $until   = goal_until($goal, $today);
        $type    = (string) $goal['goal_type'];
        $variant = $type === 'streak' ? (string) $goal['streak_variant'] : null;
        $values  = goal_day_values($userId, $goal, $start, $until);

        $out = [
            'type' => $type, 'variant' => $variant, 'percent' => 0.0,
            'current' => null, 'current_on' => null, 'total' => null,
            'met' => 0, 'counted' => 0, 'days' => [], 'periods' => [],
            'values' => $values, 'until' => $until->format('Y-m-d'),
        ];

        if ($type === 'milestone') {
            return goal_compute_milestone($goal, $values, $today) + $out;
        }

        if ($type === 'accumulate') {
            $total = array_sum($values);

            return ['total' => (float) $total, 'current' => (float) $total,
                    'percent' => goal_share_percent((float) $total, (float) $goal['target_value'])] + $out;
        }

        if ($variant === 'period') {
            return goal_compute_periods($goal, $values, $start, $end, $until, $today) + $out;
        }

        return goal_compute_daily($goal, $values, $start, $end, $until, $today) + $out;
    }

    /**
     * Mijlpaal: the latest value against the fixed baseline and the target.
     * For a day's total that is still building (steps, today), today counts
     * once it has passed the last whole day — so the figure does not drop
     * every morning, and 15.000 steps today finishes it today.
     */
    function goal_compute_milestone(array $goal, array $values, DateTimeImmutable $today): array
    {
        $current = null;
        $on      = null;

        if ($values !== []) {
            $on      = (string) array_key_last($values);
            $current = $values[$on];

            $measurement = goal_measurement_of($goal);
            if (!empty($measurement['daily']) && $on === $today->format('Y-m-d') && count($values) > 1) {
                $dates = array_keys($values);
                $prev  = $dates[count($dates) - 2];
                if ($values[$prev] > $current) {
                    $current = $values[$prev];
                    $on      = $prev;
                }
            }
        }

        return [
            'current'    => $current,
            'current_on' => $on,
            'percent'    => goal_milestone_percent((float) $goal['baseline_value'], (float) $goal['target_value'], $current),
        ];
    }

    /**
     * Streak, every day: each day of the period from its start, met or not.
     *
     *   met      the day made it (or was ticked off)
     *   missed   it was recorded and fell short (or, kept by hand, was not
     *            ticked off)
     *   unknown  nothing was recorded — not met either
     *   pending  today, not made yet: a day is not missed at breakfast
     *
     * The percentage is the met days out of ALL the period's days, so it
     * only grows, and on the end date it is the final share.
     */
    function goal_compute_daily(array $goal, array $values, DateTimeImmutable $start, DateTimeImmutable $end,
                                DateTimeImmutable $until, DateTimeImmutable $today): array
    {
        $manual = goal_is_manual($goal);
        $target = (float) ($goal['target_value'] ?? 0);
        $dir    = (string) ($goal['direction'] ?? 'increase');
        $todayK = $today->format('Y-m-d');
        $days   = [];
        $met    = 0;

        for ($d = $start; $d <= $until; $d = $d->modify('+1 day')) {
            $date  = $d->format('Y-m-d');
            $value = $values[$date] ?? null;

            if ($manual) {
                $state = $value !== null ? 'met' : 'missed';
            } elseif ($value === null) {
                $state = 'unknown';
            } else {
                $state = goal_day_meets($value, $target, $dir) ? 'met' : 'missed';
            }

            if ($date === $todayK && $state !== 'met' && !goal_is_finished($goal)) {
                $state = 'pending';
            }

            if ($state === 'met') {
                $met++;
            }

            $days[] = ['date' => $date, 'state' => $state, 'value' => $manual ? null : $value];
        }

        $total = goal_days_between($start, $end);

        return ['days' => $days, 'met' => $met, 'counted' => $total, 'current' => (float) $met,
                'percent' => goal_share_percent($met, $total)];
    }

    /**
     * Streak, a number of times per period: each week (month…) from the
     * start date on its own. A period is met as soon as its count is reached
     * — even before it is over — and missed only once it is over without.
     * A skipped period (a last one too short to ask anything) counts
     * neither way.
     */
    function goal_compute_periods(array $goal, array $values, DateTimeImmutable $start, DateTimeImmutable $end,
                                  DateTimeImmutable $until, DateTimeImmutable $today): array
    {
        $periods  = goal_periods($start, $end, (string) $goal['period_unit'], (int) $goal['frequency']);
        $finished = goal_is_finished($goal) || $today > $end;
        $out      = [];
        $met      = 0;
        $counted  = 0;

        foreach ($periods as $p) {
            $achieved = 0;
            if ($p['start'] <= $until) {
                $to = $p['end'] < $until ? $p['end'] : $until;
                foreach ($values as $date => $value) {
                    if ($date >= $p['start']->format('Y-m-d') && $date <= $to->format('Y-m-d')) {
                        $achieved += (int) round($value);
                    }
                }
            }

            $over = $finished || $p['end'] < $today;

            $state = match (true) {
                $p['required'] === 0          => 'skipped',
                $achieved >= $p['required']   => 'met',
                $over                         => 'missed',
                default                       => 'pending',
            };

            if ($p['required'] > 0) {
                $counted++;
            }
            if ($state === 'met') {
                $met++;
            }

            $out[] = [
                'index'    => $p['index'],
                'start'    => $p['start']->format('Y-m-d'),
                'end'      => $p['end']->format('Y-m-d'),
                'required' => $p['required'],
                'achieved' => $achieved,
                'state'    => $state,
                'partial'  => $p['days'] < $p['full_days'],
            ];
        }

        return ['periods' => $out, 'met' => $met, 'counted' => $counted, 'current' => (float) $met,
                'percent' => goal_share_percent($met, $counted)];
    }

    /* ==================================================================
       KEEPING IT UP TO DATE
       ================================================================== */

    /**
     * Recomputes one goal, stores where it stands, and finishes it when it
     * is finished. Called after anything that can change the answer: a page
     * render, an entry on its page, a health import. A finished goal is not
     * worked out again: what it finished on stays.
     */
    function goal_refresh_row(int $userId, array $goal, ?DateTimeImmutable $today = null): array
    {
        $today    = $today ?? goal_today();
        $progress = goal_compute($userId, $goal, $today);

        if (goal_is_finished($goal)) {
            $progress['percent'] = (float) $goal['final_pct'];
            return $progress;
        }

        $percent = $progress['percent'];

        db_run(
            'UPDATE goals SET current_value = ?, progress_pct = ?, progress_at = NOW()
              WHERE id = ? AND user_id = ? AND status IN (\'active\',\'paused\')',
            [$progress['current'], $percent, (int) $goal['id'], $userId]
        );

        goal_store_results($userId, $goal, $progress);

        $day = min($today->format('Y-m-d'), (string) $goal['end_date']);
        db_run(
            'INSERT INTO goal_progress (goal_id, recorded_on, current_value, percent_complete)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE current_value = VALUES(current_value),
                                     percent_complete = VALUES(percent_complete),
                                     computed_at = NOW()',
            [(int) $goal['id'], $day, $progress['current'], $percent]
        );

        /* Finished: a Mijlpaal or Optellen the moment it reaches 100%; any
           goal once its end date has passed, with what it had then. */
        $end = new DateTimeImmutable((string) $goal['end_date']);

        if ($goal['goal_type'] !== 'streak' && $percent >= 100.0) {
            goal_finish($userId, (int) $goal['id'], 100.0, 'completed', null);
        } elseif ($today > $end) {
            goal_finish($userId, (int) $goal['id'], $percent, $percent >= 100.0 ? 'completed' : 'ended',
                $end->format('Y-m-d') . ' 23:59:59');
        }

        return $progress;
    }

    /**
     * Finishes a goal: its status, its final percentage, and when — all in
     * one statement, and only once (status still active or paused), so the
     * final percentage can never be overwritten. The primary slot passes on.
     */
    function goal_finish(int $userId, int $goalId, float $percent, string $status, ?string $at): bool
    {
        $percent = round(max(0.0, min(100.0, $percent)), 2);

        $statement = db_run(
            'UPDATE goals SET status = ?, final_pct = ?, progress_pct = ?, completed_at = COALESCE(?, NOW())
              WHERE id = ? AND user_id = ? AND status IN (\'active\',\'paused\')',
            [$status, $percent, $percent, $at, $goalId, $userId]
        );

        if ($statement === null || $statement->rowCount() === 0) {
            return false;
        }

        goal_ensure_primary_after_completion($userId);

        return true;
    }

    /**
     * The days and periods of a streak as last worked out, kept with it
     * (goal_day_results, goal_period_results) — written only where they
     * changed, so a page render writes nothing when nothing moved.
     */
    function goal_store_results(int $userId, array $goal, array $progress): void
    {
        $goalId = (int) $goal['id'];

        if ($progress['days'] !== []) {
            $stored = [];
            foreach (db_all(
                'SELECT r.day, r.state, r.value FROM goal_day_results r JOIN goals g ON g.id = r.goal_id
                  WHERE r.goal_id = ? AND g.user_id = ?',
                [$goalId, $userId]
            ) as $row) {
                $stored[(string) $row['day']] = [(string) $row['state'], $row['value'] === null ? null : (float) $row['value']];
            }

            foreach ($progress['days'] as $day) {
                $was = $stored[$day['date']] ?? null;
                if ($was !== null && $was[0] === $day['state']
                    && ($was[1] === null ? $day['value'] === null : $day['value'] !== null && abs($was[1] - $day['value']) < 1e-4)) {
                    continue;
                }

                db_run(
                    'INSERT INTO goal_day_results (goal_id, day, value, state) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value), state = VALUES(state)',
                    [$goalId, $day['date'], $day['value'], $day['state']]
                );
            }
        }

        if ($progress['periods'] !== []) {
            $stored = [];
            foreach (db_all(
                'SELECT r.period_index, r.achieved, r.state, r.required FROM goal_period_results r JOIN goals g ON g.id = r.goal_id
                  WHERE r.goal_id = ? AND g.user_id = ?',
                [$goalId, $userId]
            ) as $row) {
                $stored[(int) $row['period_index']] = [(int) $row['achieved'], (string) $row['state'], (int) $row['required']];
            }

            foreach ($progress['periods'] as $p) {
                if (($stored[$p['index']] ?? null) === [$p['achieved'], $p['state'], $p['required']]) {
                    continue;
                }

                db_run(
                    'INSERT INTO goal_period_results (goal_id, period_index, starts_on, ends_on, required, achieved, state)
                          VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE starts_on = VALUES(starts_on), ends_on = VALUES(ends_on),
                                             required = VALUES(required), achieved = VALUES(achieved), state = VALUES(state)',
                    [$goalId, $p['index'], $p['start'], $p['end'], $p['required'], min(65535, $p['achieved']), $p['state']]
                );
            }
        }
    }

    function goal_refresh(int $userId, int $goalId, ?DateTimeImmutable $today = null): ?array
    {
        $goal = goal_get($userId, $goalId);

        return $goal === null ? null : goal_refresh_row($userId, $goal, $today);
    }

    /**
     * Recomputes every running goal of one user — after a sync
     * (includes/health-import.php), a rating (api/health/rating.php).
     * Returns how many it finished.
     */
    function goal_refresh_all(int $userId, ?DateTimeImmutable $today = null): int
    {
        if (!goal_model_available()) {
            return 0;
        }

        $finished = 0;

        foreach (db_all("SELECT * FROM goals WHERE user_id = ? AND status IN ('active','paused')", [$userId]) as $goal) {
            goal_refresh_row($userId, $goal, $today);

            if (goal_is_finished(goal_get($userId, (int) $goal['id']) ?? [])) {
                $finished++;
            }
        }

        return $finished;
    }

    /* ==================================================================
       WHAT THE PERSON LOGS ON THE GOAL'S PAGE
       ================================================================== */

    /**
     * One entry on a goal kept by hand, the way its type needs it:
     *
     *   Mijlpaal   a new measurement — the latest counts
     *   Optellen   an amount added to the total
     *   Streak     the day ticked off ($done) — or un-ticked; once a day
     *
     * $value is in the stored unit. $on: the day it is for — within the
     * goal's period and not after today.
     *
     * @return array{ok: bool, error: ?string}
     */
    function goal_record_entry(int $userId, array $goal, ?float $value, ?bool $done, DateTimeImmutable $on, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? goal_today();

        if ((int) $goal['user_id'] !== $userId) {
            return ['ok' => false, 'error' => t('Onbekend doel.')];
        }

        if (!goal_is_manual($goal)) {
            return ['ok' => false, 'error' => t('Dit doel leest zijn voortgang zelf uit je gegevens.')];
        }

        if (goal_is_finished($goal)) {
            return ['ok' => false, 'error' => t('Dit doel is al afgerond.')];
        }

        $day = $on->format('Y-m-d');

        if ($day < (string) $goal['start_date'] || $day > (string) $goal['end_date'] || $on > $today) {
            return ['ok' => false, 'error' => t('Die dag valt buiten de periode van je doel.')];
        }

        $goalId = (int) $goal['id'];

        if ($goal['goal_type'] === 'streak') {
            if ($done === null) {
                return ['ok' => false, 'error' => t('Zeg of het gelukt is.')];
            }

            db_run('DELETE FROM goal_entries WHERE goal_id = ? AND entry_date = ?', [$goalId, $day]);

            if ($done) {
                db_run('INSERT INTO goal_entries (goal_id, entry_date, value) VALUES (?, ?, NULL)', [$goalId, $day]);
            }

            return ['ok' => true, 'error' => null];
        }

        if ($value === null || !is_finite($value)) {
            return ['ok' => false, 'error' => t('Vul een waarde in.')];
        }

        if ($goal['goal_type'] === 'accumulate' && $value <= 0) {
            return ['ok' => false, 'error' => t('Vul in hoeveel er bij komt.')];
        }

        if ($value < 0 || $value > 1e9) {
            return ['ok' => false, 'error' => t('Vul een geldig getal in.')];
        }

        db_run('INSERT INTO goal_entries (goal_id, entry_date, value) VALUES (?, ?, ?)', [$goalId, $day, $value]);

        return ['ok' => true, 'error' => null];
    }

    /* ==================================================================
       THE CALENDAR AND THE CHART
       ================================================================== */

    /**
     * A daily streak's calendar: its days squared off to whole weeks from
     * the account's first day of the week (Voorkeuren), each met, missed,
     * unknown, pending, `future` (not there yet) or `before` (before the
     * start). Up to six weeks: a longer goal shows the six up to this week.
     *
     * @return list<array{date: string, state: string}>
     */
    function goal_calendar(array $goal, array $progress, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? goal_today();
        $states = array_column($progress['days'], 'state', 'date');
        $start  = new DateTimeImmutable((string) $goal['start_date']);
        $end    = new DateTimeImmutable((string) $goal['end_date']);

        if ((int) $start->diff($end)->days >= GOAL_CALENDAR_DAYS) {
            $weekEnd = week_first_day(min($today, $end))->modify('+6 day');
            $end     = $weekEnd < $end ? $weekEnd : $end;
            $start   = max($start, $end->modify('-' . (GOAL_CALENDAR_DAYS - 1) . ' day'));
        }

        $days = [];

        for ($d = week_first_day($start); $d <= $end; $d = $d->modify('+1 day')) {
            $date   = $d->format('Y-m-d');
            $days[] = [
                'date'  => $date,
                'state' => $d < $start ? 'before' : ($states[$date] ?? ($d > $today ? 'future' : 'unknown')),
            ];
        }

        return $days;
    }

    /**
     * The real values behind a goal, dated, oldest first, in the terms of
     * its type — so the Verloop's line tells the story the percentage does:
     *
     *   value    Mijlpaal: the baseline, then each day's measurement
     *   total    Optellen: the running total
     *   count    Streak, daily: the running number of days met
     *   periods  Streak, per period: the running number of periods met
     *
     * @return array{points: list<array{date: string, value: float}>, mode: string, target: ?float, baseline: ?float}
     */
    function goal_series(array $goal, array $progress): array
    {
        $type = (string) $goal['goal_type'];
        $mode = match (true) {
            $type === 'milestone'              => 'value',
            $type === 'accumulate'             => 'total',
            $progress['variant'] === 'period'  => 'periods',
            default                            => 'count',
        };

        $out = [
            'points'   => [],
            'mode'     => $mode,
            'target'   => in_array($mode, ['value', 'total'], true) ? (float) $goal['target_value'] : null,
            'baseline' => $mode === 'value' ? (float) $goal['baseline_value'] : null,
        ];

        if ($mode === 'value') {
            $first = (string) ($goal['baseline_on'] ?? $goal['start_date']);
            if (!isset($progress['values'][$first])) {
                $out['points'][] = ['date' => $first, 'value' => (float) $goal['baseline_value']];
            }
            foreach ($progress['values'] as $date => $value) {
                $out['points'][] = ['date' => (string) $date, 'value' => (float) $value];
            }
            usort($out['points'], static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            return $out;
        }

        if ($mode === 'total') {
            $running = 0.0;
            foreach ($progress['values'] as $date => $value) {
                $running += $value;
                $out['points'][] = ['date' => (string) $date, 'value' => $running];
            }
            return $out;
        }

        if ($mode === 'periods') {
            $met = 0;
            foreach ($progress['periods'] as $p) {
                if ($p['start'] > $progress['until']) {
                    break;
                }
                if ($p['state'] === 'pending' && $p['achieved'] === 0) {
                    continue;
                }
                $met += $p['state'] === 'met' ? 1 : 0;
                $out['points'][] = ['date' => min($p['end'], $progress['until']), 'value' => (float) $met];
            }
            return $out;
        }

        $met = 0;
        $started = false;
        foreach ($progress['days'] as $day) {
            if ($day['state'] === 'pending') {
                continue;
            }
            if ($day['state'] === 'met' || $day['state'] === 'missed') {
                $started = true;
            }
            if (!$started) {
                continue;
            }
            $met += $day['state'] === 'met' ? 1 : 0;
            $out['points'][] = ['date' => $day['date'], 'value' => (float) $met];
        }

        return $out;
    }
}

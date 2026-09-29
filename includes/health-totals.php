<?php
/**
 * A day's total of steps, distance and calories — every moment counted once.
 *
 * The phone and a watch both write their steps to Health Connect, and adding
 * their records up counts the same walk twice. Everything that shows or pays
 * for a day's total — the Training card, goal progress, the steps points —
 * reads it from health_metric_totals() here, so no two pages can disagree and
 * no second version of the rule can grow somewhere else.
 *
 * The rule, and where to change it, is in config/health-sources.php. Raw
 * readings are never changed here: health_metrics keeps every record as it
 * arrived, and this only decides what counts.
 *
 * Every function takes the signed-in user's id as its first argument and
 * every statement filters on it (see includes/health-data.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';

if (!function_exists('health_metric_totals')) {

    /**
     * config/health-sources.php, read once: the metrics whose day total counts
     * every moment once, and the apps that win. Without the file nothing is
     * reconciled — the plain sums of before.
     */
    function health_sources_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $file   = dirname(__DIR__) . '/config/health-sources.php';
        $loaded = is_file($file) ? (array) require $file : [];

        return $config = [
            'reconcile' => array_values(array_filter((array) ($loaded['reconcile'] ?? []), 'is_string')),
            'priority'  => array_values(array_filter((array) ($loaded['priority'] ?? []), 'is_string')),
        ];
    }

    /**
     * Whether migration 012 is in: health_metrics keeps the start of a span
     * and the app that wrote it, and health_metric_day_totals is there. Until
     * then every total is the plain sum it always was, and the importer
     * writes what it always wrote.
     */
    function health_metric_intervals_available(): bool
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
              WHERE table_schema = DATABASE() AND table_name = 'health_metrics'
                AND column_name IN ('started_at', 'data_origin')"
        ) === 2 && (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'health_metric_day_totals'"
        ) === 1;
    }

    /**
     * The rule the kept day totals were worked out by: the config and this
     * file. Change either and every kept total is worked out again the next
     * time it is read — nothing else in the app touches it.
     */
    function health_totals_rule(): string
    {
        static $rule = null;

        return $rule ??= md5(json_encode(health_sources_config()) . '|' . md5_file(__FILE__));
    }

    /**
     * A summed metric's total for every day from $from to $to (Y-m-d), keyed
     * by date — THE day total. The Training card, goal progress and the steps
     * points all read it here, so no two pages can disagree about a day.
     * Days without a reading are absent.
     *
     * For the metrics config/health-sources.php lists under 'reconcile' — the
     * activity totals — records from different apps that cover the same time
     * count once (health_reconcile_days()). Everything else, and everything
     * before migration 012, is the plain sum per day.
     */
    function health_metric_totals(int $userId, string $metricCode, string $from, string $to): array
    {
        $typeId = health_metric_type_id($metricCode);

        if ($typeId === null || $from > $to) {
            return [];
        }

        if (in_array($metricCode, health_sources_config()['reconcile'], true) && health_metric_intervals_available()) {
            $totals = health_metric_day_totals($userId, $typeId, $from, $to);
        } else {
            $totals = [];

            foreach (db_all(
                'SELECT recorded_on AS day, SUM(value) AS total FROM health_metrics
                  WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ?
               GROUP BY recorded_on',
                [$userId, $typeId, $from, $to]
            ) as $row) {
                $totals[(string) $row['day']] = (float) $row['total'];
            }
        }

        /* A step count is whole — Health Connect's own is too — so a goal of
           10.000 is never missed by a fraction the card rounds away. */
        $decimals = $metricCode === 'steps' ? 0 : 4;

        return array_map(fn (float $total): float => round($total, $decimals), $totals);
    }

    /**
     * The reconciled totals of $from..$to, each day worked out once and kept
     * in health_metric_day_totals — a year-long goal would otherwise work out
     * a year of minute-by-minute records again on every page.
     *
     * A kept total carries a fingerprint of the readings it came from and of
     * the rule. What the readings are now is one query per call, the same
     * kind the plain sum always was, and a day whose fingerprint no longer
     * matches is worked out again. So nothing that writes readings has to
     * know this table exists: a sync, a reading typed in by hand, a row
     * changed in phpMyAdmin — each shows on the next read.
     */
    function health_metric_day_totals(int $userId, int $typeId, string $from, string $to): array
    {
        $plus = static fn (string $date, int $days): string => gmdate('Y-m-d', strtotime($date . ' UTC') + $days * 86400);

        /* The readings as they are now, per date they end on. A day's total
           depends on those that end on it and on the next day — a walk past
           midnight — so both go into its fingerprint. Read before the
           readings themselves: a sync landing in between leaves a total with
           an older fingerprint, which is worked out again next time, and
           never a new fingerprint on an old total. */
        $now = [];
        foreach (db_all(
            "SELECT recorded_on AS d,
                    CONCAT(COUNT(*), ':', BIT_XOR(CRC32(CONCAT_WS('|', id, value, COALESCE(started_at, '-'), recorded_at,
                           COALESCE(data_origin, '-'), COALESCE(source_id, '-'), created_at)))) AS f
               FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_at >= ? AND recorded_at < ?
           GROUP BY recorded_on",
            [$userId, $typeId, $from . ' 00:00:00', $plus($to, 2) . ' 00:00:00']
        ) as $row) {
            $now[(string) $row['d']] = (string) $row['f'];
        }

        $kept = [];
        foreach (db_all(
            'SELECT day, total, readings_hash FROM health_metric_day_totals
              WHERE user_id = ? AND metric_type_id = ? AND day BETWEEN ? AND ?',
            [$userId, $typeId, $from, $to]
        ) as $row) {
            $kept[(string) $row['day']] = $row;
        }

        $totals = [];
        $stale  = [];   // date => the fingerprint its new total will carry

        for ($date = $from; $date <= $to; $date = $plus($date, 1)) {
            $next = $plus($date, 1);

            if (!isset($now[$date]) && !isset($now[$next])) {
                continue;   // no reading reaches into it
            }

            $hash = md5(health_totals_rule() . '|' . ($now[$date] ?? '') . '|' . ($now[$next] ?? ''));

            if (($kept[$date]['readings_hash'] ?? null) === $hash) {
                if ($kept[$date]['total'] !== null) {
                    $totals[$date] = (float) $kept[$date]['total'];
                }
                continue;
            }

            $stale[$date] = $hash;
        }

        /* The days that changed, at most a week of readings at a time, so a
           year of them is never in memory at once. */
        $weeks = [];
        foreach (array_keys($stale) as $date) {
            $last = array_key_last($weeks);

            if ($last === null || $date >= $plus($weeks[$last][0], 7)) {
                $weeks[] = [$date, $date];
            } else {
                $weeks[$last][1] = $date;
            }
        }

        foreach ($weeks as [$first, $last]) {
            $worked = health_reconcile_days(db_all(
                'SELECT id, value, started_at, recorded_at, data_origin, source_id, created_at
                   FROM health_metrics
                  WHERE user_id = ? AND metric_type_id = ? AND recorded_at >= ? AND recorded_at < ?',
                [$userId, $typeId, $first . ' 00:00:00', $plus($last, 2) . ' 00:00:00']
            ), $first, $last, health_sources_config()['priority']);

            for ($date = $first; $date <= $last; $date = $plus($date, 1)) {
                if (!isset($stale[$date])) {
                    continue;
                }

                db_run(
                    'INSERT INTO health_metric_day_totals (user_id, metric_type_id, day, total, readings_hash)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE total = VALUES(total), readings_hash = VALUES(readings_hash)',
                    [$userId, $typeId, $date, $worked[$date] ?? null, $stale[$date]]
                );

                if (isset($worked[$date])) {
                    $totals[$date] = $worked[$date];
                }
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * The day totals from rows already read — kept apart from the database so
     * it can be tested on its own. Returns [date => total] for $from..$to.
     *
     * A row: 'value'; 'recorded_at', its end; 'started_at', its start or
     * null; 'data_origin', the app that wrote it or null; 'source_id';
     * 'created_at'; 'id' — times as stored, 'Y-m-d H:i:s'.
     *
     * The rule of config/health-sources.php: a day is cut into moments, and
     * at each moment one record counts — the highest-ranked one running — for
     * the share of its value that falls in that moment. A record that crosses
     * midnight is split over both days. A reading without a span counts whole
     * on the date it was taken, and so does one whose span is longer than a
     * day: that is no record of particular moments.
     */
    function health_reconcile_days(array $rows, string $from, string $to, array $priority = []): array
    {
        $totals = [];
        $spans  = [];   // date => the spans that fall in it, cut to it

        foreach ($rows as $row) {
            /* Times are compared as the clock showed them, which is how Ownify
               stores them. Reading them as UTC only keeps daylight saving
               out of the sums: every day is 24 hours, and a span is as long
               as its two clock times say. */
            $end   = strtotime($row['recorded_at'] . ' UTC');
            $start = empty($row['started_at']) ? false : strtotime($row['started_at'] . ' UTC');
            $value = (float) $row['value'];

            if ($end === false) {
                continue;
            }

            if ($start === false || $start >= $end || $end - $start > 86400) {
                $day = gmdate('Y-m-d', $end);

                if ($day >= $from && $day <= $to) {
                    $totals[$day] = ($totals[$day] ?? 0.0) + $value;
                }
                continue;
            }

            $span = [
                'origin' => ($row['data_origin'] ?? '') !== '' ? (string) $row['data_origin'] : 'source:' . ($row['source_id'] ?? ''),
                'rate'   => $value / ($end - $start),
                /* Within one app: the newest arrival — standing in for Health
                   Connect's last-modified time — then the later start, the
                   later end and the larger value, Health Connect's order; the
                   row id last, so the answer never depends on the row order. */
                'order'  => [
                    empty($row['created_at']) ? 0 : (int) strtotime($row['created_at'] . ' UTC'),
                    $start, $end, $value, (int) ($row['id'] ?? 0),
                ],
            ];

            for ($dayStart = $start - $start % 86400; $dayStart < $end; $dayStart += 86400) {
                $day = gmdate('Y-m-d', $dayStart);

                if ($day >= $from && $day <= $to) {
                    $spans[$day][] = ['s' => max($start, $dayStart), 'e' => min($end, $dayStart + 86400)] + $span;
                }
            }
        }

        foreach ($spans as $day => $daySpans) {
            $totals[$day] = ($totals[$day] ?? 0.0) + health_reconcile_day($daySpans, $priority);
        }

        ksort($totals);

        /* To the four decimals a value is stored with: shares of a record
           add up to its value, not to 4.999,9999999998 of it. */
        return array_map(fn (float $total): float => round($total, 4), $totals);
    }

    /**
     * One day's total from the spans that fall in it: the apps ranked, then
     * the day walked moment by moment.
     */
    function health_reconcile_day(array $spans, array $priority = []): float
    {
        $apps = [];
        foreach ($spans as $span) {
            $apps[$span['origin']][] = $span;
        }

        /* The ranking of config/health-sources.php: the configured order;
           then the app covering most of the day; then the larger total of
           its own; then the name. */
        $listed = array_flip(array_values($priority));
        $keys   = [];

        foreach ($apps as $origin => $own) {
            $keys[$origin] = [
                $listed[$origin] ?? PHP_INT_MAX,
                -health_reconcile_coverage($own),
                -round(health_reconcile_walk($own, fn (array $s): array => $s['order']), 4),
                (string) $origin,
            ];
        }

        uasort($keys, fn (array $a, array $b): int => $a <=> $b);

        $rank  = array_flip(array_keys($keys));     // origin => 0 for the highest
        $count = count($rank);

        return health_reconcile_walk($spans, fn (array $s): array => [$count - $rank[$s['origin']], ...$s['order']]);
    }

    /**
     * Walks spans from edge to edge: between two edges only the running span
     * with the highest $key counts — its value per second, times the length
     * of that stretch.
     */
    function health_reconcile_walk(array $spans, callable $key): float
    {
        $edges = [];
        foreach ($spans as $span) {
            $edges[$span['s']] = true;
            $edges[$span['e']] = true;
        }
        $edges = array_keys($edges);
        sort($edges);

        usort($spans, fn (array $a, array $b): int => $a['s'] <=> $b['s']);

        $running = new SplPriorityQueue();
        $next    = 0;
        $count   = count($spans);
        $total   = 0.0;

        for ($i = 0, $last = count($edges) - 1; $i < $last; $i++) {
            $at = $edges[$i];

            while ($next < $count && $spans[$next]['s'] <= $at) {
                $running->insert($spans[$next], $key($spans[$next]));
                $next++;
            }

            /* A span that has ended leaves once it would be the one to count. */
            while (!$running->isEmpty() && $running->top()['e'] <= $at) {
                $running->extract();
            }

            if (!$running->isEmpty()) {
                $total += $running->top()['rate'] * ($edges[$i + 1] - $at);
            }
        }

        return $total;
    }

    /** How many seconds an app's spans cover, overlaps counted once. */
    function health_reconcile_coverage(array $spans): int
    {
        usort($spans, fn (array $a, array $b): int => $a['s'] <=> $b['s']);

        $covered = 0;
        $until   = PHP_INT_MIN;

        foreach ($spans as $span) {
            if ($span['e'] > $until) {
                $covered += $span['e'] - max($span['s'], $until);
                $until    = $span['e'];
            }
        }

        return $covered;
    }
}

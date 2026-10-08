<?php
/**
 * Slaap, drawn (docs/SLEEP.md) — for the website and the app alike.
 *
 *   the night    the last night's stages as a timeline: Wakker,
 *                Rusteloosheid, REM, Licht and Diep as rows, from the time
 *                the person went to bed on the left to the time they got
 *                up on the right, each recorded period of a stage a block
 *                on its row (sleep_stages, migration 018)
 *   the charts   Tijd in bed + Regelmaat, SpO₂, Huidtemperatuur and
 *                Hartslagvariabiliteit, each over 7 dagen, 30 dagen,
 *                90 dagen and 1 jaar on Ownify's time axis
 *                (lib/time-axis.php, docs/CHARTS.md): the Slaap page draws
 *                the week of each, small; each chart's own page draws it
 *                large, over every period
 *
 * Nothing here is calculated anew. Tijd in bed is the night's own, as the
 * Slaap page always showed it; Regelmaat is the sleep score's regularity
 * part as it was recorded each day (daily_scores, health_score_history()) —
 * what the Scorekompas shows beside "Regelmaat" — carried as the score is
 * carried; SpO₂, Huidtemperatuur and HRV are each day's value as
 * health_daily_metric() gives it. A day without one is a gap, never a 0.
 *
 * Positions are % of the plot (x) and % from its top (y); the lines are
 * paths in a 300 × 160 box, as the Verloop's are.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-signals.php';
require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/time-axis.php';
require_once __DIR__ . '/goal-chart.php';
require_once __DIR__ . '/hydrate-compass.php';
require_once __DIR__ . '/hydrate-health.php';

if (!function_exists('hydrate_sleep')) {

    define('SLEEP_CHART_W', 300.0);
    define('SLEEP_CHART_H', 160.0);
    define('SLEEP_CHART_PAD_Y', 12.0);   // as the Verloop's: a line's top and bottom points keep their dots inside

    /**
     * @param array  $area     config/health.php's Slaap area: `night`, `charts`, `chart_copy`
     * @param array  $periods  config/compass.php's history periods (days, label, spoken)
     * @param array  $history  health_score_history() over a year: the recorded sleep score's parts per day
     * @param string $today    Y-m-d
     */
    function hydrate_sleep(array $area, array $periods, int $userId, array $history, string $today): array
    {
        return [
            'night'  => hydrate_sleep_night((array) $area['night'], $userId, $today),
            'charts' => hydrate_sleep_charts((array) $area['charts'], (array) $area['chart_copy'], $periods, $userId, $history, $today),
        ];
    }

    /* ==================================================================
       THE NIGHT
       ================================================================== */

    /**
     * The last night of the past `days` (the main sleep, health_night_on()'s
     * rule): its bedtime and wake time, how long was slept and how much of
     * the time in bed, and each recorded period of a stage on its row.
     */
    function hydrate_sleep_night(array $copy, int $userId, string $today): array
    {
        $rows = array_map(static fn (array $s): array => ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'total' => null], $copy['stages']);
        $out  = [
            'title'      => (string) $copy['title'],
            'hint'       => (string) $copy['hint'],
            'rows'       => $rows,
            'has_night'  => false,
            'staged'     => false,
            'date'       => null,
            'start'      => null,
            'end'        => null,
            'asleep'     => null,
            'asleep_label' => (string) $copy['asleep'],
            'efficiency' => null,
            'efficiency_label' => (string) $copy['efficiency'],
            'blocks'     => [],
            'ticks'      => [],
            'aria'       => (string) $copy['title'],
            'note'       => (string) $copy['empty'],
        ];

        if (!db_available()) {
            return $out;
        }

        $from   = (new DateTimeImmutable($today))->modify('-' . max(0, (int) $copy['days'] - 1) . ' day')->format('Y-m-d');
        $nights = health_nights_on($userId, $from, $today);
        if ($nights === []) {
            return $out;
        }

        ksort($nights);
        $night = end($nights);
        $start = (int) $night['start'];
        $end   = (int) $night['end'];
        $span  = max(60, $end - $start);

        $out['has_night']  = true;
        $out['start']      = date('H:i', $start);
        $out['end']        = date('H:i', $end);
        $out['asleep']     = hydrate_hours((int) round((float) $night['minutes']));
        $out['efficiency'] = $night['efficiency'] === null ? null : (int) round((float) $night['efficiency']);
        $out['aria']       = sprintf((string) $copy['aria'], $out['start'], $out['end']);
        $out['note']       = null;

        $first = date('Y-m-d', $start);
        $last  = (string) $night['date'];
        $out['date'] = $first === $last
            ? score_compass_date($last, true)
            : sprintf((string) $copy['night'], (string) (int) substr($first, 8, 2) . (substr($first, 5, 2) === substr($last, 5, 2) ? '' : ' ' . hydrate_sleep_month($first)), score_compass_date($last, true));

        /* Which row each recorded stage is on. */
        $rowOf = [];
        foreach ($copy['stages'] as $r => $stage) {
            foreach ((array) $stage['kinds'] as $kind) {
                $rowOf[(int) $kind] = $r;
            }
        }

        /* Each period on its row, cut to the night; a stage that goes on
           where the last one of its kind ended is one block. */
        $blocks = [];
        $totals = array_fill(0, count($rows), 0);
        foreach (health_sleep_stage_periods($userId, $night['parts'] ?? [$night['session_id']]) as $period) {
            if (!isset($rowOf[$period['stage']])) {
                continue;                           // asleep of no known kind, or out of bed: on no row
            }
            $a = max($start, $period['start']);
            $b = min($end, $period['end']);
            if ($b <= $a) {
                continue;
            }

            $r    = $rowOf[$period['stage']];
            $prev = $blocks === [] ? null : $blocks[count($blocks) - 1];
            if ($prev !== null && $prev['row'] === $r && $a - $prev['b'] <= 60) {
                $blocks[count($blocks) - 1]['b'] = max($prev['b'], $b);
            } else {
                $blocks[] = ['row' => $r, 'a' => $a, 'b' => $b];
            }
        }

        foreach ($blocks as $block) {
            $totals[$block['row']] += $block['b'] - $block['a'];
            $out['blocks'][] = [
                $block['row'],
                round(($block['a'] - $start) / $span * 100, 2),
                round(($block['b'] - $start) / $span * 100, 2),
                date('H:i', $block['a']),
                date('H:i', $block['b']),
            ];
        }

        $out['staged'] = $out['blocks'] !== [];
        if ($out['staged']) {
            foreach ($totals as $r => $seconds) {
                $out['rows'][$r]['total'] = sprintf((string) $copy['minutes'], (int) round($seconds / 60));
            }
        } else {
            $out['note'] = (string) $copy['unstaged'];
        }

        $out['ticks'] = hydrate_sleep_night_ticks($start, $end);

        return $out;
    }

    /**
     * The times under the night: when it began at the left edge, when it
     * ended at the right, and whole hours between them — every hour of a
     * short night, every second or third of a longer one — none so close to
     * either end that the two would touch.
     *
     * @return list<array{x: float, label: string}>
     */
    function hydrate_sleep_night_ticks(int $start, int $end): array
    {
        $span  = max(60, $end - $start);
        $hours = $span / 3600;
        $every = $hours <= 5 ? 1 : ($hours <= 10 ? 2 : 3);

        $ticks = [['x' => 0.0, 'label' => date('H:i', $start)]];

        $hour = (int) (ceil($start / 3600) * 3600);
        for ($t = $hour; $t < $end; $t += 3600) {
            if ((int) date('G', $t) % $every !== 0) {
                continue;
            }
            $x = ($t - $start) / $span * 100;
            if ($x < 20 || $x > 80) {
                continue;
            }
            $ticks[] = ['x' => round($x, 2), 'label' => date('H:i', $t)];
        }

        $ticks[] = ['x' => 100.0, 'label' => date('H:i', $end)];

        return $ticks;
    }

    function hydrate_sleep_month(string $date): string
    {
        return time_axis_month((int) substr($date, 5, 2));
    }

    /* ==================================================================
       THE CHARTS
       ================================================================== */

    /**
     * Each chart, over each period, from a year of its days.
     *
     * @return list<array>
     */
    function hydrate_sleep_charts(array $charts, array $copy, array $periods, int $userId, array $history, string $today): array
    {
        $yearAgo = (new DateTimeImmutable($today))->modify('-364 day')->format('Y-m-d');
        $year    = substr($today, 0, 4);

        $out = [];
        foreach ($charts as $id => $chart) {
            /* Each series' days: date => [value, text, carried]. */
            $series = [];
            $starts = [];                     // each series' first day ever, or null
            foreach ($chart['series'] as $s) {
                [$days, $first] = hydrate_sleep_series_days((string) $s['key'], $s, $userId, $history, $yearAgo, $today);
                $series[] = $days;
                $starts[] = $first;
            }
            $firsts = array_filter($starts, static fn ($f) => $f !== null);
            $first  = $firsts === [] ? null : min($firsts);

            $built = [];
            foreach ($periods as $period) {
                $built[] = hydrate_sleep_chart_period((string) $id, $chart, $copy, (int) $period['days'], $period, $series, $starts, $first, $today, $year);
            }

            $week = $built[0];
            $out[] = [
                'id'      => (string) $id,
                'title'   => (string) $chart['title'],
                'open'    => sprintf((string) $copy['open'], (string) $chart['title']),
                'back'    => (string) $copy['back'],
                'switch'  => 'Periode kiezen',
                'hint'    => (string) $copy['hint'],
                'empty'   => (string) $copy['empty'],
                'series'  => array_map(static fn (array $s): array => [
                    'key'   => (string) $s['key'],
                    'label' => (string) $s['label'],
                    'kind'  => (string) $s['kind'],
                ], $chart['series']),
                'latest'  => hydrate_sleep_latest($week, $today),
                'default' => (string) $periods[0]['days'],
                'periods' => $built,
            ];
        }

        return $out;
    }

    /**
     * One series' recorded days over the year, and its first day ever.
     *
     * @return array{0: array<string,array{0: float, 1: string, 2: bool}>, 1: ?string}
     */
    function hydrate_sleep_series_days(string $key, array $s, int $userId, array $history, string $from, string $to): array
    {
        $days = [];

        if (!db_available()) {
            return [[], null];
        }

        if ($key === 'time_in_bed') {
            foreach (health_nights_on($userId, $from, $to) as $date => $night) {
                if ($night['in_bed'] !== null && $night['in_bed'] > 0) {
                    $minutes = (int) round((float) $night['in_bed']);
                    $days[(string) $date] = [$minutes / 60, hydrate_hours($minutes) . ' u', false];
                }
            }
            $first = db_value('SELECT MIN(night_of) FROM sleep_sessions WHERE user_id = ? AND time_in_bed_minutes > 0', [$userId]);

            return [$days, $first === null ? null : (string) $first];
        }

        if ($key === 'regularity') {
            $first = null;
            foreach ($history as $date => $day) {
                $value = $day['sleep']['components']['regularity'] ?? null;
                if ($value === null || ($day['sleep']['score'] ?? null) === null) {
                    continue;
                }
                $first ??= (string) $date;
                $score = (int) round((float) $value);
                $days[(string) $date] = [(float) $score, (string) $score, ($day['state'] ?? '') === 'carried' ? (string) ($day['from'] ?? '') : false];
            }

            return [$days, $first];
        }

        /* A wearable's nightly value, as the Slaap page always read it. */
        $decimals = (int) ($s['decimals'] ?? 0);
        foreach (health_daily_metric_days($userId, $key, $from, $to) as $date => $value) {
            $days[$date] = [round($value, $decimals), hydrate_sleep_number($value, $decimals, (string) $s['unit']), false];
        }
        $first = db_value(
            'SELECT MIN(m.recorded_on) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
              WHERE m.user_id = ? AND t.code = ?',
            [$userId, $key]
        );

        return [$days, $first === null ? null : (string) $first];
    }

    /** "97%", "34,2 °C", "48 ms": a value as the reading writes it. */
    function hydrate_sleep_number(float $value, int $decimals, string $unit): string
    {
        $text = number_format($value, $decimals, ',', '.');

        return $unit === '' ? $text : ($unit === '%' ? $text . '%' : $text . ' ' . $unit);
    }

    /**
     * One chart over one period: the window and dates (time_axis()), a
     * point for each day, week or month that has begun, each series' value
     * there — a week's or month's the mean of its days with one — and its
     * lines and bars.
     */
    function hydrate_sleep_chart_period(string $id, array $chart, array $copy, int $days, array $period, array $series, array $starts, ?string $first, string $today, string $year): array
    {
        $axis  = time_axis($days, $first, $today);
        $grain = $axis['grain'];

        /* Inset from either edge by most of half a point's room, so a bar
           over the first or last date stays inside the plot; the dates move
           with it (as a goal's Verloop insets its own). */
        $spacing = ($grain === 'month' ? 365 / 12 : ($grain === 'week' ? 7 : 1)) / ($days - 1);
        $pad     = 0.3 * $spacing;
        $place   = static fn (float $x): float => round(($pad + $x / 100 * (1 - 2 * $pad)) * 100, 3);

        $ticks = array_map(static fn (array $t): array => ['x' => $place($t['x'])] + array_diff_key($t, ['x' => 0, 'date' => 0]), $axis['ticks']);

        $points = [];
        $values = array_fill(0, count($series), []);
        foreach ($axis['slots'] as $slot) {
            $from = $slot['from'];
            $to   = min($slot['to'], $today);
            $row  = [
                'x'       => $place($slot['x']),
                'label'   => $from === $to || $grain === 'day'
                    ? score_compass_date_in($from, $year)
                    : hydrate_health_history_range($from, $to, $year, ['range' => '%1$s – %2$s']),
                'detail'  => null,
                'note'    => null,
                'texts'   => [],
                'carried' => false,
            ];

            foreach ($series as $k => $daysOf) {
                $value = null;
                $text  = null;

                if ($grain === 'day') {
                    if (isset($daysOf[$from])) {
                        [$value, $text, $carried] = $daysOf[$from];
                        if ($carried !== false && $carried !== '') {
                            $row['carried'] = true;
                            $row['note']    = sprintf((string) $copy['carried'], score_compass_date_in((string) $carried, $year));
                        }
                    }
                } elseif ($starts[$k] !== null && $from >= $starts[$k]) {
                    /* A week or month as one: the mean of its days with a
                       value — never one that began before the series did: a
                       line begins at a point of its own (docs/CHARTS.md). */
                    $kept = [];
                    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                        if (isset($daysOf[$d->format('Y-m-d')])) {
                            $kept[] = $daysOf[$d->format('Y-m-d')][0];
                        }
                    }
                    if ($kept !== []) {
                        $value = array_sum($kept) / count($kept);
                        $text  = hydrate_sleep_mean_text($chart['series'][$k], $value);
                    }
                }

                $values[$k][] = $value;
                $row['texts'][] = $text;
            }

            if (array_filter($row['texts'], static fn ($t) => $t !== null) === []) {
                $row['note'] = (string) ($copy['none'][$grain] ?? '');
            } elseif ($grain !== 'day') {
                $row['detail'] = ['week' => 'weekgemiddelde', 'month' => 'maandgemiddelde'][$grain];
            }

            $points[] = $row;
        }

        $geometry = hydrate_sleep_geometry($chart['series'], array_column($points, 'x'), $values, $spacing * (1 - 2 * $pad) * 100);
        $per      = ['day' => 'per dag', 'week' => 'per week', 'month' => 'per maand'][$grain];

        return [
            'key'      => (string) $days,
            'label'    => (string) $period['label'],
            'days'     => $days,
            'group'    => $grain,
            'aria'     => sprintf((string) $copy['aria'], (string) $chart['title'], $per, (string) ($period['spoken'] ?? $period['label'])),
            'axis'     => $ticks,
            /* The same dates in two rows — the day over its month where the
               month is named — for the small chart, too narrow for "8 okt"
               seven times. */
            'axis_rows' => $days === 7 ? time_axis_rows($axis['ticks'], $place) : null,
            'x'        => array_column($points, 'x'),
            'points'   => array_map(static fn (array $p): array => array_merge([$p['label'], $p['detail'], $p['note']], $p['texts']), $points),
            'carried'  => array_keys(array_filter(array_column($points, 'carried'))),
        ] + $geometry;
    }

    /** A week's or month's mean, as its days' values are written. */
    function hydrate_sleep_mean_text(array $s, float $value): string
    {
        return match ((string) $s['key']) {
            'time_in_bed' => hydrate_hours((int) round($value * 60)) . ' u',
            'regularity'  => (string) (int) round($value),
            default       => hydrate_sleep_number(round($value, (int) ($s['decimals'] ?? 0)), (int) ($s['decimals'] ?? 0), (string) $s['unit']),
        };
    }

    /**
     * The lines and bars in the 300 × 160 box.
     *
     *   a line   alone on its chart: over the range its values span, out to
     *            round steps, its levels named on the grid; beside bars
     *            (Regelmaat, a score): over 0–100, no level named — the two
     *            share no scale, and none is pretended
     *   bars     from the bottom, over 0 to the highest out to a round step
     *
     * A run of values without a gap is one monotone curve; a value on its
     * own is its dot.
     *
     * @param list<list<?float>> $values  each series' value at each point
     */
    function hydrate_sleep_geometry(array $defs, array $x, array $values, float $barRoom): array
    {
        $w    = SLEEP_CHART_W;
        $h    = SLEEP_CHART_H;
        $padY = SLEEP_CHART_PAD_Y;
        $mixed = count(array_unique(array_column($defs, 'kind'))) > 1;

        $lines = [];
        $bars  = [];
        $grid  = [];
        $has   = false;

        foreach ($defs as $k => $def) {
            $kept = array_values(array_filter($values[$k], static fn ($v) => $v !== null));
            $has  = $has || $kept !== [];

            if ($def['kind'] === 'bars') {
                $top  = $kept === [] ? 1.0 : max($kept);
                $step = goal_chart_step(0, $top, 3);
                $high = max($step, ceil($top / $step) * $step);
                $bars[] = [
                    'key' => (string) $def['key'],
                    'w'   => round($barRoom * 0.62, 3),
                    'top' => array_map(static fn ($v) => $v === null ? null : round(($padY + (1 - $v / $high) * ($h - $padY)) / $h * 100, 2), $values[$k]),
                ];
                continue;
            }

            if ($mixed) {
                [$low, $high] = [0.0, 100.0];
            } else {
                [$low, $high, $step] = hydrate_sleep_range($kept, (int) ($def['decimals'] ?? 0));
                $decimals = goal_chart_step_decimals($step);
                for ($level = (floor($low / $step) + 1) * $step; $level < $high - $step / 2; $level += $step) {
                    $grid[] = [
                        'y'     => round(($padY + (1 - ($level - $low) / ($high - $low)) * ($h - 2 * $padY)) / $h * 100, 2),
                        'label' => number_format($level, $decimals, ',', '.'),
                    ];
                }
            }

            $y    = [];
            $runs = [];
            $run  = [];
            foreach ($values[$k] as $i => $v) {
                if ($v === null) {
                    $y[] = null;
                    if ($run !== []) { $runs[] = $run; $run = []; }
                    continue;
                }
                $top = round(($padY + (1 - ($v - $low) / ($high - $low)) * ($h - 2 * $padY)) / $h * 100, 2);
                $y[] = $top;
                $run[] = [$x[$i] / 100 * $w, $top / 100 * $h];
            }
            if ($run !== []) { $runs[] = $run; }

            $lines[] = [
                'key'  => (string) $def['key'],
                'line' => array_values(array_map('goal_chart_monotone', array_filter($runs, static fn ($r) => count($r) > 1))),
                'y'    => $y,
            ];
        }

        return [
            'width'    => $w,
            'height'   => $h,
            'has_data' => $has,
            'lines'    => $lines,
            'bars'     => $bars,
            'grid'     => $grid,
        ];
    }

    /**
     * The height a line's values are drawn over: their span out to a round
     * step (1, 2 or 5 times a power of ten), with a little room around it,
     * so the grid reads 94 / 96 / 98, never 94.3 — and never finer than
     * the values themselves are written (a whole percent, a tenth of a
     * degree).
     *
     * @param float[] $values
     * @return array{0: float, 1: float, 2: float} low, high, step
     */
    function hydrate_sleep_range(array $values, int $decimals = 0): array
    {
        if ($values === []) {
            return [0.0, 100.0, 25.0];
        }

        $low  = min($values);
        $high = max($values);
        $room = max(($high - $low) * 0.15, abs($high) * 0.01, 0.1);
        $low -= $room;
        $high += $room;

        $step = max(goal_chart_step($low, $high, 3), 10 ** -$decimals);
        $low  = floor($low / $step) * $step;
        $high = ceil($high / $step) * $step;
        if ($high - $low < $step * 2) {
            $high += $step;
        }

        return [$low, $high, $step];
    }

    /**
     * What the small chart says beside its title: the most recent point
     * of its week with a value — each series' — and that point's date
     * unless it is today's.
     *
     * @return array{texts: list<?string>, date: ?string}|null
     */
    function hydrate_sleep_latest(array $week, string $today): ?array
    {
        for ($i = count($week['points']) - 1; $i >= 0; $i--) {
            $texts = array_slice($week['points'][$i], 3);
            if (array_filter($texts, static fn ($t) => $t !== null) !== []) {
                $isToday = $i === count($week['points']) - 1 && $week['points'][$i][0] === score_compass_date_in($today, substr($today, 0, 4));

                return ['texts' => $texts, 'date' => $isToday ? null : (string) $week['points'][$i][0]];
            }
        }

        return null;
    }
}

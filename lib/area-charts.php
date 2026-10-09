<?php
/**
 * An area's charts over time — Slaap's four, Training's seven — for the
 * website and the app alike (docs/CHARTS.md). One chart system: the area
 * page draws each chart's week small, two by two; each chart's own page
 * draws it large over every period, as Gezondheid's Verloop is drawn.
 *
 *   a chart     `title`, its `series` (one or two, each `kind` line or
 *               bars), and `scale`:
 *                 own     (the default) each series on a height of its own:
 *                         a line alone over the range its values span, its
 *                         levels named; bars alone from 0, theirs named; a
 *                         line beside bars over `range` (Regelmaat, a
 *                         score: 0–100) or from 0, no level named — the two
 *                         share no scale, and none is pretended
 *                 shared  every series from 0 on one height, its levels
 *                         named: two measures of one unit (Actieve and
 *                         Totale calorieën, both kcal)
 *   a series    `key`, `label`, `unit`, `kind`, `decimals`, and `format`:
 *               hours (7:45 u), score (a whole number), count (a whole
 *               number of `unit`/`units`, "2 trainingen"; a week's or
 *               month's mean per day, "0,6 per dag"), or a number with its
 *               unit (the default)
 *   its days    what the area says they are: $days(series) returns each
 *               day's [value, text, carried] and the series' first day
 *               ever. A catalogue metric's are area_metric_days(): each
 *               day's value as every page reads it (health_daily_metric()).
 *
 * Each period is Ownify's time axis (lib/time-axis.php): 7 dagen and
 * 30 dagen a point per day, 90 dagen a point per week, 1 jaar per month —
 * a week's or month's value the mean of its days with one. A day without a
 * value is a gap, never a 0. `grains` may keep a period's points daily
 * (the heart rate's 90 dagen); its dates stay the period's own.
 *
 * Positions are % of the plot (x) and % from its top (y); the lines are
 * paths in a 300 × 160 box, as the Verloop's are.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/time-axis.php';
require_once __DIR__ . '/goal-chart.php';
require_once __DIR__ . '/hydrate-health.php';
require_once __DIR__ . '/hydrate-compass.php';

if (!function_exists('area_charts')) {

    define('AREA_CHART_W', 300.0);
    define('AREA_CHART_H', 160.0);
    define('AREA_CHART_PAD_Y', 12.0);   // as the Verloop's: a line's top and bottom points keep their dots inside

    /**
     * Each chart, over each period, from a year of its days.
     *
     * @param array    $charts   id => chart (see the head of this file)
     * @param array    $copy     the area's chart_copy: empty, hint, open, aria, none, carried, back
     * @param array    $periods  config/compass.php's history periods (days, label, spoken)
     * @param callable $days     fn (array $series): array{0: array<string,array{0: float, 1: string, 2: string|false}>, 1: ?string}
     * @param string   $today    Y-m-d
     * @return list<array>
     */
    function area_charts(array $charts, array $copy, array $periods, callable $days, string $today): array
    {
        $out = [];
        foreach ($charts as $id => $chart) {
            $built = area_chart((string) $id, $chart, $copy, $periods, $days, $today);
            foreach ($built['periods'] as $k => $period) {
                unset($built['periods'][$k]['values']);     // for what is drawn over a chart (area_chart()); these draw nothing more
            }
            $out[] = $built;
        }

        return $out;
    }

    /** One chart over every period. */
    function area_chart(string $id, array $chart, array $copy, array $periods, callable $days, string $today, array $grains = []): array
    {
        $year = substr($today, 0, 4);

        $series = [];
        $starts = [];                         // each series' first day ever, or null
        foreach ($chart['series'] as $s) {
            [$of, $first] = $days($s);
            $series[] = $of;
            $starts[] = $first;
        }
        $firsts = array_filter($starts, static fn ($f) => $f !== null);
        $first  = $firsts === [] ? null : min($firsts);

        $built = [];
        foreach ($periods as $period) {
            $built[] = area_chart_period($chart, $copy, (int) $period['days'], $period, $series, $starts, $first, $today, $year, $grains[(int) $period['days']] ?? null);
        }

        return [
            'id'      => $id,
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
            'latest'  => area_chart_latest($built[0], $today),
            'default' => (string) $periods[0]['days'],
            'periods' => $built,
        ];
    }

    /**
     * A catalogue metric's days over [$from, $to], each as every page reads
     * it — health_daily_metric(): a day's total counted once over the apps
     * that recorded it, an average, or the last reading, as its type says —
     * and its first day ever. A day without a reading is absent.
     *
     * @return array{0: array<string,array{0: float, 1: string, 2: false}>, 1: ?string}
     */
    function area_metric_days(int $userId, string $code, array $s, string $from, string $to): array
    {
        if (!db_available()) {
            return [[], null];
        }

        $decimals = (int) ($s['decimals'] ?? 0);
        $days     = [];
        foreach (health_daily_metric_days($userId, $code, $from, $to) as $date => $value) {
            $days[$date] = [round($value, $decimals), area_chart_text($s, $value), false];
        }
        $first = db_value(
            'SELECT MIN(m.recorded_on) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
              WHERE m.user_id = ? AND t.code = ?',
            [$userId, $code]
        );

        return [$days, $first === null ? null : (string) $first];
    }

    /** "97%", "34,2 °C", "48 ms", "9.420": a value as the reading writes it. */
    function area_chart_number(float $value, int $decimals, string $unit): string
    {
        $text = number_format($value, $decimals, ',', '.');

        return $unit === '' ? $text : ($unit === '%' ? $text . '%' : $text . ' ' . $unit);
    }

    /** A value as its series writes it — a day's, or a week's or month's mean. */
    function area_chart_text(array $s, float $value): string
    {
        return match ((string) ($s['format'] ?? 'number')) {
            'hours' => hydrate_hours((int) round($value * 60)) . ' u',
            'score' => (string) (int) round($value),
            'count' => abs($value - round($value)) < 1e-9
                ? (int) round($value) . ' ' . ((int) round($value) === 1 ? (string) $s['unit'] : (string) ($s['units'] ?? $s['unit']))
                : number_format($value, 1, ',', '.') . ' per dag',
            default => area_chart_number(round($value, (int) ($s['decimals'] ?? 0)), (int) ($s['decimals'] ?? 0), (string) ($s['unit'] ?? '')),
        };
    }

    /**
     * One chart over one period: the window and dates (time_axis()), a
     * point for each day, week or month that has begun, each series' value
     * there — a week's or month's the mean of its days with one — and its
     * lines and bars.
     *
     * @param ?string $grain  'day' to keep the points daily where the period's own are weeks
     */
    function area_chart_period(array $chart, array $copy, int $days, array $period, array $series, array $starts, ?string $first, string $today, string $year, ?string $grain = null): array
    {
        $axis  = time_axis($days, $first, $today, $grain);
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
                        $text  = area_chart_text($chart['series'][$k], $value);
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

        $geometry = area_chart_geometry($chart['series'], array_column($points, 'x'), $values, $spacing * (1 - 2 * $pad) * 100, (string) ($chart['scale'] ?? 'own'));
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
            /* Each point's values as numbers, for what is drawn over them
               (the heart rate's zones); the texts above are what is read. */
            'values'   => $values,
        ] + $geometry;
    }

    /**
     * The lines and bars in the 300 × 160 box (see the head of this file
     * for which height each series is drawn on). A run of values without a
     * gap is one monotone curve; a value on its own is its dot.
     *
     * @param list<list<?float>> $values  each series' value at each point
     */
    function area_chart_geometry(array $defs, array $x, array $values, float $barRoom, string $scale = 'own'): array
    {
        $w     = AREA_CHART_W;
        $h     = AREA_CHART_H;
        $padY  = AREA_CHART_PAD_Y;
        $mixed = count(array_unique(array_column($defs, 'kind'))) > 1;

        $lines = [];
        $bars  = [];
        $grid  = [];
        $has   = false;

        /* From the bottom: bars always, and a line on a shared height. */
        $based = static function (array $kept, int $decimals) : array {
            $top  = $kept === [] ? 1.0 : max(max($kept), 0.0);
            $step = max(goal_chart_step(0, $top > 0 ? $top : 1.0, 3), 10 ** -$decimals);
            $high = max($step, ceil($top / $step) * $step);

            return [0.0, $high, $step];
        };
        $baseY = static fn (float $v, float $high): float => round(($padY + (1 - $v / $high) * ($h - $padY)) / $h * 100, 2);
        $levels = static function (float $high, float $step) use ($baseY): array {
            $out      = [];
            $decimals = goal_chart_step_decimals($step);
            for ($level = $step; $level <= $high + $step / 1000; $level += $step) {
                $out[] = ['y' => $baseY($level, $high), 'label' => number_format($level, $decimals, ',', '.')];
            }

            return $out;
        };

        $all = [];
        foreach ($values as $k => $vs) {
            foreach ($vs as $v) {
                if ($v !== null) {
                    $all[] = $v;
                }
            }
        }
        $shared = $scale === 'shared' ? $based($all, (int) max(array_map(static fn ($d) => (int) ($d['decimals'] ?? 0), $defs))) : null;
        if ($shared !== null) {
            $grid = $levels($shared[1], $shared[2]);
        }

        foreach ($defs as $k => $def) {
            $kept = array_values(array_filter($values[$k], static fn ($v) => $v !== null));
            $has  = $has || $kept !== [];

            if ($def['kind'] === 'bars') {
                [, $high, $step] = $shared ?? $based($kept, (int) ($def['decimals'] ?? 0));
                if ($shared === null && !$mixed) {
                    $grid = $levels($high, $step);
                }
                $bars[] = [
                    'key' => (string) $def['key'],
                    'w'   => round($barRoom * 0.62, 3),
                    'top' => array_map(static fn ($v) => $v === null ? null : $baseY($v, $high), $values[$k]),
                ];
                continue;
            }

            if ($shared !== null) {
                $y = static fn (float $v): float => $baseY($v, $shared[1]);
            } elseif ($mixed) {
                /* Beside bars: its own height, from its `range` or from 0. */
                [$low, $high] = isset($def['range']) ? [(float) $def['range'][0], (float) $def['range'][1]] : array_slice($based($kept, (int) ($def['decimals'] ?? 0)), 0, 2);
                $y = static fn (float $v): float => round(($padY + (1 - ($v - $low) / ($high - $low)) * ($h - 2 * $padY)) / $h * 100, 2);
            } else {
                [$low, $high, $step] = area_chart_range($kept, (int) ($def['decimals'] ?? 0));
                $grid = area_chart_levels($low, $high, $step);
                $y    = static fn (float $v): float => area_chart_y($v, $low, $high);
            }

            [$ys, $paths] = area_chart_line($x, $values[$k], $y);
            $lines[] = [
                'key'  => (string) $def['key'],
                'line' => $paths,
                'y'    => $ys,
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

    /** A value's height on a line drawn over $low–$high, in % from the plot's top. */
    function area_chart_y(float $v, float $low, float $high): float
    {
        return round((AREA_CHART_PAD_Y + (1 - ($v - $low) / ($high - $low)) * (AREA_CHART_H - 2 * AREA_CHART_PAD_Y)) / AREA_CHART_H * 100, 2);
    }

    /** A line's levels, named on the grid: every $step between $low and $high, neither edge. */
    function area_chart_levels(float $low, float $high, float $step): array
    {
        $out      = [];
        $decimals = goal_chart_step_decimals($step);
        for ($level = (floor($low / $step) + 1) * $step; $level < $high - $step / 2; $level += $step) {
            $out[] = ['y' => area_chart_y($level, $low, $high), 'label' => number_format($level, $decimals, ',', '.')];
        }

        return $out;
    }

    /**
     * A line's points (% from the top, null where there is no value) and its
     * monotone curves in the 300 × 160 box: one per run of values without a
     * gap — a run of one is its dot alone. $gap breaks a run between two
     * values further apart than that, in %.
     *
     * @return array{0: list<?float>, 1: list<string>}
     */
    function area_chart_line(array $x, array $values, callable $y, ?float $gap = null): array
    {
        $ys   = [];
        $runs = [];
        $run  = [];
        $last = null;
        foreach ($values as $i => $v) {
            if ($v === null) {
                $ys[] = null;
                if ($gap === null && $run !== []) { $runs[] = $run; $run = []; }
                continue;
            }
            if ($gap !== null && $last !== null && $x[$i] - $last > $gap && $run !== []) {
                $runs[] = $run;
                $run = [];
            }
            $top  = $y((float) $v);
            $ys[] = $top;
            $run[] = [$x[$i] / 100 * AREA_CHART_W, $top / 100 * AREA_CHART_H];
            $last = $x[$i];
        }
        if ($run !== []) { $runs[] = $run; }

        return [$ys, array_values(array_map('goal_chart_monotone', array_filter($runs, static fn ($r) => count($r) > 1)))];
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
    function area_chart_range(array $values, int $decimals = 0): array
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
    function area_chart_latest(array $week, string $today): ?array
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

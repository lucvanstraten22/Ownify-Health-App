<?php
/**
 * The "Verloop" chart on a goal's detail screen — geometry and words.
 *
 * ---------------------------------------------------------------------------
 * WHAT CHANGED, AND WHY
 * ---------------------------------------------------------------------------
 * The chart used to plot the stored percentage, spaced by position in the
 * list rather than by date. So it could not say "80 kg on the 12th", a week
 * with no readings looked exactly like a day, and the scale was always 0-100
 * whatever the goal was about.
 *
 * It now plots the thing the goal is about — kilos, steps, hours — against
 * real dates, from goal_series(), on Ownify's time axis (lib/time-axis.php,
 * docs/CHARTS.md): the shortest standard period that holds the goal's
 * history — 7 days, 30, 90 or a year, which rolls from then on — with the
 * history from its first day at the left and the rest of the period empty
 * ahead of it, the period's own dates under it, and a Y range rounded to
 * clean numbers around the actual values and the target.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS MADE UP
 * ---------------------------------------------------------------------------
 * Every point is a stored value on its own date, in the terms of the goal's
 * type (goal_series): a Mijlpaal's results with its best one named, a
 * Streak's length day by day, an Optellen total as it grew. Three points
 * means three dots. And the curve is monotone between points, so it can never
 * swing above the best day or below the lowest reading on its way from one to
 * the next — the shared Catmull-Rom smoothing can, which is fine for a score
 * trend and wrong for "you weighed 79.6 kg" when nobody ever did.
 */

declare(strict_types=1);

require_once __DIR__ . '/time-axis.php';

if (!defined('GOAL_CHART_W')) {
    define('GOAL_CHART_W', 1000.0);   // viewBox width; the SVG stretches to the card
    define('GOAL_CHART_H', 132.0);    // viewBox height = CSS height, so strokes stay 1:1
    define('GOAL_CHART_PAD', 0.03);   // keeps the end dots off the very edge
}

if (!function_exists('goal_chart_build')) {

    /* ==================================================================
       WORDS: units, numbers, dates
       ================================================================== */

    /**
     * How this goal's values are written.
     *
     * A day-counting goal plots days, whatever it reads them from: a streak's
     * line is its length, not the steps behind it. Otherwise an automatic
     * goal speaks its source's unit (includes/goal-progress.php keeps that
     * list, so the wizard, the labels and this chart agree), and a hand-kept
     * one uses whatever the person called it.
     *
     * @return array{word: string, one: string, axis: string, scale: float, decimals: int, attached: bool}
     */
    function goal_chart_unit(array $goal, ?string $mode = null): array
    {
        require_once dirname(__DIR__) . '/includes/goal-progress.php';

        if ($mode === 'streak' || $mode === 'count') {
            return ['word' => 'dagen', 'one' => 'dag', 'axis' => 'dagen', 'scale' => 1.0, 'decimals' => 0, 'attached' => false];
        }

        if (($goal['tracking'] ?? 'manual') !== 'auto') {
            $word = trim((string) ($goal['target_unit'] ?? ''));

            return ['word' => $word, 'one' => $word, 'axis' => $word, 'scale' => 1.0, 'decimals' => 1, 'attached' => false];
        }

        $unit = goal_source_unit($goal['source_kind'] ?? null, $goal['source_key'] ?? null);

        return $unit + ['axis' => $unit['word']];
    }

    /** Dutch digits: a point for thousands, a comma for decimals, no trailing zeroes. */
    function goal_chart_number(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, ',', '.');

        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), ',');
        }

        return $text === '-0' ? '0' : $text;
    }

    /** A value with its unit, the way the tooltip and the end label say it. */
    function goal_chart_value(float $raw, array $unit): string
    {
        $shown = $raw * $unit['scale'];
        $text  = goal_chart_number($shown, $unit['decimals']);

        if ($unit['word'] === '') {
            return $text;
        }

        $word = round($shown, $unit['decimals']) == 1.0 ? $unit['one'] : $unit['word'];

        return $unit['attached'] ? $text . $word : $text . ' ' . $word;
    }

    function goal_chart_month(int $month, bool $long): string
    {
        static $names = [
            1 => ['jan', 'januari'], 2 => ['feb', 'februari'], 3 => ['mrt', 'maart'],
            4 => ['apr', 'april'], 5 => ['mei', 'mei'], 6 => ['jun', 'juni'],
            7 => ['jul', 'juli'], 8 => ['aug', 'augustus'], 9 => ['sep', 'september'],
            10 => ['okt', 'oktober'], 11 => ['nov', 'november'], 12 => ['dec', 'december'],
        ];

        return $names[$month][$long ? 1 : 0];
    }

    /**
     * "12 september". The year only when it is not this one — every value
     * here is a day, so a time of day would be precision the data does not
     * have.
     */
    function goal_chart_date_long(DateTimeImmutable $day, DateTimeImmutable $today): string
    {
        $text = (int) $day->format('j') . ' ' . goal_chart_month((int) $day->format('n'), true);

        return $day->format('Y') === $today->format('Y') ? $text : $text . ' ' . $day->format('Y');
    }

    /* ==================================================================
       SCALES
       ================================================================== */

    /**
     * A clean step between Y ticks — 1, 2 or 5 times a power of ten — so the
     * axis reads 76 / 78 / 80 and never 76.4 / 78.1 / 79.8.
     */
    function goal_chart_step(float $low, float $high, int $count): float
    {
        $raw = ($high - $low) / max(1, $count);

        if ($raw <= 0) {
            return 1.0;
        }

        $step  = 10 ** floor(log10($raw));
        $error = $raw / $step;

        if ($error >= 7.07) {
            $step *= 10;
        } elseif ($error >= 3.16) {
            $step *= 5;
        } elseif ($error >= 1.41) {
            $step *= 2;
        }

        return $step;
    }

    /** How many decimals a step needs to be written exactly. */
    function goal_chart_step_decimals(float $step): int
    {
        for ($d = 0; $d <= 3; $d++) {
            if (abs(round($step, $d) - $step) < 1e-9) {
                return $d;
            }
        }

        return 3;
    }

    /**
     * A monotone cubic through the points (Fritsch–Carlson).
     *
     * Smooth like the rest of the app's lines, but it never overshoots: between
     * two points the curve stays between their two values. The shared helper
     * does not promise that, and on a chart of real measurements an overshoot
     * is a value that was never measured.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    function goal_chart_monotone(array $points): string
    {
        $n = count($points);

        if ($n === 0) {
            return '';
        }

        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        if ($n === 1) {
            return 'M ' . $fmt($points[0][0]) . ' ' . $fmt($points[0][1]);
        }

        $dx = $dy = $m = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $dx[$i] = $points[$i + 1][0] - $points[$i][0];
            $dy[$i] = $points[$i + 1][1] - $points[$i][1];
            $m[$i]  = $dx[$i] == 0.0 ? 0.0 : $dy[$i] / $dx[$i];
        }

        $t = array_fill(0, $n, 0.0);
        $t[0]      = $m[0];
        $t[$n - 1] = $m[$n - 2];

        for ($i = 1; $i < $n - 1; $i++) {
            $t[$i] = ($m[$i - 1] * $m[$i] <= 0) ? 0.0 : ($m[$i - 1] + $m[$i]) / 2;
        }

        for ($i = 0; $i < $n - 1; $i++) {
            if ($m[$i] == 0.0) {
                $t[$i] = $t[$i + 1] = 0.0;
                continue;
            }

            $a = $t[$i] / $m[$i];
            $b = $t[$i + 1] / $m[$i];
            $h = $a * $a + $b * $b;

            if ($h > 9) {
                $k = 3 / sqrt($h);
                $t[$i]     = $k * $a * $m[$i];
                $t[$i + 1] = $k * $b * $m[$i];
            }
        }

        $path = 'M ' . $fmt($points[0][0]) . ' ' . $fmt($points[0][1]);

        for ($i = 0; $i < $n - 1; $i++) {
            $third = $dx[$i] / 3;
            $path .= ' C ' . $fmt($points[$i][0] + $third) . ' ' . $fmt($points[$i][1] + $t[$i] * $third)
                . ', ' . $fmt($points[$i + 1][0] - $third) . ' ' . $fmt($points[$i + 1][1] - $t[$i + 1] * $third)
                . ', ' . $fmt($points[$i + 1][0]) . ' ' . $fmt($points[$i + 1][1]);
        }

        return $path;
    }

    /* ==================================================================
       THE CHART
       ================================================================== */

    /**
     * Everything the template needs to draw one goal's Verloop.
     *
     * Positions come back as percentages of the plot box, so the dots, the
     * crosshair and the tooltip — which are HTML, not SVG — land exactly on
     * the line at any width without being stretched into ellipses.
     */
    function goal_chart_build(array $goal, array $series, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $mode   = (string) ($series['mode'] ?? 'best');
        $unit   = goal_chart_unit($goal, $mode);
        $points = $series['points'] ?? [];

        $empty = [
            'has_data' => false, 'points' => [], 'line' => [], 'area' => [],
            'x_ticks' => [], 'y_ticks' => [], 'target' => null, 'end' => null,
            'axis_unit' => $unit['axis'], 'width' => GOAL_CHART_W, 'height' => GOAL_CHART_H,
            'all_dots' => false, 'summary' => '', 'mode' => $mode,
        ];

        /* No history yet: the week from today, its dates and nothing on it. */
        $empty['x_ticks'] = array_map(static fn (array $t): array => [
            'left'  => round((GOAL_CHART_PAD + $t['x'] / 100 * (1 - 2 * GOAL_CHART_PAD)) * 100, 3),
            'label' => $t['label'],
            'align' => 'center',
        ], time_axis(7, null, $today->format('Y-m-d'))['ticks']);

        if ($points === []) {
            return $empty;
        }

        /* ------------------------------------------------------- X domain */
        /* Ownify's time axis: the shortest standard period that holds the
           goal's history from its first point, the history at the left and
           the rest of the period empty ahead of it — never the few days
           there are stretched over the card; past a year, the year that
           ends today. A point from before that year is off the chart. */
        $axis  = time_axis(time_axis_fit((string) $points[0]['date'], $today->format('Y-m-d')), (string) $points[0]['date'], $today->format('Y-m-d'));
        $skip  = 0;
        while ($skip < count($points) && (string) $points[$skip]['date'] < $axis['start']) {
            $skip++;
        }
        $before = $skip > 0 ? (float) $points[$skip - 1]['value'] : 0.0;   // what a total had grown to before the year
        $points = array_values(array_slice($points, $skip));
        if ($points === []) {
            return $empty;
        }
        $dates = array_map(static fn (array $p): DateTimeImmutable => new DateTimeImmutable($p['date']), $points);

        $xOf = static fn (DateTimeImmutable $d): float
            => GOAL_CHART_PAD + time_axis_x($axis, $d->format('Y-m-d')) / 100 * (1 - 2 * GOAL_CHART_PAD);

        /* ------------------------------------------------------- Y domain */
        $values = array_map(static fn (array $p): float => (float) $p['value'] * $unit['scale'], $points);
        $target = $series['target'] === null ? null : (float) $series['target'] * $unit['scale'];

        $low  = min($values);
        $high = max($values);

        /* The target is part of the picture: how far the line is from where
           it is going is most of what the chart is for. */
        if ($target !== null) {
            $low  = min($low, $target);
            $high = max($high, $target);
        }

        /* A count is read from zero; a body weight is not. Zero comes in for
           a running total, a streak and a count of days, and for anything whose
           values already reach down near it — never for 74-82 kg, where it
           would flatten the only movement that matters. */
        $counts = in_array($mode, ['total', 'streak', 'count'], true);
        if ($low >= 0 && ($counts || ($high > 0 && $low / $high < 0.5))) {
            $low = 0.0;
        }

        if ($high - $low < 1e-9) {
            $pad   = max(1.0, abs($high) * 0.1);
            $low  -= $pad;
            $high += $pad;
            if ($low < 0 && min($values) >= 0) {
                $low = 0.0;
            }
        }

        $step  = goal_chart_step($low, $high, 4);
        $yMin  = floor($low / $step) * $step;
        $yMax  = ceil($high / $step) * $step;
        if ($yMax - $yMin < 1e-9) {
            $yMax = $yMin + $step;
        }

        $yOf = static fn (float $v): float => ($yMax - $v) / ($yMax - $yMin);

        $tickDecimals = goal_chart_step_decimals($step);
        $yTicks = [];
        for ($v = $yMin; $v <= $yMax + $step / 2; $v += $step) {
            $yTicks[] = [
                'top'   => round($yOf($v) * 100, 3),
                'label' => goal_chart_number($v, $tickDecimals),
            ];
        }

        /* ------------------------------------------------------- X ticks */
        /* The period's own dates, each under its day (lib/time-axis.php). */
        $xTicks = array_map(static fn (array $t): array => [
            'left'  => round($xOf(new DateTimeImmutable($t['date'])) * 100, 3),
            'label' => $t['label'],
            'align' => 'center',
        ] + array_intersect_key($t, ['day' => 0, 'month' => 0]), $axis['ticks']);

        /* ------------------------------------------------------- points */
        /* Each point also says what the day itself did and how far the goal
           was that day ('n'), from what is stored: an Optellen total the
           amount that day added, a day-counting goal that the day counted,
           and the percentage goal_progress kept for that date — what the
           Recent block used to list. A day without one says nothing more. */
        $percents = $series['percents'] ?? [];
        $out = [];
        foreach ($points as $i => $p) {
            $value = (float) $p['value'];
            $prev  = $i > 0 ? (float) $points[$i - 1]['value'] : $before;
            $note  = [];

            if ($mode === 'total') {
                $note[] = '+ ' . goal_chart_value($value - $prev, $unit);
            } elseif ($mode === 'count' && $value > $prev) {
                $note[] = 'Gehaald';
            }

            if (isset($percents[$p['date']])) {
                $note[] = $percents[$p['date']] . '% van je doel';
            }

            $out[] = [
                'x'     => round($xOf($dates[$i]) * 100, 3),
                'y'     => round($yOf($values[$i]) * 100, 3),
                'date'  => $p['date'],
                'd'     => goal_chart_date_long($dates[$i], $today),
                'v'     => goal_chart_value($value, $unit),
                'n'     => implode(' · ', $note),
            ];
        }

        /* ------------------------------------------------------- line runs */
        /* A series that asks for breaks gets them: a missing day is then a
           gap, not a bridge, because joining Monday to Thursday would draw
           Tuesday and Wednesday and nobody recorded those. None of the three
           types asks today — a streak's line is its length, which is known on
           every day, and a total or a best result only moves on days with
           data — but the geometry keeps the option. */
        $runs = [];
        $run  = [];
        foreach ($out as $i => $p) {
            if ($run !== [] && !empty($series['breaks'])
                && (int) $dates[$i - 1]->diff($dates[$i])->days > 1) {
                $runs[] = $run;
                $run = [];
            }
            $run[] = [$p['x'] / 100 * GOAL_CHART_W, $p['y'] / 100 * GOAL_CHART_H];
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        $lines = $areas = [];
        foreach ($runs as $segment) {
            if (count($segment) < 2) {
                continue;               // a lone day is a dot, drawn below
            }

            $path    = goal_chart_monotone($segment);
            $lines[] = $path;
            $areas[] = $path
                . ' L ' . round($segment[count($segment) - 1][0], 2) . ' ' . GOAL_CHART_H
                . ' L ' . round($segment[0][0], 2) . ' ' . GOAL_CHART_H . ' Z';
        }

        /* Every point gets a dot while there are few enough to tell apart; past
           that, the line carries the shape and only the latest keeps its dot.
           A lone day in a broken daily line always keeps one, or it would not
           be visible at all. */
        $allDots = count($out) <= 16;
        $lone    = [];
        $offset  = 0;
        foreach ($runs as $segment) {
            if (count($segment) === 1) {
                $lone[] = $offset;
            }
            $offset += count($segment);
        }

        /* A Mijlpaal is measured by its best result, so that is the point
           the label names — not the latest, which may well be worse and
           changes nothing. */
        $bestIndex = null;
        if ($mode === 'best' && !empty($series['best'])) {
            foreach ($out as $i => $p) {
                if ($p['date'] === $series['best']) {
                    $bestIndex = $i;
                    break;
                }
            }
        }

        foreach ($out as $i => &$p) {
            $p['dot'] = $allDots || in_array($i, $lone, true) || $i === count($out) - 1 || $i === $bestIndex;
        }
        unset($p);

        /* ------------------------------------------------------- labels */
        $lastPoint = $out[count($out) - 1];
        $named     = $bestIndex === null ? $lastPoint : $out[$bestIndex];
        $endLabel  = [
            'x'     => $named['x'],
            'y'     => $named['y'],
            'label' => $bestIndex === null ? $named['v'] : 'Beste ' . $named['v'],
            'below' => $named['y'] < 22,   // no room above a point at the top
            /* Ends at its point near the right edge, starts at it near the
               left, and sits over it in between — never off the plot. */
            'align' => $named['x'] > 70 ? 'end' : ($named['x'] < 30 ? 'start' : 'center'),
        ];

        $targetOut = null;
        if ($target !== null) {
            $targetOut = [
                'top'   => round($yOf($target) * 100, 3),
                'label' => 'Doel ' . goal_chart_value((float) $series['target'], $unit),
            ];

            /* If the end label would sit on the target line, drop it to the
               other side of its point rather than print one over the other. */
            if (abs($targetOut['top'] - $named['y']) < 12) {
                $endLabel['below'] = $targetOut['top'] < $named['y'];
            }
        }

        $span = $out[0]['d'] === $lastPoint['d'] ? $out[0]['d'] : $out[0]['d'] . ' tot ' . $lastPoint['d'];
        $summary = match ($mode) {
            'streak' => sprintf('Streak per dag, %s: nu %s.', $span, $lastPoint['v']),
            'count'  => sprintf('Gehaalde dagen, %s: %s.', $span, $lastPoint['v']),
            'total'  => sprintf('Totaal, %s: %s.', $span, $lastPoint['v']),
            default  => sprintf(
                '%d %s, %s. Beste resultaat %s op %s.',
                count($out),
                count($out) === 1 ? 'resultaat' : 'resultaten',
                $span,
                $named['v'],
                $named['d']
            ),
        };
        if ($targetOut !== null) {
            $summary .= ' ' . $targetOut['label'] . '.';
        }

        return [
            'has_data'  => true,
            'points'    => $out,
            'line'      => $lines,
            'area'      => $areas,
            'x_ticks'   => $xTicks,
            'y_ticks'   => $yTicks,
            'target'    => $targetOut,
            'end'       => $endLabel,
            'axis_unit' => $unit['axis'],
            'width'     => GOAL_CHART_W,
            'height'    => GOAL_CHART_H,
            'all_dots'  => $allDots,
            'summary'   => $summary,
            'mode'      => $mode,
        ];
    }
}

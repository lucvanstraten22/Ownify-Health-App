<?php
/**
 * Fills the Scorekompas for the signed-in user (includes/score-compass.php).
 *
 * The history is the Health Score as it was recorded each day (daily_scores,
 * via health_score_history()) over the longest period the compass shows, a
 * year — never recalculated from today's records — and today's score as it
 * stands. The categories are named and coloured as Overzicht names them; the
 * lines are drawn here, once, for the website and the app alike.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/goal-chart.php';

if (!function_exists('hydrate_compass')) {

    /**
     * @param array $copy  config/compass.php
     * @param array $data  app_page_data() so far: the ring's legend (scores.contributors)
     *                     and the Gezondheid areas (health.areas), already filled
     */
    function hydrate_compass(array $copy, array $data, int $userId): array
    {
        $days    = max(
            2 * (int) $copy['rules']['period_days'],
            ...array_map(static fn ($p) => (int) $p['days'], $copy['history']['periods'])
        );
        $history = db_available() ? health_score_history($userId, $days) : [];
        $compass = score_compass($history, $copy, hydrate_compass_areas($data));

        /* The 30 days of the direction (what an older app draws), and each
           period's line, in the same 300 × 120 box as Gezondheid's trend. */
        $compass['trend']['chart'] = hydrate_compass_chart($compass['trend']['values']);

        foreach ($compass['trend']['periods'] as $i => $period) {
            $compass['trend']['periods'][$i]['chart'] = hydrate_compass_chart($period['values']);
        }

        return $compass;
    }

    /**
     * One line: the paths, and every day's place in it in % of the box —
     * x left to right, y top to bottom, null for a day without a score — so
     * a finger on the line can find the day under it.
     */
    function hydrate_compass_chart(array $values): array
    {
        $width  = 300.0;
        $height = 120.0;
        $chart  = health_chart($values, $width, $height, 100.0, 0.0);
        $count  = count($values);

        $at = [];
        foreach ($values as $i => $value) {
            $point = $chart['points'][$i] ?? null;
            /* Where health_chart() put it: a single day at the left edge. */
            $x     = $count > 1 ? $i / ($count - 1) * 100 : 0.0;
            $at[]  = [round($x, 2), $point === null ? null : round($point[1] / $height * 100, 2)];
        }

        unset($chart['points']);

        return $chart + [
            'width'    => $width,
            'height'   => $height,
            'has_data' => health_series_has_data($values),
            'at'       => $at,
        ];
    }

    /**
     * Gezondheid's Verloop: Slaap, Voeding and Training as they were recorded
     * — the Scorekompas's history, its periods and its days, drawn as three
     * lines instead of the one Health Score. Nothing is scored or carried
     * here: every value is a category's score of health_score_history() as
     * the Scorekompas reads it that day — stored, carried as long as its
     * `valid_until` allows, or none (a gap, never a 0) — and every date is
     * the Scorekompas's.
     *
     *   7 and 30 days   a point a day, over the Scorekompas's window
     *   90 days         a point a week: the mean of each category's scores
     *                   over seven days, the last week ending today
     *   a year          a point a month (twelve over the last 365 days); a
     *                   history younger than half a year instead has twelve
     *                   half months from its first day — the half year it
     *                   is filling — and nothing yet where they lie ahead
     *
     * Weeks and months stand where they fall in time: a week three weeks
     * ago is three weeks from the right edge however short the history, so
     * a young history is never stretched over the chart. The height is the
     * period's own range in round tens, never less than 30 points — 73 to
     * 80 is a visible rise, 76 to 77 stays a small one — with its levels
     * named on the grid; every line is a monotone curve, which never bends
     * past a point.
     *
     * Each period carries its points (`points`: what a reading shows) and its
     * lines, drawn here, once, for the website and the app alike, in the
     * same 300 × 160 box. A period of days keeps its place in the
     * Scorekompas's list (`start`); a period of weeks or months points past
     * its end, so an older app reads nothing there rather than a wrong day.
     *
     * @param array $trend    hydrate_compass()['trend']: its periods and days
     * @param array $compass  config/compass.php (the periods' spoken names and dates)
     * @param array $copy     config/health.php `history`
     * @param array $areas    Gezondheid's areas: their names and colours, in order
     */
    function hydrate_health_history(array $trend, array $compass, array $copy, array $areas): array
    {
        $days = $trend['days'] ?? [];
        $year = $days === [] ? '' : substr((string) $days[count($days) - 1]['date'], 0, 4);
        $ids  = array_map('strval', array_keys($areas));

        $categories = [];
        foreach ($ids as $id) {
            $categories[] = ['id' => $id, 'label' => (string) $areas[$id]['label'], 'accent' => (string) $areas[$id]['accent']];
        }

        $names = array_column($categories, 'label');
        $which = count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)) . ' ' . ($copy['and'] ?? 'en') . ' ' . $names[count($names) - 1]
            : implode('', $names);

        $spoken = [];
        $tickAt = [];
        foreach ($compass['history']['periods'] as $period) {
            $spoken[(int) $period['days']] = (string) ($period['spoken'] ?? $period['label']);
            $tickAt[(int) $period['days']] = (int) ($period['ticks'] ?? 2);
        }

        $groups = (array) ($copy['group'] ?? [90 => 'week', 365 => 'month']);
        $half   = (int) ($copy['half_days'] ?? 183);

        $periods = [];
        foreach ($trend['periods'] ?? [] as $period) {
            $length = (int) $period['days'];
            $group  = (string) ($groups[$length] ?? 'day');
            /* A year of less than half a year's history: the half year it is filling. */
            if ($group === 'month' && count($days) < $half) {
                $group = 'half';
            }

            if ($group === 'day' || $days === []) {
                $built = hydrate_health_history_days($days, $period, $ids, $copy, $year);
                $group = 'day';
            } else {
                $built = hydrate_health_history_buckets($days, $group, $length, $half, $ids, $copy, $year, $tickAt[$length] ?? 4, (string) ($compass['trend']['today'] ?? ''));
            }

            $chart = hydrate_health_history_chart($built['x'], $built['points'], $ids, $areas);
            $per   = (string) ($copy['per'][$group] ?? '');

            $periods[] = [
                'key'    => (string) $period['key'],
                'label'  => (string) $period['label'],
                'days'   => $length,
                'group'  => $group,
                'start'  => $group === 'day' ? (int) $period['start'] : count($days),
                'since'  => $period['since'],
                'dots'   => (string) ($copy['dots'][$length] ?? 'alone'),
                'axis'   => $built['axis'] ?? $period['axis'],
                'every'  => $built['every'] ?? false,
                'aria'   => sprintf((string) ($copy['aria'] ?? '%1$s %2$s, %3$s'), $which, $per, $spoken[$length] ?? $period['label']),
                'points' => $built['points'],
                'chart'  => $chart,
            ];
        }

        return [
            'title'      => (string) ($copy['title'] ?? ''),
            'switch'     => (string) ($copy['switch'] ?? ''),
            'default'    => (string) ($trend['default'] ?? ''),
            'empty'      => (string) ($copy['empty'] ?? ''),
            'hint'       => (string) ($copy['hint'] ?? ''),
            'categories' => $categories,
            'periods'    => $periods,
        ];
    }

    /**
     * A period of days, as the Scorekompas has it: its window of its list,
     * a point a day, spread over the width as its own line is.
     */
    function hydrate_health_history_days(array $days, array $period, array $ids, array $copy, string $year): array
    {
        $length = (int) $period['days'];
        $slice  = array_slice($days, (int) $period['start'], count($period['values'] ?? []));
        $count  = count($slice);
        $ticks  = (int) ($copy['ticks'][$length] ?? 0);

        $points = [];
        $x      = [];
        foreach ($slice as $i => $day) {
            $x[]      = $count > 1 ? round($i / ($count - 1) * 100, 2) : 0.0;
            $points[] = [
                'label'   => (string) $day['label'],
                'detail'  => null,
                'note'    => $day['note'] ?? null,
                'carried' => ($day['state'] ?? '') === 'carried',
                'values'  => array_map(static fn ($id) => hydrate_health_history_score($day, $id), $ids),
            ];
        }

        $built = ['x' => $x, 'points' => $points];

        /* A week names every day, each under its own dots — today by its
           date too, as wide as the others; a month names as many dates as
           the Scorekompas does. */
        if ($ticks > 0 && $slice !== []) {
            $built['axis']  = score_compass_ticks(
                array_column($slice, 'date'),
                $ticks,
                $year,
                score_compass_date_in((string) $slice[$count - 1]['date'], $year, true)
            );
            $built['every'] = $ticks >= $count;
        }

        return $built;
    }

    /**
     * A period of weeks or months: its days in time, today the last of them
     * (or, for `half`, the first day of the history the first), cut into
     * buckets — seven days back from today, twelve of the 365, or twelve of
     * the half year — each the mean of every score a category had in it,
     * rounded as a score is. A bucket is a point where it holds days of the
     * history; one that lies before the history began or after today is no
     * point at all, and the place it would have stays empty.
     *
     * @return array{x: float[], points: array[], axis: array, every: bool}
     */
    function hydrate_health_history_buckets(array $days, string $group, int $length, int $half, array $ids, array $copy, string $year, int $ticks, string $today): array
    {
        $count = count($days);
        $last  = new DateTimeImmutable((string) $days[$count - 1]['date']);

        /* The period's span in days, and where the history's days fall in it. */
        if ($group === 'half') {
            $span   = $half;
            $offset = 0;                                // the history's first day is the first
        } else {
            $span   = $length;
            $offset = $span - $count;                   // today is the last; may start before the history
        }

        $bounds = [];
        if ($group === 'week') {
            for ($end = $span - 1; $end >= 0; $end -= 7) {
                array_unshift($bounds, [max(0, $end - 6), $end]);
            }
        } else {
            for ($k = 0; $k < 12; $k++) {
                $bounds[] = [(int) round($k * $span / 12), (int) round(($k + 1) * $span / 12) - 1];
            }
        }

        $x      = [];
        $points = [];
        foreach ($bounds as [$from, $to]) {
            /* Only the days of the history: none before its first, none after today. */
            $from = max($from, $offset);
            $to   = min($to, $offset + $count - 1);
            if ($from > $to) {
                continue;
            }

            $values = [];
            foreach ($ids as $id) {
                $scores = [];
                for ($d = $from; $d <= $to; $d++) {
                    $score = hydrate_health_history_score($days[$d - $offset], $id);
                    if ($score !== null) {
                        $scores[] = $score;
                    }
                }
                $values[] = $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
            }

            $none     = array_filter($values, static fn ($v) => $v !== null) === [];
            $x[]      = round(($from + $to) / 2 / ($span - 1) * 100, 2);
            $points[] = [
                /* One day so far — today, as a rule: that day, as the day's own reading names it. */
                'label'   => $from === $to
                    ? (string) $days[$from - $offset]['label']
                    : hydrate_health_history_range((string) $days[$from - $offset]['date'], (string) $days[$to - $offset]['date'], $year, $copy),
                'detail'  => $none ? null : ($copy['mean'][$group] ?? null),
                'note'    => $none ? ($copy['none'][$group] ?? null) : null,
                'carried' => false,
                'values'  => $values,
            ];
        }

        /* The dates under it: the span's own, today by name where it ends today. */
        $dates = [];
        $first = $last->modify(sprintf('-%d days', $offset + $count - 1));
        for ($d = 0; $d < $span; $d++) {
            $dates[] = $first->modify("+{$d} days")->format('Y-m-d');
        }
        $end = $group === 'half' ? score_compass_date_in($dates[$span - 1], $year, true) : $today;

        return [
            'x'      => $x,
            'points' => $points,
            'axis'   => score_compass_ticks($dates, $ticks, $year, $end),
            'every'  => false,
        ];
    }

    /** "12 – 18 sep", "28 aug – 3 sep", "28 dec 2025 – 3 jan": a bucket's days. */
    function hydrate_health_history_range(string $from, string $to, string $year, array $copy): string
    {
        $end   = score_compass_date_in($to, $year, true);
        $start = match (true) {
            substr($from, 0, 7) === substr($to, 0, 7) => (string) (int) substr($from, 8, 2),
            substr($from, 0, 4) === substr($to, 0, 4) => score_compass_date($from, true),
            default                                   => score_compass_date_in($from, $year, true),
        };

        return sprintf((string) ($copy['range'] ?? '%1$s – %2$s'), $start, $end);
    }

    /**
     * The three lines in the 300 × 160 box, over one height for all three:
     * the range their points span, widened to round tens and to at least 30
     * points, within 0–100 — and the round levels in it, named, as the grid.
     * A run of points without a gap is one monotone curve
     * (goal_chart_monotone()), a point on its own none: its dot is all.
     *
     * @param float[] $x       each point's place, in % from the left
     * @param array[] $points  each point's `values`, in the order of $ids
     */
    function hydrate_health_history_chart(array $x, array $points, array $ids, array $areas): array
    {
        $width  = 300.0;
        $height = 160.0;
        $padY   = 12.0;

        $all = [];
        foreach ($points as $point) {
            foreach ($point['values'] as $value) {
                if ($value !== null) {
                    $all[] = $value;
                }
            }
        }

        [$low, $high] = hydrate_health_history_range_of($all);
        $at = static fn (int $value): float => round(($padY + (1 - ($value - $low) / ($high - $low)) * ($height - 2 * $padY)) / $height * 100, 2);

        $lines = [];
        foreach ($ids as $k => $id) {
            $y    = [];
            $runs = [];
            $run  = [];
            foreach ($points as $i => $point) {
                $value = $point['values'][$k];
                $y[]   = $value === null ? null : $at($value);
                if ($value === null) {
                    if ($run !== []) { $runs[] = $run; $run = []; }
                    continue;
                }
                $run[] = [$x[$i] / 100 * $width, $y[$i] / 100 * $height];
            }
            if ($run !== []) { $runs[] = $run; }

            $lines[] = [
                'id'     => $id,
                'accent' => (string) $areas[$id]['accent'],
                'line'   => array_values(array_map('goal_chart_monotone', array_filter($runs, static fn ($r) => count($r) > 1))),
                'y'      => $y,
            ];
        }

        $step = match (true) {
            $high - $low <= 50 => 10,
            $high - $low <= 80 => 20,
            default            => 25,
        };
        $grid = [];
        for ($level = (int) (floor($low / $step) + 1) * $step; $level < $high; $level += $step) {
            $grid[] = ['y' => $at($level), 'label' => (string) $level];
        }

        return [
            'width'    => $width,
            'height'   => $height,
            'has_data' => $all !== [],
            'x'        => $x,
            'lines'    => $lines,
            'grid'     => $grid,
            'low'      => $low,
            'high'     => $high,
        ];
    }

    /**
     * The height the scores are drawn over: from the lowest less 5 to the
     * highest plus 5, out to round tens, at least 30 points tall, inside
     * 0–100. 0–100 itself without any score.
     *
     * @param int[] $values
     * @return array{0: int, 1: int}
     */
    function hydrate_health_history_range_of(array $values): array
    {
        if ($values === []) {
            return [0, 100];
        }

        $low  = max(0, (int) floor((min($values) - 5) / 10) * 10);
        $high = min(100, (int) ceil((max($values) + 5) / 10) * 10);

        /* Too narrow: wider by tens, the top first, until 30 — never past 0 or 100. */
        while ($high - $low < 30) {
            if ($high < 100 && ($high - max($values) <= min($values) - $low || $low === 0)) {
                $high += 10;
            } else {
                $low -= 10;
            }
        }

        return [$low, $high];
    }

    /** One category's score on a day of the Scorekompas's list; null when it had none. */
    function hydrate_health_history_score(array $day, string $id): ?int
    {
        foreach ($day['categories'] ?? [] as $category) {
            if ((string) $category['id'] === $id) {
                return $category['value'] === null ? null : (int) $category['value'];
            }
        }

        return null;
    }

    /**
     * The three categories as Overzicht shows them — its legend's names, in
     * its order — with Gezondheid's icon, colour and empty states.
     *
     * @return array<string,array{label: string, accent: string, icon: string, empty: string, collecting: string}>
     */
    function hydrate_compass_areas(array $data): array
    {
        $areas = [];

        foreach ($data['scores']['contributors'] ?? [] as $row) {
            $id   = (string) $row['area'];
            $area = $data['health']['areas'][$id] ?? [];

            $areas[$id] = [
                'label'      => (string) $row['label'],
                'accent'     => (string) ($row['accent'] ?? $area['accent'] ?? $id),
                'icon'       => (string) ($area['icon'] ?? ''),
                'empty'      => (string) ($area['empty'] ?? ''),
                'collecting' => (string) ($area['collecting'] ?? ''),
            ];
        }

        return $areas;
    }
}

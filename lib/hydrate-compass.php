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
require_once __DIR__ . '/time-axis.php';

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

        /* The 30 days of the direction, as an older app draws them, in the
           300 × 120 box of Gezondheid's trend; and each period's line, drawn
           as Gezondheid's Verloop draws its three. */
        $compass['trend']['chart'] = hydrate_compass_chart($compass['trend']['values']);
        $compass['trend']['periods'] = hydrate_compass_periods($compass['trend'], $copy);

        return $compass;
    }

    /**
     * The Scorekompas's periods drawn as Gezondheid's Verloop is
     * (hydrate_health_history()), its one line the Health Score, on Ownify's
     * time axis (lib/time-axis.php, docs/CHARTS.md): the period's window —
     * a young history from its first day at the left, the rest empty ahead;
     * a full one rolling, today at the right — its dates, and a point a day
     * over 7 and 30 days, a week over 90 and a month over a year, over the
     * period's own height with its levels named, a monotone curve. Nothing
     * else of a period changes: its sentences, direction and `since` are the
     * Scorekompas's, worked out from its days as before.
     *
     * Each period carries its points (`points`): what a reading shows, in the
     * line and in the panel under it — a day of the list as it is, or a week
     * or month as one: its days, that its scores are their mean, the Health
     * Score's and each category's mean (no parts). A period of days keeps its
     * place in the list (`start`: its first day); a period of weeks or months
     * points past its end, so an older app reads nothing there rather than a
     * wrong day.
     */
    function hydrate_compass_periods(array $trend, array $copy): array
    {
        $h     = $copy['history'];
        $days  = $trend['days'] ?? [];
        $ids   = array_map(static fn ($c) => (string) $c['id'], $trend['readout']['categories'] ?? []);
        $words = ['range' => $h['range'] ?? '%1$s – %2$s', 'mean' => $h['mean'] ?? [], 'none' => $h['empty_at'] ?? []];

        $config = [];
        foreach ($h['periods'] as $p) {
            $config[(int) $p['days']] = $p;
        }

        /* A day's scores: the Health Score, then each category's. */
        $read = static function (array $day) use ($ids): array {
            $values = array_column($day['categories'] ?? [], 'value', 'id');
            return [$day['value'] ?? null, ...array_map(static fn ($id) => $values[$id] ?? null, $ids)];
        };

        $periods = [];
        foreach ($trend['periods'] ?? [] as $period) {
            $length = (int) $period['days'];
            $built  = hydrate_history_period($days, $length, $read, ['score' => 'health'], $words);
            $grain  = $built['grain'];

            $points = array_map(static fn ($p) => $grain === 'day'
                ? $days[$p['day']] + ['detail' => null]
                : [
                    'date'       => $p['date'],
                    'label'      => $p['label'],
                    'detail'     => $p['detail'],
                    'value'      => $p['values'][0],
                    'band'       => score_colour_band($p['values'][0]),
                    'state'      => $p['values'][0] === null ? 'none' : 'stored',
                    'note'       => $p['note'],
                    'categories' => array_map(static fn ($id, $k) => [
                        'id'    => $id,
                        'value' => $p['values'][$k + 1] ?? null,
                        'band'  => score_colour_band($p['values'][$k + 1] ?? null),
                        'parts' => null,
                    ], $ids, array_keys($ids)),
                ], $built['points']);

            $direction = $period['direction'] === null ? '' : ': ' . mb_strtolower((string) $period['direction']['label']);
            $line      = $built['chart']['lines'][0];

            $periods[] = ['group' => $grain] + array_merge($period, [
                'start'    => $built['start'],
                'axis'     => $built['axis'],
                'every'    => true,
                /* Every day of a week, every week and every month a dot; a
                   month's days only where a line begins or a day stands alone. */
                'day_dots' => $grain !== 'day' || !empty($config[$length]['day_dots']),
                'aria'     => $grain === 'day'
                    ? $period['aria']
                    : sprintf((string) ($h['aria_per'][$grain] ?? $h['aria']), (string) ($config[$length]['spoken'] ?? $period['label']), $direction),
                'points'   => $points,
                'chart'    => [
                    'width'    => $built['chart']['width'],
                    'height'   => $built['chart']['height'],
                    'has_data' => $built['chart']['has_data'],
                    'line'     => $line['line'],
                    'area'     => [],
                    'at'       => array_map(static fn ($x, $y) => [$x, $y], $built['chart']['x'], $line['y']),
                    'grid'     => $built['chart']['grid'],
                    'low'      => $built['chart']['low'],
                    'high'     => $built['chart']['high'],
                ],
            ]);
        }

        return $periods;
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
     * Every period stands on Ownify's time axis (lib/time-axis.php,
     * docs/CHARTS.md): a young history starts at the left on its first day
     * and the rest of the period stays empty ahead of it; a full one rolls,
     * today at the right. A point a day over 7 and 30 days, a week over 90
     * and a month over a year, each the mean of its days; a line begins at a
     * point of its own, never before its category's first score. The height
     * is the period's own range in round tens, never less than 30 points,
     * with its levels named; every line is a monotone curve.
     *
     * Each period carries its points (`points`: what a reading shows) and its
     * lines, drawn here, once, for the website and the app alike, in the
     * same 300 × 160 box — together, and each on its own (`solo`: over its own
     * height, for that category's page). A period of days keeps its place in
     * the Scorekompas's list (`start`: its first day); a period of weeks or
     * months points past its end, so an older app reads nothing there rather
     * than a wrong day.
     *
     * @param array $trend    hydrate_compass()['trend']: its periods and days
     * @param array $compass  config/compass.php (the periods' spoken names)
     * @param array $copy     config/health.php `history`
     * @param array $areas    Gezondheid's areas: their names and colours, in order
     */
    function hydrate_health_history(array $trend, array $compass, array $copy, array $areas): array
    {
        $days = $trend['days'] ?? [];
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
        foreach ($compass['history']['periods'] as $period) {
            $spoken[(int) $period['days']] = (string) ($period['spoken'] ?? $period['label']);
        }

        /* Each day's scores, in the categories' order; each line's colour. */
        $read    = static fn (array $day): array => array_map(static fn ($id) => hydrate_health_history_score($day, $id), $ids);
        $accents = array_combine($ids, array_map(static fn ($id) => (string) $areas[$id]['accent'], $ids));

        $periods = [];
        foreach ($trend['periods'] ?? [] as $period) {
            $length = (int) $period['days'];
            $built  = hydrate_history_period($days, $length, $read, $accents, $copy);
            $chart  = $built['chart'];

            /* Each line on its own, over its own height: a category's page. */
            foreach ($chart['lines'] as $k => $line) {
                $alone = hydrate_health_history_chart(
                    $chart['x'],
                    array_map(static fn ($p) => ['values' => [$p['values'][$k]]], $built['points']),
                    [$line['id'] => $line['accent']]
                );
                $chart['lines'][$k]['solo'] = [
                    'line'     => $alone['lines'][0]['line'],
                    'y'        => $alone['lines'][0]['y'],
                    'grid'     => $alone['grid'],
                    'has_data' => $alone['has_data'],
                    'aria'     => sprintf((string) ($copy['aria'] ?? '%1$s %2$s, %3$s'), (string) $areas[$line['id']]['label'],
                        (string) ($copy['per'][$built['grain']] ?? ''), $spoken[$length] ?? $period['label']),
                ];
            }

            $periods[] = [
                'key'    => (string) $period['key'],
                'label'  => (string) $period['label'],
                'days'   => $length,
                'group'  => $built['grain'],
                'start'  => $built['start'],
                'since'  => $period['since'],
                'dots'   => (string) ($copy['dots'][$length] ?? 'alone'),
                'axis'   => $built['axis'],
                'every'  => true,
                'aria'   => sprintf((string) ($copy['aria'] ?? '%1$s %2$s, %3$s'), $which, (string) ($copy['per'][$built['grain']] ?? ''), $spoken[$length] ?? $period['label']),
                'points' => array_map(static fn ($p) => array_diff_key($p, ['day' => 0]), $built['points']),
                'chart'  => $chart,
            ];
        }

        return [
            'title'      => (string) ($copy['title'] ?? ''),
            'switch'     => (string) ($copy['switch'] ?? ''),
            'default'    => (string) ($trend['default'] ?? ''),
            'empty'      => (string) ($copy['empty'] ?? ''),
            'hint'       => (string) ($copy['hint'] ?? ''),
            'hint_one'   => (string) ($copy['hint_one'] ?? $copy['hint'] ?? ''),
            'categories' => $categories,
            'periods'    => $periods,
        ];
    }

    /**
     * One period of a history over the Scorekompas's days, on Ownify's time
     * axis: its window and dates (time_axis()), its points
     * (hydrate_history_points()) and its lines (hydrate_health_history_chart()),
     * and — for an older app — where its first day is in the list.
     *
     * @param array    $days     the Scorekompas's list, oldest first; the last is today
     * @param callable $read     a day's scores, a line each
     * @param array    $accents  the lines drawn: id => colour, in the order of $read
     * @param array    $copy     `range`, and `mean` and `none` by grain
     * @return array{grain: string, axis: array, points: array, chart: array, start: int}
     */
    function hydrate_history_period(array $days, int $length, callable $read, array $accents, array $copy): array
    {
        $today  = $days === [] ? (new DateTimeImmutable('today'))->format('Y-m-d') : (string) $days[count($days) - 1]['date'];
        $axis   = time_axis($length, $days === [] ? null : (string) $days[0]['date'], $today);
        $points = hydrate_history_points($days, $axis, $read, $copy, substr($today, 0, 4));
        $chart  = hydrate_health_history_chart(array_column($points, 'x'), $points, $accents);

        return [
            'grain'  => $axis['grain'],
            'axis'   => array_map(static fn ($t) => array_diff_key($t, ['date' => 0]), $axis['ticks']),
            'points' => $points,
            'chart'  => $chart,
            'start'  => $axis['grain'] === 'day' && $days !== []
                ? (int) (new DateTimeImmutable((string) $days[0]['date']))->diff(new DateTimeImmutable($axis['start']))->format('%a')
                : count($days),
        ];
    }

    /**
     * A period's points: one for each of its axis's slots that has begun.
     *
     *   a day      that day of the list as it was: its date, its note, each
     *              line's score, whether an earlier score was carried
     *   a week     its days so far: each line's mean over the scores it had
     *   or month   in them, rounded as a score is; that it is a mean; or that
     *              it had none
     *
     * A line begins at a point of its own: a week or month that began before
     * that line's first score is not its point — the line starts at the next
     * one — so no line ever reaches back before its category existed. Days
     * without a score are left out of a mean and are never a 0.
     *
     * @return list<array{x: float, date: string, label: string, detail: ?string, note: ?string, carried: bool, values: list<?int>, day: int}>
     */
    function hydrate_history_points(array $days, array $axis, callable $read, array $copy, string $year): array
    {
        if ($days === []) {
            return [];
        }

        $first  = new DateTimeImmutable((string) $days[0]['date']);
        $at     = static fn (string $date): int => (int) $first->diff(new DateTimeImmutable($date))->format('%r%a');
        $scores = array_map($read, $days);
        $last   = count($days) - 1;

        /* Where each line begins: its first day with a score. */
        $begins = [];
        foreach ($scores as $i => $values) {
            foreach ($values as $k => $value) {
                if ($value !== null && !isset($begins[$k])) {
                    $begins[$k] = $i;
                }
            }
        }

        $points = [];
        foreach ($axis['slots'] as $slot) {
            $from = max(0, $at($slot['from']));
            $to   = min($last, $at($slot['to']));
            if ($from > $to) {
                continue;
            }

            if ($axis['grain'] === 'day') {
                $day      = $days[$from];
                $points[] = [
                    'x'       => $slot['x'],
                    'date'    => (string) $day['date'],
                    'label'   => (string) $day['label'],
                    'detail'  => null,
                    'note'    => $day['note'] ?? null,
                    'carried' => ($day['state'] ?? '') === 'carried',
                    'values'  => $scores[$from],
                    'day'     => $from,
                ];
                continue;
            }

            $values = [];
            foreach (array_keys($scores[$from]) as $k) {
                if (!isset($begins[$k]) || $begins[$k] > $from) {
                    $values[] = null;                 // the line has not begun at this point
                    continue;
                }
                $kept = [];
                for ($d = $from; $d <= $to; $d++) {
                    if ($scores[$d][$k] !== null) {
                        $kept[] = $scores[$d][$k];
                    }
                }
                $values[] = $kept === [] ? null : (int) round(array_sum($kept) / count($kept));
            }

            $none     = array_filter($values, static fn ($v) => $v !== null) === [];
            $grain    = $axis['grain'];
            $points[] = [
                'x'       => $slot['x'],
                'date'    => (string) $days[$to]['date'],
                /* One day so far — today, as a rule: that day, as its own reading names it. */
                'label'   => $from === $to
                    ? (string) $days[$from]['label']
                    : hydrate_health_history_range((string) $days[$from]['date'], (string) $days[$to]['date'], $year, $copy),
                'detail'  => $none ? null : ($copy['mean'][$grain] ?? null),
                'note'    => $none ? ($copy['none'][$grain] ?? null) : null,
                'carried' => false,
                'values'  => $values,
                'day'     => $from,
            ];
        }

        return $points;
    }

    /** "12 – 18 sep", "28 aug – 3 sep", "28 dec 2025 – 3 jan": a point's days, in its reading. */
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
     * The lines in the 300 × 160 box — Gezondheid's three, the Scorekompas's
     * one — over one height for all of them:
     * the range their points span, widened to round tens and to at least 30
     * points, within 0–100 — and the round levels in it, named, as the grid.
     * A run of points without a gap is one monotone curve
     * (goal_chart_monotone()), a point on its own none: its dot is all.
     *
     * @param float[] $x       each point's place, in % from the left
     * @param array[] $points  each point's `values`, a line each
     * @param array<string,string> $accents  each line's id and colour, in the order of `values`;
     *                                       a value past them is not drawn
     */
    function hydrate_health_history_chart(array $x, array $points, array $accents): array
    {
        $width  = 300.0;
        $height = 160.0;
        $padY   = 12.0;

        $ids = array_keys($accents);
        $all = [];
        foreach ($points as $point) {
            foreach (array_slice($point['values'], 0, count($ids)) as $value) {
                if ($value !== null) {
                    $all[] = $value;
                }
            }
        }

        [$low, $high] = hydrate_health_history_range_of($all);
        $at = static fn (int $value): float => round(($padY + (1 - ($value - $low) / ($high - $low)) * ($height - 2 * $padY)) / $height * 100, 2);

        $lines = [];
        foreach ($ids as $k => $id) {
            $id   = (string) $id;
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
                'accent' => $accents[$id],
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

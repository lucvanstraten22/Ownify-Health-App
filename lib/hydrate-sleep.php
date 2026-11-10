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
 *                large, over every period — Ownify's area charts
 *                (lib/area-charts.php), which Training's are too
 *
 * Nothing here is calculated anew. Tijd in bed is the night's own, as the
 * Slaap page always showed it; Regelmaat is the sleep score's regularity
 * part as it was recorded each day (daily_scores, health_score_history()) —
 * what the Scorekompas shows beside "Regelmaat" — carried as the score is
 * carried; SpO₂, Huidtemperatuur and HRV are each day's value as
 * health_daily_metric() gives it. A day without one is a gap, never a 0.
 *
 * Positions are % of the plot (x) and % from its top (y).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n.php';   // language and units (docs/I18N.md)

require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-signals.php';
require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/time-axis.php';
require_once __DIR__ . '/goal-chart.php';
require_once __DIR__ . '/hydrate-compass.php';
require_once __DIR__ . '/hydrate-health.php';
require_once __DIR__ . '/area-charts.php';

if (!function_exists('hydrate_sleep')) {

    /**
     * @param array  $area     config/health.php's Slaap area: `night`, `charts`, `chart_copy`
     * @param array  $periods  config/compass.php's history periods (days, label, spoken)
     * @param array  $history  health_score_history() over a year: the recorded sleep score's parts per day
     * @param string $today    Y-m-d
     */
    function hydrate_sleep(array $area, array $periods, int $userId, array $history, string $today): array
    {
        return [
            'night'  => hydrate_sleep_night((array) $area['night'], $userId, $today, (array) $area['chart_copy'], $periods),
            'charts' => hydrate_sleep_charts((array) $area['charts'], (array) $area['chart_copy'], $periods, $userId, $history, $today),
        ];
    }

    /* ==================================================================
       THE NIGHT
       ================================================================== */

    /**
     * Slaapverloop (40.1): Training's heart-rate switch over the night.
     *
     *   Vandaag   one night at a time — this morning's and the six before
     *             it (`days`), an arrow either side — each with its bedtime
     *             and wake time, how long was slept and how much of the time
     *             in bed, and each recorded period of a stage on its row.
     *             Opened on the newest night there is.
     *   7 dagen … 1 jaar   each night's stage minutes as one bar on Ownify's
     *             time axis (docs/CHARTS.md), Diep at the bottom to Wakker at
     *             the top; a week's or month's the mean of its nights.
     *
     * The top level is the night opened first, as it always was.
     *
     * @param array $chartCopy  Slaap's `chart_copy`: the words of its charts over time
     * @param array $periods    config/compass.php's history periods
     */
    function hydrate_sleep_night(array $copy, int $userId, string $today, array $chartCopy = [], array $periods = []): array
    {
        $count  = max(1, (int) $copy['days']);
        $from   = (new DateTimeImmutable($today))->modify('-' . ($count - 1) . ' day')->format('Y-m-d');
        $nights = db_available() ? health_nights_on($userId, $from, $today) : [];

        $days   = [];
        $opened = null;
        for ($d = 0; $d < $count; $d++) {
            $date   = (new DateTimeImmutable($today))->modify("-{$d} day")->format('Y-m-d');
            $night  = $nights[$date] ?? null;
            $one    = hydrate_sleep_night_one($copy, $userId, $night);
            $one['key']   = 'd' . $d;
            $one['heading'] = $one['date'] ?? score_compass_date($date, true);
            if ($night === null) {
                $one['note'] = (string) ($copy['empty_day'] ?? $copy['empty']);
            }
            $opened ??= $night !== null ? $d : null;
            $days[] = $one;
        }

        /* No night at all: the first, saying so as it always did. */
        $default = $opened ?? 0;
        $out     = $days[$default];
        if ($opened === null) {
            $out['note']    = (string) $copy['empty'];
            $days[0]['note'] = (string) $copy['empty'];
        }

        $stacked = $periods !== [] && $chartCopy !== [] ? hydrate_sleep_night_periods($copy, $chartCopy, $periods, $userId, $today) : [];

        $options = [['key' => 'd' . $default, 'label' => (string) ($copy['today'] ?? 'Vandaag')]];
        foreach ($stacked as $period) {
            $options[] = ['key' => $period['key'], 'label' => $period['label']];
        }

        return $out + [
            'switch'  => t('Periode kiezen'),
            'prev'    => (string) ($copy['prev'] ?? ''),
            'next'    => (string) ($copy['next'] ?? ''),
            'default' => 'd' . $default,
            'options' => $options,
            'nights'  => $days,
            'stages'  => array_map(static fn (array $s): array => ['key' => (string) $s['key'], 'label' => (string) $s['label']], (array) ($copy['period_stages'] ?? [])),
            'period_hint' => (string) ($copy['period_hint'] ?? ''),
            'period_total' => (string) ($copy['period_total'] ?? 'Totaal'),
            'period_empty' => (string) $copy['empty'],
            'periods' => $stacked,
        ];
    }

    /** One night, or none: its times, its sums and its stages on their rows. */
    function hydrate_sleep_night_one(array $copy, int $userId, ?array $night): array
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

        if ($night === null) {
            return $out;
        }

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
                $out['rows'][$r]['total'] = hydrate_hours((int) round($seconds / 60)) . ' ' . t('u');
            }
        } else {
            $out['note'] = (string) $copy['unstaged'];
        }

        $out['ticks'] = hydrate_sleep_night_ticks($start, $end);

        return $out;
    }

    /**
     * 7 dagen to 1 jaar: each night's minutes per stage (the night's own
     * stored minutes; Rusteloosheid from its recorded periods) as one bar, stacked from the first of
     * `period_stages` at the bottom — on Ownify's time axis, a week's or
     * month's the mean of its nights (area_chart()). In hours, as Tijd in
     * bed is; each stage's part of a bar given as [stage, top, bottom] in %
     * from the plot's top.
     */
    function hydrate_sleep_night_periods(array $copy, array $chartCopy, array $periods, int $userId, string $today): array
    {
        $stages = (array) ($copy['period_stages'] ?? []);
        if ($stages === []) {
            return [];
        }

        $yearAgo = (new DateTimeImmutable($today))->modify('-364 day')->format('Y-m-d');
        $nights  = db_available() ? health_nights_on($userId, $yearAgo, $today) : [];
        $staged  = array_filter($nights, static fn (array $n): bool => $n['deep'] !== null || $n['light'] !== null || $n['rem'] !== null);

        /* Rusteloosheid (stage 8, 46.0) has no stored minutes: its recorded
           periods, per night. It is part of the sleep and not in the stored
           awake minutes, so Wakker stays as stored. A night without recorded
           periods has no Rusteloosheid part — never guessed. */
        $restless = [];
        if ($staged !== [] && db_available()) {
            foreach (db_all(
                'SELECT st.sleep_session_id AS id,
                        SUM(CASE WHEN st.stage = 8 THEN TIMESTAMPDIFF(SECOND, st.started_at, st.ended_at) ELSE 0 END) AS seconds
                   FROM sleep_stages st
                   JOIN sleep_sessions s ON s.id = st.sleep_session_id
                  WHERE s.user_id = ? AND s.night_of BETWEEN ? AND ?
               GROUP BY st.sleep_session_id',
                [$userId, $yearAgo, $today]
            ) as $row) {
                $restless[(int) $row['id']] = (float) $row['seconds'] / 60;
            }
        }
        foreach ($staged as $date => $night) {
            $parts = array_values(array_filter(array_map('intval', (array) ($night['parts'] ?? [$night['session_id']])), static fn (int $id): bool => isset($restless[$id])));
            if ($parts === []) {
                $staged[$date]['restless'] = null;
                continue;
            }
            $minutes = array_sum(array_map(static fn (int $id): float => $restless[$id], $parts));
            $staged[$date]['restless'] = round($minutes);
        }
        $first   = $staged === [] ? null : (string) min(array_keys($staged));

        $chart = area_chart(
            'stages',
            [
                'title'  => (string) ($copy['period_title'] ?? $copy['title']),
                'scale'  => 'shared',
                'series' => array_map(static fn (array $s): array => [
                    'key' => (string) $s['key'], 'label' => (string) $s['label'], 'unit' => 'u', 'kind' => 'bars', 'format' => 'hours',
                ], $stages),
            ],
            ['hint' => (string) ($copy['period_hint'] ?? $chartCopy['hint']), 'empty' => (string) $copy['empty']] + $chartCopy,
            $periods,
            static function (array $s) use ($staged, $first): array {
                $out = [];
                foreach ($staged as $date => $night) {
                    $minutes = $night[(string) $s['key']] ?? null;
                    if ($minutes === null) {
                        continue;                   // not measured that night: no part, never a 0
                    }
                    $hours = (float) $minutes / 60;
                    $out[(string) $date] = [$hours, area_chart_text($s, $hours), false];
                }

                return [$out, $first];
            },
            $today
        );

        $padY  = AREA_CHART_PAD_Y;
        $h     = AREA_CHART_H;
        $baseY = static fn (float $v, float $high): float => round(($padY + (1 - $v / $high) * ($h - $padY)) / $h * 100, 2);

        foreach ($chart['periods'] as $k => $period) {
            $values = $period['values'];
            $n      = count($period['x']);

            /* The height: the tallest night, whole, out to a round step. */
            $tallest = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $tallest = max($tallest, array_sum(array_map(static fn (array $vs): float => (float) ($vs[$i] ?? 0), $values)));
            }
            $step = max(goal_chart_step(0, $tallest > 0 ? $tallest : 1.0, 3), 0.5);
            $high = max($step, ceil($tallest / $step) * $step);

            $grid = [];
            for ($level = $step; $level <= $high + $step / 1000; $level += $step) {
                $grid[] = ['y' => $baseY($level, $high), 'label' => i18n_number($level, goal_chart_step_decimals($step)) . ' ' . t('u')];
            }

            /* Each bar from the bottom up, a stage's part after the one below it. */
            $stacks = [];
            $tops   = array_fill_keys(array_column($stages, 'key'), array_fill(0, $n, null));
            for ($i = 0; $i < $n; $i++) {
                $below = 0.0;
                $parts = [];
                foreach ($stages as $j => $stage) {
                    $v = $values[$j][$i] ?? null;
                    if ($v === null || $v <= 0) {
                        continue;
                    }
                    $parts[] = [(string) $stage['key'], $baseY($below + $v, $high), $baseY($below, $high)];
                    $tops[(string) $stage['key']][$i] = $baseY($below + $v, $high);
                    $below += $v;
                }
                $stacks[] = $parts;
            }

            /* The reading's first row: the night's whole time, every stage
               together ("Totaal 8:12 u"), before each stage's own. */
            foreach ($chart['periods'][$k]['points'] as $i => $point) {
                $parts = array_filter(array_map(static fn (array $vs) => $vs[$i] ?? null, $values), static fn ($v) => $v !== null);
                $total = $parts === [] ? null : area_chart_text(['format' => 'hours'], array_sum($parts));
                /* Then each stage top to bottom, as the bar, the legend and
                   Vandaag's rows have them: Wakker first, Diep last. */
                $point = $chart['periods'][$k]['points'][$i];
                $chart['periods'][$k]['points'][$i] = array_merge(array_slice($point, 0, 3), [$total], array_reverse(array_slice($point, 3)));
            }

            $chart['periods'][$k]['stacks'] = $stacks;
            $chart['periods'][$k]['w']      = $period['bars'][0]['w'] ?? 2.0;
            $chart['periods'][$k]['tops']   = $tops;
            $chart['periods'][$k]['grid']   = $period['has_data'] ? $grid : [];
            unset($chart['periods'][$k]['values'], $chart['periods'][$k]['bars'], $chart['periods'][$k]['lines']);
        }

        return $chart['periods'];
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
     * Each chart, over each period, from a year of its days — Ownify's area
     * charts (lib/area-charts.php), with Slaap's own days.
     *
     * @return list<array>
     */
    function hydrate_sleep_charts(array $charts, array $copy, array $periods, int $userId, array $history, string $today): array
    {
        $yearAgo = (new DateTimeImmutable($today))->modify('-364 day')->format('Y-m-d');

        return area_charts(
            $charts,
            $copy,
            $periods,
            static fn (array $s): array => hydrate_sleep_series_days((string) $s['key'], $s, $userId, $history, $yearAgo, $today),
            $today
        );
    }

    /**
     * One series' recorded days over the year, and its first day ever.
     *
     * @return array{0: array<string,array{0: float, 1: string, 2: string|false}>, 1: ?string}
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
                    $days[(string) $date] = [$minutes / 60, hydrate_hours($minutes) . ' ' . t('u'), false];
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
        return area_metric_days($userId, $key, $s, $from, $to);
    }
}

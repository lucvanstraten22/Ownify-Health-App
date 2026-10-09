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
        return area_metric_days($userId, $key, $s, $from, $to);
    }
}

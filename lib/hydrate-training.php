<?php
/**
 * Training, drawn (docs/TRAINING.md) — for the website and the app alike.
 *
 *   the sessions   the latest training sessions — Ownify's counted workouts
 *                  (health_workouts_counted(): 10 minutes to 8 hours, each
 *                  real session once), never the day's ordinary movement —
 *                  each opening a page of its own: what was recorded during
 *                  it, and its heart rate minute by minute
 *   the charts     Trainingen per dag, Stappen + Afstand, Actieve + Totale
 *                  calorieën, Verdiepingen, Actieve minuten, HRV and
 *                  Hartbelasting: Ownify's area charts (lib/area-charts.php),
 *                  each day's value as every page reads it
 *   the heart rate a whole day, 00:00 to 24:00, as each five minutes' mean —
 *                  today and the six days before it — and over 7 dagen to
 *                  1 jaar each day's average (the mean of its minutes), a
 *                  point per day up to 90 dagen, per month over a year; with
 *                  four zones over it, from the person's own resting and
 *                  maximum heart rate where they are known
 *
 * Nothing here is measured anew: the heart rate is heart_rate_minutes
 * (migration 019) as recorded, the totals are health_metrics as every page
 * counts them, a session's what the session or the readings during it say.
 * A day without a value is a gap, never a 0; a figure a session does not
 * have is not shown. The zones are a layer over the values, never a change
 * to them.
 *
 * Positions are % of the plot (x) and % from its top (y); the lines are
 * paths in a 300 × 160 box, as every chart's are.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-signals.php';
require_once dirname(__DIR__) . '/includes/health-totals.php';
require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/area-charts.php';

if (!function_exists('hydrate_training')) {

    /**
     * @param array  $area     config/health.php's Training area: sessions, layout, charts, chart_copy, heart
     * @param array  $periods  config/compass.php's history periods (days, label, spoken)
     * @param string $today    Y-m-d
     */
    function hydrate_training(array $area, array $periods, int $userId, string $today): array
    {
        $now      = new DateTimeImmutable($today);
        $yearAgo  = $now->modify('-364 day')->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $records = db_available() ? health_workout_records($userId, $yearAgo . ' 00:00:00', $tomorrow . ' 00:00:00') : [];
        $counted = $records === [] ? [] : health_workouts_counted($records)[0];
        $zones   = hydrate_training_zones((array) $area['heart']['zones'], $userId, $records, $today);
        $recent  = array_slice(array_reverse($counted), 0, (int) $area['sessions']['count']);

        return [
            'layout'   => (array) $area['layout'],
            'sessions' => hydrate_training_sessions((array) $area['sessions'], $recent, $today),
            'charts'   => area_charts(
                (array) $area['charts'],
                (array) $area['chart_copy'],
                $periods,
                static fn (array $s): array => hydrate_training_series_days($s, $userId, $counted, $yearAgo, $today),
                $today
            ),
            'heart'    => hydrate_training_heart((array) $area['heart'], (array) $area['chart_copy'], $periods, $zones, $userId, $today),
            'details'  => array_map(
                static fn (array $w): array => hydrate_training_session((array) $area['sessions'], $zones, $userId, $w, $today),
                $recent
            ),
        ];
    }

    /* ==================================================================
       THE SESSIONS
       ================================================================== */

    /** The list: each session's kind, day, time and duration, newest first. */
    function hydrate_training_sessions(array $copy, array $recent, string $today): array
    {
        return [
            'title' => (string) $copy['title'],
            'empty' => (string) $copy['empty'],
            'items' => array_map(static function (array $w) use ($copy, $today): array {
                $label = hydrate_training_kind($w['type']);
                $day   = hydrate_training_day(date('Y-m-d', $w['start']), $today, $copy);

                return [
                    'id'       => (string) $w['id'],
                    'detail'   => 'training-session-' . $w['id'],
                    'label'    => $label,
                    'date'     => $day,
                    'time'     => date('H:i', $w['start']),
                    'duration' => $w['minutes'] === null ? null : hydrate_training_minutes((float) $w['minutes']),
                    'open'     => sprintf((string) $copy['open'], $label, score_compass_lcfirst($day)),
                ];
            }, $recent),
        ];
    }

    /** "Hardlopen", "Krachttraining", "Training": the session's kind as Ownify names it. */
    function hydrate_training_kind(string $type): string
    {
        return score_compass_ucfirst(health_activity_label($type));
    }

    /** "Vandaag", "Gisteren", or "5 okt" ("5 okt 2025" in another year). */
    function hydrate_training_day(string $date, string $today, array $copy): string
    {
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

        return match ($date) {
            $today     => (string) $copy['today'],
            $yesterday => (string) $copy['yesterday'],
            default    => score_compass_date_in($date, substr($today, 0, 4), true),
        };
    }

    /** "42 min", or "1:12 u" from an hour. */
    function hydrate_training_minutes(float $minutes): string
    {
        $whole = (int) round($minutes);

        return $whole < 60 ? $whole . ' min' : hydrate_hours($whole) . ' u';
    }

    /* ==================================================================
       THE CHARTS
       ================================================================== */

    /**
     * One series' days over the year and its first day ever: the sessions
     * per day counted from the first one (a day without one is a 0 from
     * then on: none were recorded), anything else as every page reads it.
     */
    function hydrate_training_series_days(array $s, int $userId, array $counted, string $from, string $to): array
    {
        if ((string) $s['key'] !== 'sessions') {
            return area_metric_days($userId, (string) $s['key'], $s, $from, $to);
        }

        if (!db_available()) {
            return [[], null];
        }

        $per = [];
        foreach ($counted as $w) {
            $date = date('Y-m-d', $w['start']);
            $per[$date] = ($per[$date] ?? 0) + 1;
        }

        $first = $per === [] ? null : min(array_keys($per));
        $older = db_value('SELECT MIN(DATE(started_at)) FROM workouts WHERE user_id = ?', [$userId]);
        if ($older !== null && (string) $older < $from) {
            $first = (string) $older;           // a history older than the year: the year is full
        }
        if ($first === null) {
            return [[], null];
        }

        $days = [];
        for ($d = new DateTimeImmutable(max($first, $from)); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $n = (float) ($per[$d->format('Y-m-d')] ?? 0);
            $days[$d->format('Y-m-d')] = [$n, area_chart_text($s, $n), false];
        }

        return [$days, $first];
    }

    /* ==================================================================
       THE ZONES
       ================================================================== */

    /**
     * The four zones' lower bounds (2, 3 and 4; zone 1 is everything under
     * zone 2), what they are based on, and their names and ranges for the
     * legend — or no zones, and why, when no maximum heart rate is known.
     */
    function hydrate_training_zones(array $cfg, int $userId, array $records, string $today): array
    {
        $out = [
            'labels'     => array_values((array) $cfg['labels']),
            'thresholds' => null,
            'basis'      => null,
            'note'       => (string) $cfg['none'],
            'bands'      => [],
        ];

        if (!db_available()) {
            return $out;
        }

        $max = health_hr_max($userId, $records);
        if ($max === null || $max <= 0) {
            return $out;
        }

        $from    = (new DateTimeImmutable($today))->modify('-' . max(0, (int) $cfg['resting_days'] - 1) . ' day')->format('Y-m-d');
        $resting = hydrate_training_median(array_values(health_daily_metric_days($userId, 'resting_hr', $from, $today)));

        if ($resting !== null && $resting >= 30 && $resting < 0.75 * $max) {
            $bounds        = array_map(static fn (float $p): float => $resting + $p * ($max - $resting), (array) $cfg['reserve']);
            $out['basis']  = 'reserve';
            $out['note']   = sprintf((string) $cfg['basis_reserve'], (int) round($resting), (int) round($max));
        } else {
            $pct           = health_scoring_config()['training']['intensity']['hr_max_pct'];
            $bounds        = [(float) $cfg['light'] * $max, (float) $pct['moderate'] * $max, (float) $pct['hard'] * $max];
            $out['basis']  = 'max';
            $out['note']   = sprintf((string) $cfg['basis_max'], (int) round($max));
        }

        $t = array_map(static fn (float $b): int => (int) round($b), $bounds);
        $out['thresholds'] = $t;
        $out['bands'] = [
            ['label' => $out['labels'][0], 'range' => '< ' . $t[0]],
            ['label' => $out['labels'][1], 'range' => $t[0] . '–' . ($t[1] - 1)],
            ['label' => $out['labels'][2], 'range' => $t[1] . '–' . ($t[2] - 1)],
            ['label' => $out['labels'][3], 'range' => '≥ ' . $t[2]],
        ];

        return $out;
    }

    /** The zone a heart rate is in, 1 to 4, by its whole number as read; null without zones. */
    function hydrate_training_zone(array $zones, float $bpm): ?int
    {
        if ($zones['thresholds'] === null) {
            return null;
        }

        $v = (int) round($bpm);
        foreach ($zones['thresholds'] as $i => $from) {
            if ($v < $from) {
                return $i + 1;
            }
        }

        return 4;
    }

    function hydrate_training_median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /* ==================================================================
       THE HEART RATE
       ================================================================== */

    function hydrate_training_heart(array $cfg, array $copy, array $periods, array $zones, int $userId, string $today): array
    {
        $now    = new DateTimeImmutable($today);
        $count  = max(1, (int) $cfg['days']);
        $bucket = max(1, (int) $cfg['bucket']);
        $first  = $now->modify('-' . ($count - 1) . ' day')->format('Y-m-d');

        /* Each day's five-minute means: the mean of the minutes in each. */
        $buckets = [];
        foreach (health_heart_minutes($userId, $first . ' 00:00:00', $now->modify('+1 day')->format('Y-m-d') . ' 00:00:00') as $at => $bpm) {
            $date = substr($at, 0, 10);
            $b    = intdiv((int) substr($at, 11, 2) * 60 + (int) substr($at, 14, 2), $bucket);
            $buckets[$date][$b][0] = ($buckets[$date][$b][0] ?? 0.0) + $bpm;
            $buckets[$date][$b][1] = ($buckets[$date][$b][1] ?? 0) + 1;
        }

        /* One height for the whole week, so a day's zones stand where the
           next day's do. */
        $all = [];
        foreach ($buckets as $day) {
            foreach ($day as [$sum, $n]) {
                $all[] = $sum / $n;
            }
        }
        $range = hydrate_training_bpm_range($all);

        $days = [];
        for ($d = 0; $d < $count; $d++) {
            $date   = $now->modify("-{$d} day")->format('Y-m-d');
            $days[] = hydrate_training_heart_day($cfg, $zones, $date, $d, $buckets[$date] ?? [], $bucket, $range);
        }

        /* 7 dagen to 1 jaar: each day's average, as Ownify's area charts
           draw a series — a point per day up to 90 dagen. */
        $chart = area_chart(
            'heart',
            ['title' => (string) $cfg['title'], 'series' => [['key' => 'heart', 'label' => (string) $cfg['label'], 'unit' => (string) $cfg['unit'], 'kind' => 'line', 'decimals' => 0]]],
            ['empty' => (string) $cfg['empty'], 'hint' => (string) $cfg['hint']] + $copy,
            $periods,
            static function (array $s) use ($userId, $now, $today, $bucket): array {
                if (!health_heart_minutes_stored()) {
                    return [[], null];
                }
                $out = [];
                foreach (health_heart_days($userId, $now->modify('-364 day')->format('Y-m-d'), $today, $bucket) as $date => $bpm) {
                    $out[$date] = [$bpm, area_chart_text($s, $bpm), false];
                }
                $first = db_value('SELECT MIN(minute_at) FROM heart_rate_minutes WHERE user_id = ?', [$userId]);

                return [$out, $first === null ? null : substr((string) $first, 0, 10)];
            },
            $today,
            [90 => 'day']
        );

        /* On the heart rate's own height (hydrate_training_bpm_range()),
           its zones at theirs. */
        foreach ($chart['periods'] as $k => $period) {
            $values = $period['values'][0];
            [$low, $high, $step] = hydrate_training_bpm_range(array_values(array_filter($values, static fn ($v) => $v !== null)));
            $x = $period['x'];
            [$ys, $paths] = area_chart_line($x, $values, static fn (float $v): float => area_chart_y($v, $low, $high));
            $chart['periods'][$k]['lines']   = [['key' => 'heart', 'line' => $paths, 'y' => $ys]];
            $chart['periods'][$k]['grid']    = $period['has_data'] ? area_chart_levels($low, $high, $step) : [];
            $chart['periods'][$k]['zones_y'] = hydrate_training_zones_y($zones, $low, $high);
            $chart['periods'][$k]['zone']    = array_map(static fn ($v) => $v === null ? null : hydrate_training_zone($zones, (float) $v), $period['values'][0]);
            unset($chart['periods'][$k]['values']);
        }

        $options = [['key' => 'd0', 'label' => (string) $cfg['today']]];
        foreach ($chart['periods'] as $period) {
            $options[] = ['key' => $period['key'], 'label' => $period['label']];
        }

        return [
            'title'   => (string) $cfg['title'],
            'label'   => (string) $cfg['label'],
            'switch'  => 'Periode kiezen',
            'hint'    => (string) $cfg['hint'],
            'empty'   => (string) $cfg['empty'],
            'prev'    => (string) $cfg['prev'],
            'next'    => (string) $cfg['next'],
            'default' => 'd0',
            'options' => $options,
            'days'    => $days,
            'periods' => $chart['periods'],
            'zones'   => [
                'labels'     => $zones['labels'],
                'thresholds' => $zones['thresholds'],
                'basis'      => $zones['basis'],
                'note'       => $zones['note'],
                'bands'      => $zones['bands'],
            ],
        ];
    }

    /**
     * One day, 00:00 to 24:00: each five minutes with a heart rate a point
     * at its middle, a line through the points no more than `break` minutes
     * apart, a dot where a point stands alone; the hours under it every
     * `hours`; the zones' bounds at their heights.
     *
     * @param array<int,array{0: float, 1: int}> $buckets  five minutes => [sum, count]
     * @param array{0: float, 1: float, 2: float} $range    the week's low, high and step
     */
    function hydrate_training_heart_day(array $cfg, array $zones, string $date, int $back, array $buckets, int $bucket, array $range): array
    {
        ksort($buckets);
        [$low, $high, $step] = $range;
        $y = static fn (float $v): float => area_chart_y($v, $low, $high);

        $xs     = [];
        $values = [];
        $points = [];
        $zone   = [];
        foreach ($buckets as $b => [$sum, $n]) {
            $v        = $sum / $n;
            $minute   = $b * $bucket;
            $xs[]     = round(($minute + $bucket / 2) / 1440 * 100, 3);
            $values[] = $v;
            $z        = hydrate_training_zone($zones, $v);
            $zone[]   = $z;
            $points[] = [sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60), $z === null ? null : $zones['labels'][$z - 1], null, (int) round($v) . ' ' . $cfg['unit']];
        }

        $gap = ((int) $cfg['break']) / 1440 * 100;
        [$ys, $paths] = area_chart_line($xs, $values, $y, $gap);

        $ticks = [];
        for ($h = 0; $h < 24; $h += max(1, (int) $cfg['hours'])) {
            $ticks[] = ['x' => round($h / 24 * 100, 3), 'label' => sprintf('%02d:00', $h)];
        }

        $title = match ($back) {
            0       => (string) $cfg['today'],
            1       => (string) $cfg['yesterday'],
            2       => (string) $cfg['before'],
            default => score_compass_date($date),
        };

        return [
            'key'      => 'd' . $back,
            'date'     => $date,
            'title'    => $title,
            'group'    => 'minutes',
            'aria'     => sprintf((string) $cfg['aria_day'], $back < 3 ? mb_strtolower($title) : $title),
            'empty'    => (string) $cfg['empty_day'],
            'axis'     => $ticks,
            'x'        => $xs,
            'points'   => $points,
            'zone'     => $zone,
            'lone'     => hydrate_training_lone($xs, $gap),
            'width'    => AREA_CHART_W,
            'height'   => AREA_CHART_H,
            'has_data' => $xs !== [],
            'lines'    => [['key' => 'heart', 'line' => $paths, 'y' => $ys]],
            'bars'     => [],
            'grid'     => area_chart_levels($low, $high, $step),
            'zones_y'  => hydrate_training_zones_y($zones, $low, $high),
        ];
    }

    /**
     * The height heart rate is drawn over: its values with a few beats'
     * room, out to whole steps of 10, 20 or 40 bpm by how far they span —
     * 40 to 200 for a day with a run in it, 60 to 90 for a week of
     * averages — never from 0, where no heart rate is.
     *
     * @param float[] $values
     * @return array{0: float, 1: float, 2: float} low, high, step
     */
    function hydrate_training_bpm_range(array $values): array
    {
        if ($values === []) {
            return [40.0, 160.0, 40.0];
        }

        $min  = min($values) - 4;
        $max  = max($values) + 4;
        $step = $max - $min > 100 ? 40.0 : ($max - $min > 40 ? 20.0 : 10.0);
        $low  = max(0.0, floor($min / $step) * $step);
        $high = ceil($max / $step) * $step;
        /* At least three steps, so two levels inside are named. */
        while ($high - $low < 3 * $step) {
            $low >= $step && ($min - $low) < ($high - $max) ? $low -= $step : $high += $step;
        }

        return [$low, $high, $step];
    }

    /** The points no line reaches: a dot each. */
    function hydrate_training_lone(array $xs, float $gap): array
    {
        $lone = [];
        $n    = count($xs);
        for ($i = 0; $i < $n; $i++) {
            $before = $i > 0 && $xs[$i] - $xs[$i - 1] <= $gap;
            $after  = $i < $n - 1 && $xs[$i + 1] - $xs[$i] <= $gap;
            if (!$before && !$after) {
                $lone[] = $i;
            }
        }

        return $lone;
    }

    /** Where zones 2, 3 and 4 begin on a plot over $low–$high, % from its top; null without zones. */
    function hydrate_training_zones_y(array $zones, float $low, float $high): ?array
    {
        if ($zones['thresholds'] === null) {
            return null;
        }

        /* A zone begins at its whole bpm; the line between it and the one
           under it half a beat lower, where a value is rounded into it. */
        return array_map(static fn (int $t): float => area_chart_y($t - 0.5, $low, $high), $zones['thresholds']);
    }

    /* ==================================================================
       A SESSION
       ================================================================== */

    /**
     * A session's own page: its kind, day and times, every figure recorded
     * for it — by the session itself, or by the readings during it, counted
     * as a day's are — and its heart rate minute by minute.
     */
    function hydrate_training_session(array $copy, array $zones, int $userId, array $w, string $today): array
    {
        $start = (int) $w['start'];
        $end   = (int) $w['end'];
        $from  = date('Y-m-d H:i:s', $start);
        $to    = date('Y-m-d H:i:s', $end);
        $label = hydrate_training_kind($w['type']);
        $row   = db_one('SELECT active_kcal, total_kcal, avg_cadence, elevation_gain_m FROM workouts WHERE id = ? AND user_id = ?', [$w['id'], $userId]) ?? [];

        $window = static fn (string $code): ?float => $end > $start ? health_metric_window_total($userId, $code, $from, $to) : null;
        $minutes = $end > $start ? health_heart_minutes($userId, $from, $to) : [];

        $stats = [];
        $put   = static function (string $key, ?string $value, string $unit) use (&$stats, $copy): void {
            if ($value !== null) {
                $stats[] = ['key' => $key, 'label' => (string) $copy['stats'][$key], 'value' => $value, 'unit' => $unit];
            }
        };
        $num = static fn (?float $v, int $decimals = 0): ?string => $v === null || $v <= 0 ? null : number_format($v, $decimals, ',', '.');

        if ($w['minutes'] !== null) {
            $whole = (int) round((float) $w['minutes']);
            $whole < 60 ? $put('duration', (string) $whole, 'min') : $put('duration', hydrate_hours($whole), 'u');
        }

        $km = $w['km'] ?? $window('distance');
        $put('distance', $num($km, $km !== null && $km < 1 ? 2 : 1), 'km');

        /* Pace or speed only from the session's own distance or speed: the
           readings during it (a phone's steps on a bike ride) are not the
           session's distance. */
        $kmh = $w['kmh'];                               // its speed, or its own distance over its time
        if ($kmh !== null && $kmh > 0) {
            if (hydrate_training_paced($w['type'])) {
                $pace = (int) round(3600 / $kmh);               // seconds per km
                $put('pace', sprintf('%d:%02d', intdiv($pace, 60), $pace % 60), '/km');
            } else {
                $put('speed', $num($kmh, 1), 'km/u');
            }
        }

        $put('active_energy', $num(isset($row['active_kcal']) ? (float) $row['active_kcal'] : $window('active_energy')), 'kcal');
        $put('total_energy', $num(isset($row['total_kcal']) ? (float) $row['total_kcal'] : $window('total_energy')), 'kcal');
        $put('steps', $num($window('steps')), '');

        $bpm = array_values($minutes);
        $avg = $w['avg_hr'] ?? ($bpm === [] ? null : array_sum($bpm) / count($bpm));
        $max = $w['max_hr'] ?? ($bpm === [] ? null : max($bpm));
        $put('avg_hr', $num($avg), 'bpm');
        $put('max_hr', $num($max), 'bpm');

        $put('floors', $num($window('floors')), '');
        $put('elevation', $num(isset($row['elevation_gain_m']) ? (float) $row['elevation_gain_m'] : null), 'm');
        $put('cadence', $num(isset($row['avg_cadence']) ? (float) $row['avg_cadence'] : null), '/min');
        $put('active_minutes', $num($window('active_minutes')), 'min');

        $date = date('Y-m-d', $start);

        return [
            'id'     => (string) $w['id'],
            'detail' => 'training-session-' . $w['id'],
            'title'  => $label,
            'date'   => score_compass_ucfirst(hydrate_training_weekday($date)) . ' ' . score_compass_date_in($date, substr($today, 0, 4)),
            'time'   => date('H:i', $start) . ' – ' . date('H:i', $end),
            'back'   => (string) $copy['back'],
            'stats_title' => (string) $copy['stats_title'],
            'stats'  => $stats,
            'heart'  => hydrate_training_session_heart($copy, $zones, $start, $end, $minutes),
        ];
    }

    /** Whether a kind is told by its pace (min/km) rather than its speed: on foot. */
    function hydrate_training_paced(string $type): bool
    {
        foreach (['run', 'walk', 'hik', 'treadmill'] as $foot) {
            if (str_contains(mb_strtolower($type), $foot)) {
                return true;
            }
        }

        return false;
    }

    function hydrate_training_weekday(string $date): string
    {
        return ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'][(int) (new DateTimeImmutable($date))->format('w')];
    }

    /**
     * The heart rate during a session, minute by minute, from its start at
     * the left to its end at the right: the times under it at its two ends
     * and at round times between, its zones over it.
     *
     * @param array<string,float> $minutes  "Y-m-d H:i:00" => bpm
     */
    function hydrate_training_session_heart(array $copy, array $zones, int $start, int $end, array $minutes): array
    {
        $span   = max(60, $end - $start);
        [$low, $high, $step] = hydrate_training_bpm_range(array_values($minutes));

        $xs = [];
        $values = [];
        $points = [];
        $zone = [];
        foreach ($minutes as $at => $bpm) {
            $t = strtotime($at);
            if ($t === false) {
                continue;
            }
            $xs[]     = round(max(0, min(100, ($t + 30 - $start) / $span * 100)), 3);
            $values[] = $bpm;
            $z        = hydrate_training_zone($zones, $bpm);
            $zone[]   = $z;
            $points[] = [date('H:i', $t), $z === null ? null : $zones['labels'][$z - 1], null, (int) round($bpm) . ' bpm'];
        }

        $gap = 180 / $span * 100;                   // three minutes without one break the line
        [$ys, $paths] = area_chart_line($xs, $values, static fn (float $v): float => area_chart_y($v, $low, $high), $gap);

        return [
            'title'    => (string) $copy['heart_title'],
            'empty'    => (string) $copy['heart_empty'],
            'aria'     => (string) $copy['heart_title'],
            'axis'     => hydrate_training_session_ticks($start, $end),
            'x'        => $xs,
            'points'   => $points,
            'zone'     => $zone,
            'lone'     => hydrate_training_lone($xs, $gap),
            'width'    => AREA_CHART_W,
            'height'   => AREA_CHART_H,
            'has_data' => $xs !== [],
            'lines'    => [['key' => 'heart', 'line' => $paths, 'y' => $ys]],
            'bars'     => [],
            'grid'     => $xs === [] ? [] : area_chart_levels($low, $high, $step),
            'zones_y'  => hydrate_training_zones_y($zones, $low, $high),
        ];
    }

    /**
     * The times under a session: its start at the left edge, its end at the
     * right, and round times between — every 5, 10, 15 or 30 minutes or
     * every hour, at most three — none so close to either end that they
     * would touch.
     *
     * @return list<array{x: float, label: string}>
     */
    function hydrate_training_session_ticks(int $start, int $end): array
    {
        $span  = max(60, $end - $start);
        $every = 3600;
        foreach ([300, 600, 900, 1800, 3600, 7200] as $step) {
            if ($span / $step <= 4) {
                $every = $step;
                break;
            }
        }

        $ticks = [['x' => 0.0, 'label' => date('H:i', $start)]];
        for ($t = (int) (ceil($start / $every) * $every); $t < $end; $t += $every) {
            $x = ($t - $start) / $span * 100;
            if ($x >= 20 && $x <= 80) {
                $ticks[] = ['x' => round($x, 2), 'label' => date('H:i', $t)];
            }
        }
        $ticks[] = ['x' => 100.0, 'label' => date('H:i', $end)];

        return $ticks;
    }
}

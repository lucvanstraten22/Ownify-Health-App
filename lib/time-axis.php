<?php
/**
 * Ownify's time axis: where the days go on a chart over time. One rule for
 * every such chart, on the website and in the app — the standard is
 * docs/CHARTS.md; this file is it in code, and nothing else decides it.
 *
 *   A chart shows a period of time, never just the days there happen to be.
 *
 *   the window   7, 30, 90 or 365 days. A history shorter than the period
 *                starts at the left edge on its first day, and the rest of
 *                the period stays empty on the right, ahead of it. Once the
 *                history fills the period the window rolls: today at the
 *                right edge, a day older at the left each day.
 *   the dates    7 days    every day               "8 okt"
 *                30 days   every third day         "8 okt" "11" "14" … "1 nov" "4"
 *                90 days   every seventh day       "8 okt" "15" "22" … "5 nov" …
 *                365 days  13 month boundaries,    "okt" "nov" … "sep" "okt"
 *                          the 12 months of the year between them
 *                Never "Vandaag", never a year: the reading carries the full
 *                date. Over 30 and 90 days a date names its month where the
 *                month begins (and at the first), and is drawn in two rows —
 *                the day (`day`) over its month (`month`) — or the dates
 *                would touch on a phone; `label` is the date in one line.
 *   the points   (`slots`) a day each over 7 and 30 days, a week each over 90
 *                and a month each over a year — each starting at its date on
 *                the axis and standing there, covering the days up to the
 *                next. One that has begun is a point; one still ahead is not.
 *
 * Positions are % of the plot's width, 0 at the window's first day and 100
 * at its last; the year's closing boundary, a day past it, stands at 100 too.
 */

declare(strict_types=1);

if (!function_exists('time_axis')) {

    /** The standard periods: how many days, what a point is, how far apart the dates are. */
    define('TIME_AXIS_PERIODS', [
        7   => ['grain' => 'day',   'every' => 1],
        30  => ['grain' => 'day',   'every' => 3],
        90  => ['grain' => 'week',  'every' => 7],
        365 => ['grain' => 'month', 'every' => 0],   // month boundaries, not a number of days
    ]);

    /**
     * The window, its dates and its points for one period.
     *
     * @param int         $days   7, 30, 90 or 365
     * @param string|null $first  the history's first day (Y-m-d), null without any
     * @param string      $today  Y-m-d
     * @return array{days: int, grain: string, start: string, end: string, rolling: bool,
     *               ticks: list<array{date: string, label: string, x: float}>,
     *               slots: list<array{from: string, to: string, x: float}>}
     */
    function time_axis(int $days, ?string $first, string $today): array
    {
        $period = TIME_AXIS_PERIODS[$days] ?? null;
        if ($period === null) {
            throw new InvalidArgumentException("No standard period of {$days} days.");
        }

        $now   = new DateTimeImmutable($today);
        $back  = $now->modify('-' . ($days - 1) . ' day');           // where a full window starts
        $begin = $first === null ? $now : new DateTimeImmutable($first);
        $start = $begin > $back ? $begin : $back;                      // never before the history, never more than the period back
        $end   = $start->modify('+' . ($days - 1) . ' day');

        $axis = [
            'days'    => $days,
            'grain'   => $period['grain'],
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
            'rolling' => $start == $back,
            'ticks'   => [],
            'slots'   => [],
        ];

        /* The dates under it, and where each point begins. */
        $marks = [];
        if ($period['grain'] === 'month') {
            for ($k = 0; $k <= 12; $k++) {
                $marks[] = time_axis_add_months($start, $k);
            }
        } else {
            for ($d = 0; $d < $days; $d += $period['every']) {
                $marks[] = $start->modify("+{$d} day");
            }
        }

        $previous = null;
        foreach ($marks as $i => $date) {
            $tick = [
                'date'  => $date->format('Y-m-d'),
                'label' => time_axis_label($date, $days, $previous),
                'x'     => time_axis_x($axis, $date->format('Y-m-d')),
            ];
            /* Two rows: the day over its month, where the month is named. */
            if ($days === 30 || $days === 90) {
                $named         = $previous === null || $previous->format('n') !== $date->format('n');
                $tick['day']   = (string) (int) $date->format('j');
                $tick['month'] = $named ? time_axis_month((int) $date->format('n')) : null;
            }
            $axis['ticks'][] = $tick;
            $previous = $date;
        }

        /* The points: a day each, or a week or month from each of its dates. */
        $starts = $period['grain'] === 'day'
            ? array_map(static fn ($d) => $start->modify("+{$d} day"), range(0, $days - 1))
            : ($period['grain'] === 'week' ? $marks : array_slice($marks, 0, 12));

        foreach ($first === null ? [] : $starts as $i => $from) {
            if ($from > $now || $from > $end) {
                break;                                                 // ahead of today: no point yet
            }
            $next = $starts[$i + 1] ?? ($period['grain'] === 'month' ? $marks[12] : $end->modify('+1 day'));
            $to   = $next->modify('-1 day');

            $axis['slots'][] = [
                'from' => $from->format('Y-m-d'),
                'to'   => ($to > $end ? $end : $to)->format('Y-m-d'),
                'x'    => time_axis_x($axis, $from->format('Y-m-d')),
            ];
        }

        return $axis;
    }

    /** A day's place on the window, in % from the left; outside it, at the nearer edge. */
    function time_axis_x(array $axis, string $date): float
    {
        $from = new DateTimeImmutable($axis['start']);
        $at   = new DateTimeImmutable($date);
        $days = (int) $from->diff($at)->format('%r%a');

        return round(max(0.0, min(100.0, $days / ($axis['days'] - 1) * 100)), 2);
    }

    /**
     * The date a number of months on, on the same day of the month — the
     * last day of a shorter month: 31 jan + 1 = 28 (or 29) feb, never 3 mrt.
     */
    function time_axis_add_months(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $index = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $year  = intdiv($index, 12);
        $month = $index % 12 + 1;
        $last  = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min((int) $date->format('j'), $last)));
    }

    /** A date under the axis, as the period names it (see the head of this file). */
    function time_axis_label(DateTimeImmutable $date, int $days, ?DateTimeImmutable $previous): string
    {
        $month = time_axis_month((int) $date->format('n'));

        if ($days === 365) {
            return $month;
        }

        $day = (string) (int) $date->format('j');

        /* A week names every day in full; longer periods their month where it begins. */
        if ($days === 7 || $previous === null || $previous->format('n') !== $date->format('n')) {
            return $day . ' ' . $month;
        }

        return $day;
    }

    /**
     * A period's dates in two rows — the day over its month, the month named
     * at the first date and where it changes — as 30 and 90 days always are:
     * for a chart too narrow for "8 okt" seven times across (Slaap's small
     * charts). The same dates at the same places; $place moves them as the
     * chart insets its plot.
     *
     * @param list<array{date: string, x: float}> $ticks  time_axis()'s
     * @return list<array{x: float, label: string, day: string, month: ?string}>
     */
    function time_axis_rows(array $ticks, ?callable $place = null): array
    {
        $out      = [];
        $previous = null;
        foreach ($ticks as $tick) {
            $date  = new DateTimeImmutable($tick['date']);
            $named = $previous === null || $previous->format('n') !== $date->format('n');
            $out[] = [
                'x'     => $place === null ? $tick['x'] : $place($tick['x']),
                'label' => $tick['label'],
                'day'   => (string) (int) $date->format('j'),
                'month' => $named ? time_axis_month((int) $date->format('n')) : null,
            ];
            $previous = $date;
        }

        return $out;
    }

    function time_axis_month(int $month): string
    {
        return ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'][$month - 1];
    }

    /**
     * The shortest standard period that holds a history from its first day
     * to today, for a chart without a period switch (a goal's Verloop): 7,
     * then 30, then 90, then a year, which rolls from then on.
     */
    function time_axis_fit(string $first, string $today): int
    {
        $length = (int) (new DateTimeImmutable($first))->diff(new DateTimeImmutable($today))->format('%r%a') + 1;

        foreach (array_keys(TIME_AXIS_PERIODS) as $days) {
            if ($length <= $days) {
                return $days;
            }
        }

        return 365;
    }
}

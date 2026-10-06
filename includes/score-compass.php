<?php
/**
 * The Scorekompas — the Health Score explained, from the engine's own results.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT ANSWERS
 * ---------------------------------------------------------------------------
 *   1  What is my score made of?      the categories, and each one's
 *                                     components with the weight they have
 *   2  What is changing?              its history over 7, 30, 90 or 365
 *                                     days, in a line, in dated sentences,
 *                                     and day by day with its categories
 *   3  How does it compare with me?   the score now, and the average of the
 *                                     daily score over earlier periods
 *   4  Where is the most room?        the component with the most points on
 *                                     the Health Score still to gain
 *
 * ---------------------------------------------------------------------------
 * IT NEVER SCORES
 * ---------------------------------------------------------------------------
 * Everything is read from health_score_history() (includes/health-score.php):
 * the Health Score now — over its 168 hours, with every category's days,
 * components and facts — and as it was recorded on each earlier day. The
 * weights come from config/scoring.php, exactly as the engine uses them. No
 * score is calculated, adjusted or stored here. The longer periods are the
 * same score's history, never other scores.
 *
 * ---------------------------------------------------------------------------
 * IT NEVER GUESSES
 * ---------------------------------------------------------------------------
 * A day without a Health Score is left out, never counted as zero; too few
 * days is an empty state, not a smaller number. Sentences say what happened
 * and when, never why (config/compass.php).
 *
 * Pure: no database, no clock. lib/hydrate-compass.php hands it the history.
 */

declare(strict_types=1);

require_once __DIR__ . '/health-score.php';

if (!function_exists('score_compass')) {

    /**
     * The `compass` block both readers render (pages/score-compass.php, the
     * app's ScoreCompass screen).
     *
     * @param array<string,array> $history  health_score_history(), oldest first; its last day is now
     * @param array               $copy     config/compass.php
     * @param array<string,array> $areas    the categories in the order shown: id => [label, accent,
     *                                      icon, empty, collecting], as Overzicht and Gezondheid name them
     */
    function score_compass(array $history, array $copy, array $areas): array
    {
        $cfg = health_scoring_config();
        $now = $history === [] ? null : $history[array_key_last($history)];

        $overall = $now['overall']['score'] ?? null;

        /* The direction under the score, here and on Overzicht, is the one
           over `trend_days`; the periods below each say their own. */
        $trend = score_compass_trend($history, $copy, $areas, (int) $copy['rules']['trend_days'])
            + score_compass_periods($history, $copy, $areas);

        return [
            'title'       => $copy['title'],
            'lede'        => $copy['lede'],
            'back'        => $copy['back'],
            'open'        => $copy['open'],
            'footnote'    => $copy['footnote'],
            'score'       => ['value' => $overall, 'max' => 100, 'band' => score_colour_band($overall)],
            'direction'   => $trend['direction'],
            'composition' => score_compass_composition($now, $copy, $areas, $cfg),
            'trend'       => $trend,
            'comparison'  => score_compass_comparison($history, $copy, $cfg),
            'opportunity' => score_compass_opportunity($now, $copy, $areas, $cfg),
        ];
    }

    /* ==================================================================
       1  WHAT THE SCORE IS MADE OF
       ================================================================== */

    function score_compass_composition(?array $now, array $copy, array $areas, array $cfg): array
    {
        $c       = $copy['composition'];
        $window  = health_score_window_days();
        $counted = [];
        $rows    = [];

        foreach ($areas as $id => $area) {
            $result = $now[$id] ?? ['score' => null, 'days' => 0, 'components' => [], 'facts' => []];
            $score  = $result['score'];
            $days   = (int) $result['days'];

            if ($score !== null) {
                $counted[] = $area['label'];
            }

            $rows[] = [
                'id'      => $id,
                'label'   => $area['label'],
                'accent'  => $area['accent'],
                'icon'    => $area['icon'],
                'value'   => $score,
                'band'    => score_colour_band($score),
                'meta'    => match (true) {
                    $score !== null => sprintf(score_compass_plural($c['days'], $days), $days),
                    /* Data, but none new for too long: it is left out. */
                    !empty($result['expired']) && !empty($result['last_input'])
                        => sprintf($c['expired'], score_compass_date((string) $result['last_input'])),
                    default => score_compass_collecting($area, $days, (int) $cfg['min_days']),
                },
                'summary' => $score !== null && $id === 'nutrition' && isset($result['facts']['rating'])
                    ? sprintf($c['rating'], score_compass_decimal((float) $result['facts']['rating']))
                    : null,
                'parts'   => score_compass_parts($id, $result, $c, $cfg),
            ];
        }

        $note = match (count($counted)) {
            0       => sprintf($c['none'], '', $window),
            1       => sprintf($c['note_one'], $counted[0], $window),
            default => sprintf($c['note'], score_compass_list($counted), $window),
        };

        return ['title' => $c['title'], 'note' => $note, 'categories' => $rows];
    }

    /**
     * A category's components as its score weighs them: a component without
     * data does not count and the rest share its weight (health_weighted()),
     * so the weights shown are the ones the score used. A category with one
     * component has no list: its summary says it.
     */
    function score_compass_parts(string $category, array $result, array $c, array $cfg): array
    {
        $defs = $c['parts'][$category] ?? [];
        if (count($defs) < 2) {
            return [];
        }

        $weights    = $cfg[$category]['weights'];
        $components = $result['components'] ?? [];
        $scored     = $result['score'] !== null;

        $available = 0.0;
        foreach ($weights as $key => $weight) {
            if (($components[$key] ?? null) !== null) {
                $available += (float) $weight;
            }
        }
        $total = (float) array_sum($weights);

        $rows = [];
        foreach ($defs as $key => $def) {
            $value   = $components[$key] ?? null;
            $counted = $scored && $value !== null && $available > 0;
            $shown   = $value === null ? null : (int) round((float) $value);

            $rows[] = [
                'id'      => $key,
                'label'   => $def['label'],
                'value'   => $shown,
                'band'    => score_colour_band($shown),
                /* No score yet: the weight it will have when everything is
                   measured; otherwise the weight it has now, or none. */
                'weight'  => match (true) {
                    !$scored  => (int) round(100 * (float) $weights[$key] / $total),
                    $counted  => (int) round(100 * (float) $weights[$key] / $available),
                    default   => null,
                },
                'counted' => $counted,
                'note'    => match (true) {
                    !$scored  => null,
                    $counted  => score_compass_fact($category, $key, $result['facts'] ?? [], $c),
                    default   => $c['not_counted'] . ' · ' . $def['missing'],
                },
            ];
        }

        return $rows;
    }

    /** One line of what a component was worked out from, or null. */
    function score_compass_fact(string $category, string $key, array $facts, array $c): ?string
    {
        $f = $c['facts'];

        switch ($category . '.' . $key) {
            case 'sleep.duration':
                $minutes = $facts['minutes'] ?? [];
                return $minutes === [] ? null : sprintf($f['duration'], score_compass_hours(array_sum($minutes) / count($minutes)));

            case 'sleep.regularity':
                if (!isset($facts['bedtime_sd'], $facts['wake_sd'])) {
                    return null;
                }
                return sprintf($f['regularity'], (int) round($facts['bedtime_sd']), (int) round($facts['wake_sd']));

            case 'sleep.quality':
                $nights = (int) ($facts['quality_nights'] ?? 0);
                return $nights === 0 ? null : sprintf(score_compass_plural($f['quality'], $nights), $nights);

            case 'training.volume':
                return isset($facts['minutes_per_week'])
                    ? sprintf($f['volume'], (int) round($facts['minutes_per_week']))
                    : null;

            case 'training.intensity':
                return isset($facts['hard'], $facts['known'])
                    ? sprintf($f['intensity'], (int) $facts['hard'], (int) $facts['known'])
                    : null;

            case 'training.progression':
                $what = score_compass_signals($facts['progression']['signals'] ?? [], $c['signals']);
                return $what === null ? null : sprintf($f['progression'], score_compass_ucfirst($what));

            case 'training.balance':
                $frequency = $facts['balance']['frequency']['value'] ?? null;
                return $frequency === null ? null : sprintf($f['balance'], score_compass_decimal((float) $frequency));
        }

        return null;
    }

    /** What progression compared, as words: "tempo en VO2max". */
    function score_compass_signals(array $signals, array $names): ?string
    {
        $used = [];
        foreach ($names as $key => $name) {
            if (($signals[$key] ?? 0) > 0) {
                $used[] = $name;
            }
        }

        return $used === [] ? null : score_compass_list($used);
    }

    /** A category without a score yet: how many more days, as Gezondheid says it. */
    function score_compass_collecting(array $area, int $days, int $minDays): string
    {
        if ($days > 0 && !empty($area['collecting'])) {
            $needed = max(1, $minDays - $days);
            return sprintf((string) $area['collecting'], $needed . ' ' . ($needed === 1 ? 'dag' : 'dagen'));
        }

        return (string) ($area['empty'] ?? '');
    }

    /* ==================================================================
       2  WHAT IS CHANGING
       ================================================================== */

    /**
     * The last `trend_days` days of the Health Score, and what they show.
     *
     * The direction compares the week the period starts with — from the
     * first day with a score — with the last week, each an average over its
     * days with a score. Interpretations are only made where the data holds
     * them: a recovery needs a dip and a climb of `recovery_points` each,
     * "weeks in a row" needs every week's average to have moved the same way.
     */
    function score_compass_trend(array $history, array $copy, array $areas, int $days): array
    {
        $r = $copy['rules'];
        $t = $copy['trend'];

        $window = array_slice($history, -$days, null, true);
        $dates  = array_keys($window);
        $values = array_map(static fn ($at) => $at['overall']['score'], array_values($window));
        $scored = array_keys(array_filter($values, static fn ($v) => $v !== null));

        $out = [
            'title'      => $t['title'],
            'state'      => 'empty',
            'direction'  => null,
            'text'       => [],
            'empty'      => $t['empty'],
            'values'     => $values,
            'axis'       => $dates === [] ? [] : [score_compass_date($dates[0], true), $t['today']],
            'aria'       => sprintf($t['aria'], count($dates), ''),
        ];

        if ($scored === []) {
            return $out;
        }

        $out['state'] = 'collecting';
        $out['text']  = [$t['collecting']];

        $seg   = (int) $r['segment_days'];
        $first = $scored[0];
        $last  = count($values) - 1;
        $start = range($first, min($last, $first + $seg - 1));
        $end   = range(max(0, $last - $seg + 1), $last);

        $startValues = score_compass_pick($values, $start);
        $endValues   = score_compass_pick($values, $end);

        if (count($scored) < (int) $r['trend_min_days']
            || $first + $seg - 1 >= $last - $seg + 1
            || count($startValues) < (int) $r['segment_min']
            || count($endValues) < (int) $r['segment_min']) {
            return $out;
        }

        $from  = (int) round(array_sum($startValues) / count($startValues));
        $to    = (int) round(array_sum($endValues) / count($endValues));
        $delta = $to - $from;
        $key   = match (true) {
            $delta >= (int) $r['direction_points']  => 'up',
            $delta <= -(int) $r['direction_points'] => 'down',
            default                                 => 'flat',
        };

        $since = score_compass_date($dates[$first]);
        $text  = [];

        /* The movement — or the dip it recovered from. */
        $dip = score_compass_dip($values, $start, $end, $seg, (int) $r['segment_min']);

        if ($dip !== null
            && $from - $dip['value'] >= (int) $r['recovery_points']
            && $to - $dip['value'] >= (int) $r['recovery_points']) {
            $text[] = sprintf($t['recovery'], $from, $dip['value'], score_compass_date($dates[$dip['at']]), $to);
        } elseif ($key === 'flat') {
            $shown  = score_compass_pick($values, range($first, $last));
            $text[] = min($shown) === max($shown)
                ? sprintf($t['flat_same'], $since, min($shown))
                : sprintf($t['flat'], $since, min($shown), max($shown));
        } else {
            $text[] = sprintf($t[$key], $from, $since, $to);

            $weeks = score_compass_weeks($values, $seg, (int) $r['segment_min'], $key);
            if ($weeks >= 3) {
                $text[] = sprintf($t[$key === 'up' ? 'weeks_up' : 'weeks_down'], $weeks);
            }
        }

        /* The day it moved most after the first week — a young score moves a
           lot while its first days come in, which explains nothing about the
           period — and a category starting or stopping to count. */
        $step  = score_compass_step($values, $start[count($start) - 1] + 1);
        $event = score_compass_event($history, $window, $start, $areas);

        if ($event !== null) {
            $label = $areas[$event['area']]['label'];
            $on    = score_compass_date($dates[$event['at']]);

            if ($event['kind'] === 'joined' && $step !== null && $step['at'] === $event['at']
                && abs($step['to'] - $step['from']) >= (int) $r['step_points']) {
                $text[] = sprintf($t[$event['again'] ? 'joined_step_again' : 'joined_step'], $on, $label, $event['value'], $step['from'], $step['to']);
                $step   = null;
            } else {
                $text[] = match ($event['kind']) {
                    'joined' => sprintf($t[$event['again'] ? 'joined_again' : 'joined'], $on, $label, $event['value']),
                    default  => sprintf($t['left'], $on, $label),
                };
            }
        }

        if ($key !== 'flat' && $step !== null && abs($step['to'] - $step['from']) >= (int) $r['step_points']) {
            $text[] = sprintf($t['step'], score_compass_date($dates[$step['at']]), $step['from'], $step['to']);
        }

        /* When the score moved: of the categories that moved with it, the
           one that moved most — and within it, the component that did. */
        $moved = null;

        foreach (array_keys($areas) as $id) {
            $a = score_compass_mean(score_compass_pick(score_compass_series($window, $id), $start));
            $b = score_compass_mean(score_compass_pick(score_compass_series($window, $id), $end));

            if ($a !== null && $b !== null) {
                $change = (int) round($b) - (int) round($a);
                if (abs($change) >= (int) $r['direction_points'] && ($moved === null || abs($change) > abs($moved['change']))) {
                    $moved = ['id' => $id, 'change' => $change];
                }
            }
        }

        if ($key !== 'flat' && $moved !== null) {
            $part = score_compass_moved_part($window, $start, $end, $moved['id'], $areas[$moved['id']]['label'], $copy);
            if ($part !== null) {
                $text[] = sprintf($t['part'], $part['title'], $part['from'], $part['to']);
            }
        }

        $out['state']     = 'filled';
        $out['direction'] = ['key' => $key, 'label' => $copy['directions'][$key]];
        $out['text']      = $text;
        $out['aria']      = sprintf($t['aria'], count($dates), ': ' . mb_strtolower($copy['directions'][$key]) . ', van ' . $from . ' naar ' . $to);

        return $out;
    }

    /**
     * The history the trend card switches between: one entry per period of
     * config/compass.php (`history.periods`: 7, 30, 90 and 365 days), each
     * with its own sentences, axis and line, over ONE list of days — the
     * Health Score of each day as it was recorded, with its categories and
     * their parts, for reading the line day by day.
     *
     * The list starts at the first day with a score, so a new account's year
     * is the days it has had and never a row of placeholders; a period that
     * reaches back further says when the history begins (`since`). Without
     * any score at all there are no days, and every period is empty.
     */
    function score_compass_periods(array $history, array $copy, array $areas): array
    {
        $h     = $copy['history'];
        $t     = $copy['trend'];
        $dates = array_keys($history);
        $count = count($dates);

        $firstAt = null;
        foreach ($dates as $i => $date) {
            if (($history[$date]['overall']['score'] ?? null) !== null) {
                $firstAt = $i;
                break;
            }
        }

        $longest = max(array_map(static fn ($p) => (int) $p['days'], $h['periods']));
        $listAt  = $firstAt === null ? $count : max($firstAt, $count - $longest);
        $year    = $count === 0 ? '' : substr((string) $dates[$count - 1], 0, 4);

        $days = [];
        for ($i = $listAt; $i < $count; $i++) {
            $days[] = score_compass_day($history[$dates[$i]] + ['date' => (string) $dates[$i]], $copy, $areas, $year);
        }

        $periods = [];
        foreach ($h['periods'] as $period) {
            $length = (int) $period['days'];
            $from   = max(0, $count - $length);
            $at     = $firstAt === null ? $count : max($from, $firstAt);
            $values = array_map(static fn ($d) => $d['overall']['score'] ?? null, array_slice(array_values($history), $at));

            $entry = [
                'key'       => (string) $length,
                'label'     => (string) $period['label'],
                'days'      => $length,
                'state'     => 'empty',
                'direction' => null,
                'text'      => [],
                'empty'     => $t['empty'],
                'since'     => $firstAt !== null && $firstAt > $from
                    ? sprintf($h['since'], score_compass_date_in((string) $dates[$firstAt], $year))
                    : null,
                /* Where this period's line starts in `days`. */
                'start'     => $at - $listAt,
                'values'    => $values,
                'day_dots'  => !empty($period['day_dots']),
                'axis'      => score_compass_ticks(array_slice($dates, $at), (int) ($period['ticks'] ?? 2), $year, $t['today']),
                'aria'      => sprintf($h['aria'], (string) ($period['spoken'] ?? $period['label']), ''),
            ];

            $scored = array_values(array_filter($values, static fn ($v) => $v !== null));

            if ($scored !== []) {
                if ($length < 2 * (int) $copy['rules']['segment_days']) {
                    /* Too short to compare a first and a last week: what it held. */
                    $entry['state'] = count($scored) >= 2 ? 'filled' : 'collecting';
                    $entry['text']  = count($scored) >= 2
                        ? [min($scored) === max($scored)
                            ? sprintf($h['week_same'], min($scored))
                            : sprintf($h['week'], min($scored), max($scored))]
                        : [$t['collecting']];
                } else {
                    $analysis = score_compass_trend($history, $copy, $areas, $length);
                    $entry['state']     = $analysis['state'];
                    $entry['direction'] = $analysis['direction'];
                    $entry['text']      = $analysis['text'];
                    if ($analysis['direction'] !== null) {
                        $entry['aria'] = sprintf($h['aria'], (string) ($period['spoken'] ?? $period['label']),
                            ': ' . mb_strtolower($analysis['direction']['label']));
                    }
                }
            }

            $periods[] = $entry;
        }

        $names = [];
        foreach ($areas as $id => $area) {
            $names[] = ['id' => (string) $id, 'label' => (string) $area['label'], 'accent' => (string) $area['accent']];
        }

        return [
            'default' => (string) $h['default'],
            'switch'  => $h['switch'],
            /* What a day's reading is called, and its categories' names and
               colours once — each day lists them by id only. */
            'readout' => ['score' => $h['score'], 'hint' => $h['hint'], 'categories' => $names],
            'periods' => $periods,
            'days'    => $days,
        ];
    }

    /**
     * The dates under a period's line: `$count` of them, spread over its
     * days — the first, the last as "Vandaag" — each with where it stands,
     * in % from the left, on the day it names.
     *
     * @param string[] $dates the period's days, oldest first
     * @return array<int,array{label: string, x: float}>
     */
    function score_compass_ticks(array $dates, int $count, string $year, string $today): array
    {
        $n = count($dates);

        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return [['label' => $today, 'x' => 0.0]];
        }

        $count = max(2, min($count, $n));
        $ticks = [];

        for ($k = 0; $k < $count; $k++) {
            $i = (int) round($k * ($n - 1) / ($count - 1));
            $ticks[] = [
                'label' => $i === $n - 1 ? $today : score_compass_date_in((string) $dates[$i], $year, true),
                'x'     => round($i / ($n - 1) * 100, 2),
            ];
        }

        return $ticks;
    }

    /**
     * One day of the history as the reading shows it: the date, the Health
     * Score and how it came to be there, and each category with its parts.
     */
    function score_compass_day(array $day, array $copy, array $areas, string $year): array
    {
        $h     = $copy['history'];
        $value = $day['overall']['score'] ?? null;
        $state = (string) ($day['state'] ?? 'stored');

        $categories = [];
        foreach ($areas as $id => $area) {
            $result = $day[$id] ?? [];
            $score  = $result['score'] ?? null;

            $categories[] = [
                'id'    => $id,
                'value' => $score,
                'band'  => score_colour_band($score),
                'parts' => $score === null ? null : score_compass_part_line($id, (array) ($result['components'] ?? []), $copy),
            ];
        }

        return [
            'date'       => (string) $day['date'],
            'label'      => $state === 'today' ? $copy['trend']['today'] : score_compass_date_in((string) $day['date'], $year),
            'value'      => $value,
            'band'       => score_colour_band($value),
            'state'      => $state,
            'note'       => match (true) {
                $state === 'carried' && !empty($day['from']) => sprintf($h['carried'], score_compass_date_in((string) $day['from'], $year)),
                $value === null                              => $h['none'],
                default                                      => null,
            },
            'categories' => $categories,
        ];
    }

    /**
     * A category's parts on one line, as recorded that day: "Slaapduur 72 ·
     * Regelmaat 60 · Kwaliteit 70"; Voeding's one part as its cijfer,
     * "Dagcijfer 7,7". Null when none was recorded.
     */
    function score_compass_part_line(string $category, array $components, array $copy): ?string
    {
        $defs  = $copy['composition']['parts'][$category] ?? [];
        $items = [];

        foreach ($defs as $key => $def) {
            $value = $components[$key] ?? null;
            if ($value === null) {
                continue;
            }

            $items[] = $def['label'] . ' ' . (count($defs) < 2
                ? score_compass_decimal((float) $value / 10)
                : (string) (int) round((float) $value));
        }

        return $items === [] ? null : implode(' · ', $items);
    }

    /** A date, with its year when that is not the current one: "7 okt 2025". */
    function score_compass_date_in(string $date, string $year, bool $short = false): string
    {
        $text = score_compass_date($date, $short);

        return substr($date, 0, 4) === $year ? $text : $text . ' ' . substr($date, 0, 4);
    }

    /** The values at the given positions that have a score. */
    function score_compass_pick(array $values, array $positions): array
    {
        $out = [];
        foreach ($positions as $i) {
            if (isset($values[$i]) && $values[$i] !== null) {
                $out[] = $values[$i];
            }
        }

        return $out;
    }

    function score_compass_mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** One category's score per day of the window. */
    function score_compass_series(array $window, string $area): array
    {
        return array_map(static fn ($at) => $at[$area]['score'] ?? null, array_values($window));
    }

    /**
     * The lowest week between the first and the last: the lowest average of
     * seven consecutive days whose middle lies strictly between theirs.
     *
     * @return array{value: int, at: int}|null  at: the middle day of that week
     */
    function score_compass_dip(array $values, array $start, array $end, int $seg, int $min): ?array
    {
        $low  = null;
        $half = intdiv($seg, 2);

        for ($i = $start[0] + $half + 1; $i < $end[0] + $half; $i++) {
            $week = score_compass_pick($values, range($i - $half, $i - $half + $seg - 1));
            if (count($week) < $min) {
                continue;
            }

            $mean = (int) round(array_sum($week) / count($week));
            if ($low === null || $mean < $low['value']) {
                $low = ['value' => $mean, 'at' => $i];
            }
        }

        return $low;
    }

    /**
     * How many weeks in a row the weekly average moved in $direction, back
     * from this week: blocks of $seg days ending today.
     */
    function score_compass_weeks(array $values, int $seg, int $min, string $direction): int
    {
        $last  = count($values) - 1;
        $means = [];

        for ($k = 0; $last - ($k + 1) * $seg + 1 >= 0; $k++) {
            $week = score_compass_pick($values, range($last - ($k + 1) * $seg + 1, $last - $k * $seg));
            if (count($week) < $min) {
                break;
            }
            $means[] = (int) round(array_sum($week) / count($week));
        }

        $weeks = 0;
        for ($k = 0; $k + 1 < count($means); $k++) {
            $moved = $direction === 'up' ? $means[$k] > $means[$k + 1] : $means[$k] < $means[$k + 1];
            if (!$moved) {
                break;
            }
            $weeks++;
        }

        return $weeks;
    }

    /**
     * The biggest change from one day to the next, both days with a score,
     * on or after day $from.
     *
     * @return array{at: int, from: int, to: int}|null
     */
    function score_compass_step(array $values, int $from = 1): ?array
    {
        $step = null;

        for ($i = max(1, $from); $i < count($values); $i++) {
            if ($values[$i] === null || $values[$i - 1] === null) {
                continue;
            }

            $change = abs($values[$i] - $values[$i - 1]);
            if ($change > 0 && ($step === null || $change > abs($step['to'] - $step['from']))) {
                $step = ['at' => $i, 'from' => $values[$i - 1], 'to' => $values[$i]];
            }
        }

        return $step;
    }

    /**
     * A category that started or stopped counting in the period: not in the
     * first week and in the score today, or the other way round. Of several,
     * the one whose day moved the overall score most.
     *
     * @return array{kind: string, area: string, at: int, value: ?int, again: bool}|null
     */
    function score_compass_event(array $history, array $window, array $start, array $areas): ?array
    {
        $values = array_map(static fn ($at) => $at['overall']['score'], array_values($window));
        $before = array_slice($history, 0, count($history) - count($window), true);
        $last   = count($values) - 1;
        $best   = null;

        foreach (array_keys($areas) as $id) {
            $series  = score_compass_series($window, $id);
            $atStart = score_compass_pick($series, $start) !== [];
            $today   = $series[$last] !== null;

            if ($atStart === $today) {
                continue;
            }

            /* The day the current run began: the first of the days counting
               (joined) or not counting (left) up to today. */
            $at = $last;
            while ($at > 0 && ($series[$at - 1] !== null) === $today) {
                $at--;
            }

            $again = false;
            if ($today) {
                foreach (array_slice($series, 0, $at) as $v) {
                    $again = $again || $v !== null;
                }
                foreach ($before as $day) {
                    $again = $again || ($day[$id]['score'] ?? null) !== null;
                }
            }

            $moved = ($at > 0 && $values[$at] !== null && $values[$at - 1] !== null) ? abs($values[$at] - $values[$at - 1]) : 0;

            if ($best === null || $moved > $best['moved']) {
                $best = [
                    'kind'  => $today ? 'joined' : 'left',
                    'area'  => $id,
                    'at'    => $at,
                    'value' => $today ? $series[$at] : null,
                    'again' => $again,
                    'moved' => $moved,
                ];
            }
        }

        if ($best !== null) {
            unset($best['moved']);
        }

        return $best;
    }

    /**
     * Within a category that moved, the component whose change counted most
     * for it — its change times the weight it had. A category of one
     * component (Voeding) is named itself, with its own score: its one
     * component is on another scale (a cijfer of 7 is 70).
     *
     * @return array{title: string, from: int, to: int}|null
     */
    function score_compass_moved_part(array $window, array $start, array $end, string $category, string $label, array $copy): ?array
    {
        $defs    = $copy['composition']['parts'][$category] ?? [];
        $days    = array_values($window);
        $best    = null;

        if (count($defs) < 2) {
            $defs    = ['score' => ['title' => $label]];
            $weights = ['score' => 1.0];
        } else {
            $weights = health_scoring_config()[$category]['weights'];
            /* A component is a common noun mid-sentence; a category keeps its name. */
            $defs    = array_map(static fn ($def) => ['title' => score_compass_lcfirst($def['title'])], $defs);
        }

        foreach ($defs as $key => $def) {
            $series = array_map(
                static fn ($at) => $key === 'score' ? ($at[$category]['score'] ?? null) : ($at[$category]['components'][$key] ?? null),
                $days
            );
            $a = score_compass_mean(score_compass_pick($series, $start));
            $b = score_compass_mean(score_compass_pick($series, $end));

            if ($a === null || $b === null) {
                continue;
            }

            $from   = (int) round($a);
            $to     = (int) round($b);
            $weight = (float) ($weights[$key] ?? 0);

            if ($from !== $to && ($best === null || abs($to - $from) * $weight > $best['size'])) {
                $best = ['title' => $def['title'], 'from' => $from, 'to' => $to, 'size' => abs($to - $from) * $weight];
            }
        }

        if ($best !== null) {
            unset($best['size']);
        }

        return $best;
    }

    /* ==================================================================
       3  COMPARED WITH YOURSELF
       ================================================================== */

    /**
     * The score now, over the engine's window, and the average of the daily
     * score over the last week, the last 30 days and the 30 before them —
     * each only with enough days that had a score.
     */
    function score_compass_comparison(array $history, array $copy, array $cfg): array
    {
        $r = $copy['rules'];
        $c = $copy['comparison'];

        $values = array_values(array_map(static fn ($at) => $at['overall']['score'], $history));
        $count  = count($values);
        $period = (int) $r['period_days'];

        /* The $length days that end $offset days before today; null unless
           the history reaches back that far and $min of them had a score. */
        $average = static function (int $offset, int $length, int $min) use ($values, $count): ?int {
            $last  = $count - 1 - $offset;
            $first = $last - $length + 1;
            if ($first < 0) {
                return null;
            }

            $days = score_compass_pick($values, range($first, $last));

            return count($days) < $min ? null : (int) round(array_sum($days) / count($days));
        };

        $now      = $count === 0 ? null : $values[$count - 1];
        $week     = $average(0, 7, (int) $r['week_min']);
        $current  = $average(0, $period, (int) $r['period_min']);
        $previous = $average($period, $period, (int) $r['period_min']);

        $row = static fn (string $key, ?int $value, ?string $note) => [
            'id'    => $key,
            'label' => $c['rows'][$key]['label'],
            'value' => $value,
            'band'  => score_colour_band($value),
            'note'  => $value === null ? $c['empty'] : $note,
        ];

        $delta = null;
        if ($current !== null && $previous !== null) {
            $change = $current - $previous;
            $delta  = [
                'value' => $change,
                'text'  => match (true) {
                    $change > 0 => sprintf($c['delta']['up'], $change),
                    $change < 0 => sprintf($c['delta']['down'], -$change),
                    default     => $c['delta']['same'],
                },
            ];
        }

        return [
            'title' => $c['title'],
            'note'  => $c['note'],
            'rows'  => [
                $row('now', $now, sprintf($c['rows']['now']['note'], health_score_window_days())),
                $row('week', $week, $c['rows']['week']['note']),
                $row('period', $current, $c['rows']['period']['note']),
                $row('previous', $previous, $c['rows']['previous']['note']),
            ],
            'delta' => $delta,
        ];
    }

    /* ==================================================================
       4  THE BIGGEST OPPORTUNITY
       ================================================================== */

    /**
     * The component with the most room on the Health Score: what it lacks of
     * 100, times the share of the Health Score it decides — its weight among
     * its category's components that count, over the categories that count.
     * Only in a category with `opportunity_min_days` of data, and only when
     * that room is at least `opportunity_min_points`.
     */
    function score_compass_opportunity(?array $now, array $copy, array $areas, array $cfg): array
    {
        $r = $copy['rules'];
        $o = $copy['opportunity'];

        $out = [
            'title'    => $o['title'],
            'state'    => 'empty',
            'empty'    => $o['empty'],
            'category' => null,
            'label'    => null,
            'accent'   => null,
            'icon'     => null,
            'part'     => null,
            'name'     => null,
            'value'    => null,
            'band'     => null,
            'fact'     => null,
            'relation' => null,
            'gain'     => null,
            'gain_text' => null,
        ];

        $scored = array_filter(array_keys($areas), static fn ($id) => ($now[$id]['score'] ?? null) !== null);
        if ($scored === []) {
            return $out;
        }

        $best = null;
        foreach ($scored as $id) {
            if ((int) $now[$id]['days'] < (int) $r['opportunity_min_days']) {
                continue;
            }

            foreach (score_compass_shares($id, $now[$id]['components'], $cfg) as $key => $share) {
                $gain = $share / count($scored) * (100 - (float) $now[$id]['components'][$key]);
                if ($best === null || $gain > $best['gain']) {
                    $best = ['id' => $id, 'key' => $key, 'gain' => $gain];
                }
            }
        }

        if ($best === null) {
            return $out;
        }

        if ((int) round($best['gain']) < (int) $r['opportunity_min_points']) {
            $out['empty'] = $o['none'];
            return $out;
        }

        $id    = $best['id'];
        $key   = $best['key'];
        $value = (int) round((float) $now[$id]['components'][$key]);
        $title = $copy['composition']['parts'][$id][$key]['title'] ?? $key;
        [$fact, $relation] = score_compass_explain($id, $key, $now[$id], $title, $copy, $cfg);

        return [
            'title'     => $o['title'],
            'state'     => 'filled',
            'empty'     => null,
            'category'  => $id,
            'label'     => $areas[$id]['label'],
            'accent'    => $areas[$id]['accent'],
            'icon'      => $areas[$id]['icon'],
            'part'      => $key,
            'name'      => $title,
            'value'     => $value,
            'band'      => score_colour_band($value),
            'fact'      => $fact,
            'relation'  => $relation,
            'gain'      => (int) round($best['gain']),
            'gain_text' => sprintf($o['gain'], (int) round($best['gain'])),
        ];
    }

    /**
     * Each counted component's share of its category score: its weight over
     * the weights of the components that have data (health_weighted()).
     *
     * @return array<string,float>
     */
    function score_compass_shares(string $category, array $components, array $cfg): array
    {
        $weights = $cfg[$category]['weights'] ?? array_fill_keys(array_keys($components), 1.0);

        $available = 0.0;
        foreach ($components as $key => $value) {
            if ($value !== null && (float) ($weights[$key] ?? 0) > 0) {
                $available += (float) $weights[$key];
            }
        }

        $shares = [];
        foreach ($components as $key => $value) {
            if ($value !== null && $available > 0 && (float) ($weights[$key] ?? 0) > 0) {
                $shares[$key] = (float) $weights[$key] / $available;
            }
        }

        return $shares;
    }

    /**
     * What was measured, and what a higher score would go together with —
     * from the facts the component was worked out from.
     *
     * @return array{0: string, 1: string}
     */
    function score_compass_explain(string $category, string $key, array $result, string $title, array $copy, array $cfg): array
    {
        $texts = $copy['opportunity']['texts'];
        $top   = (float) $copy['rules']['top_score'];
        $facts = $result['facts'] ?? [];
        $pair  = static fn (string $name, array $args) => [
            vsprintf($texts[$name]['fact'], $args),
            vsprintf($texts[$name]['relation'], $args),
        ];

        switch ($category . '.' . $key) {
            case 'sleep.duration':
                $curve = $cfg['sleep']['duration_curve'];
                [$lo, $hi] = score_compass_top($curve, $top);
                $nights = $facts['minutes'] ?? [];
                $short = $long = 0;
                $lostShort = $lostLong = 0.0;
                foreach ($nights as $minutes) {
                    $hours = $minutes / 60;
                    if ($hours < $lo) {
                        $short++;
                        $lostShort += 100 - health_curve($curve, $hours);
                    } elseif ($hours > $hi) {
                        $long++;
                        $lostLong += 100 - health_curve($curve, $hours);
                    }
                }
                if ($short + $long > 0) {
                    $side = $lostShort >= $lostLong ? 'short' : 'long';
                    return $pair('sleep.duration.' . $side, [
                        $side === 'short' ? $short : $long, count($nights),
                        score_compass_hours($lo * 60), score_compass_hours($hi * 60),
                    ]);
                }
                break;

            case 'sleep.regularity':
                $weights = $cfg['sleep']['regularity']['weights'];
                $spread  = ['bedtime' => 'bedtime_sd', 'wake_time' => 'wake_sd', 'duration' => 'duration_sd'];
                $part    = score_compass_weakest($facts['regularity'] ?? [], $weights);
                if ($part !== null && isset($facts[$spread[$part]])) {
                    return $pair('sleep.regularity.' . $part, [(int) round($facts[$spread[$part]])]);
                }
                break;

            case 'sleep.quality':
                $q     = $cfg['sleep']['quality'];
                $parts = array_map(static fn ($p) => $p === null ? null : $p['score'], $facts['quality'] ?? []);
                $part  = score_compass_weakest($parts, $q['weights']);
                if ($part !== null) {
                    $value = (float) $facts['quality'][$part]['value'];
                    if ($part === 'deep' || $part === 'rem') {
                        /* On average inside the top range, a direction would be a guess. */
                        [$lo, $hi] = score_compass_top($q[$part . '_curve'], $top);
                        if ($value >= $lo && $value <= $hi) {
                            break;
                        }
                        return $pair('sleep.quality.' . $part . ($value < $lo ? '.more' : '.less'), [(int) round($value)]);
                    }
                    return $pair('sleep.quality.' . $part, [(int) round($value)]);
                }
                break;

            case 'nutrition.rating':
                if (isset($facts['rating'])) {
                    return $pair('nutrition.rating', [score_compass_decimal((float) $facts['rating']), (int) $result['days']]);
                }
                break;

            case 'training.volume':
                if (isset($facts['minutes_per_week'])) {
                    [, $hi] = score_compass_top($cfg['training']['volume_curve'], $top);
                    $more = $facts['minutes_per_week'] < $hi;
                    return $pair('training.volume.' . ($more ? 'more' : 'less'), [(int) round($facts['minutes_per_week'])]);
                }
                break;

            case 'training.intensity':
                if (isset($facts['hard'], $facts['known']) && $facts['known'] > 0) {
                    [$lo] = score_compass_top($cfg['training']['intensity']['hard_share_curve'], $top);
                    $more = $facts['hard'] / $facts['known'] < $lo;
                    return $pair('training.intensity.' . ($more ? 'more' : 'less'), [(int) $facts['hard'], (int) $facts['known']]);
                }
                break;

            case 'training.progression':
                $what   = score_compass_signals($facts['progression']['signals'] ?? [], $copy['composition']['signals']);
                $change = $facts['progression']['change'] ?? null;
                if ($what !== null && $change !== null) {
                    $text    = $texts['training.progression'];
                    $percent = (int) round(abs($change) * 100);
                    $fact    = $percent === 0
                        ? sprintf($text['same'], $what)
                        : sprintf($text['fact'], $what, $percent . '% ' . ($change > 0 ? 'beter' : 'minder goed'));
                    return [$fact, $text['relation']];
                }
                break;

            case 'training.balance':
                $b     = $cfg['training']['balance'];
                $parts = array_map(static fn ($p) => $p === null ? null : $p['score'], $facts['balance'] ?? []);
                $part  = score_compass_weakest($parts, $b['weights']);
                $fact  = $part === null ? null : $facts['balance'][$part];
                switch ($part) {
                    case 'frequency':
                        [$lo] = score_compass_top($b['frequency_curve'], $top);
                        return $pair('training.balance.frequency.' . ($fact['value'] < $lo ? 'more' : 'less'), [score_compass_decimal((float) $fact['value'])]);
                    case 'rest':
                        return $pair('training.balance.rest', [(int) $fact['value']]);
                    case 'spikes':
                    case 'hard_days':
                        return $pair('training.balance.' . $part, [(int) $fact['value'], (int) $fact['of']]);
                    case 'sleep':
                        [$lo] = score_compass_top($cfg['sleep']['duration_curve'], $top);
                        return $pair('training.balance.sleep.' . ($fact['value'] / 60 < $lo ? 'short' : 'long'), [score_compass_hours((float) $fact['value'])]);
                }
                break;
        }

        /* No sentence of its own, or no facts to fill one: what it scores. */
        $name = score_compass_lcfirst($title);

        return [
            sprintf($texts['default']['fact'], $name, (int) round((float) $result['components'][$key])),
            sprintf($texts['default']['relation'], $name),
        ];
    }

    /**
     * Of the parts with a score, the one with the most room: weight times
     * what it lacks of 100.
     */
    function score_compass_weakest(array $parts, array $weights): ?string
    {
        $weakest = null;
        $room    = 0.0;

        foreach ($parts as $key => $score) {
            if ($score === null) {
                continue;
            }

            $lack = (float) ($weights[$key] ?? 0) * (100 - (float) $score);
            if ($weakest === null || $lack > $room) {
                $weakest = $key;
                $room    = $lack;
            }
        }

        return $weakest;
    }

    /**
     * Where a scoring curve is at its top: the lowest and highest input
     * among its points that score at least $top — the range its sentences
     * name ("tussen 7:30 en 8:30").
     *
     * @param array<array{0: float|int, 1: float|int}> $curve
     * @return array{0: float, 1: float}
     */
    function score_compass_top(array $curve, float $top): array
    {
        $inputs = [];
        foreach ($curve as [$x, $y]) {
            if ((float) $y >= $top) {
                $inputs[] = (float) $x;
            }
        }

        if ($inputs === []) {
            $best   = max(array_column($curve, 1));
            $inputs = array_map(static fn ($p) => (float) $p[0], array_filter($curve, static fn ($p) => $p[1] == $best));
        }

        return [min($inputs), max($inputs)];
    }

    /* ==================================================================
       WORDS AND NUMBERS
       ================================================================== */

    /** "Slaap, Voeding en Sport". */
    function score_compass_list(array $items): string
    {
        $items = array_values($items);

        return count($items) < 2
            ? (string) ($items[0] ?? '')
            : implode(', ', array_slice($items, 0, -1)) . ' en ' . end($items);
    }

    /** [singular, plural] by count. */
    function score_compass_plural(array|string $forms, int $count): string
    {
        return is_array($forms) ? ($count === 1 ? $forms[0] : $forms[1]) : $forms;
    }

    /** One decimal, with a comma: 7,4. */
    function score_compass_decimal(float $value): string
    {
        return number_format($value, 1, ',', '');
    }

    /** Minutes as the app writes durations: 399 -> "6:39". */
    function score_compass_hours(float $minutes): string
    {
        $minutes = (int) round($minutes);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** "2026-09-03" -> "3 september", or "3 sep". */
    function score_compass_date(string $date, bool $short = false): string
    {
        static $months = [
            1 => ['jan', 'januari'], ['feb', 'februari'], ['mrt', 'maart'], ['apr', 'april'],
            ['mei', 'mei'], ['jun', 'juni'], ['jul', 'juli'], ['aug', 'augustus'],
            ['sep', 'september'], ['okt', 'oktober'], ['nov', 'november'], ['dec', 'december'],
        ];

        $day = new DateTimeImmutable($date);

        return (int) $day->format('j') . ' ' . $months[(int) $day->format('n')][$short ? 0 : 1];
    }

    function score_compass_ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    function score_compass_lcfirst(string $text): string
    {
        /* Keep abbreviations (REM, VO2max) as they are. */
        return preg_match('/^\p{Lu}\p{Lu}/u', $text) ? $text : mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}

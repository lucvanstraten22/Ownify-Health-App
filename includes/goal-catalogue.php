<?php
/**
 * The goal vocabulary, units and dates — PURE (docs/GOALS.md).
 *
 * Everything here works from config/goals.php alone, in the reader's
 * language, and never touches the database, so the wizard (both apps), the
 * engine (includes/goal-progress.php) and the tests all read one definition:
 *
 *   goal_catalogue()          the categories, measurements, units and types
 *   goal_measurement()        one measurement, or an Eigen gewoonte's rules
 *   goal_unit()/goal_unit_of() a unit as it is written and stored
 *   goal_to_stored()          a typed figure in its stored unit: 176 lb → 79.83 kg
 *   goal_value_text()         a stored figure in a unit: "10.000 stappen", "8 uur"
 *   goal_add_months()         calendar months, clamped: 31 jan + 1 = 28/29 feb
 *   goal_duration_end()       a period's last day from its first and a looptijd
 *   goal_periods()            a per-period streak's weeks/months/…, from its start
 *   goal_round_half_up()      2,5 → 3, 2,49 → 2
 *
 * assets/js/goal-wizard.js and GoalWizard.kt repeat the date and unit
 * arithmetic word for word (and tools/goal-engine-test.php pins it), so an
 * end date or a converted figure never differs between the website, the app
 * and what the server stores.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)

if (!function_exists('goal_catalogue')) {

    /** config/goals.php, in the reader's language. */
    function goal_catalogue(): array
    {
        return config_copy('goals');
    }

    /* ==================================================================
       CATEGORIES AND MEASUREMENTS
       ================================================================== */

    /** One category with its code, or null when there is none by that name. */
    function goal_category(?string $code): ?array
    {
        $category = goal_catalogue()['categories'][(string) $code] ?? null;

        return $category === null ? null : ['code' => (string) $code] + $category;
    }

    /**
     * One measurement with its code — or, for null, what an Eigen gewoonte
     * may be: kept by hand, any type, any of its units or a word of its own.
     * Null when $code is not a measurement this app knows.
     */
    function goal_measurement(?string $code): ?array
    {
        $catalogue = goal_catalogue();

        if ($code === null || $code === '' || $code === 'custom') {
            return ['code' => null, 'source_kind' => 'manual', 'source_key' => null, 'unit_code' => null,
                    'daily' => false, 'custom' => true] + $catalogue['custom'];
        }

        $measurement = $catalogue['measurements'][$code] ?? null;

        return $measurement === null ? null : ['code' => $code] + $measurement + ['daily' => false, 'custom' => false];
    }

    /** Every measurement, in the wizard's order, each with its code. */
    function goal_measurements(): array
    {
        $out = [];
        foreach (goal_catalogue()['measurements'] as $code => $measurement) {
            $out[$code] = ['code' => $code] + $measurement + ['daily' => false, 'custom' => false];
        }

        return $out;
    }

    /** Whether a measurement can be read from data rather than kept by hand. */
    function goal_measurement_auto(array $measurement): bool
    {
        return ($measurement['source_kind'] ?? 'manual') !== 'manual';
    }

    /**
     * Whether a type (and a streak's variant) makes sense for a measurement.
     * A weight cannot be added up; only what can be counted — trainingen,
     * a gewoonte — can be done a number of times per week.
     */
    function goal_type_allowed(array $measurement, string $type, ?string $variant = null): bool
    {
        if (!in_array($type, $measurement['types'] ?? [], true)) {
            return false;
        }

        if ($type !== 'streak') {
            return $variant === null;
        }

        return $variant !== null && in_array($variant, $measurement['variants'] ?? [], true);
    }

    /* ==================================================================
       UNITS
       ================================================================== */

    /**
     * A unit from the catalogue, ready to write and convert with: its code,
     * its word and singular in the reader's language, whether it sits against
     * the number, its decimals, the unit it is stored in and the factor.
     */
    function goal_unit(?string $code): ?array
    {
        $unit = goal_catalogue()['goal_units'][(string) $code] ?? null;

        if ($unit === null) {
            return null;
        }

        return [
            'code'      => (string) $code,
            'label'     => (string) $unit['label'],
            'one'       => (string) ($unit['one'] ?? $unit['label']),
            'attached'  => (bool) ($unit['attached'] ?? false),
            'decimals'  => (int) $unit['decimals'],
            'canonical' => (string) $unit['canonical'],
            'factor'    => (float) $unit['factor'],
        ];
    }

    /** An Eigen gewoonte's own word, counted as typed. */
    function goal_unit_custom(string $word): array
    {
        $word = trim($word);

        return ['code' => null, 'label' => $word, 'one' => $word, 'attached' => false,
                'decimals' => 2, 'canonical' => null, 'factor' => 1.0];
    }

    /** The unit a goal row is shown and typed in: its chosen one, or its own word. */
    function goal_unit_of(array $goal): array
    {
        if (!empty($goal['unit_code'])) {
            $unit = goal_unit((string) $goal['unit_code']);
            if ($unit !== null) {
                return $unit;
            }
        }

        return goal_unit_custom((string) ($goal['custom_unit'] ?? ''));
    }

    /**
     * The unit a measurement starts in: the imperial one for somebody who
     * reads Imperiaal (Instellingen › Eenheden), its first otherwise. The
     * goal may choose another of its units; nothing global changes.
     */
    function goal_default_unit(array $measurement, ?bool $imperial = null): string
    {
        $imperial = $imperial ?? units_imperial();
        $codes    = $measurement['unit_codes'] ?? [];

        if ($imperial && !empty($measurement['imperial_unit']) && in_array($measurement['imperial_unit'], $codes, true)) {
            return (string) $measurement['imperial_unit'];
        }

        return (string) ($codes[0] ?? $measurement['unit_code'] ?? '');
    }

    /** A figure typed in $unit, as it is stored: 176 lb → 79.832 kg, 7,5 uur → 450 min. */
    function goal_to_stored(float $typed, array $unit): float
    {
        return $typed * (float) $unit['factor'];
    }

    /** A stored figure in $unit: 450 min → 7,5 uur. */
    function goal_from_stored(float $stored, array $unit): float
    {
        return $stored / (float) $unit['factor'];
    }

    /**
     * "10.000 stappen", "7,5 uur", "85%", "1 training" — "10,000 steps",
     * "176.4 lb": a stored figure in a unit, in the reader's language, at
     * most the unit's decimals and no trailing zeroes.
     */
    function goal_value_text(float $stored, array $unit, ?int $decimals = null): string
    {
        $shown = goal_from_stored($stored, $unit);
        $text  = i18n_number_trim($shown, $decimals ?? (int) $unit['decimals']);

        if ($text === '-0') {
            $text = '0';
        }

        if ($unit['label'] === '') {
            return $text;
        }

        $word = abs(round($shown, $decimals ?? (int) $unit['decimals']) - 1.0) < 1e-9 ? $unit['one'] : $unit['label'];

        return $unit['attached'] ? $text . $word : $text . ' ' . $word;
    }

    /**
     * A figure as somebody types it for a goal: as i18n_parse_number(), and
     * a whole number grouped in threes the reader's way is thousands —
     * "10.000" stappen in Dutch, "10,000" steps in English, as the wizard's
     * own examples write them — not 10. "72,5", "72.5" and "0.500" stay
     * decimals. assets/js/goal-wizard.js and GoalWizard.kt read it the same.
     */
    function goal_parse_number(mixed $text): ?float
    {
        if (is_int($text) || is_float($text)) {
            return (float) $text;
        }

        $clean = trim(str_replace([' ', "\u{00A0}"], '', (string) $text));
        $group = i18n_locale() === 'en' ? ',' : '.';

        if (preg_match('/^-?[1-9][0-9]{0,2}(' . preg_quote($group, '/') . '[0-9]{3})+$/', $clean)) {
            return (float) str_replace($group, '', $clean);
        }

        return i18n_parse_number($clean);
    }

    /** "1 dag", "12 dagen" — "1 day", "12 days". */
    function goal_days_text(int|float $days): string
    {
        $days = (int) round($days);

        return tn($days, '%d dag', '%d dagen');
    }

    /* ==================================================================
       DATES
       ================================================================== */

    /**
     * Calendar months onward from a date, the day clamped to the month's last:
     * 31 januari + 1 = 28 (or 29) februari, 29 februari + 12 = 28 februari.
     * Always from the date given, so month after month from the 31st is
     * 28 feb, 31 mrt, 30 apr — never drifting to the 28th.
     */
    function goal_add_months(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n') - 1 + $months;
        $year += (int) floor($month / 12);
        $month = (($month % 12) + 12) % 12 + 1;
        $last  = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $day   = min((int) $date->format('j'), $last);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** A span — ['days' => 7] or ['months' => 3] — onward from a date, $times over. */
    function goal_span_add(DateTimeImmutable $date, array $span, int $times = 1): DateTimeImmutable
    {
        if (isset($span['months'])) {
            return goal_add_months($date, (int) $span['months'] * $times);
        }

        return $date->modify('+' . ((int) ($span['days'] ?? 0) * $times) . ' day');
    }

    /**
     * The last day of a period that starts on $start and lasts one of the
     * looptijden (`durations`): the day before the same date a week, month…
     * later. 1 week from Monday 6 januari is Sunday 12 januari; 1 maand from
     * 31 januari is 27 februari (28 feb is where the next month begins).
     */
    function goal_duration_end(DateTimeImmutable $start, string $duration): ?DateTimeImmutable
    {
        $span = goal_catalogue()['durations'][$duration] ?? null;

        return $span === null ? null : goal_span_add($start, $span)->modify('-1 day');
    }

    /** Which looptijd a period is exactly, or null for one picked by hand. */
    function goal_duration_of(DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        foreach (array_keys(goal_catalogue()['durations']) as $key) {
            if (goal_duration_end($start, $key)?->format('Y-m-d') === $end->format('Y-m-d')) {
                return $key;
            }
        }

        return null;
    }

    /** Days from $from to $to, both included. */
    function goal_days_between(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a') + 1;
    }

    /** Half up: 2,5 → 3, 1,49 → 1, 0,5 → 1 — for the share of a short last period. */
    function goal_round_half_up(float $value): int
    {
        return (int) floor($value + 0.5 + 1e-9);
    }

    /**
     * A per-period streak's periods, counted from its start date (not from
     * the calendar): week 1 is the goal's first seven days. Each is
     * `[index, start, end, days, full_days, required]`.
     *
     * A last period the end date cuts short asks for its share of the
     * frequency, rounded half up — 3 per week over the last 3 days of a goal
     * is 3 × 3/7 = 1,29 → 1. One that comes to 0 asks nothing and is
     * skipped: it counts neither way.
     *
     * @return list<array{index: int, start: DateTimeImmutable, end: DateTimeImmutable, days: int, full_days: int, required: int}>
     */
    function goal_periods(DateTimeImmutable $start, DateTimeImmutable $end, string $unit, int $frequency): array
    {
        $span = goal_catalogue()['periods'][$unit] ?? null;

        if ($span === null || $frequency < 1 || $end < $start) {
            return [];
        }

        $out = [];

        for ($k = 0; $k < 1000; $k++) {
            $from = goal_span_add($start, $span, $k);

            if ($from > $end) {
                break;
            }

            $fullEnd = goal_span_add($start, $span, $k + 1)->modify('-1 day');
            $to      = $fullEnd > $end ? $end : $fullEnd;
            $full    = goal_days_between($from, $fullEnd);
            $days    = goal_days_between($from, $to);

            $out[] = [
                'index'     => $k,
                'start'     => $from,
                'end'       => $to,
                'days'      => $days,
                'full_days' => $full,
                'required'  => $days === $full ? $frequency : goal_round_half_up($frequency * $days / $full),
            ];
        }

        return $out;
    }

    /* ==================================================================
       PROGRESS — PURE
       ================================================================== */

    /**
     * A Mijlpaal's percentage: how much of the way from the baseline to the
     * target the current value has come. 80 → 100 kg at 90 is 50%; at 85
     * afterwards 25% — it follows the value, it never keeps the best. Below
     * the start is 0, past the target 100. A target equal to its baseline
     * cannot be divided by: it is reached (100) or not (0).
     */
    function goal_milestone_percent(float $baseline, float $target, ?float $current): float
    {
        if ($current === null) {
            return 0.0;
        }

        $way = $target - $baseline;

        if (abs($way) < 1e-9) {
            return ($target >= $baseline ? $current >= $target : $current <= $target) ? 100.0 : 0.0;
        }

        return round(max(0.0, min(100.0, ($current - $baseline) / $way * 100)), 2);
    }

    /** Whether one day's figure clears a daily target: at least, or at most. */
    function goal_day_meets(float $value, float $target, string $direction): bool
    {
        return $direction === 'decrease' ? $value <= $target + 1e-9 : $value >= $target - 1e-9;
    }

    /** A share as a percentage, bounded and never divided by zero. */
    function goal_share_percent(float $part, float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, $part / $whole * 100)), 2);
    }
}

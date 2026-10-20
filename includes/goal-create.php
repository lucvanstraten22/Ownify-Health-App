<?php
/**
 * Making a goal from what somebody asked for — the wizard on the website and
 * in the app (api/goals/create.php), the assistant's proposal
 * (includes/ai/tools.php) and the first-run suggestion (includes/setup.php)
 * alike: one set of rules, so nothing can make a goal the wizard would refuse.
 *
 * The fields (docs/GOALS.md):
 *
 *   name            1–120 characters
 *   measurement     a code from config/goals.php `measurements`, or 'custom'
 *   category        an Eigen gewoonte's category; for a measurement, its own
 *   type            milestone | streak | accumulate — one the measurement allows
 *   variant         a streak's: daily | period
 *   unit            one of the measurement's unit codes; an Eigen gewoonte may
 *                   also say 'custom' and give `custom_unit` (its own word)
 *   target_value    the figure, in that unit, as the person writes it
 *                   ("72,5", "1.234"): to reach, each day, or in total
 *   direction       increase | decrease, where the measurement allows both
 *   frequency       per-period streak: how many times
 *   period          per-period streak: week | month | quarter | year
 *   start_date      Y-m-d, today or earlier (up to a year)
 *   end_date        Y-m-d, after the start, not before today — or
 *   duration        one of the looptijden (`durations`), counted from the start
 *   baseline_value  Mijlpaal: the starting value, in the unit — only asked
 *                   when there is no measurement to start from
 *   create_token    the wizard's one-time key: sent twice, one goal
 *
 * Checked here: that the goal can be measured the way its type measures,
 * and that it can be reached — a Mijlpaal whose target is already behind its
 * baseline, or a period too short to ask anything, is refused rather than
 * left to sit at 100% or 0% forever. The board's limits are includes/goals.php's.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)

require_once __DIR__ . '/goals.php';
require_once __DIR__ . '/goal-catalogue.php';
require_once __DIR__ . '/goal-progress.php';

if (!function_exists('goal_create_from_input')) {

    /**
     * Checks $in and, unless $dryRun, makes the goal.
     *
     * @return array{ok: bool, error: ?string, field: ?string, status: int, goal_id: ?int, goal: ?array}
     *         `goal` is the row that would be stored — the same with or without $dryRun.
     */
    function goal_create_from_input(int $userId, array $in, bool $dryRun = false, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? goal_today();

        $fail = static fn (string $error, ?string $field = null, int $status = 422): array =>
            ['ok' => false, 'error' => $error, 'field' => $field, 'status' => $status, 'goal_id' => null, 'goal' => null];

        $text = static fn (string $key): string => is_scalar($in[$key] ?? null) ? trim((string) $in[$key]) : '';

        /* "72,5", "72.5", "1.234,5" or "1,234.5": as the person writes it.
           null: not given; NAN: given, and not a number. */
        $number = static function (string $key) use ($in): ?float {
            $raw = $in[$key] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                return null;
            }
            if (!is_scalar($raw)) {
                return NAN;
            }
            return goal_parse_number((string) $raw) ?? NAN;
        };

        /* ---------------------------------------------------------- one-time key */
        $token = $text('create_token');
        if ($token !== '' && !preg_match('/^[A-Za-z0-9-]{8,36}$/', $token)) {
            return $fail(t('Er ging iets mis. Probeer het opnieuw.'));
        }

        /* The same wizard sending the same goal again — a double tap, a
           retry after a dropped connection — gets the goal it already made. */
        if ($token !== '' && !$dryRun && ($made = goal_by_token($userId, $token)) !== null) {
            return ['ok' => true, 'error' => null, 'field' => null, 'status' => 200, 'goal_id' => $made, 'goal' => goal_get($userId, $made)];
        }

        /* ------------------------------------------------------------- name */
        $name = $text('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $fail(t('Geef je doel een naam van 2 tot 120 tekens.'), 'name');
        }

        if (!goal_has_room($userId)) {
            return $fail(tf('Je hebt al %d actieve doelen. Rond er een af of verwijder er een.', GOAL_MAX_ACTIVE), null, 409);
        }

        /* ------------------------------------------------------ measurement */
        $code = $text('measurement');
        $measurement = goal_measurement($code === '' ? '__none__' : $code);

        if ($measurement === null) {
            return $fail(t('Kies wat je wilt bijhouden.'), 'measurement');
        }

        $custom   = !empty($measurement['custom']);
        $category = $custom ? $text('category') : (string) $measurement['category'];

        if (goal_category($category) === null) {
            return $fail(t('Kies de categorie van je gewoonte.'), 'category');
        }

        /* ------------------------------------------------------------- type */
        $type    = $text('type');
        $variant = $type === 'streak' ? $text('variant') : null;

        if (!in_array($type, ['milestone', 'streak', 'accumulate'], true)) {
            return $fail(t('Kies een soort doel: Mijlpaal, Streak of Optellen.'), 'type');
        }

        if (!goal_type_allowed($measurement, $type, $variant === '' ? null : $variant)) {
            return $fail($type === 'streak' && $variant !== '' && $variant !== null
                ? t('Deze meting kun je niet zo bijhouden. Kies een andere vorm.')
                : tf('%s kun je niet zo meten. Kies een ander soort doel.', (string) $measurement['label']), 'type');
        }

        /* ------------------------------------------------------------- unit */
        $unitCode   = $text('unit');
        $customUnit = null;

        if ($variant === 'period') {
            /* A per-period streak counts times — trainingen, or keer. */
            $unitCode = ($measurement['unit_code'] ?? null) === 'sessions' ? 'sessions' : 'times';
            $unit     = goal_unit($unitCode);
        } elseif ($custom && $unitCode === 'custom') {
            $customUnit = $text('custom_unit');
            if ($customUnit === '' || mb_strlen($customUnit) > 20) {
                return $fail(t('Geef je eenheid een naam van maximaal 20 tekens.'), 'custom_unit');
            }
            $unitCode = null;
            $unit     = goal_unit_custom($customUnit);
        } else {
            if ($unitCode === '') {
                $unitCode = goal_default_unit($measurement);
            }
            if (!in_array($unitCode, $measurement['unit_codes'] ?? [], true) || ($unit = goal_unit($unitCode)) === null) {
                return $fail(t('Kies een eenheid uit de lijst.'), 'unit');
            }
        }

        /* --------------------------------------------------------- direction */
        $direction = $text('direction');
        $allowed   = (string) ($measurement['direction'] ?? 'increase');

        if ($type === 'accumulate' || $variant === 'period') {
            $direction = 'increase';
        } elseif ($allowed !== 'both') {
            $direction = $allowed;
        } elseif ($direction === '') {
            $direction = (string) ($measurement['default_direction'] ?? 'increase');
        } elseif (!in_array($direction, ['increase', 'decrease'], true)) {
            return $fail(t('Kies of hoger of lager beter is.'), 'direction');
        }

        /* ----------------------------------------------------------- numbers */
        $target    = null;
        $frequency = null;
        $period    = null;

        if ($variant === 'period') {
            $frequency = $number('frequency');
            $period    = $text('period');

            if (!isset(goal_catalogue()['periods'][$period])) {
                return $fail(t('Kies per week, maand, kwartaal of jaar.'), 'period');
            }

            /* Kept by hand, a day is ticked off once: no more times than the
               period has days. Workouts can be more than one a day. */
            $most = goal_is_manual_measurement($measurement)
                ? ['week' => 7, 'month' => 28, 'quarter' => 89, 'year' => 365][$period]
                : 999;

            if ($frequency === null || is_nan($frequency) || $frequency < 1 || floor($frequency) !== $frequency || $frequency > $most) {
                return $fail(tf('Vul een heel aantal keer in, van 1 tot %d.', $most), 'frequency');
            }

            $frequency = (int) $frequency;
        } else {
            $typed = $number('target_value');

            if ($typed === null || is_nan($typed) || $typed <= 0 || $typed > 1e9) {
                return $fail(t('Vul een geldige doelwaarde in.'), 'target_value');
            }

            $target = goal_to_stored($typed, $unit);

            if ($unit['code'] === 'pct' && $typed > 100) {
                return $fail(t('Een percentage kan niet hoger zijn dan 100.'), 'target_value');
            }
        }

        /* ------------------------------------------------------------- dates */
        $start = goal_parse_date($text('start_date')) ?? $today;
        $end   = goal_parse_date($text('end_date'))
            ?? ($text('duration') !== '' ? goal_duration_end($start, $text('duration')) : null);

        if ($start > $today->modify('+1 day') || $start < $today->modify('-365 day')) {
            return $fail(t('Kies een startdatum van vandaag of eerder, tot een jaar terug.'), 'start_date');
        }

        if ($end === null) {
            return $fail(t('Kies een einddatum.'), 'end_date');
        }

        if ($end <= $start) {
            return $fail(t('De einddatum moet na de startdatum liggen.'), 'end_date');
        }

        if ($end < $today) {
            return $fail(t('De einddatum ligt al achter je. Kies een datum vanaf vandaag.'), 'end_date');
        }

        if ($end > $start->modify('+3 year')) {
            return $fail(t('Een doel loopt hoogstens drie jaar.'), 'end_date');
        }

        if ($variant === 'period') {
            $asks = array_sum(array_column(goal_periods($start, $end, $period, $frequency), 'required'));
            if ($asks < 1) {
                return $fail(t('Deze periode is te kort voor dit doel. Kies een latere einddatum of een kortere periode.'), 'end_date');
            }
        }

        /* --------------------------------------------------------- tracking */
        $tracking = !$custom && goal_user_has_data($userId, $measurement, $today) ? 'auto' : 'manual';

        /* ---------------------------------------------------------- baseline */
        $baseline = null;
        $baselineOn = null;
        $baselineSource = null;

        if ($type === 'milestone') {
            $measured = $tracking === 'auto' ? goal_baseline_for($userId, $measurement, $start, $today) : null;

            if ($measured !== null) {
                $baseline       = $measured['value'];
                $baselineOn     = $measured['on'];
                $baselineSource = $measured['source'];
            } else {
                $typed = $number('baseline_value');
                if ($typed === null || is_nan($typed) || $typed < 0 || $typed > 1e9) {
                    return $fail(t('Vul je startwaarde in.'), 'baseline_value');
                }
                $baseline       = goal_to_stored($typed, $unit);
                $baselineSource = 'manual';
            }

            /* The target has to lie ahead of the start, in the direction that
               is better — or the goal would be done, or undoable, at once. */
            $ahead = $direction === 'decrease' ? $target < $baseline - 1e-9 : $target > $baseline + 1e-9;
            if (!$ahead) {
                return $fail(tf(
                    $direction === 'decrease'
                        ? 'Je doelwaarde moet lager zijn dan je startwaarde (%s).'
                        : 'Je doelwaarde moet hoger zijn dan je startwaarde (%s).',
                    goal_value_text($baseline, $unit)
                ), 'target_value');
            }
        }

        $goal = [
            'name'             => $name,
            'category_code'    => $category,
            'measurement_code' => $custom ? null : (string) $measurement['code'],
            'goal_type'        => $type,
            'streak_variant'   => $variant,
            'tracking'         => $tracking,
            'unit_code'        => $unitCode,
            'custom_unit'      => $customUnit,
            'direction'        => $direction,
            'target_value'     => $target,
            'frequency'        => $frequency,
            'period_unit'      => $period,
            'baseline_value'   => $baseline,
            'baseline_on'      => $baselineOn,
            'baseline_source'  => $baselineSource,
            'start_date'       => $start->format('Y-m-d'),
            'end_date'         => $end->format('Y-m-d'),
            'create_token'     => $token === '' ? null : $token,
        ];

        if ($dryRun) {
            return ['ok' => true, 'error' => null, 'field' => null, 'status' => 200, 'goal_id' => null, 'goal' => $goal];
        }

        $goalId = goal_create($userId, $goal);

        if ($goalId === null) {
            /* Two taps at once: the other one made it. */
            if ($token !== '' && ($made = goal_by_token($userId, $token)) !== null) {
                return ['ok' => true, 'error' => null, 'field' => null, 'status' => 200, 'goal_id' => $made, 'goal' => goal_get($userId, $made)];
            }
            return $fail(t('Dit doel kon niet worden opgeslagen.'), null, 500);
        }

        /* Worked out once now, so a goal made against data that already
           exists opens showing where it stands. */
        goal_refresh($userId, $goalId, $today);

        return ['ok' => true, 'error' => null, 'field' => null, 'status' => 200, 'goal_id' => $goalId, 'goal' => $goal];
    }

    /** Whether a measurement is only ever kept by hand (Meditatie, an Eigen gewoonte). */
    function goal_is_manual_measurement(array $measurement): bool
    {
        return !goal_measurement_auto($measurement);
    }

    /** A Y-m-d date, or null for anything else. */
    function goal_parse_date(string $text): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? $date : null;
    }

    /**
     * Pause, resume or re-prioritise one of the person's goals —
     * api/goals/update.php and the assistant's confirmed proposal alike.
     * Finishing is never asked for: a goal finishes by its own rules.
     *
     * @return ?bool null for an action that does not exist, false when it could not be saved
     */
    function goal_apply_action(int $userId, int $goalId, string $action): ?bool
    {
        return match ($action) {
            'pause'     => goal_set_status($userId, $goalId, 'paused'),
            'resume'    => goal_set_status($userId, $goalId, 'active'),
            'primary'   => goal_set_primary($userId, $goalId),
            'secondary' => goal_set_secondary($userId, $goalId),
            default     => null,
        };
    }
}

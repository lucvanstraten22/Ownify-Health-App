<?php
/**
 * Making a goal from what somebody asked for — the wizard's form
 * (api/goals/create.php) and the assistant's proposal (includes/ai/tools.php)
 * alike, so there is one set of rules and the assistant cannot make a goal
 * the wizard would refuse.
 *
 * The board's limits — GOAL_MAX_ACTIVE active, one of them primary — are enforced in
 * includes/goals.php rather than here, so an import or a second endpoint
 * cannot get around them.
 *
 * What is checked here is that the goal can actually be measured the way its
 * type measures things, because a goal that cannot be is one that sits at 0%
 * forever or completes the moment it is made:
 *
 *   Mijlpaal   a target, and which way is better. Without the direction, a
 *              goal to get down to 80 kg from 90 reads 90 as past 80 and
 *              finishes on creation.
 *   Streak     a whole number of days that fits in the period, and — read
 *              from health data — what makes a day count.
 *   Optellen   a total, from a source that can be added up; or a number of
 *              days, with what makes a day count.
 */

declare(strict_types=1);

require_once __DIR__ . '/goals.php';
require_once __DIR__ . '/goal-progress.php';

if (!function_exists('goal_create_from_input')) {

    /**
     * Checks $in (the wizard's fields: name, category, type, duration,
     * source_kind, source_key, measure, target_value, daily_target,
     * direction, target_unit, priority) and, unless $dryRun, makes the goal.
     *
     * @return array{ok: bool, error: ?string, status: int, goal_id: ?int, goal: ?array}
     *         `goal` is what would be stored — the same with or without $dryRun.
     */
    function goal_create_from_input(int $userId, array $in, bool $dryRun = false): array
    {
        $fail = static fn (string $error, int $status = 422): array =>
            ['ok' => false, 'error' => $error, 'status' => $status, 'goal_id' => null, 'goal' => null];

        $name = trim((string) ($in['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            return $fail('Geef je doel een naam van maximaal 120 tekens.');
        }

        if (!goal_has_room($userId)) {
            return $fail(sprintf('Je hebt al %d actieve doelen. Behaal er een of verwijder er een.', GOAL_MAX_ACTIVE), 409);
        }

        $config   = require dirname(__DIR__) . '/config/goals.php';
        $category = (string) ($in['category'] ?? 'other');
        $type     = (string) ($in['type'] ?? '');
        $duration = (string) ($in['duration'] ?? '');

        if (!isset($config['categories'][$category])) {
            return $fail('Onbekende categorie.');
        }

        if (!isset($config['types'][$type])) {
            return $fail('Kies of dit een mijlpaal, een streak of een optelling is.');
        }

        /* Duration is a named period, so the end date is derived here rather
           than accepted from the browser: a client cannot set a goal to end
           in 1900. */
        $start = new DateTimeImmutable('today');
        $end   = null;

        if (isset($config['durations'][$duration])) {
            $end = $start->modify('+' . (int) $config['durations'][$duration]['days'] . ' day');
        }

        /* The number of days the goal runs, counting both ends, as every day
           count in includes/goal-progress.php does. */
        $periodDays = $end === null ? null : (int) $start->diff($end)->days + 1;

        /* ------------------------------------------------------------ source */

        /* How progress is tracked. The person chooses this; it is never
           inferred from the category, because a weight goal read from a scale
           and a weight goal somebody keeps on paper are the same category and
           different things. */
        $sourceKind = (string) ($in['source_kind'] ?? '');
        $sourceKey  = trim((string) ($in['source_key'] ?? ''));

        if ($sourceKind === '') {
            return $fail('Kies waar je voortgang vandaan komt, of kies "Geen data mogelijk".');
        }

        $source = $sourceKind === 'manual' ? null : goal_source_find($sourceKind, $sourceKey);

        if ($sourceKind !== 'manual' && $source === null) {
            return $fail('Deze gegevensbron bestaat niet.');
        }

        $isAuto = $source !== null;

        /* Only what can be added up can be added up. Steps, distance and
           workouts accumulate; a weight or a resting heart rate is a standing
           figure, and "adding up" a year of weigh-ins means nothing. */
        $summable = $isAuto && ($source['kind'] === 'workout' || $source['daily']);

        if ($type === 'streak' && $isAuto && $source['kind'] === 'measurement') {
            return $fail('Een streak telt dagen. Kies een bron die elke dag iets meet, of houd hem zelf bij.');
        }

        if ($type === 'accumulate' && $isAuto && !$summable) {
            return $fail('Deze bron kun je niet optellen. Kies een bron die zich over de dag opbouwt, zoals stappen of trainingen.');
        }

        /* ----------------------------------------------------------- numbers */

        $number = static function (string $field) use ($in): ?float {
            $raw = $in[$field] ?? null;

            if ($raw === null) {
                return null;
            }

            if (!is_scalar($raw)) {
                return NAN;
            }

            if (trim((string) $raw) === '') {
                return null;
            }

            $raw = str_replace(',', '.', trim((string) $raw));

            return is_numeric($raw) ? (float) $raw : NAN;
        };

        /* Optellen measures either an amount or a number of days. A Streak is
           always days; a Mijlpaal never is. */
        $countsDays = $type === 'streak'
            || ($type === 'accumulate' && ($in['measure'] ?? 'amount') === 'days');

        $targetValue = $number('target_value');

        if ($targetValue === null || is_nan($targetValue) || $targetValue <= 0 || $targetValue > 1e9) {
            return $fail($countsDays ? 'Vul in hoeveel dagen.' : 'Vul een geldige doelwaarde in.');
        }

        if ($countsDays) {
            if (floor($targetValue) !== $targetValue || $targetValue > 3660) {
                return $fail('Vul een heel aantal dagen in.');
            }

            /* Thirty days in a row cannot happen in a week. Refused now rather
               than left to sit at 23% until it quietly runs out. */
            if ($periodDays !== null && $targetValue > $periodDays) {
                return $fail(sprintf(
                    '%s passen niet in deze periode van %s. Kies een langere periode of minder dagen.',
                    goal_days_text($targetValue),
                    goal_days_text($periodDays)
                ));
            }
        }

        /* What makes one day count, for a goal read from health data that
           counts days. Asked in the source's own unit — hours of sleep, not
           minutes. */
        $dailyTarget = $number('daily_target');

        if ($dailyTarget !== null && (is_nan($dailyTarget) || $dailyTarget <= 0 || $dailyTarget > 1e9)) {
            return $fail('Vul een geldig dagdoel in.');
        }

        if ($countsDays && $isAuto && $dailyTarget === null) {
            return $fail('Vul in wanneer een dag telt als gelukt.');
        }

        if ($dailyTarget !== null && !($countsDays && $isAuto)) {
            return $fail('Voor dit doel kun je geen dagdoel instellen.');
        }

        /* Which way is better — asked outright rather than assumed, because
           80 kg is a goal to reach from below and from above alike. On a goal
           that counts days from health data it says whether the day's figure
           is a floor ("at least 10.000 steps") or a ceiling ("at most 2.000
           kcal"). */
        $direction = (string) ($in['direction'] ?? '');

        if ($type === 'milestone' || ($countsDays && $isAuto)) {
            if (!in_array($direction, ['increase', 'decrease'], true)) {
                return $fail($type === 'milestone'
                    ? 'Kies of een hoger of een lager resultaat beter is.'
                    : 'Kies of een dag minstens of hoogstens dit moet halen.');
            }
        } else {
            $direction = 'increase';
        }

        /* -------------------------------------------------------------- unit */

        /* A figure read from health data is typed in the unit it is shown in
           and stored in the unit it is kept in. They differ only for sleep,
           which is kept in minutes: "8 uur" is stored as 480, so that it is
           compared with the 480 minutes a night actually records rather than
           with 8. */
        if ($countsDays) {
            $targetUnit = 'dagen';
        } elseif ($isAuto) {
            $unit        = goal_source_unit($source['kind'], $source['key']);
            $targetValue = $targetValue / $unit['scale'];
            $targetUnit  = $unit['scale'] === 1.0 ? $unit['word'] : $source['unit'];
        } else {
            $targetUnit = trim((string) ($in['target_unit'] ?? ''));

            if (mb_strlen($targetUnit) > 20) {
                return $fail('Die eenheid is te lang.');
            }

            if (goal_unit_is_days($targetUnit)) {
                /* "dagen" is a count of days whatever it was typed as, and a
                   Mijlpaal of "30 dagen" is not one result: it is Optellen. */
                if ($type === 'milestone') {
                    return $fail('Tel je dagen? Kies dan Optellen of Streak.');
                }
                $targetUnit = 'dagen';
            }
        }

        if ($dailyTarget !== null) {
            $dailyTarget = $dailyTarget / goal_source_unit($source['kind'], $source['key'])['scale'];
        }

        $goal = [
            'name'         => $name,
            'category'     => $category,
            'kind'         => $type,
            'target_value' => $targetValue,
            'target_unit'  => $targetUnit === '' ? null : $targetUnit,
            'source_kind'  => $isAuto ? $source['kind'] : 'manual',
            'source_key'   => $isAuto ? $source['key'] : null,
            'daily_target' => $dailyTarget,
            'direction'    => $direction,
            'start_date'   => $start->format('Y-m-d'),
            'end_date'     => $end?->format('Y-m-d'),
            'priority'     => ($in['priority'] ?? 'secondary') === 'primary' ? 'primary' : 'secondary',
            'status'       => 'active',
        ];

        if ($dryRun) {
            return ['ok' => true, 'error' => null, 'status' => 200, 'goal_id' => null, 'goal' => $goal];
        }

        $goalId = goal_create($userId, $goal);

        if ($goalId === null) {
            return $fail('Dit doel kon niet worden opgeslagen.', 500);
        }

        /* Compute it once now, so a goal made against data that already
           exists opens showing where it stands rather than at zero until
           something else happens. */
        goal_refresh($userId, $goalId);

        return ['ok' => true, 'error' => null, 'status' => 200, 'goal_id' => $goalId, 'goal' => $goal];
    }

    /**
     * Pause, resume, complete or re-prioritise one of the person's goals —
     * api/goals/update.php and the assistant's confirmed proposal alike.
     *
     * @return ?bool null for an action that does not exist, false when it could not be saved
     */
    function goal_apply_action(int $userId, int $goalId, string $action): ?bool
    {
        return match ($action) {
            'pause'     => goal_set_status($userId, $goalId, 'paused'),
            'resume'    => goal_set_status($userId, $goalId, 'active'),
            'complete'  => goal_set_status($userId, $goalId, 'completed'),
            'primary'   => goal_set_primary($userId, $goalId),
            'secondary' => goal_set_secondary($userId, $goalId),
            default     => null,
        };
    }
}

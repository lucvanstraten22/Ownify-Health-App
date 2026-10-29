<?php
/**
 * Fills the Doelen page — and the app's, through api/app/state.php — from the
 * signed-in user's own goals (docs/GOALS.md).
 *
 * config/goals.php keeps the vocabulary; the goals are rows. Where a goal
 * stands is never worked out here: includes/goal-progress.php does that,
 * once, and this file only puts it into words — the same words for the
 * website and the app.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n.php';   // language and units (docs/I18N.md)

require_once dirname(__DIR__) . '/includes/goals.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/goal-catalogue.php';
require_once dirname(__DIR__) . '/includes/goal-progress.php';
require_once dirname(__DIR__) . '/includes/goal-suggest.php';

if (!function_exists('hydrate_goals')) {

    /**
     * @param int|null $userId the authenticated user, or null when signed out
     */
    function hydrate_goals(array $config, ?int $userId, ?DateTimeImmutable $today = null): array
    {
        $config['demo']      = false;
        $config['goals']     = [];
        $config['user_auto'] = [];

        if ($userId === null || !db_available() || !goal_model_available()) {
            /* Nobody to suggest for: Voorgesteld is the general order. */
            return goal_suggest_apply($config, ['entries' => [], 'order' => array_values(array_intersect(
                (array) goal_suggest_config()['fallback'], array_keys((array) $config['measurements'])))]);
        }

        $today = $today ?? goal_today();

        $goals = [];
        foreach (goals_for_user($userId, null) as $row) {
            $goals[] = hydrate_goal_row($userId, $row, $config, $today);
        }

        $config['goals'] = $goals;

        /* What the wizard can read automatically for this person: a goal on
           one of these is tracked from their data, any other is kept by hand. */
        $config['user_auto'] = goal_user_auto_codes($userId, $today);

        /* Voorgesteld, and every example, target and name idea in the
           wizard, for this person (includes/goal-suggest.php). */
        return goal_suggest_for($config, $userId, $today);
    }

    /** One database row in the shape the card, the detail page and the app expect. */
    function hydrate_goal_row(int $userId, array $row, array $config, DateTimeImmutable $today): array
    {
        $goalId = (int) $row['id'];

        /* Worked out, stored and — when it is time — finished, on every read:
           new steps arrive and the bar has already moved. */
        $progress = goal_refresh_row($userId, $row, $today);

        if (!goal_is_finished($row)) {
            $row = goal_get($userId, $goalId) ?? $row;
            if (goal_is_finished($row)) {
                $progress['percent'] = (float) $row['final_pct'];
            }
        }

        $finished    = goal_is_finished($row);
        $measurement = goal_measurement_of($row);
        $unit        = goal_unit_of($row);
        $words       = hydrate_goal_words($row, $progress, $measurement, $unit, $today);
        $percent     = $finished ? (float) $row['final_pct'] : (float) $progress['percent'];

        $series = goal_series($row, $progress);
        $series['percents'] = hydrate_goal_percents($userId, $goalId);

        return [
            'id'          => (string) $goalId,
            'name'        => (string) $row['name'],
            'category'    => (string) $row['category_code'],
            'measurement' => $row['measurement_code'],
            'measurement_label' => !empty($measurement['custom']) ? t('Eigen gewoonte') : (string) $measurement['label'],
            'type'        => (string) $row['goal_type'],
            'variant'     => $row['streak_variant'],
            'frequency'   => $row['frequency'] === null ? null : (int) $row['frequency'],
            'period_unit' => $row['period_unit'],
            'direction'   => (string) $row['direction'],
            'priority'    => $row['priority'] === 'primary' ? 'primary' : 'secondary',
            'status'      => (string) $row['status'],
            'outcome'     => $finished ? (string) $row['status'] : null,
            'tracking'    => goal_is_manual($row) ? 'manual' : 'auto',

            /* The percentage the card and the detail page show: floored, so
               99,6% is never "100%" before it is. Exact for the order. */
            'percent'       => (int) floor($percent + 1e-9),
            'percent_exact' => round($percent, 2),
            'final_percent' => $finished ? round((float) $row['final_pct'], 2) : null,

            'current_title' => $words['current_title'],
            'current_label' => $words['current'],
            'target_label'  => $words['target'],
            'extra_facts'   => $words['facts'],
            'note'          => $words['note'],

            'start_date'   => (string) $row['start_date'],
            'end_date'     => (string) $row['end_date'],
            'created_at'   => (string) $row['created_at'],
            'completed_at' => $finished ? (string) $row['completed_at'] : null,

            /* A daily streak's days, in calendar form. */
            'counts_days' => $progress['variant'] === 'daily',
            'days'        => $progress['variant'] === 'daily' ? goal_calendar($row, $progress, $today) : [],
            'days_met'    => $progress['variant'] === 'daily' ? $progress['met'] : 0,
            'days_total'  => $progress['variant'] === 'daily' ? $progress['counted'] : 0,
            'days_chip'   => $words['days_chip'],
            'days_note'   => $words['days_note'],

            /* A per-period streak's weeks, months… */
            'periods'      => $words['periods'],
            'periods_chip' => $words['periods_chip'],

            /* Kept by hand: how, on the goal's own page. */
            'is_manual' => goal_is_manual($row),
            'entry'     => $finished ? null : $words['entry'],
            'unit'      => ['code' => $unit['code'], 'label' => $unit['label']],

            'series'       => $series,
            'history_step' => 'day',
        ];
    }

    /**
     * Every sentence about a goal that depends on its type and on how it is
     * kept — so a Mijlpaal, a daily Streak, a Streak per week and an Optellen
     * read as the four different things they are.
     */
    function hydrate_goal_words(array $row, array $progress, array $measurement, array $unit, DateTimeImmutable $today): array
    {
        $type     = (string) $row['goal_type'];
        $variant  = $progress['variant'];
        $manual   = goal_is_manual($row);
        $finished = goal_is_finished($row);
        $target   = $row['target_value'] === null ? null : (float) $row['target_value'];
        $label    = mb_strtolower(!empty($measurement['custom']) ? (string) $row['name'] : (string) $measurement['label']);

        $out = [
            'current_title' => t('Nu'), 'current' => null, 'target' => null, 'facts' => [], 'note' => null,
            'days_chip' => null, 'days_note' => null, 'periods' => [], 'periods_chip' => null, 'entry' => null,
        ];

        $todayKey = $today->format('Y-m-d');
        $tickedToday = ($progress['values'][$todayKey] ?? null) !== null;

        switch (true) {
            case $type === 'milestone':
                $baseline = (float) $row['baseline_value'];
                $out['current_title'] = t('Nu');
                $out['current'] = $progress['current'] === null ? null : goal_value_text($progress['current'], $unit);
                $out['target']  = goal_value_text((float) $target, $unit);
                $out['facts'][] = [
                    'label' => t('Startwaarde'),
                    'value' => goal_value_text($baseline, $unit)
                        . (!empty($row['baseline_on']) ? ' · ' . i18n_date((string) $row['baseline_on'], 'day_mon') : ''),
                ];
                if ($progress['current_on'] !== null) {
                    $out['facts'][] = ['label' => t('Laatste meting'), 'value' => i18n_date($progress['current_on'], 'day_mon')];
                }
                $out['note'] = tf(
                    'Je voortgang volgt je laatste meting, van je startwaarde (%1$s) naar %2$s. Gaat je waarde de andere kant op, dan zakt je voortgang mee.',
                    goal_value_text($baseline, $unit),
                    goal_value_text((float) $target, $unit)
                );
                if ($manual) {
                    $out['entry'] = [
                        'kind'        => 'value',
                        'label'       => t('Nieuwe meting'),
                        'button'      => t('Opslaan'),
                        'placeholder' => tf('In %s', $unit['label']),
                        'unit'        => $unit['label'],
                        'note'        => t('Je laatste meting telt.'),
                    ];
                }
                break;

            case $type === 'accumulate':
                $out['current_title'] = t('Totaal');
                $out['current'] = goal_value_text((float) ($progress['total'] ?? 0), $unit);
                $out['target']  = goal_value_text((float) $target, $unit);
                $out['note']    = t('Alles binnen je periode telt op, vanaf nul op je startdatum.');
                if ($manual) {
                    $out['entry'] = [
                        'kind'        => 'add',
                        'label'       => t('Hoeveel komt erbij?'),
                        'button'      => t('Toevoegen'),
                        'placeholder' => tf('In %s', $unit['label']),
                        'unit'        => $unit['label'],
                        'note'        => t('Elke invoer telt op bij je totaal.'),
                    ];
                }
                break;

            case $variant === 'period':
                $periods = goal_catalogue()['periods'];
                $per     = (string) $row['period_unit'];
                $count   = (string) ($measurement['count'] ?? t('keer'));
                $out['current_title'] = t('Gehaald');
                $out['current'] = tf('%1$d van %2$s', $progress['met'], hydrate_period_count($per, $progress['counted']));
                $out['target']  = tf('%1$d %2$s per %3$s', (int) $row['frequency'], $count, (string) $periods[$per]['label']);
                $out['periods_chip'] = tf('%1$d van %2$d', $progress['met'], $progress['counted']);
                $out['periods'] = array_map(static fn (array $p): array => [
                    'label'    => hydrate_period_name($per, $p['index']),
                    'dates'    => hydrate_period_dates($p['start'], $p['end']),
                    'state'    => $p['state'],
                    'achieved' => $p['achieved'],
                    'required' => $p['required'],
                    'value'    => $p['state'] === 'skipped' ? t('telt niet mee') : tf('%1$d van %2$d', $p['achieved'], $p['required']),
                    'partial'  => $p['partial'],
                    'current'  => $p['start'] <= $progress['until'] && $p['end'] >= $progress['until'] && !goal_is_finished($row),
                ], $progress['periods']);
                $out['note'] = tf(
                    'Elke %1$s telt op zichzelf, gerekend vanaf je startdatum: haal je %2$d keer, dan is die %1$s gelukt. Je eindstand is het deel van je perioden dat gelukt is.',
                    (string) $periods[$per]['label'],
                    (int) $row['frequency']
                );
                if ($manual) {
                    $out['entry'] = [
                        'kind'   => 'tick',
                        'label'  => t('Vandaag gedaan'),
                        'undo'   => t('Vandaag ongedaan maken'),
                        'done'   => $tickedToday,
                        'note'   => t('Eén keer per dag. Elke dag die je afvinkt telt mee voor deze periode.'),
                    ];
                }
                break;

            default:   // a daily streak
                $dir = (string) $row['direction'];
                $amount = goal_value_text((float) $target, $unit);
                $out['current_title'] = t('Gehaald');
                $out['current'] = tf('%1$d van %2$s', $progress['met'], goal_days_text($progress['counted']));
                $out['target']  = $manual
                    ? tf('Elke dag %s', $amount)
                    : tf($dir === 'decrease' ? 'Elke dag hoogstens %s' : 'Elke dag minstens %s', $amount);
                $out['days_chip'] = tf('%1$d van %2$d', $progress['met'], $progress['counted']);
                $out['days_note'] = $manual
                    ? t('Een dag die je niet afvinkt, telt niet als gehaald. Vandaag telt zodra je hem afvinkt.')
                    : t('Een dag zonder gegevens telt niet als gehaald. Vandaag telt zodra je je doel haalt.');
                $out['note'] = $manual
                    ? t('Vink elke dag af die gelukt is. Een gemiste dag is gewoon een gemiste dag: je doel loopt door. Je eindstand is het deel van alle dagen dat gelukt is.')
                    : tf('Elke dag met %s telt. Een gemiste dag is gewoon een gemiste dag: je doel loopt door. Je eindstand is het deel van alle dagen dat gelukt is.',
                        tf($dir === 'decrease' ? 'hoogstens %s' : 'minstens %s', $amount));
                if ($manual) {
                    $out['entry'] = [
                        'kind'  => 'tick',
                        'label' => t('Vandaag gelukt'),
                        'undo'  => t('Vandaag ongedaan maken'),
                        'done'  => $tickedToday,
                        'note'  => tf('Vink af wanneer je vandaag %s hebt gehaald.', $amount),
                    ];
                }
                break;
        }

        if ($finished) {
            $out['note'] = $row['status'] === 'completed'
                ? t('Behaald. Je eindstand is bewaard en verandert niet meer.')
                : t('De periode is voorbij. Je eindstand is bewaard en verandert niet meer.');
        }

        return $out;
    }

    /** "12 weken", "1 maand", "3 kwartalen". */
    function hydrate_period_count(string $period, int $n): string
    {
        return match ($period) {
            'month'   => tn($n, '%d maand', '%d maanden'),
            'quarter' => tn($n, '%d kwartaal', '%d kwartalen'),
            'year'    => tn($n, '%d jaar', '%d jaar'),
            default   => tn($n, '%d week', '%d weken'),
        };
    }

    /** "Week 3", "Maand 2": the goal's own periods, counted from its start. */
    function hydrate_period_name(string $period, int $index): string
    {
        return match ($period) {
            'month'   => tf('Maand %d', $index + 1),
            'quarter' => tf('Kwartaal %d', $index + 1),
            'year'    => tf('Jaar %d', $index + 1),
            default   => tf('Week %d', $index + 1),
        };
    }

    /** "6 – 12 okt". */
    function hydrate_period_dates(string $from, string $to): string
    {
        return $from === $to
            ? i18n_date($from, 'day_mon')
            : i18n_date($from, 'day_mon') . ' – ' . i18n_date($to, 'day_mon');
    }

    /**
     * The percentage each day of a goal was stored with, by date — what the
     * Verloop's points say about how far the goal was that day.
     *
     * @return array<string, int>
     */
    function hydrate_goal_percents(int $userId, int $goalId): array
    {
        $out = [];

        foreach (db_all(
            'SELECT p.recorded_on, p.percent_complete
               FROM goal_progress p
               JOIN goals g ON g.id = p.goal_id
              WHERE p.goal_id = ? AND g.user_id = ? AND p.percent_complete IS NOT NULL',
            [$goalId, $userId]
        ) as $r) {
            $out[(string) $r['recorded_on']] = (int) floor((float) $r['percent_complete']);
        }

        return $out;
    }
}

if (!function_exists('hydrate_dashboard_goal')) {
    /**
     * The Overzicht page's goal card, filled from the user's primary goal.
     * Without one it stays the "no goal yet" design.
     */
    function hydrate_dashboard_goal(array $card, ?array $primary): array
    {
        if ($primary === null) {
            return $card;
        }

        $percent = $primary['percent'];

        $card['state']    = ($percent !== null && $percent >= 100) ? 'reached' : 'active';
        $card['name']     = $primary['name'];
        $card['progress'] = $percent;
        $card['headline'] = $primary['name'];

        foreach ($card['milestones'] as $index => $milestone) {
            $card['milestones'][$index]['reached'] = $percent !== null && $percent >= (int) ($milestone['at'] ?? 100);
        }

        /* The goal's own numbers, as its type says them (goals_expand()): "82 kg,
           doel 75 kg", "84.000 van 200.000 stappen", "5 van 30 dagen". */
        if (!empty($primary['reading'])) {
            $card['unit']    = (string) $primary['reading'];
            $card['reading'] = $card['unit'];
        }

        return $card;
    }
}

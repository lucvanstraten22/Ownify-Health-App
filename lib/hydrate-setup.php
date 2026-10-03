<?php
/**
 * Fills the first days for the signed-in user (includes/setup.php), for the
 * website and the app alike:
 *
 *   setup        the setup a new account starts with — its four steps, with
 *                what the account already has filled in, and a first goal
 *                when one can be worked out from the person's own data
 *   calibration  the card at the top of Overzicht in the first days, or null
 *
 * Everything is read with the engine's own functions from the same records;
 * nothing here scores.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/setup.php';
require_once dirname(__DIR__) . '/includes/goal-create.php';

if (!function_exists('hydrate_setup')) {

    /**
     * The `setup` block. Outside a pending setup it is only the flag.
     *
     * @param array $copy  config/setup.php
     * @param array $data  app_page_data() so far: settings and goals filled
     * @param array $state setup_state()
     */
    function hydrate_setup(array $copy, array $data, int $userId, array $state): array
    {
        if ($state['state'] !== 'pending') {
            return ['pending' => false];
        }

        $s     = $copy['setup'];
        $steps = $s['steps'];

        /* 1 — focus: the answer so far, if there is one. */
        $options = [];
        foreach ($steps['focus']['options'] as $key => $option) {
            $options[] = ['key' => (string) $key] + $option + ['chosen' => $state['chosen'] && $state['focus'] === $key];
        }

        /* 2 — health data: Health Connect, the one source a phone can link
           today (config/integrations.php), as Instellingen shows it. */
        $health = null;
        foreach ($data['settings']['integrations'] ?? [] as $item) {
            if (($item['provider'] ?? null) === 'google_health_connect') {
                $health = $item;
            }
        }

        $c = $steps['connect'];
        $connected = !empty($health['connected']);

        /* 3 — about you: only what Ownify uses, each with why, as
           Instellingen would edit it (the same inputs, the same endpoints). */
        $profile = $data['settings']['profile'] ?? ['raw' => []];
        $fields  = [];
        $order   = $state['focus'] === 'weight' ? ['weight', 'date_of_birth', 'height'] : ['date_of_birth', 'height', 'weight'];

        foreach ($order as $key) {
            $field = ['key' => $key, 'label' => $steps['profile']['fields'][$key]['label'], 'edit' => $key === 'date_of_birth' ? 'locked' : true]
                + ($key === 'height' ? ['unit' => 'cm'] : [])
                + ($key === 'weight' ? ['unit' => 'kg'] : []);
            $fieldState = settings_field_state($field, $profile);

            $fields[] = [
                'key'    => $key,
                'label'  => $field['label'],
                'reason' => $steps['profile']['fields'][$key]['reason'],
                'note'   => $steps['profile']['fields'][$key]['locked'] ?? null,
                /* A birth date already given is shown, not asked again. */
                'locked' => $fieldState === 'locked',
                'value'  => settings_field_value($field, $profile),
                'input'  => settings_field_input($field, $profile),
            ];
        }

        /* 4 — a first goal: the normal wizard, and a suggestion when the
           person's own data can carry one. */
        $goals = $data['goals'] ?? [];
        $names = array_map(static fn (array $g): string => (string) $g['name'], $goals['active'] ?? []);

        return [
            'pending' => true,
            'title'   => $s['title'],
            'count'   => $s['count'],
            'back'    => $s['back'],
            'next'    => $s['next'],
            'logout'  => $s['logout'],
            /* A reload in the middle starts where it makes sense: past the
               focus once it has been chosen. */
            'resume'  => $state['chosen'] ? 'connect' : 'focus',
            'steps'   => [
                [
                    'id'      => 'focus',
                    'label'   => $steps['focus']['label'],
                    'title'   => $steps['focus']['title'],
                    'lede'    => $steps['focus']['lede'],
                    'options' => $options,
                    'error'   => $steps['focus']['error'],
                ],
                [
                    'id'        => 'connect',
                    'label'     => $c['label'],
                    'title'     => $c['title'],
                    'lede'      => $c['lede'],
                    'source'    => [
                        'provider'  => 'google_health_connect',
                        'label'     => $c['health_connect']['label'],
                        'note'      => $c['health_connect']['note'],
                        'icon'      => (string) ($health['icon'] ?? 'pulse'),
                        'web'       => $c['health_connect']['web'],
                        'app'       => $c['health_connect']['app'],
                        'connected' => $connected,
                        'status'    => $connected ? $c['connected'] : $c['not_connected'],
                        /* The server counts Health Connect as connected the
                           moment a phone signs in (includes/devices.php), so
                           on that phone — where it is always true — the app
                           says what its own Health Connect allows instead,
                           in these two. */
                        'connected_label'     => $c['connected'],
                        'not_connected_label' => $c['not_connected'],
                        'last_sync' => $connected && !empty($health['last_sync'])
                            ? sprintf($c['last_sync'], $health['last_sync'])
                            : null,
                    ],
                    'connected' => $connected,
                    'manual'    => $c['manual'],
                    'later'     => $c['later'],
                    'skip'      => $c['skip'],
                ],
                [
                    'id'     => 'profile',
                    'label'  => $steps['profile']['label'],
                    'title'  => $steps['profile']['title'],
                    'lede'   => $steps['profile']['lede'],
                    'fields' => $fields,
                    'skip'   => $steps['profile']['skip'],
                    'save'   => $steps['profile']['save'],
                    'error'  => $steps['profile']['error'],
                ],
                [
                    'id'         => 'goal',
                    'label'      => $steps['goal']['label'],
                    'title'      => $steps['goal']['title'],
                    'lede'       => $steps['goal']['lede'],
                    'own'        => $steps['goal']['own'],
                    'added'      => $steps['goal']['added'],
                    'finish'     => $steps['goal']['finish'],
                    'error'      => $steps['goal']['error'],
                    'can_add'    => !empty($goals['can_add']),
                    'goals'      => $names,
                    'suggestion' => $names === [] && !empty($goals['can_add'])
                        ? hydrate_setup_suggestion($steps['goal']['suggestion'], $userId, $state['focus'])
                        : null,
                ],
            ],
        ];
    }

    /**
     * A first goal from the last two weeks of the person's own data, checked
     * with the wizard's own rules exactly as it would be made — or null.
     */
    function hydrate_setup_suggestion(array $copy, int $userId, string $focus): ?array
    {
        if (!db_available() || !function_exists('goal_sources_available') || !goal_sources_available()) {
            return null;
        }

        $now     = new DateTimeImmutable('now');
        $records = health_score_data($userId, $now->modify('-14 days'), $now);
        $plan    = setup_suggestion_plan($focus, $records, $copy);

        if ($plan === null) {
            return null;
        }

        $check = goal_create_from_input($userId, $plan['input'], true);

        if (!$check['ok']) {
            return null;
        }

        require_once dirname(__DIR__) . '/includes/ai/tools.php';

        return [
            'eyebrow' => $copy['eyebrow'],
            'basis'   => $plan['basis'],
            'name'    => $plan['name'],
            /* The goal in the app's words, as the assistant describes one. */
            'summary' => ai_goal_summary($check['goal'], $plan['input']),
            'input'   => $plan['input'],
            'add'     => $copy['add'],
            'decline' => $copy['decline'],
        ];
    }

    /**
     * The `calibration` block — the Overzicht card of the first days — or
     * null outside them.
     *
     * @param array $copy  config/setup.php
     * @param array $data  app_page_data() so far: health, scores and compass filled
     * @param array $state setup_state()
     */
    function hydrate_calibration(array $copy, array $data, int $userId, array $state): ?array
    {
        if ($state['state'] !== 'done' || $state['done_at'] === null || !db_available()) {
            return null;
        }

        $c     = $copy['calibration'];
        $start = (new DateTimeImmutable($state['done_at']))->setTime(0, 0);
        $today = new DateTimeImmutable('today');
        $day   = (int) $start->diff($today)->days + 1;

        if ($start > $today || $day > (int) $c['window']) {
            return null;
        }

        /* The records once, from the window of the setup's own day to now:
           each day since the setup scored as it stood at its end, to find
           the day the first score appeared, and the records the engine
           reads now, for what the days so far hold. */
        $now  = new DateTimeImmutable('now');
        $read = health_score_data($userId, health_score_window_start($start->setTime(23, 59, 59)), $now);

        $firstDay = calibration_first_day($read, $start, $day, $now);
        $results  = health_score_at($read, $now);
        $records  = calibration_window_records($read, $now);

        /* The categories as the legend names them, and the Scorekompas's
           own rows for the first score's components. */
        $areas = [];
        foreach ($data['scores']['contributors'] ?? [] as $row) {
            $id   = (string) $row['area'];
            $area = $data['health']['areas'][$id] ?? [];
            $areas[$id] = [
                'label'  => (string) $row['label'],
                'accent' => (string) ($row['accent'] ?? $id),
                'icon'   => (string) ($area['icon'] ?? ''),
            ];
        }

        $composition = [];
        foreach ($data['compass']['composition']['categories'] ?? [] as $row) {
            $composition[(string) $row['id']] = $row;
        }

        /* A source that has delivered something. Connected alone is not
           enough: a phone counts as connected the moment it signs in, before
           Health Connect has let it read anything. */
        $connected = false;
        foreach ($data['settings']['integrations'] ?? [] as $item) {
            $connected = $connected || (!empty($item['connected']) && !empty($item['last_sync']));
        }

        return calibration_build([
            'day'         => $day,
            'focus'       => $state['focus'],
            'min_days'    => (int) health_scoring_config()['min_days'],
            'today'       => $today->format('Y-m-d'),
            'first_day'   => $firstDay,
            'results'     => $results,
            'records'     => $records,
            'weight'      => $data['auth']['user']['weight'] ?? null,
            'connected'   => $connected,
            'areas'       => $areas,
            'composition' => $composition,
        ], $c);
    }

    /**
     * Instellingen → Account's Focus row: the account's focus, and the
     * choices the editor offers. Before migration 016 the row is left out,
     * since there would be nowhere to keep an answer.
     */
    function hydrate_settings_focus(array $settings, array $copy, array $state, array $labels): array
    {
        if (!setup_stored()) {
            foreach ($settings['pages'] ?? [] as $pageId => $page) {
                foreach ($page['blocks'] ?? [] as $b => $block) {
                    if (($block['type'] ?? null) !== 'fields') {
                        continue;
                    }
                    $settings['pages'][$pageId]['blocks'][$b]['fields'] = array_values(array_filter(
                        $block['fields'] ?? [],
                        static fn (array $field): bool => ($field['key'] ?? null) !== 'focus'
                    ));
                }
            }

            return $settings;
        }

        $options = [];
        foreach ($copy['setup']['steps']['focus']['options'] as $key => $option) {
            $options[$key] = $option['label'];
        }

        $settings['profile']['focus']         = $labels[$state['focus']] ?? null;
        $settings['profile']['raw']['focus']  = $state['focus'];
        $settings['profile']['focus_options'] = $options;

        return $settings;
    }
}

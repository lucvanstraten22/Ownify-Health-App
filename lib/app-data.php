<?php
/**
 * What the signed-in app shows — one pipeline, two readers.
 *
 * index.php renders it as the website; api/app/state.php hands it to the Ownify
 * Android app as JSON. Both call app_page_data(), so the app can never show a
 * number, a label or a state the website would not: every score, point, goal
 * percentage, chart and sentence is worked out here, once, and the app only
 * lays it out.
 *
 * Rendering has side effects, on purpose and the same for both readers: a
 * goal's progress is recomputed and stored while it is read (goal_refresh_row
 * via hydrate_goals), exactly as a page load of the website does.
 */

declare(strict_types=1);

require_once __DIR__ . '/render.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/community.php';
require_once __DIR__ . '/goals.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/hydrate-health.php';
require_once __DIR__ . '/hydrate-goals.php';
require_once __DIR__ . '/hydrate-community.php';
require_once __DIR__ . '/hydrate-compass.php';

if (!function_exists('app_page_data')) {

    /**
     * Fills the dashboard config for the signed-in [$userId].
     *
     * @param array<string, mixed> $data  config/dashboard.php, with 'auth' set
     *                                    (app_auth() or app_auth_account())
     * @return array<string, mixed>
     */
    function app_page_data(array $data, int $userId): array
    {
        $config = dirname(__DIR__) . '/config';

        /* The config files describe the shape of each page — which areas
           exist, what they are called, in what unit. The values come from
           the signed-in user's own records; anything they have not recorded
           stays null and renders empty. */
        $data['health'] = hydrate_health(require $config . '/health.php', $userId);

        /* The ring is the average of whichever pillars have a score, derived
           on every render rather than stored, so it can never disagree with
           them — and the legend under it reads the same three values. */
        $data['scores']['overall']['value'] = health_overall_score(
            $data['health'],
            (int) $data['scores']['overall']['max']
        );

        $data['scores']['contributors'] = health_contributor_scores(
            $data['scores']['contributors'],
            $data['health']
        );

        /* The Scorekompas behind the ring: what the score is made of, what
           is changing, the person's own earlier scores and where the most
           room is — read from the same engine, never scored again. */
        $data['compass'] = hydrate_compass(require $config . '/compass.php', $data, $userId);

        /* Real accounts and real points, or an empty board. */
        $data['community'] = hydrate_community(require $config . '/community.php', $userId);

        /* Goals: read for this user, then expanded, split by view and ordered. */
        $data['goals'] = goals_prepare(hydrate_goals(require $config . '/goals.php', $userId));

        /* The line under each page says which of three situations the reader
           is in. An account with data gets no line at all: there is nothing
           to disclaim. */
        $data['disclaimer'] = match (true) {
            !$data['auth']['database'] => $data['disclaimers']['no_database'],
            $data['scores']['overall']['value'] === null
                && ($data['goals']['used'] ?? 0) === 0 => $data['disclaimers']['no_data'],
            default => '',
        };

        /* The Overzicht page's goal card follows whichever goal is primary. */
        $data['goal'] = hydrate_dashboard_goal($data['goal'], $data['goals']['primary'] ?? null);

        /* Ownify AI at a glance — consent, whether it can answer, today's
           limit — so the sheet opens on the right screen. The conversations
           themselves are read when it opens (api/ai/state.php). */
        require_once dirname(__DIR__) . '/includes/ai/assistant.php';
        $data['ai']['session'] = ai_summary($userId);

        /* Settings: integrations resolved, profile read off the signed-in record. */
        $data['settings'] = settings_prepare(require $config . '/settings.php', $data['auth']);

        return $data;
    }

    /**
     * app_auth(), for a request the Ownify app makes with its account token
     * instead of a browser session: the same shape, so every template helper
     * and settings_prepare() read it the same way.
     *
     * Only for a user id api_require_account_user() has just returned. There
     * is no session behind it, so no CSRF token, no Google sign-in waiting
     * for a username and no one-time message.
     *
     * @return array<string, mixed>
     */
    function app_auth_account(int $userId): array
    {
        $user = db_available() ? user_account($userId) : null;

        if ($user !== null) {
            auth_touch_last_seen($userId);
        }

        return [
            'signed_in'      => $user !== null,
            'user'           => $user,
            'database'       => db_available(),
            'csrf'           => '',
            'providers'      => [
                'email'  => auth_provider_available('email'),
                'google' => auth_provider_available('google'),
            ],
            'google_pending' => null,
            'identities'     => $user === null ? [] : auth_identities_for_user($userId),
            'flash'          => null,
        ];
    }

    /**
     * The page data as the app receives it: everything the templates read,
     * minus what only a browser session has or needs.
     *
     *   - dates as text: 'Y-m-d' for a day, 'Y-m-d H:i:s' for a moment
     *   - `today`: today_parts(), the header date the templates print
     *   - no CSRF token, no one-time message, no waiting Google sign-in
     *   - the account's own id left out; an identity is its provider and
     *     address, which the account screen shows
     *
     * @param array<string, mixed> $data  app_page_data()
     * @return array<string, mixed>
     */
    function app_state_payload(array $data): array
    {
        unset($data['auth']['csrf'], $data['auth']['flash'], $data['auth']['google_pending']);

        if (is_array($data['auth']['user'] ?? null)) {
            unset($data['auth']['user']['id']);
        }

        $data['auth']['identities'] = array_map(
            static fn (array $identity): array => [
                'provider' => $identity['provider'] ?? null,
                'email'    => $identity['email'] ?? null,
            ],
            $data['auth']['identities'] ?? []
        );

        /* The Overzicht header's date, as the server's clock has it — the
           same today the scores and goals above were worked out for. */
        $data['today'] = today_parts();

        return app_state_plain(app_state_rendered($data));
    }

    /**
     * What the templates work out while they render, worked out the same way
     * — by the same functions — so the app receives it instead of doing it:
     *
     *   health.areas.*.highlights / groups.*.metrics
     *                      each metric with its registry definition merged in
     *                      (health_metric), and each group's `locked`
     *                      (health_group_locked)
     *   health.trend.charts.{area}.{range}
     *                      the SVG geometry of every trend line, in the
     *                      templates' 300 × 120 viewBox (health_chart), with
     *                      `has_data` (health_series_has_data)
     *   goals.all.*.days.* `day` (the date's day of the month) and `title`
     *                      ("12 sep · gehaald"), as the calendar prints them
     *   goals.wizard_sources
     *                      the wizard's source list (goal_wizard_sources)
     *   settings.pages.*.blocks.*.fields.*
     *                      `state`, `value`, `blank` and — for a field that
     *                      opens the editor — `input` (settings_field_*)
     *
     * @param array<string, mixed> $data  app_page_data()
     * @return array<string, mixed>
     */
    function app_state_rendered(array $data): array
    {
        /* Health: metrics resolved against the registry, groups locked or not. */
        $registry = $data['health']['metrics'] ?? [];

        foreach ($data['health']['areas'] ?? [] as $areaId => $area) {
            $data['health']['areas'][$areaId]['highlights'] = array_map(
                static fn (array $entry): array => health_metric($entry, $registry),
                $area['highlights'] ?? []
            );

            foreach ($area['groups'] ?? [] as $index => $group) {
                $metrics = array_map(
                    static fn (array $entry): array => health_metric($entry, $registry),
                    $group['metrics'] ?? []
                );

                $data['health']['areas'][$areaId]['groups'][$index]['metrics'] = $metrics;
                $data['health']['areas'][$areaId]['groups'][$index]['locked']  = health_group_locked($metrics);
            }
        }

        /* Health: every trend line, as components/health-trend.php draws it. */
        $trend = $data['health']['trend'] ?? null;

        if (is_array($trend)) {
            $data['health']['trend']['width']  = 300.0;
            $data['health']['trend']['height'] = 120.0;

            foreach (array_keys($data['health']['areas'] ?? []) as $areaId) {
                foreach ($trend['ranges'] ?? [] as $rangeKey => $range) {
                    $values = $trend['series'][$areaId][$rangeKey]['values'] ?? [];

                    $data['health']['trend']['charts'][$areaId][$rangeKey] =
                        health_chart($values, 300.0, 120.0, 100.0, 0.0)
                        + ['has_data' => health_series_has_data($values)];
                }
            }
        }

        /* Goals: the calendar's day numbers and titles, and the wizard's sources. */
        foreach ($data['goals']['all'] ?? [] as $g => $goal) {
            foreach ($goal['days'] ?? [] as $d => $day) {
                if (($day['state'] ?? null) === 'before' || empty($day['date'])) {
                    continue;
                }

                $when = $day['date'] instanceof DateTimeInterface
                    ? $day['date']
                    : new DateTimeImmutable((string) $day['date']);

                $data['goals']['all'][$g]['days'][$d]['day']   = (int) $when->format('j');
                $data['goals']['all'][$g]['days'][$d]['title'] = goals_date_short($when) . ' · ' . match ($day['state']) {
                    'met'     => 'gehaald',
                    'missed'  => 'niet gehaald',
                    'unknown' => 'geen gegevens',
                    'pending' => 'vandaag, nog open',
                    default   => 'nog niet geweest',
                };
            }
        }

        if (isset($data['goals'])) {
            $data['goals']['wizard_sources'] = goal_wizard_sources();
        }

        /* Settings: each profile field as components/settings-field.php shows it. */
        $profile = $data['settings']['profile'] ?? [];

        foreach ($data['settings']['pages'] ?? [] as $pageId => $page) {
            foreach ($page['blocks'] ?? [] as $b => $block) {
                if (($block['type'] ?? null) !== 'fields') {
                    continue;
                }

                foreach ($block['fields'] ?? [] as $f => $field) {
                    $state = settings_field_state($field, $profile);
                    $live  = $state === true || $state === 'once';

                    $data['settings']['pages'][$pageId]['blocks'][$b]['fields'][$f] += [
                        'state' => $state,
                        'value' => settings_field_value($field, $profile),
                        'blank' => settings_field_blank($field, $state),
                        'input' => $live && ($field['opens'] ?? null) === null
                            ? settings_field_input($field, $profile)
                            : null,
                    ];
                }
            }
        }

        return $data;
    }

    /** Dates to text, recursively; everything else as it is. */
    function app_state_plain(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = app_state_plain($item);
            }
        }

        return $value;
    }
}

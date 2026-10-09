<?php
/**
 * Settings helpers — integration status, profile values and row summaries.
 *
 * The templates never decide what a value means. They are handed a row that
 * already knows its label, its value and whether that value is a fact, a
 * blank or a preference, so the same markup renders "Nederlands" and "Nog
 * niet ingesteld" without a branch.
 *
 * Nothing here queries the database. The account screen reads the record
 * app_auth() has already loaded for the signed-in user — their own record,
 * never anyone else's — and shows empty states for everything that is not
 * filled in yet.
 */

declare(strict_types=1);

if (!function_exists('settings_prepare')) {
    /**
     * Resolves the integrations, the profile and the row summaries that depend
     * on them, so the page and its detail screens agree on every number.
     */
    function settings_prepare(array $settings, array $auth): array
    {
        require_once dirname(__DIR__) . '/includes/integrations.php';
        require_once dirname(__DIR__) . '/includes/devices.php';

        /* Real connection state for whoever is asking, or all-disconnected
           when nobody is. Nothing on this screen is a stand-in any more: a
           source says connected because there is a row saying so. */
        $userId = ($auth['signed_in'] ?? false) ? (int) $auth['user']['id'] : null;
        $live   = integrations_for_user($userId);

        $connected = 0;
        foreach ($settings['integrations'] as $index => $integration) {
            $provider = $integration['provider'] ?? null;
            $state    = $provider === null ? null : ($live[$provider] ?? null);

            $integration['status']    = $state['status'] ?? 'disconnected';
            $integration['connected'] = $integration['status'] === 'connected';
            $integration['account']   = $state['account'] ?? null;
            $integration['last_sync'] = settings_sync_label($state['last_sync_at'] ?? null);
            $integration['error']     = $state['last_error'] ?? null;
            $integration['transport'] = $state['transport'] ?? 'cloud';
            /* A sync running now (a cloud source, after connecting or on
               "Nu synchroniseren"), so the row can say so. */
            $integration['syncing']   = (bool) ($state['syncing'] ?? false);

            /* Whether connecting is possible at all, and if not, why. A
               source whose data lives on a phone cannot be reached from a
               browser however it is configured; one that simply has no
               credentials yet is a different problem with a different answer,
               and the row says which. */
            $integration['available'] = $state['available'] ?? false;
            $integration['blocked']   = $state['blocked'] ?? null;

            /* The phones paired to this source, for the owner only.
               Until now a paired phone was invisible: you could pair one and
               then never see it again, never learn when it last sent anything,
               and never revoke it without disconnecting the source entirely.
               "What has access to my health data" has to be answerable, and
               the answer has to be per phone — losing one should cost you that
               phone and not every phone.

               Never a token: user_devices stores only a SHA-256 hash, and
               there is nothing to show even if this wanted to. */
            $integration['devices'] = ($userId !== null && $provider !== null && db_available())
                ? array_map(
                    static fn (array $device): array => [
                        'id'        => (int) $device['id'],
                        'label'     => $device['label'] ?: 'Naamloos apparaat',
                        'platform'  => $device['platform'],
                        'paired'    => settings_sync_label($device['created_at']),
                        'last_sync' => settings_sync_label($device['last_sync_at']),
                    ],
                    devices_for_user($userId, $provider)
                )
                : [];

            if ($integration['connected']) {
                $connected++;
            }

            $settings['integrations'][$index] = $integration;
        }

        $settings['connected_count'] = $connected;
        $settings['profile']         = settings_profile($auth);

        /* The devices row on the main page counts what the devices screen
           shows, rather than stating it a second time. */
        $settings = settings_set_row_value($settings, 'devices', settings_device_summary($connected));

        /* The synchronisation block reports the newest sync across sources. */
        $settings = settings_set_state_value(
            $settings,
            'devices',
            'Laatste sync',
            settings_last_sync_across($live)
        );

        /* The Privacy switches that save: each as this account has it. */
        require_once dirname(__DIR__) . '/includes/leaderboard.php';
        $settings = settings_set_toggle(
            $settings,
            'privacy',
            'leaderboard_avatar',
            $userId === null || !db_available() || leaderboard_avatar_shown($userId)
        );

        /* Ownify AI: the switch, and the two facts at the top of Privacy
           that are only true while it is off. */
        require_once dirname(__DIR__) . '/includes/ai/config.php';
        $aiOn     = $userId !== null && db_available() && ai_consented($userId);
        $settings = settings_set_toggle($settings, 'privacy', 'ai_consent', $aiOn);

        if ($aiOn) {
            $settings = settings_use_ai_state($settings, 'privacy', ['Gezondheidsgegevens', 'Ownify AI']);

            /* The Privacy row on the main page says the same. */
            foreach ($settings['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    if ($row['id'] === 'privacy' && isset($row['value_ai'])) {
                        $settings = settings_set_row_value($settings, 'privacy', (string) $row['value_ai']);
                    }
                }
            }
        }

        /* The account row names the person it belongs to. */
        $settings = settings_set_row_value(
            $settings,
            'account',
            $auth['signed_in'] ? (string) $auth['user']['username'] : 'Niet ingelogd'
        );

        /* Systeem, Dark or White Mode, as this browser chose it
           (lib/theme.php). The app sends no cookie, so it gets Systeem and
           shows the phone's own choice in its place. */
        require_once __DIR__ . '/theme.php';
        $settings = settings_use_theme($settings, app_theme_preference());

        return $settings;
    }
}

if (!function_exists('settings_use_theme')) {
    /** The theme screen's choice and the main page's row, both as $theme ('system', 'dark' or 'light'). */
    function settings_use_theme(array $settings, string $theme): array
    {
        foreach ($settings['pages']['theme']['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? '') !== 'choice' || ($block['name'] ?? '') !== 'theme') {
                continue;
            }

            $settings['pages']['theme']['blocks'][$b]['selected'] = $theme;

            foreach ($block['options'] as $option) {
                if ($option['key'] === $theme) {
                    $settings = settings_set_row_value($settings, 'theme', (string) $option['label']);
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_device_summary')) {
    /** How many sources are attached, in words a person would use. */
    function settings_device_summary(int $connected): string
    {
        if ($connected === 0) {
            return 'Geen verbonden';
        }

        return $connected === 1 ? '1 verbonden' : $connected . ' verbonden';
    }
}

if (!function_exists('settings_set_row_value')) {
    /** Writes a computed value onto one main-page row, wherever it sits. */
    function settings_set_row_value(array $settings, string $id, string $value): array
    {
        foreach ($settings['groups'] as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                if ($row['id'] === $id) {
                    $settings['groups'][$g]['rows'][$r]['value'] = $value;
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_profile')) {
    /**
     * The signed-in user's own profile values, keyed the way the account
     * screen's fields are. Everything missing comes back null, which the
     * field row renders as its empty state.
     */
    function settings_profile(array $auth): array
    {
        $empty = [
            'avatar' => null, 'username' => null, 'first_name' => null, 'last_name' => null,
            'date_of_birth' => null, 'gender' => null, 'age' => null,
            'height' => null, 'weight' => null, 'activity_level' => null, 'member_since' => null,

            'raw' => [
                'first_name' => null, 'last_name' => null, 'date_of_birth' => null,
                'gender' => null, 'height' => null, 'weight' => null,
            ],
        ];

        if (empty($auth['signed_in']) || !is_array($auth['user'])) {
            return $empty;
        }

        $user = $auth['user'];

        return [
            'avatar'        => $user['avatar_path'] ?? null,
            'username'      => $user['username'] ?? null,
            'first_name'    => $user['first_name'] ?? null,
            'last_name'     => $user['last_name'] ?? null,
            'date_of_birth' => settings_date($user['date_of_birth'] ?? null),
            'gender'        => settings_gender($user['gender'] ?? null),
            'age'           => isset($user['age']) && $user['age'] !== null ? $user['age'] . ' jaar' : null,
            'height'        => settings_measurement($user['height'] ?? null),
            'weight'        => settings_measurement($user['weight'] ?? null),
            'activity_level' => settings_activity_level($user['activity_level'] ?? null),
            'member_since'   => settings_member_since($user['created_at'] ?? null),

            'raw' => [
                'first_name'    => $user['first_name'] ?? null,
                'last_name'     => $user['last_name'] ?? null,
                'date_of_birth' => $user['date_of_birth'] ?? null,
                /* 'undisclosed' is the column's default, which means nobody has
                   answered yet — not that somebody chose it. */
                'gender'        => ($user['gender'] ?? 'undisclosed') === 'undisclosed'
                    ? null
                    : $user['gender'],
                'height'        => isset($user['height']['value']) ? (float) $user['height']['value'] : null,
                'weight'        => isset($user['weight']['value']) ? (float) $user['weight']['value'] : null,
            ],
        ];
    }
}

if (!function_exists('settings_measurement')) {
    /** "182 cm" from the measurement row, or null while there is none. */
    function settings_measurement(?array $measurement): ?string
    {
        if ($measurement === null || !isset($measurement['value'])) {
            return null;
        }

        $value = (float) $measurement['value'];
        $text  = rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');

        return trim($text . ' ' . (string) ($measurement['unit'] ?? ''));
    }
}

if (!function_exists('settings_date')) {
    /** A date of birth as a person writes it: 4 oktober 1998. */
    function settings_date(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            $value = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return null;
        }

        $months = [
            1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ];

        return sprintf('%d %s %d', (int) $value->format('j'), $months[(int) $value->format('n')], (int) $value->format('Y'));
    }
}

if (!function_exists('settings_gender')) {
    /**
     * The stored enum in Dutch. 'undisclosed' is the schema default, which
     * means nothing was chosen — so it reads as empty rather than as an answer.
     */
    function settings_gender(?string $gender): ?string
    {
        return match ($gender) {
            'female'     => 'Vrouw',
            'male'       => 'Man',
            'non_binary' => 'Non-binair',
            'other'      => 'Anders',
            default      => null,
        };
    }
}

if (!function_exists('settings_field_value')) {
    /** What a profile field shows: its value, or the right kind of blank. */
    function settings_field_value(array $field, array $profile): ?string
    {
        $value = $profile[$field['key']] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (isset($field['unit']) && !str_contains($value, $field['unit'])) {
            return $value . ' ' . $field['unit'];
        }

        return (string) $value;
    }
}

if (!function_exists('settings_field_blank')) {
    /** The empty state a field gets, which depends on how it is filled in. */
    function settings_field_blank(array $field, string|bool|null $state = null): string
    {
        /* An unanswered one-time field used to read "Bij onboarding", which
           was true when there was nowhere else to answer it. It is answered
           here now, so it says the same thing every other empty field says. */
        return match ($state ?? $field['edit'] ?? true) {
            'locked'  => 'Bij onboarding',
            'derived' => 'Nog onbekend',
            default   => 'Nog niet ingesteld',
        };
    }
}

if (!function_exists('settings_activity_level')) {
    /** The self-declared level, in the words the settings screen uses. */
    function settings_activity_level(?string $level): ?string
    {
        return match ($level) {
            'sedentary' => 'Weinig beweging',
            'light'     => 'Licht actief',
            'moderate'  => 'Gemiddeld actief',
            'active'    => 'Actief',
            'athlete'   => 'Sporter',
            default     => null,
        };
    }
}

if (!function_exists('settings_member_since')) {
    /** Since when the account exists — the one date that is not private. */
    function settings_member_since(?string $createdAt): ?string
    {
        if ($createdAt === null) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($createdAt);
        } catch (Exception $e) {
            return null;
        }

        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
                   'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

        return $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    }
}

if (!function_exists('settings_field_state')) {
    /**
     * How a field behaves right now.
     *
     * The three one-time fields are not permanently locked — they are locked
     * once answered. Before that they have to be fillable, or an account
     * created today could never set a birth date at all. So 'locked' in the
     * config means "locked after the first answer", and this resolves it
     * against what the user has actually stored:
     *
     *   true      editable, as often as you like
     *   'once'    not answered yet: fillable, and permanent once saved
     *   'locked'  answered: shown with a lock, no way in
     *   'derived' calculated from something else, never entered
     */
    function settings_field_state(array $field, array $profile): string|bool
    {
        $edit = $field['edit'] ?? true;

        if ($edit !== 'locked') {
            return $edit;
        }

        $raw = $profile['raw'][$field['key']] ?? null;

        return ($raw === null || $raw === '') ? 'once' : 'locked';
    }
}

if (!function_exists('settings_field_input')) {
    /**
     * What the editor needs to put this field on screen: the kind of input,
     * the value to start from, and the bounds the endpoint will enforce
     * anyway. Nothing here is trusted server-side — it is only so the user
     * meets the limit before the request does.
     */
    function settings_field_input(array $field, array $profile): array
    {
        $key = $field['key'];
        $raw = $profile['raw'][$key] ?? null;

        $input = match ($key) {
            'first_name', 'last_name' => ['type' => 'text', 'maxlength' => 60],
            'date_of_birth'           => ['type' => 'date', 'max' => date('Y-m-d'),
                                          'min' => date('Y-m-d', strtotime('-120 years'))],
            'gender'                  => ['type' => 'choice', 'options' => [
                                             'female'     => 'Vrouw',
                                             'male'       => 'Man',
                                             'non_binary' => 'Non-binair',
                                             'other'      => 'Anders',
                                          ]],
            /* What the person most wants to understand (includes/setup.php):
               the choices come with the profile, from config/setup.php. */
            'focus'                   => ['type' => 'choice', 'options' => $profile['focus_options'] ?? []],
            'height'                  => ['type' => 'number', 'min' => 50, 'max' => 260, 'step' => '0.1'],
            'weight'                  => ['type' => 'number', 'min' => 20, 'max' => 400, 'step' => '0.1'],
            default                   => ['type' => 'text', 'maxlength' => 120],
        };

        $input['value'] = $raw === null ? '' : (string) $raw;
        $input['unit']  = $field['unit'] ?? '';

        /* Which endpoint saves it. The one-time fields go to their own, which
           is the one that refuses a second write. */
        $input['endpoint'] = in_array($key, ['date_of_birth', 'gender'], true)
            ? 'api/profile/onboarding.php'
            : 'api/profile/update.php';

        return $input;
    }
}

if (!function_exists('settings_sync_label')) {
    /** A sync time as the app says times: "Vandaag, 14:32". */
    function settings_sync_label(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            $when = new DateTimeImmutable($timestamp);
        } catch (Exception $e) {
            return null;
        }

        $days = (int) $when->setTime(0, 0)->diff(new DateTimeImmutable('today'))->days;

        $day = match (true) {
            $days === 0 => 'Vandaag',
            $days === 1 => 'Gisteren',
            default     => $when->format('j-n-Y'),
        };

        return $day . ', ' . $when->format('H:i');
    }
}

if (!function_exists('settings_last_sync_across')) {
    /** The most recent sync of any source, for the summary row. */
    function settings_last_sync_across(array $live): ?string
    {
        $newest = null;

        foreach ($live as $state) {
            $at = $state['last_sync_at'] ?? null;

            if ($at !== null && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return settings_sync_label($newest);
    }
}

if (!function_exists('settings_set_toggle')) {
    /**
     * Sets one saving switch (a `toggles` item with a `key`) of one detail
     * page to the account's own value, with the note that goes with it.
     */
    function settings_set_toggle(array $settings, string $pageId, string $key, bool $on): array
    {
        foreach ($settings['pages'][$pageId]['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? null) !== 'toggles') {
                continue;
            }

            foreach ($block['items'] as $i => $item) {
                if (($item['key'] ?? null) === $key) {
                    $settings['pages'][$pageId]['blocks'][$b]['items'][$i]['on']   = $on;
                    $settings['pages'][$pageId]['blocks'][$b]['items'][$i]['note'] = $on ? ($item['note_on'] ?? null) : ($item['note_off'] ?? null);
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_use_ai_state')) {
    /**
     * With Ownify AI on, the `states` items named in $labels say so: their
     * `value_ai` / `note_ai` replace the value and note they have while it
     * is off.
     */
    function settings_use_ai_state(array $settings, string $pageId, array $labels): array
    {
        foreach ($settings['pages'][$pageId]['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? null) !== 'states') {
                continue;
            }

            foreach ($block['items'] as $i => $item) {
                if (!in_array($item['label'] ?? null, $labels, true)) {
                    continue;
                }
                foreach (['value', 'note'] as $field) {
                    if (isset($item[$field . '_ai'])) {
                        $settings['pages'][$pageId]['blocks'][$b]['items'][$i][$field] = $item[$field . '_ai'];
                    }
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_for_app')) {
    /**
     * The Ownify app's own words: every item with a `value_app` or `note_app`
     * (config/settings.php) shows that instead of the website's `value` or
     * `note`, which would not be true on a phone — what it uses, what its
     * text follows. For the app's state only.
     */
    function settings_for_app(array $settings): array
    {
        foreach ($settings['pages'] ?? [] as $pageId => $page) {
            foreach ($page['blocks'] ?? [] as $b => $block) {
                foreach ($block['items'] ?? [] as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach (['value', 'note'] as $field) {
                        if (array_key_exists($field . '_app', $item)) {
                            $settings['pages'][$pageId]['blocks'][$b]['items'][$i][$field] = $item[$field . '_app'];
                            unset($settings['pages'][$pageId]['blocks'][$b]['items'][$i][$field . '_app']);
                        }
                    }
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_set_state_value')) {
    /** Writes a computed value onto one `states` item of one detail page. */
    function settings_set_state_value(array $settings, string $pageId, string $label, ?string $value): array
    {
        foreach ($settings['pages'][$pageId]['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? null) !== 'states') {
                continue;
            }

            foreach ($block['items'] as $i => $item) {
                if (($item['label'] ?? null) === $label) {
                    $settings['pages'][$pageId]['blocks'][$b]['items'][$i]['value'] = $value;
                }
            }
        }

        return $settings;
    }
}

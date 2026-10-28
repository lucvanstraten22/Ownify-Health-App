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

require_once dirname(__DIR__) . '/includes/i18n.php';   // language and units (docs/I18N.md)

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
            $integration['error']     = integration_message_shown($state['last_error'] ?? null);   // stored in Dutch
            $integration['transport'] = $state['transport'] ?? 'cloud';
            /* A sync running now (a cloud source, after connecting or on its
               schedule), so the row can say so. */
            $integration['syncing']   = (bool) ($state['syncing'] ?? false);
            /* Whether Ownify can ask it for new data now (pull-to-sync on
               Gezondheid): a cloud source with a sync endpoint. A phone's
               source cannot be reached from here; its phone sends on its own. */
            $integration['can_sync']  = (bool) ($state['can_sync'] ?? false);

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
            /* Each by the name its owner gave it, else its own
               (device_display_name()); `own` is the phone's own name, which
               an empty name gives back, and `custom` whether it has another. */
            $phones = ($userId !== null && $provider !== null && db_available())
                ? devices_for_user($userId, $provider)
                : [];
            $integration['devices'] = array_map(
                static fn (array $device): array => [
                    'id'        => (int) $device['id'],
                    'label'     => device_display_name($device),
                    'own'       => device_display_name(['label' => $device['label']]),
                    'custom'    => trim((string) ($device['custom_label'] ?? '')) !== '',
                    'platform'  => $device['platform'],
                    'paired'    => settings_sync_label($device['created_at']),
                    'last_sync' => settings_sync_label($device['last_sync_at']),
                ],
                $phones
            );

            /* A phone source's account line is the name of the phone that
               connected it: shown by the name its owner gave that phone. */
            foreach ($phones as $device) {
                if ($integration['account'] !== null && $integration['account'] === $device['label']) {
                    $integration['account'] = device_display_name($device);
                }
            }

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
            t('Laatste sync'),          // the config's label, as config_copy() has it
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

        /* Inzichten: Gemini's wording of insights, a question of its own
           (docs/INSIGHTS.md). Before migration 028 there is nothing to
           switch, and while config/insights.php has Gemini's wording off
           there is nothing to allow: either way the switch is not shown. */
        $insightsOn = false;
        if (is_file(dirname(__DIR__) . '/includes/insights.php') && is_file(dirname(__DIR__) . '/config/insights.php')) {
            require_once dirname(__DIR__) . '/includes/insights.php';
        }
        if (function_exists('insights_consent_state') && db_available() && insights_installed() && insights_gemini_enabled()) {
            $insightsOn = $userId !== null && insights_consent_state($userId) === 'accepted';
            $settings   = settings_set_toggle($settings, 'privacy', 'ai_insights_consent', $insightsOn);
        } else {
            $settings = settings_drop_toggle($settings, 'privacy', 'ai_insights_consent');
        }
        if ($insightsOn && !$aiOn) {
            $settings = settings_use_state_variant($settings, 'privacy', ['Gezondheidsgegevens'], 'insights');
        }

        if ($aiOn) {
            $settings = settings_use_ai_state($settings, 'privacy', ['Gezondheidsgegevens', 'OwnifyAI']);

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
            $auth['signed_in'] ? (string) $auth['user']['username'] : t('Niet ingelogd')
        );

        /* Systeem, Dark or White Mode, as this browser chose it
           (lib/theme.php). The app sends no cookie, so it gets Systeem and
           shows the phone's own choice in its place. */
        require_once __DIR__ . '/theme.php';
        $settings = settings_use_theme($settings, app_theme_preference());
        $settings = settings_use_language_units($settings);
        $settings = settings_use_accessibility($settings, accessibility_choices());
        $settings = settings_use_notices($settings, rating_notice_hour());

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

if (!function_exists('settings_use_language_units')) {
    /**
     * Taal and Eenheden as they are in force (includes/i18n.php): the ticked
     * option, the main page's rows, and what "Nu in gebruik" names.
     */
    function settings_use_language_units(array $settings): array
    {
        $language = i18n_chosen() ?? 'auto';
        $units    = units_system();

        foreach (['language', 'preferences'] as $pageId) {
            foreach ($settings['pages'][$pageId]['blocks'] ?? [] as $b => $block) {
                $name = $block['name'] ?? '';

                if (($block['type'] ?? '') === 'choice' && $name === 'language') {
                    $settings['pages'][$pageId]['blocks'][$b]['selected'] = $language;

                    /* Each language is named in itself; the other one also
                       in the language on screen ("English — Engels"). */
                    foreach ($block['options'] as $o => $option) {
                        if (in_array($option['key'], I18N_LOCALES, true) && $option['key'] === i18n_locale()) {
                            $settings['pages'][$pageId]['blocks'][$b]['options'][$o]['note'] = '';
                        }
                    }
                }

                if (($block['type'] ?? '') === 'choice' && $name === 'units') {
                    $settings['pages'][$pageId]['blocks'][$b]['selected'] = $units;
                }

                if (($block['type'] ?? '') === 'choice' && $name === 'week') {
                    $settings['pages'][$pageId]['blocks'][$b]['selected'] = week_start();
                }

                if (($block['type'] ?? '') === 'rows' && $name === 'units_now' && $units === 'imperial') {
                    $settings['pages'][$pageId]['blocks'][$b]['items'] = [
                        ['label' => t('Gewicht'),     'value' => t('pond (lb)')],
                        ['label' => t('Lengte'),      'value' => t('voet en inch (ft/in)')],
                        ['label' => t('Afstand'),     'value' => t('mijl (mi)')],
                        ['label' => t('Temperatuur'), 'value' => t('graden Fahrenheit (°F)')],
                        ['label' => t('Energie'),     'value' => t('kilocalorie (kcal)')],
                    ];
                }
            }
        }

        /* "Versie Beta 15.0": the word in the language on screen. */
        foreach ($settings['groups'] as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                if ($row['id'] === 'about' && str_starts_with((string) ($row['value'] ?? ''), 'Versie ')) {
                    $settings['groups'][$g]['rows'][$r]['value'] = t('Versie') . substr((string) $row['value'], 6);
                }
            }
        }

        $settings = settings_set_row_value($settings, 'language', match ($language) {
            'en'    => 'English',
            'nl'    => 'Nederlands',
            default => t('Automatisch'),
        });

        $week = $settings['pages']['preferences']['blocks'] ?? [];
        $day  = t('Maandag');
        foreach ($week as $block) {
            if (($block['name'] ?? '') === 'week') {
                foreach ($block['options'] as $option) {
                    if ($option['key'] === week_start()) {
                        $day = (string) $option['label'];
                    }
                }
            }
        }

        return settings_set_row_value($settings, 'preferences', ($units === 'imperial' ? t('Imperiaal') : t('Metrisch')) . ' · ' . $day);
    }
}

if (!function_exists('settings_use_accessibility')) {
    /**
     * Toegankelijkheid as the account has it: its three switches, and "Nu
     * actief" saying what is in force — the account's choice (tone on) or
     * the device's (neutral).
     *
     * @param array{text_large: bool, contrast_more: bool, motion_off: bool} $choices
     */
    function settings_use_accessibility(array $settings, array $choices): array
    {
        foreach ($choices as $key => $on) {
            $settings = settings_set_toggle($settings, 'preferences', $key, $on);
        }

        foreach ($settings['pages']['preferences']['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? null) !== 'states' || empty($block['status'])) {
                continue;
            }
            foreach ($block['items'] as $i => $item) {
                $key = $item['key'] ?? null;
                /* What it says while the device decides, for settings.js to
                   put back when the switch goes off again. */
                $settings['pages']['preferences']['blocks'][$b]['items'][$i]['value_off'] = $item['value'] ?? null;
                $settings['pages']['preferences']['blocks'][$b]['items'][$i]['note_off']  = $item['note'] ?? null;
                /* The app's own words for it, which settings_for_app() puts
                   in place for the app (`note_app` goes once the choice wins). */
                foreach (['value', 'note'] as $field) {
                    if (array_key_exists($field . '_app', $item)) {
                        $settings['pages']['preferences']['blocks'][$b]['items'][$i][$field . '_off_app'] = $item[$field . '_app'];
                    }
                }
                if ($key !== null && !empty($choices[$key])) {
                    $settings['pages']['preferences']['blocks'][$b]['items'][$i]['value'] = $item['value_on'] ?? $item['value'];
                    $settings['pages']['preferences']['blocks'][$b]['items'][$i]['note']  = $item['note_on'] ?? $item['note'] ?? null;
                    $settings['pages']['preferences']['blocks'][$b]['items'][$i]['tone']  = 'on';
                    /* The app's own note is about the device; the choice wins. */
                    unset($settings['pages']['preferences']['blocks'][$b]['items'][$i]['note_app']);
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_use_notices')) {
    /** Meldingen › Stip bij Voeding as the account has it: the ticked hour, and the main page's row. */
    function settings_use_notices(array $settings, int $hour): array
    {
        foreach ($settings['pages']['notifications']['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? '') === 'choice' && ($block['name'] ?? '') === 'rating_notice') {
                $settings['pages']['notifications']['blocks'][$b]['selected'] = (string) $hour;
            }
        }

        return settings_set_row_value($settings, 'notifications', tf('Voeding vanaf %s', sprintf('%02d:00', $hour)));
    }
}

if (!function_exists('settings_device_summary')) {
    /** How many sources are attached, in words a person would use. */
    function settings_device_summary(int $connected): string
    {
        if ($connected === 0) {
            return t('Geen verbonden');
        }

        return tf('%d verbonden', $connected);
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
            'date_of_birth' => null, 'gender' => null,
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
    /**
     * "182 cm" from the measurement row, or null while there is none — in the
     * person's units: "5 ft 11 in", "159.8 lb" (includes/i18n.php). The row
     * itself stays in cm or kg.
     */
    function settings_measurement(?array $measurement): ?string
    {
        if ($measurement === null || !isset($measurement['value'])) {
            return null;
        }

        return units_format((float) $measurement['value'], (string) ($measurement['unit'] ?? ''));
    }
}

if (!function_exists('settings_date')) {
    /** A date of birth as a person writes it: 4 oktober 1998, October 4, 1998. */
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

        return i18n_date($value, 'day_month_year');
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
            'female'     => t('Vrouw'),
            'male'       => t('Man'),
            'non_binary' => t('Non-binair'),
            'other'      => t('Anders'),
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

        /* A measurement arrives with its unit (settings_measurement), in the
           person's units; anything else gets the field's. */
        if (isset($field['unit']) && !preg_match('/\p{L}/u', $value)) {
            return $value . ' ' . units_label((string) $field['unit']);
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
            'locked'  => t('Bij onboarding'),
            'derived' => t('Nog onbekend'),
            default   => t('Nog niet ingesteld'),
        };
    }
}

if (!function_exists('settings_activity_level')) {
    /** The self-declared level, in the words the settings screen uses. */
    function settings_activity_level(?string $level): ?string
    {
        return match ($level) {
            'sedentary' => t('Weinig beweging'),
            'light'     => t('Licht actief'),
            'moderate'  => t('Gemiddeld actief'),
            'active'    => t('Actief'),
            'athlete'   => t('Sporter'),
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

        return i18n_date($date, 'month_year');
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
                                             'female'     => t('Vrouw'),
                                             'male'       => t('Man'),
                                             'non_binary' => t('Non-binair'),
                                             'other'      => t('Anders'),
                                          ]],
            /* What the person most wants to understand (includes/setup.php):
               the choices come with the profile, from config/setup.php. */
            'focus'                   => ['type' => 'choice', 'options' => $profile['focus_options'] ?? []],
            /* In the person's units (includes/i18n.php): the bounds are the
               endpoint's 50–260 cm and 20–400 kg, converted. Imperial height
               is feet and inches, two numbers. */
            'height'                  => units_imperial()
                                          ? ['type' => 'feet_inches', 'min' => 20, 'max' => 102, 'step' => '1']
                                          : ['type' => 'number', 'min' => 50, 'max' => 260, 'step' => '0.1'],
            'weight'                  => ['type' => 'number', 'min' => (int) ceil(units_out(20, 'kg')),
                                          'max' => (int) floor(units_out(400, 'kg')), 'step' => '0.1'],
            default                   => ['type' => 'text', 'maxlength' => 120],
        };

        $input['value'] = $raw === null ? '' : (string) $raw;
        $input['unit']  = isset($field['unit']) ? units_label((string) $field['unit']) : '';

        /* A measurement starts from its stored value, shown in the person's
           units, and says which unit comes back (`<key>_unit`), so the
           endpoint converts exactly once. */
        if (in_array($key, ['height', 'weight'], true)) {
            $input['post_unit'] = $input['unit'];
            if ($raw !== null) {
                $shown          = units_out((float) $raw, (string) $field['unit']);
                $input['value'] = $input['type'] === 'feet_inches'
                    ? (string) (int) round($shown)
                    : i18n_number_trim($shown, 1, false);
            }
            if ($input['type'] === 'feet_inches') {
                $inches          = $raw === null ? null : (int) round(units_out((float) $raw, 'cm'));
                $input['feet']   = $inches === null ? '' : (string) intdiv($inches, 12);
                $input['inches'] = $inches === null ? '' : (string) ($inches % 12);
                $input['unit']   = 'ft/in';
            }
        }

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
            $days === 0 => t('Vandaag'),
            $days === 1 => t('Gisteren'),
            default     => i18n_date($when, 'numeric'),
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

if (!function_exists('settings_drop_toggle')) {
    /** Takes the toggles block holding switch $key off the page (nothing to switch yet). */
    function settings_drop_toggle(array $settings, string $pageId, string $key): array
    {
        foreach ($settings['pages'][$pageId]['blocks'] ?? [] as $b => $block) {
            if (($block['type'] ?? null) === 'toggles'
                && in_array($key, array_column((array) ($block['items'] ?? []), 'key'), true)) {
                unset($settings['pages'][$pageId]['blocks'][$b]);
            }
        }
        if (isset($settings['pages'][$pageId]['blocks'])) {
            $settings['pages'][$pageId]['blocks'] = array_values($settings['pages'][$pageId]['blocks']);
        }

        return $settings;
    }

    /**
     * The `states` items named in $labels show their `value_<variant>` /
     * `note_<variant>` instead of their value and note.
     */
    function settings_use_state_variant(array $settings, string $pageId, array $labels, string $variant): array
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
                    if (isset($item[$field . '_' . $variant])) {
                        $settings['pages'][$pageId]['blocks'][$b]['items'][$i][$field] = $item[$field . '_' . $variant];
                    }
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
     * text follows; `value_off_app` / `note_off_app` likewise for what a
     * Nu actief row says once its switch is off again. For the app's state only.
     */
    function settings_for_app(array $settings): array
    {
        foreach ($settings['pages'] ?? [] as $pageId => $page) {
            foreach ($page['blocks'] ?? [] as $b => $block) {
                foreach ($block['items'] ?? [] as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach (['value', 'note', 'value_off', 'note_off'] as $field) {
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

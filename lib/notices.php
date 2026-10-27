<?php
/**
 * The red dots: what waits for the signed-in person to do it (docs/NOTICES.md).
 *
 * Worked out once, here, from what app_page_data() has already read, so the
 * website and the Android app show exactly the same dots, and a refresh, a
 * restart or the other app always agrees. Nothing is stored: a dot is gone
 * the moment what it points at is done.
 *
 *   rating   today's Voeding cijfer is not given yet, by someone who gives
 *            one (at least once before). Shown from `hour` o'clock, the
 *            account's choice (Meldingen › Stip bij Voeding, 20:00 unless
 *            chosen otherwise), on the device's own clock.
 *   goals    a running goal kept by hand that counts days, not ticked today
 *   friends  the people whose friend request waits for an answer
 *   invites  the challenges you are invited to and have not answered
 *   sources  the sources that stopped working (an error, or access revoked)
 *
 * Where each is shown — always on the icon, never beside a word, and on each
 * step of the way to it only — is the apps' own business: notice_on() for
 * the website's templates, assets/js/notices.js after a change in place,
 * Notices.kt in the app.
 */

declare(strict_types=1);

if (!function_exists('notices_for')) {

    /**
     * @param array<string, mixed> $data  app_page_data(), filled
     * @return array{rating: array{waiting: bool, hour: int, due: bool}, goals: list<string>, friends: list<int>, invites: list<int>, sources: list<string>}
     */
    function notices_for(array $data, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now  = $now ?? new DateTimeImmutable('now');
        $hour = function_exists('rating_notice_hour') ? rating_notice_hour() : 20;

        $nutrition = $data['health']['areas']['nutrition'] ?? [];
        $waiting   = isset($nutrition['rating'])
            && ($nutrition['rating_today'] ?? null) === null
            && notices_rates($userId);

        $goals = [];
        foreach ($data['goals']['active'] ?? [] as $goal) {
            if (notices_goal_waits($goal, $now)) {
                $goals[] = (string) $goal['id'];
            }
        }

        $friends = array_values(array_map(
            static fn (array $request): int => (int) ($request['user_id'] ?? $request['requested_by'] ?? 0),
            $data['community']['pending'] ?? []
        ));

        $invites = array_values(array_map(
            static fn (array $invite): int => (int) $invite['id'],
            $data['challenges']['invites'] ?? []
        ));

        $sources = [];
        foreach ($data['settings']['integrations'] ?? [] as $integration) {
            if (in_array($integration['status'] ?? null, ['error', 'revoked'], true) && isset($integration['provider'])) {
                $sources[] = (string) $integration['provider'];
            }
        }

        return [
            'rating'  => [
                'waiting' => $waiting,
                'hour'    => $hour,
                /* By the server's clock, for the first paint; the apps use
                   the device's from then on. */
                'due'     => $waiting && (int) $now->format('G') >= $hour,
            ],
            'goals'   => $goals,
            'friends' => $friends,
            'invites' => $invites,
            'sources' => $sources,
        ];
    }

    /** Whether the account gives a Voeding cijfer at all: once, ever, is enough. */
    function notices_rates(int $userId): bool
    {
        if (!function_exists('db_available') || !db_available() || !function_exists('health_source_id')) {
            return false;
        }

        return (bool) db_value(
            "SELECT 1 FROM health_metrics
              WHERE user_id = ? AND source_id = ? AND external_id LIKE 'daily-rating:%' LIMIT 1",
            [$userId, health_source_id('manual')]
        );
    }

    /**
     * A goal waits for you today when you keep it by hand, it counts days, it
     * is running (not paused, started, not finished) and today is not ticked.
     * A Mijlpaal or an Optellen needs a value only when you have one, and a
     * goal per week can still be met later in the week: no dot for those.
     */
    function notices_goal_waits(array $goal, DateTimeImmutable $now): bool
    {
        $entry = $goal['entry'] ?? null;

        return !empty($goal['is_manual'])
            && empty($goal['is_completed'])
            && ($goal['status'] ?? 'active') === 'active'
            && !empty($goal['counts_days'])
            && is_array($entry)
            && ($entry['kind'] ?? null) === 'tick'
            && empty($entry['done'])
            && (string) ($goal['start_date'] ?? '') <= $now->format('Y-m-d');
    }

    /**
     * The summary the page is drawn with, kept once app_page_data() has it,
     * so a template deep down (a challenge's invitation, a friend's row) can
     * ask for a dot without being handed it.
     */
    function notices_current(?array $use = null): array
    {
        static $current = ['rating' => ['waiting' => false, 'hour' => 20, 'due' => false],
                           'goals' => [], 'friends' => [], 'invites' => [], 'sources' => []];

        if ($use !== null) {
            $current = $use;
        }

        return $current;
    }

    /**
     * Whether the dot [$key] shows, for the templates' first paint:
     *
     *   rating, goal:<id>, friend:<user id>, invite:<id>, source:<provider>
     *   goals, friends, sources          any of them
     *   tab:health|goals|community|settings
     *   account                          a friend request waits
     */
    function notice_on(string $key, ?array $notices = null): bool
    {
        $notices = $notices ?? notices_current();
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);

        return match ($kind) {
            'rating'  => $notices['rating']['due'] ?? false,
            'goal'    => in_array((string) $id, $notices['goals'] ?? [], true),
            'friend'  => in_array((int) $id, $notices['friends'] ?? [], true),
            'invite'  => in_array((int) $id, $notices['invites'] ?? [], true),
            'source'  => in_array((string) $id, $notices['sources'] ?? [], true),
            'goals'   => ($notices['goals'] ?? []) !== [],
            'sources' => ($notices['sources'] ?? []) !== [],
            'account', 'friends' => ($notices['friends'] ?? []) !== [],
            'tab'     => match ($id) {
                'health'    => $notices['rating']['due'] ?? false,
                'goals'     => ($notices['goals'] ?? []) !== [],
                'community' => ($notices['invites'] ?? []) !== [],
                'settings'  => ($notices['sources'] ?? []) !== [],
                default     => false,
            },
            default   => false,
        };
    }

    /**
     * The dot itself, for a template: on an icon's corner (`corner`, the
     * cut-out dot) or beside a bare line icon (`beside`, the dot with a soft
     * halo). Hidden, not left out, when nothing waits, so notices.js can show
     * it again without drawing anything.
     */
    function notice_dot(string $key, string $place = 'corner'): string
    {
        return '<span class="notice-dot notice-dot--' . ($place === 'beside' ? 'beside' : 'corner') . '"'
            . ' data-notice="' . htmlspecialchars($key, ENT_QUOTES) . '" aria-hidden="true"'
            . (notice_on($key) ? '' : ' hidden') . '></span>';
    }
}

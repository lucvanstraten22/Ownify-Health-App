<?php
/**
 * OwnifyAI's pace: how many requests go to Gemini per minute, for everybody
 * together.
 *
 * Google's free tier allows the project a few requests per minute (5 for
 * gemini-3.8-flash), shared by every account. Ownify keeps its own count so
 * it never asks more: one rolling minute, in one row of ai_service_state
 * ('minute_window'), read and written under a row lock so two people
 * asking at the same moment cannot both take the last place.
 *
 * A question reserves the requests it may need (config max_rounds) before
 * anything is sent, marks each one when it is actually made, and gives back
 * what it did not use as soon as it is done. A used request holds its place
 * for a minute (and two seconds, for the clocks) from the moment it was
 * made; a reservation nobody marked lets go after two minutes, so a request
 * that died halfway never blocks anyone for long.
 *
 * When there is no room, the person is told how many seconds until there
 * is (ai_pace_take()['wait']) and the sheet counts down; nothing is sent and
 * nothing of theirs is counted.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('ai_pace_take')) {

    /* One request holds its place this long after it was made; a
       reservation that was never used lets go after AI_PACE_HOLD. */
    define('AI_PACE_WINDOW', 62);
    define('AI_PACE_HOLD', 120);

    /** The requests per minute Ownify allows itself (config minute_limit, 1–8: the row holds eight). */
    function ai_pace_limit(): int
    {
        return max(1, min(8, (int) (ai_config()['minute_limit'] ?? 5)));
    }

    /**
     * The places taken in the minute, each [tag, until, used]: drops the
     * ones whose time is up.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    function ai_pace_slots(?string $value, int $now): array
    {
        $slots = json_decode((string) $value, true);

        if (!is_array($slots)) {
            return [];
        }

        return array_values(array_filter($slots, static fn ($s): bool => is_array($s) && count($s) === 3
            && is_string($s[0]) && (int) $s[1] > $now));
    }

    /** Seconds until $need places are free among $slots (0 when they already are). */
    function ai_pace_wait_for(array $slots, int $need, int $now): int
    {
        $over = count($slots) + $need - ai_pace_limit();

        if ($over <= 0) {
            return 0;
        }

        $untils = array_map(static fn (array $s): int => (int) $s[1], $slots);
        sort($untils);

        return max(1, ($untils[min($over, count($untils)) - 1] ?? $now) - $now);
    }

    /**
     * Runs $change on the minute's places under the row lock, and stores
     * what it returns. $change gets the live places and now, and returns
     * [places to store, result].
     */
    function ai_pace_locked(callable $change): mixed
    {
        $pdo = db();

        if ($pdo === null) {
            return null;
        }

        $now = time();

        try {
            $pdo->beginTransaction();
            db_run("INSERT IGNORE INTO ai_service_state (name, value) VALUES ('minute_window', '[]')");
            $value = db_value("SELECT value FROM ai_service_state WHERE name = 'minute_window' FOR UPDATE");

            [$slots, $result] = $change(ai_pace_slots(is_string($value) ? $value : null, $now), $now);

            db_run("UPDATE ai_service_state SET value = ? WHERE name = 'minute_window'",
                [json_encode(array_values($slots))]);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] ai: the minute count could not be read or written: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Reserves $count requests for one question.
     *
     * @return array{ok: bool, tag: ?string, wait: int}  ok with a tag to use
     *         and give back; or not, with the seconds until there is room
     */
    function ai_pace_take(int $count): array
    {
        $count = max(1, min($count, ai_pace_limit()));
        $tag   = bin2hex(random_bytes(4));

        $result = ai_pace_locked(static function (array $slots, int $now) use ($count, $tag): array {
            $wait = ai_pace_wait_for($slots, $count, $now);

            if ($wait > 0) {
                return [$slots, ['ok' => false, 'tag' => null, 'wait' => $wait]];
            }

            for ($i = 0; $i < $count; $i++) {
                $slots[] = [$tag, $now + AI_PACE_HOLD, 0];
            }

            return [$slots, ['ok' => true, 'tag' => $tag, 'wait' => 0]];
        });

        /* The count could not be kept (no database): ask anyway, as before
           there was one — Google still says no when it must. */
        return $result ?? ['ok' => true, 'tag' => null, 'wait' => 0];
    }

    /**
     * One request of question $tag is being made now: its place is held for
     * a minute from now. With none of its reservations left, it takes a new
     * place if there is one. False when there is no room.
     */
    function ai_pace_use(?string $tag): bool
    {
        if ($tag === null) {
            return true;
        }

        return (bool) (ai_pace_locked(static function (array $slots, int $now) use ($tag): array {
            foreach ($slots as $i => $slot) {
                if ($slot[0] === $tag && (int) $slot[2] === 0) {
                    $slots[$i] = [$tag, $now + AI_PACE_WINDOW, 1];

                    return [$slots, true];
                }
            }

            if (ai_pace_wait_for($slots, 1, $now) > 0) {
                return [$slots, false];
            }

            $slots[] = [$tag, $now + AI_PACE_WINDOW, 1];

            return [$slots, true];
        }) ?? true);
    }

    /** Gives back what question $tag reserved and did not use. */
    function ai_pace_release(?string $tag): void
    {
        if ($tag === null) {
            return;
        }

        ai_pace_locked(static fn (array $slots, int $now): array => [
            array_filter($slots, static fn (array $s): bool => !($s[0] === $tag && (int) $s[2] === 0)),
            null,
        ]);
    }

    /** Seconds until one more question fits (0: now), without taking anything. */
    function ai_pace_wait(): int
    {
        if (!ai_installed()) {
            return 0;
        }

        $value = db_value("SELECT value FROM ai_service_state WHERE name = 'minute_window'");
        $now   = time();

        return ai_pace_wait_for(ai_pace_slots(is_string($value) ? $value : null, $now),
            max(1, min((int) ai_config()['max_rounds'], ai_pace_limit())), $now);
    }
}

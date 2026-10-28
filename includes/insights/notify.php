<?php
/**
 * "This person's data changed": the one line every save and sync calls, so
 * the next page or app load reassesses today's insights (docs/INSIGHTS.md).
 *
 * It only marks the day. Nothing is worked out here — a sync that repeats
 * the same week costs one UPDATE, and the reassessment that follows finds
 * the same facts and writes nothing. Before migration 028, or for a day that
 * was not worked out yet, it does nothing at all.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

if (!function_exists('insights_note_change')) {

    /** Whether insight_days is there (migration 028); asked once per request. */
    function insights_days_present(): bool
    {
        static $present = null;

        if ($present !== null) {
            return $present;
        }
        if (!db_available()) {
            return false;
        }

        return $present = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'insight_days'"
        ) === 1;
    }

    /** Marks today's insights for reassessment ($at: now). Never throws. */
    function insights_note_change(int $userId, ?DateTimeImmutable $at = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $at ??= new DateTimeImmutable('now');

        try {
            if (!insights_days_present()) {
                return;
            }
            db_run(
                'UPDATE insight_days SET data_changed_at = ? WHERE user_id = ? AND local_date = ?',
                [$at->format('Y-m-d H:i:s'), $userId, $at->format('Y-m-d')]
            );
        } catch (Throwable $e) {
            error_log('[ownify] insights: could not mark a change: ' . $e->getMessage());
        }
    }
}

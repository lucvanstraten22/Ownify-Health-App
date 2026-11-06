<?php
/**
 * Fetches new Google Health data for every connected person — the scheduled half of
 * keeping Google Health up to date (docs/GOOGLE-HEALTH.md). Google Health's API sends no
 * notifications, so Ownify asks; run it from cron, e.g. every 30 minutes:
 *
 *     php tools/google-health-sync.php
 *
 *   --min-age=MIN   skip anyone synced less than MIN minutes ago (default 55)
 *   --budget=N      stop after about N requests to Google Health in this run
 *                   (default 1200; Google limits requests per project per minute)
 *   --user=ID       only this account (for a test by hand)
 *
 * Prints one line per person: their id, how it went, how many records, how
 * many requests. Never a token, never a name, never health data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/integrations.php';

$options = getopt('', ['min-age::', 'budget::', 'user::']);
$minAge  = max(0, (int) ($options['min-age'] ?? 55));
$budget  = max(10, (int) ($options['budget'] ?? 1200));
$only    = isset($options['user']) ? (int) $options['user'] : null;

if (!db_available() || !google_health_stored() || !google_health_credentials_present()) {
    fwrite(STDERR, "google-health-sync: Google Health is not set up here (database, migration 020 or client credentials).\n");
    exit(1);
}

$people = db_all(
    "SELECT user_id FROM user_integrations
      WHERE provider = 'google_health' AND status IN ('connected', 'error')
        AND (sync_started_at IS NULL OR sync_started_at < NOW() - INTERVAL 15 MINUTE)
        AND (last_sync_at IS NULL OR last_sync_at <= NOW() - INTERVAL ? MINUTE)" . ($only !== null ? ' AND user_id = ?' : '') . "
      ORDER BY last_sync_at IS NOT NULL, last_sync_at",
    $only !== null ? [$minAge, $only] : [$minAge]
);

$used = 0;

foreach ($people as $person) {
    if ($used >= $budget) {
        echo "budget reached ({$used} requests): the rest next run\n";
        break;
    }

    $userId = (int) $person['user_id'];

    try {
        $result = google_health_sync($userId, 'schedule');
    } catch (Throwable $e) {
        error_log('[google-health] scheduled sync for user ' . $userId . ' failed: ' . $e::class);
        echo "user {$userId}: error\n";
        continue;
    }

    $used += $result['requests'];
    printf("user %d: %s, %d records, %d requests\n", $userId, $result['status'], $result['written'], $result['requests']);
}

echo 'done: ' . count($people) . " connected, {$used} requests\n";

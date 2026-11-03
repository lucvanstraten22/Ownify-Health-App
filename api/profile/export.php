<?php
/**
 * Gegevens downloaden (Instellingen › Privacy › Je gegevens): everything
 * Ownify keeps about the signed-in account, as one JSON file
 * (includes/data-export.php says what is in it, and what never is).
 *
 *   POST (csrf on the website, the account token in the app)
 *   → 200 { ok: true, filename, export: { format, version, exported_at, … } }
 *
 * The account is the one in the session or the token, never one named in the
 * request. The answer is written in pieces, as it is read.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/data-export.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

/* A long history takes a while to read: never cut off half-way. */
@set_time_limit(300);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

while (ob_get_level() > 0) {
    ob_end_flush();
}

echo '{"ok":true,"filename":' . json_encode(data_export_filename()) . ',"export":';
data_export_stream($userId, static function (string $piece): void {
    echo $piece;
});
echo '}';

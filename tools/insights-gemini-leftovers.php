<?php
/**
 * What 29.0's Gemini wording of insights may have left in the database, now
 * that insights are Ownify's own sentences (30.1, docs/INSIGHTS.md). The
 * columns stay (no schema change); this only looks at, and on request
 * empties, the values in them. OwnifyAI's own consent (`ai_consent`) and its
 * conversations are never touched.
 *
 *     php tools/insights-gemini-leftovers.php           count only — reads,
 *                                                       changes nothing
 *     php tools/insights-gemini-leftovers.php --clear   count, and when
 *                                                       anything is there,
 *                                                       empty it in one
 *                                                       transaction
 *
 * Looks at:
 *   user_insights   phrased_body, phrased_locale, phrased_model
 *   user_profiles   ai_insights_consent, ai_insights_consent_version, _at
 *   insight_days    gemini_calls, phrase_state, phrase_note
 *
 * Uses the server's own database settings (config/database.php, DB_* …).
 * Exit 0 when nothing is left (after --clear: when it is gone), 1 otherwise,
 * 2 when the database or migration 028 is not there.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';

$clear = in_array('--clear', array_slice($argv, 1), true);

if (!db_available()) {
    fwrite(STDERR, "No database.\n");
    exit(2);
}

$columns = static function (string $table): array {
    return array_map('strval', array_column(db_all(
        'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    ), 'c'));
};

$checks = [
    'user_insights with a stored Gemini wording' => [
        'table' => 'user_insights',
        'needs' => ['phrased_body', 'phrased_locale', 'phrased_model'],
        'where' => 'phrased_body IS NOT NULL OR phrased_locale IS NOT NULL OR phrased_model IS NOT NULL',
        'clear' => 'UPDATE user_insights SET phrased_body = NULL, phrased_locale = NULL, phrased_model = NULL
                     WHERE phrased_body IS NOT NULL OR phrased_locale IS NOT NULL OR phrased_model IS NOT NULL',
    ],
    'user_profiles with an insight consent value' => [
        'table' => 'user_profiles',
        'needs' => ['ai_insights_consent', 'ai_insights_consent_version', 'ai_insights_consent_at'],
        'where' => 'ai_insights_consent IS NOT NULL OR ai_insights_consent_version IS NOT NULL OR ai_insights_consent_at IS NOT NULL',
        'clear' => 'UPDATE user_profiles SET ai_insights_consent = NULL, ai_insights_consent_version = NULL, ai_insights_consent_at = NULL
                     WHERE ai_insights_consent IS NOT NULL OR ai_insights_consent_version IS NOT NULL OR ai_insights_consent_at IS NOT NULL',
    ],
    'insight_days with a Gemini call or wording state' => [
        'table' => 'insight_days',
        'needs' => ['gemini_calls', 'phrase_state', 'phrase_note'],
        'where' => "gemini_calls > 0 OR phrase_state <> 'none' OR phrase_note IS NOT NULL",
        'clear' => "UPDATE insight_days SET gemini_calls = 0, phrase_state = 'none', phrase_note = NULL
                     WHERE gemini_calls > 0 OR phrase_state <> 'none' OR phrase_note IS NOT NULL",
    ],
];

$total = 0;
$found = [];
foreach ($checks as $label => $check) {
    if (array_diff($check['needs'], $columns($check['table'])) !== []) {
        fwrite(STDERR, "Migration 028 is not in this database ({$check['table']}).\n");
        exit(2);
    }
    $n = (int) db_value('SELECT COUNT(*) FROM ' . $check['table'] . ' WHERE ' . $check['where']);
    $found[$label] = $n;
    $total += $n;
    printf("%-52s %d\n", $label, $n);
}

if ($total === 0) {
    echo "Nothing left. Nothing to clear.\n";
    exit(0);
}

if (!$clear) {
    echo "Something is left. Run again with --clear to empty it (OwnifyAI is not touched).\n";
    exit(1);
}

db()->beginTransaction();
try {
    foreach ($checks as $label => $check) {
        if ($found[$label] > 0) {
            db_run($check['clear']);
        }
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    fwrite(STDERR, 'Nothing was changed: ' . $e->getMessage() . "\n");
    exit(1);
}

$left = 0;
foreach ($checks as $check) {
    $left += (int) db_value('SELECT COUNT(*) FROM ' . $check['table'] . ' WHERE ' . $check['where']);
}
echo $left === 0 ? "Cleared.\n" : "Still $left left.\n";
exit($left === 0 ? 0 : 1);

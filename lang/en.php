<?php
/**
 * The English catalogue: English, keyed by the Dutch it translates
 * (includes/i18n.php, docs/I18N.md). One catalogue, kept in parts by area in
 * lang/en/ — a Dutch string has one English, wherever it appears.
 * tools/i18n-build.php checks it and derives the scripts' and the app's
 * subsets from it.
 */
declare(strict_types=1);

$catalog = [];

foreach (glob(__DIR__ . '/en/*.php') ?: [] as $part) {
    $catalog += (array) require $part;
}

return $catalog;

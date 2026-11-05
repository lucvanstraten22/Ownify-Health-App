<?php
/**
 * Over de app's Privacyverklaring, Voorwaarden and Licenties (39.0,
 * docs/LEGAL.md), tested on what both apps read.
 *
 *     php tools/legal-test.php
 *
 *   - Over de app's Juridisch block opens three documents, none of them
 *     "Binnenkort" any more
 *   - every document has a title, a lede and sections, every section a title
 *     and something under it, in Dutch and in English — every Dutch line of
 *     config/legal.php has its English in lang/en
 *   - the Licenties: PHPMailer with its vendored version, every Android
 *     artifact of the release build with a version, a known licence and a
 *     project, and the full text of every licence the lists name
 *
 * No database, no network.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/i18n.php';
require_once $root . '/lib/legal.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/* ------------------------------------------------ Over de app's Juridisch */
echo "Over de app\n";
i18n_force('nl');
$about = config_copy('settings')['pages']['about']['blocks'];
$docs  = array_values(array_filter($about, static fn ($b) => ($b['type'] ?? '') === 'documents'));
check('one Juridisch block of documents', count($docs) === 1 && $docs[0]['title'] === 'Juridisch');
check('Privacyverklaring, Voorwaarden, Licenties in that order',
    array_column($docs[0]['items'] ?? [], 'id') === ['privacy', 'terms', 'licenses']
    && array_column($docs[0]['items'] ?? [], 'label') === ['Privacyverklaring', 'Voorwaarden', 'Licenties']);
check('no "Binnenkort" left on Over de app', !str_contains(json_encode($about, JSON_UNESCAPED_UNICODE), 'Binnenkort'));

/* ----------------------------------------------- the documents, both languages */
foreach (['nl', 'en'] as $locale) {
    echo "The documents ($locale)\n";
    i18n_force($locale);
    $legal = legal_documents();
    check('three documents, each one the Juridisch block opens', array_column($legal['documents'], 'id') === ['privacy', 'terms', 'licenses']);
    check('the way back and the licence toggle are named', $legal['back'] !== '' && $legal['licence_text'] !== '');
    foreach ($legal['documents'] as $doc) {
        check("{$doc['id']}: title, lede and sections", $doc['title'] !== '' && $doc['lede'] !== '' && count($doc['sections']) > 0);
        $empty = array_filter($doc['sections'], static fn ($s) => $s['title'] === ''
            || ($s['text'] === [] && $s['points'] === [] && $s['licence'] === null));
        check("{$doc['id']}: every section has a title and content", $empty === [], json_encode(array_column($empty, 'title')));
        $shape = array_filter($doc['sections'], static fn ($s) => array_keys($s) !== ['title', 'text', 'points', 'link', 'licence']);
        check("{$doc['id']}: every section has every field", $shape === []);
        $links = array_filter(array_column($doc['sections'], 'link'));
        check("{$doc['id']}: every link goes to https or http", array_filter($links, static fn ($l) => !preg_match('#^https?://#', $l['href'])) === []);
    }
}

echo "English for every line\n";
i18n_force('en');
$catalog = i18n_catalog();
$missing = [];
$walk    = static function (array $node) use (&$walk, &$missing, $catalog): void {
    foreach ($node as $key => $value) {
        if (is_string($key) && in_array($key, I18N_IDENTIFIERS, true)) {
            continue;
        }
        if (is_array($value)) {
            $walk($value);
        } elseif (is_string($value) && $value !== '' && !isset($catalog[$value])) {
            $missing[] = $value;
        }
    }
};
$walk((array) require $root . '/config/legal.php');
foreach (legal_android_projects() as $project) {
    if (!isset($catalog[$project['name']]) && preg_match('/[a-z]-[a-z]|bibliotheken| en /', $project['name'])) {
        $missing[] = $project['name'];
    }
}
check('every Dutch line of config/legal.php has its English', $missing === [], implode(' | ', array_slice($missing, 0, 5)));
$en = json_encode(legal_documents(), JSON_UNESCAPED_UNICODE);
check('no Dutch heading left in English', !preg_match('/Licentieteksten|pakketten|Bron"|Privacyverklaring|Voorwaarden"/', $en));

/* --------------------------------------------------------------- the Licenties */
echo "Licenties\n";
i18n_force('nl');
$sections = legal_documents()['documents'][2]['sections'];
$phpmailer = array_values(array_filter($sections, static fn ($s) => str_ends_with($s['title'], 'PHPMailer')));
$vendor    = (string) file_get_contents($root . '/includes/vendor/phpmailer/VERSION');
check('PHPMailer, the website\'s one package, with its vendored version',
    count($phpmailer) === 1 && str_contains($vendor, 'v' . substr($phpmailer[0]['points'][0], strlen('phpmailer/phpmailer ')))
    && str_contains($phpmailer[0]['text'][0], 'GNU Lesser General Public License 2.1'));

$artifacts = (array) require $root . '/config/licenses-android.php';
$known     = array_keys(legal_licence_names());
check('the Android release build\'s artifacts are listed', count($artifacts) > 100);
check('every artifact with a version', array_filter($artifacts, static fn ($a) => trim((string) $a['version']) === '') === []);
$unknown = array_filter($artifacts, static fn ($a) => !in_array($a['licence'], $known, true));
check('every artifact with a known licence', $unknown === [], implode(', ', array_map(static fn ($a) => $a['group'] . ':' . $a['name'], $unknown)));

$listed = [];
foreach ($sections as $s) {
    if (str_starts_with($s['title'], 'Android-app · ')) {
        foreach ($s['points'] as $p) {
            $listed[] = $p;
        }
    }
}
$expected = array_map(static fn ($a) => $a['group'] . ':' . $a['name'] . ' ' . $a['version'], $artifacts);
sort($listed);
sort($expected);
check('every artifact shown once, under its project', $listed === $expected, count($listed) . ' of ' . count($expected));

$texts = array_column(array_filter($sections, static fn ($s) => $s['licence'] !== null), 'licence', 'title');
$used  = array_unique(array_column($artifacts, 'licence'));
check('Apache 2.0 in full', isset($texts['Licentieteksten · Apache License 2.0']) && str_contains($texts['Licentieteksten · Apache License 2.0'], 'TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION'));
check('LGPL 2.1 in full (PHPMailer\'s own copy)', isset($texts['Licentieteksten · GNU Lesser General Public License 2.1']) && str_contains($texts['Licentieteksten · GNU Lesser General Public License 2.1'], 'GNU LESSER GENERAL PUBLIC LICENSE'));
foreach (['BSD-3-Clause' => 'Redistribution and use in source and binary forms', 'MIT' => 'Permission is hereby granted, free of charge'] as $id => $phrase) {
    if (in_array($id, $used, true)) {
        $name = legal_licence_names()[$id];
        check("$name in full", str_contains($texts['Licentieteksten · ' . $name] ?? '', $phrase));
    }
}
if (in_array('Android-SDK-License', $used, true)) {
    $sdk = array_values(array_filter($sections, static fn ($s) => str_ends_with($s['title'], 'Android Software Development Kit License')));
    check('the Android SDK License, by link', count($sdk) === 1 && $sdk[0]['link']['href'] === 'https://developer.android.com/studio/terms');
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * The canonical-address rule, in PHP and in the database, checked against
 * each other (docs/ACCOUNT-SECURITY.md §5a).
 *
 *     DB_USER=root php tools/canonical-check.php
 *
 * Every CHECK constraint and REGEXP literal is read VERBATIM from
 * database/migrations/027-account-security.sql — nothing is retyped here —
 * and the source must hold exactly one pattern, with no backslash. The
 * constraints are created in a THROWAWAY database (created and dropped by
 * this script; migration 027 itself is not run), in the default sql_mode and
 * under NO_BACKSLASH_ESCAPES, and:
 *
 *   STORED  the literal the server stored must equal the file's, byte for byte;
 *   A       what people type, through the PHP normaliser (account_email_canonical());
 *   B       direct INSERTs of stored values into every constrained column,
 *           which must equal the PHP stored-value rule (account_email_storable());
 *   C       every PHP output is accepted.
 *
 * Exit code 0 when everything agrees. Never point it at a live server's
 * credentials it should not create databases with.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/account-security.php';


require_once __DIR__ . '/sql-source.php';

function canonical(string $raw): ?string { return account_email_canonical($raw); }
function storable(string $v): bool { return account_email_storable($v); }

$ok = true;
$fail = static function (string $why) use (&$ok): void { $ok = false; echo "  FAIL: $why\n"; };

echo "SOURCE: ", basename(SQL_SOURCE), " (sha256 ", substr(hash_file('sha256', SQL_SOURCE), 0, 16), "…)\n";
$literals = source_regex_literals();
foreach ($literals as $l) { printf("  line %3d  REGEXP '%s'  hex %s\n", $l['line'], $l['literal'], hex_bytes($l['literal'])); }
$pattern = source_pattern();
printf("  one pattern in the whole source: '%s' (%d bytes, hex %s)\n", $pattern, strlen($pattern), hex_bytes($pattern));
if (str_contains($pattern, '\\')) { $fail('the source pattern contains a backslash'); }

/* Everywhere else the pattern is written down must say the same: the fresh
   schema and the documentation. */
foreach (['database/schema.sql', 'docs/ACCOUNT-SECURITY.md'] as $copy) {
    $text = (string) file_get_contents(dirname(__DIR__) . '/' . $copy);
    preg_match_all("/REGEXP '([^']*)'/", $text, $cm);
    $same = $cm[1] !== [] && count(array_filter($cm[1], static fn ($l) => $l !== $pattern)) === 0;
    printf("  %-28s %d mention(s) %s\n", $copy, count($cm[1]), $same ? '== source' : 'DIFFER');
    if (!$same) { $fail("$copy does not use the source pattern"); }
}
if (!str_contains((string) file_get_contents(dirname(__DIR__) . '/docs/ACCOUNT-SECURITY.md'), hex_bytes($pattern))) {
    $fail('docs/ACCOUNT-SECURITY.md does not give the bytes ' . hex_bytes($pattern));
}

$checks = array_filter(source_checks(), static fn ($k) => str_ends_with($k, '_canonical'), ARRAY_FILTER_USE_KEY);
echo "  canonical CHECK constraints in the source: ", implode(', ', array_keys($checks)), "\n";

$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';charset=utf8mb4', (string) (getenv('DB_USER') ?: 'root'), (string) (getenv('DB_PASS') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS ownify_scratch_chk');
$pdo->exec('CREATE DATABASE ownify_scratch_chk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE ownify_scratch_chk');
echo "\nServer: ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n";

/* One table per (constraint, sql_mode), with the columns the clause names and the clause verbatim. */
$tables = [];
foreach (['' => 'def', 'NO_BACKSLASH_ESCAPES' => 'nobs'] as $mode => $tag) {
    $pdo->exec("SET SESSION sql_mode = '$mode'");
    foreach ($checks as $name => $clause) {
        preg_match_all('/`([a-z_]+)`/', $clause, $cm);
        $cols = array_values(array_unique($cm[1]));
        $t = "{$name}_{$tag}";
        $defs = implode(', ', array_map(static fn ($c) => "`$c` VARCHAR(191) NOT NULL", $cols));
        $pdo->exec("CREATE TABLE `$t` ($defs, CONSTRAINT `c_$t` CHECK ($clause)) COLLATE utf8mb4_unicode_ci");
        $tables[$t] = $cols;
    }
}
$pdo->exec('SET SESSION sql_mode = DEFAULT');

echo "\nSTORED: each constraint as the server stored it, its REGEXP literal(s) compared byte for byte with the SOURCE pattern\n";
foreach (array_keys($tables) as $t) {
    $cc = (string) $pdo->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'ownify_scratch_chk' AND TABLE_NAME = '$t'")->fetchColumn();
    preg_match_all("/regexp '([^']*)'/i", $cc, $sm);
    $same = $sm[1] !== [] && count(array_filter($sm[1], static fn ($s) => $s !== $pattern)) === 0;
    printf("  %-34s %d literal(s), hex %s  %s\n", $t, count($sm[1]), hex_bytes($sm[1][0] ?? ''), $same ? '== source' : 'DIFFERS');
    if (!$same) { $fail("$t stored a different literal"); }
    if (str_contains($cc, '\\')) { $fail("$t stored a backslash"); }
}

$inputs = [
    'sanne@example.com', 'Sanne@Example.com', 'SANNE@EXAMPLE.COM', ' sanne@example.com', "sanne@example.com \n",
    "\tSanne@Example.com", "\u{00A0}sanne@example.com\u{2003}", "sanne@example.com\r\n",
    'san ne@example.com', "sanne@exa\tmple.com", "sanne@exa\0mple.com", 'sänne@example.com', 'sanne@exämple.com',
    'İsanne@example.com', 'sanne+tag@example.com', 'san.ne@gmail.com', 'not-an-address', '',
];

echo "\nA. USER INPUT -> PHP normaliser\n";
foreach ($inputs as $in) {
    $c = canonical($in);
    printf("  %-32s -> %s\n", json_encode($in, JSON_UNESCAPED_UNICODE), $c === null ? 'REFUSED' : json_encode($c));
}

/* Insert $v into one column of a table; the other columns get a valid value. */
$try = static function (string $t, array $cols, string $col, string $v) use ($pdo): bool {
    $vals = array_map(static fn ($c) => $c === $col ? $v : 'ok@example.com', $cols);
    $sql = "INSERT INTO `$t` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    try { $pdo->prepare($sql)->execute($vals); $pdo->exec("DELETE FROM `$t`"); return true; }
    catch (PDOException $e) { return false; }
};
$stored = array_merge($inputs, ["sanne@example.com\n", "sanne@example.com\r", 'sanne@example.com ', 'sanne@example.coM', 'a~b@example.com', 'a\\b@example.com']);

echo "\nB. DIRECT INSERT of stored values, every column of every canonical CHECK, both sql_modes\n";
$inserts = 0;
foreach ($stored as $v) {
    $php = storable($v);
    $res = [];
    foreach ($tables as $t => $cols) {
        foreach ($cols as $col) {
            $r = $try($t, $cols, $col, $v); $inserts++;
            $res[] = $r;
            if ($r !== $php) { $fail("$t.$col " . json_encode($v) . ' ' . ($r ? 'accepted' : 'refused')); }
        }
    }
    $acc = count(array_filter($res));
    printf("  %-34s storable=%-3s accepted by %d of %d columns\n", json_encode($v, JSON_UNESCAPED_UNICODE), $php ? 'yes' : 'no', $acc, count($res));
}
echo "  ($inserts direct inserts)\n";

echo "\nC. AGREEMENT\n";
foreach ($inputs as $in) {
    $c = canonical($in);
    if ($c === null) { continue; }
    if (!storable($c)) { $fail('PHP output not storable ' . json_encode($c)); }
    foreach ($tables as $t => $cols) { foreach ($cols as $col) { if (!$try($t, $cols, $col, $c)) { $fail("$t.$col refused PHP output " . json_encode($c)); } } }
}
$pdo->exec('DROP DATABASE ownify_scratch_chk');
echo "\nRESULT: ", $ok ? 'PASS' : 'FAIL', " — every canonical CHECK, taken verbatim from the source, equals the PHP stored-value rule in both sql_modes, and stores the source pattern unchanged\n";
echo "throwaway database dropped\n";
exit($ok ? 0 : 1);

<?php
/**
 * Reads the CHECK constraints and REGEXP literals straight from
 * database/migrations/027-account-security.sql, so the checks that use them
 * (tools/canonical-check.php, tools/check-config.php --check-schema-rules)
 * test the migration's own text, never a copy typed elsewhere.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('SQL_SOURCE')) {
    define('SQL_SOURCE', dirname(__DIR__) . '/database/migrations/027-account-security.sql');
}

/** Every CHECK constraint in the source: name => the clause inside CHECK ( … ), verbatim. */
if (!function_exists('source_checks')) {

function source_checks(): array
{
    $sql = (string) file_get_contents(SQL_SOURCE);
    preg_match_all('/CONSTRAINT `([a-z_]+)` CHECK \(/', $sql, $m, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m[0] as $i => [$text, $at]) {
        $start = $at + strlen($text);
        $depth = 1; $quoted = false;
        for ($p = $start; $p < strlen($sql); $p++) {
            $ch = $sql[$p];
            if ($ch === "'") { $quoted = !$quoted; continue; }
            if ($quoted) { continue; }
            if ($ch === '(') { $depth++; }
            if ($ch === ')' && --$depth === 0) { break; }
        }
        $out[$m[1][$i][0]] = substr($sql, $start, $p - $start);
    }
    return $out;
}

/** Every REGEXP string literal in the WHOLE source (constraints and detection queries), with its line. */
function source_regex_literals(): array
{
    $out = [];
    foreach (file(SQL_SOURCE) as $n => $line) {
        if (str_starts_with(ltrim($line), '--')) { continue; }   // comments document, they don't run
        if (preg_match_all("/REGEXP '([^']*)'/", $line, $m)) {
            foreach ($m[1] as $lit) { $out[] = ['line' => $n + 1, 'literal' => $lit]; }
        }
    }
    return $out;
}

/** The one pattern the source uses; stops if the source uses more than one. */
function source_pattern(): string
{
    $all = array_unique(array_column(source_regex_literals(), 'literal'));
    if (count($all) !== 1) { fwrite(STDERR, 'The SQL source uses ' . count($all) . " different REGEXP literals\n"); exit(1); }
    return reset($all);
}

function hex_bytes(string $s): string
{
    return strtoupper(implode(' ', str_split(bin2hex($s), 2)));
}

}

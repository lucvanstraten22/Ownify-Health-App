<?php
/**
 * The English catalogue, everywhere it is needed (docs/I18N.md).
 *
 *     php tools/i18n-build.php           write the two generated files
 *     php tools/i18n-build.php --check   change nothing; exit 1 when a file is
 *                                        out of date or a string is missing
 *
 * lang/en.php is the one catalogue: English, keyed by the Dutch it
 * translates. The server reads it whole. The website's scripts and the
 * Android app only need the strings they write themselves, so they get a
 * generated subset:
 *
 *   assets/js/lang-en.js                          OwnifyI18n.t/tf/tn('…') in assets/js/
 *   OwnifyAndroid/app/src/main/assets/lang-en.json   tr/trf/trn("…") in the Kotlin
 *
 * The check also lists every string the code asks for — t()/tf()/tn() in
 * PHP, the scripts' and the app's calls — that has no English yet, and every
 * entry whose placeholders (%s, %d, %1$s) differ from its Dutch.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root    = dirname(__DIR__);
$check   = in_array('--check', $argv, true);
$catalog = (array) require $root . '/lang/en.php';

/* ------------------------------------------------------------- scanning */

/** Every file under $dir with one of $extensions. */
function i18n_files(string $dir, array $extensions): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array(strtolower($file->getExtension()), $extensions, true)) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/**
 * The Dutch strings a PHP file translates: the literal arguments of t(),
 * tf() (first) and tn() (second and third).
 *
 * @return array<string, string>  string => "file:line"
 */
function i18n_php_keys(string $path, string $root): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $keys   = [];
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        /* 'error' => '…' and 'message' => '…': what api_json() translates
           on the way out (api/bootstrap.php). */
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && in_array($token[1], ["'error'", "'message'"], true)) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                $j++;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $next = $tokens[$j + 1] ?? null;
                while (is_array($next) && $next[0] === T_WHITESPACE) {
                    $next = $tokens[++$j + 1] ?? null;
                }
                if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && in_array($next, [',', ']', ')'], true)) {
                    $value = eval('return ' . $tokens[$j][1] . ';');
                    if (is_string($value) && preg_match('/\p{L}/u', $value)) {
                        $keys[$value] ??= substr($path, strlen($root) + 1) . ':' . $tokens[$j][2];
                    }
                }
            }
            continue;
        }

        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['t', 'tf', 'tn', 'api_fail'], true)) {
            continue;
        }
        $before = $i - 1;
        while ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
            $before--;
        }
        if ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;                                       // a definition or a method of the same name
        }

        /* The arguments, split at depth-one commas. */
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $args  = [[]];
        $depth = 0;
        for ($j++; $j < $count; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($tok === ',' && $depth === 0) {
                $args[] = [];
                continue;
            }
            if (!(is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT], true))) {
                $args[count($args) - 1][] = $tok;
            }
        }

        /* api_fail('…'): api_json() translates its message on the way out. */
        $wanted = $token[1] === 'tn' ? [1, 2] : [0];
        foreach ($wanted as $n) {
            $arg = $args[$n] ?? [];
            if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = eval('return ' . $arg[0][1] . ';');
                if (is_string($value) && $value !== '') {
                    $keys[$value] ??= substr($path, strlen($root) + 1) . ':' . $token[2];
                }
            }
        }
    }

    return $keys;
}

/**
 * The Dutch strings a script or a Kotlin file translates. Scripts call
 * T.t('…'), T.tf('…', …) and T.tn(n, '…', '…') on OwnifyI18n; Kotlin calls
 * tr("…"), trf("…", …) and trn(n, "…", "…").
 *
 * @return array<string, string>
 */
function i18n_source_keys(string $path, string $root, bool $kotlin): array
{
    /* Block comments out, their lines kept, so an example in one is not a call. */
    $source = (string) preg_replace_callback('#/\*.*?\*/#s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        (string) file_get_contents($path));
    $keys   = [];
    $quote  = $kotlin ? '"((?:[^"\\\\$]|\\\\.|\$(?![{A-Za-z]))*)"' : "'((?:[^'\\\\]|\\\\.)*)'";
    $one    = $kotlin ? '\btrf?\(\s*' : '\b(?:OwnifyI18n|T|I18n)\.tf?\(\s*';
    $many   = $kotlin ? '\btrn\([^,()]*(?:\([^()]*\))?[^,()]*,\s*' : '\b(?:OwnifyI18n|T|I18n)\.tn\([^,()]*(?:\([^()]*\))?[^,()]*,\s*';
    $unescape = static fn (string $s): string => $kotlin
        ? str_replace(['\\"', '\\\\', '\\n', '\\$'], ['"', '\\', "\n", '$'], $s)
        : str_replace(["\\'", '\\\\', '\\n'], ["'", '\\', "\n"], $s);
    $where = static function (int $offset) use ($source, $path, $root): string {
        return substr($path, strlen($root) + 1) . ':' . (substr_count($source, "\n", 0, $offset) + 1);
    };

    if (preg_match_all('/' . $one . $quote . '/', $source, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$text, $offset]) {
            $keys[$unescape($text)] ??= $where($offset);
        }
    }
    if (preg_match_all('/' . $many . $quote . '\s*,\s*' . $quote . '/', $source, $m, PREG_OFFSET_CAPTURE)) {
        foreach ([1, 2] as $group) {
            foreach ($m[$group] as [$text, $offset]) {
                $keys[$unescape($text)] ??= $where($offset);
            }
        }
    }

    return $keys;
}

/**
 * Calls whose first argument is not a literal: T.t(x ? 'a' : 'b'),
 * tr(variable). Nothing can find their English, so the generated subsets
 * would leave them Dutch — the check names them instead.
 *
 * @return list<string>  "file:line  code"
 */
function i18n_dynamic_calls(string $path, string $root, bool $kotlin): array
{
    $source = (string) preg_replace_callback('#/\*.*?\*/#s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        (string) file_get_contents($path));
    $pattern = $kotlin ? '/(?<![\w.])trf?\(\s*(?!")/' : "/\\b(?:OwnifyI18n|T|I18n)\\.tf?\\(\\s*(?!')/";
    $found = [];
    foreach (explode("\n", $source) as $n => $line) {
        $code = (string) preg_replace('#(^|\s)//.*$#', '', $line);
        if (preg_match($pattern, $code) && !preg_match('/\bfun\s+trf?\(/', $code)) {
            $found[] = substr($path, strlen($root) + 1) . ':' . ($n + 1) . '  ' . trim($code);
        }
    }
    return $found;
}

/** The placeholders of a sprintf string, in order: %s %d %1$s %%. */
function i18n_placeholders(string $text): array
{
    preg_match_all('/%(?:\d+\$)?(?:\.\d+)?[sdf]/', $text, $m);
    $found = $m[0];
    sort($found);
    return $found;
}

$php = [];
foreach (['includes', 'lib', 'api', 'pages', 'components', 'config'] as $dir) {
    foreach (i18n_files($root . '/' . $dir, ['php']) as $file) {
        $php += i18n_php_keys($file, $root);
    }
}
foreach (['index.php'] as $file) {
    $php += i18n_php_keys($root . '/' . $file, $root);
}

$js      = [];
$dynamic = [];
foreach (i18n_files($root . '/assets/js', ['js']) as $file) {
    if (basename($file) !== 'lang-en.js') {
        $js += i18n_source_keys($file, $root, false);
        array_push($dynamic, ...i18n_dynamic_calls($file, $root, false));
    }
}

$kotlin = [];
foreach (i18n_files($root . '/OwnifyAndroid/app/src/main/java', ['kt']) as $file) {
    $kotlin += i18n_source_keys($file, $root, true);
    if (basename($file) !== 'OwnifyI18n.kt') {                  // where tr() itself is written
        array_push($dynamic, ...i18n_dynamic_calls($file, $root, true));
    }
}

/* ------------------------------------------------------------ generating */

$subset = static function (array $keys) use ($catalog): array {
    $out = [];
    foreach (array_keys($keys) as $key) {
        if (isset($catalog[$key])) {
            $out[$key] = $catalog[$key];
        }
    }
    ksort($out, SORT_STRING);
    return $out;
};

$json = static fn (array $map): string => json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

$files = [
    'assets/js/lang-en.js' =>
        "/* Generated by tools/i18n-build.php from lang/en.php — do not edit. The\n"
        . "   English for the strings the scripts write themselves (assets/js/i18n.js). */\n"
        . 'window.OWNIFY_LANG = ' . $json($subset($js)) . ";\n",
    'OwnifyAndroid/app/src/main/assets/lang-en.json' => $json($subset($kotlin)) . "\n",
];

$stale = [];
foreach ($files as $relative => $content) {
    $path = $root . '/' . $relative;
    if ((string) @file_get_contents($path) !== $content) {
        $stale[] = $relative;
        if (!$check) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $content);
        }
    }
}

/* -------------------------------------------------------------- reporting */

$problems = 0;

foreach (['PHP' => $php, 'scripts' => $js, 'Android' => $kotlin] as $where => $keys) {
    $missing = array_diff_key($keys, $catalog);
    if ($missing !== []) {
        $problems += count($missing);
        echo "\nNo English yet ($where):\n";
        foreach ($missing as $key => $at) {
            echo '  ', $at, '  ', json_encode($key, JSON_UNESCAPED_UNICODE), "\n";
        }
    }
}

if ($dynamic !== []) {
    $problems += count($dynamic);
    echo "\nNot a literal, so no English can be found for it (write the Dutch in the call):\n";
    foreach ($dynamic as $at) {
        echo '  ', $at, "\n";
    }
}

foreach ($catalog as $dutch => $english) {
    if (!is_string($english) || $english === '') {
        $problems++;
        echo 'Empty English for ', json_encode($dutch, JSON_UNESCAPED_UNICODE), "\n";
    } elseif (i18n_placeholders((string) $dutch) !== i18n_placeholders($english)) {
        $problems++;
        echo 'Placeholders differ: ', json_encode($dutch, JSON_UNESCAPED_UNICODE), ' → ', json_encode($english, JSON_UNESCAPED_UNICODE), "\n";
    }
}

if ($check && $stale !== []) {
    $problems += count($stale);
    echo "\nOut of date (run php tools/i18n-build.php): ", implode(', ', $stale), "\n";
}

printf(
    "\n%d entries · PHP asks for %d, the scripts %d, the app %d%s\n",
    count($catalog), count($php), count($js), count($kotlin),
    $check ? '' : ($stale === [] ? ' · generated files unchanged' : ' · wrote ' . implode(', ', $stale))
);

exit($check && $problems > 0 ? 1 : 0);

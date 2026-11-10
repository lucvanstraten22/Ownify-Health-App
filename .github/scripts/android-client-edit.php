<?php
/**
 * Adds one Android OAuth client id to google.android_client_ids in a copy of
 * config/auth.local.php (.github/scripts/android-client-add.sh). Prints no
 * value from the file — only counts and what it did.
 *
 *   php android-client-edit.php <current file> <new file> <client id>
 *
 * The file is edited as text, so its comments and everything else in it stay
 * exactly as they are; then both copies are loaded and compared: the new one
 * must be the old one with this id added to android_client_ids and nothing
 * else different. Exit 0 changed, 3 already there, 1 anything else.
 */
declare(strict_types=1);

[$self, $current, $next, $id] = $argv + [null, null, null, null];
$fail = static function (string $why): never { fwrite(STDERR, $why . "\n"); exit(1); };

if (!is_string($id) || !preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id)) {
    $fail('The client id is not an OAuth client id (…apps.googleusercontent.com).');
}

$load = static function (string $file) use ($fail): array {
    $value = (static fn () => require $file)();
    if (!is_array($value) || !is_array($value['google'] ?? null)) {
        $fail('auth.local.php does not return an array with a google block.');
    }
    return $value;
};
$ids = static fn (array $c): array => array_values(array_map('trim', (array) ($c['google']['android_client_ids'] ?? [])));

$before = $load($current);
if (in_array($id, $ids($before), true)) {
    echo "already there: " . count($ids($before)) . " Android client id(s), nothing changed\n";
    exit(3);
}
if (($before['google']['client_id'] ?? '') === $id) {
    $fail('That id is the Web client\'s own.');
}

$text = (string) file_get_contents($current);
$list = '/([\'"])android_client_ids\1\s*=>\s*(\[|array\s*\()/';
$block = '/([\'"])google\1\s*=>\s*(\[|array\s*\()/';
$quoted = var_export($id, true);

if (preg_match_all($list, $text) === 1) {
    $text = preg_replace($list, '$0' . $quoted . ', ', $text, 1);
} elseif (preg_match_all($list, $text) === 0 && preg_match_all($block, $text) === 1) {
    $text = preg_replace($block, '$0' . "\n        'android_client_ids' => [" . $quoted . "],", $text, 1);
} else {
    $fail('Could not find exactly one place for android_client_ids — changed nothing.');
}
file_put_contents($next, $text);

exec('php -l ' . escapeshellarg($next) . ' 2>&1', $lint, $status);
if ($status !== 0) {
    $fail('The edited copy is not valid PHP — changed nothing.');
}

$after = $load($next);
$strip = static function (array $c): array { unset($c['google']['android_client_ids']); return $c; };
if ($strip($before) !== $strip($after)) {
    $fail('The edited copy differs in more than android_client_ids — changed nothing.');
}
if (array_values(array_diff($ids($after), $ids($before))) !== [$id] || array_diff($ids($before), $ids($after)) !== []) {
    $fail('The edited copy does not hold exactly the old ids plus the new one — changed nothing.');
}

printf("edited: %d Android client id(s) before, %d after; every other value identical\n", count($ids($before)), count($ids($after)));
exit(0);

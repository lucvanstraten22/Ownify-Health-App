<?php
/**
 * Migration 028 and its rollback, on a THROWAWAY database this script makes
 * and drops (named ownify_m028_<random>) — never on an existing one.
 *
 *     DB_USER=root php tools/migration-028-test.php
 *
 * Checks:
 *   - schema.sql (a fresh install) has the registry, the insight tables and
 *     the consent columns, and no Apple Health row;
 *   - the rollback takes it back to before 028 (nobody answered the consent),
 *     Apple Health row included;
 *   - 028 over that: every piece 1, the registry of config/research.php
 *     (since 31.0 its seed carries the sources approved later too), and a
 *     second import changes nothing;
 *   - the Apple Health row stays while a record refers to it, and goes once
 *     nothing does;
 *   - tools/research-registry.php --check agrees with the database, finds a
 *     claim whose content changed, and --sync refuses that change without a
 *     new version;
 *   - deleting an account deletes its insights; a claim in use cannot be
 *     deleted;
 *   - the rollback refuses once somebody answered the insight consent.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$name = 'ownify_m028_' . bin2hex(random_bytes(3));
$user = (string) (getenv('DB_USER') ?: 'root');
$pass = (string) (getenv('DB_PASS') ?: '');
$host = (string) (getenv('DB_HOST') ?: '127.0.0.1');

$ok = 0;
$bad = 0;
function check(string $label, bool $good, string $detail = ''): void
{
    global $ok, $bad;
    $good ? $ok++ : $bad++;
    printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $label, $good || $detail === '' ? '' : "\n          " . $detail);
}

/** Runs a .sql file the way the command line imports it; returns [exit code, output]. */
function import(string $file): array
{
    global $name, $user, $pass, $host;
    $cmd = sprintf('mysql -h%s -u%s %s %s < %s 2>&1', escapeshellarg($host), escapeshellarg($user),
        $pass === '' ? '' : '-p' . escapeshellarg($pass), escapeshellarg($name), escapeshellarg($file));
    exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
}

/** The registry tool against the throwaway database. */
function registry(string $mode): array
{
    global $name, $user, $pass, $host, $root;
    $env = sprintf('DB_HOST=%s DB_NAME=%s DB_USER=%s%s', escapeshellarg($host), escapeshellarg($name), escapeshellarg($user),
        $pass === '' ? '' : ' DB_PASSWORD=' . escapeshellarg($pass));
    exec($env . ' php ' . escapeshellarg($root . '/tools/research-registry.php') . ' ' . $mode . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

$server = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$val = static fn (string $sql, array $p = []) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
$tables = static fn () => (int) $val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
    AND table_name IN ('research_sources', 'research_claims', 'insight_days', 'user_insights')");
$snapshot = static fn () => json_encode([
    $db->query('SELECT code, status, content_hash FROM research_sources ORDER BY code')->fetchAll(),
    $db->query('SELECT id, claim_key, version, status, content_hash FROM research_claims ORDER BY id')->fetchAll(),
    $db->query('SELECT code FROM data_sources ORDER BY id')->fetchAll(),
]);

echo "\nMigration 028, on $name\n" . str_repeat('-', 72) . "\n";

try {
    [$code, $out] = import($root . '/database/schema.sql');
    check('schema.sql imports', $code === 0, $out);
    check('a fresh install has the four tables', $tables() === 4);
    $registry = require $root . '/config/research.php';
    check('… the registry: every source and claim of config/research.php (' . count($registry['sources']) . ', ' . count($registry['claims']) . ')',
        (int) $val('SELECT COUNT(*) FROM research_sources') === count($registry['sources'])
        && (int) $val('SELECT COUNT(*) FROM research_claims') === count($registry['claims']));
    check('… the consent columns', (int) $val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'user_profiles' AND column_name LIKE 'ai_insights_consent%'") === 3);
    check('… and no Apple Health', (int) $val("SELECT COUNT(*) FROM data_sources WHERE code = 'apple_health'") === 0);
    $config = require $root . '/config/research.php';
    check('the registry\'s text arrives as written, also from a latin1 client (SET NAMES)',
        $val("SELECT citation FROM research_sources WHERE code = 'SLP-02'") === $config['sources']['SLP-02']['citation']
        && $val("SELECT findings FROM research_sources WHERE code = 'NUT-01'") === $config['sources']['NUT-01']['findings']);

    [$code, $out] = import($root . '/database/migrations/028-rollback.sql');
    check('the rollback takes a fresh install back to before 028', $code === 0 && $tables() === 0, $out);
    check('… Apple Health row back, consent columns gone', (int) $val("SELECT COUNT(*) FROM data_sources WHERE code = 'apple_health'") === 1
        && (int) $val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
            AND table_name = 'user_profiles' AND column_name LIKE 'ai_insights_consent%'") === 0);

    /* A record that came from Apple Health (it never could, but the guard must hold). */
    $db->exec("INSERT INTO users (username) VALUES ('ann')");
    $ann = (int) $db->lastInsertId();
    $db->exec("INSERT INTO user_profiles (user_id) VALUES ($ann)");
    $apple = (int) $val("SELECT id FROM data_sources WHERE code = 'apple_health'");
    $type  = (int) $val("SELECT id FROM health_metric_types WHERE code = 'steps'");
    $db->prepare('INSERT INTO health_metrics (user_id, metric_type_id, source_id, value, recorded_at) VALUES (?, ?, ?, 1000, NOW())')
        ->execute([$ann, $type, $apple]);

    [$code, $out] = import($root . '/database/migrations/028-research-insights.sql');
    check('028 imports', $code === 0, $out);
    $last = trim((string) substr($out, (int) strrpos($out, "\n")));
    check('the final query: every piece 1', preg_match('/^(1\s+){6}1$/', $last) === 1, $last);
    check('the Apple Health row stays while a record refers to it', (int) $val("SELECT COUNT(*) FROM data_sources WHERE code = 'apple_health'") === 1);

    $first = $snapshot();
    [$code, $out] = import($root . '/database/migrations/028-research-insights.sql');
    check('a second import runs', $code === 0, $out);
    check('… and changes nothing', $snapshot() === $first);

    $db->exec("DELETE FROM health_metrics WHERE source_id = $apple");
    [$code, $out] = import($root . '/database/migrations/028-research-insights.sql');
    check('with nothing referring to it, the Apple Health row goes', $code === 0
        && (int) $val("SELECT COUNT(*) FROM data_sources WHERE code = 'apple_health'") === 0, $out);

    [$code, $out] = registry('--check');
    check('research-registry --check: the database holds the reviewed registry', $code === 0, $out);
    [$code, $out] = registry('--sync');
    check('research-registry --sync: nothing to change', $code === 0 && str_contains($out, 'already holds'), $out);

    /* A claim whose content changed in the database without a new version. */
    $db->exec("UPDATE research_claims SET content_hash = REPEAT('0', 64) WHERE claim_key = 'nutrition.salt'");
    [$code, $out] = registry('--check');
    check('--check finds a claim whose content differs', $code === 1 && str_contains($out, 'nutrition.salt'), $out);
    [$code, $out] = registry('--sync');
    check('--sync refuses a change without a new version, and writes nothing', $code === 1
        && str_contains($out, 'without a new version')
        && $val("SELECT content_hash FROM research_claims WHERE claim_key = 'nutrition.salt'") === str_repeat('0', 64), $out);
    $row = null;
    foreach ((require $root . '/config/research.php')['claims'] as $key => $claim) {
        if ($key === 'nutrition.salt') {
            require_once $root . '/includes/research.php';
            $row = research_claim_row($key, $claim, (require $root . '/config/research.php')['sources']);
        }
    }
    $db->prepare("UPDATE research_claims SET content_hash = ? WHERE claim_key = 'nutrition.salt'")->execute([$row['content_hash']]);
    [$code] = registry('--check');
    check('… and agrees again once it is back', $code === 0);

    /* Insights hang off the account and point at a claim version. */
    $claim = (int) $val("SELECT id FROM research_claims WHERE claim_key = 'sleep.duration.adults'");
    $db->prepare("INSERT INTO insight_days (user_id, local_date, state) VALUES (?, CURDATE(), 'done')")->execute([$ann]);
    $db->prepare("INSERT INTO user_insights (user_id, local_date, slot, rule_key, outcome, claim_id, strength, applicability, facts_json, fingerprint, valid_until)
                  VALUES (?, CURDATE(), 'sleep', 'sleep.duration', 'below', ?, 'betrouwbaar', 'direct', '{}', REPEAT('a', 64), NOW())")->execute([$ann, $claim]);
    $refused = false;
    try {
        $db->exec("DELETE FROM research_claims WHERE id = $claim");
    } catch (PDOException $e) {
        $refused = true;
    }
    check('a claim an insight used cannot be deleted', $refused);
    $dup = false;
    try {
        $db->prepare("INSERT INTO user_insights (user_id, local_date, slot, rule_key, outcome, claim_id, strength, applicability, facts_json, fingerprint, valid_until)
                      VALUES (?, CURDATE(), 'sleep', 'sleep.regularity', 'steady', ?, 'betrouwbaar', 'direct', '{}', REPEAT('b', 64), NOW())")->execute([$ann, $claim]);
    } catch (PDOException $e) {
        $dup = true;
    }
    check('one insight per slot per day: a second one is refused', $dup);

    /* The rollback refuses once somebody answered. */
    $db->prepare('UPDATE user_profiles SET ai_insights_consent = 0, ai_insights_consent_version = ?, ai_insights_consent_at = NOW() WHERE user_id = ?')
        ->execute(['test', $ann]);
    [$code, $out] = import($root . '/database/migrations/028-rollback.sql');
    check('the rollback refuses once somebody answered the consent', $code !== 0 && str_contains($out, 'insight-wording consent') && $tables() === 4, $out);

    $db->exec("DELETE FROM users WHERE id = $ann");
    check('deleting the account deletes its insights and its days', (int) $val('SELECT COUNT(*) FROM user_insights') === 0
        && (int) $val('SELECT COUNT(*) FROM insight_days') === 0);

    [$code, $out] = import($root . '/database/migrations/028-rollback.sql');
    check('with nobody left who answered, the rollback runs', $code === 0 && $tables() === 0, $out);
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}

printf("\n%d passed, %d failed\n", $ok, $bad);
exit($bad === 0 ? 0 : 1);

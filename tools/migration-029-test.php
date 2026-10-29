<?php
/**
 * Migration 029 and its rollback, on a THROWAWAY database this script makes
 * and drops (named ownify_m029_<random>) — never on an existing one. It
 * starts from the schema as 30.1 shipped it (git: 45ccb1c), the state a live
 * server is in, with a person and an insight that uses a claim 029 replaces.
 *
 *     DB_USER=root php tools/migration-029-test.php
 *
 * Checks:
 *   - 029 imports over 30.1: the two tables, the caffeine metric type, every
 *     source and claim of config/research.php, and the three claims whose
 *     meaning changed have their older version retired — not deleted, so an
 *     insight stored with it still shows its research;
 *   - a second import changes nothing;
 *   - tools/research-registry.php --check agrees with the database;
 *   - deleting an account deletes its runs and results;
 *   - the rollback drops the two tables, keeps the caffeine type while a
 *     reading uses it and drops it once none does, and 029 imports again.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$name = 'ownify_m029_' . bin2hex(random_bytes(3));
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

/* The schema as 30.1 shipped it: what a live server has before 029. */
$before = tempnam(sys_get_temp_dir(), 'ownify-301-');
exec('git -C ' . escapeshellarg($root) . ' show 45ccb1c:database/schema.sql > ' . escapeshellarg($before), $o, $gitCode);
if ($gitCode !== 0 || filesize($before) < 1000) {
    fwrite(STDERR, "The 30.1 schema (git 45ccb1c) is not available in this checkout.\n");
    exit(2);
}

$server = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$val = static fn (string $sql, array $p = []) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
$tables = static fn () => (int) $val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
    AND table_name IN ('correlation_runs', 'user_correlations')");
$caffeine = static fn () => (int) $val("SELECT COUNT(*) FROM health_metric_types WHERE code = 'caffeine'");
$snapshot = static fn () => json_encode([
    $db->query('SELECT code, status, content_hash FROM research_sources ORDER BY code')->fetchAll(),
    $db->query('SELECT id, claim_key, version, status, content_hash FROM research_claims ORDER BY id')->fetchAll(),
    $db->query('SELECT code FROM health_metric_types ORDER BY id')->fetchAll(),
]);
$config = require $root . '/config/research.php';
$changed = ['activity.who.adults', 'nutrition.protein.exercise', 'nutrition.pattern'];

echo "\nMigration 029, on $name\n" . str_repeat('-', 72) . "\n";

try {
    [$code, $out] = import($before);
    check('the 30.1 schema imports (the live state before 029)', $code === 0 && $tables() === 0 && $caffeine() === 0, $out);
    check('… with the 30.1 registry: 17 sources, 25 claims', (int) $val('SELECT COUNT(*) FROM research_sources') === 17
        && (int) $val('SELECT COUNT(*) FROM research_claims') === 25);

    /* A person with an insight that used a claim 029 replaces. */
    $db->exec("INSERT INTO users (username) VALUES ('ann')");
    $ann = (int) $db->lastInsertId();
    $old = (int) $val("SELECT id FROM research_claims WHERE claim_key = 'activity.who.adults' AND version = 1");
    $db->prepare("INSERT INTO user_insights (user_id, local_date, slot, rule_key, outcome, claim_id, strength, applicability, facts_json, fingerprint, valid_until)
                  VALUES (?, CURDATE(), 'training', 'activity.minutes', 'below', ?, 'betrouwbaar', 'proxy', '{}', REPEAT('a', 64), NOW())")->execute([$ann, $old]);

    [$code, $out] = import($root . '/database/migrations/029-correlations.sql');
    check('029 imports over 30.1', $code === 0, $out);
    check('… the two tables and the caffeine metric type (mg, summed per day)', $tables() === 2 && $caffeine() === 1
        && $val("SELECT CONCAT(unit, '/', aggregation, '/', domain) FROM health_metric_types WHERE code = 'caffeine'") === 'mg/sum/nutrition');
    check('… every source of config/research.php (' . count($config['sources']) . ')',
        (int) $val("SELECT COUNT(*) FROM research_sources WHERE status = 'approved'") === count($config['sources']));
    check('… every claim active, one version each (' . count($config['claims']) . ')',
        (int) $val("SELECT COUNT(*) FROM research_claims WHERE status = 'active'") === count($config['claims'])
        && (int) $val("SELECT COUNT(*) FROM (SELECT claim_key FROM research_claims WHERE status = 'active' GROUP BY claim_key HAVING COUNT(*) > 1) d") === 0);
    $retired = $db->query("SELECT claim_key, version FROM research_claims WHERE status = 'retired' ORDER BY claim_key")->fetchAll();
    check('… the three changed claims: version 1 retired, version 2 active', array_column($retired, 'claim_key') === array_values(array_intersect(
        ['activity.who.adults', 'nutrition.pattern', 'nutrition.protein.exercise'], $changed)) && array_unique(array_column($retired, 'version')) === [1],
        json_encode($retired));
    check('… and the insight that used version 1 still points at it', (int) $val('SELECT claim_id FROM user_insights WHERE user_id = ?', [$ann]) === $old);
    check('… its new text arrives as written (SET NAMES)', $val("SELECT findings FROM research_sources WHERE code = 'XSN-05'") === $config['sources']['XSN-05']['findings']);

    $first = $snapshot();
    [$code, $out] = import($root . '/database/migrations/029-correlations.sql');
    check('a second import runs and changes nothing', $code === 0 && $snapshot() === $first, $out);

    [$code, $out] = registry('--check');
    check('research-registry --check: the database holds the reviewed registry', $code === 0, $out);
    [$code, $out] = registry('--sync');
    check('research-registry --sync: nothing left to do', $code === 0 && str_contains($out, 'already holds'), $out);

    /* Runs hang off the account. */
    $db->prepare("INSERT INTO correlation_runs (user_id, local_date, state, computed_at, engine_version) VALUES (?, CURDATE(), 'done', NOW(), '1')")->execute([$ann]);
    $run = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO user_correlations (run_id, user_id, pair_code, tier, days, detail_json) VALUES (?, ?, 'C01', 'unclear', 12, '{}')")->execute([$run, $ann]);
    $dup = false;
    try {
        $db->prepare("INSERT INTO correlation_runs (user_id, local_date) VALUES (?, CURDATE())")->execute([$ann]);
    } catch (PDOException $e) {
        $dup = true;
    }
    check('one run per person per day: a second is refused', $dup);
    $db->exec("DELETE FROM user_insights WHERE user_id = $ann");
    $db->exec("DELETE FROM users WHERE id = $ann");
    check('deleting the account deletes its runs and results', (int) $val('SELECT COUNT(*) FROM correlation_runs') === 0
        && (int) $val('SELECT COUNT(*) FROM user_correlations') === 0);

    /* The rollback keeps a person's caffeine readings, and the type with them. */
    $db->exec("INSERT INTO users (username) VALUES ('bo')");
    $bo = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO health_metrics (user_id, metric_type_id, value, recorded_at) VALUES (?, ?, 95, NOW())")
        ->execute([$bo, (int) $val("SELECT id FROM health_metric_types WHERE code = 'caffeine'")]);
    [$code, $out] = import($root . '/database/migrations/029-rollback.sql');
    check('the rollback drops the two tables', $code === 0 && $tables() === 0, $out);
    check('… and keeps the caffeine type while a reading uses it', $caffeine() === 1);
    check('… and leaves the research as it is', (int) $val("SELECT COUNT(*) FROM research_claims WHERE status = 'active'") === count($config['claims']));
    $db->exec("DELETE FROM health_metrics WHERE user_id = $bo");
    [$code, $out] = import($root . '/database/migrations/029-rollback.sql');
    check('with no caffeine reading left, the rollback drops the type too', $code === 0 && $caffeine() === 0, $out);
    [$code, $out] = import($root . '/database/migrations/029-correlations.sql');
    check('029 imports again after its rollback', $code === 0 && $tables() === 2 && $caffeine() === 1, $out);
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    @unlink($before);
}

printf("\n%d passed, %d failed\n", $ok, $bad);
exit($bad === 0 ? 0 : 1);

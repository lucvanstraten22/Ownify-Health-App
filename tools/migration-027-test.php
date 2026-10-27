<?php
/**
 * Migration 027 and its rollback, on a THROWAWAY database this script makes
 * and drops (named ownify_m027_<random>) — never on an existing one.
 *
 *     DB_USER=root php tools/migration-027-test.php
 *
 * Builds the schema as it was before 027 (database/schema.sql, then
 * 027-rollback.sql, which is allowed while nothing exists), puts in accounts
 * that make the backfill's hard cases, imports 027 and checks:
 *   - every piece present (the final query: all 1), and a second import
 *     changes nothing;
 *   - an import over an older draft (a table without its later column) adds
 *     the column;
 *   - a Google address on two accounts: listed shared_google, assigned to
 *     nobody, both still there;
 *   - a non-canonical Google address: listed not_canonical, not assigned;
 *   - one unambiguous Google address: assigned, via_google;
 *   - an unverified password address that is another account's verified
 *     address: listed unverified_shadow, neither account changed;
 *   - no account, identity or Google id rewritten;
 *   - the rollback refuses once a session number has moved, and drops only
 *     what 027 derived while nothing else exists.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$name = 'ownify_m027_' . bin2hex(random_bytes(3));
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

$server = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$val = static fn (string $sql, array $p = []) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();

echo "\nMigration 027, on $name\n" . str_repeat('-', 72) . "\n";

try {
    [$code] = import($root . '/database/schema.sql');
    check('schema.sql imports', $code === 0);
    [$code, $out] = import($root . '/database/migrations/027-rollback.sql');
    check('the rollback takes an empty database back to before 027', $code === 0, $out);
    check('before 027: no session number, no account_emails', $val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name = 'session_generation'", [$name]) == 0
        && $val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'account_emails'", [$name]) == 0);

    /* The people. */
    $add = static function (string $username, array $identities) use ($db): int {
        $db->prepare('INSERT INTO users (username) VALUES (?)')->execute([$username]);
        $id = (int) $db->lastInsertId();
        foreach ($identities as [$provider, $subject, $email, $verified]) {
            $db->prepare('INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, email_verified_at, password_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $provider, $subject, $email, $verified ? '2026-01-01 10:00:00' : null, $provider === 'email' ? '$2y$10$abcdefghijklmnopqrstuuJ5mVvOcbKk7LzR3Qy6qNd6Zt8pYtF7W' : null]);
        }
        return $id;
    };
    $ann  = $add('ann',  [['google', 'sub-ann', 'Shared@Gmail.com', true]]);
    $bob  = $add('bob',  [['google', 'sub-bob', 'shared@gmail.com ', true]]);
    $cas  = $add('cas',  [['google', 'sub-cas', 'cas@gmail.com', true]]);
    $dee  = $add('dee',  [['google', 'sub-dee', 'dé@gmail.com', true]]);
    $eli  = $add('eli',  [['email', 'cas@gmail.com', 'cas@gmail.com', false]]);        // unverified, Cas's verified address
    $fay  = $add('fay',  [['email', 'fay@example.com', 'fay@example.com', false]]);
    $before = $db->query('SELECT id, user_id, provider, provider_subject, email, email_verified_at, password_hash FROM user_auth_identities ORDER BY id')->fetchAll();

    /* An older draft made auth_email_tokens without change_id. */
    $db->exec("CREATE TABLE auth_email_tokens (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
        purpose ENUM('verify_signup','verify_link','verify_change','password_reset','revert_email') NOT NULL, email VARCHAR(191) NOT NULL,
        token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, used_at DATETIME NULL)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    [$code, $out] = import($root . '/database/migrations/027-account-security.sql');
    check('027 imports', $code === 0, $out);
    $last = trim((string) substr($out, (int) strrpos($out, "\n")));
    check('the final query: every piece 1', preg_match('/^(1\s+){9}1$/', $last) === 1, $last);
    check('over an older draft: the missing change_id was added', $val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'auth_email_tokens' AND column_name = 'change_id'", [$name]) == 1);

    $conflict = static fn (string $email) => $db->query('SELECT reason, user_ids FROM account_email_conflicts WHERE email = ' . $db->quote($email))->fetch() ?: null;
    $owner    = static fn (string $email) => $val('SELECT user_id FROM account_emails WHERE email = ?', [$email]);
    check('a Google address on two accounts: shared_google, both listed', ($conflict('shared@gmail.com')['reason'] ?? null) === 'shared_google'
        && ($conflict('shared@gmail.com')['user_ids'] ?? null) === "$ann,$bob");
    check('… and nobody owns it', $owner('shared@gmail.com') === false);
    check('a non-canonical Google address: not_canonical, not assigned', ($conflict('dé@gmail.com')['reason'] ?? null) === 'not_canonical' && $owner('dé@gmail.com') === false);
    check('the unambiguous one: assigned to its account, via_google', (int) $owner('cas@gmail.com') === $cas
        && $val('SELECT via_google FROM account_emails WHERE email = ?', ['cas@gmail.com']) == 1);
    check('an unverified password address that is Cas\'s verified one: unverified_shadow', ($conflict('cas@gmail.com')['reason'] ?? null) === 'unverified_shadow'
        && ($conflict('cas@gmail.com')['user_ids'] ?? null) === "$eli,$cas");
    check('a plain password address: nothing listed, nothing assigned', $conflict('fay@example.com') === null && $owner('fay@example.com') === false);
    $after = $db->query('SELECT id, user_id, provider, provider_subject, email, email_verified_at, password_hash FROM user_auth_identities ORDER BY id')->fetchAll();
    check('not one identity, Google id or password changed', $after === $before);
    check('every account still there and active', (int) $val("SELECT COUNT(*) FROM users WHERE status = 'active'") === 6);

    $snapshot = static fn () => json_encode([$db->query('SELECT * FROM account_emails ORDER BY email')->fetchAll(),
        $db->query('SELECT email, reason, user_ids FROM account_email_conflicts ORDER BY email')->fetchAll()]);
    $first = $snapshot();
    [$code, $out] = import($root . '/database/migrations/027-account-security.sql');
    check('a second import: no error, nothing changed', $code === 0 && $snapshot() === $first, $out);

    $db->prepare('UPDATE users SET session_generation = 1 WHERE id = ?')->execute([$fay]);
    [$code, $out] = import($root . '/database/migrations/027-rollback.sql');
    check('the rollback refuses once a session number has moved', $code !== 0 && str_contains($out, 'keep the 027 schema'), $out);
    check('… and dropped nothing', $val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'account_emails'", [$name]) == 1);

    $db->prepare('UPDATE users SET session_generation = 0 WHERE id = ?')->execute([$fay]);
    $db->prepare('INSERT INTO account_emails (email, user_id, via_password) VALUES (?, ?, 1)')->execute(['fay@example.com', $fay]);
    [$code] = import($root . '/database/migrations/027-rollback.sql');
    check('it refuses with a password-verified address too', $code !== 0);
    $db->exec("DELETE FROM account_emails WHERE email = 'fay@example.com'");
    $db->exec("UPDATE account_email_conflicts SET resolved_at = NOW() WHERE email = 'shared@gmail.com'");
    [$code] = import($root . '/database/migrations/027-rollback.sql');
    check('and with a conflict a person resolved', $code !== 0);
    $db->exec("UPDATE account_email_conflicts SET resolved_at = NULL");

    [$code, $out] = import($root . '/database/migrations/027-rollback.sql');
    check('with only what 027 derived: the rollback runs', $code === 0, $out);
    check('the accounts and identities are untouched by it', $db->query('SELECT id, user_id, provider, provider_subject, email, email_verified_at, password_hash FROM user_auth_identities ORDER BY id')->fetchAll() === $before);
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed  (throwaway database dropped)\n", $ok, $bad);
exit($bad === 0 ? 0 : 1);

<?php
/**
 * The mail outbox (includes/mailer.php), with a stand-in for SMTP: nothing
 * leaves this machine.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/mail-outbox-test.php
 *
 * DB_NAME must be a development database with migration 027. The test only
 * touches mail_outbox rows it made itself (recipients @ownify-test.invalid)
 * and removes them at the end.
 *
 * Claiming with a lease, finishing only with the claim that holds it, the
 * waits after a refusal (1, 5, 15, 60 minutes), at most 5 attempts, decoys
 * that send nothing, expiry, a worker that dies, and nothing sent while mail
 * is not configured.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');

if (getenv('OWNIFY_APP_KEY') === false) {
    putenv('OWNIFY_APP_KEY=' . base64_encode(random_bytes(32)));   // a throwaway key for this run
}

require_once dirname(__DIR__) . '/includes/mailer.php';

if (!db_available() || !account_security_schema()['outbox']) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 027.\n");
    exit(2);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "\n          " . $detail);
}

$log = tempnam(sys_get_temp_dir(), 'mail-log');
ini_set('error_log', $log);

$run  = bin2hex(random_bytes(3));
$to   = static fn (string $who): string => "mo_{$run}_{$who}@ownify-test.invalid";
$row  = static fn (int $id): ?array => db_one('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
$ours = [];
$sent = [];
$accept = static function (array $job, array $payload) use (&$sent): ?string { $sent[] = [$job['message_id'], $payload['subject'] ?? null]; return null; };
$refuse = static fn (array $job, array $payload): ?string => '421';

echo "\nThe mail outbox\n" . str_repeat('-', 72) . "\n";

try {

echo "\n== not configured: nothing goes out ==\n";
check('config/mail.php here has no App Password: not configured', !mail_configured(), (string) mail_unavailable_reason());
$id = mail_outbox_enqueue('test', $to('idle'), ['subject' => 'Ownify test', 'text' => 'x'], 60);
$ours[] = $id;
$before = $row($id);
$r = mail_outbox_run(10, [$id]);
check('the real run sends nothing and claims nothing', $r['sent'] === 0 && $row($id)['attempts'] == 0 && $row($id)['claim_token'] === null);
check('the payload is encrypted, the Message-ID fixed', !str_contains((string) $before['payload'], 'Ownify test') && str_starts_with((string) $before['message_id'], '<'));

echo "\n== sent, and a decoy ==\n";
$decoy = mail_outbox_enqueue('none', null, [], 60);
$ours[] = $decoy;
$r = mail_outbox_run(10, [$id, $decoy], $accept);
check('one sent, one decoy, both gone', $r['sent'] === 1 && $r['decoys'] === 1 && $row($id) === null && $row($decoy) === null, json_encode($r));
check('the decoy sent nothing; the message went with its own Message-ID', count($sent) === 1 && $sent[0][0] === $before['message_id'] && $sent[0][1] === 'Ownify test');

echo "\n== refused: the waits, then dropped after 5 ==\n";
$id = mail_outbox_enqueue('test', $to('refused'), ['subject' => 's', 'text' => 'x'], 600);
$ours[] = $id;
$waits = [];
for ($i = 1; $i <= 5; $i++) {
    mail_outbox_run(10, [$id], $refuse);
    $r = $row($id);
    if ($r === null) { break; }
    $waits[] = (int) round((strtotime((string) $r['send_after']) - time()) / 60);
    db_run('UPDATE mail_outbox SET send_after = NOW() WHERE id = ?', [$id]);   // the wait has passed
}
check('waits of 1, 5, 15, 60 minutes, then 60', array_slice($waits, 0, 4) === [1, 5, 15, 60], json_encode($waits));
check('after the 5th attempt the job is no longer claimed', (int) $row($id)['attempts'] === 5 && mail_outbox_claim($id) === null);
mail_outbox_run(10, [$id], $refuse);
check('and the next run drops it, logged by kind and code only', $row($id) === null
    && str_contains((string) file_get_contents($log), 'a test job was dropped') && !str_contains((string) file_get_contents($log), 'ownify-test.invalid'));

echo "\n== a worker that dies, and a stale worker ==\n";
$id = mail_outbox_enqueue('test', $to('lease'), ['subject' => 's', 'text' => 'x'], 60);
$ours[] = $id;
$first = mail_outbox_claim($id);
check('claimed once', $first !== null && (int) $first['attempts'] === 1);
check('while the lease runs nobody else can claim it', mail_outbox_claim($id) === null);
db_run('UPDATE mail_outbox SET lease_until = NOW() - INTERVAL 1 SECOND WHERE id = ?', [$id]);   // the worker died
$second = mail_outbox_claim($id);
check('the lease ran out: claimed again, the attempt counted', $second !== null && (int) $second['attempts'] === 2);
check('the stale worker can neither finish nor hand it back', !mail_outbox_finish($first) && !mail_outbox_release($first) && $row($id) !== null);
check('the one holding it finishes it', mail_outbox_finish($second) && $row($id) === null);

echo "\n== expired: dropped unsent ==\n";
$id = mail_outbox_enqueue('test', $to('old'), ['subject' => 's', 'text' => 'x'], 60);
$ours[] = $id;
db_run('UPDATE mail_outbox SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$id]);
$sent = [];
$r = mail_outbox_run(10, [$id], $accept);
check('not sent, deleted', $sent === [] && $row($id) === null && $r['dropped'] >= 1);

echo "\n== an address that is not one is never queued ==\n";
check('refused before anything is stored', mail_outbox_enqueue('test', "x@y.z\nBcc: someone@else.invalid", [], 60) === null);

} finally {
    foreach ($ours as $id) {
        if ($id !== null) { db_run('DELETE FROM mail_outbox WHERE id = ?', [$id]); }
    }
    @unlink($log);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

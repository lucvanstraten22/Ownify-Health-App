<?php
/**
 * Sends one real test message through Gmail, and says how far it got.
 * Command line only; there is no web version (docs/MAIL.md).
 *
 *     php tools/mail-test.php you@example.com
 *
 * 1. reads the real settings (config/mail.php and its mail.local.php or the
 *    environment) and says WHICH files were read — never what is in them;
 * 2. connects to smtp.gmail.com and starts TLS;
 * 3. logs in with the App Password;
 * 4. sends one plain-text + HTML message with Ownify as the sender;
 * 5. prints "sent" (Gmail accepted it — check the inbox, and the spam folder
 *    the first time) or the step that failed with Gmail's reply code.
 *
 * Exit code 0 only when Gmail accepted the message.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/mailer.php';

$to = $argv[1] ?? '';
if ($to === '' || str_starts_with($to, '-')) {
    fwrite(STDERR, "Usage: php tools/mail-test.php you@example.com\n");
    exit(2);
}

$recipient = account_email_canonical($to);
if ($recipient === null) {
    fwrite(STDERR, "Not an address.\n");
    exit(2);
}

$s = mail_settings();
$step = static fn (string $label, string $detail) => printf("  %-14s %s\n", $label, $detail);

echo "Ownify mail test\n";
$step('settings', implode(', ', array_map(
    static fn ($source) => str_starts_with($source, '$') ? $source : str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '…/', $source),
    $s['_sources'] ?: ['config/mail.php only — no mail.local.php found, no OWNIFY_SMTP_* set']
)));
$step('server', $s['host'] . ':' . $s['port'] . ' (' . (($s['encryption'] ?? 'starttls') === 'smtps' ? 'implicit TLS' : 'STARTTLS') . ')');
$step('sender', ($s['from_name'] ?? 'Ownify') . ' <' . $s['from'] . '>, logging in as ' . $s['username']);
$step('password', trim((string) $s['password']) === '' ? 'NOT SET' : 'set (' . strlen((string) $s['password']) . ' characters; an App Password has 16)');

if (($reason = mail_transport_reason()) !== null) {
    echo "\nNot sent: $reason\n";
    exit(1);
}

$messages = [
    'connect'   => 'could not reach the server (network, firewall or a blocked port 587)',
    'tls'       => 'the TLS handshake failed (check the server\'s CA certificates and OpenSSL)',
    'auth'      => 'Gmail refused the login (wrong App Password, 2-Step Verification off, or the normal password used)',
    'sender'    => 'Gmail refused the sender (\'from\' must be the account itself)',
    'recipient' => 'Gmail refused the recipient',
    'data'      => 'Gmail refused the message',
];

echo "\n";
$check = mail_smtp_check();
if (!$check['ok']) {
    $step('connect+login', 'FAILED at ' . $check['stage'] . ' (' . $check['code'] . '): ' . ($messages[$check['stage']] ?? 'see the stage'));
    exit(1);
}
$step('connect+login', 'ok (TLS, authenticated)');

$when = (new DateTimeImmutable())->format('Y-m-d H:i:s T');
$text = "Dit is een testbericht van Ownify, verstuurd op $when.\nJe hoeft er niets mee te doen.\n\n"
      . "This is a test message from Ownify, sent on $when.\nThere is nothing you need to do.\n";
$html = '<!doctype html><html><body style="font-family: -apple-system, Segoe UI, sans-serif; line-height: 1.5;">'
      . '<p>Dit is een testbericht van <strong>Ownify</strong>, verstuurd op ' . htmlspecialchars($when) . '.<br>Je hoeft er niets mee te doen.</p>'
      . '<p>This is a test message from <strong>Ownify</strong>, sent on ' . htmlspecialchars($when) . '.<br>There is nothing you need to do.</p>'
      . '</body></html>';

$sent = mail_send($recipient, 'Ownify: testbericht / test message', $text, $html, ['kind' => 'test']);
if (!$sent['ok']) {
    $step('send', 'FAILED at ' . $sent['stage'] . ' (' . $sent['code'] . '): ' . ($messages[$sent['stage']] ?? 'see the stage'));
    exit(1);
}

$step('send', 'sent — Gmail accepted it. Check the inbox (and the spam folder the first time).');
exit(0);

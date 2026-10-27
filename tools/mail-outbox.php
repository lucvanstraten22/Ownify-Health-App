<?php
/**
 * Sends what is due in the mail outbox (includes/mailer.php), and clears what
 * can no longer be sent. For cron on Hestia, or Windows Task Scheduler on
 * WAMP, every minute where that is allowed:
 *
 *     php tools/mail-outbox.php
 *
 * The same function a request runs after its response; there is no other way
 * out. Nothing is sent while config/mail.php is not configured — Release A
 * (28.0) puts nothing in the outbox anyway. Prints counts only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/mailer.php';

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(2);
}

$done = mail_outbox_run(20);
printf("sent %d, decoys %d, failed %d, dropped %d%s\n", $done['sent'], $done['decoys'], $done['failed'], $done['dropped'],
    mail_configured() ? '' : ' (mail is not configured: ' . mail_unavailable_reason() . ')');

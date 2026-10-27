<?php
/**
 * Mail: the outbox, and the one function that sends from it.
 * docs/ACCOUNT-SECURITY.md §11.
 *
 * ---------------------------------------------------------------------------
 * RELEASE A (28.0): PRESENT, AND INERT
 * ---------------------------------------------------------------------------
 * Nothing in Release A puts a message in the outbox, and nothing is sent
 * while config/mail.php is not configured (mail_configured()). The code is
 * here so the server can be checked (tools/check-config.php) and the
 * Gmail test can be run by hand before Release B uses it.
 *
 * ---------------------------------------------------------------------------
 * ONE WAY OUT
 * ---------------------------------------------------------------------------
 * Every flow only ENQUEUES: one mail_outbox row, inside its own transaction,
 * with its Message-ID fixed and its payload (the link included) encrypted
 * with the app key. mail_outbox_run() is the only thing that sends — called
 * after a response has been finished (fastcgi_finish_request) or by
 * tools/mail-outbox.php from cron — and it claims each job with a 5-minute
 * LEASE: claim_token + lease_until, attempts + 1. Only the worker whose
 * claim_token still matches may delete the job (sent, or a decoy) or hand it
 * back (an SMTP failure, again after 1, 5, 15 or 60 minutes). A worker that
 * dies leaves the lease to run out; the job is claimable again. At most 5
 * claims; a job at 5, or past expires_at, is deleted unsent.
 *
 * At least once while a job lives, never exactly once: a crash after SMTP
 * accepted the message and before the delete sends it twice — the same
 * Message-ID and the same single-use link, so the second is harmless.
 *
 * Nothing here logs an address, a link or a password: only the kind of job
 * and the SMTP reply code.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/account-security.php';

/** The lease a worker holds on a job, in minutes, and the attempts a job gets. */
if (!defined('MAIL_LEASE_MINUTES')) {
    define('MAIL_LEASE_MINUTES', 5);
    define('MAIL_MAX_ATTEMPTS', 5);
    define('MAIL_SEND_SECONDS', 15);
}

if (!function_exists('mail_settings')) {

    /** config/mail.php, read once. Never printed: it holds the App Password. */
    function mail_settings(): array
    {
        static $settings = null;

        return $settings ??= (array) require dirname(__DIR__) . '/config/mail.php';
    }

    /**
     * Why mail cannot be sent here, or null when it can: every setting there,
     * a https public_url, the app key, and the outbox table.
     */
    function mail_unavailable_reason(): ?string
    {
        $s = mail_settings();

        foreach (['host', 'username', 'password', 'from', 'public_url'] as $key) {
            if (trim((string) ($s[$key] ?? '')) === '') {
                return "config/mail.php: '$key' is not set";
            }
        }

        if (!str_starts_with((string) $s['public_url'], 'https://')) {
            return "config/mail.php: 'public_url' must start with https://";
        }

        if (!crypto_available()) {
            return 'the app key is missing';
        }

        if (!account_security_schema()['outbox']) {
            return 'the mail_outbox table (migration 027) is missing';
        }

        return null;
    }

    function mail_configured(): bool
    {
        return mail_unavailable_reason() === null;
    }

    /**
     * Puts one message in the outbox, inside the caller's transaction. A
     * `none` job is a decoy: it does the same database work and sends nothing.
     *
     * @param array<string, mixed> $payload subject, text, html, … — encrypted; never a password
     */
    function mail_outbox_enqueue(string $kind, ?string $recipient, array $payload, int $validMinutes): ?int
    {
        /* Without the app key nothing is stored rather than a link in the clear. */
        if (!account_security_schema()['outbox'] || ($kind !== 'none' && !crypto_available())) {
            return null;
        }

        if ($kind !== 'none') {
            $recipient = $recipient === null ? null : account_email_canonical($recipient);
            if ($recipient === null) {
                return null;
            }
        }

        $domain    = parse_url((string) (mail_settings()['public_url'] ?? ''), PHP_URL_HOST) ?: 'ownify.invalid';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';

        db_run(
            'INSERT INTO mail_outbox (kind, recipient, payload, message_id, expires_at)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)',
            [$kind, $kind === 'none' ? null : $recipient, $kind === 'none' ? null : base64_encode((string) crypto_seal(json_encode($payload))),
             $messageId, max(1, $validMinutes)]
        );

        return db_insert_id();
    }

    /**
     * The one sending path. Claims up to $max due jobs (only $ids when given),
     * one at a time, and finishes each: deleted when SMTP accepted it or it is
     * a decoy, handed back with a wait when SMTP refused. $send is the sender —
     * PHPMailer through mail_smtp_send() unless a test gives its own; it
     * returns null when accepted, or the SMTP reply code.
     *
     * @param ?list<int> $ids
     * @param ?callable(array $job, array $payload): ?string $send
     * @return array{sent: int, decoys: int, failed: int, dropped: int}
     */
    function mail_outbox_run(int $max, ?array $ids = null, ?callable $send = null): array
    {
        $done = ['sent' => 0, 'decoys' => 0, 'failed' => 0, 'dropped' => 0];

        if (!account_security_schema()['outbox']) {
            return $done;
        }

        /* Never stuck: out of attempts once the last lease is over, or past
           the job's own expiry — deleted unsent, the kind logged. */
        foreach (db_all(
            'SELECT id, kind FROM mail_outbox
              WHERE expires_at <= NOW() OR (attempts >= ? AND (lease_until IS NULL OR lease_until < NOW()))',
            [MAIL_MAX_ATTEMPTS]
        ) as $old) {
            db_run('DELETE FROM mail_outbox WHERE id = ?', [(int) $old['id']]);
            error_log('[ownify] mail: a ' . $old['kind'] . ' job was dropped unsent (expired or out of attempts)');
            $done['dropped']++;
        }

        if ($send === null && !mail_configured()) {
            return $done;                       // Release A, or not set up: nothing goes out
        }

        $send ??= 'mail_smtp_send';

        $where  = $ids === null ? '' : ' AND id IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')';
        $due    = db_all(
            'SELECT id FROM mail_outbox
              WHERE (lease_until IS NULL OR lease_until < NOW()) AND send_after <= NOW()
                AND attempts < ? AND expires_at > NOW()' . $where . '
              ORDER BY send_after, id LIMIT ' . max(0, $max),
            [MAIL_MAX_ATTEMPTS]
        );

        foreach ($due as $row) {
            $job = mail_outbox_claim((int) $row['id']);
            if ($job === null) {
                continue;                       // somebody else holds it
            }

            if ($job['kind'] === 'none') {
                mail_outbox_finish($job);
                $done['decoys']++;
                continue;
            }

            $payload = json_decode((string) crypto_open(base64_decode((string) $job['payload'], true) ?: null), true);
            $code    = is_array($payload) ? $send($job, $payload) : 'payload';

            if ($code === null) {
                mail_outbox_finish($job);
                $done['sent']++;
            } else {
                mail_outbox_release($job);
                error_log('[ownify] mail: a ' . $job['kind'] . ' job was not accepted (' . preg_replace('/[^A-Za-z0-9.]/', '', (string) $code) . ')');
                $done['failed']++;
            }
        }

        return $done;
    }

    /** Claims one job for this worker, or null when it is not claimable. */
    function mail_outbox_claim(int $id): ?array
    {
        $claim = bin2hex(random_bytes(16));

        $claimed = db_run(
            'UPDATE mail_outbox
                SET claim_token = ?, lease_until = NOW() + INTERVAL ' . MAIL_LEASE_MINUTES . ' MINUTE, attempts = attempts + 1
              WHERE id = ? AND (lease_until IS NULL OR lease_until < NOW())
                AND send_after <= NOW() AND attempts < ? AND expires_at > NOW()',
            [$claim, $id, MAIL_MAX_ATTEMPTS]
        );

        if ($claimed === null || $claimed->rowCount() !== 1) {
            return null;
        }

        $job = db_one('SELECT * FROM mail_outbox WHERE id = ? AND claim_token = ?', [$id, $claim]);

        return $job === null ? null : $job + ['claim' => $claim];
    }

    /** Sent, or a decoy: gone — only if this worker still holds it. */
    function mail_outbox_finish(array $job): bool
    {
        $done = db_run('DELETE FROM mail_outbox WHERE id = ? AND claim_token = ?', [(int) $job['id'], $job['claim']]);

        return $done !== null && $done->rowCount() === 1;
    }

    /** Not accepted: back in the queue after 1, 5, 15, then 60 minutes — only if this worker still holds it. */
    function mail_outbox_release(array $job): bool
    {
        $gaps = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
        $wait = $gaps[(int) $job['attempts']] ?? 60;

        $done = db_run(
            'UPDATE mail_outbox SET lease_until = NULL, claim_token = NULL, send_after = NOW() + INTERVAL ? MINUTE
              WHERE id = ? AND claim_token = ?',
            [$wait, (int) $job['id'], $job['claim']]
        );

        return $done !== null && $done->rowCount() === 1;
    }

    /**
     * One message through Gmail's SMTP with PHPMailer (includes/vendor/
     * phpmailer, v7.1.1, D5). Null when SMTP accepted it; otherwise only the
     * reply code. Certificate checks stay on; SMTPDebug stays 0.
     */
    function mail_smtp_send(array $job, array $payload): ?string
    {
        require_once __DIR__ . '/vendor/phpmailer/Exception.php';
        require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

        $s = mail_settings();
        @set_time_limit(MAIL_SEND_SECONDS + 15);

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = (string) $s['host'];
            $mail->Port       = (int) $s['port'];
            $mail->SMTPAuth   = true;
            $mail->Username   = (string) $s['username'];
            $mail->Password   = (string) $s['password'];
            $mail->SMTPSecure = ($s['encryption'] ?? 'smtps') === 'starttls'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPDebug  = 0;
            $mail->Timeout    = MAIL_SEND_SECONDS;
            $mail->CharSet    = 'UTF-8';
            $mail->MessageID  = (string) $job['message_id'];
            $mail->setFrom((string) $s['from'], (string) ($s['from_name'] ?? 'Ownify'));
            $mail->addAddress((string) $job['recipient']);
            $mail->Subject    = (string) ($payload['subject'] ?? 'Ownify');
            if (isset($payload['html'])) {
                $mail->isHTML(true);
                $mail->Body    = (string) $payload['html'];
                $mail->AltBody = (string) ($payload['text'] ?? '');
            } else {
                $mail->Body = (string) ($payload['text'] ?? '');
            }

            $mail->send();

            return null;
        } catch (\Throwable $e) {
            $smtp = $mail->getSMTPInstance();
            $code = $smtp !== null ? ($smtp->getError()['smtp_code'] ?? '') : '';

            return $code !== '' ? (string) $code : 'no-reply';
        }
    }
}

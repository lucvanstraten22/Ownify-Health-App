<?php
/**
 * Where Polar sends the browser back to — the redirect URL registered on the
 * AccessLink client (https://admin.polaraccesslink.com), so its address must
 * not change without changing it there too:
 *
 *     https://ownify.acits.nl/api/integrations/polar/callback.php
 *
 * Not a JSON endpoint: a person arrives here in their browser.
 *
 *   started on the website  the state must belong to this browser's session
 *                           and its signed-in person; then back to
 *                           Instellingen with a line saying how it went
 *   started in the app      a page in the phone's browser: which Ownify
 *                           account Polar is about to go to, and a button to
 *                           confirm (POST, with a one-time token only this
 *                           page has); after that, "go back to the app"
 *
 * Whose connection it is comes from the state Ownify made, never from the
 * query. The code is exchanged only after every check; neither it nor a
 * token is ever shown or logged. After connecting, the first sync runs once
 * the answer has gone out.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/integrations.php';
require_once dirname(__DIR__, 3) . '/lib/render.php';
require_once dirname(__DIR__, 3) . '/lib/theme.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');                       // the code must not leak onward
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');                              // the confirm button cannot be framed
header("Content-Security-Policy: frame-ancestors 'none'");

$app = '../../../';

if (!db_available() || !polar_stored()) {
    auth_flash('Polar koppelen is op dit moment niet beschikbaar.', 'error', 'settings-devices');
    header('Location: ' . $app, true, 303);
    exit;
}

$result = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? polar_confirm((string) ($_POST['token'] ?? ''), (string) ($_POST['action'] ?? ''))
    : polar_callback($_GET, current_user_id());

$outcome = $result['outcome'];

/* ------------------------------------------------------------ the website */

if ($result['client'] === 'web') {
    auth_flash(
        (string) $result['message'],
        match ($outcome) { 'connected' => 'ok', 'cancelled' => 'info', default => 'error' },
        'settings-devices'
    );

    if ($outcome === 'connected') {
        polar_respond_then_sync((int) $result['user_id'], static function () use ($app): void {
            header('Location: ' . $app, true, 303);
        });
    }

    header('Location: ' . $app, true, 303);
    exit;
}

/* ------------------------------------------- the app's browser: a page */

$data  = require dirname(__DIR__, 3) . '/config/dashboard.php';
$title = match ($outcome) {
    'confirm'   => 'Polar koppelen',
    'connected' => 'Polar is gekoppeld',
    'cancelled' => 'Niet gekoppeld',
    default     => 'Koppelen is niet gelukt',
};

$page = static function () use ($data, $title, $outcome, $result): void {
    ?>
<!DOCTYPE html>
<html lang="nl" data-theme="<?= e(app_theme()) ?>">
<head>
    <base href="../../../">
<?php component('document-head', ['app' => $data['app'], 'styles' => ['theme', 'components', 'connect']]); ?>
</head>
<body class="connect-page">
    <main class="connect card">
        <span class="icon-tile connect__tile" aria-hidden="true"><?= $outcome === 'connected' ? icon('check') : icon('device') ?></span>
        <h1 class="connect__title"><?= e($title) ?></h1>

        <?php if ($outcome === 'confirm'): ?>
            <p class="connect__text">
                Je Polar-gegevens (trainingen, slaap, stappen, hartslag en Nightly Recharge) worden gekoppeld aan het Ownify-account
                <strong><?= e($result['confirm']['username']) ?></strong>.
            </p>
            <?php if (!empty($result['confirm']['signed_in_as'])): ?>
                <p class="connect__text connect__warn" role="alert">
                    Let op: in deze browser ben je ingelogd als <strong><?= e($result['confirm']['signed_in_as']) ?></strong>,
                    niet als <strong><?= e($result['confirm']['username']) ?></strong>. Heb je het koppelen niet zelf in de app gestart, kies dan Annuleren.
                </p>
            <?php else: ?>
                <p class="connect__text connect__text--quiet">Is dit niet jouw account? Kies dan Annuleren.</p>
            <?php endif; ?>
            <form class="connect__actions" method="post" action="api/integrations/polar/callback.php">
                <input type="hidden" name="token" value="<?= e($result['confirm']['token']) ?>">
                <button class="btn btn--primary press" type="submit" name="action" value="confirm">Koppelen</button>
                <button class="btn press" type="submit" name="action" value="cancel">Annuleren</button>
            </form>
        <?php elseif ($outcome === 'connected'): ?>
            <p class="connect__text">Je gegevens worden nu opgehaald. Ga terug naar de Ownify-app; dit venster kun je sluiten.</p>
        <?php else: ?>
            <p class="connect__text"><?= e($result['message']) ?></p>
            <p class="connect__text connect__text--quiet">Ga terug naar de Ownify-app; dit venster kun je sluiten.</p>
        <?php endif; ?>
    </main>
</body>
</html>
    <?php
};

require_once dirname(__DIR__, 3) . '/components/icons.php';

if ($outcome === 'connected') {
    polar_respond_then_sync((int) $result['user_id'], $page);
}

http_response_code(in_array($outcome, ['confirm', 'connected', 'cancelled'], true) ? 200 : 400);
$page();
exit;

/**
 * Sends the answer (`$respond` writes it), lets the browser go, and then
 * runs the first sync — so nobody waits on a blank page for Polar. Where the
 * server cannot let go early, the sync runs before the answer is finished.
 */
function polar_respond_then_sync(int $userId, callable $respond): never
{
    db_run("UPDATE user_integrations SET sync_started_at = NOW() WHERE user_id = ? AND provider = 'polar'", [$userId]);

    ignore_user_abort(true);
    @set_time_limit(300);

    $respond();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();                     // the next page must not wait on this session
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }

    try {
        polar_sync($userId, 'connect');
    } catch (Throwable $e) {
        error_log('[polar] first sync for user ' . $userId . ' failed: ' . $e::class);
        db_run("UPDATE user_integrations SET sync_started_at = NULL WHERE user_id = ? AND provider = 'polar'", [$userId]);
    }

    exit;
}

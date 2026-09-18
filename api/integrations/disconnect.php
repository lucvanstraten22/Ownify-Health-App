<?php
/**
 * Disconnects an outside health source for the signed-in user.
 *
 * Disconnecting destroys the stored tokens, so nothing can be fetched
 * afterwards even if a sync job is already queued. It does not delete the data
 * already imported — that is the user's health history, and they did not ask
 * for it to be thrown away.
 *
 * The provider arrives from the browser; the user never does. An id in this
 * request can only ever name which of YOUR connections to drop.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/integrations.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId   = api_require_user();
$provider = (string) ($_POST['provider'] ?? '');

if (!integration_known($provider)) {
    api_fail('Onbekende koppeling.', 400);
}

if (!integration_disconnect($userId, $provider)) {
    api_fail('Ontkoppelen is niet gelukt.', 500);
}

api_ok(['provider' => $provider] + ['state' => integration_public($userId, $provider)]);

<?php
/**
 * Mints a pairing code for the signed-in user to type into the phone app.
 *
 * Session-authenticated, because the person asking is sitting in front of the
 * website. The code comes back once, in this response, and is never
 * retrievable again — only its hash is stored, so showing it twice would mean
 * keeping it, and then it would be worth stealing.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId   = api_require_user();
$provider = (string) ($_POST['provider'] ?? '');

if (!integration_known($provider)) {
    api_fail('Onbekende koppeling.', 400);
}

/* Only sources whose data lives on a phone are paired this way. A cloud
   source is connected with OAuth, and offering a code for one would be
   offering something that could not be used. */
$meta = integration_providers()[$provider] ?? null;

if (($meta['transport'] ?? null) !== 'device') {
    api_fail('Deze bron wordt niet met een koppelcode verbonden.', 400);
}

$code = device_create_pairing_code($userId, $provider);

if ($code === null) {
    api_fail('Er kon geen koppelcode worden gemaakt.', 500);
}

api_ok($code);

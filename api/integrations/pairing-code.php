<?php
/**
 * Mints a pairing code for the signed-in user to type into the phone app.
 *
 * Asked for by the signed-in account — on the website, or in the Ownify app
 * signed in with its account token (api_require_account_user()), to pair
 * another phone. A pairing token can never mint one. The code comes back once,
 * in this response, and is never retrievable again — only its hash is stored,
 * so showing it twice would mean keeping it, and then it would be worth
 * stealing.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$userId   = api_require_account_user();
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

/* No code for a source no app can receive it for (`app_available` in
   config/integrations.php): it could never be used. */
if (!integration_configured($provider)) {
    api_fail((string) integration_blocked_reason($provider), 409);
}

$code = device_create_pairing_code($userId, $provider);

if ($code === null) {
    api_fail('Er kon geen koppelcode worden gemaakt.', 500);
}

api_ok($code);

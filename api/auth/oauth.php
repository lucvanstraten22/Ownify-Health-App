<?php
/**
 * Apple and Google sign-in are NOT implemented.
 *
 * This endpoint exists so the buttons have somewhere honest to point. It never
 * signs anyone in. Implementing a provider means: redirect to the provider,
 * receive the callback, VERIFY the ID token server-side (signature, issuer,
 * audience, nonce, expiry), and only then call auth_link_identity() with the
 * verified 'sub'. Until that exists, this answers 501.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_csrf();

$provider = (string) ($_POST['provider'] ?? '');

if (!in_array($provider, ['apple', 'google'], true)) {
    api_fail('Onbekende aanbieder.', 400);
}

api_fail('Inloggen met ' . ucfirst($provider) . ' is nog niet gekoppeld.', 501);

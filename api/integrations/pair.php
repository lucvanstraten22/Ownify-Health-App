<?php
/**
 * The phone app exchanges a pairing code for its own device token.
 *
 * No session and no CSRF token: this is not a browser and there is no cookie
 * to protect. The pairing code IS the credential, which is why it is short
 * lived, single use, and answers every kind of failure with one message.
 *
 * The token comes back once. There is nowhere to fetch it again — only its
 * hash is stored — so the app has to keep it, and losing it means pairing
 * again rather than recovering it from us.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

/* The app posts JSON; a form post works too, so the contract is easy to try
   with curl before any Kotlin exists. */
$input = api_json_body();

$result = device_pair((string) ($input['code'] ?? ''), [
    'label'       => $input['label'] ?? null,
    'platform'    => $input['platform'] ?? null,
    'app_version' => $input['app_version'] ?? null,
]);

if (!$result['ok']) {
    api_fail($result['error'], 401);
}

api_ok([
    'token'    => $result['token'],
    'provider' => $result['provider'],
]);

<?php
/**
 * The Ownify app signs out: the account token it sends stops working.
 *
 *   POST  Authorization: Bearer <account token>      (no body)
 *
 *   200   { ok: true, revoked: true }    the token was revoked just now
 *   200   { ok: true, revoked: false }   there was nothing to revoke: the
 *         token was unknown, already revoked, lapsed, or not an account token
 *   400   no token sent
 *
 * Only that token, and only its own phone's row: the account's other phones
 * and every browser stay signed in. A sync token from a pairing code is not
 * revoked here — that is done on the website, as always.
 *
 * Signing out succeeds whatever state the token was in, so the app can
 * always finish signing out — forget the token, stop syncing — even when the
 * website had already revoked it. The answer reveals nothing a caller holding
 * the token did not know.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$token = api_bearer_token();

if ($token === null) {
    api_fail('Stuur het token van dit apparaat mee om uit te loggen.', 400);
}

api_ok(['revoked' => device_revoke_token($token)]);

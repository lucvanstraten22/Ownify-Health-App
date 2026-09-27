<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/* Guarded because a deploy lands one file at a time: until the limit's file
   is there, signing in works exactly as it did, just not counted. */
if (is_file(dirname(__DIR__, 2) . '/includes/auth-throttle.php')) {
    require_once dirname(__DIR__, 2) . '/includes/auth-throttle.php';
}

api_require_post();
api_require_csrf();
api_require_database();

/* The field is labelled Gebruikersnaam; an e-mail address works too, so
   nobody is locked out for having forgotten which one they registered with.
   Wrong guesses are counted per name and network address, and a name that
   has had too many waits a while (includes/auth-throttle.php). */
$identifier = (string) ($_POST['username'] ?? $_POST['email'] ?? '');
$password   = (string) ($_POST['password'] ?? '');

$result = function_exists('auth_login_attempt')
    ? auth_login_attempt($identifier, $password)
    : auth_login_password($identifier, $password);

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
        api_fail($result['error'], 429);
    }

    api_fail($result['error'], 401);
}

session_login((int) $result['user_id']);
auth_touch_last_seen((int) $result['user_id']);

api_ok(api_account_payload((int) $result['user_id']));

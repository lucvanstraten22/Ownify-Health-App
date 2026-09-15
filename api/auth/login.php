<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_csrf();
api_require_database();

$result = auth_login_email(
    (string) ($_POST['email'] ?? ''),
    (string) ($_POST['password'] ?? '')
);

if (!$result['ok']) {
    api_fail($result['error'], 401);
}

session_login((int) $result['user_id']);
auth_touch_last_seen((int) $result['user_id']);

api_ok(api_account_payload((int) $result['user_id']));

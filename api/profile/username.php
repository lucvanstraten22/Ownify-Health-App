<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$result = user_update_username($userId, (string) ($_POST['username'] ?? ''));

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok(['username' => $result['username']]);

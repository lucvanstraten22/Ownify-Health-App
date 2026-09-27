<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!isset($_FILES['avatar'])) {
    api_fail('Geen bestand ontvangen.', 400);
}

$result = user_set_avatar($userId, $_FILES['avatar'], app_upload_root());

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok(['avatar' => $result['avatar']]);

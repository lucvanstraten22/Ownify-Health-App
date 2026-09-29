<?php
/**
 * Database connection settings.
 *
 * The defaults match a stock WampServer install, so a fresh checkout connects
 * without editing anything.
 *
 * ---------------------------------------------------------------------------
 * DO NOT PUT REAL CREDENTIALS IN THIS FILE
 * ---------------------------------------------------------------------------
 * This file is in the repository, and the repository is on GitHub. A password
 * written here is published, stays in the history after it is removed, and is
 * deployed to the server in the clear.
 *
 * Put them in config/database.local.php instead. It is git-ignored, it is not
 * in the deploy, and it overrides only the keys you name:
 *
 *     <?php  // config/database.local.php
 *     return [
 *         'host'     => 'localhost',
 *         'database' => 'luc_ownify',
 *         'username' => 'luc_ownify',
 *         'password' => 'the-real-one',
 *     ];
 *
 * Setting the DB_* environment variables works too, and takes precedence.
 * See config/database.local.php.example.
 */

declare(strict_types=1);

$settings = [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'ownify',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $settings = array_replace($settings, (array) require $local);
}

/* Environment variable => the setting it overrides. The names on the LEFT are
   read from the environment; the names on the RIGHT are keys in $settings
   above. Putting a value on either side does nothing — a literal on the right
   is written to a key that does not exist, and the real setting keeps its
   default, which is how a server ends up quietly connecting as root. */
foreach ([
    'DB_HOST'     => 'host',
    'DB_PORT'     => 'port',
    'DB_NAME'     => 'database',
    'DB_USER'     => 'username',
    'DB_PASSWORD' => 'password',
] as $variable => $key) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$key] = $value;
    }
}

return $settings;

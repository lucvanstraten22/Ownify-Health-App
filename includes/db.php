<?php
/**
 * The one place the application connects to MySQL.
 *
 * Nothing else calls `new PDO`. Every query in includes/ goes through the
 * handle returned here, with prepared statements — user input is never
 * concatenated into SQL.
 *
 * db() returns null when the database is unreachable instead of throwing, so
 * a checkout without a database still renders the app in its logged-out,
 * placeholder state rather than showing a stack trace.
 */

declare(strict_types=1);

if (!function_exists('db')) {

    function db(): ?PDO
    {
        static $pdo = null;
        static $attempted = false;

        if ($attempted) {
            return $pdo;
        }

        $attempted = true;
        $settings = require dirname(__DIR__) . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $settings['host'],
            (int) $settings['port'],
            $settings['database'],
            $settings['charset']
        );

        try {
            $pdo = new PDO($dsn, $settings['username'], (string) $settings['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, so the driver never interpolates.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            db_note_failure($e->getMessage());
            $pdo = null;
        }

        return $pdo;
    }

    /** Remembers why the connection failed, for the status panel — never shown raw to users. */
    function db_note_failure(?string $message = null): ?string
    {
        static $failure = null;

        if ($message !== null) {
            $failure = $message;
        }

        return $failure;
    }

    function db_available(): bool
    {
        return db() instanceof PDO;
    }

    /** Runs a prepared statement and returns it. */
    function db_run(string $sql, array $params = []): ?PDOStatement
    {
        $pdo = db();
        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /** First row, or null. */
    function db_one(string $sql, array $params = []): ?array
    {
        $statement = db_run($sql, $params);
        if ($statement === null) {
            return null;
        }

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** All rows. */
    function db_all(string $sql, array $params = []): array
    {
        $statement = db_run($sql, $params);

        return $statement === null ? [] : $statement->fetchAll();
    }

    /** Single scalar, or null. */
    function db_value(string $sql, array $params = []): mixed
    {
        $statement = db_run($sql, $params);
        if ($statement === null) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    function db_insert_id(): ?int
    {
        $pdo = db();

        return $pdo === null ? null : (int) $pdo->lastInsertId();
    }
}

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

            /* MySQL's NOW(), CURDATE() and DATETIME defaults run on PHP's
               clock — the zone includes/bootstrap.php sets — so a time the
               database writes and a time PHP writes can be compared. As an
               offset ("+02:00"), which needs no time-zone tables in MySQL. */
            $pdo->exec("SET time_zone = '" . date('P') . "'");
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

    /** Whether a statement only reads. Deliberately conservative. */
    function db_is_read(string $sql): bool
    {
        return (bool) preg_match('/^\s*\(*\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql);
    }

    /**
     * Runs a prepared statement and returns it.
     *
     * -----------------------------------------------------------------------
     * A READ THAT FAILS IS AN EMPTY STATE. A WRITE THAT FAILS IS AN ERROR.
     * -----------------------------------------------------------------------
     * db() already returns null rather than throwing, so a checkout with no
     * database renders signed-out on empty states instead of a stack trace.
     * A database that is *reachable* but missing a table did not get the same
     * treatment, and on 18 September that took the whole site down: one table
     * from a migration that had never been imported, and every visitor got a
     * blank 500 — including the four pages that never touch it.
     *
     * So a failing READ now behaves exactly like an unreachable database:
     * null, an empty array, an empty state. The page still renders, minus the
     * part that has no data, which is what every component already draws.
     *
     * A failing WRITE still throws, and that is the important half. Swallowing
     * one would mean a caller being told it saved something it did not — an
     * account half-created, a goal that vanishes, a sync reported as fine. A
     * statement inside a transaction throws too, whichever kind it is, because
     * the caller is managing a rollback and has to hear about it.
     *
     * Either way it goes in the log with the statement that failed. This makes
     * the site survive a mistake; it does not make the mistake invisible.
     */
    function db_run(string $sql, array $params = []): ?PDOStatement
    {
        $pdo = db();
        if ($pdo === null) {
            return null;
        }

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException $e) {
            if ($pdo->inTransaction() || !db_is_read($sql)) {
                throw $e;
            }

            db_note_failure($e->getMessage());

            error_log(sprintf(
                '[jolu] read failed, rendering the empty state instead: %s -- statement: %s',
                $e->getMessage(),
                preg_replace('/\s+/', ' ', trim($sql))
            ));

            return null;
        }
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

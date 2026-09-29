<?php
/**
 * Copies one database into another, then proves the copy is the same.
 *
 *     php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify
 *     php tools/copy-database.php --from=luc_healthapp --to=luc_ownify --to-user=luc_ownify --verify
 *
 * Written for moving the production database on Hestia from `luc_healthapp`
 * to `luc_ownify` when the app was renamed to Ownify, and usable for any copy
 * like it. The target is created beforehand (in the Hestia panel); this only
 * fills it. The whole procedure is in docs/OWNIFY-MIGRATION.md, section A.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT NEVER DOES
 * ---------------------------------------------------------------------------
 * It never writes to the source. The source connection is a read-only
 * transaction and only ever runs SELECT and SHOW, so however a run ends, the
 * old database is exactly as it was — it stays the backup until the new one
 * has proven itself, and dropping it is left to a person, later.
 *
 * It never overwrites a target that already has something in it. A second
 * attempt into the same target has to say so by naming it twice:
 * --replace=<target>, which empties the target (and only the target) first.
 *
 * It prints no passwords. The connection settings are the app's own
 * (config/database.php, config/database.local.php, the DB_* variables); a
 * different user for the target is given with --to-user, and its password is
 * asked for without echoing it, or read from TARGET_DB_PASSWORD.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT COPIES
 * ---------------------------------------------------------------------------
 * Every table exactly as SHOW CREATE TABLE has it — columns, indexes, foreign
 * keys, charset, collation and the AUTO_INCREMENT counter, so an id that was
 * ever handed out is never handed out again — and then every row, with the
 * foreign-key checks off while loading (so the order of the tables does not
 * matter) and the rows read in one consistent snapshot of the source (so a
 * write to the source during the copy cannot leave half of it in). Generated
 * columns are left for the target to compute. Views, triggers, procedures,
 * functions and events are copied too, after the rows, so no trigger fires on
 * the copy; their DEFINER is left out, so they belong to whoever runs this.
 *
 * ---------------------------------------------------------------------------
 * HOW IT CHECKS
 * ---------------------------------------------------------------------------
 * Table by table: the same definition (only the AUTO_INCREMENT figure may
 * differ, and it may only be higher in the copy), the same number of rows,
 * and the same SHA-256 over every value of every row in primary-key order —
 * the source's taken inside the snapshot the rows were copied from. Then
 * every foreign key of the copy is checked for rows pointing at nothing.
 * Views, triggers, routines and events are compared by name.
 *
 * --verify runs only the checks, against a copy made some other way (by
 * phpMyAdmin, say) or made earlier. It compares with the source as it is NOW,
 * so run it before anything writes to either side.
 *
 * Exit code: 0 when the copy is the same, 1 when it is not or the copy
 * failed, 2 for a mistake on the command line.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$known   = ['from:', 'to:', 'to-user:', 'verify', 'replace:', 'help'];
$options = getopt('', $known);

/* getopt() drops what it does not know without a word; a mistyped --verify
   must not quietly become a copy. */
$arguments = array_slice($argv ?? [], 1);
for ($i = 0; $i < count($arguments); $i++) {
    $argument = $arguments[$i];
    $name     = preg_replace('/^--([^=]*).*$/s', '$1', $argument);
    if (!str_starts_with($argument, '--') || !preg_grep('/^' . preg_quote($name, '/') . ':?$/', $known)) {
        fwrite(STDERR, "Unknown argument: $argument (see --help)\n");
        exit(2);
    }
    /* "--from luc_healthapp": the value is the next argument. */
    if (!str_contains($argument, '=') && in_array($name . ':', $known, true)) {
        $i++;
    }
}

if (isset($options['help']) || !isset($options['from'], $options['to'])) {
    fwrite(STDERR, <<<'USAGE'
        Copies a database into another and checks the copy (docs/OWNIFY-MIGRATION.md).

          php tools/copy-database.php --from=SOURCE --to=TARGET [options]

          --to-user=USER     connect to TARGET as USER; the password is asked for,
                             or read from TARGET_DB_PASSWORD
          --verify           only compare SOURCE and TARGET, copy nothing
          --replace=TARGET   empty TARGET before copying (TARGET only, never SOURCE)

        TARGET must exist already (on Hestia: the panel, DB, Add Database).
        The connection settings are the app's own: config/database.php,
        config/database.local.php and the DB_* variables.

        USAGE);
    exit(2);
}

$source  = (string) $options['from'];
$target  = (string) $options['to'];
$verify  = isset($options['verify']);
$replace = $options['replace'] ?? null;

foreach (['--from' => $source, '--to' => $target] as $flag => $name) {
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        fwrite(STDERR, "$flag: a database name is letters, digits and _ only.\n");
        exit(2);
    }
}

if (strcasecmp($source, $target) === 0) {
    fwrite(STDERR, "--from and --to are the same database; there is nothing to copy.\n");
    exit(2);
}

if ($replace !== null && $replace !== $target) {
    fwrite(STDERR, "--replace must name the target again (--replace=$target), so emptying a database is never a slip.\n");
    exit(2);
}

if ($verify && $replace !== null) {
    fwrite(STDERR, "--verify only reads; it cannot be combined with --replace.\n");
    exit(2);
}

$settings = (array) require dirname(__DIR__) . '/config/database.php';

$targetUser     = $options['to-user'] ?? null;
$targetPassword = null;

if ($targetUser !== null) {
    $fromEnvironment = getenv('TARGET_DB_PASSWORD');
    $targetPassword  = is_string($fromEnvironment) && $fromEnvironment !== ''
        ? $fromEnvironment
        : ask_hidden("Password for $targetUser: ");
}

/* ---------------------------------------------------------- connecting */

/** A connection for copying: exceptions, every value as the server's own text. */
function copy_connect(array $settings, ?string $database, ?string $user = null, ?string $password = null): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $settings['host'], (int) $settings['port'], $settings['charset'] ?? 'utf8mb4');

    if ($database !== null) {
        $dsn .= ';dbname=' . $database;
    }

    $pdo = new PDO($dsn, $user ?? (string) $settings['username'], $password ?? (string) $settings['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        /* The exact text the server stores, so what is written back is
           byte for byte what was read — no float or date round trip. */
        PDO::ATTR_STRINGIFY_FETCHES  => true,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    /* TIMESTAMP columns are converted to and from the session's time zone;
       UTC on both sides has no daylight-saving hour to lose or repeat. */
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function ask_hidden(string $label): string
{
    fwrite(STDERR, $label);
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);

    if ($hide) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($hide) {
        @shell_exec('stty echo 2>/dev/null');
    }

    fwrite(STDERR, "\n");

    return rtrim((string) $line, "\r\n");
}

function say(string $line = ''): void
{
    echo $line, "\n";
}

function quote_name(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

try {
    $from = copy_connect($settings, $source);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot open the source database $source: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $to = copy_connect($settings, $target, $targetUser, $targetPassword);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot open the target database $target: " . $e->getMessage() . "\n");
    if (str_contains($e->getMessage(), '[1049]')) {
        fwrite(STDERR, "It has to exist first: create it in the Hestia panel (DB, Add Database).\n");
    }
    exit(1);
}

/* --------------------------------------------------------- inventory */

/**
 * What a database holds, by kind and name.
 *
 * @return array{tables: list<string>, views: list<string>, triggers: list<string>,
 *               procedures: list<string>, functions: list<string>, events: list<string>}
 */
function inventory(PDO $pdo): array
{
    $list = static fn (string $sql): array => array_map(
        static fn (array $row): string => (string) $row[0],
        $pdo->query($sql)->fetchAll()
    );

    return [
        'tables'     => $list("SELECT TABLE_NAME FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"),
        'views'      => $list("SELECT TABLE_NAME FROM information_schema.VIEWS
                                WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"),
        'triggers'   => $list("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                                WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"),
        'procedures' => $list("SELECT ROUTINE_NAME FROM information_schema.ROUTINES
                                WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' ORDER BY ROUTINE_NAME"),
        'functions'  => $list("SELECT ROUTINE_NAME FROM information_schema.ROUTINES
                                WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'FUNCTION' ORDER BY ROUTINE_NAME"),
        'events'     => $list("SELECT EVENT_NAME FROM information_schema.EVENTS
                                WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"),
    ];
}

/** SHOW CREATE TABLE, without the AUTO_INCREMENT figure, which is compared on its own. */
function table_definition(PDO $pdo, string $table): array
{
    $create = (string) $pdo->query('SHOW CREATE TABLE ' . quote_name($table))->fetch()[1];
    $next   = preg_match('/\sAUTO_INCREMENT=(\d+)/', $create, $m) ? (int) $m[1] : null;

    return [preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create), $next];
}

/** @return list<string> the columns rows are ordered by: the primary key, else every column */
function order_columns(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                                 ORDER BY ORDINAL_POSITION");
    $statement->execute([$table]);
    $key = array_map(static fn (array $r): string => (string) $r[0], $statement->fetchAll());

    return $key !== [] ? $key : all_columns($pdo, $table);
}

/** @return list<string> */
function all_columns(PDO $pdo, string $table, bool $writableOnly = false): array
{
    $statement = $pdo->prepare('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $statement->execute([$table]);
    $columns = [];

    foreach ($statement->fetchAll() as [$name, $extra]) {
        /* A generated column is computed by the server and refuses a value. */
        if ($writableOnly && preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $extra)) {
            continue;
        }
        $columns[] = (string) $name;
    }

    return $columns;
}

/** Rows of $table in a stable order, one at a time, and what is needed to hash them. */
function table_rows(PDO $pdo, string $table, array $columns): PDOStatement
{
    $order = implode(', ', array_map('quote_name', order_columns($pdo, $table)));

    /* Unbuffered: a big table streams instead of being held in memory. */
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $statement = $pdo->query(sprintf(
        'SELECT %s FROM %s ORDER BY %s',
        implode(', ', array_map('quote_name', $columns)),
        quote_name($table),
        $order
    ));
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    return $statement;
}

/** Adds one row to a running hash; NULL and '' and 'NULL' all hash differently. */
function hash_row($context, array $row): void
{
    foreach ($row as $value) {
        hash_update($context, $value === null ? "\x00N" : "\x00S" . strlen((string) $value) . ':' . $value);
    }
    hash_update($context, "\x01");
}

/** Every value of every row, in primary-key order: [row count, SHA-256]. */
function table_fingerprint(PDO $pdo, string $table): array
{
    $columns = all_columns($pdo, $table);
    $context = hash_init('sha256');
    $count   = 0;

    $rows = table_rows($pdo, $table, $columns);
    while (($row = $rows->fetch()) !== false) {
        hash_row($context, $row);
        $count++;
    }
    $rows->closeCursor();

    return [$count, hash_final($context)];
}

/** The CREATE statement of a view, trigger, routine or event, with no DEFINER and no source name in it. */
function portable_definition(string $sql, string $sourceName, string $targetName): string
{
    $sql = (string) preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql);

    return str_replace(quote_name($sourceName) . '.', quote_name($targetName) . '.', $sql);
}

$have = inventory($from);
$got  = inventory($to);

say("Source: $source   ({$settings['host']}, as {$settings['username']})");
say("Target: $target   ({$settings['host']}, as " . ($targetUser ?? $settings['username']) . ')');
say(sprintf(
    'Source holds %d tables, %d views, %d triggers, %d procedures, %d functions, %d events.',
    count($have['tables']),
    count($have['views']),
    count($have['triggers']),
    count($have['procedures']),
    count($have['functions']),
    count($have['events'])
));
say();

/* ------------------------------------------------------------- copy */

$sourceFingerprints = [];

if (!$verify) {
    $targetHasSomething = array_filter($got, static fn (array $names): bool => $names !== []) !== [];

    if ($targetHasSomething && $replace === null) {
        fwrite(STDERR, "The target $target is not empty, so nothing was copied. To empty it and copy again,\n"
            . "add --replace=$target (the source is never touched either way).\n");
        exit(1);
    }

    /* Everything besides tables, read before the target is touched: a
       definition this user may not see (a routine is only shown in full to
       whoever defined it) stops the run while the target is still as it was. */
    $objects = [];
    foreach ([
        'procedures' => ['SHOW CREATE PROCEDURE', 2],
        'functions'  => ['SHOW CREATE FUNCTION', 2],
        'triggers'   => ['SHOW CREATE TRIGGER', 2],
        'events'     => ['SHOW CREATE EVENT', 3],
    ] as $kind => [$show, $column]) {
        foreach ($have[$kind] as $name) {
            $definition = $from->query($show . ' ' . quote_name($name))->fetch();
            if (!is_string($definition[$column] ?? null) || trim($definition[$column]) === '') {
                fwrite(STDERR, sprintf(
                    "The definition of %s %s cannot be read as %s (only its definer, or a user with rights on mysql.proc, sees it).\n"
                        . "Nothing was copied. Run this as that user, or recreate %s by hand in the copy afterwards.\n",
                    rtrim($kind, 's'),
                    $name,
                    $settings['username'],
                    $name
                ));
                exit(1);
            }
            $objects[] = [rtrim($kind, 's'), $name, (string) $definition[1], (string) $definition[$column]];
        }
    }

    $to->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    $to->exec('SET SESSION foreign_key_checks = 0');
    $to->exec('SET SESSION unique_checks = 0');

    if ($targetHasSomething) {
        say("Emptying $target first (--replace) …");
        foreach ($got['events'] as $name) {
            $to->exec('DROP EVENT IF EXISTS ' . quote_name($name));
        }
        foreach ($got['triggers'] as $name) {
            $to->exec('DROP TRIGGER IF EXISTS ' . quote_name($name));
        }
        foreach ($got['procedures'] as $name) {
            $to->exec('DROP PROCEDURE IF EXISTS ' . quote_name($name));
        }
        foreach ($got['functions'] as $name) {
            $to->exec('DROP FUNCTION IF EXISTS ' . quote_name($name));
        }
        foreach ($got['views'] as $name) {
            $to->exec('DROP VIEW IF EXISTS ' . quote_name($name));
        }
        foreach ($got['tables'] as $name) {
            $to->exec('DROP TABLE IF EXISTS ' . quote_name($name));
        }
    }

    /* One snapshot for every read of the source: the rows copied, and the
       fingerprint they are checked against, are the same moment in time. */
    $from->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $from->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

    try {
        foreach ($have['tables'] as $table) {
            $to->exec((string) $from->query('SHOW CREATE TABLE ' . quote_name($table))->fetch()[1]);

            $all      = all_columns($from, $table);
            $writable = all_columns($from, $table, true);
            $keep     = array_keys(array_intersect($all, $writable));
            $perBatch = max(1, min(500, intdiv(60000, max(1, count($writable)))));

            $insert = static function (array $batch) use ($to, $table, $writable): void {
                $row = '(' . implode(', ', array_fill(0, count($writable), '?')) . ')';
                $sql = sprintf(
                    'INSERT INTO %s (%s) VALUES %s',
                    quote_name($table),
                    implode(', ', array_map('quote_name', $writable)),
                    implode(', ', array_fill(0, count($batch), $row))
                );
                $to->prepare($sql)->execute(array_merge(...$batch));
            };

            $context = hash_init('sha256');
            $count   = 0;
            $batch   = [];
            $bytes   = 0;

            $rows = table_rows($from, $table, $all);
            while (($row = $rows->fetch()) !== false) {
                hash_row($context, $row);
                $count++;

                $values  = array_values(array_intersect_key($row, array_flip($keep)));
                $batch[] = $values;
                $bytes  += array_sum(array_map(static fn ($v): int => strlen((string) $v), $values));

                if (count($batch) >= $perBatch || $bytes > 1_000_000) {
                    $insert($batch);
                    $batch = [];
                    $bytes = 0;
                }
            }
            $rows->closeCursor();

            if ($batch !== []) {
                $insert($batch);
            }

            $sourceFingerprints[$table] = [$count, hash_final($context)];
            say(sprintf('  copied  %-28s %8d rows', $table, $count));
        }

        /* Views may use each other: create what can be created, and go round
           again for the rest, until a round makes no progress. */
        $pending = $have['views'];
        while ($pending !== []) {
            $left = [];
            foreach ($pending as $view) {
                $sql = (string) $from->query('SHOW CREATE VIEW ' . quote_name($view))->fetch()[1];
                try {
                    $to->exec(portable_definition($sql, $source, $target));
                    say("  copied  view $view");
                } catch (PDOException $e) {
                    $left[] = $view;
                    $lastError = $e->getMessage();
                }
            }
            if (count($left) === count($pending)) {
                throw new RuntimeException('These views could not be created: ' . implode(', ', $left) . ' — ' . ($lastError ?? ''));
            }
            $pending = $left;
        }

        /* Triggers come after the rows, so none fires on the copy. Each is
           created under the sql_mode it was written under, as the original
           was, and the loading mode is put back afterwards. */
        foreach ($objects as [$kind, $name, $sqlMode, $sql]) {
            $to->prepare('SET SESSION sql_mode = ?')->execute([$sqlMode]);
            $to->exec(portable_definition($sql, $source, $target));
            $to->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            say("  copied  $kind $name");
        }
    } catch (Throwable $e) {
        $from->exec('ROLLBACK');
        fwrite(STDERR, "\nThe copy stopped: " . $e->getMessage() . "\n");
        fwrite(STDERR, "The source $source is untouched. The target $target is incomplete; run again with --replace=$target.\n");
        exit(1);
    }

    $from->exec('COMMIT');
    $to->exec('SET SESSION foreign_key_checks = 1');
    $to->exec('SET SESSION unique_checks = 1');
    $got = inventory($to);
    say();
}

/* ------------------------------------------------------------ check */

$problems = 0;

$report = static function (bool $ok, string $what) use (&$problems): void {
    if (!$ok) {
        $problems++;
    }
    say(($ok ? '  ok    ' : '  DIFF  ') . $what);
};

say('Checking the copy against the source' . ($verify ? ' as it is now' : '') . ' …');

foreach (['tables', 'views', 'triggers', 'procedures', 'functions', 'events'] as $kind) {
    $missing = array_diff($have[$kind], $got[$kind]);
    $extra   = array_diff($got[$kind], $have[$kind]);

    if ($have[$kind] === [] && $got[$kind] === []) {
        continue;
    }

    $report(
        $missing === [] && $extra === [],
        sprintf('%s: %d in both', $kind, count(array_intersect($have[$kind], $got[$kind])))
            . ($missing !== [] ? '; missing from the copy: ' . implode(', ', $missing) : '')
            . ($extra !== [] ? '; only in the copy: ' . implode(', ', $extra) : '')
    );
}

if ($verify) {
    /* No copy was made in this run, so the source is read in a snapshot now. */
    $from->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $from->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
}

$totalRows = 0;

foreach (array_intersect($have['tables'], $got['tables']) as $table) {
    [$sourceDefinition, $sourceNext] = table_definition($from, $table);
    [$targetDefinition, $targetNext] = table_definition($to, $table);

    [$sourceCount, $sourceHash] = $sourceFingerprints[$table] ?? table_fingerprint($from, $table);
    [$targetCount, $targetHash] = table_fingerprint($to, $table);
    $totalRows += $targetCount;

    $sameDefinition = $sourceDefinition === $targetDefinition;
    $counterOk      = $sourceNext === null ? $targetNext === null : $targetNext !== null && $targetNext >= $sourceNext;

    $report(
        $sameDefinition && $counterOk && $sourceCount === $targetCount && hash_equals($sourceHash, $targetHash),
        sprintf('%-28s %8d rows', $table, $targetCount)
            . ($sameDefinition ? '' : '; the definition differs (columns, keys, foreign keys or collation)')
            . ($counterOk ? '' : sprintf('; AUTO_INCREMENT is %s in the copy, %s in the source', $targetNext ?? 'none', $sourceNext ?? 'none'))
            . ($sourceCount === $targetCount ? '' : "; the source has $sourceCount rows")
            . ($sourceCount === $targetCount && !hash_equals($sourceHash, $targetHash) ? '; same number of rows, different contents' : '')
    );
}

if ($verify) {
    $from->exec('COMMIT');
}

/* Rows pointing at nothing, foreign key by foreign key: the checks were off
   while loading, so this is where a broken reference would show. */
$keys = $to->query("SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
                      FROM information_schema.KEY_COLUMN_USAGE k
                     WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                     ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION")->fetchAll();

$constraints = [];
foreach ($keys as [$name, $table, $column, $parent, $parentColumn]) {
    $constraints[$table . '.' . $name]['table']    = $table;
    $constraints[$table . '.' . $name]['parent']   = $parent;
    $constraints[$table . '.' . $name]['pairs'][]  = [$column, $parentColumn];
}

$orphans = 0;
foreach ($constraints as $label => $constraint) {
    $on      = implode(' AND ', array_map(static fn (array $p): string => 'c.' . quote_name($p[0]) . ' = p.' . quote_name($p[1]), $constraint['pairs']));
    $present = implode(' AND ', array_map(static fn (array $p): string => 'c.' . quote_name($p[0]) . ' IS NOT NULL', $constraint['pairs']));
    $missing = 'p.' . quote_name($constraint['pairs'][0][1]) . ' IS NULL';
    $count   = (int) $to->query(sprintf(
        'SELECT COUNT(*) FROM %s c LEFT JOIN %s p ON %s WHERE %s AND %s',
        quote_name($constraint['table']),
        quote_name($constraint['parent']),
        $on,
        $present,
        $missing
    ))->fetch()[0];

    if ($count > 0) {
        $orphans += $count;
        $report(false, "foreign key $label: $count rows point at nothing in {$constraint['parent']}");
    }
}
$report($orphans === 0, sprintf(
    'foreign keys: %d checked, %s',
    count($constraints),
    $orphans === 0 ? 'no row points at nothing' : "$orphans rows point at nothing"
));

say();

if ($problems === 0) {
    say(sprintf('The copy is the same: %d tables, %d rows. The source %s was not changed.', count($got['tables']), $totalRows, $source));
    exit(0);
}

say("$problems difference(s) — see DIFF above. Do not switch the app to $target yet. The source $source was not changed.");
exit(1);

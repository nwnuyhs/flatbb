<?php
/**
 * Database layer. PDO with SQLite (default) and MySQL 5.7+.
 *
 * Rules for all code, core and plugins:
 *  - Query with q()/one()/val()/col(); never build SQL from user input.
 *  - Quote identifiers with backticks (valid in both engines).
 *  - Create schema only with db_create_table()/db_ensure_columns()/db_create_index().
 *  - Write with db_insert()/db_update()/db_delete()/db_upsert()/db_insert_ignore().
 *  - Timestamps are integer unix seconds; booleans are 0/1 integers; JSON is stored in TEXT.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = db_connect((array)config('db', []));
    return $pdo;
}

function db_driver(): string
{
    return (string)(config('db')['driver'] ?? 'sqlite');
}

function db_is_mysql(): bool
{
    return db_driver() === 'mysql';
}

/** Open a connection from a config array. Used by db() and by the installer. */
function db_connect(array $c): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($c['driver'] ?? 'sqlite') === 'mysql') {
        $dsn = 'mysql:host=' . ($c['host'] ?? '127.0.0.1') . ';port=' . (int)($c['port'] ?? 3306) . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), $opts);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'");
        return $pdo;
    }
    $path = (string)($c['path'] ?? DATA_DIR . '/flatbb.sqlite');
    $pdo = new PDO('sqlite:' . $path, null, null, $opts);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=OFF; PRAGMA temp_store=MEMORY');
    return $pdo;
}

function sql_query_count(bool $inc = false): int
{
    static $n = 0;
    if ($inc) $n++;
    return $n;
}

/** Run a prepared query. */
function q(string $sql, array $params = []): PDOStatement
{
    sql_query_count(true);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** First column of every row. */
function col(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN, 0);
}

/** Run $fn inside a transaction; nested calls join the outer transaction. */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function sql_marks(int $count): string
{
    return implode(',', array_fill(0, max(1, $count), '?'));
}

/** Fetch rows by primary key, keyed by id. Chunks large id lists. */
function rows_by_ids(string $table, array $ids, string $cols = '*', string $key = 'id'): array
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    if ($ids === []) return [];
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (all("SELECT {$cols} FROM `{$table}` WHERE `{$key}` IN (" . sql_marks(count($chunk)) . ')', $chunk) as $row) {
            $out[(int)$row[$key]] = $row;
        }
    }
    return $out;
}

/** LIKE pattern for user input, to be used with `LIKE ? ESCAPE '!'` (a backslash escape character is a syntax error on MySQL). */
function db_like(string $s): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s) . '%';
}

/* ---------------------------------------------------------------- writes */

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . '`) VALUES (' . sql_marks(count($cols)) . ')', array_values($data));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $params = []): int
{
    if ($data === []) return 0;
    $set = implode(',', array_map(static fn(string $c): string => "`{$c}`=?", array_keys($data)));
    return q("UPDATE `{$table}` SET {$set} WHERE {$where}", array_merge(array_values($data), $params))->rowCount();
}

function db_delete(string $table, string $where, array $params = []): int
{
    return q("DELETE FROM `{$table}` WHERE {$where}", $params)->rowCount();
}

/** Insert or update on conflict of $keys (must be PK or UNIQUE). */
function db_upsert(string $table, array $data, array $keys): void
{
    $cols = array_keys($data);
    $update = array_values(array_diff($cols, $keys));
    $colsSql = '`' . implode('`,`', $cols) . '`';
    $marks = sql_marks(count($cols));
    if (db_is_mysql()) {
        $set = $update === [] ? '`' . $keys[0] . '`=`' . $keys[0] . '`' : implode(',', array_map(static fn(string $c): string => "`{$c}`=VALUES(`{$c}`)", $update));
        q("INSERT INTO `{$table}` ({$colsSql}) VALUES ({$marks}) ON DUPLICATE KEY UPDATE {$set}", array_values($data));
        return;
    }
    $conflict = '`' . implode('`,`', $keys) . '`';
    $set = $update === [] ? 'NOTHING' : 'UPDATE SET ' . implode(',', array_map(static fn(string $c): string => "`{$c}`=excluded.`{$c}`", $update));
    q("INSERT INTO `{$table}` ({$colsSql}) VALUES ({$marks}) ON CONFLICT({$conflict}) DO {$set}", array_values($data));
}

/** Insert unless a unique key already exists. Returns true when inserted. */
function db_insert_ignore(string $table, array $data): bool
{
    $cols = array_keys($data);
    $sql = (db_is_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . " INTO `{$table}` (`" . implode('`,`', $cols) . '`) VALUES (' . sql_marks(count($cols)) . ')';
    return q($sql, array_values($data))->rowCount() > 0;
}

/** Atomic counter update: db_increment('fb_topics', 'view_count', 1, 'id=?', [$id]) */
function db_increment(string $table, string $column, int $delta, string $where, array $params = []): void
{
    q("UPDATE `{$table}` SET `{$column}`=`{$column}`+? WHERE {$where}", array_merge([$delta], $params));
}

/* ---------------------------------------------------------------- schema */

/**
 * Portable column types. Use these names in db_create_table()/db_ensure_columns():
 * id, uint, int, bigint, bool, float, string (255), key (191, indexable), text, mediumtext.
 */
function db_types(): array
{
    if (db_is_mysql()) {
        return [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'uint' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'int' => 'INT NOT NULL DEFAULT 0',
            'bigint' => 'BIGINT NOT NULL DEFAULT 0',
            'bool' => 'TINYINT NOT NULL DEFAULT 0',
            'float' => 'DOUBLE NOT NULL DEFAULT 0',
            'string' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'key' => "VARCHAR(191) NOT NULL DEFAULT ''",
            'text' => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
        ];
    }
    return [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'uint' => 'INTEGER NOT NULL DEFAULT 0',
        'int' => 'INTEGER NOT NULL DEFAULT 0',
        'bigint' => 'INTEGER NOT NULL DEFAULT 0',
        'bool' => 'INTEGER NOT NULL DEFAULT 0',
        'float' => 'REAL NOT NULL DEFAULT 0',
        'string' => "TEXT NOT NULL DEFAULT ''",
        'key' => "TEXT NOT NULL DEFAULT ''",
        'text' => 'TEXT',
        'mediumtext' => 'TEXT',
    ];
}

function db_column_sql(string $name, string $type): string
{
    $types = db_types();
    return "`{$name}` " . ($types[$type] ?? $type);
}

/**
 * Create a table if missing. $columns: ['id' => 'id', 'title' => 'string', 'body' => 'text', 'flag' => 'TINYINT NOT NULL DEFAULT 1'].
 * Raw SQL types are allowed but must work on both engines.
 */
function db_create_table(string $table, array $columns): void
{
    if (db_table_exists($table)) {
        db_ensure_columns($table, $columns);
        return;
    }
    $defs = [];
    foreach ($columns as $name => $type) $defs[] = db_column_sql((string)$name, $type);
    $suffix = db_is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    q("CREATE TABLE `{$table}` (" . implode(',', $defs) . ')' . $suffix);
}

function db_drop_table(string $table): void
{
    q("DROP TABLE IF EXISTS `{$table}`");
}

function db_table_exists(string $table): bool
{
    if (db_is_mysql()) return (bool)val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
    return (bool)val("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name=?", [$table]);
}

function db_columns(string $table): array
{
    if (db_is_mysql()) return col('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', [$table]);
    return array_map(static fn(array $r): string => (string)$r['name'], all("PRAGMA table_info(`{$table}`)"));
}

/** Add any missing columns (idempotent). */
function db_ensure_columns(string $table, array $columns): void
{
    $have = array_flip(db_columns($table));
    foreach ($columns as $name => $type) {
        if (isset($have[$name]) || $type === 'id') continue;
        q("ALTER TABLE `{$table}` ADD COLUMN " . db_column_sql((string)$name, $type));
    }
}

function db_drop_column(string $table, string $column): void
{
    if (!in_array($column, db_columns($table), true)) return;
    q("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
}

function db_index_exists(string $table, string $index): bool
{
    if (db_is_mysql()) return (bool)val('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?', [$table, $index]);
    return (bool)val("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name=?", [$index]);
}

/** db_create_index('fb_posts', 'idx_posts_topic', ['topic_id','id']) */
function db_create_index(string $table, string $index, array $columns, bool $unique = false): void
{
    if (db_index_exists($table, $index)) return;
    q('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX `{$index}` ON `{$table}` (`" . implode('`,`', $columns) . '`)');
}

function db_drop_index(string $table, string $index): void
{
    if (!db_index_exists($table, $index)) return;
    q(db_is_mysql() ? "DROP INDEX `{$index}` ON `{$table}`" : "DROP INDEX `{$index}`");
}

/** Full-text index for MySQL; SQLite uses FTS5 virtual tables (see search.php). */
function db_create_fulltext(string $table, string $index, array $columns): void
{
    if (!db_is_mysql() || db_index_exists($table, $index)) return;
    q("ALTER TABLE `{$table}` ADD FULLTEXT `{$index}` (`" . implode('`,`', $columns) . '`)');
}

/** Portable "greatest of two expressions". */
function db_greatest(string $a, string $b): string
{
    return db_is_mysql() ? "GREATEST({$a},{$b})" : "MAX({$a},{$b})";
}

/** Portable random ordering. */
function db_random(): string
{
    return db_is_mysql() ? 'RAND()' : 'RANDOM()';
}

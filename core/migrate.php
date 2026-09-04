<?php
/**
 * Database migration: copy every fb_* and plugin_* table from a SQLite file into the current database
 * (normally MySQL). Ids are preserved. Destination tables are emptied first.
 *
 *   php flatbb migrate:import /path/to/flatbb.sqlite
 *   Admin → Tools → Import from SQLite
 *
 * Steps for moving a site from SQLite to MySQL:
 *   1. Install a fresh flatbb on MySQL (same version), copy plugins/ and uploads/ over.
 *   2. Enable the same plugins (so their tables exist), then run the import.
 *   3. The tool rebuilds the search index and counters when it finishes.
 */

/** Returns a report array: ['tables' => [name => rows], 'skipped' => [name => reason], 'seconds' => float]. */
function migrate_import_sqlite(string $file, ?callable $progress = null): array
{
    if (!is_file($file)) throw new RuntimeException('SQLite file not found: ' . $file);
    if (!db_is_mysql() && realpath($file) === realpath((string)(config('db')['path'] ?? ''))) throw new RuntimeException('Source and destination are the same database');
    $src = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $start = microtime(true);
    $report = ['tables' => [], 'skipped' => [], 'seconds' => 0.0];
    $names = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE 'fb\\_%' ESCAPE '\\' OR name LIKE 'plugin\\_%' ESCAPE '\\') AND name NOT LIKE '%\\_fts%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    schema_install();
    foreach ($names as $table) {
        if (!db_table_exists($table)) {
            if (!migrate_create_table_like_sqlite($src, $table)) { $report['skipped'][$table] = 'could not create table'; continue; }
        }
        $src_cols = array_map(static fn(array $c): string => (string)$c['name'], $src->query("PRAGMA table_info(`{$table}`)")->fetchAll());
        $dst_cols = db_columns($table);
        $cols = array_values(array_intersect($src_cols, $dst_cols));
        if ($cols === []) { $report['skipped'][$table] = 'no matching columns'; continue; }
        if ($table === 'fb_settings') {
            // keep the destination's database-specific settings but take everything else
            $keep = ['installed_at', 'rewrite', 'plugin_assets_hash', 'stats_cache'];
            q('DELETE FROM `fb_settings` WHERE `key` NOT IN (' . sql_marks(count($keep)) . ')', $keep);
        } else {
            q("DELETE FROM `{$table}`");
        }
        $n = 0;
        $sel = $src->query('SELECT `' . implode('`,`', $cols) . "` FROM `{$table}`" . ($table === 'fb_settings' ? " WHERE `key` NOT IN ('installed_at','rewrite','plugin_assets_hash','stats_cache')" : ''));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . sql_marks(count($cols)) . ')';
        $batch = [];
        $flush = static function () use (&$batch, $sql): void {
            if ($batch === []) return;
            tx(static function () use ($batch, $sql): void {
                $st = db()->prepare($sql);
                foreach ($batch as $row) $st->execute(array_values($row));
            });
            $batch = [];
        };
        while (($row = $sel->fetch()) !== false) {
            $batch[] = $row;
            $n++;
            if (count($batch) >= 500) { $flush(); if ($progress) $progress($table, $n); }
        }
        $flush();
        $report['tables'][$table] = $n;
        if ($progress) $progress($table, $n);
    }
    if (db_is_mysql()) {
        foreach (array_keys($report['tables']) as $table) {
            if (in_array('id', db_columns($table), true)) {
                $max = (int)val("SELECT COALESCE(MAX(id),0) FROM `{$table}`");
                q("ALTER TABLE `{$table}` AUTO_INCREMENT=" . ($max + 1));
            }
        }
    }
    request_cache('settings', null, true);
    request_cache('categories', null, true);
    request_cache('groups', null, true);
    plugins(true);
    search_rebuild();
    save_settings(['stats_cache' => '']);
    plugin_assets_build();
    $report['seconds'] = round(microtime(true) - $start, 1);
    fire('migrate.after_import', ['report' => $report]);
    return $report;
}

/** Create a plugin table in the destination by translating SQLite column types. */
function migrate_create_table_like_sqlite(PDO $src, string $table): bool
{
    $info = $src->query("PRAGMA table_info(`{$table}`)")->fetchAll();
    if ($info === []) return false;
    $cols = [];
    foreach ($info as $c) {
        $type = strtoupper((string)$c['type']);
        $name = (string)$c['name'];
        if ((int)$c['pk'] === 1 && str_contains($type, 'INT')) { $cols[$name] = 'id'; continue; }
        $cols[$name] = match (true) {
            str_contains($type, 'INT') => 'bigint',
            str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB') => 'float',
            default => 'mediumtext',
        };
    }
    db_create_table($table, $cols);
    return true;
}

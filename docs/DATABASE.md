# Database

flatbb runs on MySQL 5.7+ / MariaDB 10.2+ (`utf8mb4`, the installer default) or SQLite 3 (`data/flatbb-<random>.sqlite`). The same code path serves both; only `core/db.php` knows the dialect.

## Portability rules

- Quote identifiers with backticks. Bind values with `?`.
- Types come from `db_types()`:

  | Name | SQLite | MySQL |
  | --- | --- | --- |
  | `id` | INTEGER PRIMARY KEY AUTOINCREMENT | INT UNSIGNED AUTO_INCREMENT PRIMARY KEY |
  | `uint` / `int` / `bigint` | INTEGER NOT NULL DEFAULT 0 | INT UNSIGNED / INT / BIGINT NOT NULL DEFAULT 0 |
  | `bool` | INTEGER NOT NULL DEFAULT 0 | TINYINT NOT NULL DEFAULT 0 |
  | `float` | REAL | DOUBLE |
  | `string` | TEXT NOT NULL DEFAULT '' | VARCHAR(255) NOT NULL DEFAULT '' |
  | `key` | TEXT NOT NULL DEFAULT '' | VARCHAR(191) NOT NULL DEFAULT '' (safe to index/unique on utf8mb4) |
  | `text` / `mediumtext` | TEXT (nullable) | TEXT / MEDIUMTEXT (nullable) |

- Never index a `string`/`text` column on MySQL; use `key` for anything that needs an index or unique constraint.
- Timestamps are unix seconds in `uint`. Booleans are 0/1. JSON is stored as text (`json_encode_value()` / `json_decode_array()`), never as a JSON column.
- Avoid: CTEs, window functions, `INSERT … ON DUPLICATE KEY UPDATE` written by hand (use `db_upsert()`), `LIMIT` in subqueries with `IN` on MySQL, `RETURNING`, `ILIKE`, `||` concatenation (use `CONCAT` on MySQL / `||` on SQLite → avoid both, concatenate in PHP), `GREATEST` (use `db_greatest()`), `RANDOM()`/`RAND()` (use `db_random()`).
- Full-text search is abstracted in `core/search.php`; do not query `fb_search_fts` directly.
- `LIKE` needs `ESCAPE '!'` and `db_like()` for user input (never a backslash as escape character: MySQL treats it as a string escape and fails with error 1064).

## Helpers

```php
db_create_table('plugin_x_items', ['id' => 'id', 'name' => 'key', 'body' => 'text', 'created_at' => 'uint']);
db_ensure_columns('plugin_x_items', ['score' => 'int']);      // adds missing columns only
db_create_index('plugin_x_items', 'ux_x_items_name', ['name'], true);
db_drop_index(...); db_drop_column(...); db_drop_table(...);
$id = db_insert('t', [...]);  db_update('t', [...], 'id=?', [$id]);  db_delete('t', 'id=?', [$id]);
db_upsert('t', ['k' => 'a', 'v' => 1], ['k']);   db_insert_ignore('t', [...]);   db_increment('t', 'views', 1, 'id=?', [$id]);
rows_by_ids('t', $ids, 'id,name');               // keyed by id, chunked
tx(function () { ... });
```

## Core tables (`fb_` prefix)

| Table | Purpose |
| --- | --- |
| `fb_settings` | key/value site settings |
| `fb_users` | accounts; denormalised counters; `prefs` JSON |
| `fb_groups` | user groups with `permissions` JSON and admin/mod flags |
| `fb_categories` | categories (one level of nesting via `parent_id`), per-group view/post restrictions |
| `fb_tags`, `fb_topic_tags` | tags |
| `fb_topics` | topics with counters, last post info, pinned/locked/deleted flags, `hot_score`, `meta` JSON |
| `fb_posts` | first post (`floor=0`) and replies; markdown `body` and rendered `body_html` |
| `fb_likes`, `fb_bookmarks`, `fb_topic_reads` | per-user relations |
| `fb_notifications` | reply / mention / like / system notifications |
| `fb_attachments` | uploaded files (linked to a post after saving) |
| `fb_plugins` | plugin registry: enabled/installed flags, settings JSON, manifest snapshot |
| `fb_cron` | last run / status per job |
| `fb_search` (+ `fb_search_fts` on SQLite) | search index, one row per post |

Full definitions: `schema_tables()` and `schema_indexes()` in `core/schema.php`. `schema_install()` is idempotent and is what the installer, `php flatbb schema:upgrade` and Admin → Tools run.

## Migrations

Core: bump `SCHEMA_VERSION`, add columns to `schema_tables()` (they are added with `db_ensure_columns()` on upgrade), add indexes to `schema_indexes()`. Data migrations go into `schema_install()` guarded by the stored `schema_version` setting.

Plugins: change `install` (it runs again when the manifest version changes) and keep it idempotent.

## Moving from SQLite to MySQL

1. Install a fresh flatbb of the same version on MySQL. Copy `plugins/` and `uploads/` from the old site and enable the same plugins (so their tables exist).
2. Copy the old `data/flatbb.sqlite` somewhere the new server can read, then either run

   ```bash
   php flatbb migrate:import /path/to/flatbb.sqlite
   ```

   or use Admin → Tools → Import from SQLite.
3. Every `fb_*` and `plugin_*` table is emptied and refilled with the SQLite rows; ids are preserved, MySQL auto-increment counters are advanced, the search index and counters are rebuilt. Site settings that belong to the new install (`rewrite`, `installed_at`) are kept.

The importer lives in `core/migrate.php` and also works SQLite → SQLite (restore from a copy).

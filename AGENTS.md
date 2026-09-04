# flatbb — guide for AI assistants

flatbb is a flat, lightweight forum in plain PHP 8.1+ (no framework, no Composer, no build step) with SQLite or MySQL 5.7+.
Read this file first. It tells you where things are and the rules that keep the codebase small and safe.

## What you are probably here to do

| Task | Read | Touch |
| --- | --- | --- |
| Build or change a **plugin** | `docs/PLUGIN.md` (rules), `docs/HOOKS.md` (positions), `docs/API.md` (functions) | only `plugins/<id>/` |
| Change the **look** | `docs/THEME.md` | `assets/app.css`, `app/views/*.php`, or better: a theme plugin |
| Change **core behaviour** | `docs/ARCHITECTURE.md` | `core/*.php`, `app/*.php` |
| Change the **database** | `docs/DATABASE.md` | `core/schema.php` (core) or your plugin's `install` |
| Publish a plugin to www.flatbb.com | `docs/PUBLISH.md` | `php flatbb plugin:publish <id>` |

## Map

```
index.php          web entry: require core/boot.php; app_boot(); dispatch();
flatbb             CLI entry (php flatbb <command>)
core/              the kernel — small files, one concern each, all plain functions
  boot.php         constants, config(), error handling, load order
  helpers.php      h(), t(), now(), url helpers, settings, request_cache(), json/redirect/fail
  db.php           q()/one()/val()/col()/tx(), db_insert/update/upsert, db_create_table(), db_types()
  schema.php       core tables + indexes, schema_install(), schema_seed()
  auth.php         me()/uid(), cookies, csrf, groups, can(), users_by_ids()
  hook.php         hook()/fire(), region()/region_list()/slot(), regions_known(), admin layout blocks
  plugin.php       plugin registry, manifests, settings schema, enable/disable, asset bundling
  render.php       view(), page(), icon(), avatar(), pagination(), form helpers, editor()
  markdown.php     md() safe markdown renderer, md_excerpt(), md_mentions()
  upload.php       attachments, avatars, image resizing
  search.php       FTS5 / MySQL FULLTEXT / LIKE search index
  cron.php         scheduled jobs (cron_run), core jobs
  router.php       routes table, dispatch(), url(), topic_url(), current_path()
  devtools.php     plugin_check(), plugin_package(), plugin_publish(), docs generators (CLI only)
app/               request handlers: one file per area, functions named <area>_<action>()
  home.php category.php tag.php topic.php user.php account.php notification.php search.php api.php setup.php
  admin.php admin_content.php admin_system.php
app/views/         PHP templates rendered by view('name', $vars). layout.php is the page shell.
assets/            app.css (CSS variables, three-column grid), app.js (vanilla, data-* driven), favicon.svg
plugins/<id>/      plugins. plugin.php returns the manifest. hello/ is the reference example.
lang/<code>.php    translations (English keys are the source strings; t('text'); php flatbb lang:sync <code> refreshes a pack)
data/              runtime: config.php, flatbb.sqlite, cache/ — never web-accessible, never commit
uploads/           user files, web-accessible, PHP execution blocked
docs/              documentation for humans and AIs (HOOKS.md and API.md are generated)
```

## Hard rules (core and plugins)

1. **Escape everything**: `h()` on any value that reaches HTML. Markdown goes through `md()`, which already sanitises.
2. **Never build SQL from user input.** Use `q()`/`one()`/`val()` with `?` placeholders. Identifiers use backticks.
3. **Portable SQL only** (SQLite and MySQL 5.7): no JSON columns, no CTEs, no window functions, no engine-specific functions.
   Create tables only with `db_create_table()`/`db_ensure_columns()`/`db_create_index()` and the type names from `db_types()`.
4. **No N+1**: never query inside a loop. Collect ids, load once with `rows_by_ids()`/`users_by_ids()`/`IN (...)`, map in memory.
   Hooks marked "loop, no DB" (`topic_list.item.*`, `post.*`) must not touch the database at all.
5. **State changes are POST + CSRF, enforced centrally**: `dispatch()` verifies the CSRF token on every POST before any handler runs (a route that authenticates with an API token lists itself in the manifest key `csrf_exempt`). Handlers still call `require_post()` for the method check; forms include `csrf_field()`; permissions with `need_login()`, `need_admin()`, `can()`.
   **Never read `$_POST`/`$_GET` directly**: use `post_str()`, `post_int()`, `post_list()`, `post_secret()`, `get_str()`, `get_int()`. In templates `<?= ... ?>` may only start with `h()`, `t()`, a known HTML helper, or `raw()` for HTML that was already escaped upstream. `php flatbb security:check` fails the build on violations (also run by `plugin:check`).
6. **Files stay small**: no file over 30 KB. Split by concern rather than growing a file.
7. **Plain functions, no classes, no globals**: per-request memoisation goes through `request_cache()`.
8. **Plugins never edit core files.** If a plugin needs a hook that does not exist, add the hook to core (one line) and document it.
9. **Every plugin symbol is prefixed** with the plugin id: functions `myplugin_*`, tables `plugin_myplugin_*`, CSS classes `.myplugin-*`, JS globals `myplugin_*`.
10. **Bump `version`** in the manifest on every plugin change.

## Conventions

- Handlers are `never`-returning functions that end with `page()`, `redirect()`, or `json_ok()`.
- Views are plain PHP with short echo tags; logic stays in `app/*.php`.
- Text shown to users goes through `t('English text')` so it can be translated.
- Timestamps are unix seconds (`now()`), booleans are 0/1 integers, JSON is stored in TEXT columns.
- URLs are built with `url('/path')`, `topic_url($topic)`, `category_url($c)`, `user_url($u)`, `admin_url('page')` — never hard-coded.
- Icons: `icon('name')` (see `icon_paths()` in core/render.php). Colours and sizes come from CSS variables in `assets/app.css`.

## Commands

```bash
php flatbb plugin:check <id>      # lint + naming rules (what the marketplace checks)
php flatbb plugin:sync            # register plugins found in plugins/
php flatbb plugin:enable <id>
php flatbb plugin:publish <id>    # package and upload to www.flatbb.com (FLATBB_TOKEN)
php flatbb hooks:list > docs/HOOKS.md
php flatbb api:list   > docs/API.md
php flatbb lang:sync <code>       # create/refresh lang/<code>.php with every t() string
php flatbb security:check         # static rules: no $_POST/$_GET reads, no eval/exec, no unescaped template output (exit 1 on findings)
php flatbb cron                   # run due scheduled jobs
php -l <file>                     # always lint changed PHP files
```

## Definition of done

- `php -l` passes on every changed file; `php flatbb plugin:check <id>` passes for plugins.
- No new queries in loops; list pages stay under ~10 queries.
- Works on SQLite and on MySQL 5.7 (same schema helpers, no dialect SQL).
- User-facing text is escaped, translatable, and English.
- For plugins: version bumped, description updated, install/uninstall idempotent.

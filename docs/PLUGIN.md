# FlatBB plugin development guide (for humans and AI assistants)

This is the complete specification for creating, changing and reviewing FlatBB plugins.
Read it fully before writing code, then look at `plugins/hello/plugin.php` and the plugin closest to what you need.
Implement against the real functions in `core/` (see `docs/API.md`); do not invent APIs.

## 1. Work order

1. Decide the plugin **id** (lowercase letters, digits, underscores, 2–40 chars, e.g. `word_filter`), its scope, settings, data, pages, permissions, external requests and scheduled jobs.
2. Prefer core functions, hooks, regions and the settings schema over custom infrastructure. If the plugin mechanism cannot do it, add a hook to core instead of editing core behaviour from the plugin.
3. Put all logic in `plugins/<id>/plugin.php`. Extra files are allowed (`assets/`, `lang/`, `README.md`, helper `.php` files you `require` from plugin.php) but the manifest must be returned by `plugin.php`.
4. New plugin: verify defaults, enable, disable, uninstall. Existing plugin: stay compatible with old settings and data, bump the patch version at least, update the description if user-visible behaviour changed.
5. Finish with `php -l plugins/<id>/plugin.php` and `php flatbb plugin:check <id>`, then go through the checklist at the end.

## 2. Minimal plugin

```php
<?php
if (!defined('FLATBB')) exit;

function hello_footer(string $html, array $ctx): string
{
    return $html . '<span class="hello-badge">' . h((string)plugin_setting('hello', 'text', 'Hello')) . '</span>';
}

function hello_css(): string
{
    return '.hello-badge{color:var(--brand);font-size:var(--font-size-xs)}';
}

return [
    'id'          => 'hello',
    'name'        => 'Hello Badge',
    'version'     => '1.0.0',
    'description' => 'Shows a small greeting badge in the footer.',
    'author'      => 'your-name',
    'requires'    => ['flatbb' => '0.1.0'],
    'hooks'       => ['region.footer.right' => 'hello_footer'],
    'settings'    => ['text' => ['type' => 'text', 'label' => 'Badge text', 'default' => 'Hello', 'max' => 40]],
    'assets'      => ['css' => ['hello_css']],
];
```

Register it once with `php flatbb plugin:sync` or Admin → Plugins → "Scan plugins folder", then enable it.

## 3. Manifest reference

| Key | Required | Meaning |
| --- | --- | --- |
| `id` | yes | Same as the directory name. |
| `type` | no | `'theme'` makes the plugin a theme ([THEME.md](THEME.md)): one at a time, managed under Admin → Appearance → Themes, with `tokens`, `screenshot` and template overrides in `views/`. Leave it out for a plugin. |
| `name`, `version`, `description`, `author` | yes | `version` is semantic `x.y.z`. `description` is for end users: what it does, no tech words. |
| `url` | no | Homepage or repository. |
| `requires` | no | `['flatbb' => '0.1.0']` minimum core version. |
| `hooks` | no | `['hook.name' => 'callback' \| ['cb1', 'cb2']]`. See `docs/HOOKS.md`. |
| `routes` | no | `['/path' => 'callback', '/path/{id}' => 'callback']`. Patterns as in `core/router.php`. |
| `csrf_exempt` | no | Paths from `routes` that authenticate with an API token instead of a browser session (e.g. `['/api/myid/webhook']`). Every other POST is rejected by the dispatcher without a valid CSRF token. |

The manifest is **data**: strings, numbers, booleans, nested arrays and constants only, no calls, variables or expressions. It is read with PHP's tokenizer without executing the file (the admin lists disabled plugins that way, the marketplace validates uploads that way) and cached when the plugin is scanned; `plugin:check` refuses a manifest that cannot be read statically. `plugin:package` writes a `plugin.json` copy into the zip for tools that want the metadata without PHP; you never edit it.
| `importer` | no | Makes the plugin an importer listed under Admin → Import: `['from' => 'flarum', 'label' => 'Flarum', 'page' => 'run', 'step' => 'myid_step', 'cli' => 'myid_cli']`. See §13b. |
| `admin_pages` | no | `['key' => ['label' => 'Menu label', 'callback' => 'fn']]` → `/admin/ext/<id>/<key>`. Call `need_admin()` inside. |
| `settings` | no | Declarative settings; the admin form is generated (see §5). |
| `assets` | no | `['css' => [...], 'js' => [...]]`, each item a function name returning source, or a file path relative to the plugin dir. Bundled into one file for all plugins. |
| `cron` | no | `['job' => ['callback' => 'fn', 'interval' => 3600 \| 'fn_returning_seconds']]`. |
| `install` | no | Function run when enabled the first time or after a version change. Must be idempotent. |
| `uninstall` | no | Function that drops the plugin's own tables/files. Never delete user content you do not own. |
| `price` | no | Reserved for the marketplace (0 = free). |

## 4. Naming and isolation (checked by `plugin:check`)

- PHP functions `myid_*`, constants `MYID_*`, classes `MyId*`.
- Database tables `plugin_myid_*`; never alter `fb_*` core tables. Store extra per-topic/post/user data in your own table keyed by id, or in the `meta` JSON column via hooks — never add columns to core tables.
- When you write a key into `fb_topics.meta`, read the column fresh (`val('SELECT meta FROM fb_topics WHERE id=?', [$id])`), merge your key, write, then `request_cache('topic_' . $id, null, true)`. Several plugins save their key in the same `topic.after_save` request, and `topic_by_id()` is cached per request — merging into the cached copy silently drops what the plugin before you just wrote.
- CSS classes/ids/variables `myid-*` / `--myid-*`; `data-myid-*` attributes; JS functions and globals `myid_*`; browser storage keys `myid_*`.
- Custom hooks fired by your plugin: `myid.event_name`. Core hooks keep their original names.
- Plugins must not call each other's functions. Shared needs go into core.
- `plugin.php` starts with `if (!defined('FLATBB')) exit;`.
- Read/write files only under `plugin_path($id, ...)`, `DATA_DIR . '/myid_*'` or `UPLOAD_DIR`. Never hard-code paths.

## 5. Settings

Declare a schema; the admin page renders the form and validates input:

```php
'settings' => [
    'enabled'   => ['type' => 'checkbox', 'label' => 'Enable', 'default' => 1],
    'limit'     => ['type' => 'number', 'label' => 'Items', 'default' => 5, 'min' => 1, 'max' => 50],
    'mode'      => ['type' => 'select', 'label' => 'Mode', 'options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'],
    'text'      => ['type' => 'text', 'label' => 'Title', 'default' => '', 'max' => 80, 'help' => 'Shown above the list'],
    'html'      => ['type' => 'html', 'label' => 'Custom HTML', 'rows' => 6],
    'color'     => ['type' => 'color', 'label' => 'Accent', 'default' => '#e7672e'],
    'icon'      => ['type' => 'icon', 'label' => 'Icon', 'default' => 'star'],
],
```

An `icon` setting shows the site's icon field (the same one Admin → Categories and Menus use): the built-in icons, an emoji, or one of the icons the admin uploaded. Draw the stored value with `icon_any($value)`; never store an icon of your own in another format. In a form of your own, use `icon_picker($name, $value)` and read it back with `icon_from_post($name)`.

Read with `plugin_setting('myid', 'limit', 5)` or `plugin_settings('myid')`. Defaults come from the schema, so old installs missing a key still work.
Write programmatically with `plugin_save_settings('myid', $array)`.

Where it shows up: Admin → Plugins → *Settings* opens a drawer beside the list with this form; the same form is reachable as a full page via `?full=1`. Keep everything declarative when you can — that is what keeps every plugin's settings looking and behaving the same. Only for pages that need tables, charts or multi-step actions register `admin_pages` (`['key' => ['label' => 'Menu label', 'callback' => 'myid_admin_page']]`); those appear as links at the top of the settings drawer and under "Plugins" in the admin menu, and render with `admin_page($title, $html, 'ext.myid.key')`. Never put destructive actions inside a settings form; use `admin_row_menu()` + a confirmation.

## 6. Hooks and regions

Signature for every hook: `function myid_x($value, array $ctx)`. Return the new value, or `null` to leave it unchanged.
Events (fired with `fire()`) ignore the return value.

- **HTML regions** (`region.header.left`, `region.sidebar.right.top`, `region.footer.right`, …): `$value` is HTML, append to it.
- **List regions** (`region.header.nav`, `region.sidebar.left.nav`, `region.header.user_menu`, `region.post.actions`, `region.sidebar.right.cards`, …): `$value` is an array keyed by item id. Add `['label' => …, 'url' => …, 'icon' => …]` or `['html' => …]`. Insert your item with your plugin id as key (`myid` or `myid_<n>`). Optional keys the core honours for every list region: `weight` (int, lower first; equal weights keep insertion order; the marketplace links use 50 and 60), `visible` (`everyone` default, `members`, `admins`: filtered by the core, do not re-check in your callback), `new_tab` (bool, header links open in a new tab). Admins can hide any single item per region under Admin → Widgets, so never hard-code your item as mandatory. Reference example: `plugins/nav_menu/plugin.php` (one table, one admin page, one list-region hook).
- **Inline regions inside loops** (`region.topic_list.item.*`, `region.post.*`): called once per row/post. **No database access.** Use data already present in `$ctx['topic']`/`$ctx['post']`, or attach data beforehand with the batch hooks `topic_list.rows` / `topic.posts` (called once per page with all rows).
- **Admin control**: every region appears in Admin → Appearance → Widgets (the menus under Menus), where admins can switch your plugin off per region or add HTML blocks. Do not fight that with CSS.
- **Front end**: every region element carries `data-slot="<region>"`; select with `[data-slot~="post.actions"]`. In-loop slots repeat; scope by `[data-post-id]` / `[data-topic-id]`.

Frequently used hooks (full list in `docs/HOOKS.md`):

| Hook | Use |
| --- | --- |
| `app.boot` | Preload data once per request. |
| `topic_list.rows` / `topic.posts` | Batch-attach data to topic rows / posts (the right place to query). |
| `topic.before_save` / `post.before_save` | Validate or modify content (return array; call `fail('message')` to reject). |
| `topic.after_save` / `post.after_save` | React to new content (index, notify, award). |
| `markdown.after` | Post-process rendered HTML (embeds, emoji). Called per post: no DB. |
| `page.before_output` | Whole document: page-level placeholder replacement. |
| `region.sidebar.right.cards` | Add a card: `$cards['myid'] = card('Title', $html)`. Key it by your plugin id: Admin → Widgets lists the card under that id (drag to order, switch to hide) even on pages where your callback adds nothing. |
| `region.member.labels` / `region.member.stats` / `region.member.actions` | Show something about a member. One hook reaches every place the core draws a member: the sidebar member card (`place` `card`), the topic author card (`author`), the account menu (`menu`) and the profile (`profile`); check `$ctx['place']` to pick. Stats are `label`, `value`, `url`, `sub`, and `progress` (0..1) for a bar; actions are `label`, `url`, `icon`, `primary`, `count`. Prefer these to the place-specific `user.profile.*` and `header.user_menu.*` regions. `region.member.sections` adds a whole card beside the lists on a profile (`title`, `html`, `url`, `link`). |
| `region.composer.toolbar` | Add editor buttons. |
| `api.<action>` | JSON endpoints at `/api/<action>`. |
| `admin.settings_fields` | Add site settings groups. |
| `cron.jobs` | Register jobs dynamically. |

### Where an entry goes

Decide what the entry is, then use the one place for it. Every place is a list the member or the admin already knows; do not add a card or a menu of your own to show links.

| The entry is… | Put it in | Hook |
| --- | --- | --- |
| Something to do (post, check in) | Buttons of the member card, the account menu, the profile | `region.member.actions` (`post` => true for a POST button) |
| Something of mine (messages, orders, drafts, invites) | The account menu, group `you`; the member card shows these as shortcuts on its own (`card` => false keeps one out) | `region.header.user_menu` |
| A list of my content (topics, replies, favourites) | A tab of the profile | `region.user.profile.tabs` |
| A setting of mine (privacy, notifications) | A tab of Settings, never the menus | `region.user.settings.tabs` |
| A page inside a feature that already has a hub (a leaderboard in Growth) | A tab of that hub | the hub's own list, such as `region.growth.tabs` + `growth.tab_page` |
| A place on the site to go to (a shop, a tag square) | One link in the left menu, in a group: `community`, `tools`, or your own with `group_label` | `region.sidebar.left.nav` |
| Meta and utilities (RSS, downloads, the API) | The footer links | `region.footer.links` |
| A site-wide switch (colour scheme, language) | The header, right side | `region.header.right` |

Every item of these menus can be renamed, relinked, hidden or moved by the admin under Admin → Appearance → Menus, so give yours a stable key (your plugin id) and a plain label; never fight an admin's edit in your callback. A list region of your own becomes editable there when you add it to the filter `menus.known`.

One plugin, one link in the left menu: a feature with several pages opens one page and shows the others as its tabs. `plugin:check` warns when a plugin adds more. Past a number of links (Admin setting `nav_visible`, 8) the left menu folds the rest under More, and an admin orders or hides any item under Admin → Widgets.

### Permissions, uploads, hidden content and pages

Four hooks cover what bigger plugins need (trust levels, object storage, reply-to-see, a portal). Use them instead of patching anything.

**Change a permission for one member: `user.can`.** The group decides first; your filter gets its answer and returns the final one. Guests and admins never reach it.

```php
function myid_can(bool $ok, array $ctx): bool
{
    // members below level 2 may not post links or upload files yet
    if (in_array($ctx['permission'], ['upload'], true) && user_level((array)$ctx['user']) < 2) return false;
    return $ok;
}
// 'hooks' => ['user.can' => 'myid_can']
```

**Follow, refuse or move uploads: `upload.before_save`, `upload.after_save`, `upload.url`.** Return a message from `upload.before_save` to refuse a file (or change the file at `$ctx['tmp']` in place: compress, watermark). `upload.after_save` fires for attachments, avatars and site images (`$ctx['kind']`), with the saved path and file: copy it to object storage there. `upload.url` rewrites the address a file is served from; it runs for every avatar on a page, so it must not query.

```php
function myid_url(string $url, array $ctx): string
{
    return plugin_setting('myid', 'cdn', '') !== '' ? rtrim((string)plugin_setting('myid', 'cdn'), '/') . '/' . ltrim($ctx['path'], '/') : $url;
}
```

**Content not every reader may see: `topic.posts` + `markdown.excerpt`.** Hide it when the page is drawn (`topic.posts` runs once per page with every post and the reader known), and strip it from the source in `markdown.excerpt`, which every excerpt goes through: the search index, page descriptions, notifications and feeds. Doing only the first leaks the content everywhere else.

```php
function myid_excerpt(string $md, array $ctx): string
{
    return preg_replace('~\[hide\].*?\[/hide\]~s', '', $md) ?? $md;
}
```

**Serve a core page: `router.routes`.** Map a core path to your handler (a portal at `/`). Admin, sign-in, settings, setup and API addresses cannot be taken over, and Admin → Plugins tells the admin which pages a plugin serves.

```php
function myid_routes(array $routes, array $ctx): array
{
    $routes['/'] = 'myid_portal'; // the latest topics stay at /latest
    return $routes;
}
```

### Batch pattern (the only acceptable way to add per-row data)

```php
function myid_rows(array $rows, array $ctx): array
{
    $ids = array_column($rows, 'id');
    $extra = $ids ? rows_by_ids('plugin_myid_stats', $ids, 'topic_id,score', 'topic_id') : [];
    foreach ($rows as &$r) $r['myid_score'] = (int)($extra[(int)$r['id']]['score'] ?? 0);
    return $rows;
}
function myid_title_suffix(string $html, array $ctx): string   // loop hook: memory only
{
    $s = (int)($ctx['topic']['myid_score'] ?? 0);
    return $s > 0 ? $html . '<span class="myid-score">' . $s . '</span>' : $html;
}
// manifest
'hooks' => ['topic_list.rows' => 'myid_rows', 'region.topic_list.item.title_suffix' => 'myid_title_suffix'],
```

If a loop hook cannot receive its data through a batch hook, output a unique placeholder `<!--myid-<token>-<id>-->` and replace all of them at once in `page.before_output` with one `IN (...)` query. Never leave placeholders in the final HTML.

## 7. Routes and pages

```php
function myid_page(string $id = ''): never
{
    $me = need_login();                  // or need_admin(), or nothing for public pages
    $row = one('SELECT * FROM plugin_myid_items WHERE id=?', [(int)$id]);
    if ($row === null) not_found();
    page('Title', '<div class="card"><div class="card-body">' . h($row['title']) . '</div></div>', ['class' => 'page-myid']);
}
function myid_save(): never
{
    need_login();
    require_post();                      // POST + CSRF
    $title = post_str('title', 120);
    if ($title === '') fail(t('Title is required.'));
    db_insert('plugin_myid_items', ['user_id' => uid(), 'title' => $title, 'created_at' => now()]);
    flash(t('Saved.'));
    redirect(url('/myid'));
}
'routes' => ['/myid' => 'myid_page', '/myid/{id}' => 'myid_page', '/myid/save' => 'myid_save'],
```

- Links: `url('/myid/' . $id)`. Forms: include `csrf_field()`; add `data-ajax="1"` to submit via fetch (handler must respond with `redirect()`/`json_ok()`).
- AJAX-only endpoints: `json_ok([...])` / `json_error('msg', 400)`. `is_ajax()` tells you how the request came in.
- Page options: `['left' => false]` hides the left column, `['right' => $html]` replaces the right column, `['breadcrumbs' => [[label, url]]]`.
- Build UI with the helpers in `core/render.php`: `card()`, `tabs()`, `pagination()`, `form_row()`, `input()`, `select()`, `checkbox()`, `action_form()`, `editor()`, `avatar()`, `user_link()`, `icon()`.

## 8. Database

```php
function myid_install(array $manifest): void
{
    db_create_table('plugin_myid_items', [
        'id' => 'id', 'user_id' => 'uint', 'title' => 'string', 'body' => 'text', 'score' => 'int', 'created_at' => 'uint',
    ]);
    db_create_index('plugin_myid_items', 'ix_myid_items_user', ['user_id', 'created_at']);
}
function myid_uninstall(array $manifest): void
{
    db_drop_table('plugin_myid_items');
}
```

- Types: `id`, `uint`, `int`, `bigint`, `bool`, `float`, `string` (255), `key` (191, indexable/unique), `text`, `mediumtext`. Raw SQL types are allowed only if valid on both SQLite and MySQL 5.7.
- Writes: `db_insert()` (returns id), `db_update()`, `db_delete()`, `db_upsert($table, $data, $keys)` (keys need a PK or unique index), `db_insert_ignore()`, `db_increment()`.
- Multi-step writes in `tx(function () { ... })`.
- Portable helpers: `db_greatest()`, `db_random()`, `db_like()` (with `ESCAPE '\\'`), `sql_marks()`.
- Schema changes only in `install` (which runs again when the version changes — make it idempotent with `db_ensure_columns()`).
- Cache across requests with `save_settings(['myid_cache' => json_encode_value($v)])` + `setting('myid_cache')`; within a request with `request_cache('myid_x', fn() => ...)`.

## 9. Assets and front end

- Declare CSS/JS in the manifest; do not print `<style>`/`<script>` tags from hooks except in `region.head`/`region.body.end` when the content must be dynamic.
- Asset functions take no arguments and return source without tags. They run at bundle time (enable/disable), not per request, so they cannot depend on the current user or page. Pass dynamic values through `data-myid-*` attributes.
- Wrap JS in an IIFE. `window.FB` provides `base`, `csrf`, `uid`, `request(url, opts)` (fetch with CSRF header), `toast(msg, type)`, `initEditor(el)`. Listen to `fb:ready`, `fb:ajax`, `fb:editor` events.
- Use CSS variables only: `--bg --panel --panel-2 --line --line-soft --text --text-muted --text-subtle --brand --brand-hover --brand-soft --success --danger --warning --info` (+ `*-soft`), `--radius --radius-sm --shadow`, font sizes `--font-size-xs|sm|md|lg|xl|2xl`. No hard-coded colours or pixel font sizes; no `!important`; scope selectors under your own class.
- Reuse core classes for consistency: `.card`, `.card-head`, `.card-body`, `.btn`, `.btn-primary`, `.btn-sm`, `.tag-badge`, `.flag`, `.muted`, `.form-row`, `.table-wrap table.admin`.
- Static files (images) in `plugins/<id>/assets/` are served directly: `plugin_url($id, 'assets/logo.png')`.
- Right-to-left languages: write direction-neutral CSS. Use `margin-inline-start`/`-end`, `padding-inline-*`, `border-inline-*`, `inset-inline-*` and `text-align: start` instead of left/right; the core switches `<html dir="rtl">` when the language pack says so, and `[dir="rtl"]` rules cover the rest. Give user text (`.post-content`, titles, textareas) `dir="auto"`.

## 10. Security

- CSRF is verified by the dispatcher for every POST; you never need to remember it. A route that is called by machines with a token goes into `csrf_exempt` and must verify that token itself.
- Content Security Policy: inline `<script>` blocks need the request nonce, so write them with `script_tag('...js...')` (or `script_tag('', $src)` for an external file) instead of a raw tag, and never use inline event handlers (`onclick="..."`); put JS in the plugin's `assets` bundle. A plugin that loads scripts, styles or fonts from a CDN adds the host through the `security.csp` filter (`$p['script-src'][] = 'https://cdn.example.com'`).
- High-risk admin pages call `need_sudo()` after `need_admin()`: the admin re-enters the password once per confirmation window (Settings → Security, default ten minutes, 0 = off), so a stolen or script-ridden session cannot change what matters. Log what an admin changed with `admin_log('myid.action', $target, $detail)`; it shows under Tools and fires `admin.action`.
- The visitor's address is `client_ip()`; it already accounts for trusted proxies (Settings → Security), so never read the forwarded headers yourself.
- Never touch `$_POST`, `$_GET`, `$_REQUEST` or `$_COOKIE`: use `post_str()`, `post_int()`, `post_list()` (checkbox groups), `post_secret()` (passwords, untrimmed), `get_str()`, `get_int()`. `plugin:check` rejects direct superglobal access.
- `h()` everything that reaches HTML. In templates, `<?= ... ?>` must start with `h()`, `t()`, a core HTML helper (`icon()`, `avatar()`, `form_row()`, …) or `raw()` for HTML you already built safely; a bare variable fails the check. Markdown goes through `md()`.
- Permissions: `uid()`, `me()`, `need_login()`, `need_mod()`, `need_admin()`, `can('post'|'reply'|'upload'|'edit_own'|'delete_own')`, `is_admin()`, `is_mod()`, `can_edit_post()`, `can_manage_topic()`, `category_can_view()`.
- Files: whitelist extensions, never trust client mime, reuse `upload_store()`; never write under `plugins/` at runtime.
- External requests: curl with connect/total timeouts, size limits, no internal IPs, no credentials in URLs; never block page rendering on a remote call — do it in cron.
- Never log or display tokens, passwords, or full SQL.

## 11. Scheduled jobs

```php
function myid_cleanup(array $job): string
{
    $n = db_delete('plugin_myid_items', 'created_at<?', [now() - 86400 * 30]);
    return $n . ' removed';         // shown in Admin → Scheduled jobs
}
'cron' => ['cleanup' => ['callback' => 'myid_cleanup', 'interval' => 86400]],
```

Jobs run from `/cron?key=…` or `php flatbb cron`, under a lock, only when enabled. Make them idempotent and quick; use a queue table for long work.

## 12. Points (economy)

The core owns the balance (`fb_users.points`) and the private history (`fb_points_log`); plugins decide when points move.

```php
function checkin_reasons(array $r, array $ctx): array { return $r + ['checkin' => t('Daily check-in')]; }
function checkin_claim(): never
{
    $me = need_login(); require_post();
    if (!points_add((int)$me['id'], 5, 'checkin')) fail(t('Nothing to add.'));
    flash(t('+5 points!')); redirect(url('/'));
}
'hooks' => ['points.reasons' => 'checkin_reasons'], 'routes' => ['/checkin' => 'checkin_claim'],
```

- `points_add($user_id, $delta, $reason, $ref_id = 0, $note = '')` — negative delta spends; `points_of()`, `points_log()`, `points_top()` read.
- Always register your reason codes through `points.reasons` so the user's history shows a readable label; never write `fb_points_log` or `fb_users.points` directly.
- `points.before_change` lets a plugin veto or cap changes (return `false`); `points.after_change` is the place for badges, notifications or a shop.
- The history is private (Settings → Points, and admins). Show public totals through a `member.stats` item (the profile's own Points tile is already rendered when the user allows it).

## 13. Editor extensions

The composer is one component (`app/views/editor.php` + the editor block in `assets/app.js`). Stable contract for plugins:

- **Modes**: the tabs above the toolbar are the list region `composer.modes` (`write`, `preview`); add a mode with `label`, `icon`, `cmd` (your command toggles it) and `class` (on `.editor` while it is on) — see the Visual Editor plugin.
- **Buttons**: add to the `composer.toolbar` list region: `$buttons['myid_stamp'] = ['icon' => 'clock', 'title' => 'Timestamp', 'cmd' => 'myid_stamp', 'arg' => 'Y-m-d'];` (`arg` is passed to your handler; `['html' => …]` inserts raw markup).
- **Commands (JS)**: `FB.editor.register('myid_stamp', function (api, arg, el) { api.insert(new Date().toISOString().slice(0, 10)); });` The `api` object: `value(v?)`, `selection()` → `[start, end, text]`, `replace(start, end, text, cursor?)`, `insert(text)`, `wrap(before, after?, placeholder?)`, `prefix(lineStart)`, `block(text)`, `upload(files)`, `preview(bool?)`, `fullscreen(bool?)`, `status(text)`, `run(cmd, arg)`, `clearDraft()`. Registering an existing command name (e.g. `link`) overrides the built-in one.
- **Events**: `document` receives a cancelable `fb:editor` event before every command (`detail: {editor, api, cmd, arg}`; `preventDefault()` blocks it). `FB.editor.get(el)` returns the api of any `[data-editor]` element; `FB.editor.init(el)` initialises one you inserted dynamically.
- **Options** (PHP): hook `editor.options` filters `['preview','emoji','fullscreen','draft_days','upload','accept','scope']` per composer (ctx carries `name` and the topic/post being edited). `editor.emoji` filters the emoji list, `editor.help` the formatting-help rows.
- **Markup**: the stable selectors are `[data-editor]`, `textarea[name=body]`, `.editor-toolbar`, `[data-preview]`. Everything else may change.

## 13a. AI

The site has one AI connection, set up under Admin → Settings → AI (provider, address, model, key). A plugin that needs a model calls it instead of shipping its own client or asking for its own key:

```php
if (!ai_ready()) return;                       // not set up: stay quiet, or tell the admin in your settings page
$r = ai_chat('You file forum posts. Answer with JSON: {"tags": [...]}', $title . "\n\n" . $body,
    ['purpose' => 'myid', 'max_tokens' => 200, 'json' => true]);
if (!$r['ok']) return;                          // $r['error'] says why
$data = ai_json($r['text']);                    // the first JSON object in the answer, or null
```

- Two protocols cover nearly every service: `openai` (Chat Completions: OpenAI, DeepSeek, Qwen, Moonshot, OpenRouter, a local Ollama…) and `anthropic` (Claude). Your plugin never sees the key or the difference.
- The admin may add up to two backup connections: when the main one fails, `ai_chat()` asks the next by itself, so a plugin handles one failure path only (every connection failed). `$r['connection']` says which one answered.
- `ai_chat()` is one HTTPS request that can take seconds: call it on a user action or in a cron job, never in a loop, a list, a region or a transaction. Rate-limit it per member and cache answers for the same text.
- Treat the answer as untrusted input: check every value against what you allow (a category the writer may post in, a tag that passes `tags_parse()`), and `h()` it like anything else.
- `purpose` is your plugin id. Filter `ai.request` sees it and may change or refuse a request (quotas, redaction); event `ai.response` reports model and token usage.
- **Picking a category**: filter `topic.category_missing` (ctx `title`, `body`, `user`) runs when a new topic arrives without one; return a category id. Filter `topic.category_auto` returns true while your plugin can answer, which makes the category optional in the composer. The AI Classify plugin is the example.

## 13b. Importers

An importer brings another forum into a **new, empty** FlatBB forum. The core owns the job, Admin → Import and `php flatbb import <from>`; your plugin reads the source and hands rows to the core's writers, the only code that fills `fb_*` tables with imported content. Name the plugin after its source: `phpbb_importer`, "phpBB Importer". The Flarum Importer is the example.

```php
'importer' => ['from' => 'phpbb', 'label' => 'phpBB', 'page' => 'run', 'step' => 'phpbb_importer_step', 'cli' => 'phpbb_importer_cli'],
'admin_pages' => ['run' => ['label' => '', 'callback' => 'phpbb_importer_page']],   // your connect / check page, linked from Admin → Import
```

- **Start**: your page (or `cli($opts, $out)`) checks the source, then calls `import_start('myid', $phases, $data, $secret)`. `$phases` is the order of work with counts (`[['key' => 'users', 'label' => 'Members', 'total' => 3412], …]`); `$data` is your own state (connection without password, what the source has); `$secret` (a database password) is removed when the job ends. `import_ready()` says whether the forum may receive an import; `import_start()` removes the starter content first.
- **Step**: `step(array $job): array` runs one batch of `$job['phases'][$job['phase']]` (a few hundred rows), adds to its `done`, keeps its place in `$job['cursor']`, and returns `import_phase_next($job)` when the phase has no rows left. The core runs steps for a few seconds per request from the page (closing it pauses the import) or to the end from the command line; each batch is one transaction with the saved job, so a failed batch is retried from where it stopped. The core adds the Counters and Search index phases at the end.
- **Writers**: `import_user()` (keeps a bcrypt/argon hash, so members sign in with their old password; a member with the administrator's email becomes that account), `import_group()`, `import_category()`, `import_tag()`, `import_topic()` and `import_post()` (both may keep the source id; `REVIEW_PENDING` puts a post in the review queue), `import_like()`, `import_read()`, `import_copy_file()`. Posts are Markdown: convert the source format in your plugin. `import_note($text)` adds a line to the report.
- Keep your own maps (old id → new id) in your plugin's tables; clear them on the event `import.reset`, which fires when an import starts. `router.not_found` is the place to redirect the source forum's old addresses. Event `import.done` fires at the end.
- `import_steps_html($labels, $current)`, `import_stats_html($stats)` and `import_job_html($job, $back)` draw the steps, the numbers found and the progress, the same way for every importer.

## 14. Translations

Wrap user-facing strings in `t('English text')`. Ship `plugins/<id>/lang/<code>.php` returning `['English text' => 'Translation']`; it is loaded automatically for the active language. A pack for a right-to-left script adds `'__dir' => 'rtl'`. The language is chosen per visitor (preference, cookie, then the site default), so never cache translated HTML across requests. Print timestamps with `time_tag($ts)`: the browser re-renders them in the visitor's own time zone.

## 14a. Points: rules, awards and the ledger

The core keeps the balance, the history and the **rule table** (Admin → Points: what each action pays, a daily cap per member, on/off). Plugins declare rules and pay through them, so the admin tunes every number in one place:

```php
'hooks' => ['points.rules' => 'myid_rules', 'region.points.actions' => 'myid_points_action'],
function myid_rules(array $rules, array $ctx): array { $rules['myid_checkin'] = ['label' => t('Daily check-in'), 'amount' => 5, 'cap' => 1, 'once' => true, 'group' => t('Check-in')]; return $rules; }
points_award($user_id, 'myid_checkin', $day_key);   // pays what the rule says; false when off, capped for today, or already paid for that ref
points_add($user_id, -20, 'myid_shop', $item_id);   // a fixed amount, e.g. spending (negative delta)
points_revoke($user_id, 'myid_checkin', $ref, 'myid_undo'); // takes back what a rule paid for that ref
```

`cap` is per member per day (0 = none); `once` refuses a second payment for the same `ref_id`. Ledger lines link to the post or topic behind them for the core reasons; answer `points.ref_url` (ctx reason, ref_id) for yours. Members see the rules that are on under "How to earn points" on `/points`, and the list region `points.actions` puts a button there (check-in, tasks, leaderboard).

## 14b. Plugins that cost points

Your plugin is your own work under any licence you like: FlatBB's AGPL does not extend to plugins and themes (an additional permission, see `LICENSING.md`).

Every plugin is free unless its author sets a number of points on the marketplace (My plugins → Manage; the package and the manifest carry no price). A member pays those points once with their www.flatbb.com account, the author receives them, and the plugin is theirs for good: any forum where that account is connected (Admin → Plugins → Marketplace → Account) installs and updates it, or the zip is downloaded from the plugin page. There is nothing to check inside the plugin: no key, no licence, no expiry. Write it exactly like a free plugin.

## 15. Delivery checklist

- [ ] `php -l` and `php flatbb plugin:check <id>` pass.
- [ ] Everything is prefixed with the plugin id (functions, tables, CSS, JS, storage, hooks).
- [ ] No query in a loop; loop hooks are memory-only; no leftover placeholders.
- [ ] All state changes happen on POST (`require_post()` in handlers; admin pages may branch on `is_post()`, the dispatcher has verified the CSRF token already); permissions checked; output escaped; `php flatbb security:check` and `plugin:check` pass.
- [ ] Works on SQLite and MySQL 5.7 (no dialect SQL, schema via `db_*` helpers).
- [ ] Old settings/data still work; `install` and `uninstall` are idempotent.
- [ ] `version` bumped; `description` accurate; `README.md` describes settings and usage.
- [ ] Narrow screens, long text, empty states and error states handled.

## 16. Prompt to give an AI

No command line is needed to build or ship a plugin: the AI writes `plugins/<id>/plugin.php`, you zip that folder and upload it under Admin → Plugins → Upload plugin (or publish it to everyone at https://www.flatbb.com/market/publish). The complete specification, hooks and API are hosted as one plain-text file at https://www.flatbb.com/dev/plugins.md, so the prompt can be as short as this ([Build a plugin with AI](AI.md) walks through the whole process):

```
Read https://www.flatbb.com/dev/plugins.md first. Then create a flatbb plugin plugins/<id>/plugin.php that: <what it does, where it shows up, its settings, who may use it>.
Follow every rule in the document. Give me the finished folder as a zip I can upload under Admin -> Plugins.
```

Two bundled plugins are meant to be copied: `plugins/hello` (footer badge and a page, the minimum) and `plugins/nav_menu` (a table, an admin page with a drawer form and a list-region hook, the typical shape of a real plugin).

Inside a checkout of FlatBB the local files work the same way:

```
Read CLAUDE.md, docs/PLUGIN.md and docs/HOOKS.md in this repository, then look at plugins/hello/plugin.php.
Create the plugin plugins/<id>/plugin.php that: <what it does, where it shows up, its settings, who may use it>.
Follow every rule in docs/PLUGIN.md. When done run `php -l` and `php flatbb plugin:check <id>` and fix any findings.
```

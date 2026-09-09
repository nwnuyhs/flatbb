# Architecture

flatbb is deliberately small: plain PHP functions, one directory of kernel files, one directory of request handlers, PHP templates, one CSS file and one JS file. There is no autoloader, container, ORM or template engine.

## Request lifecycle

```
index.php
  └─ core/boot.php        defines constants, installs error handlers, requires core/*.php and app/*.php
  └─ app_boot()           creates data/ dirs; if installed: plugins_load() then fire('app.boot')
  └─ dispatch()           (router.php) resolves current_path() against routes() and calls the handler
       └─ handler         e.g. topic_view('12') in app/topic.php — loads data, then
            └─ page()     (render.php) renders app/views/layout.php with the main HTML and both columns, prints, exits
```

- `current_path()` is `/t/slug-12` with clean URLs or `index.php?r=/t/slug-12` without. `url()` produces the right form based on the `rewrite` setting.
- Handlers never `return`; they end with `page()`, `redirect()`, `json_ok()` or `not_found()`.
- CLI: `flatbb` bootstraps the same way (`core/boot.php` + `app_boot()`), then runs a command.

## Load order and dependencies

`helpers → db → schema → lang → auth → hook → plugin → render → markdown → upload → search → cron → router → devtools`, then every `app/*.php`.
Files only call functions from files loaded earlier or at request time (all functions exist by the time any request runs), so ordering only matters for constants.

## Configuration and state

- `data/config.php` returns `['db' => [...], 'secret' => ..., 'debug' => bool, 'lang' => 'en', 'base_url' => optional, 'base_path' => optional, 'phone_home' => optional]`. `config('key')` reads it. The interface language is the `site_lang` setting (Admin → Settings → General); `lang` in config.php is only the fallback. Packs are `lang/<code>.php` (`php flatbb lang:sync <code>` creates or refreshes one with every `t()` string; empty values fall back to English).
- **Update check / install statistics**: once a day the forum asks the marketplace whether a newer core exists, and that request carries the site URL and the flatbb version so the project can count how many sites run it — the same phone-home WordPress does for its update check. Set `'phone_home' => false` in `data/config.php` to opt out; the check still runs but sends only a generic user agent. The marketplace client (`plugins/market`) sends the same header when a forum browses or installs plugins.
- Site settings live in `fb_settings` (`setting('key')`, `save_settings([...])`), defaults in `setting_defaults()`. They are loaded once per request.
- Per-request memoisation: `request_cache('key', fn() => …)`. Categories, groups, settings, the current user and loaded plugins all use it.
- No PHP sessions. Login is a signed cookie (`fb_auth`), CSRF is an HMAC of a visitor cookie (`fb_vt`), flash messages are a short-lived cookie (`fb_flash`).

## Rendering

- `page($title, $mainHtml, $opts)` builds the three-column shell. `left`/`right` default to the standard sidebars; pass `false` to hide or HTML to replace.
- Views are `app/views/*.php`, rendered with `view('name', $vars)` (output buffered, variables extracted).
- Layout positions are **regions** (`core/hook.php`): `region('name')` returns `<div data-slot="name">` + hook output + admin HTML blocks; `region_list('name', $items)` filters arrays (menus, tabs, cards); `slot('name')` is the light in-loop variant. `regions_known()` lists every position and feeds Admin → Layout.
- Icons are inline SVG from `icon_paths()`. Avatars fall back to a coloured initial.
- All colours, sizes and radii are CSS variables in `assets/app.css`; dark mode is `[data-theme=dark]` or `auto`.

## Data access

- `q($sql, $params)`, `one()`, `all()`, `val()`, `col()`; writes via `db_insert/update/delete/upsert/insert_ignore/increment`; `tx()` for transactions.
- Portable schema via `db_types()` and `db_create_table()`; see `docs/DATABASE.md`.
- Lists are always built with batch loading: `topic_list_fetch()` → `topic_list_attach()` (users, categories, tags, read state), `posts_attach()` (users, likes, reply-to). Never query per row.
- Search is a separate table (`fb_search`) with FTS5 (SQLite) or FULLTEXT (MySQL) and a LIKE fallback.

## Plugins

`core/plugin.php`. Registered plugins live in `fb_plugins`; only enabled ones are included at boot. A manifest declares hooks, routes, admin pages, settings schema, assets, cron and install/uninstall. Assets from all enabled plugins are bundled to `data/cache/plugins.css|js` and served through `/plugin-assets/css|js` with a content hash.

## Admin

`app/admin.php` dispatches `/admin/<page>` to `admin_page_<page>()`. `app/admin_ui.php` holds the shared building blocks: `admin_page()` (shell with grouped menu, page header with a primary action, optional right-side **drawer**), `admin_drawer_link()`, `admin_row_menu()` ("…" menu for dangerous actions), `admin_switch()`, `admin_table()`, `admin_form_actions()`.

Editing pattern ("list + drawer"): a list page renders its rows; when the URL carries `?edit=`/`?delete=`/`?settings=` the same handler also builds a `drawer` array (title, sub, body, links, back) and `admin_page()` renders it open beside the list. Links marked `data-drawer` fetch that URL and inject the drawer without reloading (app.js); without JS the link simply navigates. `?full=1` renders the drawer body as a standalone page (deep links, heavy plugin pages). Delete never lives inside an edit form: it is a row-menu entry that opens a confirmation drawer with its follow-up options.

Plugin admin pages are `/admin/ext/<id>/<key>`; they are linked from the plugin's settings drawer and listed under "Plugins" in the admin menu.

## Security model

- Output: `h()` everywhere; markdown is escaped before parsing, so no raw HTML from users ever reaches the page.
- Input: `post_str/post_int/post_list/post_secret/get_str/get_int` clamp types and lengths; direct superglobal reads are forbidden outside core/helpers.php.
- State changes: every POST is CSRF-checked in `dispatch()` before the handler runs (`csrf_exempt` manifest key for token-authenticated API routes); handlers call `require_post()` for the method check and do their own permission checks.
- Templates: `<?= ?>` output must go through `h()`, `t()`, a core HTML helper or `raw()` (intentional HTML). `php flatbb security:check` enforces these three rules statically for core, app and plugins; `plugin:check` applies them to a plugin.
- Rate limits: post interval, new-account limits, registration per IP, login attempts per IP.
- Files: extension whitelist + finfo sniffing, random names, `uploads/` blocks PHP execution, `data/` is not web-accessible.
- Email verification (core/verify.php): with `register_verify` on, registration and address changes need a six-digit code mailed through `mail_send()` (a mail plugin delivers it via `mail.send`); codes are hashed, expire after ten minutes, five attempts, throttled per address and visitor. `fb_users.email_verified` records the outcome.
- Plugin manifests are read statically (core/manifest.php, PHP tokenizer) for disabled plugins and marketplace uploads; only enabled plugins are included.
- Response headers (core/security.php): nosniff, SAMEORIGIN framing, referrer policy, and a nonce-based Content Security Policy (`csp_mode` setting: off / report only / enforce; violations land in `data/csp-report.log`, shown under Tools). Inline scripts carry `csp_nonce()`; plugins use `script_tag()` and extend the policy through `security.csp`.
- Real client IP: `client_ip()` reads the forwarded address only when `REMOTE_ADDR` is a trusted proxy (`trusted_proxies` setting, "cloudflare" expands to Cloudflare's ranges).
- Admin action log: `admin_log()` writes to `fb_admin_log` (who, real IP, action, target, detail; pruned after 180 days) and fires `admin.action`.
- Confirm mode: Settings, Users, Groups, Plugins, Tools and Layout require the admin's password again every N minutes (Settings → Security → Password confirmation window, default 10; 0 switches it off) (`need_sudo()`, signed `fb_sudo` cookie bound to the password hash).

## Adding a core feature (for maintainers)

1. Add the route in `routes_core()` and the handler in the matching `app/*.php` (create a new file if the area is new; keep files under 30 KB).
2. Add a view under `app/views/` if there is markup.
3. Expose extension points: `region()` for layout, `hook()` for data, `fire()` for events. Add the region to `regions_known()` and a description to `docs_hook_descriptions()`.
4. Run `php flatbb hooks:list > docs/HOOKS.md` and `php flatbb api:list > docs/API.md`.

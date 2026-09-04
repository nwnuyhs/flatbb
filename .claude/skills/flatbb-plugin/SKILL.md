---
name: flatbb-plugin
description: Create, modify, check and publish flatbb forum plugins. Use when the user asks for a flatbb plugin, an extension for the forum, a new admin page, a layout widget, or to publish/update a plugin on www.flatbb.com.
---

# flatbb plugin workflow

1. Read `CLAUDE.md`, `docs/PLUGIN.md` and `docs/HOOKS.md` in the repository root. Skim `docs/API.md` for the functions you need. Look at `plugins/hello/plugin.php` and any existing plugin closest to the request.
2. Choose the plugin id (lowercase, underscores). Every function, table, CSS class and JS symbol is prefixed with it.
3. Write `plugins/<id>/plugin.php` returning the manifest. Use the declarative `settings` schema instead of hand-written admin forms; use regions for placement; batch-load data with `topic_list.rows` / `topic.posts` and never query inside loop hooks.
4. Add `plugins/<id>/README.md` (what it does, settings, usage).
5. Validate:
   ```bash
   php -l plugins/<id>/plugin.php
   php flatbb plugin:check <id>
   php flatbb plugin:sync && php flatbb plugin:enable <id>
   ```
   Fix every ERROR; review every WARNING.
6. When changing an existing plugin: keep old settings/data working, bump `version` (patch at least), update `description` if behaviour changed.
7. To publish or update on www.flatbb.com: make sure `FLATBB_TOKEN` is set in the environment (ask the user to set it; never ask for the token value), then
   ```bash
   php flatbb plugin:publish <id> --changelog="<one line>"
   ```
   Report the returned URL.

Rules that are checked and will fail the marketplace review: missing `FLATBB` guard, unprefixed symbols, tables not named `plugin_<id>_*`, callbacks that do not exist, dialect SQL, queries inside loops, unescaped output, state changes without `require_post()`.

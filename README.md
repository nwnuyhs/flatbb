# flatbb

Source: https://github.com/nwnuyhs/flatbb · Download: https://www.flatbb.com/download · Plugins: https://www.flatbb.com/market


A flat, lightweight, AI-friendly forum. Plain PHP 8.1+, SQLite or MySQL 5.7+, no framework, no Composer, no build step. Three-column layout in the spirit of Discourse and Flarum, a plugin system that AI assistants can work with, and one-command publishing to the plugin marketplace at [www.flatbb.com](https://www.flatbb.com).

## Features

- Categories (nested one level, per-group permissions), tags, topics, replies with quotes and @mentions
- Markdown editor with live preview, drag-and-drop image upload, paste-to-upload
- Likes, bookmarks, unread tracking, notifications, full-text search (FTS5 / MySQL FULLTEXT)
- Latest / Top / Unread lists, user profiles, avatars, dark mode, clean URLs, RSS, sitemap
- Admin panel: settings, categories, tags, users, groups, layout blocks, plugins, scheduled jobs, tools
- Plugins: hooks, ~45 layout regions, declarative settings, routes, admin pages, cron, bundled assets; one-click install from the marketplace at www.flatbb.com
- Interface in English, German, French, Spanish, Portuguese, Italian or Dutch (Admin → Settings → General); add a language with `php flatbb lang:sync <code>`
- Everything documented for AI assistants: `CLAUDE.md`, `docs/PLUGIN.md`, `docs/HOOKS.md`, `docs/API.md`

## Requirements

PHP 8.1+ with `pdo_sqlite` or `pdo_mysql`, `mbstring`; `gd` for avatars and image resizing; `curl` and `zip` for the marketplace. Nginx or Apache with rewrite support (optional but recommended).

## Install

1. Unzip: everything is inside one folder `flatbb-<version>/`. Upload its contents so that `index.php` is in the web root (a subdirectory also works).
2. Make `data/` and `uploads/` writable by the web server.
3. Nginx: add the rules from `nginx.conf.example` to your server block. Apache: `.htaccess` is included.
4. Open the site in a browser and follow the installer (choose SQLite or MySQL, name the site, create the admin).
5. Set up the scheduler: call `https://your-site/cron?key=<key shown in Admin → Scheduled jobs>` every minute, or run `php flatbb cron` from crontab.

BaoTa / aaPanel note: paste `nginx.conf.example` into the site's "rewrite" box and comment out the panel's `error_page 404 /404.html;` line in the site config so flatbb can show its own 404 page.

## Command line

```bash
php flatbb cron                      # run due jobs
php flatbb plugin:sync               # register plugins in plugins/
php flatbb plugin:enable <id>
php flatbb plugin:check <id>         # lint + rules
php flatbb plugin:publish <id>       # upload to www.flatbb.com (FLATBB_TOKEN)
php flatbb search:rebuild
php flatbb schema:upgrade
php flatbb test                           # dependency-free test suite (tests/*_test.php)
php flatbb lang:sync <code>              # create/refresh lang/<code>.php with every UI string
php flatbb admin:password <user> <newpass>
php flatbb migrate:import <file.sqlite>   # move a SQLite site into this (MySQL) database
php flatbb upgrade:check                  # latest release on www.flatbb.com
php flatbb upgrade                        # in-place core upgrade (also in Admin → Tools)
```

## Upgrading

Admin → Tools → Updates shows the latest release; **Upgrade** downloads the package from www.flatbb.com, verifies its checksum, replaces the core files (`core/`, `app/`, `assets/`, `lang/`, `docs/`, bundled plugins) and runs the schema upgrade. `data/`, `uploads/` and your own plugins are never touched; the previous files are kept in `data/backup-<version>-<date>/`. Same thing from the shell: `php flatbb upgrade`.

## Building plugins

Start with `plugins/hello/plugin.php`, read `docs/PLUGIN.md`. With Claude Code or another AI assistant, open the repository and say what you want; `CLAUDE.md` gives the assistant everything it needs. Publish with `php flatbb plugin:publish <id>` (see `docs/PUBLISH.md`).

## Layout

```
core/      kernel (db, auth, hooks, plugins, render, markdown, search, cron, router)
app/       request handlers + views
assets/    app.css, app.js
plugins/   one folder per plugin
data/      config, SQLite database, cache  (private)
uploads/   user files (public)
docs/      documentation
```

## License

MIT — see `LICENSE`.

---

## 中文简介

flatbb 是一个纯 PHP 8.1 的轻量论坛：无框架、无 Composer、无构建；SQLite 或 MySQL 5.7+；Discourse/Flarum 风格三栏布局；插件系统对 AI 友好，并支持一条命令把插件发布到官网 www.flatbb.com。

- 安装：上传文件，保证 `data/`、`uploads/` 可写，nginx 按 `nginx.conf.example` 配置伪静态，浏览器打开站点按向导安装。
- 宝塔用户：把 `nginx.conf.example` 内容粘贴到站点"伪静态"，并把站点配置里的 `error_page 404 /404.html;` 注释掉。
- 开发插件：先读 `docs/PLUGIN.md`（中文版 `docs/zh/PLUGIN.md`），参考 `plugins/hello`。用 Claude Code 等工具时直接打开仓库，`CLAUDE.md` 会引导 AI。
- 发布插件：`php flatbb plugin:publish <id>`，详见 `docs/PUBLISH.md`。

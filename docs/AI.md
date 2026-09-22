# Build a FlatBB plugin with AI

A FlatBB plugin is one folder with a `plugin.php` that follows a short, strict specification. An AI coding assistant such as Claude Code, Codex, Cursor or ChatGPT can write one from a single prompt: you describe the plugin, the assistant reads the specification, you upload the zip. No command line is needed.

## 1. Give your assistant this prompt

```text
Read https://www.flatbb.com/dev/plugins.md first.
Then create a FlatBB plugin plugins/<id>/plugin.php that:
<what it does, where it shows up, its settings, who may use it>.
Follow every rule in the document.
Give me the finished folder as a zip I can upload under Admin -> Plugins.
```

`https://www.flatbb.com/dev/plugins.md` is one plain-text file with everything the assistant needs: this page, the plugin specification, every hook and region, the core API and the theme rules. It follows the latest FlatBB release.

What to write in the angle brackets, so the first answer is the right one:

- **Where it shows up**: a sidebar card, a line under every post, a new page at `/something`, an admin page, a button on the topic page.
- **Its settings**, with their defaults: "a limit, 20 by default".
- **Who may use it**: everyone, members, moderators, administrators.
- **What it stores**, if anything: "remember which members voted".

## 2. Install it on your forum

Open **Admin → Plugins → Upload plugin** and choose the zip. A new plugin is switched on right away; its settings open from its row in the list. If something does not work, paste the error or describe what you see back to your assistant and upload the fixed zip: an update keeps the plugin's data and settings.

## 3. Share it with everyone (optional)

Publish the zip at https://www.flatbb.com/market/publish, or connect your forum to the marketplace and publish from its admin. Other forums then install it in one click. The details, including updates and the checks a package has to pass, are in [Publishing a plugin](PUBLISH.md).

## A theme instead of a plugin

Ask for a theme in the same prompt: "…create a FlatBB theme plugins/<id>/ with `'type' => 'theme'` that looks like <colours, mood, density, corners, fonts>. Support light and dark mode." Upload it under **Admin → Appearance → Themes**.

## Working inside a copy of FlatBB

With the FlatBB source on your computer, the assistant can also check its own work:

```text
Read CLAUDE.md, docs/PLUGIN.md and docs/HOOKS.md in this repository,
then look at plugins/hello/plugin.php.
Create the plugin plugins/<id>/plugin.php that:
<what it does, where it shows up, its settings, who may use it>.
Follow every rule in docs/PLUGIN.md. When done run php -l and
php flatbb plugin:check <id> and fix any findings.
```

Two bundled plugins are meant to be copied: `plugins/hello` (the minimum: a footer badge and a page) and `plugins/nav_menu` (a table, an admin page with a form and a list region: the shape of a real plugin).

## What changed

New hooks, new regions and rule changes, release by release: [What's new for plugin authors](WHATS-NEW.md).

## Writing plugins by hand

The reference your assistant reads is written for people too: [Plugin specification](PLUGIN.md), [Hooks and regions](HOOKS.md), [Core API](API.md), [Themes and layout](THEME.md), [Database](DATABASE.md) and [Architecture](ARCHITECTURE.md).

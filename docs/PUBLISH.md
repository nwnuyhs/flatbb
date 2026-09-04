# Publishing a plugin to www.flatbb.com

The marketplace at https://www.flatbb.com/market lists plugins that people can install from Admin → Plugins → Market. Publishing is one command; an AI assistant can run it for you.

## 1. Get a token

Sign in at https://www.flatbb.com, open **Settings → Developer**, create a token (`fbk_…`). Keep it secret. Set it once:

```bash
export FLATBB_TOKEN=fbk_xxxxxxxx        # Linux/macOS
setx FLATBB_TOKEN fbk_xxxxxxxx          # Windows (new shells)
```

or store it in `data/config.php` as `'market_token' => 'fbk_…'` (the file is never web-accessible).

## 2. Prepare the plugin

- `plugins/<id>/plugin.php` with a complete manifest (`id`, `name`, `version`, `description`, `author`, `url`).
- `plugins/<id>/README.md` — becomes the marketplace page: what it does, settings, screenshots (relative image paths inside the plugin folder are allowed).
- Bump `version` for every release; the marketplace rejects a version that already exists.
- Run the checks:

```bash
php flatbb plugin:check <id>
```

## 3. Publish

From the browser: zip the plugin folder and upload it at https://www.flatbb.com/market/publish (signed in). The manifest is read from plugin.php, README.md becomes the plugin page.

From the command line:

```bash
php flatbb plugin:publish <id> --changelog="What changed in this version"
```

The command packages `plugins/<id>/` into `dist/<id>-<version>.zip`, re-runs the checks and uploads it. On success it prints the marketplace URL. A new plugin is listed right away as **Community** (it passed the automatic checks: syntax, prefixes, no executables, matching manifest) and becomes **Certified** once a marketplace reviewer has looked at it; forum admins see the badge in the marketplace and are asked to confirm before installing a community plugin. Updates to a listed plugin go live immediately unless the automated checks flag something.

Options: `--token=…` overrides the environment; `--insecure` skips TLS verification when your PHP has no CA bundle (typical on Windows). `--insecure` skips TLS verification when your PHP has no CA bundle (typical on Windows: better set `curl.cainfo` in php.ini).

## 4. Let an AI do it

The repository ships a Claude Code skill (`.claude/skills/flatbb-plugin/`) and the `/publish-plugin` command. In Claude Code:

```
/publish-plugin hello
```

or simply: "Bump the version of the hello plugin, run the checks and publish it to www.flatbb.com". The assistant reads `docs/PLUGIN.md`, runs `plugin:check`, fixes findings, bumps the version, and runs `plugin:publish`. It never sees the token: the CLI reads `FLATBB_TOKEN` from the environment.

## 5. From the admin panel

Admin → Plugins → **Publish** on a plugin row does the same thing from the browser (the token is stored in site settings, admin-only).

## API (for other tools)

`POST https://www.flatbb.com/api/market/publish` — multipart form, `Authorization: Bearer <token>`:

| Field | Value |
| --- | --- |
| `id`, `version` | from the manifest |
| `manifest` | JSON of the public manifest keys |
| `changelog`, `readme` | markdown text |
| `flatbb_version` | version of the publishing site |
| `file` | the zip (`<id>/plugin.php` at its root) |

Response: `{"ok":true,"url":"https://www.flatbb.com/market/<id>"}` or `{"ok":false,"error":"…"}`.

Read endpoints used by the Market page: `GET /api/market/plugins?q=&page=`, `GET /api/market/plugins/<id>`, `GET /api/market/plugins/<id>/download?version=`, `GET /api/market/core/latest`.

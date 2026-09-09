# Publishing a plugin to www.flatbb.com

The marketplace at https://www.flatbb.com/market lists plugins that people can install from Admin → Plugins → Market. Publishing is one command; an AI assistant can run it for you.

## 1. Get a token

Sign in at https://www.flatbb.com, open **Settings → Developer**, create a token (`fbk_…`). Keep it secret. Set it once:

```bash
export FLATBB_TOKEN=fbk_xxxxxxxx        # Linux/macOS
setx FLATBB_TOKEN fbk_xxxxxxxx          # Windows (new shells)
```

or store it in `data/config.php` as `'market_token' => 'fbk_…'` (the file is never web-accessible).

From a forum's admin panel none of this is needed: **Marketplace → Account → Connect** sends you to www.flatbb.com to approve, and the token is handed to the forum without copying anything.

## 2. Prepare the plugin

- `plugins/<id>/plugin.php` with a complete manifest (`id`, `name`, `version`, `description`, `author`, `url`).
- `plugins/<id>/README.md` — becomes the marketplace page: what it does, settings, screenshots (relative image paths inside the plugin folder are allowed).
- Bump `version` for every release; the marketplace rejects a version that already exists.
- Run the checks:

```bash
php flatbb plugin:check <id>
```

## 3. Publish

From the browser: zip the plugin folder and upload it at https://www.flatbb.com/market/publish (signed in). The manifest is read from plugin.php, README.md becomes the plugin page. The form also takes a changelog (several lines) and up to five screenshots (jpg, png, gif or webp, 2 MB each); the first screenshot is the cover in the plugin list. Every plugin on the marketplace is free.

From the command line:

```bash
php flatbb plugin:publish <id> --changelog="What changed in this version" --images=shot1.png,shot2.png
```

The command packages `plugins/<id>/` into `dist/<id>-<version>.zip`, re-runs the checks and uploads it. On success it prints the marketplace URL. A new plugin is listed right away as **Community** (it passed the automatic checks: syntax, prefixes, no executables, matching manifest) and becomes **Certified** once a marketplace reviewer has looked at it; forum admins see the badge in the marketplace and are asked to confirm before installing a community plugin. Updates to a listed plugin go live immediately unless the automated checks flag something.

Options: `--images=a.png,b.png` uploads screenshots (up to five; they replace the ones already on the plugin page, leave the flag out to keep them); `--token=…` overrides the environment; `--insecure` skips TLS verification when your PHP has no CA bundle (typical on Windows). `--insecure` skips TLS verification when your PHP has no CA bundle (typical on Windows: better set `curl.cainfo` in php.ini). `--confirm-other=1` is needed when a marketplace administrator republishes a plugin id that belongs to another developer (the answer names the owner otherwise).

## 4. Let an AI do it

The repository ships a Claude Code skill (`.claude/skills/flatbb-plugin/`) and the `/publish-plugin` command. In Claude Code:

```
/publish-plugin hello
```

or simply: "Bump the version of the hello plugin, run the checks and publish it to www.flatbb.com". The assistant reads `docs/PLUGIN.md`, runs `plugin:check`, fixes findings, bumps the version, and runs `plugin:publish`. It never sees the token: the CLI reads `FLATBB_TOKEN` from the environment.

## 5. From the admin panel

Admin → Plugins → **Publish** on a plugin row opens the same form in the admin panel: changelog, screenshots, and the developer token the first time (it is then stored in the plugin settings, admin-only). When the plugin is not yours (the marketplace lists it under another developer, or the manifest names another author), the form says so and asks for a confirmation tick before it publishes; the marketplace itself refuses another developer's id unless your account is one of its administrators, and even then only with that confirmation.

Screenshots and the changelog of the latest version can be changed later without publishing a new version: on www.flatbb.com go to Settings → Developer → **My plugins** → Manage.

## API (for other tools)

`POST https://www.flatbb.com/api/market/publish` — multipart form, `Authorization: Bearer <token>`:

| Field | Value |
| --- | --- |
| `id`, `version` | from the manifest |
| `manifest` | JSON of the public manifest keys |
| `changelog`, `readme` | markdown text |
| `images[0]`…`images[4]` | optional screenshots (jpg/png/gif/webp, 2 MB each); when present they replace the plugin's set |
| `flatbb_version` | version of the publishing site |
| `file` | the zip (`<id>/plugin.php` at its root) |

Response: `{"ok":true,"url":"https://www.flatbb.com/market/<id>"}` or `{"ok":false,"error":"…"}`.

Read endpoints used by the Market page: `GET /api/market/plugins?q=&page=`, `GET /api/market/plugins/<id>`, `GET /api/market/plugins/<id>/download?version=`, `GET /api/market/core/latest`.

## Plugins that cost points

Set a number of points on the plugin's Manage page (My plugins → Manage on www.flatbb.com); 0, the default, means free. A member gets the plugin once with the points of their account: they pay N, you receive N, the marketplace keeps nothing, and the plugin stays theirs through every later version. Their forum installs it with the account connected (Admin → Plugins → Marketplace → Account → token), or they download the zip from the plugin page after "Get for N points". Refunds are done by the marketplace staff (Market review → Purchases). Nothing changes in the package: a plugin that costs points is written like a free one.

API: `POST /api/market/buy` with `id=` and the account's Bearer token answers `{"ok":true,"message":…,"points":balance}` or `402` with the reason; `GET /api/market/whoami` returns `username`, `points` and `purchased` (plugin ids); a download of a plugin that costs points needs the Bearer token of an account that has it (else `402`).


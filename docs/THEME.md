# Themes and layout

A **theme** changes how a FlatBB forum looks: colours, shapes, type, component styles and, when needed, the HTML of single templates. Features (pages, tables, settings that do things) belong in [plugins](PLUGIN.md); a theme only changes the look.

A theme **is a plugin** whose manifest says `'type' => 'theme'`. It is packaged, uploaded, checked and published exactly like a plugin, and the marketplace lists it under Themes. The differences:

- One theme is on at a time. Switching a theme on switches the previous one off; no theme means the built-in look of `assets/app.css`.
- It is managed under **Admin → Appearance → Themes** (cards with a screenshot, Activate, Preview, Customize, Upload theme), not under Plugins.
- An admin can **Preview** an installed theme: only their own browser shows it, with a bar on top of every page (Activate / Exit preview).

## 1. The three layers

Use the lightest layer that does the job.

| Layer | Where | What for |
| --- | --- | --- |
| **Tokens** | manifest `tokens` | Colours, radius, fonts, spacing of components. Most themes need nothing else. |
| **Stylesheet** | manifest `assets.css` (a file in the theme) | Restyle components beyond what tokens reach. Loads after `app.css` and after every plugin's CSS. |
| **Templates** | `views/<name>.php` in the theme | Change the HTML of one core template (`app/views/<name>.php`). Last resort: templates must follow core changes. |

## 2. Quick start

```bash
php flatbb theme:new midnight --name="Midnight"   # plugins/midnight/: plugin.php, assets/theme.css, README.md
php flatbb theme:check midnight                    # tokens, templates, screenshot, CSS hints, security rules
```

Then Admin → Appearance → Themes → **Scan plugins folder**, **Preview**, **Activate**. Without a command line, create the same files by hand (below), zip the folder and use **Upload theme**.

```
plugins/midnight/
  plugin.php          manifest (data only) with 'type' => 'theme'
  screenshot.png      1200×800, shown on the theme card and required by the marketplace
  assets/theme.css    optional component styles
  views/              optional template copies (php flatbb theme:override midnight topic_rows)
  README.md           what the theme looks like, its settings
```

## 3. Manifest

```php
<?php
if (!defined('FLATBB')) exit;

return [
    'id' => 'midnight',
    'type' => 'theme',
    'name' => 'Midnight',
    'version' => '1.0.0',
    'description' => 'Deep blue surfaces, violet accent, tighter corners.',
    'author' => 'you',
    'requires' => ['flatbb' => '0.1.89'],
    'screenshot' => 'screenshot.png',            // optional: screenshot.png|jpg|webp is found without it
    'tokens' => [
        'all'   => ['--radius' => '8px', '--radius-sm' => '6px', '--card-shadow' => 'none'],
        'light' => ['--bg' => '#f4f5fb', '--panel' => '#ffffff', '--line' => '#e3e5f0'],
        'dark'  => ['--bg' => '#0d1020', '--panel' => '#151a2e', '--line' => '#262c45'],
    ],
    'settings' => [
        'accent' => ['type' => 'color', 'label' => 'Accent colour', 'default' => '#7c5cff', 'token' => '--brand'],
        'corners' => ['type' => 'number', 'label' => 'Corner radius (px)', 'default' => 8, 'min' => 0, 'max' => 20, 'token' => '--radius', 'unit' => 'px'],
        'flat' => ['type' => 'checkbox', 'label' => 'Flat cards (no shadow)', 'default' => 1, 'token' => '--card-shadow', 'values' => ['1' => 'none', '0' => '']],
    ],
    'assets' => ['css' => ['assets/theme.css']],
];
```

| Key | Meaning |
| --- | --- |
| `type` | `'theme'`. Anything else is a plugin. |
| `tokens` | Groups `all` (every colour mode), `light`, `dark`, each `'--variable' => 'value'`. Light and dark follow the page's colour mode, and "follow system" follows the visitor's system. |
| `settings` | The usual settings schema ([PLUGIN.md §5](PLUGIN.md)), shown under **Customize** on the active theme. A setting with `token` sets that variable from the saved value: `unit` is appended to a number, `values` maps the saved value to CSS (a value mapped to `''` sets nothing), `mode` (`light`/`dark`) limits it to one colour mode. |
| `assets.css` | Stylesheet files inside the theme (a function name also works; it runs only while the theme is on). `assets.js` is bundled like a plugin's. |
| `screenshot` | Image inside the theme folder. |

The manifest is data (no calls, no variables), as for plugins. The rules of [PLUGIN.md §4](PLUGIN.md) apply: own CSS classes and functions carry the theme id prefix.

Token values are printed inside `<style>`, so a value may not contain `; { } < > \`, a comment, `url(` or `@import` (backgrounds with images go in the stylesheet). When a theme sets `--brand` as `#rrggbb` without `--brand-hover`/`--brand-soft`, both shades are derived. A brand colour the admin set in Settings → General still wins over the theme.

## 4. Tokens

Global variables, defined in `:root` of `assets/app.css` (light) and overridden for dark mode:

| Group | Variables |
| --- | --- |
| Brand | `--brand` (Settings → General → Brand color), `--brand-hover`, `--brand-soft`, `--brand-text` |
| Surfaces | `--bg`, `--panel`, `--panel-2`, `--line`, `--line-soft`, `--backdrop`, `--shadow`, `--shadow-md` |
| Text | `--text`, `--text-muted`, `--text-subtle`, `--text-disabled` |
| Status | `--success`, `--danger`, `--warning`, `--info` and `*-soft` |
| Shape | `--radius`, `--radius-sm`, `--radius-pill` |
| Type | `--font`, `--mono`, `--font-size-xs` 11px, `-sm` 12px, `-md` 14px, `-lg` 16px, `-xl` 18px, `-2xl` 22px, `-3xl` 26px |
| Layout | `--topbar-h`, `--left-w`, `--right-w`, `--gap`, `--container` |

Component tokens are **not defined** anywhere: core rules read them with a fallback, `var(--card-bg, var(--panel))`, so an unset token means the default look. Set them to restyle a component without CSS:

| Token | Default | Used by |
| --- | --- | --- |
| `--line-height` | `1.55` | body text |
| `--heading-weight` | `650` | h1–h4 |
| `--topbar-bg`, `--topbar-line` | `--panel`, `--line` | top bar |
| `--card-bg`, `--card-line`, `--card-radius`, `--card-shadow` | `--panel`, `--line`, `--radius`, `--shadow` | `.card`, `.list-card` (no shadow), `.post` |
| `--card-pad`, `--card-head-pad` | `16px`, `12px 16px` | card body and head |
| `--post-pad` | `16px` | posts on a topic page |
| `--btn-pad`, `--btn-radius`, `--btn-weight` | `7px 14px`, `--radius-sm`, `550` | `.btn` |
| `--input-pad`, `--input-radius`, `--input-bg` | `9px 11px`, `--radius-sm`, `--panel` | inputs, selects, textareas |
| `--tab-pad`, `--tab-radius`, `--tab-active-bg`, `--tab-active-text` | `6px 12px`, `--radius-sm`, `--brand-soft`, `--brand` | `.tab` |
| `--side-link-radius`, `--side-link-active-bg`, `--side-link-active-text` | `--radius-sm`, `--brand-soft`, `--brand` | left navigation |
| `--row-pad`, `--row-line` | `11px 16px`, `--line-soft` | topic list rows (phones keep their own padding) |
| `--avatar-radius` | `50%` | avatars (`8px` gives rounded squares) |
| `--flag-radius` | `4px` | `.flag` labels |
| `--footer-bg`, `--footer-line` | `--panel`, `--line` | footer |

Variables of your own start with the theme id: `--midnight-glow`.

## 5. Stylesheet

- Loads last (after `app.css`, the admin brand colour and all plugin CSS), so equal selectors win without `!important`. Never use `!important`.
- Use variables for every colour, so light and dark mode both work. A colour that must differ per mode is a token in `light`/`dark`.
- Use logical properties (`margin-inline-start`, `inset-inline-end`, `text-align: start`), never `left`/`right`: right-to-left languages mirror the layout.
- Restyle core classes freely (list below); new classes carry the theme prefix (`.midnight-hero`).
- The stylesheet also applies on admin pages, so keep forms and tables readable.

## 6. Templates

```bash
php flatbb theme:override midnight topic_rows          # copies app/views/topic_rows.php to plugins/midnight/views/ with a stamp
php flatbb theme:override midnight topic_rows --stamp  # after merging core changes into your copy: record the new core version
php flatbb theme:override midnight topic_rows --force  # copy the core template again (your copy is kept as topic_rows.php.bak)
```

- `views/<name>.php` renders instead of `app/views/<name>.php`, with the same variables (each core template lists them in its first comment).
- The first line is the stamp `<?php /* flatbb-view: topic_rows@3f2a9c1b2d4e … */ ?>`: the core template and its hash when it was copied. When the core template changes, `theme:check` and the theme card list the template as older than the core. The theme keeps working meanwhile.
- Keep every `region()`, `slot()` and `hook()` call of the core template: they are where plugins put their buttons, badges and cards.
- The template rules of the core apply: `<?= ?>` starts with `h()`, `t()`, `raw()` or a safe helper; no database queries in templates; text through `t()`.
- **Admin pages always use the core templates**, so a broken theme can never lock an admin out.
- A template that throws (an undefined variable counts) is replaced by the core template for that render, and the error is shown on the theme card.
- Override the smallest template that works: `card_stats` or `topic_rows`, not `layout`.

Templates: `layout` (page shell), `topic_list`, `topic_rows`, `categories`, `category_head`, `topic`, `post`, `post_rows`, `topic_form`, `post_form`, `editor`, `sidebar_left`, `sidebar_right`, `sidebar_topic`, `card_user`, `card_stats`, `card_newest`, `card_related`, `card_author`, `profile`, `settings`, `notifications`, `search`, `tags`, `login`, `register`, `forgot`, `reset`, `error`, `setup`.

## 7. Check, package, publish

`php flatbb theme:check <id>` runs every `plugin:check` rule plus:

- errors: unknown token groups, invalid variable names or values, a template that replaces no core template, a missing screenshot file named in the manifest;
- warnings: a variable that is not a core variable and not prefixed, templates without a stamp or older than the core, no screenshot, many hard-coded colours, physical `left`/`right`, `!important`, routes or admin pages in a theme.

Package and publish like a plugin: `php flatbb plugin:package <id>`, `php flatbb plugin:publish <id> --images=screenshot.png`, or upload the zip at https://www.flatbb.com/market/publish. The marketplace requires at least one screenshot for a theme.

## 8. Prompt to give an AI

```
Read CLAUDE.md and docs/THEME.md in this repository. Create the FlatBB theme plugins/<id>/ (run php flatbb theme:new <id> first) that looks like: <colours, mood, density, corners, fonts, references>.
Use tokens first, assets/theme.css only for what tokens cannot do, and template overrides (php flatbb theme:override) only if the HTML must change.
Support light and dark mode. When done, run php flatbb theme:check <id> and fix every error and warning.
```

Without a checkout: "Read https://www.flatbb.com/dev/plugins.md (the Themes section), then write plugins/<id>/plugin.php with 'type' => 'theme' that …". Zip the folder and upload it under Admin → Appearance → Themes.

## Layout (desktop ≥ 1200px)

```
┌──────────────────────────── header ─────────────────────────────┐
│ [☰] [logo] header.left  header.nav … header.right.before_search  │
│      [search] header.right.after_search [theme] [+] [🔔] [avatar]│
├───────────────┬────────────────────────────────┬────────────────┤
│ sidebar.left  │ main.before                    │ sidebar.right  │
│  .top         │ main.tabs / main.toolbar       │  .top          │
│  .nav (list)  │ topic_list.item.* (per row)    │  .cards (list) │
│  categories   │ topic_list.after               │  .bottom       │
│  tags         │ main.after                     │                │
│  .bottom      │                                │                │
├───────────────┴────────────────────────────────┴────────────────┤
│ footer.left            footer.links (list)          footer.right │
└─────────────────────────────────────────────────────────────────┘
```

Topic page: `topic.header`, `topic.actions` (list), `post.before` / `post.content_after` / `post.actions` (list) / `post.after` per post, `topic.replies_after`, `composer.toolbar` (list), `composer.extra`; right column becomes `topic.sidebar.top` / `.cards` / `.bottom`.

Breakpoints: < 1200px the right column moves under the main column; < 992px the left column becomes an off-canvas drawer (☰); < 640px compact rows and icon-only actions.

Every position is listed in `regions_known()` and in Admin → Layout, where custom HTML blocks can be inserted without code.

## Right-to-left

`<html dir="rtl">` is set when the active language pack declares `'__dir' => 'rtl'` (Persian, Arabic, Hebrew). `assets/app.css` uses logical properties, so the whole layout mirrors by itself; a few `[dir="rtl"]` rules flip the phone drawer and directional icons. Themes follow the same rule.

## Reusable classes

`.card .card-head .card-body`, `.list-card .list-head`, `.btn .btn-primary .btn-ghost .btn-danger .btn-sm .btn-lg .btn-block`, `.icon-btn`, `.tabs .tab`, `.flag .flag-danger .flag-success`, `.badge`, `.tag-badge`, `.cat-badge`, `.muted .small .mono .hidden`, `.form-row .form-grid .form-help .check`, `.flash .flash-error .flash-success`, `.empty`, `.table-wrap table.admin`, `.post-content` (markdown typography), `.topic-rows .topic-row`, `.pagination`, `.dropdown .dropdown-menu`, `.topbar`, `.side-link`, `.footer`.

## Icons

`icon('name')` renders inline SVG (24×24, `currentColor`, stroke 2). Add icons from a plugin with the `icon.paths` hook: `$paths['myid-star'] = '<path d="…"/>';`.

## Logo and colours

Admin → Settings: site name, logo and favicon (uploaded images; the default mark is `logo_mark()` in core/render.php and `assets/favicon.svg`, both drawn from the same SVG, the inline one follows `--brand`), brand colour, default colour mode (auto/light/dark), footer text, extra `<head>`/`</body>` HTML.

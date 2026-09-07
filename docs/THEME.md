# Theming and layout

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

## CSS variables

Defined in `:root` of `assets/app.css` (light) and overridden by `[data-theme="dark"]` / `[data-theme="auto"]` + `prefers-color-scheme`.

| Group | Variables |
| --- | --- |
| Brand | `--brand` (set from Admin → Settings → Brand color), `--brand-hover`, `--brand-soft`, `--brand-text` |
| Surfaces | `--bg`, `--panel`, `--panel-2`, `--line`, `--line-soft`, `--backdrop`, `--shadow`, `--shadow-md` |
| Text | `--text`, `--text-muted`, `--text-subtle`, `--text-disabled` |
| Status | `--success`, `--danger`, `--warning`, `--info` and `*-soft` |
| Shape | `--radius`, `--radius-sm`, `--radius-pill` |
| Type | `--font`, `--mono`, `--font-size-xs` 11px, `-sm` 12px, `-md` 14px, `-lg` 16px, `-xl` 18px, `-2xl` 22px, `-3xl` 26px |
| Layout | `--topbar-h`, `--left-w`, `--right-w`, `--gap`, `--container` |

Rules: never hard-code a colour or font size in plugin CSS; use the variables. Never use `!important`.

## Right-to-left

`<html dir="rtl">` is set when the active language pack declares `'__dir' => 'rtl'` (Persian, Arabic, Hebrew). `assets/app.css` uses logical properties (`margin-inline-start`, `inset-inline-end`, `text-align: start`…), so the whole layout mirrors by itself; a few `[dir="rtl"]` rules flip the phone drawer and directional icons. Theme plugins should follow the same rule and avoid physical `left`/`right` properties.

## Reusable classes

`.card .card-head .card-body`, `.btn .btn-primary .btn-ghost .btn-danger .btn-sm .btn-lg .btn-block`, `.icon-btn`, `.tabs .tab`, `.flag .flag-danger .flag-success`, `.badge`, `.tag-badge`, `.cat-badge .cat-dot`, `.muted .small .mono .hidden`, `.form-row .form-grid .form-help .check`, `.flash .flash-error .flash-success`, `.empty`, `.table-wrap table.admin`, `.post-content` (markdown typography), `.topic-rows .topic-row`, `.pagination`, `.dropdown .dropdown-menu`.

## Making a theme plugin

A theme is a plugin whose CSS overrides variables (and optionally a few components):

```php
function midnight_css(): string
{
    return ':root{--brand:#7c3aed;--brand-hover:#6d28d9;--brand-soft:#ede9fe;--radius:6px}'
         . '[data-theme="dark"],[data-theme="auto"]{--bg:#0b0b12}';
}
return ['id' => 'midnight', 'name' => 'Midnight theme', 'version' => '1.0.0', 'description' => 'Purple accent, sharper corners.', 'author' => 'you', 'assets' => ['css' => ['midnight_css']]];
```

To change markup, override a view: plugins can filter `page.options` to replace `left`/`right`, add regions, or (as a last resort) hook `page.before_output` and transform the HTML.

## Icons

`icon('name')` renders inline SVG (24×24, `currentColor`, stroke 2). Add icons from a plugin with the `icon.paths` hook: `$paths['myid-star'] = '<path d="…"/>';`.

## Logo and colours

Admin → Settings: site name, logo and favicon (uploaded images; the default mark is `logo_mark()` in core/render.php and `assets/favicon.svg`, both drawn from the same SVG, the inline one follows `--brand`), brand colour, default theme (auto/light/dark), footer text, extra `<head>`/`</body>` HTML.

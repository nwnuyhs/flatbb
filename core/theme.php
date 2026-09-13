<?php
/**
 * Themes.
 *
 * A theme is a plugin whose manifest declares 'type' => 'theme' (docs/THEME.md). One theme is on at a time: switching a theme
 * on switches the previous one off, and no theme at all means the look of assets/app.css. A theme works in three layers,
 * lightest first:
 *   tokens     manifest 'tokens' => ['all' => [...], 'light' => [...], 'dark' => [...]]: CSS variables printed in <head>
 *   CSS        manifest 'assets' => ['css' => [...]]: its own stylesheet, loaded after every plugin's CSS (data/cache/theme.css)
 *   templates  plugins/<id>/views/<name>.php renders instead of app/views/<name>.php (never on /admin pages; a template that
 *              fails falls back to the core one and the error is shown on Admin → Themes)
 * A setting may carry 'token' => '--brand': a colour picker or a text field then sets a variable without code.
 * An admin can preview an installed theme in their own browser before switching it on (cookie fb_theme_preview).
 * Code-free rule: a disabled theme's PHP never runs, except its templates while its own admin previews it on purpose.
 */

const THEME_PREVIEW_COOKIE = 'fb_theme_preview';

function plugin_is_theme(?array $manifest): bool
{
    return is_array($manifest) && (string)($manifest['type'] ?? '') === 'theme';
}

/** Installed themes keyed by id: the manifest snapshot taken when the folder was scanned, plus 'enabled'. Never runs theme code. */
function themes(): array
{
    return request_cache('themes', static function (): array {
        $out = [];
        foreach (plugins() as $id => $row) {
            $snap = is_array($row['manifest'] ?? null) ? $row['manifest'] : [];
            if (!plugin_is_theme($snap)) continue;
            $out[(string)$id] = ['id' => (string)$id, 'name' => (string)$row['name'], 'version' => (string)$row['version']] + $snap + ['enabled' => false];
            $out[(string)$id]['enabled'] = (int)$row['enabled'] === 1;
        }
        return $out;
    }) ?? [];
}

/** The theme that is switched on for everyone ('' = the core look). */
function theme_enabled_id(): string
{
    foreach (themes() as $id => $t) if (!empty($t['enabled'])) return (string)$id;
    return '';
}

/** The theme this request renders with: the one an admin is previewing, else the enabled one. */
function theme_active(): string
{
    $preview = theme_preview_id();
    return $preview !== '' ? $preview : theme_enabled_id();
}

/** The installed theme the signed-in admin is previewing ('' when none). */
function theme_preview_id(): string
{
    return request_cache('theme_preview', static function (): string {
        $id = cookie_str(THEME_PREVIEW_COOKIE, 41);
        if ($id === '' || !plugin_id_valid($id) || !isset(themes()[$id]) || !is_file(plugin_path($id, 'plugin.php'))) return '';
        return is_admin() ? $id : '';
    }) ?? '';
}

/** A theme's manifest: the running one for the enabled theme, read as text (never executed) for any other. */
function theme_manifest(string $id): ?array
{
    return request_cache('theme_manifest_' . $id, static fn(): ?array => plugin_enabled($id) ? plugin_manifest($id) : plugin_peek($id));
}

/** A theme's preview image inside its folder: manifest 'screenshot', else screenshot.png|jpg|webp; '' when there is none. */
function theme_screenshot(string $id, array $m = []): string
{
    $named = (string)($m['screenshot'] ?? '');
    foreach (array_merge($named !== '' ? [$named] : [], ['screenshot.png', 'screenshot.jpg', 'screenshot.webp']) as $file) {
        if (plugin_id_valid($id) && preg_match('#^[a-z0-9_/.-]+\.(png|jpe?g|webp)$#i', $file) && !str_contains($file, '..') && is_file(plugin_path($id, $file))) return $file;
    }
    return '';
}

/* ---------------------------------------------------------------- tokens */

/** "brand" or "--brand" → "--brand"; '' when the name is not a plain CSS variable name. */
function theme_token_name(string $name): string
{
    $name = '--' . ltrim(strtolower(trim($name)), '-');
    return preg_match('/^--[a-z][a-z0-9-]{0,48}$/', $name) ? $name : '';
}

/** A token value that is safe to print inside <style>; '' when it could close the rule, load something or break out of the tag. */
function theme_token_value(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 300) return '';
    return preg_match('/[;{}<>\\\\]|\/\*|url\s*\(|expression\s*\(|@import|javascript:/i', $value) ? '' : $value;
}

/**
 * The variables a theme sets, grouped by colour mode: ['all' => [--name => value], 'light' => [...], 'dark' => [...]].
 * Sources: manifest 'tokens', then settings that carry 'token' (saved value, else the default; 'unit' is appended to numbers,
 * 'values' maps a saved value to CSS, 'mode' picks light or dark). A --brand without its shades gets them derived.
 */
function theme_tokens(string $id, ?array $m = null): array
{
    $m ??= theme_manifest($id) ?? [];
    $out = ['all' => [], 'light' => [], 'dark' => []];
    $put = static function (string $group, string $name, mixed $value) use (&$out): void {
        $n = theme_token_name($name);
        $v = is_scalar($value) ? theme_token_value((string)$value) : '';
        if ($n !== '' && $v !== '' && isset($out[$group])) $out[$group][$n] = $v;
    };
    foreach ((array)($m['tokens'] ?? []) as $group => $set) {
        foreach (is_array($set) ? $set : [] as $name => $value) $put((string)$group, (string)$name, $value);
    }
    $saved = (array)(plugins()[$id]['settings'] ?? []);
    foreach ((array)($m['settings'] ?? []) as $key => $def) {
        if (!is_array($def) || !is_string($def['token'] ?? null)) continue;
        $raw = $saved[$key] ?? ($def['default'] ?? '');
        if (isset($def['values']) && is_array($def['values'])) $raw = $def['values'][(string)$raw] ?? '';
        elseif (isset($def['unit']) && is_numeric($raw)) $raw = (string)(0 + $raw) . (string)$def['unit'];
        $put(in_array($def['mode'] ?? '', ['light', 'dark'], true) ? (string)$def['mode'] : 'all', $def['token'], $raw);
    }
    foreach (['all', 'light', 'dark'] as $group) {
        $brand = $out[$group]['--brand'] ?? '';
        if (!preg_match('/^#[0-9a-f]{6}$/i', $brand)) continue;
        [$r, $g, $b] = sscanf($brand, '#%02x%02x%02x');
        $has = static fn(string $n): bool => isset($out['all'][$n]) || isset($out['light'][$n]) || isset($out['dark'][$n]);
        if (!$has('--brand-hover')) $out[$group]['--brand-hover'] = sprintf('#%02x%02x%02x', (int)($r * .88), (int)($g * .88), (int)($b * .88));
        if (!$has('--brand-soft')) {
            if ($group !== 'dark') $out['light']['--brand-soft'] = sprintf('rgba(%d,%d,%d,.12)', $r, $g, $b);
            if ($group !== 'light') $out['dark']['--brand-soft'] = sprintf('rgba(%d,%d,%d,.16)', $r, $g, $b);
        }
    }
    return $out;
}

/** CSS for grouped tokens. Light and dark follow the page's data-theme, and "auto" follows the visitor's system. */
function theme_tokens_css(array $tokens): string
{
    $decl = static function (array $set): string {
        $s = '';
        foreach ($set as $n => $v) $s .= $n . ':' . $v . ';';
        return $s;
    };
    $css = '';
    if (($all = $decl((array)($tokens['all'] ?? []))) !== '') $css .= ':root{' . $all . '}';
    if (($light = $decl((array)($tokens['light'] ?? []))) !== '') $css .= '[data-theme="light"]{' . $light . '}@media not all and (prefers-color-scheme: dark){[data-theme="auto"]{' . $light . '}}';
    if (($dark = $decl((array)($tokens['dark'] ?? []))) !== '') $css .= '[data-theme="dark"]{' . $dark . '}@media (prefers-color-scheme: dark){[data-theme="auto"]{' . $dark . '}}';
    return $css;
}

/** <style> with the active theme's tokens, printed right after app.css (layout.php). */
function theme_head(): string
{
    $id = theme_active();
    if ($id === '') return '';
    $css = theme_tokens_css(theme_tokens($id));
    return $css !== '' ? '<style id="theme-tokens">' . $css . '</style>' : '';
}

/* ---------------------------------------------------------------- stylesheet */

/** A theme's CSS: files from 'assets' => ['css' => [...]], and function sources only when $run_code (the enabled theme). */
function theme_css_source(string $id, bool $run_code): string
{
    $m = $run_code ? plugin_read_manifest($id) : plugin_peek($id);
    $css = '';
    foreach ((array)($m['assets']['css'] ?? []) as $src) {
        if (!is_string($src) || $src === '') continue;
        $code = '';
        if (str_contains($src, '.')) {
            $file = plugin_path($id, $src);
            if (str_ends_with(strtolower($src), '.css') && !str_contains($src, '..') && is_file($file)) $code = (string)file_get_contents($file);
        } elseif ($run_code && function_exists($src)) {
            $code = (string)$src();
        }
        if (trim($code) !== '') $css .= "\n/* " . $id . " */\n" . $code . "\n";
    }
    return $css;
}

/** Write data/cache/theme.css for the enabled theme (called with the plugin bundle). */
function theme_assets_build(): void
{
    $id = theme_enabled_id();
    $css = $id !== '' ? theme_css_source($id, true) : '';
    @file_put_contents(CACHE_DIR . '/theme.css', $css, LOCK_EX);
    save_settings(['theme_assets_hash' => substr(md5($id . $css), 0, 8)]);
}

/** The theme stylesheet, after the plugin bundle. A previewed theme that is not enabled gets its CSS files inline instead. */
function theme_assets_tag(): string
{
    $preview = theme_preview_id();
    if ($preview !== '' && $preview !== theme_enabled_id()) {
        $css = str_ireplace('</style', '', theme_css_source($preview, false));
        return trim($css) !== '' ? '<style id="theme-preview-css">' . $css . '</style>' : '';
    }
    $file = CACHE_DIR . '/theme.css';
    if (theme_enabled_id() === '' || !is_file($file) || filesize($file) === 0) return '';
    return '<link rel="stylesheet" href="' . h(url('/plugin-assets/theme', ['v' => setting('theme_assets_hash', '0')])) . '">';
}

/* ---------------------------------------------------------------- templates */

/** The theme's file for a template, or '' to use app/views. Admin pages always use the core templates, so a broken theme cannot lock anyone out. */
function theme_view_file(string $view): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $view) || str_starts_with(current_path() . '/', '/admin/')) return '';
    $id = theme_active();
    if ($id === '') return '';
    $file = plugin_path($id, 'views/' . $view . '.php');
    return is_file($file) ? $file : '';
}

/** A theme template threw: log it, remember it for Admin → Themes (once per message), and let view() render the core template. */
function theme_view_failed(string $view, string $file, Throwable $e): void
{
    $msg = cut(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($file) . ':' . $e->getLine() . ')', 300, '…');
    if (!IS_CLI) @error_log('[flatbb theme] ' . $view . ' ' . $msg);
    $id = theme_active();
    clearstatcache(true, $file);
    $mtime = (int)@filemtime($file);
    $seen = json_decode_array(setting('theme_view_errors', ''));
    if ((string)($seen[$id][$view]['msg'] ?? '') === $msg && (int)($seen[$id][$view]['mtime'] ?? 0) === $mtime) return;
    $seen = array_intersect_key($seen, [$id => true]);
    $seen[$id][$view] = ['msg' => $msg, 'mtime' => $mtime];
    save_settings(['theme_view_errors' => json_encode_value($seen)]);
}

/** Errors a theme's templates raised: [view => message]. An error disappears once its template file changes (fixed or updated). */
function theme_view_errors(string $id): array
{
    $out = [];
    foreach ((array)(json_decode_array(setting('theme_view_errors', ''))[$id] ?? []) as $view => $e) {
        $file = plugin_path($id, 'views/' . $view . '.php');
        if (!is_array($e) || !preg_match('/^[a-z0-9_]+$/', (string)$view) || !is_file($file)) continue;
        clearstatcache(true, $file);
        if ((int)@filemtime($file) === (int)($e['mtime'] ?? -1)) $out[(string)$view] = (string)($e['msg'] ?? '');
    }
    return $out;
}

/** First 12 hex digits of sha1 over a core template (line endings normalised): the stamp a theme's copy records. */
function theme_core_view_hash(string $view): string
{
    $file = VIEW_DIR . '/' . $view . '.php';
    if (!preg_match('/^[a-z0-9_]+$/', $view) || !is_file($file)) return '';
    return substr(sha1(str_replace("\r\n", "\n", (string)file_get_contents($file))), 0, 12);
}

/** ['view' => name, 'hash' => 12 hex] from the "flatbb-view: name@hash" stamp at the top of a theme template; [] when absent. */
function theme_view_stamp(string $file): array
{
    $head = (string)@file_get_contents($file, false, null, 0, 800);
    return preg_match('/flatbb-view:\s*([a-z0-9_]+)@([0-9a-f]{12})/', $head, $m) ? ['view' => $m[1], 'hash' => $m[2]] : [];
}

/**
 * The templates a theme replaces and how each compares with the core template it was copied from:
 * view => ok | outdated (the core template changed since) | unstamped (no stamp line) | unknown (no such core template).
 */
function theme_views(string $id): array
{
    $out = [];
    if (!plugin_id_valid($id)) return $out;
    foreach (glob(plugin_path($id, 'views') . '/*.php') ?: [] as $file) {
        $view = basename($file, '.php');
        $core = theme_core_view_hash($view);
        $stamp = theme_view_stamp($file);
        $out[$view] = $core === '' ? 'unknown' : ($stamp === [] ? 'unstamped' : ($stamp['hash'] === $core ? 'ok' : 'outdated'));
    }
    ksort($out);
    return $out;
}

/* ---------------------------------------------------------------- preview */

/** The bar on top of every page while an admin previews a theme: whose look it is, Activate, Exit. page() inserts it after <body>. */
function theme_preview_bar(): string
{
    $id = theme_preview_id();
    if ($id === '') return '';
    $t = themes()[$id];
    $enabled = theme_enabled_id() === $id;
    return '<div class="theme-preview-bar" role="status">' . icon('eye') . '<span>' . t('Previewing the theme %s. Only you see it.', '<b>' . h((string)$t['name']) . '</b>') . '</span>'
        . ($enabled ? '' : action_form(admin_url('themes'), '<button type="submit" class="btn btn-sm btn-primary">' . icon('check') . t('Activate') . '</button>', ['action' => 'activate', 'id' => $id], 'inline'))
        . action_form(admin_url('themes'), '<button type="submit" class="btn btn-sm">' . icon('x') . t('Exit preview') . '</button>', ['action' => 'preview_exit'], 'inline') . '</div>';
}

function theme_preview_inject(string $html): string
{
    $bar = theme_preview_bar();
    if ($bar === '') return $html;
    $out = preg_replace('/<body\b[^>]*>/i', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $bar), $html, 1);
    return is_string($out) ? $out : $html;
}

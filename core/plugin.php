<?php
/**
 * Plugin system.
 *
 * A plugin is plugins/<id>/plugin.php that returns a manifest array. See docs/PLUGIN.md.
 * Registration lives in fb_plugins; normal requests never scan the plugins directory.
 * Only enabled plugins are loaded. Loading = include the file, register hooks/routes/cron/lang.
 * Disabled plugins are never included: the admin page, the folder scan and the zip upload read their manifest as text (plugin_peek).
 */

function plugin_id_valid(string $id): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]{1,40}$/', $id);
}

function plugin_path(string $id, string $file = ''): string
{
    return PLUGIN_DIR . '/' . $id . ($file !== '' ? '/' . ltrim($file, '/') : '');
}

/** Public URL for a static file inside plugins/<id>/assets/. */
function plugin_url(string $id, string $file): string
{
    return base_path() . '/plugins/' . $id . '/' . ltrim($file, '/');
}

/** Registered plugins keyed by id (rows from fb_plugins with decoded JSON). */
function plugins(bool $refresh = false): array
{
    if ($refresh) request_cache('plugins', null, true);
    return request_cache('plugins', static function (): array {
        $out = [];
        foreach (all('SELECT * FROM fb_plugins ORDER BY sort,id') as $row) {
            $row['settings'] = json_decode_array($row['settings'] ?? '');
            $row['manifest'] = json_decode_array($row['manifest'] ?? '');
            $out[(string)$row['id']] = $row;
        }
        return $out;
    }) ?? [];
}

function plugin_enabled(string $id): bool
{
    return (int)(plugins()[$id]['enabled'] ?? 0) === 1;
}

/** Live manifests of loaded plugins (from plugin.php, not the DB snapshot). */
function plugin_manifests(?array $set = null): array
{
    static $m = [];
    if ($set !== null) $m = $set;
    return $m;
}

function plugin_manifest(string $id): ?array
{
    return plugin_manifests()[$id] ?? (plugin_enabled($id) ? plugin_read_manifest($id) : null); // disabled plugins are not executed to answer this
}

/**
 * Read id, name, version, description and author of plugins/<id>/plugin.php as text, without executing it.
 * Used for plugins that are not enabled: their code only runs once an admin enables them.
 */
function plugin_peek(string $id): ?array
{
    if (!plugin_id_valid($id)) return null;
    $file = plugin_path($id, 'plugin.php');
    if (!is_file($file)) return null;
    $m = plugin_manifest_parse((string)file_get_contents($file)); // the tokenizer reads the returned array; the file is never executed
    if ($m === null || (string)($m['id'] ?? '') !== $id) return null;
    return $m + ['name' => $id, 'version' => '0.0.0', 'description' => '', 'author' => '', 'requires' => [], 'hooks' => [], 'routes' => [], 'admin_pages' => [], 'cron' => [], 'settings' => []];
}

/** Include plugins/<id>/plugin.php once and return its manifest (null when invalid). Only call this for enabled plugins or on an explicit admin/CLI action. */
function plugin_read_manifest(string $id): ?array
{
    static $files = [];
    if (!plugin_id_valid($id)) return null;
    if (array_key_exists($id, $files)) return $files[$id];
    $file = plugin_path($id, 'plugin.php');
    if (!is_file($file)) return $files[$id] = null;
    $m = include $file;
    if (!is_array($m) || ($m['id'] ?? '') !== $id) return $files[$id] = null;
    $m += ['name' => $id, 'version' => '0.0.0', 'description' => '', 'author' => '', 'hooks' => [], 'routes' => [], 'settings' => [], 'assets' => [], 'admin_pages' => [], 'cron' => []];
    return $files[$id] = $m;
}

/** Load every enabled plugin and register what it declares. Called from app_boot(). */
function plugins_load(): void
{
    $loaded = [];
    foreach (plugins() as $id => $row) {
        if ((int)$row['enabled'] !== 1) continue;
        $m = plugin_read_manifest($id);
        if ($m === null) continue;
        $loaded[$id] = $m;
        foreach ((array)$m['hooks'] as $hook => $fn) {
            foreach ((array)$fn as $callback) hook_add($hook, $callback, $id);
        }
        foreach ((array)$m['routes'] as $pattern => $fn) router_add($pattern, (string)$fn);
        if (!empty($m['csrf_exempt'])) router_csrf_exempt_add((array)$m['csrf_exempt']);
        $lang = plugin_path($id, 'lang/' . lang_code() . '.php');
        if (lang_code() !== 'en' && is_file($lang)) lang_add((array)include $lang);
    }
    plugin_manifests($loaded);
}

/* ---------------------------------------------------------------- settings */

function plugin_settings_schema(string $id): array
{
    return (array)(plugin_manifest($id)['settings'] ?? []);
}

/** All settings for a plugin, defaults from the manifest merged with saved values. */
function plugin_settings(string $id): array
{
    $saved = plugins()[$id]['settings'] ?? [];
    $out = [];
    foreach (plugin_settings_schema($id) as $key => $def) $out[$key] = $saved[$key] ?? ($def['default'] ?? '');
    return $out + $saved;
}

function plugin_setting(string $id, string $key, mixed $default = null): mixed
{
    $all = plugin_settings($id);
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function plugin_save_settings(string $id, array $settings): void
{
    db_update('fb_plugins', ['settings' => json_encode_value($settings), 'updated_at' => now()], 'id=?', [$id]);
    plugins(true);
}

/** Validate submitted values against the manifest schema. Unknown keys are dropped. */
function plugin_settings_from_post(string $id, array $post): array
{
    $out = plugin_settings($id);
    foreach (plugin_settings_schema($id) as $key => $def) {
        $type = (string)($def['type'] ?? 'text');
        $raw = $post[$key] ?? null;
        $out[$key] = match ($type) {
            'checkbox', 'bool' => $raw ? 1 : 0,
            'number' => max((int)($def['min'] ?? PHP_INT_MIN), min((int)($def['max'] ?? PHP_INT_MAX), (int)$raw)),
            'select' => array_key_exists((string)$raw, (array)($def['options'] ?? [])) ? (string)$raw : (string)($def['default'] ?? ''),
            'textarea', 'html', 'code' => is_string($raw) ? cut(str_replace("\r\n", "\n", $raw), (int)($def['max'] ?? 65535), '') : '',
            default => is_string($raw) ? cut(trim($raw), (int)($def['max'] ?? 255), '') : '',
        };
    }
    return $out;
}

/** Render admin form fields from the manifest schema. */
function plugin_settings_form(string $id): string
{
    $values = plugin_settings($id);
    $html = '';
    foreach (plugin_settings_schema($id) as $key => $def) {
        $type = (string)($def['type'] ?? 'text');
        $label = (string)($def['label'] ?? ucfirst(str_replace('_', ' ', $key)));
        $help = isset($def['help']) ? '<div class="form-help">' . h((string)$def['help']) . '</div>' : '';
        $name = 'plugin_' . h($key);
        $v = $values[$key] ?? '';
        $field = match ($type) {
            'checkbox', 'bool' => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($v ? ' checked' : '') . '> ' . h($label) . '</label>',
            'textarea', 'html', 'code' => '<textarea name="' . $name . '" rows="' . (int)($def['rows'] ?? 5) . '"' . ($type === 'code' ? ' class="mono"' : '') . '>' . h((string)$v) . '</textarea>',
            'select' => '<select name="' . $name . '">' . implode('', array_map(static fn($k, $l): string => '<option value="' . h((string)$k) . '"' . ((string)$k === (string)$v ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys((array)($def['options'] ?? [])), (array)($def['options'] ?? []))) . '</select>',
            'number' => '<input type="number" name="' . $name . '" value="' . h((string)$v) . '"' . (isset($def['min']) ? ' min="' . (int)$def['min'] . '"' : '') . (isset($def['max']) ? ' max="' . (int)$def['max'] . '"' : '') . '>',
            'color' => '<input type="color" name="' . $name . '" value="' . h((string)$v ?: '#000000') . '">',
            default => '<input type="text" name="' . $name . '" value="' . h((string)$v) . '">',
        };
        $html .= in_array($type, ['checkbox', 'bool'], true)
            ? '<div class="form-row">' . $field . $help . '</div>'
            : '<div class="form-row"><label>' . h($label) . '</label>' . $field . $help . '</div>';
    }
    return $html;
}

/* ---------------------------------------------------------------- lifecycle */

/** Scan plugins/ and update fb_plugins. New plugins are registered disabled. */
function plugin_sync(): array
{
    $found = [];
    foreach (glob(PLUGIN_DIR . '/*/plugin.php') ?: [] as $file) {
        $id = basename(dirname($file));
        // disabled plugins are only peeked at (name, version): their code runs for the first time when an admin enables them
        $enabled = (int)(plugins()[$id]['enabled'] ?? 0) === 1;
        $m = $enabled ? plugin_read_manifest($id) : plugin_peek($id);
        if ($m === null) continue;
        $found[$id] = $m;
        $snapshot = array_intersect_key($m, array_flip(['name', 'version', 'description', 'author', 'url', 'requires', 'hooks', 'routes', 'admin_pages', 'cron', 'settings']));
        $existing = plugins()[$id] ?? null;
        // files changed underneath an installed plugin (scan, zip upload, core upgrade): run its install routine for the new version now,
        // because the version stored below is what plugin_enable() compares against later
        if ($enabled && $existing !== null && (int)($existing['installed'] ?? 0) === 1 && (string)$existing['version'] !== (string)$m['version'] && !empty($m['install']) && is_callable($m['install'])) {
            $m['install']($m);
        }
        $data = ['id' => $id, 'name' => (string)$m['name'], 'version' => (string)$m['version'], 'manifest' => json_encode_value($snapshot), 'updated_at' => now()];
        if ($existing === null) $data += ['enabled' => 0, 'installed' => 0, 'settings' => '{}', 'sort' => 0];
        db_upsert('fb_plugins', $data, ['id']);
    }
    foreach (plugins() as $id => $row) {
        if (!isset($found[$id])) db_delete('fb_plugins', 'id=?', [$id]);
    }
    plugins(true);
    plugin_assets_build();
    return $found;
}

function plugin_enable(string $id): void
{
    $m = plugin_read_manifest($id);
    if ($m === null) throw new RuntimeException('Plugin not found: ' . $id);
    $row = plugins()[$id] ?? null;
    if ($row === null) { plugin_sync(); $row = plugins()[$id] ?? []; }
    $need = version_compare((string)FLATBB_VERSION, (string)($m['requires']['flatbb'] ?? '0'), '>=');
    if (!$need) throw new RuntimeException('Plugin requires flatbb ' . $m['requires']['flatbb']);
    $install = $m['install'] ?? null;
    if ($install !== null && is_callable($install) && ((int)($row['installed'] ?? 0) !== 1 || ($row['version'] ?? '') !== $m['version'])) {
        $install($m);
    }
    db_update('fb_plugins', ['enabled' => 1, 'installed' => 1, 'version' => (string)$m['version'], 'updated_at' => now()], 'id=?', [$id]);
    plugins(true);
    plugin_assets_build();
}

function plugin_disable(string $id): void
{
    db_update('fb_plugins', ['enabled' => 0, 'updated_at' => now()], 'id=?', [$id]);
    plugins(true);
    plugin_assets_build();
}

/** Run the plugin's uninstall callback (drops its tables) and forget its settings. Files stay. */
function plugin_uninstall(string $id): void
{
    $m = plugin_read_manifest($id);
    $fn = $m['uninstall'] ?? null;
    if ($fn !== null && is_callable($fn)) $fn($m);
    db_update('fb_plugins', ['enabled' => 0, 'installed' => 0, 'settings' => '{}', 'updated_at' => now()], 'id=?', [$id]);
    plugins(true);
    plugin_assets_build();
}

/** Remove plugins/<id>/ from disk. $forget=false keeps the registry row (settings, enabled state) for a reinstall. */
function plugin_delete_files(string $id, bool $forget = true): void
{
    if (!plugin_id_valid($id)) return;
    $dir = plugin_path($id);
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
    if ($forget) db_delete('fb_plugins', 'id=?', [$id]);
    plugins(true);
}


/* ---------------------------------------------------------------- install from zip */

/**
 * Install (or replace) a plugin from a zip that contains exactly one top-level folder <id>/ with plugin.php.
 * Used by the admin upload form and by the marketplace client. Returns the plugin id; throws RuntimeException.
 */
function plugin_install_zip(string $file): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException(t('The zip PHP extension is required.'));
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException(t('Invalid zip file.'));
    $id = '';
    $has_manifest = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) { $zip->close(); throw new RuntimeException(t('Unsafe path in package: %s', $name)); }
        if (str_starts_with($name, '__MACOSX/') || str_ends_with($name, '.DS_Store')) continue;
        $top = explode('/', $name, 2)[0];
        if ($id === '') $id = $top;
        if ($top !== $id) { $zip->close(); throw new RuntimeException(t('The package must contain a single folder named after the plugin id.')); }
        if ($name === $id . '/plugin.php') $has_manifest = true;
        if (preg_match('/\.(exe|dll|so|sh|bat|phar)$/i', $name)) { $zip->close(); throw new RuntimeException(t('Executable files are not allowed: %s', $name)); }
    }
    if (!plugin_id_valid($id) || !$has_manifest) { $zip->close(); throw new RuntimeException(t('The package must contain <id>/plugin.php.')); }
    $src = (string)$zip->getFromName($id . '/plugin.php');
    if (!preg_match('/[\'"]id[\'"]\s*=>\s*[\'"]' . preg_quote($id, '/') . '[\'"]/', $src)) { $zip->close(); throw new RuntimeException(t('plugin.php does not declare id "%s".', $id)); }
    $was_enabled = plugin_enabled($id);
    if ($was_enabled) plugin_disable($id);
    if (is_dir(plugin_path($id))) plugin_delete_files($id, false); // keep settings and enabled state across the reinstall
    if (!$zip->extractTo(PLUGIN_DIR)) { $zip->close(); throw new RuntimeException(t('Could not write to plugins/.')); }
    $zip->close();
    foreach (glob(PLUGIN_DIR . '/__MACOSX') ?: [] as $junk) upgrade_rmdir($junk);
    plugin_sync();
    if (plugin_peek($id) === null) throw new RuntimeException(t('The installed files do not contain a valid manifest.'));
    if ($was_enabled) plugin_enable($id);
    fire('plugin.after_install_zip', ['id' => $id]);
    return $id;
}

/* ---------------------------------------------------------------- assets */

/**
 * Concatenate CSS/JS declared by enabled plugins into data/cache/plugins.css|js.
 * 'assets' => ['css' => 'my_css_function' | 'assets/style.css' | [..], 'js' => ...]
 */
function plugin_assets_build(): void
{
    $out = ['css' => '', 'js' => ''];
    foreach (plugins() as $id => $row) {
        if ((int)$row['enabled'] !== 1) continue;
        $m = plugin_read_manifest($id);
        if ($m === null) continue;
        foreach (['css', 'js'] as $type) {
            foreach ((array)($m['assets'][$type] ?? []) as $src) {
                $code = '';
                if (is_string($src) && function_exists($src)) $code = (string)$src();
                elseif (is_string($src) && is_file(plugin_path($id, $src))) $code = (string)file_get_contents(plugin_path($id, $src));
                if (trim($code) !== '') $out[$type] .= "\n/* {$id} */\n" . $code . "\n";
            }
        }
    }
    foreach ($out as $type => $code) @file_put_contents(CACHE_DIR . '/plugins.' . $type, $code, LOCK_EX);
    save_settings(['plugin_assets_hash' => substr(md5($out['css'] . $out['js']), 0, 8)]);
}

function plugin_assets_tag(string $type): string
{
    $file = CACHE_DIR . '/plugins.' . $type;
    if (!is_file($file) || filesize($file) === 0) return '';
    $u = h(url('/plugin-assets/' . $type, ['v' => setting('plugin_assets_hash', '0')]));
    return $type === 'css' ? '<link rel="stylesheet" href="' . $u . '">' : '<script src="' . $u . '" defer></script>';
}

function plugin_assets_serve(string $type): never
{
    $type = $type === 'js' ? 'js' : 'css';
    $file = CACHE_DIR . '/plugins.' . $type;
    header('Content-Type: ' . ($type === 'js' ? 'application/javascript' : 'text/css') . '; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    echo is_file($file) ? file_get_contents($file) : '';
    exit;
}

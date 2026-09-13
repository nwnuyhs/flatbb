<?php
/**
 * Theme developer tools, used by the CLI (php flatbb theme:new | theme:override | theme:check) and by plugin_check():
 * the rules a theme must pass, copying a core template into a theme with its stamp, and a starter theme.
 * Nothing here runs during normal page requests. Runtime theming lives in core/theme.php; the guide is docs/THEME.md.
 */

/** Every CSS variable assets/app.css defines or reads (component tokens exist only as var() fallbacks): the names a theme can set. */
function theme_known_tokens(): array
{
    return request_cache('theme_known_tokens', static function (): array {
        preg_match_all('/(--[a-z][a-z0-9-]*)\s*[:,)]/', (string)@file_get_contents(ROOT . '/assets/app.css'), $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }) ?? [];
}

/** The rules a theme passes on top of plugin_check(): [errors, warnings]. $m is the loaded manifest. */
function theme_check(string $id, array $m): array
{
    $errors = [];
    $warnings = [];
    $own = '--' . str_replace('_', '-', $id) . '-';
    $known = theme_known_tokens();
    foreach ((array)($m['tokens'] ?? []) as $group => $set) {
        if (!in_array($group, ['all', 'light', 'dark'], true)) { $errors[] = 'tokens: unknown group "' . $group . '" (use all, light or dark)'; continue; }
        if (!is_array($set)) { $errors[] = 'tokens.' . $group . ' must be an array of \'--variable\' => \'value\''; continue; }
        foreach ($set as $name => $value) {
            $n = theme_token_name((string)$name);
            if ($n === '') { $errors[] = 'tokens.' . $group . ': "' . $name . '" is not a CSS variable name'; continue; }
            if (!is_scalar($value) || theme_token_value((string)$value) === '') $errors[] = 'tokens.' . $group . '.' . $n . ': value refused (empty, over 300 characters, or contains ; { } < > \\ url( or a comment)';
            elseif (!in_array($n, $known, true) && !str_starts_with($n, $own)) $warnings[] = 'tokens.' . $group . '.' . $n . ' is not a core variable (a typo?); variables of your own start with ' . $own;
        }
    }
    foreach ((array)($m['settings'] ?? []) as $key => $def) {
        if (is_array($def) && isset($def['token']) && theme_token_name((string)$def['token']) === '') $errors[] = 'settings.' . $key . '.token "' . (string)$def['token'] . '" is not a CSS variable name';
    }
    foreach (theme_views($id) as $view => $state) {
        if ($state === 'unknown') $errors[] = 'views/' . $view . '.php replaces nothing: app/views/' . $view . '.php does not exist';
        elseif ($state === 'unstamped') $warnings[] = 'views/' . $view . '.php has no "flatbb-view:" stamp; create copies with: php flatbb theme:override ' . $id . ' ' . $view;
        elseif ($state === 'outdated') $warnings[] = 'views/' . $view . '.php was copied from an older app/views/' . $view . '.php: bring the changes over, then run php flatbb theme:override ' . $id . ' ' . $view . ' --stamp';
    }
    $shot = (string)($m['screenshot'] ?? '');
    if ($shot !== '' && theme_screenshot($id, ['screenshot' => $shot]) !== $shot) $errors[] = 'screenshot "' . $shot . '" not found (png, jpg or webp inside the theme folder)';
    elseif (theme_screenshot($id, $m) === '') $warnings[] = 'no screenshot: add screenshot.png (1200x800) to the theme folder; the marketplace requires one for themes';
    $css = theme_css_source($id, true);
    if (preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\(/i', $css) > 12) $warnings[] = 'the theme CSS hard-codes many colours; set tokens instead, so light and dark mode both follow';
    if (preg_match('/(?<![\w-])(?:(?:margin|padding)-(?:left|right)|left|right)\s*:|(?:text-align|float)\s*:\s*(?:left|right)/i', $css)) $warnings[] = 'physical left/right in the theme CSS: use logical properties (margin-inline-start, inset-inline-end, text-align: start) so right-to-left languages mirror';
    if (str_contains($css, '!important')) $warnings[] = '!important in the theme CSS: the theme stylesheet already loads after everything else';
    if (!empty($m['routes']) || !empty($m['cron']) || !empty($m['admin_pages'])) $warnings[] = 'a theme changes the look only: routes, admin pages and scheduled jobs belong in a separate plugin';
    return [$errors, $warnings];
}

/**
 * Copy app/views/<view>.php into plugins/<id>/views/ with a stamp line naming the core template and its hash.
 * $mode: '' (refuse to overwrite), 'force' (copy again, the old copy is kept as <view>.php.bak), 'stamp' (keep the copy, record today's core hash
 * after you brought the core changes over). Returns the file path.
 */
function theme_override(string $id, string $view, string $mode = ''): string
{
    if (!plugin_id_valid($id) || !is_file(plugin_path($id, 'plugin.php'))) throw new RuntimeException('No theme at plugins/' . $id . '/plugin.php (create one with php flatbb theme:new ' . $id . ')');
    $hash = theme_core_view_hash($view);
    if ($hash === '') throw new RuntimeException('No core template app/views/' . $view . '.php. Templates: ' . implode(', ', array_map(static fn(string $f): string => basename($f, '.php'), glob(VIEW_DIR . '/*.php') ?: [])));
    $dir = plugin_path($id, 'views');
    $file = $dir . '/' . $view . '.php';
    $stamp = '<?php /* flatbb-view: ' . $view . '@' . $hash . ' (copied from app/views/' . $view . '.php; php flatbb theme:check ' . $id . ' tells when the core template changes) */ ?>' . "\n";
    if ($mode === 'stamp') {
        if (!is_file($file)) throw new RuntimeException($file . ' does not exist yet; run without --stamp to copy it');
        $body = (string)file_get_contents($file);
        $body = (string)preg_replace('/\A<\?php \/\* flatbb-view:[^\n]*\*\/ \?>\n/', '', $body);
        file_put_contents($file, $stamp . $body);
        return $file;
    }
    if (is_file($file) && $mode !== 'force') throw new RuntimeException($file . ' exists. --stamp records the current core template after you merged its changes; --force copies it again (your copy is kept as ' . $view . '.php.bak)');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_file($file)) @copy($file, $file . '.bak');
    file_put_contents($file, $stamp . str_replace("\r\n", "\n", (string)file_get_contents(VIEW_DIR . '/' . $view . '.php')));
    return $file;
}

/** Create plugins/<id>/ with a working starter theme (tokens, a colour setting, an empty stylesheet, a README). Returns the files written. */
function theme_scaffold(string $id, string $name = ''): array
{
    if (!plugin_id_valid($id)) throw new RuntimeException('Invalid theme id "' . $id . '": lowercase letters, digits and underscores, starting with a letter');
    if (is_dir(plugin_path($id))) throw new RuntimeException('plugins/' . $id . ' already exists');
    $name = trim($name) !== '' ? trim($name) : ucwords(str_replace('_', ' ', $id));
    $q = static fn(string $s): string => var_export($s, true);
    $files = [
        'plugin.php' => "<?php\n/**\n * " . $name . " — a FlatBB theme.\n * tokens change colours, shapes and type; assets/theme.css restyles components; views/ holds the core templates this theme\n * changes (copy one with: php flatbb theme:override " . $id . " <template>). Every variable and rule: docs/THEME.md\n */\nif (!defined('FLATBB')) exit;\n\nreturn [\n"
            . "    'id' => " . $q($id) . ",\n    'type' => 'theme',\n    'name' => " . $q($name) . ",\n    'version' => '1.0.0',\n    'description' => 'One sentence about the look.',\n    'author' => 'you',\n    'requires' => ['flatbb' => " . $q(FLATBB_VERSION) . "],\n"
            . "    'tokens' => [\n        'all' => ['--radius' => '12px', '--radius-sm' => '8px'],\n        'light' => ['--bg' => '#f5f6f8', '--panel' => '#ffffff', '--line' => '#e6e8ec'],\n        'dark' => ['--bg' => '#111418', '--panel' => '#191d23', '--line' => '#2a3038'],\n    ],\n"
            . "    'settings' => [\n        'accent' => ['type' => 'color', 'label' => 'Accent colour', 'default' => '#e7672e', 'token' => '--brand'],\n    ],\n"
            . "    'assets' => ['css' => ['assets/theme.css']],\n];\n",
        'assets/theme.css' => "/* " . $name . " — component styles. Loaded after app.css and every plugin's CSS.\n * Prefer tokens (plugin.php) over rules here; use CSS variables for colours and logical properties (margin-inline-start) for sides.\n * Example: .topic-row:hover { background: var(--panel-2); }\n */\n",
        'README.md' => "# " . $name . "\n\nA FlatBB theme. Install: zip the `" . $id . "` folder and upload it under Admin → Appearance → Themes, then Preview or Activate.\n\n## Settings\n\n- **Accent colour**: the brand colour of buttons, links and highlights.\n",
    ];
    $written = [];
    foreach ($files as $rel => $body) {
        $path = plugin_path($id, $rel);
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $body);
        $written[] = 'plugins/' . $id . '/' . $rel;
    }
    return $written;
}

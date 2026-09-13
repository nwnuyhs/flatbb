<?php
/** Themes: tokens, one theme at a time, template overrides with their stamps and fallback, theme checks, preview. Run with: php flatbb test theme_test.php */

/** A throwaway theme in plugins/<id>/ ($extra is appended to the manifest array, $files are written inside the folder). */
function theme_fixture(string $id, string $extra = '', array $files = []): void
{
    $dir = PLUGIN_DIR . '/' . $id;
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/plugin.php', "<?php\nif (!defined('FLATBB')) exit;\nreturn ['id' => '" . $id . "', 'type' => 'theme', 'name' => 'Test " . $id . "', 'version' => '1.0.0', 'description' => 'test only', 'author' => 'tests', 'requires' => ['flatbb' => '0.1.0']" . $extra . "];\n");
    foreach ($files as $rel => $body) {
        @mkdir(dirname($dir . '/' . $rel), 0755, true);
        file_put_contents($dir . '/' . $rel, $body);
    }
}

function theme_fixture_remove(string ...$ids): void
{
    foreach ($ids as $id) {
        plugin_delete_files($id);
        db_delete('fb_plugins', 'id=?', [$id]);
    }
    plugins(true);
    save_settings(['theme_view_errors' => '']);
    plugin_assets_build();
}

function test_theme_tokens_are_validated_and_grouped(): void
{
    test_same('--brand', theme_token_name('brand'));
    test_same('--card-bg', theme_token_name('--card-bg'));
    test_same('', theme_token_name('--bad name'));
    test_same('#fff', theme_token_value(' #fff '));
    test_same('"Noto Serif", Georgia, serif', theme_token_value('"Noto Serif", Georgia, serif'));
    foreach (['red;color:blue', 'x}body{', '</style><script>', 'url(https://example.com/a.png)', 'a/*b*/', 'expression(alert(1))', 'a\\62', ''] as $bad) {
        test_same('', theme_token_value($bad), 'refused: ' . $bad);
    }
    $css = theme_tokens_css(['all' => ['--radius' => '6px'], 'light' => ['--bg' => '#fff'], 'dark' => ['--bg' => '#000']]);
    test_contains(':root{--radius:6px;}', $css);
    test_contains('[data-theme="light"]{--bg:#fff;}', $css);
    test_contains('@media not all and (prefers-color-scheme: dark){[data-theme="auto"]{--bg:#fff;}}', $css);
    test_contains('[data-theme="dark"]{--bg:#000;}', $css);
    test_contains('@media (prefers-color-scheme: dark){[data-theme="auto"]{--bg:#000;}}', $css);
    test_same('', theme_tokens_css(['all' => [], 'light' => [], 'dark' => []]));
}

function test_theme_switching_tokens_settings_and_template_override(): void
{
    $a = 'zz_theme_a';
    $b = 'zz_theme_b';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $vars = ['title' => 'T', 'message' => 'hello'];
    try {
        theme_fixture($a, ", 'tokens' => ['all' => ['--brand' => '#336699', '--radius' => '4px'], 'dark' => ['--bg' => '#010203']],"
            . " 'settings' => ['round' => ['type' => 'number', 'label' => 'Corners', 'default' => 9, 'token' => '--radius-sm', 'unit' => 'px'], 'dense' => ['type' => 'checkbox', 'label' => 'Dense rows', 'default' => 1, 'token' => '--row-pad', 'values' => ['1' => '6px 12px', '0' => '']]],"
            . " 'assets' => ['css' => ['assets/theme.css']]",
            ['views/error.php' => '<p class="zz-themed"><?= h($message) ?></p>', 'assets/theme.css' => '.zz-theme-a{color:var(--brand)}']);
        theme_fixture($b);
        plugin_sync();
        test_assert(isset(themes()[$a], themes()[$b]), 'both themes are registered');
        test_same('', theme_enabled_id(), 'a scanned theme stays off');
        test_not_contains('zz-themed', view('error', $vars), 'core template while no theme is on');

        plugin_enable($a);
        test_same($a, theme_enabled_id());
        test_contains('<p class="zz-themed">hello</p>', view('error', $vars), 'the theme template renders');
        $tokens = theme_tokens($a);
        test_same('#336699', $tokens['all']['--brand'] ?? null);
        test_same('#2c5986', $tokens['all']['--brand-hover'] ?? null, 'hover shade derived from the brand');
        test_same('rgba(51,102,153,.16)', $tokens['dark']['--brand-soft'] ?? null, 'dark soft shade derived');
        test_same('9px', $tokens['all']['--radius-sm'] ?? null, 'number setting default with its unit');
        test_same('6px 12px', $tokens['all']['--row-pad'] ?? null, 'checkbox setting mapped through values');
        test_contains('--brand:#336699;', theme_head());
        plugin_save_settings($a, ['round' => 3, 'dense' => 0]);
        $tokens = theme_tokens($a);
        test_same('3px', $tokens['all']['--radius-sm'] ?? null, 'the saved setting wins over the default');
        test_assert(!isset($tokens['all']['--row-pad']), 'a value mapped to nothing sets no token');
        test_contains('.zz-theme-a', (string)file_get_contents(CACHE_DIR . '/theme.css'), 'theme CSS is its own file');
        test_not_contains('.zz-theme-a', (string)@file_get_contents(CACHE_DIR . '/plugins.css'), 'and stays out of the plugin bundle');

        $_SERVER['REQUEST_URI'] = '/admin/themes';
        request_cache('current_path', null, true);
        test_not_contains('zz-themed', view('error', $vars), 'admin pages keep the core templates');
        $_SERVER['REQUEST_URI'] = $uri;
        request_cache('current_path', null, true);

        plugin_enable($b);
        test_same($b, theme_enabled_id(), 'switching another theme on');
        test_same(0, (int)val('SELECT enabled FROM fb_plugins WHERE id=?', [$a]), 'switches the previous one off');
        test_not_contains('zz-themed', view('error', $vars), 'the new theme has no template of its own');
        test_same('', trim((string)file_get_contents(CACHE_DIR . '/theme.css')), 'nor a stylesheet');
        plugin_disable($b);
        test_same('', theme_enabled_id(), 'no theme on: the default look');
        test_same('', theme_head());
    } finally {
        $_SERVER['REQUEST_URI'] = $uri;
        request_cache('current_path', null, true);
        theme_fixture_remove($a, $b);
    }
}

function test_theme_template_that_fails_falls_back_to_core(): void
{
    $id = 'zz_theme_broken';
    try {
        theme_fixture($id, '', ['views/error.php' => "<p>half<?php throw new RuntimeException('broken on purpose'); ?>"]);
        plugin_sync();
        plugin_enable($id);
        $level = ob_get_level();
        $html = view('error', ['title' => 'T', 'message' => 'still here']);
        test_same($level, ob_get_level(), 'output buffers are unwound');
        test_contains('still here', $html, 'the core template rendered instead');
        test_not_contains('half', $html);
        test_contains('broken on purpose', (string)(theme_view_errors($id)['error'] ?? ''), 'the error is kept for Admin → Themes');
        file_put_contents(PLUGIN_DIR . '/' . $id . '/views/error.php', '<p><?= h($no_such_variable) ?></p>'); // written for another core version
        test_contains('still here', view('error', ['title' => 'T', 'message' => 'still here']), 'an undefined variable falls back too');
    } finally {
        theme_fixture_remove($id);
    }
}

function test_theme_override_stamps_and_outdated_templates(): void
{
    $id = 'zz_theme_stamp';
    try {
        theme_fixture($id);
        $file = theme_override($id, 'error');
        test_same(['view' => 'error', 'hash' => theme_core_view_hash('error')], theme_view_stamp($file));
        test_same(['error' => 'ok'], theme_views($id));
        $refused = false;
        try { theme_override($id, 'error'); } catch (RuntimeException) { $refused = true; }
        test_assert($refused, 'an existing copy is not overwritten without --force');
        file_put_contents($file, str_replace(theme_core_view_hash('error'), '000000000000', (string)file_get_contents($file)));
        test_same(['error' => 'outdated'], theme_views($id));
        theme_override($id, 'error', 'stamp');
        test_same(['error' => 'ok'], theme_views($id), '--stamp records the current core template');
        test_same(1, substr_count((string)file_get_contents($file), 'flatbb-view:'), 'still one stamp line');
        theme_override($id, 'error', 'force');
        test_assert(is_file($file . '.bak'), '--force keeps the previous copy');
        file_put_contents(PLUGIN_DIR . '/' . $id . '/views/login.php', '<p></p>');
        file_put_contents(PLUGIN_DIR . '/' . $id . '/views/no_such_view.php', '<p></p>');
        test_same(['error' => 'ok', 'login' => 'unstamped', 'no_such_view' => 'unknown'], theme_views($id));
    } finally {
        theme_fixture_remove($id);
    }
}

function test_theme_check_and_scaffold(): void
{
    $bad = 'zz_theme_check';
    $new = 'zz_theme_new';
    try {
        theme_fixture($bad, ", 'tokens' => ['all' => ['--brand' => 'red;}', '--brnad' => '#fff'], 'night' => ['--bg' => '#000']]", ['views/no_such_view.php' => '<p></p>']);
        $r = plugin_check($bad);
        $errors = implode("\n", $r['errors']);
        $warnings = implode("\n", $r['warnings']);
        test_contains('--brand: value refused', $errors);
        test_contains('unknown group "night"', $errors);
        test_contains('replaces nothing', $errors);
        test_contains('--brnad is not a core variable', $warnings);
        test_contains('no screenshot', $warnings);

        test_same(3, count(theme_scaffold($new, 'Brand New')));
        $r = plugin_check($new);
        test_same([], $r['errors'], 'the starter theme passes the check');
        test_assert(plugin_is_theme($r['manifest']), 'and is a theme');
    } finally {
        theme_fixture_remove($bad, $new);
    }
}

function test_theme_preview_needs_an_admin(): void
{
    $id = 'zz_theme_peek';
    try {
        theme_fixture($id, ", 'tokens' => ['all' => ['--radius' => '2px']]");
        plugin_sync();
        cookie_set_for_test(THEME_PREVIEW_COOKIE, $id);
        request_cache('theme_preview', null, true);
        test_same('', theme_preview_id(), 'a visitor holding the cookie previews nothing');
        test_same('<body class="x"><p>', theme_preview_inject('<body class="x"><p>'), 'and gets no bar');
        test_same('', theme_head(), 'nor the theme tokens');
    } finally {
        cookie_set_for_test(THEME_PREVIEW_COOKIE, null);
        request_cache('theme_preview', null, true);
        theme_fixture_remove($id);
    }
}

function cookie_set_for_test(string $name, ?string $value): void
{
    if ($value === null) unset($GLOBALS['_COOKIE'][$name]);
    else $GLOBALS['_COOKIE'][$name] = $value;
}

<?php
/**
 * Admin → Appearance → Themes. The installed themes as cards (the default look first): switch one on, preview it in your own
 * browser, customise it (its settings), upload a theme zip, remove one. Plugins add tabs through region admin.themes.tabs (the
 * Plugin Market adds the marketplace's themes). A theme is a plugin with 'type' => 'theme': core/theme.php, docs/THEME.md.
 */

/** GET|POST /admin/themes[?settings=<id>|upload=1] */
function admin_page_themes(): never
{
    $back = admin_url('themes');
    if (is_post()) admin_themes_post($back);
    $all = themes();
    $on = theme_enabled_id();
    $cards = admin_theme_card_default($on === '');
    foreach ($all as $id => $t) $cards .= admin_theme_card((string)$id, $t, $on === $id);
    $tabs = region_list('admin.themes.tabs', ['installed' => ['label' => t('Installed'), 'url' => $back, 'active' => true, 'badge' => count($all) ?: '', 'weight' => 0]], ['active' => 'installed']);
    $html = tabs($tabs) . '<div style="height:12px"></div><div class="theme-grid">' . $cards . '</div>'
        . '<p class="muted small theme-foot">' . t('A theme changes colours, shapes and templates; features stay in plugins. Preview shows a theme to you alone before everyone gets it.') . '</p>';
    $drawer = null;
    if (get_int('upload', 0) === 1) {
        $body = '<form method="post" action="' . h($back) . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="upload">'
            . '<p class="muted">' . t('A theme package is a zip with one folder named after the theme id, plugin.php inside declaring \'type\' => \'theme\'. Uploading a newer version keeps its settings; a new theme stays off until you preview or activate it.') . '</p>'
            . form_row(t('Theme zip'), input('zip', '', ['type' => 'file', 'accept' => '.zip', 'required' => true]))
            . admin_form_actions(t('Install'), $back) . '</form>';
        $drawer = ['title' => t('Upload theme'), 'sub' => t('Install from a zip file'), 'body' => $body, 'back' => $back];
    }
    $sid = get_str('settings', 40);
    if ($sid !== '' && $sid === $on && ($m = plugin_manifest($sid)) !== null) {
        $form = plugin_settings_form($sid);
        $body = $form !== ''
            ? '<form method="post" action="' . h($back) . '">' . csrf_field() . '<input type="hidden" name="action" value="settings"><input type="hidden" name="id" value="' . h($sid) . '">' . $form . admin_form_actions(t('Save'), $back) . '</form>'
            : '<p class="muted">' . t('This theme has no settings.') . '</p>';
        $drawer = ['title' => t('Customize %s', (string)$m['name']), 'sub' => 'v' . $m['version'] . (!empty($m['author']) ? ' · ' . $m['author'] : ''), 'body' => $body, 'back' => $back];
    }
    $action = admin_drawer_link(admin_url('themes', ['upload' => 1]), t('Upload theme'), 'btn btn-primary', 'upload')
        . action_form($back, '<button class="btn" type="submit">' . icon('refresh') . t('Scan plugins folder') . '</button>', ['action' => 'sync'], 'inline');
    admin_page(t('Themes'), $html, 'themes', ['action' => '<div class="btn-row">' . $action . '</div>', 'drawer' => $drawer]);
}

/** Every POST of the page (the preview bar's buttons post here too). */
function admin_themes_post(string $back): never
{
    $id = post_str('id', 40);
    $known = static fn(string $id): bool => $id !== '' && isset(themes()[$id]);
    try {
        switch (post_str('action', 20)) {
            case 'activate':
                if (!$known($id)) fail(t('No such theme.'), $back);
                plugin_enable($id);
                if (theme_preview_id() !== '') app_cookie(THEME_PREVIEW_COOKIE, '', now() - 3600);
                admin_log('theme.activate', $id);
                flash(t('%s is now the theme of the site.', (string)themes()[$id]['name']));
                break;
            case 'default':
                if (($on = theme_enabled_id()) !== '') { plugin_disable($on); admin_log('theme.default', $on); }
                flash(t('The default look is back.'));
                break;
            case 'preview':
                if (!$known($id)) fail(t('No such theme.'), $back);
                app_cookie(THEME_PREVIEW_COOKIE, $id, now() + 7200);
                redirect(url('/'));
            case 'preview_exit':
                app_cookie(THEME_PREVIEW_COOKIE, '', now() - 3600);
                flash(t('Preview closed.'));
                break;
            case 'settings':
                if ($id !== theme_enabled_id()) fail(t('Only the active theme can be customised.'), $back);
                $post = [];
                foreach ($_POST as $k => $v) if (str_starts_with((string)$k, 'plugin_')) $post[substr((string)$k, 7)] = $v;
                plugin_save_settings($id, plugin_settings_from_post($id, $post));
                plugin_assets_build(); // a stylesheet function may read the settings
                fire('plugin.settings_saved', ['id' => $id]);
                admin_log('theme.settings', $id);
                flash(t('Settings saved.'));
                break;
            case 'upload':
                $f = $_FILES['zip'] ?? null;
                $retry = admin_url('themes', ['upload' => 1]);
                if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail(t('Choose a theme zip file.'), $retry);
                if ((int)$f['size'] > 20 * 1048576) fail(t('The package is larger than 20 MB.'), $retry);
                if (!plugin_is_theme(admin_theme_zip_manifest((string)$f['tmp_name']))) fail(t('This zip is not a theme: its plugin.php does not declare \'type\' => \'theme\'. Plugins are installed under Plugins.'), $retry);
                $pid = plugin_install_zip((string)$f['tmp_name']);
                admin_log('theme.upload', $pid, (string)$f['name']);
                flash(!empty(themes()[$pid]['enabled']) ? t('%s updated.', $pid) : t('%s installed. Preview it, or activate it for everyone.', $pid));
                break;
            case 'delete':
                if (!$known($id)) fail(t('No such theme.'), $back);
                if ($id === theme_enabled_id()) fail(t('Switch to another theme before removing this one.'), $back);
                if (theme_preview_id() === $id) app_cookie(THEME_PREVIEW_COOKIE, '', now() - 3600);
                plugin_delete_files($id);
                admin_log('theme.delete', $id);
                flash(t('Theme removed.'));
                break;
            case 'sync':
                plugin_sync();
                flash(t('%d themes installed.', count(themes())));
                break;
            default:
                fail(t('Unknown action.'), $back);
        }
    } catch (Throwable $e) {
        fail(t('Theme error: %s', $e->getMessage()), $back);
    }
    redirect($back);
}

/** The manifest of plugin.php inside an uploaded zip, read as text (the file is not executed); null when there is none. */
function admin_theme_zip_manifest(string $file): ?array
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return null;
    $m = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (preg_match('#^[a-z][a-z0-9_]{1,40}/plugin\.php$#', str_replace('\\', '/', (string)$zip->getNameIndex($i)))) { $m = plugin_manifest_parse((string)$zip->getFromIndex($i)); break; }
    }
    $zip->close();
    return $m;
}

/** The built-in look, always first. */
function admin_theme_card_default(bool $on): string
{
    $ops = $on ? '<span class="flag flag-success">' . icon('check') . t('Active') . '</span>'
        : action_form(admin_url('themes'), '<button type="submit" class="btn btn-sm">' . t('Use the default look') . '</button>', ['action' => 'default'], 'inline');
    return '<div class="theme-card' . ($on ? ' is-active' : '') . '"><div class="theme-shot">' . admin_theme_swatch([]) . '</div><div class="theme-body">'
        . '<h3>' . t('FlatBB Default') . '</h3><div class="muted small">' . t('Built in') . '</div>'
        . '<p class="theme-desc">' . t('The built-in look. The brand colour comes from Settings → General.') . '</p><div class="theme-ops">' . $ops . '</div></div></div>';
}

function admin_theme_card(string $id, array $t, bool $on): string
{
    $back = admin_url('themes');
    $shot = theme_screenshot($id, $t);
    $img = $shot !== '' ? '<img src="' . h(plugin_url($id, $shot) . '?v=' . rawurlencode((string)$t['version'])) . '" alt="" loading="lazy">' : admin_theme_swatch(theme_tokens($id, $t));
    $missing = !is_file(plugin_path($id, 'plugin.php'));
    $notes = '';
    if ($missing) $notes .= '<div class="theme-warn">' . icon('alert') . t('The files of this theme are missing.') . '</div>';
    $review = array_keys(array_filter(theme_views($id), static fn(string $s): bool => $s !== 'ok'));
    if ($review !== []) $notes .= '<div class="theme-warn" title="' . h(t('The core changed these templates after the theme copied them. The theme still works; its author should update it.')) . '">' . icon('alert') . t('Templates older than the core: %s', h(implode(', ', $review))) . '</div>';
    if ($on) foreach (theme_view_errors($id) as $view => $msg) $notes .= '<div class="theme-warn theme-error">' . icon('alert') . t('Template %s failed, the default one is shown: %s', h((string)$view), h((string)$msg)) . '</div>';
    $ops = [];
    if ($on) {
        $ops[] = '<span class="flag flag-success">' . icon('check') . t('Active') . '</span>';
        if (!empty($t['settings'])) $ops[] = admin_drawer_link(admin_url('themes', ['settings' => $id]), t('Customize'), 'btn btn-sm', 'edit');
    } elseif (!$missing) {
        $ops[] = action_form($back, '<button type="submit" class="btn btn-sm btn-primary">' . icon('check') . t('Activate') . '</button>', ['action' => 'activate', 'id' => $id], 'inline', t('Switch the whole site to %s?', (string)$t['name']));
        $ops[] = action_form($back, '<button type="submit" class="btn btn-sm">' . icon('eye') . t('Preview') . '</button>', ['action' => 'preview', 'id' => $id], 'inline');
    }
    if (!$on) $ops[] = admin_row_menu([action_form($back, '<button type="submit" class="danger">' . icon('trash') . t('Remove theme') . '</button>', ['action' => 'delete', 'id' => $id], '', t('Delete plugins/%s from disk?', $id))]);
    return '<div class="theme-card' . ($on ? ' is-active' : '') . '"><div class="theme-shot">' . $img . '</div><div class="theme-body">'
        . '<h3>' . h((string)$t['name']) . '</h3><div class="muted small">v' . h((string)$t['version']) . (!empty($t['author']) ? ' · ' . h((string)$t['author']) : '') . ' · <span class="mono">' . h($id) . '</span></div>'
        . '<p class="theme-desc">' . h((string)($t['description'] ?? '')) . '</p>' . $notes . '<div class="theme-ops">' . implode('', $ops) . '</div></div></div>';
}

/** A small drawing of a page in a theme's colours, for themes without a screenshot. [] = the default look. */
function admin_theme_swatch(array $tokens): string
{
    $v = (array)($tokens['light'] ?? []) + (array)($tokens['all'] ?? []);
    $style = '';
    foreach (['brand' => '#e7672e', 'bg' => '#f5f6f8', 'panel' => '#ffffff', 'line' => '#e6e8ec', 'text' => '#1f2328', 'radius' => '12px'] as $n => $default) {
        $style .= '--sw-' . $n . ':' . ($v['--' . $n] ?? $default) . ';';
    }
    return '<div class="theme-swatch" style="' . h($style) . '" aria-hidden="true"><i class="sw-top"><b></b></i><i class="sw-side"><s></s><s></s><s></s></i><i class="sw-main"><i class="sw-card"><b></b><s></s><s></s></i><i class="sw-card"><b></b><s></s></i></i></div>';
}

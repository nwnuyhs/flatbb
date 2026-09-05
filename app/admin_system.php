<?php
/**
 * Admin: plugins, layout (regions + custom HTML blocks), scheduled jobs, tools.
 */

function admin_page_plugins(): never
{
    $list_url = admin_url('plugins');
    if (is_post()) {
        check_csrf();
        $action = post_str('action', 20);
        $id = post_str('id', 40);
        try {
            switch ($action) {
                case 'sync': $n = count(plugin_sync()); flash(t('%d plugins registered.', $n)); break;
                case 'upload':
                    $f = $_FILES['zip'] ?? null;
                    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail(t('Choose a plugin zip file.'), admin_url('plugins', ['upload' => 1]));
                    if ((int)$f['size'] > 20 * 1048576) fail(t('The package is larger than 20 MB.'), admin_url('plugins', ['upload' => 1]));
                    $pid = plugin_install_zip((string)$f['tmp_name']);
                    flash(t('%s installed. Enable it when you are ready.', $pid));
                    break;
                case 'enable': plugin_enable($id); flash(t('Plugin enabled.')); break;
                case 'disable': plugin_disable($id); flash(t('Plugin disabled.')); break;
                case 'uninstall': plugin_uninstall($id); flash(t('Plugin uninstalled. Its files are still in plugins/%s.', $id)); break;
                case 'delete': plugin_uninstall($id); plugin_delete_files($id); flash(t('Plugin removed.')); break;
                case 'settings':
                    $post = [];
                    foreach ($_POST as $k => $v) if (str_starts_with((string)$k, 'plugin_')) $post[substr((string)$k, 7)] = $v;
                    plugin_save_settings($id, plugin_settings_from_post($id, $post));
                    fire('plugin.settings_saved', ['id' => $id]);
                    flash(t('Settings saved.'));
                    redirect(admin_url('plugins', ['settings' => $id]));
                default: fail(t('Unknown action.'));
            }
        } catch (Throwable $e) {
            fail(t('Plugin error: %s', $e->getMessage()), $list_url);
        }
        redirect($list_url);
    }
    $rows = [];
    foreach (plugins() as $id => $p) {
        $m = $p['manifest'];
        $enabled = (int)$p['enabled'] === 1;
        $live = $enabled ? plugin_read_manifest($id) : plugin_peek($id); // a disabled plugin's code never runs, not even to read its manifest
        $has_settings = $live !== null && (!empty($live['settings']) || !empty($live['admin_pages']));
        $update = $live !== null && version_compare((string)$live['version'], (string)$p['version'], '>') ? ' <span class="flag">' . t('%s on enable', $live['version']) . '</span>' : '';
        $menu = [];
        $extra = (string)hook('admin.plugin_ops', '', ['plugin' => $p]);
        if ($extra !== '') $menu[] = $extra;
        if ((int)$p['installed'] === 1 && !$enabled) $menu[] = action_form($list_url, '<button type="submit" class="danger">' . icon('trash') . t('Uninstall (drop data)') . '</button>', ['action' => 'uninstall', 'id' => $id], '', t('Run the uninstall routine of %s? Its tables and data are removed.', $id));
        if (!$enabled) $menu[] = action_form($list_url, '<button type="submit" class="danger">' . icon('x') . t('Remove files') . '</button>', ['action' => 'delete', 'id' => $id], '', t('Delete plugins/%s from disk?', $id));
        $rows[] = [
            '<b>' . h($p['name']) . '</b>' . $update . ($live === null ? ' <span class="flag flag-danger">' . t('file missing') . '</span>' : '') . '<br><small class="muted">' . h((string)($m['description'] ?? '')) . '</small>',
            '<span class="mono small">' . h($id) . '</span><br><small class="muted">v' . h((string)$p['version']) . (!empty($m['author']) ? ' · ' . h((string)$m['author']) : '') . '</small>',
            $live === null ? '' : admin_switch($list_url, ['action' => $enabled ? 'disable' : 'enable', 'id' => $id], $enabled, $enabled ? t('Disable') : t('Enable')),
            '<div class="row-actions">' . ($has_settings && $enabled ? admin_drawer_link(admin_url('plugins', ['settings' => $id]), t('Settings')) : '') . admin_row_menu($menu) . '</div>',
        ];
    }
    $tabs = ['installed' => ['label' => t('Installed'), 'url' => $list_url, 'active' => true, 'badge' => count($rows) ?: '']];
    if (plugin_enabled('market')) $tabs['market'] = ['label' => t('Market'), 'url' => url('/admin/ext/market/market')];
    $html = tabs($tabs) . '<div style="height:12px"></div>' . admin_table([t('Plugin'), t('ID / version'), t('Enabled'), ''], $rows, t('No plugins registered yet. Put a plugin in plugins/<id>/plugin.php and click "Scan plugins folder".'));
    $drawer = null;
    if (get_int('upload', 0) === 1) {
        $body = '<form method="post" action="' . h($list_url) . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="upload">'
            . '<p class="muted">' . t('A plugin package is a zip containing one folder named after the plugin id with plugin.php inside, the same layout as the plugins/ directory. Installing a newer version of an existing plugin keeps its settings.') . '</p>'
            . form_row(t('Plugin zip'), input('zip', '', ['type' => 'file', 'accept' => '.zip', 'required' => true]))
            . admin_form_actions(t('Install'), $list_url) . '</form>';
        $drawer = ['title' => t('Upload plugin'), 'sub' => t('Install from a zip file'), 'body' => $body, 'back' => $list_url];
    }
    $sid = get_str('settings', 40);
    if ($sid !== '' && ($m = plugin_manifest($sid)) !== null) {
        $form = plugin_settings_form($sid);
        $links = [];
        foreach ((array)($m['admin_pages'] ?? []) as $key => $def) {
            $label = is_array($def) ? (string)($def['label'] ?? $key) : (string)$key;
            if ($label !== '') $links[$label] = url('/admin/ext/' . $sid . '/' . $key);
        }
        $body = $form !== ''
            ? '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="action" value="settings"><input type="hidden" name="id" value="' . h($sid) . '">' . $form . admin_form_actions(t('Save'), $list_url) . '</form>'
            : '<p class="muted">' . t('This plugin has no settings.') . ($links !== [] ? ' ' . t('Use the pages above.') : '') . '</p>';
        $drawer = ['title' => (string)$m['name'], 'sub' => 'v' . $m['version'] . (!empty($m['author']) ? ' · ' . $m['author'] : ''), 'body' => $body, 'back' => $list_url, 'links' => $links];
    }
    $action = admin_drawer_link(admin_url('plugins', ['upload' => 1]), t('Upload plugin'), 'btn btn-primary', 'upload')
        . action_form($list_url, '<button class="btn" type="submit">' . icon('refresh') . t('Scan plugins folder') . '</button>', ['action' => 'sync'], 'inline')
        . ' <a class="btn" href="https://www.flatbb.com/market" target="_blank" rel="noopener">' . icon('external') . t('Marketplace') . '</a>';
    admin_page(t('Plugins'), $html, 'plugins', ['action' => '<div class="btn-row">' . $action . '</div>', 'drawer' => $drawer]);
}

/* ---------------------------------------------------------------- layout */

function admin_page_layout(): never
{
    $list_url = admin_url('layout');
    $blocks = json_decode_array(setting('layout_blocks', '[]'));
    if (is_post()) {
        check_csrf();
        $action = post_str('action', 20);
        $bid = post_str('id', 40);
        if ($action === 'item_on' || $action === 'item_off') {
            $map = json_decode_array(setting('layout_hidden_items', '{}'));
            $region = post_str('region', 60);
            $item = post_str('item', 200);
            if (!isset(regions_known()[$region]) || $item === '') fail(t('Choose a position.'), $list_url);
            if ($action === 'item_off') $map[$region][$item] = 1; else unset($map[$region][$item]);
            save_settings(['layout_hidden_items' => json_encode_value(array_filter($map))]);
            json_ok();
        }
        if ($action === 'plugin_on' || $action === 'plugin_off') {
            $map = json_decode_array(setting('layout_regions', '{}'));
            $hook = 'region.' . post_str('region', 60);
            $pid = post_str('plugin', 40);
            if ($action === 'plugin_off') $map[$hook][$pid] = 0; else unset($map[$hook][$pid]);
            save_settings(['layout_regions' => json_encode_value(array_filter($map))]);
            json_ok();
        }
        if ($action === 'block_delete') {
            $blocks = array_values(array_filter($blocks, static fn(array $b): bool => (string)($b['id'] ?? '') !== $bid));
            save_settings(['layout_blocks' => json_encode_value($blocks)]);
            flash(t('Block deleted.'));
            redirect($list_url);
        }
        if ($action === 'block_on' || $action === 'block_off') {
            foreach ($blocks as &$b) if ((string)($b['id'] ?? '') === $bid) $b['enabled'] = $action === 'block_on' ? 1 : 0;
            unset($b);
            save_settings(['layout_blocks' => json_encode_value($blocks)]);
            json_ok();
        }
        $html = post_str('html', 65535);
        $region = post_str('region', 60);
        if ($html === '') fail(t('The HTML is empty.'), $list_url);
        if (!isset(regions_known()[$region])) fail(t('Choose a position.'), $list_url);
        $data = ['id' => $bid !== '' ? $bid : 'b' . substr(md5(uniqid('', true)), 0, 6), 'region' => $region, 'title' => post_str('title', 60), 'html' => $html, 'enabled' => post_int('enabled') ? 1 : 0, 'sort' => post_int('sort')];
        $found = false;
        foreach ($blocks as &$b) if ((string)($b['id'] ?? '') === $data['id']) { $b = $data; $found = true; }
        unset($b);
        if (!$found) $blocks[] = $data;
        save_settings(['layout_blocks' => json_encode_value(array_values($blocks))]);
        flash(t('Block saved.'));
        redirect($list_url);
    }
    /* HTML blocks */
    $rows = [];
    foreach ($blocks as $b) {
        $rows[] = [
            '<b>' . h((string)($b['title'] ?: t('Untitled'))) . '</b><br><small class="muted">' . h(cut(strip_tags((string)$b['html']), 60)) . '</small>',
            '<code>' . h((string)$b['region']) . '</code>', (int)($b['sort'] ?? 0),
            admin_switch($list_url, ['action' => !empty($b['enabled']) ? 'block_off' : 'block_on', 'id' => $b['id']], !empty($b['enabled']), t('Enabled')),
            '<div class="row-actions">' . admin_drawer_link(admin_url('layout', ['block' => $b['id']]), t('Edit')) . admin_row_menu([admin_drawer_link(admin_url('layout', ['delete' => $b['id']]), t('Delete'), 'danger', 'trash')]) . '</div>',
        ];
    }
    $html = '<h3 class="admin-sub">' . t('HTML blocks') . '</h3><p class="muted small">' . t('Ads, notices or widgets placed in any position without writing a plugin.') . '</p>'
        . admin_table([t('Block'), t('Position'), t('Sort'), t('Enabled'), ''], $rows, t('No HTML blocks yet.'));
    /* positions: plugins per region */
    $by_hook = [];
    foreach (hook_registry() as $hook => $entries) {
        if (!str_starts_with($hook, 'region.')) continue;
        foreach ($entries as $e) if ($e['plugin'] !== '') $by_hook[substr($hook, 7)][$e['plugin']] = true;
    }
    $count = [];
    foreach ($blocks as $b) $count[(string)$b['region']] = ($count[(string)$b['region']] ?? 0) + 1;
    $rows = [];
    foreach (regions_known() as $name => $desc) {
        $chips = '';
        foreach (array_keys($by_hook[$name] ?? []) as $pid) {
            $on = layout_plugin_enabled('region.' . $name, $pid);
            $chips .= '<span class="chip">' . admin_switch($list_url, ['action' => $on ? 'plugin_off' : 'plugin_on', 'region' => $name, 'plugin' => $pid], $on, t('Show in this position')) . h(plugins()[$pid]['name'] ?? $pid) . '</span>';
        }
        // single items of list regions (links, tabs, menu entries) can be hidden one by one; loop regions have no items outside a row
        if (str_contains($desc, '(list)') && isset($by_hook[$name])) {
            $hidden = layout_hidden_items($name);
            try { $items = hook('region.' . $name, [], []); } catch (Throwable) { $items = []; }
            foreach (is_array($items) ? $items : [] as $iid => $it) {
                if (!is_array($it)) continue;
                $on = !isset($hidden[(string)$iid]);
                $label = (string)($it['label'] ?? $iid);
                $chips .= '<span class="chip chip-item">' . admin_switch($list_url, ['action' => $on ? 'item_off' : 'item_on', 'region' => $name, 'item' => (string)$iid], $on, t('Show this item')) . h(cut($label, 24)) . '</span>';
            }
        }
        $rows[] = [
            '<code>' . h($name) . '</code><br><small class="muted">' . h($desc) . '</small>',
            $chips !== '' ? '<div class="chips">' . $chips . '</div>' : '<span class="muted small">—</span>',
            isset($count[$name]) ? (int)$count[$name] : '<span class="muted small">0</span>',
            '<div class="row-actions">' . admin_drawer_link(admin_url('layout', ['block' => 'new', 'region' => $name]), t('Add block'), 'btn btn-sm', 'plus') . '</div>',
        ];
    }
    $html .= '<h3 class="admin-sub">' . t('Positions') . '</h3><p class="muted small">' . t('Every position a plugin or an HTML block can occupy. Switch a plugin off to hide it in that position only.') . '</p>'
        . admin_table([t('Position'), t('Plugins'), t('Blocks'), ''], $rows);
    /* drawers */
    $drawer = null;
    $bid = get_str('block', 40);
    $del = get_str('delete', 40);
    $find = static function (string $id) use ($blocks): ?array { foreach ($blocks as $b) if ((string)($b['id'] ?? '') === $id) return $b; return null; };
    if ($bid !== '') {
        $edit = $find($bid) ?? ['id' => '', 'region' => get_str('region', 60), 'title' => '', 'html' => '', 'enabled' => 1, 'sort' => 0];
        $positions = [];
        foreach (regions_known() as $name => $desc) $positions[$name] = $name;
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . h((string)$edit['id']) . '">'
            . form_row(t('Title (admin only)'), input('title', (string)$edit['title']))
            . '<div class="form-grid">' . form_row(t('Position'), select('region', $positions, (string)$edit['region'])) . form_row(t('Sort'), input('sort', (string)(int)$edit['sort'], ['type' => 'number'])) . '</div>'
            . form_row(t('HTML'), textarea('html', (string)$edit['html'], ['rows' => 10, 'class' => 'mono']))
            . '<div class="form-row">' . checkbox('enabled', !empty($edit['enabled']), t('Enabled')) . '</div>'
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => $edit['id'] !== '' ? (string)($edit['title'] ?: t('Untitled')) : t('New HTML block'), 'sub' => $edit['id'] !== '' ? t('Edit block') : '', 'body' => $body, 'back' => $list_url];
    } elseif ($del !== '' && ($b = $find($del)) !== null) {
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="action" value="block_delete"><input type="hidden" name="id" value="' . h((string)$b['id']) . '">'
            . '<p>' . t('Delete the block "%s"?', (string)($b['title'] ?: t('Untitled'))) . '</p>'
            . '<div class="form-actions sticky"><button type="submit" class="btn btn-danger">' . icon('trash') . t('Delete block') . '</button><a class="btn btn-ghost" href="' . h($list_url) . '" data-drawer-close>' . t('Cancel') . '</a></div></form>';
        $drawer = ['title' => t('Delete block'), 'sub' => (string)($b['title'] ?? ''), 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Layout'), $html, 'layout', ['action' => admin_drawer_link(admin_url('layout', ['block' => 'new']), t('Add HTML block'), 'btn btn-primary', 'plus'), 'drawer' => $drawer]);
}

/* ---------------------------------------------------------------- cron */

function admin_page_cron(): never
{
    if (is_post()) {
        check_csrf();
        $r = cron_run(true);
        flash(t('Ran %d jobs.', count($r)));
        redirect(admin_url('cron'));
    }
    $state = [];
    foreach (all('SELECT * FROM fb_cron') as $r) $state[$r['name']] = $r;
    $html = '<div class="admin-toolbar">' . action_form(admin_url('cron'), '<button class="btn btn-primary">' . icon('refresh') . t('Run all jobs now') . '</button>') . '</div>';
    $html .= '<div class="table-wrap"><table class="admin"><thead><tr><th>' . t('Job') . '</th><th>' . t('Interval') . '</th><th>' . t('Last run') . '</th><th>' . t('Status') . '</th><th>' . t('Runs') . '</th></tr></thead><tbody>';
    foreach (cron_jobs() as $name => $job) {
        $s = $state[$name] ?? null;
        $html .= '<tr><td><code>' . h($name) . '</code></td><td>' . (int)(cron_interval($job) / 60) . ' min</td><td>' . ($s ? human_time((int)$s['last_run']) : '-') . '</td><td>' . h((string)($s['last_status'] ?? '')) . '</td><td>' . (int)($s['run_count'] ?? 0) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    $html .= '<div class="admin-form" style="margin-top:16px"><h3>' . t('How to trigger') . '</h3><p>' . t('Call one of these every minute from your server or an external cron service:') . '</p><pre>GET ' . h(absolute_url('/cron', ['key' => setting('cron_key')])) . "\n" . 'php ' . h(ROOT) . '/flatbb cron</pre></div>';
    admin_page(t('Scheduled jobs'), $html, 'cron');
}

/* ---------------------------------------------------------------- tools */

function admin_page_tools(): never
{
    if (is_post()) {
        check_csrf();
        switch (post_str('action', 30)) {
            case 'search_rebuild': flash(t('%d posts re-indexed.', search_rebuild())); break;
            case 'recount':
                foreach (categories() as $c) category_refresh_stats((int)$c['id']);
                q('UPDATE fb_users SET topic_count=(SELECT COUNT(*) FROM fb_topics WHERE fb_topics.user_id=fb_users.id AND is_deleted=0), post_count=(SELECT COUNT(*) FROM fb_posts WHERE fb_posts.user_id=fb_users.id AND is_deleted=0 AND floor>0)');
                q('UPDATE fb_tags SET topic_count=(SELECT COUNT(*) FROM fb_topic_tags WHERE fb_topic_tags.tag_id=fb_tags.id)');
                save_settings(['stats_cache' => '']);
                flash(t('Counters recalculated.'));
                break;
            case 'clear_cache':
                foreach (glob(CACHE_DIR . '/*') ?: [] as $f) if (is_file($f) && !str_starts_with(basename($f), 'plugins.')) @unlink($f);
                save_settings(['stats_cache' => '']);
                plugin_assets_build();
                flash(t('Cache cleared.'));
                break;
            case 'schema': schema_install(); flash(t('Schema upgraded.')); break;
            case 'update_check':
                $i = upgrade_check(true);
                flash(!empty($i['error']) ? t('Update check failed: %s', (string)$i['error']) : t('Latest release: %s (installed %s).', (string)$i['version'], FLATBB_VERSION), !empty($i['error']) ? 'error' : 'info');
                break;
            case 'upgrade':
                try {
                    $v = upgrade_apply(null);
                    flash(t('Upgraded to flatbb %s. A backup of the previous files is in data/.', $v));
                } catch (Throwable $e) {
                    fail(t('Upgrade failed: %s', $e->getMessage()), admin_url('tools'));
                }
                break;
            case 'import_sqlite':
                $file = post_str('sqlite_file', 300);
                try {
                    set_time_limit(0);
                    $r = migrate_import_sqlite($file);
                    flash(t('Imported %d tables in %ss. Copy uploads/ and plugins/ manually if you have not yet.', count($r['tables']), (string)$r['seconds']));
                } catch (Throwable $e) {
                    fail(t('Import failed: %s', $e->getMessage()), admin_url('tools'));
                }
                break;
            default: fire('admin.tool', ['action' => post_str('action', 30)]);
        }
        redirect(admin_url('tools'));
    }
    $tools = [
        ['search_rebuild', t('Rebuild search index'), t('Re-index every post. Run after importing data or switching database.')],
        ['recount', t('Recalculate counters'), t('Fix topic/reply counts on users, categories and tags.')],
        ['clear_cache', t('Clear cache'), t('Removes data/cache files and rebuilds plugin assets.')],
        ['schema', t('Upgrade database schema'), t('Create any missing tables, columns and indexes (safe to repeat).')],
    ];
    $tools = (array)hook('admin.tools', $tools, []);
    $html = '<div class="table-wrap"><table class="admin"><tbody>';
    foreach ($tools as [$action, $label, $desc]) {
        $html .= '<tr><td><b>' . h($label) . '</b><br><small class="muted">' . h($desc) . '</small></td><td style="text-align:right">' . action_form(admin_url('tools'), '<button class="btn btn-sm">' . t('Run') . '</button>', ['action' => $action]) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    $latest = json_decode_array(setting('core_update_cache', ''));
    $newer = !empty($latest['version']) && version_compare((string)$latest['version'], FLATBB_VERSION, '>');
    $html .= '<div class="admin-form" style="margin-top:16px"><h3>' . t('Updates') . '</h3><p>' . t('Installed: flatbb %s.', FLATBB_VERSION) . ' ' . (!empty($latest['version']) ? t('Latest: %s (checked %s).', (string)$latest['version'], human_time((int)($latest['checked_at'] ?? 0))) : t('Not checked yet.')) . '</p><div class="btn-row">'
        . action_form(admin_url('tools'), '<button class="btn">' . icon('refresh') . t('Check for updates') . '</button>', ['action' => 'update_check'])
        . ($newer ? action_form(admin_url('tools'), '<button class="btn btn-primary">' . icon('download') . t('Upgrade to %s', (string)$latest['version']) . '</button>', ['action' => 'upgrade'], '', t('Upgrade now? Back up your database first. Core files are replaced; data, uploads and your plugins are kept.')) : '')
        . (!empty($latest['url']) ? '<a class="btn btn-ghost" href="' . h((string)$latest['url']) . '" target="_blank" rel="noopener">' . t('Release notes') . '</a>' : '') . '</div></div>';
    $html .= '<form method="post" action="' . h(admin_url('tools')) . '" class="admin-form" style="margin-top:16px" data-confirm="' . t('This empties the current database tables and replaces them with the SQLite data. Continue?') . '">' . csrf_field() . '<input type="hidden" name="action" value="import_sqlite"><h3>' . t('Import from SQLite') . '</h3><p class="muted">' . t('Moving from SQLite to MySQL: install this copy on MySQL, enable the same plugins, then import the old data/flatbb.sqlite file. Ids are preserved; the search index and counters are rebuilt. Copy uploads/ yourself.') . '</p>'
        . form_row(t('Path to the SQLite file on this server'), input('sqlite_file', '', ['placeholder' => DATA_DIR . '/old-flatbb.sqlite'])) . '<button type="submit" class="btn btn-danger">' . t('Import') . '</button></form>';
    admin_page(t('Tools'), $html, 'tools');
}

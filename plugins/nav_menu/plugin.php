<?php
/**
 * Nav Menu — header navigation links managed in the admin panel.
 * Reference example for a list region: one table, one admin page, one hook. The same items feed the header nav and the
 * mobile drawer (the drawer mirrors `header.nav`). Ordering across plugins, the members/admins filter and per-item hiding
 * are handled by the core `region_list()` contract: this plugin only declares `weight`, `visible` and `new_tab`.
 * Full rules: docs/PLUGIN.md
 */
if (!defined('FLATBB')) exit;

function nav_menu_install(array $m): void
{
    db_create_table('plugin_nav_menu_items', [
        'id' => 'id', 'label' => 'string', 'url' => 'string', 'icon' => 'string', 'new_tab' => 'uint', 'visible' => 'string', 'weight' => 'int', 'enabled' => 'uint', 'created_at' => 'uint',
    ]);
    if ((int)val('SELECT COUNT(*) FROM plugin_nav_menu_items') === 0) {
        db_insert('plugin_nav_menu_items', ['label' => 'Categories', 'url' => '/categories', 'icon' => 'folder', 'new_tab' => 0, 'visible' => 'everyone', 'weight' => 10, 'enabled' => 1, 'created_at' => now()]);
        db_insert('plugin_nav_menu_items', ['label' => 'Tags', 'url' => '/tags', 'icon' => 'tag', 'new_tab' => 0, 'visible' => 'everyone', 'weight' => 20, 'enabled' => 1, 'created_at' => now()]);
    }
}

function nav_menu_uninstall(array $m): void
{
    db_drop_table('plugin_nav_menu_items');
}

/** All items ordered by weight, loaded once per request. */
function nav_menu_items(): array
{
    return request_cache('nav_menu_items', static fn(): array => all('SELECT * FROM plugin_nav_menu_items ORDER BY weight ASC, id ASC')) ?? [];
}

/** Internal paths stay relative to the forum (url() adds the base path); absolute URLs are used as they are. */
function nav_menu_href(string $url): string
{
    if (!str_starts_with($url, '/')) return $url;
    $p = parse_url($url) ?: [];
    parse_str((string)($p['query'] ?? ''), $params);
    return url((string)($p['path'] ?? '/'), $params) . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
}

/** region.header.nav: add the enabled items. One query per page, no per-item work beyond string building. */
function nav_menu_nav(array $items, array $ctx): array
{
    foreach (nav_menu_items() as $it) {
        if ((int)$it['enabled'] !== 1) continue;
        $items['nav_menu_' . (int)$it['id']] = [
            'label' => (string)$it['label'], 'url' => nav_menu_href((string)$it['url']), 'icon' => (string)$it['icon'] ?: 'external',
            'active' => str_starts_with((string)$it['url'], '/') && is_active_path((string)(parse_url((string)$it['url'], PHP_URL_PATH) ?: '/')),
            'weight' => (int)$it['weight'], 'visible' => (string)$it['visible'], 'new_tab' => (int)$it['new_tab'] === 1,
        ];
    }
    return $items;
}

/* ---------------------------------------------------------------- admin */

function nav_menu_admin(string $page): never
{
    need_admin();
    $list_url = url('/admin/ext/nav_menu/menu');
    if (is_post()) {
        require_post(); // the dispatcher verified the CSRF token already; this documents the contract
        $id = post_int('id');
        $action = post_str('action', 20);
        $row = $id > 0 ? one('SELECT * FROM plugin_nav_menu_items WHERE id=?', [$id]) : null;
        if (in_array($action, ['on', 'off', 'delete', 'up', 'down'], true) && $row === null) fail(t('Item not found.'), $list_url);
        switch ($action) {
            case 'on':
            case 'off':
                db_update('plugin_nav_menu_items', ['enabled' => $action === 'on' ? 1 : 0], 'id=?', [$id]);
                json_ok();
            case 'delete':
                db_delete('plugin_nav_menu_items', 'id=?', [$id]);
                flash(t('Link deleted.'));
                redirect($list_url);
            case 'up':
            case 'down':
                // swap weights with the neighbour only; other items keep the weights the admin chose
                $all = array_values(nav_menu_items());
                foreach ($all as $i => $it) {
                    if ((int)$it['id'] !== $id) continue;
                    $j = $action === 'up' ? $i - 1 : $i + 1;
                    if (!isset($all[$j])) break;
                    $w = (int)$it['weight'];
                    $wn = (int)$all[$j]['weight'];
                    if ($w === $wn) { db_update('plugin_nav_menu_items', ['weight' => $action === 'up' ? $wn - 1 : $wn + 1], 'id=?', [$id]); break; }
                    db_update('plugin_nav_menu_items', ['weight' => $wn], 'id=?', [$id]);
                    db_update('plugin_nav_menu_items', ['weight' => $w], 'id=?', [(int)$all[$j]['id']]);
                    break;
                }
                redirect($list_url);
            default:
                // save (new or edit)
                $label = trim(post_str('label', 40));
                $url = trim(post_str('url', 255));
                if ($label === '') fail(t('The label is empty.'), $list_url);
                if (!preg_match('~^(/(?!/)[^\s]*|https?://[^\s]+)$~', $url)) fail(t('The URL must start with / or http(s)://'), $list_url);
                $icon = post_str('icon', 30);
                if (!isset(icon_paths()[$icon])) $icon = 'external';
                $visible = post_str('visible', 10);
                if (!in_array($visible, ['everyone', 'members', 'admins'], true)) $visible = 'everyone';
                $data = ['label' => $label, 'url' => $url, 'icon' => $icon, 'new_tab' => post_int('new_tab') ? 1 : 0, 'visible' => $visible, 'weight' => post_int('weight'), 'enabled' => post_int('enabled') ? 1 : 0];
                if ($row !== null) db_update('plugin_nav_menu_items', $data, 'id=?', [$id]);
                else db_insert('plugin_nav_menu_items', $data + ['created_at' => now()]);
                flash(t('Link saved.'));
                redirect($list_url);
        }
    }
    $rows = [];
    $items = array_values(nav_menu_items());
    foreach ($items as $i => $it) {
        $rows[] = [
            icon((string)$it['icon'] ?: 'external') . ' <b>' . h((string)$it['label']) . '</b><br><small class="muted">' . h((string)$it['url']) . ((int)$it['new_tab'] === 1 ? ' · ' . t('new tab') : '') . '</small>',
            h(match ((string)$it['visible']) { 'members' => t('Members'), 'admins' => t('Admins'), default => t('Everyone') }),
            '<div class="row-actions">' . ($i > 0 ? action_form($list_url, '<button class="btn btn-sm" title="' . t('Move up') . '">' . '&uarr;' . '</button>', ['action' => 'up', 'id' => (int)$it['id']], 'inline') : '')
                . ($i < count($items) - 1 ? action_form($list_url, '<button class="btn btn-sm" title="' . t('Move down') . '">' . '&darr;' . '</button>', ['action' => 'down', 'id' => (int)$it['id']], 'inline') : '') . '</div>',
            admin_switch($list_url, ['action' => (int)$it['enabled'] === 1 ? 'off' : 'on', 'id' => (int)$it['id']], (int)$it['enabled'] === 1, t('Enabled')),
            '<div class="row-actions">' . admin_drawer_link(url('/admin/ext/nav_menu/menu', ['edit' => (int)$it['id']]), t('Edit'))
                . admin_row_menu([action_form($list_url, '<button class="danger">' . icon('trash') . t('Delete') . '</button>', ['action' => 'delete', 'id' => (int)$it['id']], '', t('Delete the link "%s"?', (string)$it['label']))]) . '</div>',
        ];
    }
    $html = '<p class="muted small">' . t('Links shown in the header and in the mobile drawer. Links added by plugins (for example the marketplace) keep their own place; hide any single item under Layout.') . '</p>'
        . admin_table([t('Link'), t('Visible to'), t('Order'), t('Enabled'), ''], $rows, t('No links yet.'));
    $drawer = null;
    if (($eid = get_str('edit', 10)) !== '') {
        $edit = $eid !== 'new' ? one('SELECT * FROM plugin_nav_menu_items WHERE id=?', [(int)$eid]) : null;
        $edit ??= ['id' => 0, 'label' => '', 'url' => '/', 'icon' => 'external', 'new_tab' => 0, 'visible' => 'everyone', 'weight' => (count($items) + 1) * 10, 'enabled' => 1];
        $icons = [];
        foreach (array_keys(icon_paths()) as $k) $icons[$k] = $k;
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
            . form_row(t('Label'), input('label', (string)$edit['label'], ['required' => true, 'maxlength' => 40]))
            . form_row(t('URL'), input('url', (string)$edit['url'], ['required' => true, 'maxlength' => 255]), t('A path like /categories, or a full https:// address.'))
            . '<div class="form-grid">' . form_row(t('Icon (mobile drawer)'), select('icon', $icons, (string)$edit['icon']))
            . form_row(t('Visible to'), select('visible', ['everyone' => t('Everyone'), 'members' => t('Signed-in members'), 'admins' => t('Admins')], (string)$edit['visible']))
            . form_row(t('Weight'), input('weight', (string)(int)$edit['weight'], ['type' => 'number']), t('Lower comes first. Marketplace links use 50 and 60.')) . '</div>'
            . '<div class="form-row">' . checkbox('new_tab', (int)$edit['new_tab'] === 1, t('Open in a new tab')) . ' ' . checkbox('enabled', (int)$edit['enabled'] === 1, t('Enabled')) . '</div>'
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => (int)$edit['id'] > 0 ? (string)$edit['label'] : t('New link'), 'sub' => t('Navigation'), 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Navigation'), $html, 'ext.nav_menu.menu', ['action' => admin_drawer_link(url('/admin/ext/nav_menu/menu', ['edit' => 'new']), t('Add link'), 'btn btn-primary', 'plus'), 'drawer' => $drawer]);
}

return [
    'id' => 'nav_menu',
    'name' => 'Navigation Menu',
    'version' => '1.0.1',
    'description' => 'Header navigation links you manage in the admin panel: label, URL, icon, new tab, visibility and order. Also fills the mobile drawer.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.12'],
    'install' => 'nav_menu_install',
    'uninstall' => 'nav_menu_uninstall',
    'hooks' => ['region.header.nav' => 'nav_menu_nav'],
    'admin_pages' => ['menu' => ['label' => 'Navigation', 'callback' => 'nav_menu_admin']],
];

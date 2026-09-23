<?php
/**
 * Admin → Appearance → Menus: every navigation list of the site in one place (menus_known()): the top navigation, the left menu,
 * the category bar above the lists (the phone's top row), the account menu and the footer links. Each item, whoever added it (the
 * core, a plugin, the admin), can be shown or hidden, moved, and given new text, a new link, an icon or a new tab; the admin adds
 * links of their own. Edits live in setting menu_items and apply in region_list(); hiding and order are the settings Admin → Widgets
 * writes, so both pages always agree.
 */

/** One menu's items with where each came from: id => item + ['_src' => 'core' | 'custom' | plugin id, '_edited' => bool, '_default' => the item before the admin's edits]. */
function admin_menu_items_of(string $region): array
{
    $items = layout_core_items($region);
    $src = array_fill_keys(array_map('strval', array_keys($items)), 'core');
    foreach (hook_registry()['region.' . $region] ?? [] as $e) {
        try { $r = is_callable($e['fn']) ? ($e['fn'])($items, ['layout' => true]) : null; } catch (Throwable) { $r = null; }
        if (is_array($r)) $items = $r;
        foreach (array_keys($items) as $id) $src[(string)$id] ??= (string)$e['plugin'];
    }
    $edits = menu_edits($region);
    $before = $items;
    $items = menu_apply($region, $items);
    $out = [];
    foreach ($items as $id => $it) {
        if (!is_array($it)) continue; // a plain HTML item is not a link
        $id = (string)$id;
        $out[$id] = $it + ['label' => $id, 'url' => '', 'icon' => ''];
        $out[$id]['_src'] = !empty($edits[$id]['custom']) ? 'custom' : ($src[$id] ?? 'core');
        $out[$id]['_edited'] = isset($edits[$id]) && empty($edits[$id]['custom']);
        $out[$id]['_default'] = is_array($before[$id] ?? null) ? $before[$id] + ['label' => $id, 'url' => '', 'icon' => ''] : $out[$id];
    }
    return layout_order_items($region, $out);
}

/** GET|POST /admin/menus[?menu=<region>&edit=<item>|new] */
function admin_page_menus(): never
{
    $menus = menus_known();
    $region = get_str('menu', 60);
    if (!isset($menus[$region])) $region = (string)array_key_first($menus);
    $list_url = admin_url('menus', ['menu' => $region]);
    if (is_post()) {
        check_csrf();
        $action = post_str('action', 20);
        $region = post_str('region', 60);
        if (!isset($menus[$region])) fail(t('Choose a menu.'), admin_url('menus'));
        $list_url = admin_url('menus', ['menu' => $region]);
        $item = post_str('item', 60);
        $map = json_decode_array(setting('menu_items', '{}'));
        if ($action === 'item_on' || $action === 'item_off') { // the same switch as Admin → Widgets
            $hidden = json_decode_array(setting('layout_hidden_items', '{}'));
            if ($action === 'item_off') $hidden[$region][$item] = 1; else unset($hidden[$region][$item]);
            save_settings(['layout_hidden_items' => json_encode_value(array_filter($hidden))]);
            json_ok();
        }
        if ($action === 'item_order') { // the rows were dragged into a new order
            $ids = array_values(array_filter(array_map('trim', explode(',', post_str('ids', 4000))), static fn(string $v): bool => $v !== ''));
            $order = json_decode_array(setting('layout_item_order', '{}'));
            $order[$region] = $ids;
            save_settings(['layout_item_order' => json_encode_value($order)]);
            json_ok();
        }
        if ($action === 'item_up' || $action === 'item_down') {
            $ids = array_map('strval', array_keys(admin_menu_items_of($region)));
            $pos = array_search($item, $ids, true);
            $to = $pos === false ? -1 : ($action === 'item_up' ? $pos - 1 : $pos + 1);
            if ($pos !== false && $to >= 0 && $to < count($ids)) {
                [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                $order = json_decode_array(setting('layout_item_order', '{}'));
                $order[$region] = $ids;
                save_settings(['layout_item_order' => json_encode_value($order)]);
            }
            redirect($list_url);
        }
        if ($action === 'reset' || $action === 'delete') { // back to what the core or the plugin says; a link of the admin's own goes
            unset($map[$region][$item]);
            save_settings(['menu_items' => json_encode_value(array_filter($map))]);
            admin_log('menu.' . $action, $region . ':' . $item);
            flash($action === 'delete' ? t('Link removed.') : t('Item reset.'));
            redirect($list_url);
        }
        // save: an edit of an existing item, or a new link (item "new")
        $url = trim(post_str('url', 500));
        $label = trim(post_str('label', 60));
        $new = $item === 'new';
        if ($url !== '' && menu_href($url) === '') fail(t('Use a path on this site starting with / or a full https:// address.'), $list_url);
        if ($new && ($label === '' || $url === '')) fail(t('A new link needs a text and an address.'), $list_url);
        try { $icon = icon_from_post('icon'); } catch (RuntimeException $e) { fail($e->getMessage(), $list_url); } // a pick, an emoji, or an upload into the icon library
        $data = ['label' => $label, 'url' => $url, 'icon' => $icon, 'new_tab' => post_int('new_tab') ? 1 : 0];
        if ($region === 'sidebar.left.nav') $data['group'] = in_array(post_str('group', 20), ['', 'community', 'tools'], true) ? post_str('group', 20) : '';
        if ($new) {
            $item = 'm' . substr(md5(uniqid('', true)), 0, 7);
            $data += ['custom' => 1, 'weight' => 50];
        } elseif (!empty($map[$region][$item]['custom'])) {
            $data += ['custom' => 1, 'weight' => (int)($map[$region][$item]['weight'] ?? 50)];
        } else {
            // blank fields keep the default; so does a new-tab box left as the item has it, so saving an untouched form changes nothing
            $def = (array)(admin_menu_items_of($region)[$item]['_default'] ?? []);
            $tab = (int)$data['new_tab'];
            $data = array_filter($data, static fn($v): bool => $v !== '' && $v !== 0 && $v !== 1);
            if ($tab !== (int)!empty($def['new_tab'])) $data['new_tab'] = $tab;
            if (isset($data['group']) && $data['group'] === (string)($def['group'] ?? '')) unset($data['group']);
        }
        if ($data === []) unset($map[$region][$item]); else $map[$region][$item] = $data;
        save_settings(['menu_items' => json_encode_value($map)]);
        admin_log($new ? 'menu.add' : 'menu.edit', $region . ':' . $item, $label);
        flash($new ? t('Link added.') : t('Item saved.'));
        redirect($list_url);
    }

    // the menus as tabs, the chosen one as a table
    $tabs = [];
    foreach ($menus as $r => $name) $tabs[$r] = ['label' => $name, 'url' => admin_url('menus', ['menu' => $r]), 'active' => $r === $region];
    $hidden = layout_hidden_items($region);
    $items = admin_menu_items_of($region);
    $rows = [];
    $n = count($items);
    $k = 0;
    foreach ($items as $id => $it) {
        $src = (string)$it['_src'];
        $from = $src === 'core' ? t('Built in') : ($src === 'custom' ? t('Your link') : (string)(plugins()[$src]['name'] ?? $src));
        $menu = [];
        if ($k > 0) $menu[] = action_form($list_url, '<button type="submit">' . icon('chevron-up') . t('Move up') . '</button>', ['action' => 'item_up', 'region' => $region, 'item' => $id]);
        if ($k < $n - 1) $menu[] = action_form($list_url, '<button type="submit">' . icon('chevron-down') . t('Move down') . '</button>', ['action' => 'item_down', 'region' => $region, 'item' => $id]);
        if ($src === 'custom') $menu[] = action_form($list_url, '<button type="submit" class="danger">' . icon('trash') . t('Remove') . '</button>', ['action' => 'delete', 'region' => $region, 'item' => $id], '', t('Remove the link "%s"?', (string)$it['label']));
        elseif (!empty($it['_edited'])) $menu[] = action_form($list_url, '<button type="submit">' . icon('refresh') . t('Reset to default') . '</button>', ['action' => 'reset', 'region' => $region, 'item' => $id]);
        $rows[$id] = [
            '<span class="drag-grip" title="' . t('Drag to reorder') . '">' . icon('grip') . '</span>',
            admin_switch($list_url, ['action' => isset($hidden[$id]) ? 'item_on' : 'item_off', 'region' => $region, 'item' => $id], !isset($hidden[$id]), t('Show this item')),
            '<span class="menu-item-label">' . icon_any((string)$it['icon']) . '<b>' . h((string)$it['label']) . '</b>' . (!empty($it['_edited']) ? ' <span class="flag">' . t('edited') . '</span>' : '')
                . (!empty($it['group']) ? ' <span class="muted small">· ' . h((string)$it['group']) . '</span>' : '') . '</span>',
            (string)$it['url'] !== '' ? '<code class="small">' . h(cut((string)$it['url'], 48)) . '</code>' : '<span class="muted small">' . t('Set by the page') . '</span>',
            '<span class="muted small">' . h($from) . '</span>',
            '<div class="row-actions">' . admin_drawer_link(admin_url('menus', ['menu' => $region, 'edit' => $id]), t('Edit')) . ($menu !== [] ? admin_row_menu($menu) : '') . '</div>',
        ];
        $k++;
    }
    $intro = [
        'header.nav' => t('The links across the top of every page; on a phone they open from the menu button.'),
        'sidebar.left.nav' => t('The main links of the left column (and of the phone drawer). A link can sit in a group: Community or Tools.'),
        'main.categories' => t('The row above the topic lists: All and the top-level categories. On a phone it is the first row of the page. Add links such as Growth or Docs, or hide a category.'),
        'header.user_menu' => t('The menu under the avatar. Its first items also show as shortcuts on the member card.'),
        'footer.links' => t('The links at the bottom of every page.'),
    ][$region] ?? '';
    $html = tabs($tabs, 'tabs') . '<p class="muted small">' . h($intro) . ' ' . t('Plugins add items here too; you can rename, relink or hide any of them.') . '</p>'
        . admin_table(['', t('Show'), t('Item'), t('Link'), t('From'), ''], $rows, t('This menu is empty.'), ['region' => $region, 'url' => $list_url]);

    // drawer: edit an item or add a link
    $drawer = null;
    $eid = get_str('edit', 60);
    if ($eid !== '' && ($eid === 'new' || isset($items[$eid]))) {
        $it = $eid === 'new' ? ['label' => '', 'url' => '', 'icon' => '', 'new_tab' => false, 'group' => '', '_src' => 'custom'] : $items[$eid];
        $e = $eid === 'new' ? [] : (menu_edits($region)[$eid] ?? []);
        $custom = (string)$it['_src'] === 'custom';
        $def = (array)($it['_default'] ?? []);
        $body = '<form method="post" action="' . h(admin_url('menus')) . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="region" value="' . h($region) . '"><input type="hidden" name="item" value="' . h($eid) . '">'
            . form_row(t('Text'), input('label', $custom ? (string)$it['label'] : (string)($e['label'] ?? ''), ['maxlength' => 60, 'placeholder' => $custom ? '' : (string)($def['label'] ?? ''), 'required' => $custom]), $custom ? '' : t('Leave empty to keep "%s".', (string)($def['label'] ?? $it['label'])))
            . form_row(t('Link'), input('url', $custom ? (string)($e['url'] ?? '') : (string)($e['url'] ?? ''), ['maxlength' => 500, 'placeholder' => $custom ? '/growth' : ((string)($def['url'] ?? '') !== '' ? (string)$def['url'] : t('Set by the page')), 'required' => $custom]),
                t('A path on this site (/growth, /c/news) or a full https:// address.') . ($custom ? '' : ' ' . t('Leave empty to keep the link the item already has.')))
            . '<div class="form-grid">' . form_row(t('Icon'), icon_picker('icon', (string)($custom ? ($it['icon'] ?? '') : ($e['icon'] ?? '')), $custom ? [] : ['keep' => t('Keep the icon it has')]))
            . ($region === 'sidebar.left.nav' ? form_row(t('Group'), select('group', ['' => t('Main links'), 'community' => t('Community'), 'tools' => t('Tools')], (string)($custom ? ($it['group'] ?? '') : ($e['group'] ?? ($it['group'] ?? ''))))) : '') . '</div>'
            . '<div class="form-row">' . checkbox('new_tab', !empty($custom ? ($it['new_tab'] ?? false) : ($e['new_tab'] ?? ($it['new_tab'] ?? false))), t('Open in a new tab')) . '</div>'
            . admin_form_actions($eid === 'new' ? t('Add link') : t('Save'), $list_url) . '</form>';
        $drawer = ['title' => $eid === 'new' ? t('New link') : (string)$it['label'], 'sub' => $menus[$region], 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Menus'), $html, 'menus', ['action' => admin_drawer_link(admin_url('menus', ['menu' => $region, 'edit' => 'new']), t('Add link'), 'btn btn-primary', 'plus'), 'drawer' => $drawer]);
}

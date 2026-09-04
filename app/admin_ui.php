<?php
/**
 * Admin UI building blocks shared by every admin page.
 *
 * admin_page($title, $body, $active, $opts)  page shell with grouped menu; $opts:
 *   'action'  => html for the primary button at the top right
 *   'drawer'  => ['title' => .., 'sub' => .., 'body' => .., 'links' => [label => url], 'back' => url]  right-side editor
 *   The same drawer renders as a full page when the URL carries full=1 (no-JS fallback, deep links, heavy pages).
 * admin_drawer_link($url, $label)   link that opens $url inside the drawer without leaving the list (JS) or navigates (no JS)
 * admin_row_menu([$html, ...])       the "…" menu at the end of a table row (dangerous actions live here)
 * admin_switch($url, $hidden, $on)   on/off toggle posted with AJAX
 * admin_table($head, $rows)          consistent table markup
 */

function admin_menu_items(string $active): array
{
    $items = [
        'dashboard' => ['label' => t('Dashboard'), 'icon' => 'chart', 'group' => ''],
        'categories' => ['label' => t('Categories'), 'icon' => 'folder', 'group' => t('Content')],
        'tags' => ['label' => t('Tags'), 'icon' => 'tag', 'group' => t('Content')],
        'users' => ['label' => t('Users'), 'icon' => 'users', 'group' => t('Content')],
        'groups' => ['label' => t('Groups'), 'icon' => 'shield', 'group' => t('Content')],
        'layout' => ['label' => t('Layout'), 'icon' => 'layout', 'group' => t('Appearance')],
        'settings' => ['label' => t('Settings'), 'icon' => 'settings', 'group' => t('System')],
        'plugins' => ['label' => t('Plugins'), 'icon' => 'puzzle', 'group' => t('System')],
        'cron' => ['label' => t('Scheduled jobs'), 'icon' => 'clock', 'group' => t('System')],
        'tools' => ['label' => t('Tools'), 'icon' => 'terminal', 'group' => t('System')],
    ];
    foreach ($items as $k => &$it) { $it['url'] = admin_url($k); $it['active'] = $k === $active; }
    unset($it);
    foreach (plugin_manifests() as $id => $m) {
        foreach ((array)($m['admin_pages'] ?? []) as $key => $def) {
            $label = is_array($def) ? (string)($def['label'] ?? $key) : (string)$m['name'];
            if ($label === '') continue; // pages with an empty label are actions, not menu entries
            $items['ext.' . $id . '.' . $key] = ['label' => $label, 'icon' => 'puzzle', 'group' => t('Plugins'), 'url' => url('/admin/ext/' . $id . '/' . $key), 'active' => $active === 'ext.' . $id . '.' . $key];
        }
    }
    return region_list('admin.menu', $items, ['active' => $active]);
}

function admin_page(string $title, string $body, string $active = '', array $opts = []): never
{
    $active = $active !== '' ? $active : (string)(explode('/', trim(current_path(), '/'))[1] ?? 'dashboard');
    $menu = '<nav class="side-nav" data-slot="admin.menu"><a class="side-link" href="' . h(url('/')) . '">' . icon('arrow-left') . '<span>' . t('Back to site') . '</span></a>';
    $group = null;
    foreach (admin_menu_items($active) as $it) {
        if (($it['group'] ?? '') !== $group) { $group = $it['group']; if ($group !== '') $menu .= '<h4 class="side-group">' . h($group) . '</h4>'; }
        $menu .= '<a class="side-link' . (!empty($it['active']) ? ' active' : '') . '" href="' . h((string)$it['url']) . '">' . icon((string)($it['icon'] ?? 'circle')) . '<span>' . h((string)$it['label']) . '</span></a>';
    }
    $menu .= '</nav>';
    $drawer = $opts['drawer'] ?? null;
    $full = $drawer !== null && get_int('full', 0) === 1;
    $head = '<div class="admin-head"><h1>' . h($full ? (string)$drawer['title'] : $title) . '</h1>' . ($full ? '' : (string)($opts['action'] ?? '')) . '</div>';
    if ($full) {
        $crumb = '<nav class="breadcrumbs"><a href="' . h((string)($drawer['back'] ?? admin_url($active))) . '">' . h($title) . '</a><span>/</span><span>' . h((string)$drawer['title']) . '</span></nav>';
        $main = $crumb . $head . admin_drawer_links($drawer) . '<div class="admin-form admin-full">' . $drawer['body'] . '</div>';
    } else {
        $main = $head . $body . ($drawer !== null ? admin_drawer_html($drawer) : '');
    }
    page(t('Admin') . ' · ' . $title, '<div class="admin-page' . ($drawer !== null && !$full ? ' has-drawer' : '') . '">' . $main . '</div>', ['left' => $menu, 'right' => false, 'class' => 'page-admin', 'robots' => 'noindex']);
}

function admin_drawer_links(array $d): string
{
    if (empty($d['links'])) return '';
    $h = '<nav class="drawer-links">';
    foreach ($d['links'] as $label => $url) $h .= '<a class="' . (current_url() === $url ? 'active' : '') . '" href="' . h((string)$url) . '">' . h((string)$label) . '</a>';
    return $h . '</nav>';
}

function admin_drawer_html(array $d): string
{
    $back = (string)($d['back'] ?? admin_url());
    $q = $_GET; unset($q['r']); $q['full'] = 1;
    $full_url = url(current_path(), $q);
    return '<div class="adrawer-backdrop" data-drawer-close data-drawer-backdrop></div><aside class="adrawer" id="drawer" data-back="' . h($back) . '" role="dialog" aria-label="' . h((string)$d['title']) . '">'
        . '<header class="adrawer-head"><div><b>' . h((string)$d['title']) . '</b>' . (!empty($d['sub']) ? '<div class="muted small">' . h((string)$d['sub']) . '</div>' : '') . '</div>'
        . '<a class="icon-btn" href="' . h($full_url) . '" title="' . t('Open as page') . '">' . icon('external') . '</a><a class="icon-btn" href="' . h($back) . '" data-drawer-close title="' . t('Close') . '">' . icon('x') . '</a></header>'
        . admin_drawer_links($d) . '<div class="adrawer-body">' . $d['body'] . '</div></aside>';
}

function admin_drawer_link(string $url, string $label, string $class = 'btn btn-sm', string $icon = ''): string
{
    return '<a class="' . h($class) . '" href="' . h($url) . '" data-drawer>' . ($icon !== '' ? icon($icon) : '') . h($label) . '</a>';
}

/** "…" menu: pass a list of link/form HTML; forms should be action_form() with a plain button. */
function admin_row_menu(array $items): string
{
    $items = array_filter($items, static fn($i): bool => is_string($i) && trim($i) !== '');
    if ($items === []) return '';
    return '<div class="dropdown row-menu" data-dropdown><button type="button" class="icon-btn dropdown-toggle" aria-label="' . t('More') . '">' . icon('more') . '</button><div class="dropdown-menu">' . implode('', $items) . '</div></div>';
}

/** Toggle switch posted via AJAX: admin_switch(admin_url('plugins'), ['action' => 'disable', 'id' => $id], true) */
function admin_switch(string $url, array $hidden, bool $on, string $title = ''): string
{
    return action_form($url, '<button type="submit" class="switch' . ($on ? ' on' : '') . '" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" title="' . h($title) . '"><span></span></button>', $hidden, 'inline');
}

function admin_table(array $head, array $rows, string $empty = ''): string
{
    if ($rows === [] && $empty !== '') return '<div class="empty">' . icon('folder') . '<p>' . h($empty) . '</p></div>';
    $h = '<div class="table-wrap"><table class="admin"><thead><tr>';
    foreach ($head as $c) $h .= '<th>' . $c . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) $h .= '<tr>' . implode('', array_map(static fn(string $c): string => '<td>' . $c . '</td>', $r)) . '</tr>';
    return $h . '</tbody></table></div>';
}

/** Sticky save bar used at the end of drawer forms. */
function admin_form_actions(string $primary, string $cancel_url = ''): string
{
    return '<div class="form-actions sticky"><button type="submit" class="btn btn-primary">' . h($primary) . '</button>' . ($cancel_url !== '' ? '<a class="btn btn-ghost" href="' . h($cancel_url) . '" data-drawer-close>' . t('Cancel') . '</a>' : '') . '</div>';
}

<?php
/**
 * Hooks and layout regions.
 *
 * hook($name, $value, $ctx): every registered callback receives ($value, $ctx) and returns the
 * (possibly modified) value; returning null keeps the previous value.
 * fire($name, $ctx): notification-only hook, return values ignored.
 * region($name, $ctx): HTML for a named layout position. A region is the hook "region.<name>"
 * plus admin-managed custom HTML blocks, wrapped in <div data-slot="<name>">.
 * region_list($name, $items, $ctx): array-valued region (nav links, tabs, cards, menu items).
 *
 * The complete list of hooks and regions lives in docs/HOOKS.md.
 */

function hook_registry(?array $set = null): array
{
    static $registry = [];
    if ($set !== null) $registry = $set;
    return $registry;
}

/** Register a callback at runtime (core modules and plugins loaded from manifests). */
function hook_add(string $name, callable|string $callback, string $plugin = '', int $priority = 10): void
{
    $registry = hook_registry();
    $registry[$name][] = ['fn' => $callback, 'plugin' => $plugin, 'priority' => $priority];
    usort($registry[$name], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    hook_registry($registry);
}

function hook_has(string $name): bool
{
    return !empty(hook_registry()[$name]);
}

function hook(string $name, mixed $value = null, array $ctx = []): mixed
{
    foreach (hook_registry()[$name] ?? [] as $entry) {
        if ($entry['plugin'] !== '' && !layout_plugin_enabled($name, $entry['plugin'])) continue;
        if (!is_callable($entry['fn'])) continue;
        $r = ($entry['fn'])($value, $ctx);
        if ($r !== null) $value = $r;
    }
    return $value;
}

function fire(string $name, array $ctx = []): void
{
    foreach (hook_registry()[$name] ?? [] as $entry) {
        if (is_callable($entry['fn'])) ($entry['fn'])(null, $ctx);
    }
}

/* ---------------------------------------------------------------- regions */

/** Every layout region the core renders, with a short description (used by Admin → Widgets and docs). */
function regions_known(): array
{
    return (array)hook('regions.known', [
        'head' => 'Inside <head> (meta tags, analytics)',
        'header.left' => 'Header, right of the logo',
        'header.nav' => 'Header navigation links (list)',
        'header.right' => 'Header, right side: search, new topic, language, theme, notifications, account menu (list)',
        'header.right.before_search' => 'Header, left of the search box',
        'header.right.after_search' => 'Header, right of the search box',
        'header.user_menu' => 'The account list: the header dropdown shows all of it, the sidebar member card the "you" items as shortcuts (list: label, url, icon, count, group "you" or "site", weight, card => false keeps one out of the card)',
        'header.user_menu.labels' => 'User dropdown, under the name next to the group label: short labels such as the level (ctx: user)',
        'header.user_menu.stats' => 'User dropdown numbers strip (list: label, value, url; ctx: user)',
        'sidebar.left.top' => 'Left column, top',
        'sidebar.left.nav' => 'Left column navigation links, one per plugin (list: label, url, icon, badge, active, weight; group "community", "tools" or your own id with group_label; past setting nav_visible links the rest fold under More)',
        'sidebar.left.bottom' => 'Left column, bottom',
        'main.before' => 'Above the main content on every page',
        'main.categories' => 'Category bar above the list tabs: All + top-level categories (list)',
        'main.tabs' => 'Tabs above topic lists (list)',
        'main.toolbar' => 'Right of the list tabs; its New Topic button is hidden on wide screens where the member card shows its own',
        'topic_list.before' => 'Between the list tabs and the topic rows (announcements, notices)',
        'topic_list.item.title_suffix' => 'After each topic title (loop, no DB)',
        'topic_list.item.meta' => 'In each topic row meta line (loop, no DB)',
        'topic_list.item.after' => 'After each topic row (loop, no DB)',
        'topic_list.after' => 'After the topic list, before pagination',
        'main.after' => 'Below the main content on every page',
        'sidebar.right.top' => 'Right column, top',
        'sidebar.right.cards' => 'Right column card stack (list)',
        'member.labels' => 'Short labels after the group label, wherever the core shows a member: the sidebar member card, the topic author card, the account menu and the profile (ctx: user, self, place card|author|menu|profile)',
        'member.stats' => 'Numbers of a member, in the sidebar member card, the topic author card, the account menu strip and the profile card (list: label, value, url, sub, progress 0..1 draws a bar, weight; ctx: user, self, place card|author|menu|profile)',
        'member.sections' => 'Sections beside the lists on a profile, one card each: badges, a calendar, a showcase (list: title, html, url, link, weight; ctx: user, self, place profile)',
        'member.actions' => 'Buttons of a member: the sidebar member card (New Topic first), the topic author card, the profile card and the account menu, under its numbers (list: label, url, icon, primary, count, title, done, weight; post: the button POSTs to url with the CSRF token and back = the current path; ctx: user, self, place card|author|profile|menu)',
        'sidebar.right.bottom' => 'Right column, bottom',
        'topic.header' => 'Topic page, below the title block',
        'topic.no_access' => 'Topic page when a plugin refused the visitor, under the reason',
        'topic.actions' => 'Topic page action buttons (list)',
        'post.before' => 'Before each post (loop, no DB)',
        'post.content_after' => 'After each post body (loop, no DB)',
        'post.actions' => 'Post action buttons (list, loop, no DB)',
        'post.after' => 'After each post (loop, no DB)',
        'post.name' => 'Post name row, after the username, OP and group labels: a short label such as the level (loop, no DB)',
        'post.meta' => 'Post meta line, after the time: level, title, badges (loop, no DB)',
        'topic.replies_after' => 'After the post stream, before the reply box',
        'composer.extra' => 'Extra fields inside the post/reply form',
        'composer.toolbar' => 'Editor toolbar buttons (list)',
        'topic.sidebar.top' => 'Topic page right column, top',
        'topic.sidebar.cards' => 'Topic page right column cards (list)',
        'topic.sidebar.bottom' => 'Topic page right column, bottom',
        'user.profile.tabs' => 'Profile page tabs (list)',
        'user.profile.labels' => 'Profile card, after the group label: short labels such as a custom title (ctx: user, self)',
        'user.profile.meta' => 'Profile card details, after Joined / Seen / website: short items with an icon (ctx: user, self)',
        'user.profile.stats' => 'Profile card numbers (list of label, value, url, sub; progress 0..1 draws a bar across the card)',
        'user.profile.after' => 'Sections inside the profile card, under the numbers: badges (ctx: user, self)',
        'user.profile.cards' => 'Cards under the profile card (list)',
        'user.settings.tabs' => 'Settings page menu, a section list on phones (list): id => [label, group account|preferences|security|community|developer|more, weight]',
        'auth.login.extra' => 'Inside the sign-in form',
        'auth.register.extra' => 'Inside the registration form',
        'footer.left' => 'Footer, left',
        'footer.links' => 'Footer links (list)',
        'footer.right' => 'Footer, right',
        'body.end' => 'Before </body> (scripts)',
        'admin.menu' => 'Admin menu items (list)',
        'admin.dashboard.cards' => 'Admin dashboard cards (list)',
        'points.actions' => 'Ways to earn on the /points page, as tiles (list: label, title, sub, url, icon, primary, done; ctx: user)',
        'points.summary' => 'Short facts next to the balance on the /points page, such as the level (list: label, sub, progress 0..1, url; ctx: user)',
        'admin.plugins.tabs' => 'The tab row of Admin → Plugins: Installed plus what plugins add (list; ctx: active)',
        'admin.category.fields' => 'Extra fields at the end of the category editor (ctx: category)',
    ], []);
}

/** HTML region. $default is the core content (may be empty). $wrap=false returns raw HTML (for <head>, scripts). */
function region(string $name, array $ctx = [], string $default = '', bool $wrap = true): string
{
    $html = (string)hook('region.' . $name, $default, $ctx);
    $html .= layout_blocks_html($name, $wrap);
    if (!$wrap) return $html;
    return '<div class="region region-' . h(str_replace('.', '-', $name)) . '" data-slot="' . h($name) . '">' . $html . '</div>';
}

/**
 * Whether region HTML shows anything: hidden inputs, scripts and empty containers do not count. A template uses this
 * before it draws a frame around a region ("More options" in the composer), so a plugin that only plants a hidden
 * field or a script does not leave an empty box behind.
 */
function region_visible(string $html): bool
{
    $html = preg_replace('~<input\b[^>]*\btype="hidden"[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
    if (preg_match('~<(input|select|textarea|button|img|iframe|svg)\b~i', $html)) return true;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), " \t\r\n\xC2\xA0") !== '';
}

/** Array region: items keyed by id, each ['label'=>..,'url'=>..,'icon'=>..,'active'=>bool,'html'=>..]. */
/**
 * Array-valued region (nav links, tabs, cards, menu items). Items are keyed by id; besides label/url/icon/html an item may carry
 *  - weight  (int, default 0): lower comes first, equal weights keep insertion order
 *  - visible ('everyone' default | 'members' | 'admins'): filtered here, once, for every plugin
 *  - new_tab (bool): links open in a new tab where the template supports it
 * Admins can hide single items per region and put them in any order in Admin -> Widgets (settings layout_hidden_items, layout_item_order).
 */
function region_list(string $name, array $items, array $ctx = []): array
{
    $items = hook('region.' . $name, $items, $ctx);
    if (!is_array($items)) return [];
    if (isset(menus_known()[$name])) $items = menu_apply($name, $items); // Admin → Appearance → Menus: new text, links, icons, links of the admin's own
    $hidden = layout_hidden_items($name);
    $shown = [];
    foreach ($items as $id => $item) {
        if (isset($hidden[(string)$id])) continue;
        if (is_array($item)) {
            $vis = (string)($item['visible'] ?? 'everyone');
            if (($vis === 'members' && uid() <= 0) || ($vis === 'admins' && !is_admin())) continue;
        }
        $shown[$id] = $item; // plain HTML items (sidebar cards are rendered views) keep their place: weight 0, always visible
    }
    return layout_order_items($name, $shown);
}

/**
 * Sort the items of a list region: the order an admin saved in Admin → Widgets comes first (in that order),
 * then everything else by weight, then registration order. Plain HTML items count as weight 0.
 */
function layout_order_items(string $region, array $items): array
{
    $saved = array_flip(layout_item_order($region));
    $sorted = [];
    $i = 0;
    foreach ($items as $id => $item) {
        $w = is_array($item) ? (int)($item['weight'] ?? 0) : 0;
        $sorted[$id] = ['k' => isset($saved[(string)$id]) ? [0, $saved[(string)$id], 0] : [1, $w, $i++], 'v' => $item];
    }
    uasort($sorted, static fn(array $a, array $b): int => $a['k'] <=> $b['k']);
    $out = [];
    foreach ($sorted as $id => $e) $out[$id] = $e['v'];
    return $out;
}

/** Item ids an admin ordered in one list region, first to last (setting layout_item_order: {region: [id, …]}). */
function layout_item_order(string $region): array
{
    $map = request_cache('layout_item_order', static fn(): array => json_decode_array(setting('layout_item_order', '{}'))) ?? [];
    return array_values(array_map('strval', (array)($map[$region] ?? [])));
}

/** The items the core itself puts into a list region (label only), so Admin → Widgets can order them next to plugin items. */
function layout_core_items(string $region): array
{
    return match ($region) {
        'header.right' => ['search' => ['label' => t('Search'), 'weight' => -20], 'new' => ['label' => t('New Topic'), 'weight' => -10], 'lang' => ['label' => t('Language'), 'weight' => 10], 'theme' => ['label' => t('Toggle theme'), 'weight' => 20], 'notifications' => ['label' => t('Notifications'), 'weight' => 30], 'user' => ['label' => t('Account menu'), 'weight' => 40]],
        'header.user_menu' => ['profile' => ['label' => t('Profile')], 'bookmarks' => ['label' => t('Bookmarks')], 'settings' => ['label' => t('Settings')], 'admin' => ['label' => t('Admin')]],
        'sidebar.left.nav' => ['latest' => ['label' => t('Latest'), 'weight' => -30], 'categories' => ['label' => t('Categories')], 'tags' => ['label' => t('Tags')]], // Latest first; Top and Unread live in the head of the list
        'sidebar.right.cards' => ['user' => ['label' => t('Account'), 'weight' => -20], 'newest' => ['label' => t('Newest members')], 'tags' => ['label' => t('Tags'), 'weight' => 90], 'stats' => ['label' => t('Statistics'), 'weight' => 100]], // the account card first, then plugin cards, tags and statistics last
        'member.stats' => ['topics' => ['label' => t('Topics'), 'weight' => 10], 'replies' => ['label' => t('Replies'), 'weight' => 20], 'likes' => ['label' => t('Likes'), 'weight' => 30], 'points' => ['label' => t('Points'), 'weight' => 40]],
        'member.actions' => ['new' => ['label' => t('New Topic'), 'weight' => -10]],
        'topic.sidebar.cards' => ['author' => ['label' => t('Author')], 'related' => ['label' => t('Related topics')]],
        'main.categories' => (static function (): array {
            $items = ['all' => ['label' => t('All'), 'url' => url('/'), 'weight' => 0]];
            foreach (function_exists('category_tree') ? (category_tree()[0] ?? []) : [] as $i => $c) $items['c' . (int)$c['id']] = ['label' => (string)$c['name'], 'url' => category_url($c), 'weight' => $i + 1];
            return $items;
        })(),
        'footer.links' => ['categories' => ['label' => t('Categories')], 'tags' => ['label' => t('Tags')], 'rss' => ['label' => 'RSS']],
        default => [],
    };
}

/** Items an admin hid in one list region: id => 1 (setting layout_hidden_items: {region: {id: 1}}). */
function layout_hidden_items(string $region): array
{
    $map = request_cache('layout_hidden_items', static fn(): array => json_decode_array(setting('layout_hidden_items', '{}'))) ?? [];
    return (array)($map[$region] ?? []);
}

/* ---------------------------------------------------------------- menus (Admin → Appearance → Menus) */

/**
 * The list regions an admin edits as menus, region => name. Their items can get new text, a new link, an icon and a new tab, and
 * the admin adds links of their own; hiding and order are the same settings Admin → Widgets writes. Plugins add a menu of their own
 * through the filter menus.known.
 */
function menus_known(): array
{
    return request_cache('menus_known', static fn(): array => (array)hook('menus.known', [
        'header.nav' => t('Top navigation'),
        'sidebar.left.nav' => t('Left menu'),
        'main.categories' => t('Category bar'),
        'header.user_menu' => t('Account menu'),
        'footer.links' => t('Footer links'),
    ], [])) ?? [];
}

/** The admin's edits of one menu, item id => [label, url, icon, new_tab, group, custom, weight] (setting menu_items: {region: {id: {…}}}). */
function menu_edits(string $region): array
{
    $map = request_cache('menu_items', static fn(): array => json_decode_array(setting('menu_items', '{}'))) ?? [];
    return (array)($map[$region] ?? []);
}

/** A link an admin typed: a path on this site ("/growth?tab=x") goes through url(), a full http(s) address stays, anything else is ''. */
function menu_href(string $url): string
{
    $url = trim($url);
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $p = parse_url($url) ?: [];
        parse_str((string)($p['query'] ?? ''), $params);
        return url((string)($p['path'] ?? '/'), $params) . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
    }
    return preg_match('~^https?://[^\s"<>]+$~i', $url) ? $url : '';
}

/** A menu with the admin's edits applied: new text, link, icon or tab for any item (core, plugin or custom), and the custom links added. */
function menu_apply(string $region, array $items): array
{
    foreach (menu_edits($region) as $id => $e) {
        if (!is_array($e)) continue;
        $id = (string)$id;
        if (!empty($e['custom'])) {
            $href = menu_href((string)($e['url'] ?? ''));
            if ($href === '' || trim((string)($e['label'] ?? '')) === '') continue;
            $path = str_starts_with((string)$e['url'], '/') ? (string)(parse_url((string)$e['url'], PHP_URL_PATH) ?: '/') : '';
            $items[$id] = ['label' => (string)$e['label'], 'url' => $href, 'icon' => (string)($e['icon'] ?? ''), 'new_tab' => !empty($e['new_tab']), 'group' => (string)($e['group'] ?? ''),
                'active' => $path !== '' && $path !== '/' && is_active_path($path), 'weight' => (int)($e['weight'] ?? 50), 'custom' => true];
            continue;
        }
        if (!isset($items[$id]) || !is_array($items[$id])) continue;
        if (trim((string)($e['label'] ?? '')) !== '') $items[$id]['label'] = (string)$e['label'];
        if (($href = menu_href((string)($e['url'] ?? ''))) !== '') $items[$id]['url'] = $href;
        if ((string)($e['icon'] ?? '') !== '') $items[$id]['icon'] = (string)$e['icon'];
        if (array_key_exists('new_tab', $e)) $items[$id]['new_tab'] = !empty($e['new_tab']);
        if (array_key_exists('group', $e)) $items[$id]['group'] = (string)$e['group'];
    }
    return $items;
}

/** Admin-managed HTML blocks stored in setting layout_blocks: [{id,region,title,html,enabled,sort}] */
function layout_blocks(): array
{
    return request_cache('layout_blocks', static function (): array {
        $out = [];
        foreach (json_decode_array(setting('layout_blocks', '[]')) as $b) {
            if (empty($b['enabled']) || empty($b['region']) || !isset($b['html'])) continue;
            $out[(string)$b['region']][] = $b;
        }
        foreach ($out as &$list) usort($list, static fn(array $a, array $b): int => (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));
        return $out;
    }) ?? [];
}

function layout_blocks_html(string $region, bool $wrap = true): string
{
    $html = '';
    foreach (layout_blocks()[$region] ?? [] as $b) {
        $html .= $wrap ? '<div class="layout-block" data-block="' . h((string)($b['id'] ?? '')) . '">' . ($b['html'] ?? '') . '</div>' : ($b['html'] ?? '');
    }
    return $html;
}

/** Admin can switch a plugin off in a specific region (setting layout_regions: {"region.x": {"plugin": 0}}). */
function layout_plugin_enabled(string $hook, string $plugin): bool
{
    $map = request_cache('layout_regions', static fn(): array => json_decode_array(setting('layout_regions', '{}'))) ?? [];
    return (int)($map[$hook][$plugin] ?? 1) === 1;
}

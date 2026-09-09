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

/** Every layout region the core renders, with a short description (used by Admin → Layout and docs). */
function regions_known(): array
{
    return (array)hook('regions.known', [
        'head' => 'Inside <head> (meta tags, analytics)',
        'header.left' => 'Header, right of the logo',
        'header.nav' => 'Header navigation links (list)',
        'header.right' => 'Header, right side: search, new topic, language, theme, notifications, account menu (list)',
        'header.right.before_search' => 'Header, left of the search box',
        'header.right.after_search' => 'Header, right of the search box',
        'header.user_menu' => 'User dropdown menu items (list)',
        'sidebar.left.top' => 'Left column, top',
        'sidebar.left.nav' => 'Left column navigation links (list)',
        'sidebar.left.bottom' => 'Left column, bottom',
        'main.before' => 'Above the main content on every page',
        'main.categories' => 'Category bar above the list tabs: All + top-level categories (list)',
        'main.tabs' => 'Tabs above topic lists (list)',
        'main.toolbar' => 'Right of the list tabs',
        'topic_list.before' => 'Between the list tabs and the topic rows (announcements, notices)',
        'topic_list.item.title_suffix' => 'After each topic title (loop, no DB)',
        'topic_list.item.meta' => 'In each topic row meta line (loop, no DB)',
        'topic_list.item.after' => 'After each topic row (loop, no DB)',
        'topic_list.after' => 'After the topic list, before pagination',
        'main.after' => 'Below the main content on every page',
        'sidebar.right.top' => 'Right column, top',
        'sidebar.right.cards' => 'Right column card stack (list)',
        'sidebar.right.bottom' => 'Right column, bottom',
        'topic.header' => 'Topic page, below the title block',
        'topic.no_access' => 'Topic page when a plugin refused the visitor, under the reason',
        'topic.actions' => 'Topic page action buttons (list)',
        'post.before' => 'Before each post (loop, no DB)',
        'post.content_after' => 'After each post body (loop, no DB)',
        'post.actions' => 'Post action buttons (list, loop, no DB)',
        'post.after' => 'After each post (loop, no DB)',
        'post.meta' => 'Post meta line, after the time: level, title, badges (loop, no DB)',
        'topic.replies_after' => 'After the post stream, before the reply box',
        'composer.extra' => 'Extra fields inside the post/reply form',
        'composer.toolbar' => 'Editor toolbar buttons (list)',
        'topic.sidebar.top' => 'Topic page right column, top',
        'topic.sidebar.cards' => 'Topic page right column cards (list)',
        'topic.sidebar.bottom' => 'Topic page right column, bottom',
        'user.profile.tabs' => 'Profile page tabs (list)',
        'user.profile.meta' => 'Profile meta line, after Joined / Seen: level, title, badges (ctx: user, self)',
        'user.profile.stats' => 'Profile header statistics (list of label/value/url)',
        'user.profile.after' => 'Profile page, below the header',
        'user.profile.cards' => 'Profile page right column cards (list)',
        'user.settings.tabs' => 'Settings page menu, a section list on phones (list): id => [label, group account|preferences|security|community|developer|more, weight]',
        'auth.login.extra' => 'Inside the sign-in form',
        'auth.register.extra' => 'Inside the registration form',
        'footer.left' => 'Footer, left',
        'footer.links' => 'Footer links (list)',
        'footer.right' => 'Footer, right',
        'body.end' => 'Before </body> (scripts)',
        'admin.menu' => 'Admin menu items (list)',
        'admin.dashboard.cards' => 'Admin dashboard cards (list)',
        'admin.plugins.tabs' => 'The tab row of Admin → Plugins: Installed plus what plugins add (list; ctx: active)',
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
 * Admins can hide single items per region and put them in any order in Admin -> Layout (settings layout_hidden_items, layout_item_order).
 */
function region_list(string $name, array $items, array $ctx = []): array
{
    $items = hook('region.' . $name, $items, $ctx);
    if (!is_array($items)) return [];
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
 * Sort the items of a list region: the order an admin saved in Admin → Layout comes first (in that order),
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

/** The items the core itself puts into a list region (label only), so Admin → Layout can order them next to plugin items. */
function layout_core_items(string $region): array
{
    return match ($region) {
        'header.right' => ['search' => ['label' => t('Search'), 'weight' => -20], 'new' => ['label' => t('New Topic'), 'weight' => -10], 'lang' => ['label' => t('Language'), 'weight' => 10], 'theme' => ['label' => t('Toggle theme'), 'weight' => 20], 'notifications' => ['label' => t('Notifications'), 'weight' => 30], 'user' => ['label' => t('Account menu'), 'weight' => 40]],
        'header.user_menu' => ['profile' => ['label' => t('Profile')], 'bookmarks' => ['label' => t('Bookmarks')], 'settings' => ['label' => t('Settings')], 'admin' => ['label' => t('Admin')]],
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

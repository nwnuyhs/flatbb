<?php
/**
 * Rendering: views, the page shell, and small HTML helpers.
 *
 * view('topic_list', $vars)   -> renders app/views/topic_list.php with $vars extracted
 * page($title, $main, $opts)  -> full three-column page (see app/views/layout.php)
 *   $opts: left (html|null=default nav|false=hidden), right (html|null=default cards|false=hidden),
 *          class, description, canonical, robots, breadcrumbs (array of [label,url]), head (extra html),
 *          top (html at the top of the main column, above breadcrumbs: the category bar on list pages)
 */

function view(string $__view, array $__vars = []): string
{
    $__file = VIEW_DIR . '/' . $__view . '.php';
    if (!is_file($__file)) throw new RuntimeException('View not found: ' . $__view);
    extract($__vars, EXTR_SKIP);
    ob_start();
    include $__file;
    return (string)ob_get_clean();
}

function page(string $title, string $main, array $opts = []): never
{
    // title_full: the complete <title> text when a plugin composes it (SEO templates); empty = the layout's own "title - site"
    $opts += ['left' => null, 'right' => null, 'class' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'breadcrumbs' => [], 'head' => '', 'top' => '', 'title_full' => ''];
    $opts = hook('page.options', $opts, ['title' => $title]);
    if ($opts['left'] === null) $opts['left'] = view('sidebar_left', []);
    if ($opts['right'] === null) $opts['right'] = view('sidebar_right', ['cards' => sidebar_cards_default()]);
    $html = view('layout', ['title' => $title, 'main' => $main] + $opts);
    $html = (string)hook('page.before_output', $html, ['title' => $title]);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/** Default right-column cards; plugins add through region.sidebar.right.cards. */
function sidebar_cards_default(): array
{
    $cards = [];
    $cards['user'] = view('card_user', ['me' => me()]);
    $cards['stats'] = view('card_stats', ['stats' => site_stats()]);
    $cards['newest'] = view('card_newest', ['users' => site_stats()['newest_users'] ?? []]);
    return region_list('sidebar.right.cards', $cards);
}

/**
 * Lightweight inline slot for positions rendered inside loops (topic rows, posts).
 * Runs the hook "region.<name>" but skips admin HTML blocks and uses a <span>.
 * Hook callbacks here must not query the database (see docs/PLUGIN.md, N+1 rule).
 */
function slot(string $name, array $ctx = [], string $default = ''): string
{
    $html = (string)hook('region.' . $name, $default, $ctx);
    if ($html === '') return '<span data-slot="' . h($name) . '"></span>';
    return '<span class="slot" data-slot="' . h($name) . '">' . $html . '</span>';
}

function site_stats(): array
{
    return request_cache('site_stats', static function (): array {
        $cached = json_decode_array(setting('stats_cache', ''));
        if (!empty($cached['at']) && now() - (int)$cached['at'] < 300) return $cached;
        $s = [
            'topics' => (int)val('SELECT COUNT(*) FROM fb_topics WHERE is_deleted=0'),
            'posts' => (int)val('SELECT COUNT(*) FROM fb_posts WHERE is_deleted=0 AND floor>0'),
            'users' => (int)val('SELECT COUNT(*) FROM fb_users'),
            'online' => (int)val('SELECT COUNT(*) FROM fb_users WHERE last_seen>?', [now() - 900]),
            'newest_users' => q('SELECT id, username, avatar FROM fb_users ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC), // newest first, for the "Newest members" sidebar card
            'newest' => '',
            'at' => now(),
        ];
        $s['newest'] = (string)($s['newest_users'][0]['username'] ?? ''); // kept for plugins that read the old key
        save_settings(['stats_cache' => json_encode_value($s)]);
        return $s;
    }) ?? [];
}

function card(string $title, string $body, string $class = '', string $extra = ''): string
{
    return '<section class="card ' . h($class) . '">' . ($title !== '' ? '<header class="card-head"><h3>' . h($title) . '</h3>' . $extra . '</header>' : '') . '<div class="card-body">' . $body . '</div></section>';
}

/** Default logo mark (rounded square, F + dot). Fill follows --brand; assets/favicon.svg is the same drawing. */
function logo_mark(int $size = 28, string $class = ''): string
{
    return '<svg class="logo-mark' . ($class !== '' ? ' ' . h($class) : '') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<rect width="64" height="64" rx="16" fill="var(--brand, #e7672e)"/>'
        . '<path d="M21 13h27a5 5 0 0 1 0 10H26v4h14a5 5 0 0 1 0 10H26v9a5 5 0 0 1-10 0V18a5 5 0 0 1 5-5z" fill="#fff"/>'
        . '<circle cx="43" cy="45" r="5.5" fill="#fff"/></svg>';
}

/** Inline SVG icon (24x24, currentColor). Plugins can add icons via hook icon.paths. */
function icon(string $name, string $class = ''): string
{
    $paths = request_cache('icon_paths', static fn(): array => hook('icon.paths', icon_paths(), [])) ?? [];
    $p = $paths[$name] ?? $paths['circle'];
    return '<svg class="icon icon-' . h($name) . ($class !== '' ? ' ' . h($class) : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function icon_paths(): array
{
    return [
        'circle' => '<circle cx="12" cy="12" r="9"/>',
        'home' => '<path d="M3 11l9-8 9 8v9a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chevron-up' => '<path d="m6 15 6-6 6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a13.5 13.5 0 0 1 0 18M12 3a13.5 13.5 0 0 0 0 18"/>',
        'flame' => '<path d="M12 22c4.4 0 7-3 7-7 0-3-1.5-5-3-7-.5 2-1.5 3-2.5 3.5C13 9 12 6 12 2 8 5 5 9 5 15c0 4 2.6 7 7 7z"/>',
        'dot' => '<circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'tag' => '<path d="M20 12l-8 8-9-9V3h8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.5-4.5"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8"/><path d="M22 21a7 7 0 0 0-5-6.7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'reply' => '<path d="M9 17l-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        'eye' => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l8.8 8.8 8.8-8.8a5.5 5.5 0 0 0 0-7.8z"/>',
        'bookmark' => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 3h6l-1 7 3 3H7l3-3z"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
        'more' => '<circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-left' => '<path d="M15 18l-6-6 6-6"/>',
        'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'bold' => '<path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/>',
        'italic' => '<path d="M19 4h-9M14 20H5M15 4L9 20"/>',
        'code' => '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>',
        'quote' => '<path d="M3 21c3 0 7-1 7-8V5H3v8h4c0 4-1 5-4 5z"/><path d="M14 21c3 0 7-1 7-8V5h-7v8h4c0 4-1 5-4 5z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
        'puzzle' => '<path d="M14 3a2 2 0 0 1 2 2v2h2a2 2 0 0 1 2 2v3h-1.5a1.5 1.5 0 0 0 0 3H20v3a2 2 0 0 1-2 2h-3v-1.5a1.5 1.5 0 0 0-3 0V20H9a2 2 0 0 1-2-2v-3H5.5a1.5 1.5 0 0 1 0-3H7V9a2 2 0 0 1 2-2h3V5a2 2 0 0 1 2-2z"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'chart' => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'flag' => '<path d="M4 22V4a1 1 0 0 1 1-1h11l-1 4 1 4H5"/>',
        'arrow-left' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'arrow-up' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14L21 3"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
        'alert' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'refresh' => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'star' => '<path d="M12 2l3 6.5 7 1-5 5 1.2 7L12 18l-6.2 3.5L7 14.5l-5-5 7-1z"/>',
        'rss' => '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1" fill="currentColor"/>',
        'terminal' => '<path d="M4 17l6-6-6-6M12 19h8"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'strike' => '<path d="M4 12h16"/><path d="M17.5 7.5A4.5 4.5 0 0 0 13 4H11a4 4 0 0 0-1.6 7.7"/><path d="M6.5 16.5A4.5 4.5 0 0 0 11 20h2a4 4 0 0 0 1.6-7.7"/>',
        'heading' => '<path d="M6 4v16M18 4v16M6 12h12"/>',
        'list-ol' => '<path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
        'table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/>',
        'minus' => '<path d="M5 12h14"/>',
        'maximize' => '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'smile' => '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>',
    ];
}

/** Avatar image or letter fallback. $user needs id, username, avatar. */
function avatar(?array $user, int $size = 32, bool $link = true): string
{
    $name = (string)($user['username'] ?? '?');
    $cls = 'avatar avatar-' . $size;
    $style = '--s:' . $size . 'px';
    if (!empty($user['avatar'])) {
        $img = '<img class="' . $cls . '" style="' . $style . '" src="' . h(upload_url((string)$user['avatar'])) . '" width="' . $size . '" height="' . $size . '" alt="' . h($name) . '" loading="lazy">';
    } else {
        $hue = $name === '?' ? 0 : crc32(mb_strtolower($name)) % 360;
        $img = '<span class="' . $cls . ' avatar-letter" style="' . $style . ';--hue:' . $hue . '">' . h(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
    }
    if (!$link || $user === null) return $img;
    return '<a class="avatar-link" href="' . h(user_url($user)) . '" title="' . h($name) . '">' . $img . '</a>';
}

/**
 * <time> for a unix timestamp. The server prints the site time zone (relative text, or an absolute date after a month) with the
 * absolute time as tooltip; app.js re-renders both in the visitor's own time zone. $fmt: rel | date (Sep 7, 2026) | month (Sep 2026) | full (2026-09-07 14:05).
 */
function time_tag(int $ts, string $fmt = 'rel', string $class = ''): string
{
    $text = match ($fmt) { 'date' => date('M j, Y', $ts), 'month' => date('M Y', $ts), 'full' => date('Y-m-d H:i', $ts), default => human_time($ts) };
    return '<time datetime="' . date('c', $ts) . '" data-fmt="' . h($fmt) . '" title="' . date('Y-m-d H:i', $ts) . '"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . h($text) . '</time>';
}

/**
 * "You can post again in 3 minutes" with a clock that runs down and puts the form back when it reaches zero.
 * Under ten minutes it ticks by the second; a longer wait shows the words and the local time it ends (app.js does both).
 */
function post_hold_notice(array $hold): string
{
    if ($hold === []) return '';
    return '<div class="post-hold" data-hold-until="' . (int)$hold['until'] . '">' . icon('clock')
        . '<div><b data-hold-text>' . h((string)$hold['message']) . '</b>'
        . '<div class="muted small">' . t('You can post again at %s.', time_tag((int)$hold['until'], 'full')) . '</div></div></div>';
}

function user_link(?array $user, string $class = 'user-link'): string
{
    if ($user === null) return '<span class="' . h($class) . ' user-deleted">' . t('deleted') . '</span>';
    $g = str_contains($class, 'plain') ? null : group_by_id((int)($user['group_id'] ?? 0)); // "plain": no group colour (lists)
    $style = !empty($g['color']) ? ' style="color:' . h($g['color']) . '"' : '';
    return '<a class="' . h($class) . '" href="' . h(user_url($user)) . '"' . $style . '>' . h($user['username']) . '</a>' . hook('user.link_after', '', ['user' => $user, 'class' => $class]);
}

/** The category's icon when the admin picked one in Admin → Categories, else ''. */
function category_icon(array $cat): string
{
    $name = (string)($cat['icon'] ?? '');
    return $name !== '' && isset(icon_paths()[$name]) ? icon($name) : '';
}

function category_badge(?array $cat, bool $link = true): string
{
    if ($cat === null) return '';
    $inner = category_icon($cat) . h($cat['name']);
    return $link ? '<a class="cat-badge" href="' . h(category_url($cat)) . '">' . $inner . '</a>' : '<span class="cat-badge">' . $inner . '</span>';
}

function tag_badge(array $tag): string
{
    return '<a class="tag-badge" href="' . h(tag_url($tag)) . '">' . h($tag['name']) . '</a>';
}

/** Pagination links. $url_fn(int $page): string */
function pagination(array $p, callable $url_fn): string
{
    if ($p['pages'] <= 1) return '';
    $cur = $p['page']; $last = $p['pages'];
    $pages = array_unique(array_filter([1, 2, $cur - 2, $cur - 1, $cur, $cur + 1, $cur + 2, $last - 1, $last], static fn(int $n): bool => $n >= 1 && $n <= $last));
    sort($pages);
    $html = '<nav class="pagination" aria-label="Pagination">';
    if ($cur > 1) $html .= '<a class="page-prev" href="' . h($url_fn($cur - 1)) . '" rel="prev">' . icon('chevron-left') . '</a>';
    $prev = 0;
    foreach ($pages as $n) {
        if ($prev && $n - $prev > 1) $html .= '<span class="page-gap">…</span>';
        $html .= $n === $cur ? '<span class="page-cur">' . $n . '</span>' : '<a href="' . h($url_fn($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    if ($cur < $last) $html .= '<a class="page-next" href="' . h($url_fn($cur + 1)) . '" rel="next">' . icon('chevron-right') . '</a>';
    return $html . '</nav>';
}

function tabs(array $items, string $class = 'tabs'): string
{
    $html = '<nav class="' . h($class) . '">';
    foreach ($items as $key => $it) {
        if (!empty($it['html'])) { $html .= $it['html']; continue; }
        $html .= '<a class="tab' . (!empty($it['active']) ? ' active' : '') . '" href="' . h((string)$it['url']) . '" data-tab="' . h((string)$key) . '">' . (!empty($it['icon']) ? icon((string)$it['icon']) : '') . '<span>' . h((string)$it['label']) . '</span>' . (!empty($it['badge']) ? '<b class="badge">' . h((string)$it['badge']) . '</b>' : '') . '</a>';
    }
    return $html . '</nav>';
}

/* ---------------------------------------------------------------- forms */

function form_row(string $label, string $field, string $help = ''): string
{
    return '<div class="form-row"><label>' . h($label) . '</label>' . $field . ($help !== '' ? '<div class="form-help">' . $help . '</div>' : '') . '</div>';
}

function input(string $name, string $value = '', array $attr = []): string
{
    $attr += ['type' => 'text'];
    $a = '';
    foreach ($attr as $k => $v) $a .= $v === true ? ' ' . $k : ' ' . $k . '="' . h((string)$v) . '"';
    return '<input name="' . h($name) . '" value="' . h($value) . '"' . $a . '>';
}

function textarea(string $name, string $value = '', array $attr = []): string
{
    $attr += ['rows' => 5];
    $a = '';
    foreach ($attr as $k => $v) $a .= $v === true ? ' ' . $k : ' ' . $k . '="' . h((string)$v) . '"';
    return '<textarea name="' . h($name) . '"' . $a . '>' . h($value) . '</textarea>';
}

function select(string $name, array $options, string $value = '', array $attr = []): string
{
    $a = '';
    foreach ($attr as $k => $v) $a .= $v === true ? ' ' . $k : ' ' . $k . '="' . h((string)$v) . '"';
    $html = '<select name="' . h($name) . '"' . $a . '>';
    foreach ($options as $k => $label) $html .= '<option value="' . h((string)$k) . '"' . ((string)$k === $value ? ' selected' : '') . '>' . h((string)$label) . '</option>';
    return $html . '</select>';
}

function checkbox(string $name, bool $checked, string $label): string
{
    return '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '> ' . h($label) . '</label>';
}

/** A tiny POST form with one button (for actions like like/delete/pin). */
function action_form(string $url, string $button_html, array $hidden = [], string $class = '', string $confirm = ''): string
{
    $h = '';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    return '<form method="post" action="' . h($url) . '" class="action-form ' . h($class) . '" data-ajax="1"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field() . $h . $button_html . '</form>';
}

/** Markdown editor widget shared by new topic, reply and edit forms. */
/** $opts: scope (draft key such as "topic-new", "reply-12", "post-34"), ctx (passed to editor.options hook). */
function editor(string $name, string $value = '', string $placeholder = '', array $opts = []): string
{
    return view('editor', ['name' => $name, 'value' => $value, 'placeholder' => $placeholder, 'scope' => (string)($opts['scope'] ?? ''), 'ctx' => (array)($opts['ctx'] ?? [])]);
}

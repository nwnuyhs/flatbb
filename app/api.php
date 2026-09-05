<?php
/**
 * Small JSON API used by app.js, plus sitemap and RSS.
 *   POST /api/preview    body -> {html}
 *   GET  /api/users?q=   -> [{username, avatar}] for @mention autocomplete
 *   GET  /api/unread     -> {count}
 *   any  /api/<action>   -> hook api.<action> for plugins (return array to respond)
 */
function api_dispatch(string $action): never
{
    switch ($action) {
        case 'preview':
            need_login();
            check_csrf();
            json_ok(['html' => md(post_str('body'))]);
        case 'users':
            need_login();
            $q = mb_strtolower(get_str('q', 30));
            if ($q === '') json_ok(['users' => []]);
            $rows = all("SELECT username,avatar FROM fb_users WHERE username_lower LIKE ? ESCAPE '!' AND status=1 ORDER BY post_count DESC LIMIT 8", [rtrim(db_like($q), '%')]);
            foreach ($rows as &$r) $r['avatar'] = $r['avatar'] !== '' ? upload_url((string)$r['avatar']) : '';
            json_ok(['users' => $rows]);
        case 'unread':
            json_ok(['count' => notifications_unread()]);
        case 'info':
            json_ok(['name' => setting('site_name'), 'version' => FLATBB_VERSION, 'topics' => site_stats()['topics'] ?? 0, 'users' => site_stats()['users'] ?? 0]);
    }
    $r = hook('api.' . $action, null, ['action' => $action]);
    if (is_array($r)) json_ok($r);
    json_error('unknown action', 404);
}

function seo_sitemap(): never
{
    header('Content-Type: application/xml; charset=utf-8');
    $visible = category_visible_ids();
    $where = 'is_deleted=0' . ($visible !== null ? ($visible === [] ? ' AND 0' : ' AND category_id IN (' . implode(',', $visible) . ')') : '');
    $rows = all("SELECT id,slug,updated_at FROM fb_topics WHERE {$where} ORDER BY id DESC LIMIT 5000");
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>' . h(absolute_url('/')) . '</loc></url>';
    foreach (categories() as $c) if (category_can_view($c)) echo '<url><loc>' . h(absolute_url('/c/' . $c['slug'])) . '</loc></url>';
    foreach ($rows as $t) echo '<url><loc>' . h(absolute_url('/t/' . $t['slug'] . '-' . $t['id'])) . '</loc><lastmod>' . date('c', (int)$t['updated_at']) . '</lastmod></url>';
    echo '</urlset>';
    exit;
}

/** Web app manifest: "Add to home screen" uses the site name and icon instead of the page title. */
function seo_manifest(): never
{
    $site = setting('site_name');
    $fav = setting('site_favicon');
    $icon = $fav !== '' ? upload_url($fav) : base_path() . '/assets/favicon.svg';
    $type = str_contains($icon, '.svg') ? 'image/svg+xml' : (str_contains($icon, '.ico') ? 'image/x-icon' : 'image/png');
    $brand = preg_match('/^#[0-9a-f]{6}$/i', setting('brand_color', '#e7672e')) ? setting('brand_color') : '#e7672e';
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode_value([
        'name' => $site, 'short_name' => cut($site, 12, ''), 'description' => setting('site_tagline'),
        'start_url' => (base_path() ?: '') . '/', 'scope' => (base_path() ?: '') . '/', 'display' => 'minimal-ui',
        'background_color' => '#ffffff', 'theme_color' => $brand,
        'icons' => [['src' => $icon, 'sizes' => $type === 'image/svg+xml' ? 'any' : '192x192 512x512', 'type' => $type, 'purpose' => 'any']],
    ]);
    exit;
}

function seo_rss(): never
{
    header('Content-Type: application/rss+xml; charset=utf-8');
    $visible = category_visible_ids();
    $where = 'is_deleted=0' . ($visible !== null ? ($visible === [] ? ' AND 0' : ' AND category_id IN (' . implode(',', $visible) . ')') : '');
    $rows = all("SELECT id,title,slug,first_post_id,user_id,created_at FROM fb_topics WHERE {$where} ORDER BY id DESC LIMIT 30");
    $posts = rows_by_ids('fb_posts', array_column($rows, 'first_post_id'), 'id,body_html');
    $users = users_by_ids(array_column($rows, 'user_id'));
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . h(setting('site_name')) . '</title><link>' . h(absolute_url('/')) . '</link><description>' . h(setting('site_tagline')) . '</description>';
    foreach ($rows as $t) {
        echo '<item><title>' . h($t['title']) . '</title><link>' . h(absolute_url('/t/' . $t['slug'] . '-' . $t['id'])) . '</link><guid>' . h(absolute_url('/t/' . $t['slug'] . '-' . $t['id'])) . '</guid>';
        echo '<pubDate>' . date('r', (int)$t['created_at']) . '</pubDate><author>' . h($users[(int)$t['user_id']]['username'] ?? '') . '</author>';
        echo '<description>' . h($posts[(int)$t['first_post_id']]['body_html'] ?? '') . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

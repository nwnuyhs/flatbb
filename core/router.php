<?php
/**
 * Routing. A route is "pattern => handler function". Patterns:
 *   '/latest'                 exact path
 *   '/c/{slug}'               {name} matches one path segment, passed as string argument
 *   '~^/t/(?:[^/]*-)?(\d+)$'  raw regex when the pattern starts with "~" (the regex must not contain "~"); groups become arguments
 * Handlers decide the HTTP method themselves (require_post() for state changes).
 * Plugins add routes through their manifest 'routes' => ['/hello' => 'hello_page'].
 *
 * URLs: url('/path', ['q'=>1]) respects the base path and the rewrite setting.
 */

function routes(?array $add = null): array
{
    static $routes = null;
    if ($routes === null) $routes = routes_core();
    if ($add !== null) $routes = $routes + $add;
    return $routes;
}

function router_add(string $pattern, string $handler): void
{
    routes([$pattern => $handler]);
}

function routes_core(): array
{
    return [
        '/' => 'home_index',
        '/latest' => 'home_latest',
        '/top' => 'home_top',
        '/top/{period}' => 'home_top',
        '/unread' => 'home_unread',
        '/categories' => 'category_index',
        '/c/{slug}' => 'category_view',
        '/tags' => 'tag_index',
        '/tag/{name}' => 'tag_view',
        '~^/t/(?:[^/]*-)?(\d+)$' => 'topic_view',
        '/new-topic' => 'topic_new',
        '/t/{id}/reply' => 'topic_reply',
        '/t/{id}/edit' => 'topic_edit',
        '/t/{id}/action' => 'topic_action',
        '/t/{id}/bookmark' => 'topic_bookmark',
        '/post/{id}' => 'post_permalink',
        '/post/{id}/edit' => 'post_edit',
        '/post/{id}/like' => 'post_like',
        '/post/{id}/delete' => 'post_delete',
        '/post/{id}/raw' => 'post_raw',
        '/u/{name}' => 'user_profile',
        '/u/{name}/{tab}' => 'user_profile',
        '/settings' => 'user_settings',
        '/settings/{tab}' => 'user_settings',
        '/login' => 'account_login',
        '/register' => 'account_register',
        '/logout' => 'account_logout',
        '/forgot' => 'account_forgot',
        '/reset/{token}' => 'account_reset',
        '/notifications' => 'notification_index',
        '/notifications/read' => 'notification_read',
        '/search' => 'search_page',
        '/upload' => 'upload_handle',
        '/admin' => 'admin_index',
        '/admin/{page}' => 'admin_index',
        '/admin/ext/{plugin}/{page}' => 'admin_ext',
        '/api/{action}' => 'api_dispatch',
        '/plugin-assets/{type}' => 'plugin_assets_serve',
        '/cron' => 'cron_run_web',
        '/sitemap.xml' => 'seo_sitemap',
        '/rss' => 'seo_rss',
        '/manifest.webmanifest' => 'seo_manifest',
        '/setup' => 'setup_index',
        '/__rewrite_check' => 'router_rewrite_check',
    ];
}

function router_rewrite_check(): never
{
    header('Content-Type: text/plain');
    echo 'ok';
    exit;
}

/** Directory the app is served from, "" when at the web root, "/forum" when in a subfolder. */
function base_path(): string
{
    return request_cache('base_path', static function (): string {
        $configured = (string)config('base_path', '');
        if ($configured !== '') return rtrim($configured, '/');
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }) ?? '';
}

function base_url(): string
{
    $configured = (string)config('base_url', '');
    if ($configured !== '') return rtrim($configured, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https://' : 'http://') . $host . base_path();
}

function rewrite_enabled(): bool
{
    return setting('rewrite', '0') === '1';
}

/** Build an in-app URL. url('/t/hello-1', ['page' => 2]) */
function url(string $path = '/', array $params = []): string
{
    $path = '/' . ltrim($path, '/');
    $query = $params === [] ? '' : http_build_query($params);
    if ($path === '/' ) return base_path() . '/' . ($query !== '' ? '?' . $query : '');
    if (rewrite_enabled()) return base_path() . $path . ($query !== '' ? '?' . $query : '');
    return base_path() . '/index.php?r=' . rawurlencode($path) . ($query !== '' ? '&' . $query : '');
}

function absolute_url(string $path = '/', array $params = []): string
{
    return base_url() . substr(url($path, $params), strlen(base_path()));
}

/** The request path relative to the app, e.g. "/t/hello-1". */
function current_path(): string
{
    return request_cache('current_path', static function (): string {
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            $p = '/' . ltrim($_GET['r'], '/');
        } else {
            $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $base = base_path();
            if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
            $p = '/' . ltrim($uri, '/');
            if (str_starts_with($p, '/index.php')) $p = '/' . ltrim(substr($p, 10), '/');
        }
        $p = rawurldecode($p);
        return $p !== '/' ? rtrim($p, '/') : '/';
    }) ?? '/';
}

function current_url(): string
{
    $q = $_GET;
    unset($q['r']);
    return url(current_path(), $q);
}

function is_active_path(string $path): bool
{
    $cur = current_path();
    return $cur === $path || ($path !== '/' && str_starts_with($cur, $path . '/'));
}

/** Match the current request and call the handler. */
function dispatch(): void
{
    if (current_path() === '/__rewrite_check') router_rewrite_check();
    if (!is_installed()) {
        setup_index();
        return;
    }
    if (setting('installed_at', '') === '' && current_path() !== '/setup') {
        redirect(url('/setup'));
    }
    $path = current_path();
    [$handler, $args] = route_match($path);
    if ($handler === null || !function_exists($handler)) not_found();
    if (is_post() && !router_csrf_exempt($path)) check_csrf(); // every POST carries the token unless the route authenticates otherwise
    $handler(...$args);
}

/**
 * Routes that authenticate without a browser session (API tokens) and therefore skip the CSRF check.
 * Core has none; plugins declare them in the manifest: 'csrf_exempt' => ['/api/market/publish'].
 */
function router_csrf_exempt_add(array $paths): void
{
    router_csrf_exempt('', $paths);
}

function router_csrf_exempt(string $path, array $add = []): bool
{
    static $list = [];
    foreach ($add as $p) $list[(string)$p] = true;
    return $path !== '' && isset($list[$path]);
}

function route_match(string $path): array
{
    $routes = routes();
    if (isset($routes[$path]) && !str_starts_with($path, '~')) return [$routes[$path], []];
    foreach ($routes as $pattern => $handler) {
        if ($pattern[0] === '~') {
            if (preg_match('~' . substr($pattern, 1) . '~u', $path, $m)) return [$handler, array_slice($m, 1)];
            continue;
        }
        if (!str_contains($pattern, '{')) continue;
        $regex = '#^' . preg_replace('/\\\\\{[a-z_]+\\\\\}/i', '([^/]+)', preg_quote($pattern, '#')) . '$#u';
        if (preg_match($regex, $path, $m)) return [$handler, array_slice($m, 1)];
    }
    return [null, []];
}

/* ---------------------------------------------------------------- url helpers */

function topic_url(array $topic, int $page = 1, int $post_id = 0): string
{
    $u = url('/t/' . ($topic['slug'] !== '' ? $topic['slug'] . '-' : '') . (int)$topic['id'], $page > 1 ? ['page' => $page] : []);
    return $post_id > 0 ? $u . '#post-' . $post_id : $u;
}

function category_url(array $category): string
{
    return url('/c/' . $category['slug']);
}

function tag_url(array|string $tag): string
{
    return url('/tag/' . rawurlencode(is_array($tag) ? (string)$tag['slug'] : $tag));
}

function admin_url(string $page = '', array $params = []): string
{
    return url('/admin' . ($page !== '' ? '/' . $page : ''), $params);
}

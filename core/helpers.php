<?php
/**
 * Small helpers used everywhere. Keep them pure and dependency-free.
 */

/** HTML-escape any scalar. Always use this before printing user data. */
/** Marks HTML that is intentionally output unescaped (already-rendered fragments). security:check allows only raw() and known helpers after <?= */
function raw(string $html): string
{
    return $html;
}

function h(string|int|float|bool|null $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): int
{
    return REQUEST_TIME;
}

/** Truncate a UTF-8 string with an ellipsis. */
function cut(string $s, int $max = 100, string $suffix = '…'): string
{
    $s = trim($s);
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) . $suffix : $s;
}

/** "3 minutes ago" style relative time. */
function human_time(int $ts): string
{
    $d = now() - $ts;
    if ($d < 5) return t('just now');
    if ($d < 60) return t('%ds ago', $d);
    if ($d < 3600) return t('%dm ago', intdiv($d, 60));
    if ($d < 86400) return t('%dh ago', intdiv($d, 3600));
    if ($d < 86400 * 30) return t('%dd ago', intdiv($d, 86400));
    return date(now() - $ts > 86400 * 365 ? 'M j, Y' : 'M j', $ts);
}

/** Time zone choices for Settings → General: identifier => 'Region/City (UTC+03:00)'. */
function tz_options(): array
{
    $out = [];
    $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (timezone_identifiers_list() as $id) {
        $off = (new DateTimeZone($id))->getOffset($at);
        $out[$id] = $id . ' (UTC' . ($off === 0 ? '' : ($off < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv(abs($off), 3600), intdiv(abs($off) % 3600, 60))) . ')';
    }
    return $out;
}

function human_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function human_number(int $n): string
{
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000) return round($n / 1000, 1) . 'k';
    return (string)$n;
}

/** URL slug from a title: "Hello World!" -> "hello-world". */
function slugify(string $s, int $max = 80): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') return 'topic';
    return cut($s, $max, '');
}

function json_decode_array(?string $json, array $default = []): array
{
    if ($json === null || $json === '') return $default;
    $v = json_decode($json, true);
    return is_array($v) ? $v : $default;
}

function json_encode_value(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/** The visitor's address; behind a trusted proxy (setting trusted_proxies) the forwarded address. See core/security.php. */
function client_ip(): string
{
    return client_ip_resolve($_SERVER);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function post_str(string $key, int $max = 65535): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? cut(str_replace("\r\n", "\n", trim($v)), $max, '') : '';
}

/** Untrimmed POST string (passwords). */
function post_secret(string $key, int $max = 4096): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? substr($v, 0, $max) : '';
}

/** POST list of scalars as strings (checkbox groups, multi-selects). */
function post_list(string $key, int $max = 200): array
{
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) return [];
    $out = [];
    foreach (array_slice($v, 0, $max) as $x) if (is_scalar($x)) $out[] = (string)$x;
    return $out;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function get_str(string $key, int $max = 500): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? cut(trim($v), $max, '') : '';
}

function get_int(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $v = $_GET[$key] ?? null;
    $n = is_numeric($v) ? (int)$v : $default;
    return max($min, min($max, $n));
}

/** Send JSON and stop. */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_value($data);
    exit;
}

function json_ok(array $data = []): never
{
    json_response(['ok' => true] + $data);
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

/** Redirect and stop. */
function redirect(string $url, int $status = 302): never
{
    if (is_ajax()) json_ok(['redirect' => $url]);
    header('Location: ' . $url, true, $status);
    exit;
}

/** Flash message stored in a short-lived cookie (no server sessions). */
function flash(string $message, string $type = 'success'): void
{
    app_cookie('fb_flash', json_encode_value([$type, $message]), now() + 60, true);
}

function flash_take(): ?array
{
    $raw = $_COOKIE['fb_flash'] ?? '';
    if ($raw === '') return null;
    app_cookie('fb_flash', '', now() - 3600, true);
    $v = json_decode($raw, true);
    return is_array($v) && count($v) === 2 ? ['type' => (string)$v[0], 'message' => (string)$v[1]] : null;
}

/** A cookie value, trimmed to $max characters ('' when absent). */
function cookie_str(string $name, int $max = 200): string
{
    $v = $_COOKIE[$name] ?? '';
    return is_string($v) ? mb_substr($v, 0, $max) : '';
}

function app_cookie(string $name, string $value, int $expires, bool $httponly = true): void
{
    if (headers_sent()) return;
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => base_path() ?: '/',
        'secure' => is_https(),
        'httponly' => $httponly,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Show an error page and stop. */
function error_page(string $message, int $status = 400, string $title = ''): never
{
    if (is_ajax()) json_error($message, $status);
    http_response_code($status);
    $title = $title !== '' ? $title : (string)$status;
    page($title, view('error', ['title' => $title, 'message' => $message]), ['class' => 'page-error']);
    exit;
}

function not_found(string $message = ''): never
{
    error_page($message !== '' ? $message : t('The page you requested does not exist.'), 404, '404');
}

function forbidden(string $message = ''): never
{
    error_page($message !== '' ? $message : t('You do not have permission to do that.'), 403, '403');
}

/** Abort POST handlers with a message; AJAX gets JSON, browsers get a flash + back. */
function fail(string $message, string $back = ''): never
{
    if (is_ajax()) json_error($message);
    flash($message, 'error');
    redirect($back !== '' ? $back : ((string)($_SERVER['HTTP_REFERER'] ?? '') ?: url('/')));
}

/** Per-request memo cache. request_cache('key', fn() => ...) */
function request_cache(string $key, ?callable $fn = null, bool $reset = false): mixed
{
    static $cache = [];
    if ($reset) { unset($cache[$key]); return null; }
    if ($fn === null) return $cache[$key] ?? null;
    if (!array_key_exists($key, $cache)) $cache[$key] = $fn();
    return $cache[$key];
}

/** Read a site setting (fb_settings). All settings are cached per request. */
function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return $all[$key] ?? $default;
}

function settings_all(): array
{
    if (!is_installed()) return setting_defaults();
    return request_cache('settings', static function (): array {
        $rows = q('SELECT `key`,`value` FROM `fb_settings`')->fetchAll(PDO::FETCH_KEY_PAIR);
        return ($rows ?: []) + setting_defaults();
    }) ?? [];
}

function setting_defaults(): array
{
    return [
        'site_name' => 'flatbb',
        'site_tagline' => 'A flat, lightweight forum',
        'site_description' => '',
        'site_logo' => '',
        'site_favicon' => '',
        'site_lang' => '',
        'site_tz' => 'UTC',
        'per_page' => '25',
        'category_bar' => 'mobile',
        'posts_per_page' => '20',
        'post_image_max' => '0',
        'allow_register' => '1',
        'invite_code' => '',
        'register_verify' => '0',
        'allow_rename' => '0',
        'rename_days' => '30',
        'post_interval' => '15',
        'new_user_limit_hours' => '24',
        'new_user_max_posts' => '5',
        'upload_max_mb' => '5',
        'upload_types' => 'jpg,jpeg,png,gif,webp,pdf,zip,txt',
        'rewrite' => '0',
        'csp_mode' => 'report',
        'trusted_proxies' => '',
        'theme' => 'auto',
        'brand_color' => '#e7672e',
        'footer_text' => '',
        'cron_key' => '',
        'layout_blocks' => '[]',
        'layout_regions' => '{}',
        'seo_keywords' => '',
        'mail_from' => '',
        'editor_preview' => '1',
        'editor_emoji' => '1',
        'editor_fullscreen' => '1',
        'editor_draft_days' => '7',
        'head_code' => '',
        'foot_code' => '',
    ];
}

function save_settings(array $values): void
{
    foreach ($values as $k => $v) {
        db_upsert('fb_settings', ['key' => (string)$k, 'value' => (string)$v], ['key']);
    }
    request_cache('settings', null, true);
}

/**
 * Send a plain-text email. Plugins take over through the mail.send hook (return true when sent,
 * false when failed); without a plugin PHP's mail() is used. Returns whether the mail was accepted.
 */
function mail_send(string $to, string $subject, string $text): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = setting('mail_from', '') ?: 'noreply@' . preg_replace('/^www\./', '', (string)parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
    $r = hook('mail.send', null, ['to' => $to, 'subject' => $subject, 'text' => $text, 'from' => $from, 'from_name' => setting('site_name')]);
    if (is_bool($r)) return $r;
    $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n" . 'X-Mailer: flatbb';
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
}

/**
 * True when $url points back at this very site while running under PHP's single-threaded dev server
 * (php -S): such a request would wait for itself and freeze the server. Callers should skip the call.
 */
function http_self_request_blocked(string $url): bool
{
    if (PHP_SAPI !== 'cli-server') return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $port = (int)(parse_url($url, PHP_URL_PORT) ?: 80);
    $own = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
    $own_port = (int)(parse_url(base_url(), PHP_URL_PORT) ?: 80);
    return $host === $own && $port === $own_port;
}

/** Simple ordered pagination info. */
function paginate_calc(int $total, int $page, int $per_page): array
{
    $pages = max(1, (int)ceil($total / max(1, $per_page)));
    $page = max(1, min($pages, $page));
    return ['page' => $page, 'pages' => $pages, 'per_page' => $per_page, 'offset' => ($page - 1) * $per_page, 'total' => $total];
}

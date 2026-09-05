<?php
/**
 * Security layer: response headers with a nonce-based Content Security Policy, the real client IP behind trusted
 * proxies, the admin action log, and "confirm mode" (an admin re-enters the password before high-risk actions, so a
 * stolen or script-ridden session cannot change what matters).
 */
if (!defined('FLATBB')) exit;

/* ---------------------------------------------------------------- response headers and CSP */

/** Per-request nonce for inline scripts (layout, plugins via script_tag()). */
function csp_nonce(): string
{
    return request_cache('csp_nonce', static fn(): string => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')) ?? '';
}

/** CSP directives as name => sources. Plugins add their CDNs through the `security.csp` filter. */
function csp_policy(): array
{
    $p = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'nonce-" . csp_nonce() . "'"],
        'style-src' => ["'self'", "'unsafe-inline'", 'https:'],
        'img-src' => ['*', 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:', 'https:'],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'", 'https:', 'data:'],
        'frame-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];
    return (array)hook('security.csp', $p, []);
}

/** Send the security headers once per request (called by dispatch() before any output). */
function security_headers(): void
{
    if (headers_sent() || request_cache('security_headers_sent') === true) return;
    request_cache('security_headers_sent', static fn(): bool => true);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    $mode = setting('csp_mode', 'report');
    if ($mode !== 'report' && $mode !== 'enforce') return;
    $parts = [];
    foreach (csp_policy() as $name => $sources) if (is_array($sources) && $sources !== []) $parts[] = $name . ' ' . implode(' ', array_unique(array_map('strval', $sources)));
    $parts[] = 'report-uri ' . url('/csp-report');
    header(($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only') . ': ' . implode('; ', $parts));
}

/** A <script> tag that passes the CSP: inline code or an external src. Plugins use this instead of writing the tag by hand. */
function script_tag(string $inline = '', string $src = '', array $attr = []): string
{
    $a = '';
    foreach ($attr as $k => $v) $a .= ' ' . h((string)$k) . ($v === true ? '' : '="' . h((string)$v) . '"');
    if ($src !== '') return '<script src="' . h($src) . '"' . $a . '></script>';
    return '<script nonce="' . h(csp_nonce()) . '"' . $a . '>' . $inline . '</script>';
}

/** POST /csp-report (no CSRF: sent by the browser). Appends one line per report to data/csp-report.log, capped at 512 KB. */
function csp_report_handle(): never
{
    $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
    $r = json_decode_array($raw);
    $r = (array)($r['csp-report'] ?? $r);
    if ($r !== []) {
        $line = json_encode_value(['at' => date('Y-m-d H:i:s'), 'ip' => client_ip(), 'page' => cut((string)($r['document-uri'] ?? ''), 200, ''), 'directive' => cut((string)($r['effective-directive'] ?? ($r['violated-directive'] ?? '')), 80, ''), 'blocked' => cut((string)($r['blocked-uri'] ?? ''), 200, ''), 'source' => cut((string)($r['source-file'] ?? ''), 200, '') . ':' . (int)($r['line-number'] ?? 0)]);
        $f = csp_report_file();
        if (is_file($f) && filesize($f) > 524288) { $keep = (string)file_get_contents($f, false, null, (int)filesize($f) - 262144); file_put_contents($f, substr($keep, (int)strpos($keep, "\n") + 1)); }
        @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    http_response_code(204);
    exit;
}

function csp_report_file(): string
{
    return DATA_DIR . '/csp-report.log';
}

/** The last $n CSP reports, newest first. */
function csp_reports(int $n = 20): array
{
    $f = csp_report_file();
    if (!is_file($f)) return [];
    $lines = array_filter(explode("\n", (string)file_get_contents($f, false, null, max(0, (int)filesize($f) - 65536))));
    $out = [];
    foreach (array_reverse($lines) as $l) { $r = json_decode_array($l); if ($r !== []) $out[] = $r; if (count($out) >= $n) break; }
    return $out;
}

/* ---------------------------------------------------------------- real client IP behind proxies */

/** Cloudflare's published ranges (setting trusted_proxies = "cloudflare"). */
function cloudflare_ranges(): array
{
    return ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
}

/** Ranges from the trusted_proxies setting: "cloudflare" and/or IPs and CIDRs separated by commas or spaces. */
function trusted_proxy_ranges(): array
{
    return request_cache('trusted_proxies', static function (): array {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim(setting('trusted_proxies', '')))) ?: [] as $e) {
            if ($e === '') continue;
            if ($e === 'cloudflare') { $out = array_merge($out, cloudflare_ranges()); continue; }
            $out[] = $e;
        }
        return $out;
    }) ?? [];
}

/** Whether an IPv4/IPv6 address lies in a CIDR range (a bare address means /32 or /128). */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string)$net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $max = strlen($a) * 8;
    $bits = $bits === null || $bits === '' ? $max : (int)$bits;
    if ($bits < 0 || $bits > $max) return false;
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
}

function ip_in_ranges(string $ip, array $ranges): bool
{
    foreach ($ranges as $r) if (ip_in_cidr($ip, (string)$r)) return true;
    return false;
}

/** The visitor's address: REMOTE_ADDR, or the forwarded address when the request came through a trusted proxy. */
function client_ip_resolve(array $server): string
{
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return '0.0.0.0';
    $ranges = trusted_proxy_ranges();
    if ($ranges === [] || !ip_in_ranges($remote, $ranges)) return $remote;
    $cf = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $xff = trim((string)strtok((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''), ','));
    return $xff !== '' && filter_var($xff, FILTER_VALIDATE_IP) ? $xff : $remote;
}

/* ---------------------------------------------------------------- admin action log */

/** Record an admin action (who, real IP, what, on which object). Fires admin.action for plugins. */
function admin_log(string $action, string $target = '', string $detail = ''): void
{
    $row = ['user_id' => uid(), 'ip' => cut(client_ip(), 45, ''), 'action' => cut($action, 40, ''), 'target' => cut($target, 120, ''), 'detail' => cut($detail, 500, ''), 'created_at' => now()];
    try { db_insert('fb_admin_log', $row); } catch (Throwable) { /* the table appears with the schema upgrade */ }
    fire('admin.action', $row);
}

/** Last $n admin actions with the acting users loaded. */
function admin_log_recent(int $n = 20): array
{
    try { $rows = all('SELECT * FROM fb_admin_log ORDER BY id DESC LIMIT ' . (int)$n); } catch (Throwable) { return []; }
    $users = users_by_ids(array_column($rows, 'user_id'));
    foreach ($rows as &$r) $r['user'] = $users[(int)$r['user_id']] ?? null;
    return $rows;
}

/* ---------------------------------------------------------------- confirm mode (sudo) */

const SUDO_TTL = 600;

function sudo_signature(array $user, int $exp): string
{
    return hash_hmac('sha256', (int)$user['id'] . '.' . $exp . '.sudo', secret() . (string)$user['password']);
}

/** Whether the current admin confirmed the password within the last ten minutes. */
function sudo_ok(): bool
{
    $me = me();
    $raw = (string)($_COOKIE['fb_sudo'] ?? '');
    if ($me === null || substr_count($raw, '.') !== 2) return false;
    [$id, $exp, $sig] = explode('.', $raw);
    if (!ctype_digit($id) || !ctype_digit($exp) || (int)$id !== (int)$me['id'] || (int)$exp < now()) return false;
    return hash_equals(sudo_signature($me, (int)$exp), $sig);
}

/** Remember a successful password confirmation for SUDO_TTL seconds. */
function sudo_grant(array $user): void
{
    $exp = now() + SUDO_TTL;
    app_cookie('fb_sudo', (int)$user['id'] . '.' . $exp . '.' . sudo_signature($user, $exp), $exp);
}

/**
 * Require a fresh password confirmation for high-risk admin pages: on GET the admin is sent to the confirm page and
 * comes back afterwards; a POST without confirmation is refused so a riding script cannot change anything.
 */
function need_sudo(): void
{
    if (sudo_ok()) return;
    $back = current_path() . (($q = (string)($_SERVER['QUERY_STRING'] ?? '')) !== '' ? '?' . $q : '');
    if (is_post()) fail(t('Please confirm your password first.'), admin_url('confirm', ['back' => $back]));
    redirect(admin_url('confirm', ['back' => $back]));
}

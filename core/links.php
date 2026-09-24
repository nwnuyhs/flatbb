<?php
/**
 * Link previews: the title, description and picture of a page a post links to, shown as a card — for a link on a line
 * of its own in a post, and for lists that want one (X Style: the first link of an opening post).
 *
 * Showing a card never fetches anything: previews live in fb_link_previews. A link seen for the first time is queued
 * (status 0) and fetched after the response is sent (PHP-FPM) or by the scheduled job core.link_previews, then kept for
 * seven days. The fetch is the only place the forum requests an address a member typed, so it is strict: http(s) on the
 * standard ports only, every address the name resolves to must be public (no loopback, private, link-local or reserved
 * network), the connection is pinned to the checked address, at most three redirects each checked the same way, five
 * seconds, 512 KB, HTML only.
 */
if (!defined('FLATBB')) exit;

define('LINK_TTL', 7 * 86400);
define('LINK_MAX_BYTES', 512 * 1024);

function link_previews_on(): bool
{
    return setting('link_preview', '1') === '1';
}

/** The key of a URL: trimmed, without its fragment. */
function link_hash(string $url): string
{
    $url = trim($url);
    $cut = strpos($url, '#');
    return sha1($cut === false ? $url : substr($url, 0, $cut));
}

/**
 * Whether a URL may get a card: http(s) to another site, not a picture, a video, a file or a video site (those have their
 * own players), not on the blocked list. Filter link.previewable (bool; ctx url, host) lets a plugin take a link over.
 */
function link_previewable(string $url): bool
{
    if (strlen($url) > 2000 || !preg_match('~^https?://~i', $url)) return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === strtolower((string)parse_url(base_url(), PHP_URL_HOST))) return false;
    if (preg_match('~\.(jpe?g|png|gif|webp|avif|svg|bmp|ico|mp4|webm|mov|m4v|mp3|m4a|ogg|wav|pdf|zip|rar|7z|gz|exe|dmg|apk)$~i', (string)parse_url($url, PHP_URL_PATH))) return false;
    $bare = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
    if (in_array($bare, ['youtube.com', 'youtu.be', 'bilibili.com', 'b23.tv', 'vimeo.com'], true)) return false;
    foreach (preg_split('/[\s,]+/', strtolower(setting('link_preview_block', ''))) ?: [] as $d) {
        $d = trim($d, ". \t");
        if ($d !== '' && ($host === $d || str_ends_with($host, '.' . $d))) return false;
    }
    return (bool)hook('link.previewable', true, ['url' => $url, 'host' => $host]);
}

/** The links that stand in a paragraph of their own in rendered post HTML, in order, at most $max. */
function link_standalone(string $html, int $max = 3): array
{
    if (!preg_match_all('~<p><a href="(https?://[^"]+)"[^>]*>[^<]*</a></p>~i', $html, $m)) return [];
    $out = [];
    foreach ($m[1] as $href) {
        $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!in_array($url, $out, true) && link_previewable($url)) $out[] = $url;
        if (count($out) === $max) break;
    }
    return $out;
}

/**
 * The stored previews of these URLs that are ready, url => row, in one query; the ones never seen are queued in one
 * insert and fetched after this request (or by the scheduled job). Lists and topic pages call it once per page.
 */
function link_previews(array $urls, bool $queue = true): array
{
    if (!link_previews_on()) return [];
    $by = [];
    foreach ($urls as $u) if (is_string($u) && $u !== '') $by[link_hash($u)] = $u;
    if ($by === []) return [];
    $out = $seen = [];
    foreach (all('SELECT * FROM fb_link_previews WHERE url_hash IN (' . sql_marks(count($by)) . ')', array_keys($by)) as $r) {
        $seen[(string)$r['url_hash']] = true;
        if ((int)$r['status'] === 1) $out[$by[(string)$r['url_hash']]] = $r;
    }
    $new = array_slice(array_diff_key($by, $seen), 0, 20, true);
    if ($queue && $new !== []) {
        $params = [];
        foreach ($new as $hash => $u) array_push($params, $hash, $u, now());
        try {
            q('INSERT INTO fb_link_previews (url_hash, url, status, created_at) VALUES ' . implode(',', array_fill(0, count($new), '(?, ?, 0, ?)')), $params);
            link_fetch_soon();
        } catch (Throwable) {
            // another request queued one of them a moment ago: the scheduled job fetches whatever is pending
        }
    }
    return $out;
}

/** Queue the stand-alone links of a post that was just written, so its cards are ready before anyone opens it. */
function link_queue_post(string $html): void
{
    if (link_previews_on()) link_previews(link_standalone($html), true);
}

/** After the response is sent (PHP-FPM), fetch what this request queued; elsewhere the scheduled job does it. */
function link_fetch_soon(): void
{
    if (request_cache('link_fetch_soon') === true || !function_exists('fastcgi_finish_request')) return;
    request_cache('link_fetch_soon', static fn(): bool => true);
    register_shutdown_function(static function (): void {
        fastcgi_finish_request();
        link_fetch_pending(5);
    });
}

/** Scheduled job core.link_previews: fetch pending links, retry failed ones (up to three tries a day apart), refresh old ones. */
function link_fetch_job(): string
{
    return link_previews_on() ? link_fetch_pending(15) . ' fetched' : 'off';
}

function link_fetch_pending(int $limit): int
{
    $rows = all('SELECT id, url, tries FROM fb_link_previews WHERE status=0 OR (status=2 AND tries<3 AND fetched_at<?) OR (status=1 AND fetched_at<?) ORDER BY status ASC, id ASC LIMIT ' . max(1, $limit), [now() - 86400, now() - LINK_TTL]);
    $done = 0;
    foreach ($rows as $r) {
        $p = link_previewable((string)$r['url']) ? link_fetch((string)$r['url']) : null;
        $data = $p !== null
            ? ['status' => 1, 'title' => $p['title'], 'description' => $p['description'], 'image' => $p['image'], 'site_name' => $p['site_name'], 'card' => $p['card'], 'tries' => 0, 'fetched_at' => now()]
            : ['status' => 2, 'tries' => (int)$r['tries'] + 1, 'fetched_at' => now()];
        db_update('fb_link_previews', $data, 'id=?', [(int)$r['id']]);
        $done++;
    }
    return $done;
}

/** Fetch and read one page: ['title', 'description', 'image', 'site_name', 'card' large|small|text] or null. */
function link_fetch(string $url): ?array
{
    $page = link_http_get($url);
    return $page === null ? null : link_parse($page['body'], $page['url'], $page['type']);
}

/** A public address: not loopback, private (RFC 1918, fc00::/7), link-local, CGNAT, reserved or IPv4 mapped in IPv6. */
function link_public_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    return !preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|192\.0\.0\.|198\.1[89]\.|::ffff:|fe[89ab][0-9a-f]:|f[cd][0-9a-f]{2}:)/i', $ip);
}

/** The one address to connect to for a host, when every address it resolves to is public; null otherwise. */
function link_resolve(string $host): ?string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return link_public_ip($host) ? $host : null;
    if (!preg_match('/^[a-z0-9.-]+$/i', $host) || !str_contains($host, '.')) return null;
    $ips = @gethostbynamel($host) ?: [];
    foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
    if ($ips === []) return null;
    foreach ($ips as $ip) if (!link_public_ip($ip)) return null; // one private answer is enough to refuse: the name may switch to it
    foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
    return $ips[0];
}

/** GET a page with every check in the file header: ['url' => final url, 'type' => content type, 'body' => up to 512 KB] or null. */
function link_http_get(string $url, int $hops = 0): ?array
{
    if (!function_exists('curl_init')) return null;
    $p = parse_url($url);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = (string)($p['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) return null;
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) return null;
    $ip = link_resolve($host);
    if ($ip === null) return null;
    $body = '';
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)], // connect to the address that was checked, nothing else
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FlatBB-LinkPreview/' . FLATBB_VERSION . ')',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1', 'Accept-Language: ' . (lang_code() ?: 'en') . ',en;q=0.8'],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
            $body .= $chunk;
            return strlen($body) > LINK_MAX_BYTES ? 0 : strlen($chunk); // enough for the head of any page: stop reading
        },
    ]);
    if (PHP_OS_FAMILY === 'Windows' && (string)ini_get('curl.cainfo') === '' && (string)ini_get('openssl.cafile') === '') curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 16);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status >= 300 && $status < 400 && isset($headers['location']) && $hops < 3) {
        $next = link_absolute($headers['location'], $url);
        return $next !== '' ? link_http_get($next, $hops + 1) : null;
    }
    $type = strtolower((string)($headers['content-type'] ?? ''));
    if ($status < 200 || $status >= 300 || $body === '' || !preg_match('~text/html|application/xhtml~', $type)) return null;
    return ['url' => $url, 'type' => $type, 'body' => substr($body, 0, LINK_MAX_BYTES)];
}

/** An address from a page made absolute against the page's own; '' unless it ends up http(s). */
function link_absolute(string $ref, string $base): string
{
    $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($ref === '' || preg_match('/[\x00-\x1f\s]/', $ref)) return '';
    if (preg_match('~^https?://~i', $ref)) return $ref;
    $b = parse_url($base);
    $origin = strtolower((string)($b['scheme'] ?? 'https')) . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($ref, '//')) return strtolower((string)($b['scheme'] ?? 'https')) . ':' . $ref;
    if (str_starts_with($ref, '/')) return $origin . $ref;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref)) return ''; // javascript:, data: and the like
    $dir = preg_replace('~/[^/]*$~', '/', (string)($b['path'] ?? '/')) ?: '/';
    return $origin . $dir . $ref;
}

/** Read the card from a page's HTML: Open Graph first, then Twitter cards, then the plain title and description. */
function link_parse(string $html, string $url, string $type = ''): ?array
{
    $cut = stripos($html, '</head>');
    $head = $cut !== false ? substr($html, 0, $cut) : substr($html, 0, 200000);
    $charset = '';
    if (preg_match('/charset=["\']?([\w-]+)/i', $type . ' ' . $head, $m)) $charset = strtoupper($m[1]);
    $charset = ['GB2312' => 'GB18030', 'GBK' => 'GB18030', 'X-GBK' => 'GB18030'][$charset] ?? $charset; // pages that say gb2312 or gbk often use the whole superset
    $converted = false;
    if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
        try { $head = (string)mb_convert_encoding($head, 'UTF-8', $charset); $converted = true; } catch (ValueError) {} // a charset PHP does not know: read it as UTF-8
    }
    if (!$converted && !mb_check_encoding($head, 'UTF-8')) $head = (string)mb_convert_encoding($head, 'UTF-8', 'UTF-8');
    $meta = [];
    if (preg_match_all('~<meta\s[^>]*>~i', $head, $tags)) {
        foreach ($tags[0] as $tag) {
            if (!preg_match_all('~([a-z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $tag, $a, PREG_SET_ORDER)) continue;
            $attr = [];
            foreach ($a as $x) $attr[strtolower($x[1])] = $x[2] !== '' ? $x[2] : ($x[3] ?? '') . ($x[4] ?? '');
            $key = strtolower((string)($attr['property'] ?? $attr['name'] ?? ''));
            if ($key !== '' && isset($attr['content']) && !isset($meta[$key])) $meta[$key] = $attr['content'];
        }
    }
    $clean = static fn(string $s, int $max): string => cut(trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? ''), $max);
    $pick = static function (array $keys) use ($meta): string { foreach ($keys as $k) if (trim((string)($meta[$k] ?? '')) !== '') return (string)$meta[$k]; return ''; }; // the first one a page actually fills
    $plain = preg_match('~<title[^>]*>(.*?)</title>~is', $head, $m) ? $m[1] : '';
    $title = $clean($pick(['og:title', 'twitter:title']) ?: $plain, 200);
    if ($title === '') return null;
    $image = link_absolute($pick(['og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src']), $url);
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST))) ?? '';
    $large = strtolower((string)($meta['twitter:card'] ?? '')) === 'summary_large_image' || (int)($meta['og:image:width'] ?? 0) >= 600;
    return [
        'title' => $title,
        'description' => $clean($pick(['og:description', 'twitter:description', 'description']), 300),
        'image' => cut($image, 1000, ''),
        'site_name' => $clean($pick(['og:site_name']) ?: $host, 80) ?: $host,
        'card' => $image === '' ? 'text' : ($large ? 'large' : 'small'),
    ];
}

/** The card: a link to the page with its picture (when it has one), its site, title and description. Everything escaped. */
function link_card_html(array $p, string $url): string
{
    $card = in_array($p['card'] ?? '', ['large', 'small', 'text'], true) ? (string)$p['card'] : 'text';
    $image = (string)($p['image'] ?? '');
    if ($image === '' || !preg_match('~^https?://~i', $image)) $card = 'text';
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST))) ?? '';
    return '<a class="link-card link-card-' . $card . '" href="' . h($url) . '" target="_blank" rel="nofollow ugc noopener">'
        . ($card !== 'text' ? '<span class="link-card-img"><img src="' . h($image) . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"></span>' : '')
        . '<span class="link-card-meta"><span class="link-card-site">' . icon('link') . h((string)($p['site_name'] ?? '') !== '' ? (string)$p['site_name'] : $host) . '</span>'
        . '<span class="link-card-title">' . h((string)($p['title'] ?? '')) . '</span>'
        . ((string)($p['description'] ?? '') !== '' ? '<span class="link-card-desc">' . h((string)$p['description']) . '</span>' : '') . '</span></a>';
}

/** The topic page: a link on a line of its own becomes its card (three per post), the cards of the whole page in one query. */
function link_cards_in_posts(array $posts): array
{
    if (!link_previews_on() || setting('link_preview_posts', '1') !== '1') return $posts;
    $want = [];
    foreach ($posts as $i => $p) if (empty($p['is_deleted'])) $want[$i] = link_standalone((string)($p['body_html'] ?? ''));
    $cards = link_previews(array_merge([], ...array_values($want)));
    if ($cards === []) return $posts;
    foreach ($want as $i => $urls) {
        if ($urls === []) continue;
        $posts[$i]['body_html'] = preg_replace_callback('~<p><a href="(https?://[^"]+)"[^>]*>[^<]*</a></p>~i', static function (array $m) use ($cards): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return isset($cards[$url]) ? link_card_html($cards[$url], $url) : $m[0];
        }, (string)$posts[$i]['body_html']) ?? (string)$posts[$i]['body_html'];
    }
    return $posts;
}

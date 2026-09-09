<?php
/**
 * Market — licences on this forum. Loaded by plugin.php.
 *
 * A licence key from the marketplace is activated on this forum (Marketplace → Browse when a paid plugin is installed, or Marketplace → Licences): the marketplace
 * binds this site to it and returns a signed token, which is verified offline with the marketplace's public key
 * (fetched once and pinned). A daily job refreshes the token; when the marketplace cannot be reached the last token
 * keeps counting for 14 days. Plugins ask market_entitled('product') — a paid plugin id, 'commercial' or 'support'.
 * Nothing here runs unless a key was entered: free plugins and forums without a licence never touch it.
 */
if (!defined('FLATBB')) exit;

const MARKET_LICENSE_GRACE_DAYS = 14;

/* ---------------------------------------------------------------- storage */

/** Licences held by this site, keyed by product: [product => ['key','token','claims','checked_at','error']]. */
function market_licenses(): array
{
    return request_cache('market_licenses', static fn(): array => json_decode_array(setting('market_licenses', ''))) ?? [];
}

function market_licenses_save(array $list): void
{
    save_settings(['market_licenses' => $list === [] ? '' : json_encode_value($list)]);
    request_cache('market_licenses', null, true);
}

/** What the marketplace signs against: the same digest it computes from the X-Flatbb-Site header. */
function market_site_id(): string
{
    return sha1(strtolower(rtrim(base_url(), '/')));
}

/* ---------------------------------------------------------------- talking to the marketplace */

/** POST form fields to a marketplace URL. Returns the decoded JSON (ok or error) or ['ok' => false, 'error' => …] when unreachable. */
function market_http_post(string $url, array $fields): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl extension missing'];
    if (http_self_request_blocked($url)) return ['ok' => false, 'error' => 'self request skipped on the dev server'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], market_site_headers())]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) return ['ok' => false, 'error' => $err !== '' ? $err : 'unreachable'];
    $d = json_decode_array($body);
    return isset($d['ok']) ? $d : ['ok' => false, 'error' => 'bad response'];
}

/** The marketplace's public key, fetched once and kept (trust on first contact); '' when it cannot be had. */
function market_license_pubkey(bool $refresh = false): string
{
    $pem = (string)setting('market_license_pubkey', '');
    if ($pem !== '' && !$refresh) return $pem;
    $body = market_http_get(market_endpoint() . '/license/pubkey', 65536, $error);
    $pem = (string)(json_decode_array((string)$body)['public_key'] ?? '');
    if (!str_starts_with($pem, '-----BEGIN PUBLIC KEY-----')) return (string)setting('market_license_pubkey', '');
    save_settings(['market_license_pubkey' => $pem]);
    return $pem;
}

/** The claims of a token, or null when the signature, the site or the shape is wrong. */
function market_license_verify(string $token): ?array
{
    if (!function_exists('openssl_verify')) return null;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    $b64 = static fn(string $s): string|false => base64_decode(strtr($s, '-_', '+/'), true);
    $sig = $b64($parts[1]);
    $pem = market_license_pubkey();
    if ($sig === false || $pem === '' || openssl_verify($parts[0], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;
    $claims = json_decode_array((string)$b64($parts[0]));
    if ((string)($claims['site'] ?? '') !== market_site_id() || (string)($claims['product'] ?? '') === '') return null;
    return $claims;
}

/** Activate a key for this site. Returns ['ok' => bool, 'message' => …]. */
function market_license_activate(string $key): array
{
    $key = strtoupper(trim($key));
    if (!preg_match('/^FB-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $key)) return ['ok' => false, 'message' => t('That does not look like a licence key (FB-XXXX-XXXX-XXXX-XXXX).')];
    $r = market_http_post(market_endpoint() . '/license/activate', ['key' => $key]);
    if (empty($r['ok'])) return ['ok' => false, 'message' => t('The marketplace refused the key: %s', (string)($r['error'] ?? 'unknown error'))];
    $claims = market_license_verify((string)($r['token'] ?? ''));
    if ($claims === null) return ['ok' => false, 'message' => t('The marketplace answered, but its signature could not be verified. Try again in a minute.')];
    $list = market_licenses();
    $list[(string)$claims['product']] = ['key' => $key, 'token' => (string)$r['token'], 'claims' => $claims, 'checked_at' => now(), 'error' => ''];
    market_licenses_save($list);
    return ['ok' => true, 'product' => (string)$claims['product'], 'message' => t('Licence for %s activated on this site.', (string)$claims['product'])];
}

/** Refresh one licence from the marketplace; the entry is updated in place. Returns true when the marketplace answered. */
function market_license_refresh(string $product): bool
{
    $list = market_licenses();
    if (!isset($list[$product])) return false;
    $r = market_http_post(market_endpoint() . '/license/check', ['key' => (string)$list[$product]['key']]);
    if (!empty($r['ok'])) {
        $claims = market_license_verify((string)($r['token'] ?? ''));
        if ($claims !== null) $list[$product] = ['key' => (string)$list[$product]['key'], 'token' => (string)$r['token'], 'claims' => $claims, 'checked_at' => now(), 'error' => ''];
        else $list[$product]['error'] = 'bad signature';
    } elseif (in_array((string)($r['status'] ?? ''), ['revoked', 'unbound', 'expired'], true)) {
        $list[$product]['claims']['status'] = (string)$r['status']; // the marketplace said no: remember why, keep the key so the admin sees it
        $list[$product]['checked_at'] = now();
        $list[$product]['error'] = (string)($r['error'] ?? '');
    } else {
        $list[$product]['error'] = (string)($r['error'] ?? 'unreachable'); // network trouble: the last token stands for the grace period
    }
    market_licenses_save($list);
    return !empty($r['ok']) || isset($r['status']);
}

/** Free this site's seat on the marketplace and forget the licence here. */
function market_license_deactivate(string $product): void
{
    $list = market_licenses();
    if (!isset($list[$product])) return;
    market_http_post(market_endpoint() . '/license/deactivate', ['key' => (string)$list[$product]['key']]); // best effort: the seat can also be freed under My licences
    unset($list[$product]);
    market_licenses_save($list);
}

/** Daily: refresh every licence. */
function market_license_cron(): void
{
    foreach (array_keys(market_licenses()) as $product) market_license_refresh($product);
}

/* ---------------------------------------------------------------- what plugins ask */

/**
 * Whether this site holds a licence for a product ('commercial', 'support', or a paid plugin id).
 * True while the licence is active — and after it expired (features keep working; only updates stop). False when
 * there is no licence, it was revoked or unbound, or the marketplace could not confirm it for 14 days.
 */
function market_entitled(string $product): bool
{
    $l = market_licenses()[$product] ?? null;
    if ($l === null) return false;
    $status = (string)($l['claims']['status'] ?? '');
    if (!in_array($status, ['active', 'expired'], true)) return false;
    return now() - (int)($l['checked_at'] ?? 0) < 86400 * MARKET_LICENSE_GRACE_DAYS;
}

/** The claims behind a licence (product, plan, host, expires, status, hint) or null. */
function market_license_info(string $product): ?array
{
    $l = market_licenses()[$product] ?? null;
    return $l !== null ? (array)$l['claims'] + ['checked_at' => (int)$l['checked_at'], 'error' => (string)$l['error']] : null;
}

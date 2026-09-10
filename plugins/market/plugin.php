<?php
/**
 * Market — browse, install and update plugins from the flatbb marketplace at www.flatbb.com,
 * and publish your own plugins from Admin → Plugins.
 */
if (!defined('FLATBB')) exit;

const MARKET_CACHE_TTL = 900;

require_once __DIR__ . '/admin.php';

function market_endpoint(): string
{
    return rtrim((string)config('market_endpoint', FLATBB_MARKET_ENDPOINT), '/');
}

/** Identifies this forum to the marketplace (install statistics). */
function market_site_headers(): array
{
    return ['X-Flatbb-Site: ' . base_url(), 'X-Flatbb-Version: ' . FLATBB_VERSION];
}

/** GET a URL with curl (timeouts, size cap). Returns body or null. */
function market_http_get(string $url, int $max_bytes = 20971520, ?string &$error = null, array $headers = []): ?string
{
    if (!function_exists('curl_init')) { $error = 'curl extension missing'; return null; }
    if (http_self_request_blocked($url)) { $error = 'self request skipped on the dev server'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, application/zip'], market_site_headers(), $headers)]);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn($r, $dl_total, $dl): int => $dl > $max_bytes ? 1 : 0);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    $body = http_exec_prefer_local($ch, $url);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        // the marketplace explains a refusal in JSON (a plugin that costs points and is not yours, a gated download): pass that on
        $said = is_string($body) ? (string)(json_decode_array($body)['error'] ?? '') : '';
        $error = $said !== '' ? $said : ($err !== '' ? $err : 'HTTP ' . $status);
        return null;
    }
    return (string)$body;
}

/** Marketplace listing, cached for 15 minutes in data/cache/market_list.json. */
function market_list(bool $refresh = false, string $q = ''): array
{
    $file = CACHE_DIR . '/market_list.json';
    if (!$refresh && $q === '' && is_file($file) && now() - (int)filemtime($file) < MARKET_CACHE_TTL) {
        $d = json_decode_array((string)file_get_contents($file));
        if ($d !== []) return $d;
    }
    $body = market_http_get(market_endpoint() . '/plugins' . ($q !== '' ? '?q=' . rawurlencode($q) : ''), 2097152, $error);
    if ($body === null) return ['error' => $error ?? 'unreachable', 'plugins' => [], 'core' => []];
    $d = json_decode_array($body);
    if (!isset($d['plugins'])) return ['error' => 'bad response', 'plugins' => [], 'core' => []];
    if ($q === '') @file_put_contents($file, $body, LOCK_EX);
    return $d;
}

/** "Authorization: Bearer …" for the connected account, or [] when none is connected. */
function market_auth_headers(): array
{
    $token = market_token();
    return $token !== '' ? ['Authorization: Bearer ' . $token] : [];
}

/** POST form fields to a marketplace API path with the account token. The decoded JSON, or ['ok' => false, 'error' => …]. */
function market_api_post(string $path, array $fields): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl extension missing'];
    $url = market_endpoint() . $path;
    if (http_self_request_blocked($url)) return ['ok' => false, 'error' => 'self request skipped on the dev server'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], market_site_headers(), market_auth_headers())]);
    $body = http_exec_prefer_local($ch, $url);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) return ['ok' => false, 'error' => $err !== '' ? $err : 'unreachable'];
    $d = json_decode_array($body);
    return isset($d['ok']) ? $d : ['ok' => false, 'error' => 'bad response'];
}

/** Get a plugin for points with the connected account. ['ok' => bool, 'message' => …]; the account cache is refreshed. */
function market_buy(string $id): array
{
    if (market_token() === '') return ['ok' => false, 'message' => t('Connect your www.flatbb.com account first (Marketplace → Account).')];
    $r = market_api_post('/buy', ['id' => $id]);
    if (!empty($r['ok'])) market_account(true);
    return ['ok' => !empty($r['ok']), 'message' => (string)($r['message'] ?? $r['error'] ?? '')];
}

/** Switch a freshly installed plugin on (an update keeps its state); answers the message to show. */
function market_enable_after_install(string $id, string $version): string
{
    if (plugin_enabled($id)) return t('%s %s installed.', $id, $version);
    try { plugin_enable($id); return t('%s %s installed and enabled.', $id, $version); }
    catch (Throwable $e) { return t('%s %s installed, but it could not be enabled: %s', $id, $version, $e->getMessage()); }
}

/** Download a plugin package from the marketplace and install it. Returns the installed version. */
function market_install(string $id, string $version = ''): string
{
    if (!plugin_id_valid($id)) throw new RuntimeException('Invalid plugin id');
    $url = market_endpoint() . '/plugins/' . $id . '/download' . ($version !== '' ? '?version=' . rawurlencode($version) : '');
    $zip_body = market_http_get($url, 20971520, $error, market_auth_headers()); // a plugin that costs points is released to the connected account
    if ($zip_body === null) throw new RuntimeException('Download failed: ' . $error);
    $tmp = CACHE_DIR . '/market_' . $id . '_' . random_token(4) . '.zip';
    file_put_contents($tmp, $zip_body, LOCK_EX);
    try {
        $installed = plugin_install_zip($tmp);
    } finally {
        @unlink($tmp);
    }
    if ($installed !== $id) throw new RuntimeException('Package id mismatch: ' . $installed);
    $m = plugin_read_manifest($id);
    fire('market.after_install', ['id' => $id, 'version' => (string)($m['version'] ?? '')]);
    return (string)($m['version'] ?? '');
}

/** Ids the marketplace runs itself or ships as an example: it refuses them, so no Publish button. */
function market_reserved_ids(): array
{
    return ['market', 'market_server', 'hello', 'quality'];
}

/** "Publish" button on Admin → Plugins rows (the form asks for the token the first time). */
function market_plugin_ops(string $ops, array $ctx): string
{
    $id = (string)($ctx['plugin']['id'] ?? '');
    if ($id === '' || in_array($id, market_reserved_ids(), true)) return $ops;
    return $ops . '<a class="btn btn-sm" href="' . h(url('/admin/ext/market/publish', ['id' => $id])) . '">' . icon('upload') . t('Publish') . '</a>';
}

/** GET: the publish form (changelog, screenshots, the token the first time). POST: package and upload. */
/** The marketplace account behind a token: username, is_admin, points, purchased (plugin ids); [] when it cannot be asked. */
function market_whoami(string $token): array
{
    $token = trim($token);
    if ($token === '' || !function_exists('curl_init')) return [];
    $url = market_endpoint() . '/whoami';
    if (http_self_request_blocked($url)) return [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], market_site_headers())]);
    $body = http_exec_prefer_local($ch, $url);
    curl_close($ch);
    $d = is_string($body) ? json_decode_array($body) : [];
    return !empty($d['ok']) && isset($d['username']) ? ['username' => (string)$d['username'], 'is_admin' => !empty($d['is_admin']), 'points' => (int)($d['points'] ?? 0), 'purchased' => array_values(array_map('strval', (array)($d['purchased'] ?? [])))] : [];
}

/**
 * Whether publishing $id from this forum means publishing someone else's plugin: ['confirm' => bool, 'text' => …] or null when it is
 * plainly the admin's own. Compares the marketplace account behind the token with the id's publisher (or, for a new id, the manifest author).
 */
function market_publish_other(string $id, array $m, string $token): ?array
{
    $listed = market_cached_plugin($id);
    $me = market_whoami($token);
    $mine = (string)($me['username'] ?? '');
    $author = trim((string)(plugin_read_manifest($id)['author'] ?? $m['author'] ?? '')); // the registry row has no author; the manifest file does
    if ($listed !== null) {
        $publisher = (string)($listed['publisher'] ?? '');
        if ($mine !== '' && $publisher !== '' && strcasecmp($publisher, $mine) === 0) return null;
        if ($mine !== '' && $publisher !== '') return ['confirm' => true, 'text' => t('On the marketplace, %1$s is published by %2$s, not by your account (%3$s).', $id, $publisher, $mine) . ' '
            . (!empty($me['is_admin']) ? t('Your account is a marketplace administrator, so this would go through and replace their published version.') : t('The marketplace refuses this unless you are one of its administrators.'))];
        $shown = $publisher !== '' ? $publisher : (string)($listed['author'] ?? '');
        return $shown !== '' && ($mine === '' || strcasecmp($shown, $mine) !== 0)
            ? ['confirm' => true, 'text' => t('%1$s is already on the marketplace, listed under %2$s. Publishing a version of a plugin that is not yours replaces theirs.', $id, $shown)]
            : null;
    }
    if ($author === '' || ($mine !== '' && strcasecmp($author, $mine) === 0)) return null;
    return $mine !== ''
        ? ['confirm' => true, 'text' => t('The manifest names %1$s as the author, and your marketplace account is %2$s. Publish only plugins you wrote or were given permission to publish.', $author, $mine)]
        : ['confirm' => false, 'text' => t('The manifest names %s as the author. The marketplace could not be asked whose account your token is, so make sure this plugin is yours to publish.', $author)];
}

function market_admin_publish(string $page): never
{
    need_admin();
    $id = is_post() ? post_str('id', 40) : get_str('id', 40);
    if (!isset(plugins()[$id])) fail(t('Plugin not found.'), url('/admin/plugins'));
    if (in_array($id, market_reserved_ids(), true)) fail(t('%s is part of the marketplace itself and cannot be published.', $id), url('/admin/plugins'));
    $saved = market_token();
    if (is_post()) {
        $pasted = trim(post_str('token', 120));
        if ($pasted !== '') plugin_save_settings('market', ['token' => $pasted] + plugin_settings('market')); // pasted once, kept in the plugin settings
        $token = $pasted !== '' ? $pasted : $saved;
        if ($token === '') fail(t('Publishing to the marketplace needs a developer token.'), url('/admin/ext/market/publish', ['id' => $id]));
        $confirm = post_str('confirm_other', 2) === '1';
        $other = market_publish_other($id, plugins()[$id], $token);
        if ($other !== null && $other['confirm'] && !$confirm) fail(t('Tick the confirmation first: you are about to publish someone else\'s plugin.'), url('/admin/ext/market/publish', ['id' => $id]));
        $shots = array_map(static fn(array $f): array => ['path' => $f['tmp_name'], 'name' => $f['name']], upload_files_list('images'));
        $r = plugin_publish($id, $token, post_str('changelog', 2000), '', false, $shots, $confirm);
        flash($r['message'] . (!empty($r['url']) ? ' ' . $r['url'] : ''), $r['ok'] ? 'success' : 'error');
        redirect(url('/admin/plugins'));
    }
    $m = plugins()[$id];
    $body = '<form method="post" action="' . h(url('/admin/ext/market/publish')) . '" enctype="multipart/form-data" class="admin-form">' . csrf_field() . '<input type="hidden" name="id" value="' . h($id) . '">'
        . '<p class="muted">' . t('%s %s is packaged from plugins/%s and uploaded to the marketplace. Publishing may take a minute: the marketplace checks the package before it answers.', h((string)$m['name']), h((string)$m['version']), h($id)) . '</p>';
    if ($saved === '') {
        $body .= '<p class="muted">' . t('Publishing needs the token of your www.flatbb.com account. Create one at %s (Settings → Developer), paste it here once; it is kept under Marketplace → Account (or click Connect there).', '<a href="https://www.flatbb.com/settings/developer" target="_blank" rel="noopener">www.flatbb.com</a>') . '</p>'
            . form_row(t('Developer token'), input('token', '', ['required' => true, 'maxlength' => 120, 'autofocus' => true, 'autocomplete' => 'off', 'placeholder' => 'fbk_…']));
    }
    $other = $saved !== '' ? market_publish_other($id, $m, $saved) : null;
    if ($other !== null) {
        $body .= '<div class="market-other' . ($other['confirm'] ? ' market-other-stop' : '') . '">' . icon('alert') . '<div><p>' . h($other['text']) . '</p>'
            . ($other['confirm'] ? checkbox('confirm_other', false, t('Yes, publish %s although it is someone else\'s plugin', $id)) : '') . '</div></div>';
    }
    $body .= form_row(t('Changelog for this version'), textarea('changelog', '', ['rows' => 6, 'maxlength' => 2000]), t('Shown in the version history on the plugin page.'))
        . form_row(t('Screenshots'), input('images[]', '', ['type' => 'file', 'accept' => 'image/*', 'multiple' => true]), t('Optional, up to 5 (jpg / png / gif / webp, 2 MB each). The first one is the cover in the plugin list; new screenshots replace the old set. Leave empty to keep the current ones.'))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . icon('upload') . t('Publish %s', $id) . '</button> <a class="btn" href="' . h(url('/admin/plugins')) . '">' . t('Cancel') . '</a></div></form>';
    admin_page(t('Publish %s', $id), $body, 'ext.market.market');
}

function market_dashboard_cards(array $cards, array $ctx): array
{
    $file = CACHE_DIR . '/market_list.json';
    if (!is_file($file)) return $cards;
    $n = 0;
    foreach ((array)(json_decode_array((string)file_get_contents($file))['plugins'] ?? []) as $p) {
        $local = plugins()[(string)($p['id'] ?? '')] ?? null;
        if ($local !== null && version_compare((string)($p['version'] ?? '0'), (string)$local['version'], '>')) $n++;
    }
    if ($n > 0) $cards['market_updates'] = ['html' => card('', '<b>' . $n . '</b><span><a href="' . h(market_admin_url('browse', ['kind' => 'updates'])) . '">' . t('Plugin updates') . '</a></span>')];
    return $cards;
}

return [
    'id' => 'market',
    'name' => 'Plugin Market',
    'version' => '2.1.4',
    'description' => 'Browse, install and update plugins from www.flatbb.com, get plugins that cost points with your account, and publish your own plugins with a changelog and screenshots.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.49'],
    'admin_pages' => [
        'market' => ['label' => 'Marketplace', 'callback' => 'market_admin_page'],
        'publish' => ['label' => '', 'callback' => 'market_admin_publish'],
    ],
    'hooks' => [
        'admin.plugin_ops' => 'market_plugin_ops',
        'region.admin.plugins.tabs' => 'market_plugins_tabs',
        'region.admin.dashboard.cards' => 'market_dashboard_cards',
    ],
    'assets' => ['css' => ['market_css']],
];

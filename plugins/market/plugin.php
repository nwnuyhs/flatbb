<?php
/**
 * Market — browse, install and update plugins from the flatbb marketplace at www.flatbb.com,
 * and publish your own plugins from Admin → Plugins.
 */
if (!defined('FLATBB')) exit;

const MARKET_CACHE_TTL = 900;

require_once __DIR__ . '/license.php';

function market_endpoint(): string
{
    return rtrim((string)config('market_endpoint', FLATBB_MARKET_ENDPOINT), '/');
}

/** Identifies this forum to the marketplace (install statistics and site licences). */
function market_site_headers(): array
{
    return ['X-Flatbb-Site: ' . base_url(), 'X-Flatbb-Version: ' . FLATBB_VERSION];
}

/** GET a URL with curl (timeouts, size cap). Returns body or null. */
function market_http_get(string $url, int $max_bytes = 20971520, ?string &$error = null): ?string
{
    if (!function_exists('curl_init')) { $error = 'curl extension missing'; return null; }
    if (http_self_request_blocked($url)) { $error = 'self request skipped on the dev server'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, application/zip'], market_site_headers())]);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn($r, $dl_total, $dl): int => $dl > $max_bytes ? 1 : 0);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        // the marketplace explains a refusal in JSON (a paid plugin without a licence, a gated download): pass that on
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

/** Download a plugin package from the marketplace and install it. Returns the installed version. */
function market_install(string $id, string $version = ''): string
{
    if (!plugin_id_valid($id)) throw new RuntimeException('Invalid plugin id');
    $url = market_endpoint() . '/plugins/' . $id . '/download' . ($version !== '' ? '?version=' . rawurlencode($version) : '');
    $zip_body = market_http_get($url, 20971520, $error);
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

/* ---------------------------------------------------------------- admin page */

function market_admin_page(string $page): never
{
    need_admin();
    if (is_post()) {
        check_csrf();
        $id = post_str('id', 40);
        try {
            switch (post_str('action', 20)) {
                case 'install':
                case 'update':
                    $v = market_install($id, post_str('version', 20));
                    flash(t('%s %s installed. Enable it under Plugins.', $id, $v));
                    break;
                case 'refresh':
                    market_list(true);
                    flash(t('Marketplace list refreshed.'));
                    break;
                default:
                    fail(t('Unknown action.'));
            }
        } catch (Throwable $e) {
            fail(t('Market error: %s', $e->getMessage()), url('/admin/ext/market/market'));
        }
        redirect(url('/admin/ext/market/market'));
    }
    $q = get_str('q', 60);
    $data = market_list(false, $q);
    $local = plugins();
    $html = '<div class="admin-toolbar"><form method="get" action="' . h(url('/admin/ext/market/market')) . '">' . (rewrite_enabled() ? '' : '<input type="hidden" name="r" value="/admin/ext/market/market">') . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . t('Search plugins') . '"><button class="btn" type="submit">' . icon('search') . t('Search') . '</button></form>'
        . action_form(url('/admin/ext/market/market'), '<button class="btn" type="submit">' . icon('refresh') . t('Refresh') . '</button>', ['action' => 'refresh'])
        . '<a class="btn" href="' . h(url('/admin/plugins')) . '">' . icon('puzzle') . t('Installed plugins') . '</a><span class="muted small">www.flatbb.com</span></div>';
    if (!empty($data['error'])) $html .= '<div class="flash flash-error">' . t('Could not reach the marketplace: %s', (string)$data['error']) . '</div>';
    if (!empty($data['core']['version']) && version_compare((string)$data['core']['version'], FLATBB_VERSION, '>')) {
        $html .= '<div class="flash flash-info">' . t('flatbb %s is available (you run %s).', (string)$data['core']['version'], FLATBB_VERSION) . ' <a href="' . h((string)($data['core']['url'] ?? 'https://www.flatbb.com')) . '" target="_blank" rel="noopener">' . t('Release notes') . '</a></div>';
    }
    $items = (array)($data['plugins'] ?? []);
    if ($items === []) $html .= '<div class="empty">' . icon('puzzle') . '<p>' . t('No plugins found.') . '</p></div>';
    foreach ($items as $p) {
        $id = (string)($p['id'] ?? '');
        if (!plugin_id_valid($id)) continue;
        $installed = $local[$id] ?? null;
        $remote_v = (string)($p['version'] ?? '0');
        $ops = '';
        $status = (string)($p['status'] ?? 'certified');
        if ($installed === null) {
            $ops = action_form(url('/admin/ext/market/market'), '<button class="btn btn-sm btn-primary">' . icon('download') . t('Install') . '</button>', ['action' => 'install', 'id' => $id, 'version' => $remote_v], '', $status === 'certified' ? '' : t('%s passed the automatic checks but has not been reviewed by the marketplace yet. Install it?', $id));
        } elseif (version_compare($remote_v, (string)$installed['version'], '>')) {
            $ops = action_form(url('/admin/ext/market/market'), '<button class="btn btn-sm btn-primary">' . icon('refresh') . t('Update to %s', $remote_v) . '</button>', ['action' => 'update', 'id' => $id, 'version' => $remote_v], '', t('Update %s? Your settings are kept.', $id));
        } else {
            $ops = '<span class="flag flag-success">' . t('installed') . '</span>';
        }
        $badge = $status === 'certified' ? '<span class="flag flag-success" title="' . h(t('Reviewed by the marketplace')) . '">' . t('Certified') . '</span>' : ($status === 'community' ? '<span class="flag" title="' . h(t('Passed the automatic checks; not reviewed by a person yet')) . '">' . t('Community') . '</span>' : '<span class="flag flag-danger">' . h($status) . '</span>');
        if ((int)($p['price'] ?? 0) > 0) $badge .= ' <span class="flag" title="' . h(t('Needs a licence key, activated under Plugin Market → Licence')) . '">' . (function_exists('market_entitled') && market_entitled($id) ? t('Licensed') : t('Paid · licence')) . '</span>';
        $html .= '<div class="plugin-item"><div class="plugin-main"><h3>' . h((string)($p['name'] ?? $id)) . ' ' . $badge . '</h3>'
            . '<div class="plugin-meta"><span>ID ' . h($id) . '</span><span>v' . h($remote_v) . '</span>' . (!empty($p['author']) ? '<span>' . t('by') . ' ' . h((string)$p['author']) . '</span>' : '') . (isset($p['downloads']) ? '<span>' . (int)$p['downloads'] . ' ' . t('installs') . '</span>' : '') . (!empty($p['url']) ? '<a href="' . h((string)$p['url']) . '" target="_blank" rel="noopener">' . t('details') . '</a>' : '') . '</div>'
            . '<p class="muted">' . h((string)($p['description'] ?? '')) . '</p></div><div class="plugin-ops">' . $ops . '</div></div>';
    }
    admin_page(t('Plugin market'), $html, 'ext.market.market');
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
function market_admin_publish(string $page): never
{
    need_admin();
    $id = is_post() ? post_str('id', 40) : get_str('id', 40);
    if (!isset(plugins()[$id])) fail(t('Plugin not found.'), url('/admin/plugins'));
    if (in_array($id, market_reserved_ids(), true)) fail(t('%s is part of the marketplace itself and cannot be published.', $id), url('/admin/plugins'));
    $saved = (string)plugin_setting('market', 'token', '') ?: (string)(getenv('FLATBB_TOKEN') ?: '');
    if (is_post()) {
        $pasted = trim(post_str('token', 120));
        if ($pasted !== '') plugin_save_settings('market', ['token' => $pasted] + plugin_settings('market')); // pasted once, kept in the plugin settings
        $token = $pasted !== '' ? $pasted : $saved;
        if ($token === '') fail(t('Publishing to the marketplace needs a developer token.'), url('/admin/ext/market/publish', ['id' => $id]));
        $shots = array_map(static fn(array $f): array => ['path' => $f['tmp_name'], 'name' => $f['name']], upload_files_list('images'));
        $r = plugin_publish($id, $token, post_str('changelog', 2000), '', false, $shots);
        flash($r['message'] . (!empty($r['url']) ? ' ' . $r['url'] : ''), $r['ok'] ? 'success' : 'error');
        redirect(url('/admin/plugins'));
    }
    $m = plugins()[$id];
    $body = '<form method="post" action="' . h(url('/admin/ext/market/publish')) . '" enctype="multipart/form-data" class="admin-form">' . csrf_field() . '<input type="hidden" name="id" value="' . h($id) . '">'
        . '<p class="muted">' . t('%s %s is packaged from plugins/%s and uploaded to the marketplace. Publishing may take a minute: the marketplace checks the package before it answers.', h((string)$m['name']), h((string)$m['version']), h($id)) . '</p>';
    if ($saved === '') {
        $body .= '<p class="muted">' . t('Publishing to the marketplace needs a developer token. Create one at %s (Settings → Developer, it is shown once), paste it here and it is kept for the next time under Admin → Plugins → Plugin Market → Settings.', '<a href="https://www.flatbb.com/settings/developer" target="_blank" rel="noopener">www.flatbb.com</a>') . '</p>'
            . form_row(t('Developer token'), input('token', '', ['required' => true, 'maxlength' => 120, 'autofocus' => true, 'autocomplete' => 'off', 'placeholder' => 'fbk_…']));
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
    if ($n > 0) $cards['market_updates'] = ['html' => card('', '<b>' . $n . '</b><span><a href="' . h(url('/admin/ext/market/market')) . '">' . t('Plugin updates') . '</a></span>')];
    return $cards;
}

return [
    'id' => 'market',
    'name' => 'Plugin Market',
    'version' => '1.1.0',
    'description' => 'Browse, install and update plugins from www.flatbb.com, publish your own plugins with a changelog and screenshots, and activate a licence key for paid plugins or commercial use.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.49'],
    'settings' => [
        'token' => ['type' => 'text', 'label' => 'Developer token (for publishing)', 'default' => '', 'max' => 120, 'help' => 'Create one at www.flatbb.com → Settings → Developer (it is shown once) and paste it here; the Publish button asks for it the first time. Leave empty to use the FLATBB_TOKEN environment variable.'],
    ],
    'admin_pages' => [
        'market' => ['label' => 'Market', 'callback' => 'market_admin_page'],
        'publish' => ['label' => '', 'callback' => 'market_admin_publish'],
        'license' => ['label' => 'Licence', 'callback' => 'market_license_admin'],
    ],
    'cron' => ['license' => ['callback' => 'market_license_cron', 'interval' => 86400]],
    'hooks' => [
        'admin.plugin_ops' => 'market_plugin_ops',
        'admin.dashboard.cards' => 'market_dashboard_cards',
    ],
];

<?php
/**
 * Market — Admin → Plugins → Marketplace: three tabs (Browse, Licences, Publish) and the licence block that the
 * settings drawer of a paid plugin shows. Loaded by plugin.php.
 */
if (!defined('FLATBB')) exit;

function market_admin_url(string $tab = 'browse', array $params = []): string
{
    return url('/admin/ext/market/market', ($tab !== 'browse' ? ['tab' => $tab] : []) + $params);
}

/** The marketplace's cached row for a plugin (the listing fetched at most 15 minutes ago; no request here). */
function market_cached_plugin(string $id): ?array
{
    $list = request_cache('market_cached_list', static function (): array {
        $file = CACHE_DIR . '/market_list.json';
        $out = [];
        if (is_file($file)) foreach ((array)(json_decode_array((string)file_get_contents($file))['plugins'] ?? []) as $p) if (isset($p['id'])) $out[(string)$p['id']] = $p;
        return $out;
    }) ?? [];
    return $list[$id] ?? null;
}

/** GET|POST /admin/ext/market/market[?tab=browse|licences|publish] */
function market_admin_page(string $page): never
{
    need_admin();
    $tab = get_str('tab', 10) ?: post_str('tab', 10) ?: 'browse';
    if (!in_array($tab, ['browse', 'licences', 'publish'], true)) not_found();
    if (is_post()) market_admin_post($tab);
    $nav = tabs([
        'browse' => ['label' => t('Browse'), 'icon' => 'puzzle', 'url' => market_admin_url('browse'), 'active' => $tab === 'browse'],
        'licences' => ['label' => t('Licences'), 'icon' => 'shield', 'url' => market_admin_url('licences'), 'active' => $tab === 'licences', 'badge' => count(market_licenses()) ?: ''],
        'publish' => ['label' => t('Publish'), 'icon' => 'upload', 'url' => market_admin_url('publish'), 'active' => $tab === 'publish'],
    ], 'tabs market-tabs');
    $body = match ($tab) {
        'licences' => market_tab_licences(),
        'publish' => market_tab_publish(),
        default => market_tab_browse(),
    };
    admin_page(t('Marketplace'), $nav . $body, 'ext.market.market');
}

/** Every POST of the page. "back" (a path on this site) sends the admin back to where the form was, e.g. a settings drawer. */
function market_admin_post(string $tab): never
{
    check_csrf();
    $back = post_str('back', 200);
    if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//')) $back = market_admin_url($tab);
    $id = post_str('id', 40);
    try {
        switch (post_str('action', 20)) {
            case 'install':
            case 'update':
                $key = trim(post_str('key', 40));
                if ($key !== '') { // a paid plugin: the key is activated first, then the download is allowed
                    $r = market_license_activate($key);
                    if (!$r['ok']) fail($r['message'], $back);
                    if ((string)($r['product'] ?? '') !== $id) fail(t('That key is for %s, not for %s.', (string)($r['product'] ?? '?'), $id), $back);
                }
                $v = market_install($id, post_str('version', 20));
                flash(t('%s %s installed. Enable it under Plugins.', $id, $v));
                break;
            case 'refresh':
                market_list(true);
                flash(t('Marketplace list refreshed.'));
                break;
            case 'activate':
                $r = market_license_activate(post_str('key', 40));
                flash($r['message'], $r['ok'] ? 'success' : 'error');
                break;
            case 'check':
                flash(market_license_refresh(post_str('product', 40)) ? t('Licence checked.') : t('The marketplace could not be reached; the licence keeps counting for now.'), 'info');
                break;
            case 'deactivate':
                market_license_deactivate(post_str('product', 40));
                flash(t('Licence removed from this site; its seat is free again.'));
                break;
            case 'save_token':
                plugin_save_settings('market', ['token' => trim(post_str('token', 120))] + plugin_settings('market'));
                flash(post_str('token', 120) !== '' ? t('Developer token saved.') : t('Developer token removed.'));
                break;
            default:
                fail(t('Unknown action.'), $back);
        }
    } catch (Throwable $e) {
        fail(t('Market error: %s', $e->getMessage()), $back);
    }
    redirect($back);
}

/* ---------------------------------------------------------------- Browse */

function market_tab_browse(): string
{
    $q = get_str('q', 60);
    $kind = get_str('kind', 10);
    if (!in_array($kind, ['free', 'paid', 'installed', 'updates'], true)) $kind = '';
    $data = market_list(false, $q);
    $local = plugins();
    $link = static fn(string $k): string => market_admin_url('browse', array_filter(['q' => $q, 'kind' => $k]));
    $html = '<div class="admin-toolbar"><form method="get" action="' . h(url('/admin/ext/market/market')) . '" class="search-form">' . (rewrite_enabled() ? '' : '<input type="hidden" name="r" value="/admin/ext/market/market">') . ($kind !== '' ? '<input type="hidden" name="kind" value="' . h($kind) . '">' : '') . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . t('Search plugins') . '"><button class="btn" type="submit">' . icon('search') . t('Search') . '</button></form>'
        . action_form(market_admin_url('browse'), '<button class="btn" type="submit">' . icon('refresh') . t('Refresh') . '</button>', ['action' => 'refresh'])
        . '<a class="btn" href="' . h((string)parse_url(market_endpoint(), PHP_URL_SCHEME) . '://' . (string)parse_url(market_endpoint(), PHP_URL_HOST) . '/market') . '" target="_blank" rel="noopener">' . icon('external') . h((string)parse_url(market_endpoint(), PHP_URL_HOST)) . '</a></div>';
    $html .= tabs([
        'all' => ['label' => t('All'), 'url' => $link(''), 'active' => $kind === ''],
        'free' => ['label' => t('Free'), 'url' => $link('free'), 'active' => $kind === 'free'],
        'paid' => ['label' => t('Paid'), 'url' => $link('paid'), 'active' => $kind === 'paid'],
        'installed' => ['label' => t('Installed'), 'url' => $link('installed'), 'active' => $kind === 'installed'],
        'updates' => ['label' => t('Updates'), 'url' => $link('updates'), 'active' => $kind === 'updates'],
    ], 'tabs tabs-sub');
    if (!empty($data['error'])) $html .= '<div class="flash flash-error">' . t('Could not reach the marketplace: %s', (string)$data['error']) . '</div>';
    if (!empty($data['core']['version']) && version_compare((string)$data['core']['version'], FLATBB_VERSION, '>')) {
        $html .= '<div class="flash flash-info">' . t('FlatBB %s is available (you run %s).', (string)$data['core']['version'], FLATBB_VERSION) . ' <a href="' . h(admin_url('updates')) . '">' . t('Updates') . '</a></div>';
    }
    $shown = 0;
    $cards = '';
    foreach ((array)($data['plugins'] ?? []) as $p) {
        $id = (string)($p['id'] ?? '');
        if (!plugin_id_valid($id)) continue;
        $installed = $local[$id] ?? null;
        $remote_v = (string)($p['version'] ?? '0');
        $paid = (int)($p['price'] ?? 0) > 0;
        $newer = $installed !== null && version_compare($remote_v, (string)$installed['version'], '>');
        if (($kind === 'free' && $paid) || ($kind === 'paid' && !$paid) || ($kind === 'installed' && $installed === null) || ($kind === 'updates' && !$newer)) continue;
        $shown++;
        $status = (string)($p['status'] ?? 'certified');
        $badge = $status === 'certified' ? '<span class="flag flag-success" title="' . h(t('Reviewed by the marketplace')) . '">' . t('Certified') . '</span>' : ($status === 'community' ? '<span class="flag" title="' . h(t('Passed the automatic checks; not reviewed by a person yet')) . '">' . t('Community') . '</span>' : '<span class="flag flag-danger">' . h($status) . '</span>');
        $badge .= ' ' . market_price_badge($p, $id);
        $confirm = $status === 'certified' ? '' : t('%s passed the automatic checks but has not been reviewed by the marketplace yet. Install it?', $id);
        if ($installed === null && $paid && !market_entitled($id)) {
            // a paid plugin without a licence here: the key is asked for right where the install happens
            $ops = '<form method="post" action="' . h(market_admin_url('browse')) . '" class="market-key-form">' . csrf_field() . '<input type="hidden" name="action" value="install"><input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="version" value="' . h($remote_v) . '">'
                . '<input type="text" name="key" placeholder="FB-XXXX-XXXX-XXXX-XXXX" maxlength="40" required autocomplete="off" spellcheck="false" title="' . h(t('The licence key for this plugin')) . '"><button class="btn btn-sm btn-primary">' . icon('shield') . t('Install with key') . '</button></form>'
                . '<small class="muted">' . t('Needs a licence key ($%s). <a href="%s" target="_blank" rel="noopener">How to get one</a>', h(market_price_text((int)$p['price'])), h((string)($p['url_market'] ?? ((string)parse_url(market_endpoint(), PHP_URL_SCHEME) . '://' . (string)parse_url(market_endpoint(), PHP_URL_HOST) . '/market/' . $id)))) . '</small>';
        } elseif ($installed === null) {
            $ops = action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary">' . icon('download') . t('Install') . '</button>', ['action' => 'install', 'id' => $id, 'version' => $remote_v], '', $confirm);
        } elseif ($newer) {
            $ops = action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary">' . icon('refresh') . t('Update to %s', $remote_v) . '</button>', ['action' => 'update', 'id' => $id, 'version' => $remote_v], '', t('Update %s? Your settings are kept.', $id));
        } else {
            $ops = '<span class="flag flag-success">' . t('installed') . '</span>';
        }
        $cards .= '<div class="plugin-item"><div class="plugin-main"><h3>' . h((string)($p['name'] ?? $id)) . ' ' . $badge . '</h3>'
            . '<div class="plugin-meta"><span>ID ' . h($id) . '</span><span>v' . h($remote_v) . '</span>' . (!empty($p['author']) ? '<span>' . t('by') . ' ' . h((string)$p['author']) . '</span>' : '') . (isset($p['downloads']) ? '<span>' . (int)$p['downloads'] . ' ' . t('installs') . '</span>' : '') . (!empty($p['url']) ? '<a href="' . h((string)$p['url']) . '" target="_blank" rel="noopener">' . t('Website') . '</a>' : '') . '</div>'
            . '<p class="muted">' . h((string)($p['description'] ?? '')) . '</p></div><div class="plugin-ops">' . $ops . '</div></div>';
    }
    if ($shown === 0) $html .= '<div class="empty">' . icon('puzzle') . '<p>' . ($kind === 'updates' ? t('Every installed plugin is up to date.') : t('No plugins found.')) . '</p></div>';
    return $html . $cards;
}

/** "Free", "$9 · licence", or "Licensed" when this site holds the key. */
function market_price_badge(array $p, string $id): string
{
    $cents = (int)($p['price'] ?? 0);
    if ($cents <= 0) return '<span class="flag market-free">' . t('Free') . '</span>';
    if (market_entitled($id)) return '<span class="flag flag-success" title="' . h(t('This forum holds a licence for it')) . '">' . t('Licensed') . '</span>';
    return '<span class="flag market-paid" title="' . h(t('Needs a licence key, entered when you install it')) . '">$' . h(market_price_text($cents)) . ' · ' . t('licence') . '</span>';
}

function market_price_text(int $cents): string
{
    return $cents % 100 === 0 ? (string)intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');
}

/* ---------------------------------------------------------------- Licences */

function market_tab_licences(): string
{
    $back = market_admin_url('licences');
    $html = '<p class="muted">' . t('A key comes with a paid plugin, a commercial licence or a support plan. A paid plugin asks for its key when you install it from Browse; keys for anything else are activated here. Free plugins never need one.') . '</p>';
    $list = market_licenses();
    if ($list !== []) {
        $rows = [];
        foreach ($list as $product => $l) {
            $c = (array)$l['claims'];
            $status = (string)($c['status'] ?? '');
            $entitled = market_entitled((string)$product);
            $rows[] = [
                '<b>' . h((string)$product) . '</b><br><small class="muted">' . h((string)($c['plan'] ?? '')) . ' · ····' . h((string)($c['hint'] ?? '')) . '</small>',
                '<span class="flag' . ($entitled ? ' flag-success' : ' flag-danger') . '">' . h($status !== '' ? $status : t('unknown')) . '</span>' . ((string)$l['error'] !== '' ? '<br><small class="muted">' . h((string)$l['error']) . '</small>' : ''),
                (int)($c['expires'] ?? 0) > 0 ? date('Y-m-d', (int)$c['expires']) : t('never'),
                '<small class="muted">' . ((int)$l['checked_at'] > 0 ? h(human_time((int)$l['checked_at'])) : '—') . '</small>',
                action_form($back, '<button class="btn btn-sm">' . icon('refresh') . t('Check now') . '</button>', ['action' => 'check', 'product' => (string)$product], 'inline')
                . action_form($back, '<button class="btn btn-sm btn-danger">' . icon('x') . t('Remove') . '</button>', ['action' => 'deactivate', 'product' => (string)$product], 'inline', t('Remove this licence from the forum? Its seat is freed for another site.')),
            ];
        }
        $html .= admin_table([t('Product'), t('Status'), t('Expires'), t('Last check'), ''], $rows, '');
    }
    return $html . '<form method="post" action="' . h($back) . '" class="admin-form" style="margin-top:16px">' . csrf_field() . '<input type="hidden" name="action" value="activate">'
        . form_row(t('Activate a key'), input('key', '', ['placeholder' => 'FB-XXXX-XXXX-XXXX-XXXX', 'maxlength' => 40, 'required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false']), t('A key can be active on as many forums as it has seats; free a seat under My licences on the marketplace.'))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . icon('shield') . t('Activate') . '</button></div></form>';
}

/* ---------------------------------------------------------------- Publish */

function market_tab_publish(): string
{
    $back = market_admin_url('publish');
    $token = (string)plugin_setting('market', 'token', '');
    $env = (string)(getenv('FLATBB_TOKEN') ?: '');
    $html = '<p class="muted">' . t('Publishing packages a plugin from plugins/<id> on this forum and uploads it to the marketplace under your developer account. Create a token at %s (Settings → Developer) and keep it here.', '<a href="' . h((string)parse_url(market_endpoint(), PHP_URL_SCHEME) . '://' . (string)parse_url(market_endpoint(), PHP_URL_HOST) . '/settings/developer') . '" target="_blank" rel="noopener">' . h((string)parse_url(market_endpoint(), PHP_URL_HOST)) . '</a>') . '</p>';
    $html .= '<form method="post" action="' . h($back) . '" class="admin-form">' . csrf_field() . '<input type="hidden" name="action" value="save_token">'
        . form_row(t('Developer token'), input('token', $token !== '' ? $token : '', ['type' => 'password', 'placeholder' => $env !== '' ? t('Using FLATBB_TOKEN from the environment') : 'fbk_…', 'maxlength' => 120, 'autocomplete' => 'off']), $token !== '' ? t('A token is saved; paste another to replace it, or empty the field to remove it.') : ($env !== '' ? t('FLATBB_TOKEN is set on this server and is used when nothing is saved here.') : t('Nothing saved yet.')))
        . '<div class="form-actions"><button type="submit" class="btn">' . icon('check') . t('Save token') . '</button></div></form>';
    $rows = [];
    foreach (plugins() as $id => $p) {
        if (in_array($id, market_reserved_ids(), true)) continue;
        $remote = market_cached_plugin($id);
        $state = $remote === null ? '<span class="muted small">' . t('not on the marketplace') . '</span>' : '<span class="flag' . ((string)($remote['status'] ?? '') === 'certified' ? ' flag-success' : '') . '">' . h((string)($remote['status'] ?? '')) . '</span> <small class="muted">v' . h((string)($remote['version'] ?? '')) . '</small>';
        $rows[] = ['<b>' . h((string)$p['name']) . '</b> <small class="muted">' . h($id) . ' · v' . h((string)$p['version']) . '</small>', $state, '<a class="btn btn-sm' . ($remote === null || version_compare((string)$p['version'], (string)($remote['version'] ?? '0'), '>') ? ' btn-primary' : '') . '" href="' . h(url('/admin/ext/market/publish', ['id' => $id])) . '">' . icon('upload') . t('Publish') . '</a>'];
    }
    return $html . '<h3 style="margin:18px 0 8px">' . t('Plugins on this forum') . '</h3>' . admin_table([t('Plugin'), t('On the marketplace'), ''], $rows, t('No plugins to publish.'));
}

/* ---------------------------------------------------------------- the settings drawer of a paid plugin */

/** admin.plugin_settings.before: a licence block at the top of the drawer when the marketplace lists the plugin as paid. */
function market_settings_licence(string $html, array $ctx): string
{
    $id = (string)($ctx['id'] ?? '');
    $remote = $id !== '' ? market_cached_plugin($id) : null;
    if ($remote === null || (int)($remote['price'] ?? 0) <= 0) return $html;
    $back = admin_url('plugins', ['settings' => $id]);
    $info = market_license_info($id);
    if ($info !== null && market_entitled($id)) {
        $line = t('Licensed') . ' · ' . h((string)($info['plan'] ?? '')) . ' · ' . ((int)($info['expires'] ?? 0) > 0 ? t('until %s', date('Y-m-d', (int)$info['expires'])) : t('never expires'));
        return $html . '<div class="market-licence-block market-licence-ok">' . icon('shield') . '<span>' . $line . '</span>'
            . action_form(market_admin_url('licences'), '<button class="btn btn-sm">' . icon('refresh') . t('Check') . '</button>', ['action' => 'check', 'product' => $id, 'back' => $back], 'inline')
            . action_form(market_admin_url('licences'), '<button class="btn btn-sm">' . icon('x') . t('Remove') . '</button>', ['action' => 'deactivate', 'product' => $id, 'back' => $back], 'inline', t('Remove this licence from the forum?')) . '</div>';
    }
    $why = $info !== null ? ' ' . h((string)($info['status'] ?? '')) : '';
    return $html . '<div class="market-licence-block market-licence-missing"><div>' . icon('shield') . '<b>' . t('Licence') . '</b> <span class="muted small">' . t('This plugin is paid ($%s).', h(market_price_text((int)$remote['price']))) . $why . '</span></div>'
        . '<form method="post" action="' . h(market_admin_url('licences')) . '" class="market-key-form">' . csrf_field() . '<input type="hidden" name="action" value="activate"><input type="hidden" name="back" value="' . h($back) . '"><input type="text" name="key" placeholder="FB-XXXX-XXXX-XXXX-XXXX" maxlength="40" required autocomplete="off" spellcheck="false"><button class="btn btn-sm btn-primary">' . icon('shield') . t('Activate') . '</button></form></div>';
}

/** Old address of the Licence page: it is a tab now. */
function market_admin_licence_redirect(string $page): never
{
    need_admin();
    redirect(market_admin_url('licences'));
}

function market_css(): string
{
    return '.market-other{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;margin:0 0 14px;border-radius:var(--radius-sm);background:var(--warning-soft,var(--info-soft));color:var(--text)}.market-other svg{width:20px;height:20px;flex:none;margin-top:2px;color:var(--warning,var(--info))}.market-other p{margin:0 0 6px}.market-other-stop{background:var(--danger-soft)}.market-other-stop svg{color:var(--danger)}'
        . '.market-tabs{margin-bottom:12px}.market-free{background:var(--success-soft,#dcfce7);color:var(--success,#15803d)}.market-paid{background:#fff1e6;color:#c2410c}[data-theme=dark] .market-paid{background:rgba(194,65,12,.25);color:#fdba74}'
        . '.market-key-form{display:flex;gap:6px;align-items:center;margin:0}.market-key-form input[type=text]{width:220px;max-width:100%;font-family:ui-monospace,monospace;font-size:var(--font-size-sm)}.plugin-ops .market-key-form{flex-wrap:wrap;justify-content:flex-end}.plugin-ops small{display:block;text-align:right;margin-top:4px}'
        . '.market-licence-block{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;margin-bottom:14px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--panel-2)}.market-licence-block svg{width:16px;height:16px;color:var(--brand);vertical-align:-3px;margin-inline-end:4px}.market-licence-ok{border-color:var(--success,#15803d)}.market-licence-block>div{flex-basis:100%}.market-licence-block .action-form{display:inline}';
}

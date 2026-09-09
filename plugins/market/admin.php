<?php
/**
 * Market — Admin → Plugins → Marketplace. Two tabs: Browse (search, All / Installed / Updates; install, update, or get a
 * plugin for points and install it) and Account (the www.flatbb.com account behind the token: points, purchases, and
 * the plugins of this forum to publish). Loaded by plugin.php.
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

/** The token that connects this forum to a www.flatbb.com account (settings, else the FLATBB_TOKEN environment variable). */
function market_token(): string
{
    return trim((string)plugin_setting('market', 'token', '')) ?: trim((string)(getenv('FLATBB_TOKEN') ?: ''));
}

/**
 * The connected account: username, is_admin, points, purchased (plugin ids). Cached for 10 minutes in the plugin
 * settings; [] when no token is set or the marketplace never answered. 'stale' => true when the last answer is reused.
 */
function market_account(bool $refresh = false): array
{
    $token = market_token();
    if ($token === '') return [];
    $hint = substr(sha1($token), 0, 8);
    $cached = json_decode_array((string)plugin_setting('market', 'account', ''));
    if ((string)($cached['token_hint'] ?? '') !== $hint) $cached = [];
    if (!$refresh && $cached !== [] && now() - (int)($cached['at'] ?? 0) < 600) return $cached;
    $a = market_whoami($token);
    if ($a === []) return $cached !== [] ? $cached + ['stale' => true] : [];
    $a += ['at' => now(), 'token_hint' => $hint];
    plugin_save_settings('market', ['account' => json_encode_value($a)] + plugin_settings('market'));
    return $a;
}

/** The marketplace's own web address (scheme + host of the API endpoint). */
function market_site_url(string $path = ''): string
{
    return (string)parse_url(market_endpoint(), PHP_URL_SCHEME) . '://' . (string)parse_url(market_endpoint(), PHP_URL_HOST) . $path;
}

/** region.admin.plugins.tabs: Marketplace and Account next to Installed, on the Plugins page and on ours. */
function market_plugins_tabs(array $tabs, array $ctx): array
{
    $active = (string)($ctx['active'] ?? '');
    $account = market_account();
    $tabs['market'] = ['label' => t('Marketplace'), 'url' => market_admin_url('browse'), 'active' => $active === 'market', 'weight' => 10];
    $tabs['account'] = ['label' => t('Account'), 'url' => market_admin_url('account'), 'active' => $active === 'account', 'weight' => 20, 'badge' => $account !== [] ? (string)($account['username'] ?? '') . ' · ' . t('%d points', (int)($account['points'] ?? 0)) : ''];
    return $tabs;
}

/** GET|POST /admin/ext/market/market[?tab=browse|account] */
function market_admin_page(string $page): never
{
    need_admin();
    $tab = get_str('tab', 10) ?: post_str('tab', 10) ?: 'browse';
    if ($tab === 'publish' || $tab === 'licences') $tab = 'account'; // the old tabs
    if (!in_array($tab, ['browse', 'account'], true)) not_found();
    if (is_post()) market_admin_post($tab);
    if ($tab === 'account' && get_str('connect', 10) !== '') market_connect_finish(); // back from the marketplace's approval page
    $account = market_account();
    // the same first row as Admin → Plugins (Installed plus what plugins add there: our Marketplace and Account)
    $top = tabs(region_list('admin.plugins.tabs', ['installed' => ['label' => t('Installed'), 'url' => admin_url('plugins'), 'badge' => count(plugins()) ?: '', 'weight' => 0]], ['active' => $tab === 'account' ? 'account' : 'market']));
    admin_page(t('Plugins'), $top . '<div style="height:12px"></div>' . ($tab === 'account' ? market_tab_account($account) : market_tab_browse($account)), 'ext.market.market');
}

/** Every POST of the page. */
function market_admin_post(string $tab): never
{
    check_csrf();
    $back = market_admin_url($tab);
    $id = post_str('id', 40);
    try {
        switch (post_str('action', 20)) {
            case 'install':
            case 'update':
                $v = market_install($id, post_str('version', 20));
                flash(t('%s %s installed. Enable it under Plugins.', $id, $v));
                break;
            case 'buy': // get it for points, then install it
                $r = market_buy($id);
                if (!$r['ok']) fail($r['message'], $back);
                $v = market_install($id, post_str('version', 20));
                flash($r['message'] . ' ' . t('%s %s installed. Enable it under Plugins.', $id, $v));
                break;
            case 'refresh':
                market_list(true);
                market_account(true);
                flash(t('Marketplace list refreshed.'));
                break;
            case 'connect': // send the admin to the marketplace; it comes back with ?connect=done&req=…
                $state = random_token(16);
                plugin_save_settings('market', ['connect_state' => $state] + plugin_settings('market'));
                $r = market_api_post('/connect/start', ['site' => base_url(), 'return' => absolute_url('/admin/ext/market/market', ['tab' => 'account']), 'state' => $state]);
                if (empty($r['ok']) || empty($r['url'])) fail(t('The marketplace could not start the connection: %s', (string)($r['error'] ?? 'no answer')), $back);
                redirect((string)$r['url']);
            case 'save_token':
                $token = trim(post_str('token', 120));
                plugin_save_settings('market', ['token' => $token, 'account' => ''] + plugin_settings('market'));
                if ($token === '') { flash(t('Account disconnected.')); break; }
                $a = market_account(true);
                flash($a !== [] ? t('Connected as %s.', (string)$a['username']) : t('Token saved, but the marketplace did not accept it. Check it under Settings → Developer on %s.', market_site_url()), $a !== [] ? 'success' : 'error');
                break;
            default:
                fail(t('Unknown action.'), $back);
        }
    } catch (Throwable $e) {
        fail(t('Market error: %s', $e->getMessage()), $back);
    }
    redirect($back);
}

/** ?connect=done&req=… (or ?connect=cancelled) on the Account tab: exchange the request for the token, once. */
function market_connect_finish(): never
{
    $back = market_admin_url('account');
    $state = (string)plugin_setting('market', 'connect_state', '');
    plugin_save_settings('market', ['connect_state' => ''] + plugin_settings('market'));
    if (get_str('connect', 10) !== 'done') { flash(t('Connection cancelled.'), 'info'); redirect($back); }
    if ($state === '') fail(t('This connection was not started from here; click Connect again.'), $back);
    $r = market_api_post('/connect/exchange', ['req' => get_str('req', 64), 'state' => $state]);
    if (empty($r['ok']) || empty($r['token'])) fail(t('The marketplace did not hand over the token: %s', (string)($r['error'] ?? 'no answer')), $back);
    plugin_save_settings('market', ['token' => (string)$r['token'], 'account' => ''] + plugin_settings('market'));
    $a = market_account(true);
    flash(t('Connected as %s.', (string)($a['username'] ?? $r['username'] ?? '')));
    redirect($back);
}

/* ---------------------------------------------------------------- Browse */

function market_tab_browse(array $account): string
{
    $q = get_str('q', 60);
    $kind = get_str('kind', 10);
    if (!in_array($kind, ['installed', 'updates'], true)) $kind = '';
    $data = market_list(false, $q);
    $local = plugins();
    $owned = (array)($account['purchased'] ?? []);
    $link = static fn(string $k): string => market_admin_url('browse', array_filter(['q' => $q, 'kind' => $k]));
    $html = '<div class="market-filter-row">' . tabs([
        'all' => ['label' => t('All'), 'url' => $link(''), 'active' => $kind === ''],
        'installed' => ['label' => t('Installed'), 'url' => $link('installed'), 'active' => $kind === 'installed'],
        'updates' => ['label' => t('Updates'), 'url' => $link('updates'), 'active' => $kind === 'updates'],
    ], 'tabs tabs-sub')
        . '<form method="get" action="' . h(url('/admin/ext/market/market')) . '" class="search-form market-search">' . (rewrite_enabled() ? '' : '<input type="hidden" name="r" value="/admin/ext/market/market">') . ($kind !== '' ? '<input type="hidden" name="kind" value="' . h($kind) . '">' : '') . icon('search') . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . h(t('Search')) . '"></form>'
        . action_form(market_admin_url('browse'), '<button class="icon-btn" type="submit" title="' . h(t('Fetch the list again')) . '">' . icon('refresh') . '</button>', ['action' => 'refresh'], 'inline') . '</div>';
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
        $points = (int)($p['points'] ?? 0);
        $mine = $account !== [] && (in_array($id, $owned, true) || strcasecmp((string)($p['publisher'] ?? ''), (string)$account['username']) === 0);
        $newer = $installed !== null && version_compare($remote_v, (string)$installed['version'], '>');
        if (($kind === 'installed' && $installed === null) || ($kind === 'updates' && !$newer)) continue;
        $shown++;
        $status = (string)($p['status'] ?? 'certified');
        $badge = $status === 'certified' ? '<span class="flag flag-success" title="' . h(t('Reviewed by the marketplace')) . '">' . t('Certified') . '</span>' : ($status === 'community' ? '<span class="flag" title="' . h(t('Passed the automatic checks; not reviewed by a person yet')) . '">' . t('Community') . '</span>' : '<span class="flag flag-danger">' . h($status) . '</span>');
        $badge .= ' ' . market_points_badge($points, $mine);
        $confirm = $status === 'certified' ? '' : t('%s passed the automatic checks but has not been reviewed by the marketplace yet. Install it?', $id);
        if ($installed !== null && $newer) {
            $ops = action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary">' . icon('refresh') . t('Update to %s', $remote_v) . '</button>', ['action' => 'update', 'id' => $id, 'version' => $remote_v], '', t('Update %s? Your settings are kept.', $id));
        } elseif ($installed !== null) {
            $ops = '<span class="flag flag-success">' . t('installed') . '</span> <a class="btn btn-sm" href="' . h(admin_url('plugins')) . '">' . t('Manage') . '</a>';
        } elseif ($points <= 0 || $mine) {
            $ops = action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary">' . icon('download') . t('Install') . '</button>', ['action' => 'install', 'id' => $id, 'version' => $remote_v], '', $confirm);
        } elseif ($account === []) {
            $ops = '<a class="btn btn-sm" href="' . h(market_admin_url('account')) . '">' . icon('user') . t('Connect account to get it') . '</a>';
        } else {
            $have = (int)($account['points'] ?? 0);
            $ops = action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary"' . ($have < $points ? ' disabled title="' . h(t('You have %d points.', $have)) . '"' : '') . '>' . icon('star') . t('Get for %d points and install', $points) . '</button>', ['action' => 'buy', 'id' => $id, 'version' => $remote_v], '', t('Spend %1$d points on %2$s? They go to its author; the plugin is yours for good.', $points, $id))
                . ($have < $points ? '<small class="muted">' . t('You have %d points.', $have) . '</small>' : '');
        }
        $cards .= '<div class="plugin-item"><div class="plugin-main"><h3>' . h((string)($p['name'] ?? $id)) . ' ' . $badge . '</h3>'
            . '<div class="plugin-meta"><span>ID ' . h($id) . '</span><span>v' . h($remote_v) . '</span>' . (!empty($p['author']) ? '<span>' . t('by') . ' ' . h((string)$p['author']) . '</span>' : '') . (isset($p['downloads']) ? '<span>' . (int)$p['downloads'] . ' ' . t('installs') . '</span>' : '') . (!empty($p['url']) ? '<a href="' . h((string)$p['url']) . '" target="_blank" rel="noopener">' . t('Website') . '</a>' : '') . '</div>'
            . '<p class="muted">' . h((string)($p['description'] ?? '')) . '</p></div><div class="plugin-ops">' . $ops . '</div></div>';
    }
    if ($shown === 0) $html .= '<div class="empty">' . icon('puzzle') . '<p>' . ($kind === 'updates' ? t('Every installed plugin is up to date.') : t('No plugins found.')) . '</p></div>';
    return $html . $cards . '<p class="muted small market-foot">' . t('Plugins come from %s.', '<a href="' . h(market_site_url('/market')) . '" target="_blank" rel="noopener">' . h((string)parse_url(market_endpoint(), PHP_URL_HOST)) . '</a>') . '</p>';
}

/** "Free", "50 points", or "Yours" when the connected account has it. */
function market_points_badge(int $points, bool $mine): string
{
    if ($points <= 0) return '<span class="flag market-free">' . t('Free') . '</span>';
    if ($mine) return '<span class="flag flag-success" title="' . h(t('Bought with the connected account')) . '">' . t('Yours') . '</span>';
    return '<span class="flag market-paid" title="' . h(t('Paid once with the points of your www.flatbb.com account; the author receives them')) . '">' . h(t('%d points', $points)) . '</span>';
}

/* ---------------------------------------------------------------- Account */

function market_tab_account(array $account): string
{
    $back = market_admin_url('account');
    $host = (string)parse_url(market_endpoint(), PHP_URL_HOST);
    $dev = '<a href="' . h(market_site_url('/settings/developer')) . '" target="_blank" rel="noopener">' . h($host) . ' → Settings → Developer</a>';
    if ($account !== []) {
        $html = '<div class="market-account-card">' . icon('user') . '<div><b>' . t('Connected as %s', h((string)$account['username'])) . '</b>' . (!empty($account['stale']) ? ' <span class="flag">' . t('marketplace unreachable, last known') . '</span>' : '')
            . '<div class="muted small">' . t('%d points', (int)($account['points'] ?? 0)) . ' · ' . t('%d plugins bought', count((array)($account['purchased'] ?? []))) . ' · ' . t('Points are earned on %s; plugins that cost points are paid with them once, to their author.', h($host)) . '</div></div>'
            . '<div class="btn-row">' . action_form($back, '<button class="btn btn-sm">' . icon('refresh') . t('Refresh') . '</button>', ['action' => 'refresh'], 'inline')
            . action_form($back, '<button class="btn btn-sm btn-danger">' . icon('x') . t('Disconnect') . '</button>', ['action' => 'save_token', 'token' => ''], 'inline', t('Disconnect this forum from the account? Plugins that cost points cannot be installed until an account is connected again.')) . '</div></div>';
        $owned = (array)($account['purchased'] ?? []);
        if ($owned !== []) {
            $rows = [];
            foreach ($owned as $id) {
                $id = (string)$id;
                $remote = market_cached_plugin($id);
                $installed = plugins()[$id] ?? null;
                $rows[] = ['<b>' . h((string)($remote['name'] ?? $id)) . '</b> <small class="muted">' . h($id) . '</small>', $installed !== null ? '<span class="flag flag-success">' . t('installed') . ' v' . h((string)$installed['version']) . '</span>' : '<span class="muted small">' . t('not installed') . '</span>',
                    $installed === null && $remote !== null ? action_form(market_admin_url('browse'), '<button class="btn btn-sm btn-primary">' . icon('download') . t('Install') . '</button>', ['action' => 'install', 'id' => $id, 'version' => (string)($remote['version'] ?? '')], 'inline') : ''];
            }
            $html .= '<h3 style="margin:18px 0 8px">' . t('Bought with this account') . '</h3>' . admin_table([t('Plugin'), t('On this forum'), ''], $rows, '');
        }
    } else {
        $html = '<div class="market-connect"><p>' . t('Connect the www.flatbb.com account of this forum\'s admin: the forum then gets plugins that cost points with the account\'s points and publishes this forum\'s plugins under it.') . '</p>'
            . action_form($back, '<button type="submit" class="btn btn-primary btn-lg">' . icon('link') . t('Connect with %s', $host) . '</button>', ['action' => 'connect'])
            . '<p class="muted small">' . t('You sign in on %s (if you are not already) and approve; nothing to copy.', h($host)) . '</p></div>'
            . '<details class="market-token-fold"><summary class="muted small">' . t('Or paste a token') . '</summary>'
            . '<form method="post" action="' . h($back) . '" class="admin-form">' . csrf_field() . '<input type="hidden" name="action" value="save_token">'
            . form_row(t('Token'), input('token', '', ['type' => 'password', 'placeholder' => 'fbk_…', 'maxlength' => 120, 'autocomplete' => 'off', 'required' => true]), t('From %s.', $dev) . (market_token() !== '' ? ' ' . t('A token is set but the marketplace did not accept it.') : ''))
            . '<div class="form-actions"><button type="submit" class="btn">' . icon('check') . t('Save token') . '</button></div></form></details>';
    }
    return $html . '<p class="muted small market-foot">' . t('To publish a plugin of this forum, use Publish on its row under Installed.') . '</p>';
}

function market_css(): string
{
    return '.market-filter-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}.market-filter-row .tabs{margin:0}.market-search{margin-inline-start:auto;width:200px;height:32px;padding:0 10px}.market-search input{font-size:var(--font-size-sm)}.market-filter-row .icon-btn{width:32px;height:32px}'
        . '@media(max-width:640px){.market-search{width:100%;margin-inline-start:0;order:2}.market-filter-row .icon-btn{order:3}}'
        . '.market-connect{padding:18px 16px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--panel-2);text-align:center}.market-connect p:first-child{max-width:520px;margin:0 auto 12px}.market-connect .btn-lg{margin:4px 0 8px}.market-token-fold{margin-top:14px}.market-token-fold summary{cursor:pointer}.market-token-fold .admin-form{margin-top:10px}'
        . '@media(max-width:640px){.plugin-item{flex-direction:column;gap:10px}.plugin-ops{justify-content:flex-start}.market-account-card .btn-row{margin-inline-start:0}.admin-toolbar form.inline{margin:0}}'
        . '.market-other{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;margin:0 0 14px;border-radius:var(--radius-sm);background:var(--warning-soft,var(--info-soft));color:var(--text)}.market-other svg{width:20px;height:20px;flex:none;margin-top:2px;color:var(--warning,var(--info))}.market-other p{margin:0 0 6px}.market-other-stop{background:var(--danger-soft)}.market-other-stop svg{color:var(--danger)}'
        . '.market-tabs{margin-bottom:12px}.market-free{background:var(--success-soft,#dcfce7);color:var(--success,#15803d)}.market-paid{background:#fff1e6;color:#c2410c}[data-theme=dark] .market-paid{background:rgba(194,65,12,.25);color:#fdba74}'
        . '.market-account{display:inline-flex;align-items:center;gap:6px;margin-inline-start:auto;color:var(--text-muted);font-size:var(--font-size-sm);white-space:nowrap}.market-account svg{width:16px;height:16px}.market-account b{color:var(--text)}'
        . '.market-account-card{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 16px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--panel-2)}.market-account-card>svg{width:28px;height:28px;color:var(--brand);flex:none}.market-account-card>div:first-of-type{flex:1;min-width:200px}.market-account-card .btn-row{margin-inline-start:auto}'
        . '.plugin-ops small{display:block;text-align:right;margin-top:4px}.plugin-ops .btn[disabled]{opacity:.5;cursor:default}.market-foot{margin-top:14px}';
}

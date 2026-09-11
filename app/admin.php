<?php
/**
 * Admin panel: dispatcher, dashboard, settings, users, groups.
 * Shared UI helpers (page shell, drawer, row menu, switch) live in admin_ui.php;
 * categories and tags in admin_content.php; plugins, layout, cron, tools in admin_system.php.
 * Plugins add pages via manifest 'admin_pages' => ['key' => ['label' => 'My page', 'callback' => 'my_admin_fn']].
 */

/** GET|POST /admin[/{page}] */
function admin_index(string $page = 'dashboard'): never
{
    need_admin();
    $fn = 'admin_page_' . str_replace('-', '_', $page);
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $page) || !function_exists($fn)) not_found();
    if (in_array($page, ['settings', 'users', 'groups', 'plugins', 'tools', 'layout', 'updates', 'points'], true)) need_sudo(); // confirm mode: password re-entered within the window set in Settings → Security
    $fn();
}

/** GET|POST /admin/ext/{plugin}/{page} — plugin admin pages */
function admin_ext(string $plugin, string $page): never
{
    need_admin();
    $def = plugin_manifest($plugin)['admin_pages'][$page] ?? null;
    if ($def === null || !plugin_enabled($plugin)) not_found();
    $cb = is_array($def) ? ($def['callback'] ?? null) : $def;
    if (!is_callable($cb)) not_found();
    $cb($page);
}

/* ---------------------------------------------------------------- dashboard */

/**
 * Deployment self-check shown on the dashboard: private directories must not be reachable over HTTP.
 * Results are cached for an hour (setting security_check); POST action=recheck refreshes.
 */
function admin_security_checks(bool $force = false): array
{
    $cached = json_decode_array(setting('security_check', ''));
    if (!$force && !empty($cached['at']) && now() - (int)$cached['at'] < 3600) return $cached;
    // the single-threaded PHP dev server cannot answer a request to itself while it is busy: skip the probes
    if (PHP_SAPI === 'cli-server') return ['at' => now(), 'issues' => debug_mode() ? [t('Debug mode is on (data/config.php): error details are shown to visitors.')] : [], 'skipped' => 'cli-server'];
    // The probe goes to the web server on this machine (http_exec_prefer_local), so a CDN in front cannot fake the answer.
    $probe = static function (string $path, bool $public = false): string {
        if (!function_exists('curl_init')) return 'unknown';
        $ch = curl_init(base_url() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'flatbb-selfcheck']);
        $body = $public ? curl_exec($ch) : http_exec_prefer_local($ch, base_url() . $path);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 0) return 'unknown';
        return (string)$code . (is_string($body) && trim($body) === '' ? ' empty' : '');
    };
    // Does the loopback answer for this site at all? Several sites on one server, or a proxy in front, can make it answer
    // from somewhere else, and then every probe below would describe another site. A file that certainly exists here says so.
    $local = str_starts_with($probe('/assets/app.css'), '200');
    // 403/404 = blocked by the web server; "200 empty" = the PHP guard ran and printed nothing (acceptable)
    $blocked = static fn(string $code): bool => in_array($code, ['403', '404', 'unknown', '200 empty', '403 empty', '404 empty'], true);
    $r = ['data' => $probe('/data/index.html', !$local), 'core' => $probe('/core/boot.php', !$local), 'plugins' => $probe('/plugins/hello/plugin.php', !$local), 'rewrite' => $probe('/__rewrite_check', !$local), 'at' => now(), 'issues' => [], 'local' => $local];
    if (str_starts_with($r['data'], '200')) $r['issues'][] = t('data/ is reachable over HTTP (contains the configuration and, with SQLite, the database).');
    if (!$blocked($r['core'])) $r['issues'][] = t('core/ and app/ are reachable over HTTP.');
    if (!$blocked($r['plugins'])) $r['issues'][] = t('PHP files under plugins/ can be executed directly.');
    // rewrite_proven(): this page was opened at a clean URL, so they work here whatever a probe says
    if (!str_starts_with($r['rewrite'], '200') && $r['rewrite'] !== 'unknown' && rewrite_enabled() && !rewrite_proven()) $r['issues'][] = t('Clean URLs are enabled in settings but /__rewrite_check does not answer (HTTP %s); links may be broken.', $r['rewrite']);
    if (debug_mode()) $r['issues'][] = t('Debug mode is on (data/config.php): error details are shown to visitors.');
    save_settings(['security_check' => json_encode_value($r)]);
    return $r;
}

function admin_page_dashboard(): never
{
    if (is_post()) {
        check_csrf();
        admin_security_checks(true);
        redirect(admin_url());
    }
    $sec = admin_security_checks();
    $s = site_stats();
    $cards = [
        'topics' => ['html' => card('', '<b>' . human_number($s['topics']) . '</b><span>' . t('Topics') . '</span>')],
        'posts' => ['html' => card('', '<b>' . human_number($s['posts']) . '</b><span>' . t('Replies') . '</span>')],
        'users' => ['html' => card('', '<b>' . human_number($s['users']) . '</b><span>' . t('Members') . '</span>')],
        'today' => ['html' => card('', '<b>' . (int)val('SELECT COUNT(*) FROM fb_posts WHERE created_at>?', [now() - 86400]) . '</b><span>' . t('Posts today') . '</span>')],
        'plugins' => ['html' => card('', '<b>' . count(plugin_manifests()) . '</b><span>' . t('Active plugins') . '</span>')],
    ];
    $cards = region_list('admin.dashboard.cards', $cards, []);
    $html = '';
    if (!empty($sec['issues'])) {
        $html .= '<div class="flash flash-error"><b>' . t('Deployment check') . '</b><ul style="margin:6px 0 8px 18px;padding:0">';
        foreach ($sec['issues'] as $i) $html .= '<li>' . h($i) . '</li>';
        $html .= '</ul><div class="small">' . t('Fix: add the rules from nginx.conf.example to your nginx site (on BaoTa/aaPanel paste them into the site\'s "URL rewrite" box), or keep the shipped .htaccess on Apache. Then click re-check.') . '</div>' . action_form(admin_url(), '<button class="btn btn-sm" style="margin-top:8px">' . icon('refresh') . t('Re-check') . '</button>', ['action' => 'recheck']) . '</div>';
    } else {
        $html .= '<p class="muted small">' . icon('check') . ' ' . t('Deployment check passed %s.', human_time((int)($sec['at'] ?? now()))) . ' ' . action_form(admin_url(), '<button class="link">' . t('re-check') . '</button>', ['action' => 'recheck'], 'inline') . '</p>';
    }
    $html .= '<div class="admin-cards" data-slot="admin.dashboard.cards">';
    foreach ($cards as $c) $html .= $c['html'] ?? '';
    $html .= '</div>';
    $info = [
        [t('FlatBB version'), FLATBB_VERSION],
        [t('PHP'), PHP_VERSION],
        [t('Database'), db_is_mysql() ? 'MySQL ' . (string)val('SELECT VERSION()') : 'SQLite ' . (string)val('SELECT sqlite_version()')],
        [t('Search backend'), search_backend()],
        [t('Clean URLs'), rewrite_enabled() ? t('enabled') : t('disabled (index.php?r=...)')],
        [t('Cron'), setting('cron_key') !== '' ? absolute_url('/cron', ['key' => setting('cron_key')]) : '-'],
    ];
    $rows = '';
    foreach ($info as [$k, $v]) $rows .= '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>';
    $ru = '';
    foreach (all('SELECT id,username,created_at FROM fb_users ORDER BY id DESC LIMIT 8') as $u) $ru .= '<tr><td>' . user_link($u) . '</td><td>' . human_time((int)$u['created_at']) . '</td></tr>';
    $html .= '<div class="form-grid"><div class="table-wrap"><table class="admin"><thead><tr><th colspan="2">' . t('System') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $html .= '<div class="table-wrap"><table class="admin"><thead><tr><th>' . t('Newest members') . '</th><th></th></tr></thead><tbody>' . $ru . '</tbody></table></div></div>';
    admin_page(t('Dashboard'), $html, 'dashboard');
}

/* ---------------------------------------------------------------- settings */

function admin_settings_fields(): array
{
    return (array)hook('admin.settings_fields', [
        'general' => [t('General'), [
            'site_name' => ['text', t('Site name')],
            'site_tagline' => ['text', t('Tagline')],
            'site_description' => ['textarea', t('Meta description')],
            'site_logo' => ['image', t('Logo'), t('PNG, JPG, WebP or SVG, up to 2 MB. Shown in the header instead of the site name.'), ['png', 'jpg', 'webp', 'svg', 'gif']],
            'site_favicon' => ['image', t('Favicon'), t('PNG, ICO or SVG; a square PNG works everywhere.'), ['png', 'ico', 'svg']],
            'brand_color' => ['color', t('Brand color')],
            'theme' => ['select', t('Default theme'), '', ['auto' => t('Follow system'), 'light' => t('Light'), 'dark' => t('Dark')]],
            'site_lang' => ['select', t('Language'), t('Default interface language. Visitors pick their own from the globe in the header or in Settings → Preferences. Packs live in lang/.'), lang_available()],
            'site_tz' => ['select', t('Time zone'), t('Used where the browser cannot help: emails, feeds, the admin log and the time shown without JavaScript. Visitors see times in their own time zone.'), tz_options()],
            'footer_text' => ['text', t('Footer text')],
            'online_dot' => ['checkbox', t('Online dot on avatars'), t('A green dot on the avatar of every member seen in the last 15 minutes.')],
        ]],
        'content' => [t('Content'), [
            'per_page' => ['number', t('Topics per page'), '', null, 5, 100],
            'category_bar' => ['select', t('Category bar above topic lists'), t('A row of top-level categories above Latest / Top. On phones the left column is hidden, so this is the quickest way into a category.'), ['mobile' => t('Phones only'), 'always' => t('Always'), 'off' => t('Off')]],
            'posts_per_page' => ['number', t('Posts per page'), '', null, 5, 100],
            'post_image_max' => ['number', t('Max image width in posts (px)'), t('0 = as wide as the post. Writers can size a single image with ![alt|300](url).'), null, 0, 4000],
            'post_interval' => ['number', t('Seconds between posts'), '', null, 0, 3600],
            'new_user_limit_hours' => ['number', t('New account restriction (hours)'), t('0 disables the restriction.'), null, 0, 720],
            'new_user_max_posts' => ['number', t('Max posts during restriction'), '', null, 0, 100],
        ]],
        'editor' => [t('Editor'), [
            'editor_preview' => ['checkbox', t('Live preview button')],
            'editor_emoji' => ['checkbox', t('Emoji picker')],
            'editor_fullscreen' => ['checkbox', t('Fullscreen button')],
            'editor_draft_days' => ['number', t('Keep unsent drafts for (days)'), t('0 disables drafts. Drafts live in the writer\'s browser.'), null, 0, 90],
        ]],
        'registration' => [t('Registration'), [
            'allow_register' => ['checkbox', t('Allow new registrations')],
            'invite_code' => ['text', t('Invite code'), t('When set, registration requires this code.')],
            'register_ip_limit' => ['number', t('Registrations per network per hour'), t('Accounts one IP address may create in an hour. 0 = no limit.'), null, 0, 100],
            'password_min' => ['number', t('Minimum password length'), '', null, 4, 64],
            'username_min' => ['number', t('Shortest username'), t('Letters, numbers, dot, dash and underscore; the first character a letter or a digit.'), null, 1, 40],
            'username_max' => ['number', t('Longest username'), '', null, 2, 40],
            'register_verify' => ['checkbox', t('Require email verification'), t('New members confirm their address with a six-digit code before the account is created; changing the address later needs a code too. Mail is delivered by: %s.', mail_transport_label())],
            'allow_rename' => ['checkbox', t('Members may change their own username'), t('Administrators can always rename users from the Users page. Old profile links redirect to the new name.')],
            'rename_days' => ['number', t('Days between username changes'), t('Applies to members renaming themselves.'), null, 0, 3650],
        ]],
        'email' => [t('Email'), [
            'mail_from' => ['text', t('Sender address'), t('Used for password resets and notifications. Install an SMTP plugin for reliable delivery; without one PHP mail() is used.')],
        ]],
        'uploads' => [t('Uploads'), [
            'upload_max_mb' => ['decimal', t('Max upload size (MB)'), t('Fractions are allowed: 0.3 keeps uploads under about 300 KB.'), null, 0.1, 100],
            'upload_types' => ['text', t('Allowed extensions'), t('Comma separated.')],
        ]],
        'security' => [t('Security'), [
            'csp_mode' => ['select', t('Content Security Policy'), t('Report only logs violations to data/csp-report.log (see Tools) without blocking anything; switch to Enforce once the log stays clean. Inline scripts in the extra HTML fields need nonce="{nonce}".'), ['off' => t('Off'), 'report' => t('Report only'), 'enforce' => t('Enforce')]],
            'sudo_minutes' => ['number', t('Password confirmation window (minutes)'), t('High-risk admin pages (settings, users, plugins, updates…) ask for your password again after this long. 0 switches the confirmation off; keep it on if others can reach your signed-in browser.'), null, 0, 1440],
            'trusted_proxies' => ['text', t('Trusted proxies'), t('Behind Cloudflare enter "cloudflare"; otherwise list the proxy IPs or CIDRs. The real visitor address is then read from the proxy headers (throttling, IP records and bans depend on it).')],
        ]],
        'advanced' => [t('Advanced'), [
            'rewrite' => ['checkbox', t('Clean URLs (requires rewrite rules)'), t('Only enable when /__rewrite_check returns "ok" on your server.')],
            'seo_keywords' => ['text', t('Meta keywords')],
            'head_code' => ['code', t('Extra HTML in <head>'), t('Analytics, fonts, meta tags.')],
            'foot_code' => ['code', t('Extra HTML before </body>')],
        ]],
    ], []);
}

/** One section at a time (?section=general); each section is its own form. */
function admin_page_settings(): never
{
    $sections = admin_settings_fields();
    $key = get_str('section', 30) ?: (string)post_str('section', 30) ?: array_key_first($sections);
    if (!isset($sections[$key])) not_found();
    [$label, $fields] = $sections[$key];
    if (is_post()) {
        check_csrf();
        $save = [];
        foreach ($fields as $name => $def) {
            $raw = post_str($name, 20000);
            if ($def[0] === 'image') {
                if (post_int($name . '_remove') === 1) { $save[$name] = ''; continue; }
                $f = $_FILES[$name] ?? null;
                if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try { $save[$name] = upload_site_image($name, $f, (array)($def[3] ?? ['png', 'jpg'])); }
                    catch (RuntimeException $e) { fail($def[1] . ': ' . $e->getMessage(), admin_url('settings', ['section' => $key])); }
                }
                continue;
            }
            $save[$name] = match ($def[0]) {
                'checkbox' => $raw ? '1' : '0',
                'number' => (string)max((int)($def[4] ?? PHP_INT_MIN), min((int)($def[5] ?? PHP_INT_MAX), (int)$raw)),
                'decimal' => rtrim(rtrim(number_format(max((float)($def[4] ?? 0), min((float)($def[5] ?? 1000000), (float)str_replace(',', '.', trim($raw)))), 3, '.', ''), '0'), '.') ?: (string)($def[4] ?? 0),
                'select' => isset($def[3][(string)$raw]) ? (string)$raw : setting($name),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)$raw) ? strtolower((string)$raw) : '#e7672e',
                'code', 'textarea' => is_string($raw) ? cut(str_replace("\r\n", "\n", $raw), 20000, '') : '',
                default => is_string($raw) ? cut(trim($raw), 500, '') : '',
            };
        }
        save_settings(hook('admin.settings_save', $save, ['section' => $key]));
        admin_log('settings', $key, implode(', ', array_keys($save)));
        flash(t('Settings saved.'));
        redirect(admin_url('settings', ['section' => $key]));
    }
    $tabs = [];
    foreach ($sections as $k => [$l]) $tabs[$k] = ['label' => $l, 'url' => admin_url('settings', ['section' => $k]), 'active' => $k === $key];
    $html = tabs($tabs) . '<form method="post" action="' . h(admin_url('settings')) . '" class="admin-form" style="margin-top:14px" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="section" value="' . h($key) . '">';
    foreach ($fields as $name => $def) {
        [$type, $flabel] = $def;
        $help = (string)($def[2] ?? '');
        $v = setting($name);
        $field = match ($type) {
            'checkbox' => checkbox($name, $v === '1', $flabel),
            'textarea' => textarea($name, $v, ['rows' => 3]),
            'code' => textarea($name, $v, ['rows' => 4, 'class' => 'mono']),
            'select' => select($name, (array)$def[3], $v),
            'number' => input($name, $v, ['type' => 'number', 'min' => $def[4] ?? 0, 'max' => $def[5] ?? 100000]),
            'decimal' => input($name, $v, ['type' => 'number', 'min' => $def[4] ?? 0, 'max' => $def[5] ?? 100000, 'step' => 'any']), // any: 0.3 and 0.25 are both fine, the browser refuses nothing
            'color' => input($name, $v ?: '#e7672e', ['type' => 'color']),
            'image' => ($v !== '' ? '<div class="image-current"><img src="' . h(upload_url($v)) . '" alt=""> ' . checkbox($name . '_remove', false, t('Remove')) . '</div>' : '') . input($name, '', ['type' => 'file', 'accept' => implode(',', array_map(static fn(string $e): string => '.' . $e, (array)($def[3] ?? [])))]),
            default => input($name, $v),
        };
        $html .= $type === 'checkbox' ? '<div class="form-row">' . $field . ($help !== '' ? '<div class="form-help">' . h($help) . '</div>' : '') . '</div>' : form_row($flabel, $field, h($help));
    }
    $html .= admin_form_actions(t('Save')) . '</form>';
    admin_page(t('Settings'), $html, 'settings');
}

/* ---------------------------------------------------------------- users */

function admin_page_users(): never
{
    $q = get_str('q', 50);
    $list_url = admin_url('users', $q !== '' ? ['q' => $q] : []);
    if (is_post()) {
        check_csrf();
        $u = user_by_id(post_int('id'));
        if ($u === null) fail(t('User not found.'));
        $group = group_by_id(post_int('group_id'));
        if ($group === null) fail(t('Group not found.'));
        if ((int)$u['id'] === uid() && !(int)$group['is_admin']) fail(t('You cannot remove your own admin rights.'));
        $new_name = post_str('username', 30);
        if ($new_name !== '' && $new_name !== (string)$u['username']) {
            $err = user_rename($u, $new_name, uid());
            if ($err !== '') fail($err, admin_url('users', ['q' => $q, 'edit' => $u['id']]));
        }
        db_update('fb_users', ['group_id' => (int)$group['id'], 'status' => post_int('status') ? 1 : 0], 'id=?', [(int)$u['id']]);
        $af = $_FILES['avatar'] ?? null;
        if (post_int('avatar_remove') === 1) {
            db_update('fb_users', ['avatar' => ''], 'id=?', [(int)$u['id']]);
        } elseif (is_array($af) && ($af['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$af['size'] > 4 * 1048576) fail(t('Avatar must be smaller than 4 MB.'), admin_url('users', ['q' => $q, 'edit' => $u['id']]));
            try { db_update('fb_users', ['avatar' => avatar_store((int)$u['id'], (string)$af['tmp_name'])], 'id=?', [(int)$u['id']]); }
            catch (RuntimeException $e) { fail($e->getMessage(), admin_url('users', ['q' => $q, 'edit' => $u['id']])); }
        }
        $np = post_secret('password');
        if ($np !== '') {
            if (password_check($np) !== '') fail(password_check($np));
            db_update('fb_users', ['password' => password_hash($np, PASSWORD_DEFAULT)], 'id=?', [(int)$u['id']]);
        }
        if (post_int('points_delta') !== 0) points_add((int)$u['id'], max(-100000, min(100000, post_int('points_delta'))), 'manual', 0, post_str('points_note', 120) ?: t('by %s', (string)me()['username']));
        admin_log('user.save', '#' . (int)$u['id'] . ' ' . (string)$u['username'], 'group ' . (string)$group['slug'] . ', status ' . (post_int('status') ? 1 : 0) . ($np !== '' ? ', password changed' : '') . ($new_name !== '' && $new_name !== (string)$u['username'] ? ', renamed to ' . $new_name : ''));
        fire('admin.user_saved', ['user_id' => (int)$u['id']]);
        flash(t('User saved.'));
        redirect($list_url);
    }
    $where = $q !== '' ? "WHERE username_lower LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!'" : '';
    $params = $q !== '' ? [db_like(mb_strtolower($q)), db_like(mb_strtolower($q))] : [];
    $pg = paginate_calc((int)val("SELECT COUNT(*) FROM fb_users {$where}", $params), get_int('page', 1, 1, 100000), 30);
    $rows = [];
    foreach (all("SELECT * FROM fb_users {$where} ORDER BY id DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params) as $u) {
        $g = group_by_id((int)$u['group_id']);
        $rows[] = [
            avatar($u, 24) . ' ' . user_link($u) . '<br><small class="muted">#' . (int)$u['id'] . ($u['email'] !== '' ? ' · ' . h((string)$u['email']) : '') . '</small>',
            h($g['name'] ?? '?'), (int)$u['topic_count'] . ' / ' . (int)$u['post_count'], human_time((int)$u['last_seen']),
            (int)$u['status'] === 1 ? '<span class="flag flag-success">' . t('active') . '</span>' : '<span class="flag flag-danger">' . t('suspended') . '</span>',
            '<div class="row-actions">' . admin_drawer_link(admin_url('users', ['q' => $q, 'edit' => $u['id']]), t('Edit')) . '</div>',
        ];
    }
    $html = '<form method="get" action="' . h(admin_url('users')) . '" class="admin-toolbar">' . (rewrite_enabled() ? '' : '<input type="hidden" name="r" value="/admin/users">') . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . t('Search username or email') . '"><button class="btn" type="submit">' . icon('search') . t('Search') . '</button><span class="muted small">' . t('%d users', $pg['total']) . '</span></form>';
    $html .= admin_table([t('User'), t('Group'), t('Topics / replies'), t('Seen'), t('Status'), ''], $rows, t('No users match.'));
    $html .= pagination($pg, static fn(int $n): string => admin_url('users', ['q' => $q, 'page' => $n]));
    $drawer = null;
    if (($edit = user_by_id(get_int('edit', 0))) !== null) {
        $opts = [];
        foreach (groups() as $g) $opts[(string)$g['id']] = $g['name'];
        $former = array_map(static fn(array $f): string => (string)$f['name'], user_former_names($edit));
        $body = '<form method="post" action="' . h($list_url) . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
            . form_row(t('Picture'), '<div class="image-current">' . avatar($edit, 48, false) . ' ' . ((string)$edit['avatar'] !== '' ? checkbox('avatar_remove', false, t('Remove')) : '') . '</div>' . input('avatar', '', ['type' => 'file', 'accept' => 'image/*']), t('JPG, PNG or WebP, up to 4 MB. It is cropped to a square.'))
            . form_row(t('Username'), input('username', (string)$edit['username'], ['maxlength' => 30, 'pattern' => '[A-Za-z0-9][A-Za-z0-9_.-]{1,29}']), t('Letters, numbers, dot, dash or underscore. Links to the old name redirect to the new one.') . ($former !== [] ? ' ' . t('Former names: %s', implode(', ', $former)) : ''))
            . form_row(t('Group'), select('group_id', $opts, (string)$edit['group_id']))
            . form_row(t('Status'), select('status', ['1' => t('Active'), '0' => t('Suspended')], (string)$edit['status']))
            . form_row(t('New password'), input('password', '', ['type' => 'password', 'autocomplete' => 'new-password']), t('Leave empty to keep the current password.'))
            . '<div class="form-grid">' . form_row(t('Adjust points (±)'), input('points_delta', '', ['type' => 'number', 'placeholder' => '0'])) . form_row(t('Reason shown to the user'), input('points_note', '')) . '</div>'
            . '<p class="muted small">' . t('Balance: %s points', human_number((int)$edit['points'])) . '</p>' . points_log_html(points_log((int)$edit['id'], 1, 8)['rows'], t('No points activity yet.'))
            . '<p class="muted small">' . t('Email') . ': ' . h($edit['email'] ?: '-') . '<br>' . t('Registered') . ' ' . date('Y-m-d', (int)$edit['created_at']) . ' · IP ' . h($edit['created_ip']) . '<br>' . t('Topics') . ' ' . (int)$edit['topic_count'] . ' · ' . t('Replies') . ' ' . (int)$edit['post_count'] . '</p>'
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => $edit['username'], 'sub' => t('Edit user') . ' · #' . (int)$edit['id'], 'body' => $body, 'back' => $list_url, 'links' => [t('Public profile') => user_url($edit)]];
    }
    admin_page(t('Users'), $html, 'users', ['drawer' => $drawer]);
}

/* ---------------------------------------------------------------- groups */

function admin_page_groups(): never
{
    $perms = (array)hook('permissions.known', ['post' => t('Create topics'), 'reply' => t('Reply'), 'upload' => t('Upload files'), 'edit_own' => t('Edit own posts'), 'delete_own' => t('Delete own posts')], []);
    $list_url = admin_url('groups');
    if (is_post()) {
        check_csrf();
        $id = post_int('id');
        if (post_str('action', 20) === 'delete') {
            $g = group_by_id($id);
            if ($g === null || in_array($g['slug'], ['admin', 'member'], true)) fail(t('This group cannot be deleted.'));
            if (val('SELECT 1 FROM fb_users WHERE group_id=?', [$id])) fail(t('Move its members to another group first.'));
            db_delete('fb_groups', 'id=?', [$id]);
            admin_log('group.delete', (string)$g['slug']);
            flash(t('Group deleted.'));
            redirect($list_url);
        }
        $name = post_str('name', 60);
        if ($name === '') fail(t('Name is required.'));
        $slug = slugify(post_str('slug', 60) ?: $name);
        $data = ['name' => $name, 'slug' => $slug, 'color' => preg_match('/^#[0-9a-f]{6}$/i', post_str('color', 7)) ? post_str('color', 7) : '', 'is_admin' => post_int('is_admin') ? 1 : 0, 'is_mod' => post_int('is_mod') ? 1 : 0, 'permissions' => json_encode_value(array_values(array_intersect(array_keys($perms), post_list('perm')))), 'sort' => post_int('sort')];
        if ($id > 0) {
            $g = group_by_id($id);
            if ($g === null) fail(t('Group not found.'));
            if ($g['slug'] === 'admin') $data['is_admin'] = 1;
            if (val('SELECT 1 FROM fb_groups WHERE slug=? AND id<>?', [$slug, $id])) fail(t('Slug already used.'));
            db_update('fb_groups', $data, 'id=?', [$id]);
        } else {
            if (val('SELECT 1 FROM fb_groups WHERE slug=?', [$slug])) fail(t('Slug already used.'));
            db_insert('fb_groups', $data);
        }
        admin_log('group.save', $slug, 'admin ' . (int)$data['is_admin'] . ', mod ' . (int)$data['is_mod'] . ', ' . implode(' ', json_decode_array((string)$data['permissions'])));
        flash(t('Group saved.'));
        redirect($list_url);
    }
    $counts = q('SELECT group_id,COUNT(*) FROM fb_users GROUP BY group_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [];
    foreach (groups() as $g) {
        $role = (int)$g['is_admin'] ? t('Admin') : ((int)$g['is_mod'] ? t('Moderator') : t('Member'));
        $menu = in_array($g['slug'], ['admin', 'member'], true) ? [] : [action_form($list_url, '<button type="submit" class="danger">' . icon('trash') . t('Delete') . '</button>', ['action' => 'delete', 'id' => $g['id']], '', t('Delete this group?'))];
        $rows[] = ['<b style="color:' . h($g['color'] ?: 'inherit') . '">' . h($g['name']) . '</b><br><small class="muted">' . h($g['slug']) . '</small>', (int)($counts[$g['id']] ?? 0), h($role), '<small>' . h(implode(', ', $g['permissions'])) . '</small>',
            '<div class="row-actions">' . admin_drawer_link(admin_url('groups', ['edit' => $g['id']]), t('Edit')) . admin_row_menu($menu) . '</div>'];
    }
    $html = admin_table([t('Group'), t('Members'), t('Role'), t('Permissions'), ''], $rows);
    $drawer = null;
    if (($eid = get_int('edit', -1)) >= 0) {
        $edit = group_by_id($eid) ?? ['id' => 0, 'name' => '', 'slug' => '', 'color' => '', 'is_admin' => 0, 'is_mod' => 0, 'permissions' => ['post', 'reply', 'upload', 'edit_own', 'delete_own'], 'sort' => 10];
        $pc = '';
        foreach ($perms as $k => $label) $pc .= '<label class="check"><input type="checkbox" name="perm[]" value="' . h($k) . '"' . (in_array($k, (array)$edit['permissions'], true) ? ' checked' : '') . '> ' . h($label) . '</label> ';
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
            . form_row(t('Name'), input('name', (string)$edit['name'], ['required' => true]))
            . '<div class="form-grid">' . form_row(t('Slug'), input('slug', (string)$edit['slug'])) . form_row(t('Color'), input('color', (string)$edit['color'] ?: '#888888', ['type' => 'color'])) . form_row(t('Sort'), input('sort', (string)$edit['sort'], ['type' => 'number'])) . '</div>'
            . '<div class="form-row">' . checkbox('is_admin', (int)$edit['is_admin'] === 1, t('Administrator (full access)')) . '<br>' . checkbox('is_mod', (int)$edit['is_mod'] === 1, t('Moderator (manage topics and posts)')) . '</div>'
            . '<div class="form-row"><label>' . t('Permissions') . '</label>' . $pc . '</div>'
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => (int)$edit['id'] ? (string)$edit['name'] : t('New group'), 'sub' => (int)$edit['id'] ? t('Edit group') : '', 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Groups'), $html, 'groups', ['action' => admin_drawer_link(admin_url('groups', ['edit' => 0]), t('New group'), 'btn btn-primary', 'plus'), 'drawer' => $drawer]);
}

/* ---------------------------------------------------------------- confirm mode */

/** GET|POST /admin/confirm: the admin re-enters the password before a high-risk page (see need_sudo()). */
function admin_page_confirm(): never
{
    $me = need_admin();
    $back = get_str('back', 300) ?: post_str('back', 300);
    if (!preg_match('~^/(?!/)~', $back)) $back = '/admin';
    $self = admin_url('confirm', ['back' => $back]);
    if (is_post()) {
        check_csrf();
        if (!login_throttle_ok()) fail(t('Too many attempts. Please wait a minute.'), $self);
        if (!password_verify(post_secret('password'), (string)$me['password'])) {
            login_throttle_hit();
            fail(t('Current password is incorrect.'), $self);
        }
        sudo_grant($me);
        admin_log('confirm', '', 'password confirmed for ' . $back);
        $p = parse_url($back) ?: [];
        parse_str((string)($p['query'] ?? ''), $params);
        redirect(url((string)($p['path'] ?? '/admin'), $params));
    }
    $body = '<form method="post" action="' . h(admin_url('confirm')) . '" class="admin-form">' . csrf_field() . '<input type="hidden" name="back" value="' . h($back) . '">'
        . '<p class="muted">' . t('This area changes who can do what on your forum. Confirm your password to continue; you will not be asked again for %d minutes.', intdiv(max(60, sudo_ttl()), 60)) . '</p>'
        . form_row(t('Password'), input('password', '', ['type' => 'password', 'required' => true, 'autofocus' => true, 'autocomplete' => 'current-password']))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . icon('shield') . t('Confirm') . '</button></div></form>';
    admin_page(t('Confirm your password'), $body, '');
}

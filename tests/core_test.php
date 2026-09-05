<?php
/** Database schema, users, permissions, points ledger, search and language fallback. Run with: php flatbb test */

function test_schema_creates_every_core_table(): void
{
    foreach (['fb_settings', 'fb_users', 'fb_groups', 'fb_categories', 'fb_topics', 'fb_posts', 'fb_points_log', 'fb_search'] as $t) {
        test_assert(val("SELECT COUNT(*) FROM `{$t}`") !== null, $t . ' exists');
    }
}

function test_seed_creates_admin_in_admin_group(): void
{
    $u = user_by_name('admin');
    test_assert($u !== null, 'admin user exists');
    $g = group_by_id((int)$u['group_id']);
    test_same(1, (int)$g['is_admin']);
    test_assert(password_verify('admin-password-1', (string)$u['password']), 'password hash verifies');
}

function test_guest_has_no_post_permission(): void
{
    test_same(false, can('post'));
    test_same(0, uid());
}

function test_slugify_is_url_safe(): void
{
    test_same('hello-world', slugify('  Hello, World!  '));
    test_same('topic', slugify('!!!'), 'fallback slug');
    test_same(10, strlen(slugify(str_repeat('a', 50), 10)));
}

function test_points_ledger_adds_up(): void
{
    $u = user_by_name('admin');
    $uid = (int)$u['id'];
    $before = points_of($uid);
    points_add($uid, 5, 'manual', 0, 'test');
    points_add($uid, -2, 'manual', 0, 'test');
    test_same($before + 3, points_of($uid));
    test_assert(count(points_log($uid, 1, 10)) >= 2, 'ledger rows written');
}

function test_search_indexes_and_finds_a_post(): void
{
    $cat = one('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $u = user_by_name('admin');
    $tid = db_insert('fb_topics', ['category_id' => (int)$cat['id'], 'user_id' => (int)$u['id'], 'title' => 'Zebra migration guide', 'slug' => 'zebra-migration-guide', 'created_at' => now(), 'updated_at' => now(), 'last_post_at' => now()]);
    $pid = db_insert('fb_posts', ['topic_id' => $tid, 'user_id' => (int)$u['id'], 'body' => 'How to migrate zebras safely.', 'floor' => 1, 'created_at' => now()]);
    search_index_post($pid, $tid, 'Zebra migration guide', 'How to migrate zebras safely.');
    $r = search_query('zebra');
    $ids = array_map(static fn(array $row): int => (int)($row['topic_id'] ?? 0), (array)($r['rows'] ?? $r['results'] ?? []));
    test_assert(in_array($tid, $ids, true), 'search result contains the topic (got ' . json_encode($r) . ')');
}

function test_translation_falls_back_to_english(): void
{
    test_same('New Topic', t('New Topic'));
    test_same('3 replies', t('%d replies', 3));
    lang_add(['New Topic' => 'Neues Thema']);
    test_same('Neues Thema', t('New Topic'));
    lang_add(['New Topic' => '']); // empty means untranslated
    test_same('New Topic', t('New Topic'));
}

function test_language_packs_are_complete(): void
{
    $keys = lang_keys();
    foreach (lang_available() as $code => $name) {
        if ($code === 'en') continue;
        $table = (array)include LANG_DIR . '/' . $code . '.php';
        $missing = array_diff($keys, array_keys($table));
        test_same([], array_values($missing), $code . ' missing keys');
        foreach ($table as $k => $v) {
            if ($k === '__name' || $v === '') continue;
            preg_match_all('/%[sd]/', $k, $a); preg_match_all('/%[sd]/', (string)$v, $b);
            sort($a[0]); sort($b[0]);
            test_same($a[0], $b[0], $code . ' placeholders in ' . $k);
        }
    }
}

function test_region_list_orders_by_weight_and_filters_visibility(): void
{
    hook_add('region.test.links', static function (array $items, array $ctx): array {
        $items['b'] = ['label' => 'B', 'url' => '/b', 'weight' => 20];
        $items['a'] = ['label' => 'A', 'url' => '/a', 'weight' => 10];
        $items['c'] = ['label' => 'C', 'url' => '/c'];                      // no weight = 0, so it comes first
        $items['staff'] = ['label' => 'Staff', 'url' => '/staff', 'visible' => 'admins'];
        $items['me'] = ['label' => 'Me', 'url' => '/me', 'visible' => 'members'];
        return $items;
    }, 'testplugin');
    $keys = array_keys(region_list('test.links', []));
    test_same(['c', 'a', 'b'], $keys, 'guest sees public items in weight order');
    $cards = region_list('test.cards', ['user' => '<div>user card</div>', 'stats' => '<div>stats</div>']);
    test_same(['user', 'stats'], array_keys($cards), 'plain HTML items (sidebar cards) are kept in order');
    test_same('<div>stats</div>', $cards['stats']);
    save_settings(['layout_hidden_items' => json_encode_value(['test.links' => ['a' => 1]])]);
    request_cache('layout_hidden_items', null, true);
    test_same(['c', 'b'], array_keys(region_list('test.links', [])), 'hidden item is dropped');
    save_settings(['layout_hidden_items' => '{}']);
    request_cache('layout_hidden_items', null, true);
}

function test_like_escape_runs_and_matches_literally(): void
{
    test_same('%50!%off!_now!!%', db_like('50%off_now!'));
    $n = (int)val("SELECT COUNT(*) FROM fb_users WHERE username_lower LIKE ? ESCAPE '!'", [db_like('adm')]);
    test_same(1, $n, 'admin matched with the ! escape character');
    $n = (int)val("SELECT COUNT(*) FROM fb_users WHERE username_lower LIKE ? ESCAPE '!'", [db_like('a_m')]);
    test_same(0, $n, 'underscore is literal, not a wildcard');
}

function test_user_rename_keeps_old_name_for_redirects(): void
{
    $uid = user_create('rename_me', '', 'password-123');
    $u = user_by_id($uid);
    test_same('', user_rename($u, 'renamed_user', 1));
    test_assert(user_by_name('renamed_user') !== null, 'new name resolves');
    test_assert(user_by_name('rename_me') === null, 'old name is free');
    $old = user_by_former_name('RENAME_ME');
    test_same($uid, (int)($old['id'] ?? 0), 'old name (any case) finds the renamed user');
    $u = user_by_id($uid);
    test_assert(user_rename($u, 'admin') !== '', 'taken name is refused');
    test_assert(user_rename($u, 'a b') !== '', 'invalid name is refused');
    test_assert(user_rename($u, 'renamed_user') !== '', 'same name is refused');
    test_same(1, count(user_former_names($u)));
}

function test_markdown_autolink_stops_before_emphasis_markers(): void
{
    $html = md('Upload it at **https://www.flatbb.com/market/publish**. Then *https://example.com/a_b* and __https://example.com/x__');
    test_assert(str_contains($html, '<strong><a href="https://www.flatbb.com/market/publish"'), 'bold wraps the link, the URL has no trailing **');
    test_assert(!str_contains($html, 'publish**') && !str_contains($html, 'publish</strong>"'), 'no emphasis marker or tag inside the href');
    test_assert(str_contains($html, '<em><a href="https://example.com/a_b"'), 'underscore inside the URL is kept, the closing * is not');
    test_assert(str_contains($html, '<strong><a href="https://example.com/x"'), '__ around a link works too');
}

function test_client_ip_trusts_only_listed_proxies(): void
{
    test_assert(ip_in_cidr('104.16.5.9', '104.16.0.0/13'), 'ipv4 cidr');
    test_assert(!ip_in_cidr('104.32.0.1', '104.16.0.0/13'), 'ipv4 outside');
    test_assert(ip_in_cidr('2606:4700::1', '2606:4700::/32'), 'ipv6 cidr');
    test_assert(ip_in_cidr('10.0.0.1', '10.0.0.1'), 'bare address');
    $server = ['REMOTE_ADDR' => '172.68.10.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 172.68.10.10'];
    save_settings(['trusted_proxies' => '']);
    request_cache('trusted_proxies', null, true);
    test_same('172.68.10.10', client_ip_resolve($server), 'without trusted proxies the headers are ignored');
    save_settings(['trusted_proxies' => 'cloudflare']);
    request_cache('trusted_proxies', null, true);
    test_same('203.0.113.7', client_ip_resolve($server), 'behind cloudflare the CF header wins');
    test_same('198.51.100.1', client_ip_resolve(['REMOTE_ADDR' => '172.68.10.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']), 'x-forwarded-for as fallback');
    test_same('8.8.8.8', client_ip_resolve(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']), 'a spoofed header from an untrusted address is ignored');
    save_settings(['trusted_proxies' => '']);
    request_cache('trusted_proxies', null, true);
}

function test_admin_log_records_actions(): void
{
    admin_log('test.action', 'thing #1', 'detail');
    $row = one("SELECT * FROM fb_admin_log WHERE action='test.action' ORDER BY id DESC");
    test_assert($row !== null, 'row written');
    test_same('thing #1', (string)$row['target']);
}

function test_csp_policy_has_nonce_and_no_unsafe_inline_scripts(): void
{
    $p = csp_policy();
    test_assert(in_array("'nonce-" . csp_nonce() . "'", $p['script-src'], true), 'nonce in script-src');
    test_assert(!in_array("'unsafe-inline'", $p['script-src'], true), 'no unsafe-inline for scripts');
    test_assert(str_contains(script_tag('x()'), 'nonce="' . csp_nonce() . '"'), 'script_tag carries the nonce');
    test_same("'none'", $p['object-src'][0]);
}

function test_sudo_signature_is_bound_to_user_and_password(): void
{
    $u = user_by_name('admin');
    $exp = now() + 600;
    $sig = sudo_signature($u, $exp);
    test_assert(hash_equals($sig, sudo_signature($u, $exp)), 'stable');
    test_assert(!hash_equals($sig, sudo_signature(array_merge($u, ['password' => 'other']), $exp)), 'changes with the password hash');
    test_assert(!hash_equals($sig, sudo_signature($u, $exp + 1)), 'changes with the expiry');
}

function test_logout_everywhere_invalidates_old_session_signatures(): void
{
    $u = user_by_name('admin');
    $exp = now() + 600;
    $old = auth_signature((int)$u['id'], $exp, auth_key($u));
    user_logout_everywhere((int)$u['id']);
    $fresh = user_by_id((int)$u['id']);
    test_assert((string)$fresh['auth_salt'] !== '', 'salt set');
    test_assert(!hash_equals($old, auth_signature((int)$u['id'], $exp, auth_key($fresh))), 'old signature no longer matches');
}

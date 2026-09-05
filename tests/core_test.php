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
    save_settings(['layout_hidden_items' => json_encode_value(['test.links' => ['a' => 1]])]);
    request_cache('layout_hidden_items', null, true);
    test_same(['c', 'b'], array_keys(region_list('test.links', [])), 'hidden item is dropped');
    save_settings(['layout_hidden_items' => '{}']);
    request_cache('layout_hidden_items', null, true);
}

<?php
/** Posts written without an account (a guest-comments plugin, forum topic 175), threaded pages, ASCII slugs, review links, favicon. Run with: php flatbb test */

function guest_test_hooks(callable $fn): mixed
{
    $saved = hook_registry();
    try { return $fn(); } finally { hook_registry($saved); }
}

function test_post_author_names_a_guest(): void
{
    $post = ['id' => 5, 'user_id' => 0, 'meta' => '{"guest_name":"Visitor Vic"}', 'created_at' => 100];
    test_same(null, post_author($post, []), 'no plugin: no author');
    $u = guest_test_hooks(static function () use ($post): ?array {
        hook_add('post.author', static fn($v, array $ctx): array => ['username' => json_decode_array((string)$ctx['post']['meta'])['guest_name'] ?? 'Guest']);
        return post_author($post, []);
    });
    test_same('Visitor Vic', $u['username'] ?? null, 'the plugin names the guest');
    test_same(0, $u['id'], 'id 0');
    test_assert(user_is_guest($u), 'marked as a guest');
    $link = user_link($u);
    test_contains('Visitor Vic', $link, 'the name shows');
    test_not_contains('href=', $link, 'no profile link');
    test_not_contains('href=', avatar($u, 32), 'the avatar does not link either');
    $member = ['id' => 7, 'username' => 'real'];
    test_same($member, post_author(['user_id' => 7], [7 => $member]), 'a member is the loaded row');
}

function test_review_hold_reaches_guests_and_trust_stays_single(): void
{
    test_same('', review_hold_reason('reply', 0, 1, 'hello'), 'no plugin: a guest post is not held');
    $why = guest_test_hooks(static function (): string {
        hook_add('review.hold', static fn($v, array $ctx): string => !empty($ctx['guest']) ? 'Guest posts need approval' : (string)$v);
        return review_hold_reason('reply', 0, 1, 'hello');
    });
    test_same('Guest posts need approval', $why, 'the filter runs for a guest and can hold the post');
    save_settings(['review_first_posts' => '0']);
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $tid = topic_create($cat, user_create('ga_owner', 'ga_owner@example.invalid', 'password-123'), 'Guest replies here', 'Body.');
    $ids = [];
    foreach ([1, 2] as $n) {
        $pid = db_insert('fb_posts', ['topic_id' => $tid, 'user_id' => 0, 'floor' => $n, 'body' => 'Guest ' . $n, 'body_html' => '<p>Guest ' . $n . '</p>', 'created_at' => now(), 'created_ip' => '', 'meta' => '{}', 'is_deleted' => REVIEW_PENDING]);
        review_hold('reply', $tid, $pid, 0, 'Guest posts need approval');
        $ids[] = (int)val('SELECT id FROM fb_review WHERE post_id=?', [$pid]);
    }
    $admin = (int)val('SELECT MIN(u.id) FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE g.is_admin=1');
    test_same(1, review_trust(one('SELECT * FROM fb_review WHERE id=?', [$ids[0]]), $admin), 'trusting a guest approves that post only');
    test_same(0, (int)val('SELECT status FROM fb_review WHERE id=?', [$ids[1]]), 'the other guest still waits');
    test_same(false, review_ban(one('SELECT * FROM fb_review WHERE id=?', [$ids[1]]), $admin), 'there is no account to ban');
}

function test_topic_slug_folds_accents_only_for_topics(): void
{
    test_same('huong-dan-nap-tien', topic_slug('Hướng dẫn nạp tiền'), 'Vietnamese');
    test_same('da-nang', topic_slug('Đà Nẵng'), 'đ');
    test_same('grosse-uber-arger', topic_slug('Größe über Ärger'), 'German');
    test_same('lodz-krakow', topic_slug('Łódź Kraków'), 'Polish');
    test_same('中文标题-test', topic_slug('中文标题 test'), 'other scripts stay');
    test_same('café', slugify('Café'), 'slugify() itself is unchanged: category and page slugs keep their addresses');
    save_settings(['review_first_posts' => '0']);
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $tid = topic_create($cat, user_create('slug_user', 'slug_user@example.invalid', 'password-123'), 'Crème brûlée', 'Body.');
    test_same('creme-brulee', (string)val('SELECT slug FROM fb_topics WHERE id=?', [$tid]), 'new topics get the folded slug');
}

function test_review_links_lists_outside_addresses(): void
{
    $html = '<p><a href="https://spam.example/buy?x=1&amp;y=2">buy</a> <a href="' . h(absolute_url('/t/1')) . '">ours</a> <a href="/relative">rel</a> <a href="https://spam.example/buy?x=1&amp;y=2">again</a></p>';
    test_same(['https://spam.example/buy?x=1&y=2' => 'spam.example'], review_links($html), 'outside links once each, ours and relative ones left out');
    test_same([], review_links('<p>no links</p>'), 'none');
}

function test_favicon_address_and_inline_handler_rule(): void
{
    $old = setting('site_favicon');
    save_settings(['site_favicon' => '']);
    test_contains('/assets/favicon.svg', site_favicon_url(), 'the FlatBB mark by default');
    save_settings(['site_favicon' => 'site/site_favicon.png?v=5']);
    test_contains('/uploads/site/site_favicon.png?v=5', site_favicon_url(), 'the uploaded one when set');
    save_settings(['site_favicon' => $old]);
    $dir = sys_get_temp_dir() . '/flatbb-scan-' . getmypid();
    @mkdir($dir . '/views', 0777, true);
    file_put_contents($dir . '/views/x.php', "<select name=\"c\" onchange=\"this.form.submit()\"></select>\n<?php \$p = ['onerror=', 'onload=']; ?>\n<select data-autosubmit></select>\n");
    $found = security_scan([$dir . '/views/x.php']);
    test_same(1, count($found), 'one finding: the inline handler, not the quoted pattern list');
    test_contains('inline event handler (onchange=)', $found[0] ?? '', 'named');
    @unlink($dir . '/views/x.php'); @rmdir($dir . '/views'); @rmdir($dir);
}

function test_site_image_gets_a_new_name_each_upload(): void
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $up = static function () use ($png): string {
        $tmp = tempnam(sys_get_temp_dir(), 'fbimg');
        file_put_contents($tmp, $png);
        return explode('?', upload_site_image('test_favicon', ['error' => UPLOAD_ERR_OK, 'size' => strlen($png), 'name' => 'f.png', 'tmp_name' => $tmp], ['png']), 2)[0];
    };
    $a = $up(); touch(UPLOAD_DIR . '/' . $a, time() - 20);
    $b = $up(); touch(UPLOAD_DIR . '/' . $b, time() - 10);
    $c = $up();
    test_assert($a !== $b && $b !== $c, 'a new file name each time (a server that caches open files serves the new picture)');
    test_assert(str_starts_with($c, 'site/test_favicon-') && str_ends_with($c, '.png'), 'named after the setting');
    test_assert(is_file(UPLOAD_DIR . '/' . $c) && is_file(UPLOAD_DIR . '/' . $b), 'the current file and the one before (Undo) stay');
    test_assert(!is_file(UPLOAD_DIR . '/' . $a), 'older ones are deleted');
    @unlink(UPLOAD_DIR . '/' . $b); @unlink(UPLOAD_DIR . '/' . $c);
}

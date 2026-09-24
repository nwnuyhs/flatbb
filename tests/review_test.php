<?php
/** Review queue: what waits, what nobody else sees meanwhile, and what approval and rejection set off. Run with: php flatbb test */

function review_test_sign_in(int $uid): void
{
    request_cache('users_full', null, true);
    request_cache('me', null, true);
    request_cache('my_group', null, true);
    $u = user_by_id($uid);
    request_cache('me', static fn(): ?array => $u);
}

function review_test_sign_out(): void
{
    request_cache('me', null, true);
    request_cache('my_group', null, true);
    request_cache('users_full', null, true);
}

function review_test_category(): int
{
    return (int)val("SELECT id FROM fb_categories ORDER BY id LIMIT 1");
}

function test_review_holds_a_new_members_first_posts_until_approved(): void
{
    save_settings(['review_first_posts' => '2']);
    $cat = review_test_category();
    $mod = user_create('rv_mod', 'rv_mod@example.invalid', 'password-123', (int)val("SELECT id FROM fb_groups WHERE is_mod=1 AND is_admin=0 ORDER BY id LIMIT 1") ?: (int)val('SELECT id FROM fb_groups WHERE is_admin=1 LIMIT 1'));
    $new = user_create('rv_newbie', 'rv_newbie@example.invalid', 'password-123');
    review_test_sign_in($new);
    $tid = topic_create($cat, $new, 'Waiting topic about quokkaflux', 'Quokkaflux is a made-up word and this post waits.');
    $t = one('SELECT * FROM fb_topics WHERE id=?', [$tid]);
    test_same(REVIEW_PENDING, (int)$t['is_deleted'], 'the topic waits');
    test_same(REVIEW_PENDING, (int)val('SELECT is_deleted FROM fb_posts WHERE id=?', [(int)$t['first_post_id']]), 'its first post waits');
    test_same(0, (int)val('SELECT topic_count FROM fb_users WHERE id=?', [$new]), 'not counted yet');
    test_same(0, (int)search_query('quokkaflux')['total'], 'not searchable yet');
    test_same(true, content_visible($t), 'its author sees it');
    review_test_sign_in($mod);
    test_same(true, content_visible($t), 'a moderator sees it');
    $r = one('SELECT * FROM fb_review WHERE topic_id=? AND kind=?', [$tid, 'topic']);
    test_same('First posts of a new member', (string)$r['reason'], 'the reason is kept');
    test_same(true, review_approve($r, $mod), 'approved');
    test_same(0, (int)val('SELECT is_deleted FROM fb_topics WHERE id=?', [$tid]), 'public now');
    test_same(1, (int)val('SELECT topic_count FROM fb_users WHERE id=?', [$new]), 'counted now');
    test_same(1, (int)search_query('quokkaflux')['total'], 'searchable now');
    test_same(1, (int)val('SELECT COUNT(*) FROM fb_notifications WHERE user_id=? AND kind=?', [$new, 'review_approved']), 'the author is told');
    test_same(false, review_approve(one('SELECT * FROM fb_review WHERE id=?', [(int)$r['id']]), $mod), 'an item is decided once');
    save_settings(['review_first_posts' => '0']);
    review_test_sign_out();
}

function test_review_rejects_a_reply_and_keeps_it_from_others(): void
{
    save_settings(['review_first_posts' => '1']);
    $cat = review_test_category();
    $admin = (int)val('SELECT u.id FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE g.is_admin=1 ORDER BY u.id LIMIT 1') ?: user_create('rv_admin', 'rv_admin@example.invalid', 'password-123', (int)val('SELECT id FROM fb_groups WHERE is_admin=1 LIMIT 1'));
    review_test_sign_in($admin);
    $tid = topic_create($cat, $admin, 'An open topic for replies', 'Anyone may reply here.');
    test_same(0, (int)val('SELECT is_deleted FROM fb_topics WHERE id=?', [$tid]), 'an administrator never waits');
    $spam = user_create('rv_spammer', 'rv_spammer@example.invalid', 'password-123');
    review_test_sign_in($spam);
    $pid = post_create((array)topic_by_id($tid), $spam, 'Buy cheap things at my shop');
    test_same(REVIEW_PENDING, (int)val('SELECT is_deleted FROM fb_posts WHERE id=?', [$pid]), 'the reply waits');
    test_same(0, (int)val('SELECT reply_count FROM fb_topics WHERE id=?', [$tid]), 'the topic does not count it');
    $other = user_create('rv_reader', 'rv_reader@example.invalid', 'password-123');
    review_test_sign_in($other);
    test_same(false, content_visible((array)post_by_id($pid)), 'another member does not see it');
    review_test_sign_in($admin);
    $r = one('SELECT * FROM fb_review WHERE post_id=?', [$pid]);
    test_same(true, review_reject($r, $admin, 'Advertising is not allowed.'), 'rejected');
    test_same(1, (int)val('SELECT is_deleted FROM fb_posts WHERE id=?', [$pid]), 'deleted');
    test_same('Advertising is not allowed.', (string)val('SELECT content FROM fb_notifications WHERE user_id=? AND kind=?', [$spam, 'review_rejected']), 'the author gets the note');
    save_settings(['review_first_posts' => '0']);
    review_test_sign_out();
}

function test_review_category_and_trust(): void
{
    $cat = review_test_category();
    db_update('fb_categories', ['review_topics' => 1], 'id=?', [$cat]);
    request_cache('categories', null, true);
    $u = user_create('rv_trusted', 'rv_trusted@example.invalid', 'password-123');
    db_update('fb_users', ['prefs' => json_encode_value(['review_trusted' => 1])], 'id=?', [$u]);
    request_cache('users_full', null, true);
    test_same('New topics in this category need approval', review_hold_reason('topic', $u, $cat, 'x'), 'the category holds every new topic, trusted or not');
    test_same('', review_hold_reason('reply', $u, $cat, 'x'), 'but not replies');
    db_update('fb_categories', ['review_topics' => 0], 'id=?', [$cat]);
    request_cache('categories', null, true);
    save_settings(['review_first_posts' => '5']);
    test_same('', review_hold_reason('topic', $u, $cat, 'x'), 'a trusted member skips the new-member rule');
    save_settings(['review_first_posts' => '0']);
    review_test_sign_out();
}

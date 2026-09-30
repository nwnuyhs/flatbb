<?php
/** Banning and deleting a spammer (app/moderation.php): who may do what, and what is left afterwards. Run with: php flatbb test */

function modtest_sign_in(int $uid): void
{
    foreach (['users_full', 'me', 'my_group'] as $k) request_cache($k, null, true);
    $u = user_by_id($uid);
    request_cache('me', static fn(): ?array => $u);
}

function modtest_group(string $slug): int
{
    return (int)val('SELECT id FROM fb_groups WHERE slug=?', [$slug]);
}

/** A spammer with two topics (one with a real member's reply), a reply in a real member's topic and a like. */
function modtest_setup(string $p): array
{
    save_settings(['review_first_posts' => '0']);
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $admin = (int)val("SELECT MIN(u.id) FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE g.is_admin=1");
    $mod = user_create($p . 'mod', $p . 'mod@example.invalid', 'password-123', modtest_group('moderator'));
    $good = user_create($p . 'good', $p . 'good@example.invalid', 'password-123');
    $spam = user_create($p . 'spam', $p . 'spam@example.invalid', 'password-123');
    q('UPDATE fb_users SET created_ip=? WHERE id=?', ['198.51.100.7', $spam]);
    modtest_sign_in($good);
    $good_topic = topic_create($cat, $good, 'A real question about zebrafloss', 'Real content here.', ['realtag']);
    $w = 'quixotrap' . rtrim($p, '_'); // a word of this test's own spam, for the search checks
    modtest_sign_in($spam);
    $s1 = topic_create($cat, $spam, 'Promo code ' . $w . ' one', 'Buy now ' . $w . '.', [$p . 'spamtag']);
    $s2 = topic_create($cat, $spam, 'Promo code ' . $w . ' two', 'Buy again ' . $w . '.', [$p . 'spamtag']);
    $spam_reply = post_create(topic_by_id($good_topic), $spam, 'Check my ' . $w . ' offer');
    request_cache('topic_' . $s1, null, true);
    modtest_sign_in($good);
    $good_reply = post_create(topic_by_id($s1), $good, 'This looks like spam to me');
    import_like($spam, (int)val('SELECT first_post_id FROM fb_topics WHERE id=?', [$good_topic]), $good_topic); // the spammer liked the real post
    q('UPDATE fb_posts SET like_count=1 WHERE id=?', [(int)val('SELECT first_post_id FROM fb_topics WHERE id=?', [$good_topic])]);
    q('UPDATE fb_users SET like_count=1 WHERE id=?', [$good]);
    db_insert('fb_attachments', ['user_id' => $spam, 'post_id' => (int)val('SELECT first_post_id FROM fb_topics WHERE id=?', [$s1]), 'name' => 'x.png', 'path' => 'attachments/modtest-' . $p . '.png', 'mime' => 'image/png', 'size' => 3, 'is_image' => 1, 'width' => 1, 'height' => 1, 'hash' => '', 'created_at' => now()]);
    @mkdir(UPLOAD_DIR . '/attachments', 0777, true);
    file_put_contents(UPLOAD_DIR . '/attachments/modtest-' . $p . '.png', 'png');
    return compact('w', 'cat', 'admin', 'mod', 'good', 'spam', 'good_topic', 's1', 's2', 'spam_reply', 'good_reply');
}

function test_moderate_who_may_do_what(): void
{
    $x = modtest_setup('mw_');
    $admin = user_by_id($x['admin']); $mod = user_by_id($x['mod']); $good = user_by_id($x['good']);
    test_same('', user_moderate_refusal(user_by_id($x['spam']), $mod), 'a moderator may ban a member');
    test_assert(user_moderate_refusal($admin, $mod) !== '', 'nobody bans an administrator');
    test_assert(user_moderate_refusal($mod, $mod) !== '', 'not oneself');
    test_assert(user_moderate_refusal($good, $good) !== '', 'a member may not');
    $other_mod = user_by_id(user_create('mw_mod2', 'mw_mod2@example.invalid', 'password-123', modtest_group('moderator')));
    test_assert(user_moderate_refusal($other_mod, $mod) !== '', 'a moderator does not ban a moderator');
    test_same('', user_moderate_refusal($other_mod, $admin), 'an administrator may');
    $r = user_moderate([$x['spam']], 'delete', $mod);
    test_same([], $r['done'], 'a moderator may not delete a member');
    test_assert(user_by_id($x['spam']) !== null, 'still there');
}

function test_moderate_ban_signs_out_and_lift(): void
{
    $x = modtest_setup('mb_');
    $salt = (string)val('SELECT auth_salt FROM fb_users WHERE id=?', [$x['spam']]);
    $r = user_moderate([$x['spam']], 'ban', user_by_id($x['mod']));
    test_same([$x['spam']], $r['done'], 'banned');
    test_same(0, (int)val('SELECT status FROM fb_users WHERE id=?', [$x['spam']]), 'suspended');
    test_assert((string)val('SELECT auth_salt FROM fb_users WHERE id=?', [$x['spam']]) !== $salt, 'signed out everywhere');
    test_same(0, (int)val('SELECT is_deleted FROM fb_topics WHERE id=?', [$x['s1']]), 'a ban alone leaves the posts');
    user_moderate([$x['spam']], 'unban', user_by_id($x['mod']));
    test_same(1, (int)val('SELECT status FROM fb_users WHERE id=?', [$x['spam']]), 'ban lifted');
}

function test_moderate_remove_hides_everything_and_fixes_counts(): void
{
    $x = modtest_setup('mr_');
    $r = user_moderate([$x['spam']], 'remove', user_by_id($x['mod']));
    test_same(2, $r['topics'], 'two topics');
    test_same(1, $r['replies'], 'one reply');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_posts WHERE user_id=? AND is_deleted=0', [$x['spam']]), 'every post removed');
    test_same(1, (int)val('SELECT is_deleted FROM fb_topics WHERE id=?', [$x['s2']]), 'topics removed');
    test_same(0, (int)val('SELECT reply_count FROM fb_topics WHERE id=?', [$x['good_topic']]), 'the real topic counts its replies again');
    test_same(0, (int)search_query($x['w'])['total'], 'gone from search');
    test_same(0, (int)val("SELECT topic_count FROM fb_tags WHERE name='mr_spamtag'"), 'tag count');
    test_same(0, (int)val('SELECT status FROM fb_users WHERE id=?', [$x['spam']]), 'and banned');
    test_assert(user_by_id($x['spam']) !== null, 'the account stays');
}

function test_moderate_delete_removes_the_member_and_all_they_wrote(): void
{
    $x = modtest_setup('md_');
    $file = UPLOAD_DIR . '/attachments/modtest-md_.png';
    $r = user_moderate([$x['spam']], 'delete', user_by_id($x['admin']));
    test_same([$x['spam']], $r['done'], 'deleted');
    test_same(null, user_by_id($x['spam']), 'the account is gone');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_topics WHERE id IN (?,?)', [$x['s1'], $x['s2']]), 'their topics are gone');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_posts WHERE id=?', [$x['good_reply']]), 'with the replies in them');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_posts WHERE id=?', [$x['spam_reply']]), 'their reply elsewhere is gone');
    test_same(1, (int)val('SELECT COUNT(*) FROM fb_topics WHERE id=?', [$x['good_topic']]), 'the real topic stays');
    test_same(0, (int)val('SELECT reply_count FROM fb_topics WHERE id=?', [$x['good_topic']]), 'and counts no reply');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_likes WHERE user_id=?', [$x['spam']]), 'their likes are gone');
    test_same(0, (int)val('SELECT like_count FROM fb_users WHERE id=?', [$x['good']]), 'the liked member counts again');
    test_same(0, (int)val('SELECT post_count FROM fb_users WHERE id=?', [$x['good']]), 'the real member lost the reply in the spam topic');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_attachments WHERE user_id=?', [$x['spam']]), 'attachment rows gone');
    test_same(false, is_file($file), 'the uploaded file is deleted');
    test_same(0, (int)search_query($x['w'])['total'], 'gone from search');
}

function test_moderate_bulk_skips_whom_it_may_not_touch(): void
{
    $x = modtest_setup('mk_');
    $r = user_moderate([$x['spam'], $x['admin'], $x['mod']], 'ban', user_by_id($x['mod']));
    test_same([$x['spam']], $r['done'], 'only the member');
    test_same(2, count($r['skipped']), 'the administrator and oneself are skipped');
    test_contains('Skipped', user_moderate_message($r, 'ban'), 'the message says so');
}

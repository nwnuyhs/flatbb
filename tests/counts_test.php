<?php
/** Members' and tags' counts follow deletes and restores (forum topic 172). Run with: php flatbb test */

function counts_test_member(string $p): array
{
    save_settings(['review_first_posts' => '0']);
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $a = user_create($p . 'a', $p . 'a@example.invalid', 'password-123');
    $b = user_create($p . 'b', $p . 'b@example.invalid', 'password-123');
    $tid = topic_create($cat, $a, 'Counting topic ' . $p, 'Body of the counting topic.', [$p . 'tag']);
    request_cache('topic_' . $tid, null, true);
    $pid = post_create(topic_by_id($tid), $b, 'A reply that will be deleted.');
    return [$a, $b, $tid, $pid];
}

function counts_test_user(int $id): array
{
    return one('SELECT topic_count,post_count FROM fb_users WHERE id=?', [$id]) ?? [];
}

function test_topic_delete_and_restore_move_the_author_count(): void
{
    [$a, , $tid] = counts_test_member('cd_');
    test_same(1, (int)counts_test_user($a)['topic_count'], 'counted when posted');
    $tag = (int)val("SELECT id FROM fb_tags WHERE slug='cdtag'");
    test_same(1, (int)val('SELECT topic_count FROM fb_tags WHERE id=?', [$tag]), 'the tag counts it');
    q('UPDATE fb_topics SET is_deleted=1 WHERE id=?', [$tid]); // what topic_action('delete') writes, then:
    topic_counts_refresh(topic_by_id($tid));
    test_same(0, (int)counts_test_user($a)['topic_count'], 'the author\'s count drops');
    test_same(0, (int)val('SELECT topic_count FROM fb_tags WHERE id=?', [$tag]), 'and the tag\'s');
    q('UPDATE fb_topics SET is_deleted=0 WHERE id=?', [$tid]);
    topic_counts_refresh(topic_by_id($tid));
    test_same(1, (int)counts_test_user($a)['topic_count'], 'restored, counted again');
    test_same(1, (int)val('SELECT topic_count FROM fb_tags WHERE id=?', [$tag]), 'the tag too');
}

function test_reply_delete_moves_the_member_count(): void
{
    [, $b, , $pid] = counts_test_member('cr_');
    test_same(1, (int)counts_test_user($b)['post_count'], 'the reply counts');
    q('UPDATE fb_posts SET is_deleted=1 WHERE id=?', [$pid]);
    users_refresh_counts([$b]);
    test_same(0, (int)counts_test_user($b)['post_count'], 'gone once deleted');
    test_same(0, (int)(user_by_id($b)['post_count'] ?? -1), 'and the cached user row is fresh');
}

function test_tags_refresh_counts_all_ignores_deleted_topics(): void
{
    [, , $tid] = counts_test_member('ct_');
    q('UPDATE fb_topics SET is_deleted=1 WHERE id=?', [$tid]);
    q("UPDATE fb_tags SET topic_count=9 WHERE slug='cttag'");
    tags_refresh_counts();
    test_same(0, (int)val("SELECT topic_count FROM fb_tags WHERE slug='cttag'"), 'Recalculate counters counts visible topics only');
}

<?php
/** Opening an unread topic lands on the first post the member has not read, even on a later page. Run with: php flatbb test */

function test_topic_first_unread_finds_the_post_and_its_page(): void
{
    $author = user_create('unread_author', '', 'password-123');
    $reader = user_create('unread_reader', '', 'password-123');
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $tid = topic_create($cat, $author, 'A topic that grows past one page', 'The first post of a long topic.');
    $topic = topic_by_id($tid);
    $ids = [(int)val('SELECT MIN(id) FROM fb_posts WHERE topic_id=?', [$tid])];
    for ($i = 1; $i <= 24; $i++) $ids[] = post_create(topic_by_id($tid), $author, 'Reply number ' . $i . ' with enough words in it.');
    $per = max(5, min(100, (int)setting('posts_per_page', '20')));

    test_assert(topic_first_unread($topic, 0) === null, 'a visitor is sent to the topic itself');
    test_assert(topic_first_unread($topic, $reader) === null, 'a topic never opened starts at the top');

    // the reader saw the first page only: the first unread post is the first one of page 2
    db_upsert('fb_topic_reads', ['user_id' => $reader, 'topic_id' => $tid, 'last_post_id' => $ids[$per - 1], 'read_at' => now()], ['user_id', 'topic_id']);
    test_same(['post_id' => $ids[$per], 'page' => 2], topic_first_unread($topic, $reader), 'first page read');

    // a deleted post is skipped and does not count for the page
    db_update('fb_posts', ['is_deleted' => 1], 'id=?', [$ids[$per]]);
    test_same(['post_id' => $ids[$per + 1], 'page' => 2], topic_first_unread($topic, $reader), 'deleted post skipped');
    db_update('fb_posts', ['is_deleted' => 0], 'id=?', [$ids[$per]]);

    // read in the middle of the first page: that page
    db_upsert('fb_topic_reads', ['user_id' => $reader, 'topic_id' => $tid, 'last_post_id' => $ids[4], 'read_at' => now()], ['user_id', 'topic_id']);
    test_same(['post_id' => $ids[5], 'page' => 1], topic_first_unread($topic, $reader), 'read to the fifth post');

    // everything read: the topic itself
    db_upsert('fb_topic_reads', ['user_id' => $reader, 'topic_id' => $tid, 'last_post_id' => end($ids), 'read_at' => now()], ['user_id', 'topic_id']);
    test_assert(topic_first_unread($topic, $reader) === null, 'nothing unread');

    test_same(url('/t/' . $tid . '/unread'), topic_unread_url($topic), 'unread link');
}

function test_topic_new_from_marks_the_first_post_not_read_before(): void
{
    $posts = [['id' => 10], ['id' => 11], ['id' => 12, 'is_deleted' => 1], ['id' => 13], ['id' => 14]];
    test_same(0, topic_new_from($posts, 0), 'never opened: no line');
    test_same(11, topic_new_from($posts, 10), 'the line goes above the first post after the last one read');
    test_same(13, topic_new_from([['id' => 12, 'is_deleted' => 1], ['id' => 13]], 11), 'a deleted post is passed over');
    test_same(0, topic_new_from($posts, 14), 'everything on the page was read');
}

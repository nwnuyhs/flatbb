<?php
/** notify_many(): one notification for many members in a few queries, with the same rules as notify(). Run with: php flatbb test */

function test_notify_many_follows_the_rules(): void
{
    $from = user_create('many_writer', 'many_writer@example.invalid', 'password-123');
    $a = user_create('many_a', 'many_a@example.invalid', 'password-123');
    $b = user_create('many_b', 'many_b@example.invalid', 'password-123');
    $off = user_create('many_off', 'many_off@example.invalid', 'password-123');
    $gone = user_create('many_gone', 'many_gone@example.invalid', 'password-123');
    db_update('fb_users', ['prefs' => json_encode_value(['notify_news' => 0])], 'id=?', [$off]);
    db_update('fb_users', ['status' => 0], 'id=?', [$gone]);
    $n = notify_many([$a, $b, $b, $off, $gone, $from, 0], $from, 'news', 'hello', 0, 0);
    test_same(2, $n, 'two members notified: no repeats, no sender, no switched-off kind, no suspended account');
    test_same(1, (int)val('SELECT COUNT(*) FROM fb_notifications WHERE user_id=? AND kind=?', [$b, 'news']), 'one row per member');
    test_same(0, (int)val('SELECT COUNT(*) FROM fb_notifications WHERE user_id IN (?,?,?) AND kind=?', [$off, $gone, $from, 'news']), 'nobody else');
    test_same(1, (int)val('SELECT unread_notifications FROM fb_users WHERE id=?', [$a]), 'unread count raised');
    test_same(0, (int)val('SELECT unread_notifications FROM fb_users WHERE id=?', [$off]), 'switched off: count unchanged');
}

function test_notify_many_writes_large_lists_in_chunks(): void
{
    $from = user_create('many_big', 'many_big@example.invalid', 'password-123');
    $ids = [];
    for ($i = 0; $i < 230; $i++) $ids[] = user_create('many_r' . $i, 'many_r' . $i . '@example.invalid', 'password-123');
    test_same(230, notify_many($ids, $from, 'bulk'), 'everyone notified across three chunks');
    test_same(230, (int)val('SELECT COUNT(*) FROM fb_notifications WHERE kind=?', ['bulk']), 'every row written');
}

function test_notification_kinds_switch_off_by_their_own_preference(): void
{
    $to = user_create('kind_reader', 'kind_reader@example.invalid', 'password-123');
    $from = user_create('kind_writer', 'kind_writer@example.invalid', 'password-123');
    db_update('fb_users', ['prefs' => json_encode_value(['notify_follow' => 0])], 'id=?', [$to]);
    request_cache('users_full', null, true);
    test_same(false, notify($to, $from, 'follow'), 'a plugin kind switched off by notify_<kind>');
    test_same(true, notify($to, $from, 'like', 'x'), 'other kinds still sent');
}

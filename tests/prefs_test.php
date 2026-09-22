<?php
/** Settings → Preferences: the answers survive the language write, and the notification checkboxes decide. Run with: php flatbb test */

/** Sign a member in for the rest of the test, the way login_user() does, without touching cookies. */
function prefs_sign_in(int $uid): void
{
    $u = user_by_id($uid);
    request_cache('me', null, true);
    request_cache('me', static fn(): ?array => $u);
}

function test_preferences_survive_the_language_write(): void
{
    $uid = user_create('prefs_saver', 'prefs_saver@example.invalid', 'password-123');
    prefs_sign_in($uid);
    $prefs = ['theme' => 'dark', 'notify_reply' => 0, 'notify_mention' => 0, 'show_points' => 0, 'lang' => 'ru'];
    db_update('fb_users', ['prefs' => json_encode_value($prefs)], 'id=?', [$uid]);
    lang_set('ru', $prefs); // what Settings → Preferences does after writing the row
    $saved = json_decode_array((string)val('SELECT prefs FROM fb_users WHERE id=?', [$uid]));
    test_same('dark', (string)($saved['theme'] ?? ''), 'theme kept');
    test_same(0, (int)($saved['notify_reply'] ?? 1), 'reply notifications kept');
    test_same(0, (int)($saved['show_points'] ?? 1), 'points preference kept');
    test_same('ru', (string)($saved['lang'] ?? ''), 'language saved');
    request_cache('me', null, true);
}

function test_language_switcher_keeps_the_other_preferences(): void
{
    $uid = user_create('prefs_switcher', 'prefs_switcher@example.invalid', 'password-123');
    prefs_sign_in($uid); // the row is now in the request cache without the preferences written below
    db_update('fb_users', ['prefs' => json_encode_value(['theme' => 'dark', 'notify_reply' => 0])], 'id=?', [$uid]);
    lang_set('ru'); // the header switcher, which reads the row again
    $saved = json_decode_array((string)val('SELECT prefs FROM fb_users WHERE id=?', [$uid]));
    test_same('dark', (string)($saved['theme'] ?? ''), 'theme kept');
    test_same(0, (int)($saved['notify_reply'] ?? 1), 'reply notifications kept');
    test_same('ru', (string)($saved['lang'] ?? ''), 'language saved');
    request_cache('me', null, true);
}

function test_notification_preferences_decide(): void
{
    $to = user_create('notify_reader', 'notify_reader@example.invalid', 'password-123');
    $from = user_create('notify_writer', 'notify_writer@example.invalid', 'password-123');
    db_update('fb_users', ['prefs' => json_encode_value(['notify_reply' => 0, 'notify_mention' => 1])], 'id=?', [$to]);
    request_cache('users_full', null, true);
    test_same(false, notify($to, $from, 'reply', 'hello'), 'replies switched off');
    test_same(true, notify($to, $from, 'mention', 'hello'), 'mentions switched on');
    test_same(true, notify($to, $from, 'like', 'hello'), 'kinds without a preference are always sent');
    db_update('fb_users', ['prefs' => json_encode_value(['notify_reply' => 1])], 'id=?', [$to]);
    request_cache('users_full', null, true);
    test_same(true, notify($to, $from, 'reply', 'hello again'), 'replies switched on again');
}

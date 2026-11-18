<?php
/** Marketplace rules (English first, forum topic 198) and the review queue skip for trusted automation. Run with: php flatbb test */

function test_text_not_latin_spots_other_scripts(): void
{
    test_same(false, text_not_latin('Cap Captcha — verify humans'), 'English');
    test_same(false, text_not_latin('Crème brûlée, Đà Nẵng, Größe'), 'accented Latin is fine');
    test_same(true, text_not_latin('Cap 人机验证'), 'Chinese');
    test_same(true, text_not_latin('Привет'), 'Cyrillic');
    test_same(true, text_not_latin('پیک'), 'Persian');
    test_same(false, text_not_latin('123 → ok!'), 'no letters at all');
}

function test_plugin_text_not_english_lists_the_places(): void
{
    $dir = sys_get_temp_dir() . '/flatbb-rules-' . getmypid();
    @mkdir($dir . '/lang', 0777, true);
    file_put_contents($dir . '/plugin.php', "<?php\necho t('Hello'); echo t('启用人机验证'); echo t(\"另一个\");\n");
    file_put_contents($dir . '/lang/zh-cn.php', "<?php return ['Hello' => t('你好')];\n");
    $m = ['name' => 'Cap 人机验证', 'description' => 'Fine in English', 'settings' => ['on' => ['label' => '启用', 'help' => 'Turn it on'], 'mode' => ['label' => 'Mode', 'options' => ['a' => 'Easy', 'b' => '困难']]], 'admin_pages' => ['log' => ['label' => '日志']]];
    $where = plugin_text_not_english($m, $dir);
    test_same(['The manifest "name"', 'Setting "on" label', 'Setting "mode" options', 'Admin page "log" label', 'plugin.php: 2 t() texts'], $where, 'every place, the language pack left alone');
    test_same([], plugin_text_not_english(['name' => 'Wiki', 'description' => 'A wiki'], ''), 'an English plugin passes');
    @unlink($dir . '/plugin.php'); @unlink($dir . '/lang/zh-cn.php'); @rmdir($dir . '/lang'); @rmdir($dir);
}

function test_review_skip_lets_trusted_automation_post(): void
{
    save_settings(['review_first_posts' => '3']);
    $u = user_create('skip_new', 'skip_new@example.invalid', 'password-123');
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    test_assert(review_hold_reason('topic', $u, $cat, 'x') !== '', 'a new member waits');
    test_same('', review_skip(static fn(): string => review_hold_reason('topic', $u, $cat, 'x')), 'not inside review_skip()');
    $tid = review_skip(static fn(): int => topic_create($cat, $u, 'Marketplace topic', 'Body.'));
    test_same(0, (int)val('SELECT is_deleted FROM fb_topics WHERE id=?', [$tid]), 'the topic is public at once');
    test_assert(review_hold_reason('topic', $u, $cat, 'x') !== '', 'and the queue is back on afterwards');
    save_settings(['review_first_posts' => '0']);
}

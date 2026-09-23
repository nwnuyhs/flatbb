<?php
/** Admin → Appearance → Menus: the admin's edits apply to any item of a menu, custom links join it, unsafe links never do. Run with: php flatbb test */

function test_menu_edits_apply_in_region_list(): void
{
    save_settings(['menu_items' => json_encode_value(['footer.links' => [
        'rss' => ['label' => 'Feed', 'url' => '/feed?x=1'],
        'mhelp' => ['custom' => 1, 'label' => 'Help', 'url' => '/c/help', 'weight' => 50],
        'mbad' => ['custom' => 1, 'label' => 'Bad', 'url' => 'javascript:alert(1)', 'weight' => 60],
        'mfar' => ['custom' => 1, 'label' => 'Far', 'url' => 'https://example.com/x', 'new_tab' => 1, 'weight' => 70],
        'gone' => ['label' => 'Nobody has this item'],
    ]])]);
    request_cache('menu_items', null, true);
    $items = region_list('footer.links', ['rss' => ['label' => 'RSS', 'url' => url('/feed'), 'weight' => 10], 'tags' => ['label' => 'Tags', 'url' => url('/tags'), 'weight' => 20]]);
    save_settings(['menu_items' => '{}']);
    request_cache('menu_items', null, true);

    test_same('Feed', $items['rss']['label'] ?? '', 'a built-in item takes the new text');
    test_same(url('/feed', ['x' => '1']), $items['rss']['url'] ?? '', 'and the new link, through url()');
    test_same('Tags', $items['tags']['label'] ?? '', 'an item without edits stays as it was');
    test_same(url('/c/help'), $items['mhelp']['url'] ?? '', 'a custom link joins the menu');
    test_same(false, isset($items['mbad']), 'a javascript: link is dropped');
    test_same(true, !empty($items['mfar']['new_tab']) && ($items['mfar']['url'] ?? '') === 'https://example.com/x', 'a full https address stays, in a new tab');
    test_same(false, isset($items['gone']), 'an edit of an item nobody adds adds nothing');
    test_same('', menu_href('//evil.example/x'), 'a protocol-relative address is refused');
}

<?php
/** Picture fields and saved keys (image_field(), secret_field()) and the Undo store behind them. Run with: php flatbb test */

function test_fields_markup(): void
{
    $empty = image_field('site_favicon', '', ['action' => '/admin/settings?section=general']);
    test_same(true, str_contains($empty, 'is-empty') && str_contains($empty, 'data-action="/admin/settings?section=general"') && !str_contains($empty, '<noscript>'), 'empty: a placeholder, no Remove box');
    $set = image_field('site_favicon', 'site/a.png?v=1', ['wide' => true]);
    test_same(true, !str_contains($set, 'is-empty') && str_contains($set, 'site_favicon_remove') && !str_contains($set, 'data-action'), 'set: the picture, a Remove box for pages without JavaScript');
    $key = secret_field('ai_key', 'sk-abcdefghijklmnop1234', ['action' => '/x']);
    test_same(true, str_contains($key, '…1234') && !str_contains($key, 'abcdefgh'), 'a saved key shows its last four characters only');
    test_same(false, str_contains(secret_field('k', 'short'), 'hort'), 'a short key shows nothing of itself');
    test_same(true, str_contains(secret_field('k', ''), 'is-empty'), 'no key: the input');
    test_same(false, str_contains(image_field('x"><script>', ''), '<script>'), 'the name is escaped');
}

function test_undo_store(): void
{
    undo_keep('test:field', 'site/old.png');
    test_same('site/old.png', undo_take('test:field'), 'what a removal replaced comes back');
    test_same(null, undo_take('test:field'), 'once');
    undo_keep('test:field', 'site/old.png');
    $me = user_by_id(1) ?? ['id' => 1];
    request_cache('me', null, true);
    request_cache('me', static fn(): array => ['id' => 999999] + $me);
    test_same(null, undo_take('test:field'), 'and only for whoever removed it');
    request_cache('me', null, true);
    undo_take('test:field');
}

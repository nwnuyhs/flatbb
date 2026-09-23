<?php
/** A new topic without a category: a plugin's pick first, then the default category when one is not required. Run with: php flatbb test */

function category_fallback_run(callable $fn): mixed
{
    $saved = hook_registry();
    $member = user_by_id(user_create('cat_fallback', 'cat_fallback@example.invalid', 'password-123'));
    request_cache('me', null, true);
    request_cache('me', static fn(): ?array => $member);
    try { return $fn($member); } finally { hook_registry($saved); request_cache('me', null, true); save_settings(['category_required' => '1', 'default_category' => '0']); }
}

function test_category_fallback_order(): void
{
    $general = db_insert('fb_categories', ['parent_id' => 0, 'name' => 'Fallback General', 'slug' => 'fallback-general', 'description' => '', 'sort' => 90, 'post_groups' => '', 'view_groups' => '']);
    $staff = db_insert('fb_categories', ['parent_id' => 0, 'name' => 'Fallback Staff', 'slug' => 'fallback-staff', 'description' => '', 'sort' => 91, 'post_groups' => '1', 'view_groups' => '']); // admins only
    request_cache('categories', null, true);
    category_fallback_run(static function (array $me) use ($general, $staff): void {
        save_settings(['category_required' => '1', 'default_category' => (string)$general]);
        test_same(null, topic_category_fallback('A title', 'A body', $me), 'required: nothing is picked, the writer is asked');
        test_same(false, topic_category_optional(), 'required: the composer keeps the category required');
        save_settings(['category_required' => '0']);
        test_same($general, (int)(topic_category_fallback('A title', 'A body', $me)['id'] ?? 0), 'not required: the default category');
        test_same(true, topic_category_optional(), 'not required: the category may stay empty');
        save_settings(['default_category' => (string)$staff]);
        test_same(null, topic_category_fallback('A title', 'A body', $me), 'a default the writer may not post in is not used');
        save_settings(['category_required' => '1']);
        hook_add('topic.category_missing', static fn(int $id, array $ctx): int => $id ?: $general);
        test_same($general, (int)(topic_category_fallback('A title', 'A body', $me)['id'] ?? 0), 'a plugin picks even when a category is required');
        hook_add('topic.category_missing', static fn(int $id, array $ctx): int => $staff, '', 20);
        test_same(null, topic_category_fallback('A title', 'A body', $me), 'a pick the writer may not post in is refused');
        test_same(false, topic_category_optional(), 'no plugin says it picks: the category stays required');
        hook_add('topic.category_auto', static fn(bool $a): bool => true);
        test_same(true, topic_category_optional(), 'a plugin ready to pick makes it optional');
    });
}

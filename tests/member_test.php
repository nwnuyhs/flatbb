<?php
/** The shared member.* regions: one hook reaches the sidebar member card, the account menu and the profile. Run with: php flatbb test */

function test_member_regions_reach_the_card_and_the_menu(): void
{
    $saved = hook_registry();
    $uid = user_create('member_card', 'member_card@example.invalid', 'password-123');
    $me = user_by_id($uid);
    request_cache('me', null, true);
    request_cache('me', static fn(): ?array => $me); // signed in, so New Topic is offered
    $places = [];
    hook_add('region.member.labels', static function (string $html, array $ctx) use (&$places): string {
        $places[] = (string)($ctx['place'] ?? '');
        return $html . '<span class="mt-label">Lv9</span>';
    });
    hook_add('region.member.stats', static function (array $items, array $ctx): array {
        if (($ctx['place'] ?? '') !== 'card') return $items;
        $items['streak'] = ['label' => 'Streak', 'value' => '5'];
        $items['growth'] = ['label' => 'Growth', 'value' => 'Lv 9', 'sub' => '10 to go', 'progress' => 0.25];
        return $items;
    });
    hook_add('region.member.actions', static function (array $items, array $ctx): array {
        $items['checkin'] = ['label' => 'Check in', 'url' => '/checkin', 'icon' => 'check', 'count' => 2];
        return $items;
    });
    request_cache('list_category', static fn(): int => 7);
    $card = view('card_user', ['me' => $me]);
    $menu = view('user_menu', ['me' => $me, 'items' => []]);
    hook_registry($saved);
    request_cache('list_category', null, true);
    request_cache('me', null, true);

    test_same(true, str_contains($card, 'mt-label') && str_contains($menu, 'mt-label'), 'one labels hook shows in the card and the menu');
    test_same(['card', 'menu'], $places, 'the place tells them apart');
    test_same(true, str_contains($card, '<span>Streak</span>'), 'a plugin number becomes a tile');
    test_same(true, str_contains($card, 'class="me-bar"') && str_contains($card, 'width:25%'), 'a number with progress becomes a bar');
    test_same(true, !str_contains($menu, 'Streak'), 'the card-only number stays out of the menu');
    test_same(true, str_contains($card, 'data-action="checkin"') && str_contains($card, 'me-count'), 'a plugin button joins New Topic');
    test_same(true, str_contains($card, 'data-action="new"') && str_contains($card, 'category=7'), 'New Topic follows the category of the list');
}

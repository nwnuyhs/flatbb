<?php
/** Display names: shown instead of the username while the setting is on; the username stays the address. Run with: php flatbb test */

function test_display_name_rules(): void
{
    $a = user_create('dn_alice', 'dn_alice@example.invalid', 'password-123');
    user_create('dn_bob', 'dn_bob@example.invalid', 'password-123');
    $alice = (array)user_by_id($a);
    test_same("Alice \u{4E3D}\u{4E1D}", display_name_clean("  Alice \u{200B}  \u{4E3D}\u{4E1D} \n"), 'spaces collapsed, zero-width space removed');
    test_same('', display_name_set($alice, "\u{7231}\u{4E3D}\u{4E1D}"), 'a Chinese display name is fine');
    test_same("\u{7231}\u{4E3D}\u{4E1D}", (string)val('SELECT display_name FROM fb_users WHERE id=?', [$a]), 'stored');
    test_same(true, display_name_set((array)user_by_id($a), 'DN_Bob') !== '', "another member's username is refused");
    test_same(true, display_name_set((array)user_by_id($a), str_repeat('x', 31)) !== '', 'more than 30 characters is refused');
    test_same('', display_name_set((array)user_by_id($a), 'DN_ALICE'), 'the own username is allowed ...');
    test_same('', (string)val('SELECT display_name FROM fb_users WHERE id=?', [$a]), '... and simply clears the display name');
}

function test_display_name_shows_only_while_on(): void
{
    $id = user_create('dn_carol', 'dn_carol@example.invalid', 'password-123');
    db_update('fb_users', ['display_name' => "Carol \u{5361}\u{6D1B}"], 'id=?', [$id]);
    $u = ['id' => $id, 'username' => 'dn_carol', 'display_name' => "Carol \u{5361}\u{6D1B}", 'group_id' => 0];
    save_settings(['display_names' => '0']);
    test_same('dn_carol', user_name($u), 'off: the username');
    save_settings(['display_names' => '1']);
    test_same("Carol \u{5361}\u{6D1B}", user_name($u), 'on: the display name');
    $link = user_link($u);
    test_same(true, str_contains($link, "Carol \u{5361}\u{6D1B}") && str_contains($link, 'title="@dn_carol"') && str_contains($link, 'href="' . h(user_url($u)) . '"'),'the link shows the display name, names the account on hover and keeps the username address');
    test_same('deleted', user_name(null), 'a deleted account');
    save_settings(['display_names' => '0']);
}

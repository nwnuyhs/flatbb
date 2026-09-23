<?php
/** The four hooks for bigger plugins: user.can, upload.*, markdown.excerpt, router.routes. Run with: php flatbb test */

/** Run $fn signed in as $user (null: a guest), with the hooks restored afterwards. */
function plugin_hooks_as(?array $user, callable $fn): mixed
{
    $saved = hook_registry();
    request_cache('me', null, true);
    request_cache('me', static fn(): ?array => $user);
    try { return $fn(); } finally { hook_registry($saved); request_cache('me', null, true); }
}

function test_user_can_filter(): void
{
    $member = user_by_id(user_create('can_member', 'can_member@example.invalid', 'password-123'));
    $admin_id = user_create('can_admin', 'can_admin@example.invalid', 'password-123');
    db_update('fb_users', ['group_id' => 1], 'id=?', [$admin_id]);
    request_cache('users_full', null, true);
    $admin = user_by_id($admin_id);
    $deny = static function (): void { hook_add('user.can', static fn(bool $ok, array $ctx): bool => $ctx['permission'] === 'post' ? false : $ok); };
    test_same(true, plugin_hooks_as($member, static fn(): bool => can('post')), 'a member may post by their group');
    test_same(false, plugin_hooks_as($member, static function () use ($deny): bool { $deny(); return can('post'); }), 'a plugin takes it away');
    test_same(true, plugin_hooks_as($member, static function () use ($deny): bool { $deny(); return can('reply'); }), 'other permissions are left alone');
    test_same(true, plugin_hooks_as($admin, static function () use ($deny): bool { $deny(); return can('post'); }), 'an admin always may');
    test_same(false, plugin_hooks_as(null, static function (): bool { hook_add('user.can', static fn(): bool => true); return can('post'); }), 'a guest is never given a permission');
}

function test_markdown_excerpt_filter_hides_content(): void
{
    $text = "Open part. [hide]secret reply-only part[/hide] The end.";
    test_same(true, str_contains(md_excerpt($text), 'secret'), 'without a plugin the excerpt has it all');
    $out = plugin_hooks_as(null, static function () use ($text): string {
        hook_add('markdown.excerpt', static fn(string $t): string => preg_replace('~\[hide\].*?\[/hide\]~s', '', $t) ?? $t);
        return md_excerpt($text);
    });
    test_same('Open part. The end.', $out, 'the hidden block never reaches an excerpt');
}

function test_upload_hooks(): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'fbup');
    file_put_contents($tmp, 'plain text attachment');
    $user = user_by_id(user_create('up_member', 'up_member@example.invalid', 'password-123'));
    $refused = plugin_hooks_as($user, static function () use ($tmp, $user): string {
        hook_add('upload.before_save', static fn(string $why, array $ctx): string => $ctx['ext'] === 'txt' ? 'No text files here.' : $why);
        try { upload_store($user, $tmp, 'note.txt'); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
    });
    test_same('No text files here.', $refused, 'a plugin refuses a file with its own message');
    $seen = [];
    $att = plugin_hooks_as($user, static function () use ($tmp, $user, &$seen): array {
        hook_add('upload.after_save', static function ($v, array $ctx) use (&$seen): void { $seen = $ctx; });
        return upload_store($user, $tmp, 'note.txt');
    });
    test_same(true, ($seen['kind'] ?? '') === 'attachment' && ($seen['path'] ?? '') === $att['path'] && is_file((string)($seen['file'] ?? '')), 'after_save tells where the file is');
    @unlink(UPLOAD_DIR . '/' . $att['path']);
    $url = plugin_hooks_as(null, static function (): string {
        hook_add('upload.url', static fn(string $url, array $ctx): string => 'https://cdn.example/' . $ctx['path']);
        return upload_url('2026/09/x.png');
    });
    test_same('https://cdn.example/2026/09/x.png', $url, 'a plugin serves files from elsewhere');
}

function test_router_routes_takeover(): void
{
    [$final, $taken] = plugin_hooks_as(null, static function (): array {
        request_cache('routes_final', null, true);
        hook_add('router.routes', static fn(array $r): array => ['/' => 'portal_home', '/admin' => 'portal_admin', '/login' => 'portal_login'] + $r);
        $out = [routes_final(), routes_taken_over()];
        request_cache('routes_final', null, true);
        return $out;
    });
    test_same('portal_home', $final['/'] ?? '', 'a plugin serves the home page');
    test_same('admin_index', $final['/admin'] ?? '', 'the admin stays the core');
    test_same(routes_core()['/login'], $final['/login'] ?? '', 'sign-in stays the core');
    test_same(['/' => 'portal_home'], $taken, 'the takeover is listed for the admin');
}

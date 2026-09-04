<?php
/** Escaping, request helpers, CSRF and the static security rules. Run with: php flatbb test */

function test_h_escapes_html(): void
{
    test_same('&lt;a href=&quot;x&quot;&gt;&amp;&#039;', h('<a href="x">&\''));
}

function test_post_helpers_clamp_and_ignore_arrays(): void
{
    $_POST = ['s' => "  hello\r\nworld  ", 'n' => '42abc', 'arr' => ['1', '2', ['nested']], 'pw' => '  secret  '];
    test_same("hello\nworld", post_str('s'));
    test_same('', post_str('arr'), 'array given to post_str');
    test_same(0, post_int('n'), 'non-numeric to post_int');
    test_same(['1', '2'], post_list('arr'), 'post_list keeps scalars only');
    test_same('  secret  ', post_secret('pw'), 'post_secret keeps whitespace');
    test_same('hel', post_str('s', 3), 'post_str max length');
    $_POST = [];
}

function test_get_int_is_clamped(): void
{
    $_GET = ['p' => '9999'];
    test_same(50, get_int('p', 1, 1, 50));
    $_GET = ['p' => 'x'];
    test_same(1, get_int('p', 1, 1, 50));
    $_GET = [];
}

function test_csrf_token_is_stable_per_visitor(): void
{
    $_COOKIE['fb_vt'] = str_repeat('a', 32);
    test_same(csrf_token(), csrf_token());
    test_same(64, strlen(csrf_token()));
    $_COOKIE['fb_vt'] = str_repeat('b', 32);
    test_assert(csrf_token() !== hash_hmac('sha256', 'csrf.' . str_repeat('a', 32) . '.0', secret()), 'token changes with the visitor cookie');
}

function test_csrf_exempt_registry(): void
{
    test_same(false, router_csrf_exempt('/api/x/hook'));
    router_csrf_exempt_add(['/api/x/hook']);
    test_same(true, router_csrf_exempt('/api/x/hook'));
    test_same(false, router_csrf_exempt('/api/x/other'));
}

function test_security_scan_catches_the_known_bad_patterns(): void
{
    $dir = sys_get_temp_dir() . '/flatbb-scan-' . getmypid();
    @mkdir($dir . '/views', 0777, true);
    file_put_contents($dir . '/a.php', "<?php\n\$x = \$_POST['x'];\neval('1;');\n\$pdo->exec('x');\n");
    file_put_contents($dir . '/views/v.php', "<?= \$title ?><?= h(\$t) ?><?= raw(\$x) ?><?= strtoupper(\$x) ?><?= \$a ? 'b' : 'c' ?><?= (int)\$n ?>");
    $found = security_scan(security_files($dir));
    test_same(4, count($found), 'findings: ' . implode(' | ', $found));
    foreach (security_files($dir) as $f) unlink($f);
    @rmdir($dir . '/views'); @rmdir($dir);
}

function test_security_scan_passes_on_the_shipped_code(): void
{
    $found = security_scan(array_merge(security_files(ROOT . '/core'), security_files(ROOT . '/app'), security_files(ROOT . '/plugins/hello'), security_files(ROOT . '/plugins/market')));
    test_same([], $found, 'security:check findings');
}

function test_plugin_check_passes_for_bundled_plugins(): void
{
    foreach (['hello', 'market'] as $id) {
        $r = plugin_check($id);
        test_same([], $r['errors'], $id . ' errors');
    }
}

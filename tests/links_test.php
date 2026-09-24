<?php
/** Link previews (core/links.php): which addresses may be fetched, how a page is read, where cards go. No network. Run with: php flatbb test */

function test_link_fetch_refuses_private_and_odd_addresses(): void
{
    foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) test_same(true, link_public_ip($ip), $ip . ' is public');
    foreach (['127.0.0.1', '10.0.0.8', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1'] as $ip) test_same(false, link_public_ip($ip), $ip . ' is refused');
    test_same(null, link_resolve('127.0.0.1'), 'a loopback address');
    test_same(null, link_resolve('localhost'), 'a name without a dot');
    foreach (['http://127.0.0.1/', 'http://[::1]/', 'ftp://example.com/', 'http://example.com:8080/', 'http://user:pw@example.com/', 'file:///etc/passwd', 'gopher://example.com/'] as $url) test_same(null, link_http_get($url), $url . ' is never requested');
}

function test_link_absolute_and_parse(): void
{
    test_same('https://a.org/x/y.png', link_absolute('y.png', 'https://a.org/x/page'), 'relative');
    test_same('https://a.org/y.png', link_absolute('/y.png', 'https://a.org/x/page'), 'root relative');
    test_same('https://cdn.org/y.png', link_absolute('//cdn.org/y.png', 'https://a.org/'), 'scheme relative');
    test_same('', link_absolute('javascript:alert(1)', 'https://a.org/'), 'no script addresses');
    $html = '<html><head><title>Plain title</title><meta property="og:title" content="PHP 8.4 &amp; you"><meta name="description" content="A  short   read.">'
        . '<meta property="og:image" content="/img/cover.png"><meta name="twitter:card" content="summary_large_image"><meta property="og:site_name" content="PHP"></head><body></body></html>';
    $p = link_parse($html, 'https://www.php.net/releases/8.4/', 'text/html; charset=utf-8');
    test_same(['PHP 8.4 & you', 'A short read.', 'https://www.php.net/img/cover.png', 'PHP', 'large'], [$p['title'], $p['description'], $p['image'], $p['site_name'], $p['card']], 'Open Graph first, entities decoded, picture made absolute');
    $p = link_parse('<head><title>Only a title</title></head>', 'https://www.example.org/a', 'text/html');
    test_same(['Only a title', 'example.org', 'text'], [$p['title'], $p['site_name'], $p['card']], 'a page without a picture is a text card');
    test_same(null, link_parse('<head></head>', 'https://example.org/', 'text/html'), 'no title, no card');
    test_same('Rust', link_parse('<head><meta property="og:title" content=""><title>Rust</title></head>', 'https://rust-lang.org/', 'text/html')['title'] ?? '', 'an empty og:title falls back to the title');
    $gbk = mb_convert_encoding('<head><meta charset="gbk"><title>中文标题</title></head>', 'GBK', 'UTF-8');
    test_same('中文标题', link_parse($gbk, 'https://example.cn/', 'text/html')['title'] ?? '', 'a GBK page is read as UTF-8');
}

function test_link_cards_where_and_how(): void
{
    $html = md("Read this:\n\nhttps://www.php.net/releases/8.4/\n\nin a https://example.org/x sentence\n\nhttps://www.youtube.com/watch?v=jNQXAC9IVRw\n\nhttps://example.org/pic.png\n\n[Docs](https://docs.example.org/start)");
    test_same(['https://www.php.net/releases/8.4/', 'https://docs.example.org/start'], link_standalone($html), 'only links on a line of their own, no videos, no pictures');
    test_same(false, link_previewable(base_url() . '/t/1'), 'this site links stay links');
    save_settings(['link_preview_block' => "example.org\n"]);
    test_same(false, link_previewable('https://docs.example.org/start'), 'a blocked domain and its subdomains');
    save_settings(['link_preview_block' => '']);
    $card = link_card_html(['title' => '<script>x</script>', 'description' => 'd"', 'image' => 'javascript:alert(1)', 'site_name' => 'S', 'card' => 'large'], 'https://a.org/"x');
    test_same(false, str_contains($card, '<script>') || str_contains($card, 'javascript:') || str_contains($card, '/"x'), 'everything escaped, a bad picture dropped');
    test_same(true, str_contains($card, 'link-card-text'), 'no usable picture: a text card');
}

function test_link_previews_queue_then_serve(): void
{
    $url = 'https://example.net/article-' . random_token(4);
    test_same([], link_previews([$url]), 'unknown: nothing to show yet');
    test_same(0, (int)val('SELECT status FROM fb_link_previews WHERE url_hash=?', [link_hash($url)]), 'and queued');
    db_update('fb_link_previews', ['status' => 1, 'title' => 'T', 'card' => 'text', 'fetched_at' => now()], 'url_hash=?', [link_hash($url)]);
    test_same('T', link_previews([$url . '#part'])[$url . '#part']['title'] ?? '', 'ready: served, the fragment ignored');
}

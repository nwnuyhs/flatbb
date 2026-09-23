<?php
/** The site's icons: what an icon field may store, how icon_any() draws it, and uploaded SVG made safe. Run with: php flatbb test */

function test_icon_values(): void
{
    test_same(true, icon_value_ok('book') && icon_value_ok('trophy'), 'built-in names, old and new');
    test_same(true, icon_value_ok('') && icon_value_ok('none'), 'no icon, and an edit that removes one');
    test_same(true, icon_value_ok('emoji:🔥'), 'an emoji');
    test_same(false, icon_value_ok('emoji:<b>'), 'markup is not an emoji');
    test_same(false, icon_value_ok('site/../config.php') || icon_value_ok('../data/x.png') || icon_value_ok('no-such-icon'), 'no paths out of uploads/site, no unknown names');
    test_same('<span class="icon icon-emoji" aria-hidden="true">🔥</span>', icon_any('emoji:🔥'), 'an emoji draws as text');
    test_same('', icon_any('none'), 'none draws nothing');
}

function test_svg_sanitize_keeps_drawing_only(): void
{
    $out = (string)svg_sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><style>*{}</style>'
        . '<path d="M1 1h2" onclick="x()" fill="url(https://evil.example/x)" stroke="red"/><use href="https://evil.example/s.svg#a"/><use href="#ok"/>'
        . '<foreignObject><div>x</div></foreignObject><rect width="2" height="2" fill="url(#g)"/></svg>');
    foreach (['onload', 'onclick', '<script', '<style', 'evil.example', 'foreignObject'] as $bad) test_same(false, str_contains($out, $bad), 'dropped: ' . $bad);
    test_same(true, str_contains($out, 'd="M1 1h2"') && str_contains($out, 'href="#ok"') && str_contains($out, 'fill="url(#g)"'), 'the drawing and its own references stay');
    test_same(null, svg_sanitize('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'), 'entities are refused');
    test_same(null, svg_sanitize('<html><body/></html>'), 'not an SVG');
}

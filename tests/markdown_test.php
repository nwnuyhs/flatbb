<?php
/** Markdown renderer: formatting works and nothing dangerous survives. Run with: php flatbb test */

function test_md_renders_basic_formatting(): void
{
    $html = md("# Title\n\nSome **bold** and `code`.\n\n- one\n- two");
    test_contains('<h2>Title</h2>', $html); // # is rendered as h2: the page title owns h1
    test_contains('<strong>bold</strong>', $html);
    test_contains('<code>code</code>', $html);
    test_contains('<li>one</li>', $html);
}

function test_md_strips_scripts_and_event_handlers(): void
{
    $html = md("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[x](javascript:alert(1))");
    test_not_contains('<script', $html);
    test_assert(!preg_match('/<[^>]*onerror/i', $html), 'no onerror attribute inside a tag');
    test_not_contains('<img', $html);
    test_assert(!preg_match('/href=["']\s*javascript/i', $html), 'javascript: links are never rendered as links');
}

function test_md_links_are_escaped_and_nofollow(): void
{
    $html = md('[site](https://example.com/?a=1&b="2")');
    test_contains('href="https://example.com/?a=1&amp;b=&quot;2&quot;"', $html);
    test_contains('rel="nofollow', $html);
}

function test_md_code_block_keeps_html_as_text(): void
{
    $html = md("```php\n<?php echo '<b>hi</b>';\n```");
    test_contains('&lt;b&gt;hi&lt;/b&gt;', $html);
    test_not_contains('<b>hi</b>', $html);
}

function test_md_excerpt_is_plain_text(): void
{
    $text = md_excerpt("**Bold** text with [a link](https://x.y) and `code`", 50);
    test_not_contains('<', $text);
    test_not_contains('**', $text);
    test_contains('Bold', $text);
}

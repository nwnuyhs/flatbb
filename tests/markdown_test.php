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
    test_assert(!preg_match('/href=["\']\s*javascript/i', $html), 'javascript: links are never rendered as links');
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

function test_md_nested_lists_render_once_and_end(): void
{
    // a nested list with anything after it made the outer list read the sub-list again and again until memory ran out
    $html = md("- `alt`: image description, because:\n  1. Shows this text if the image fails to load.\n  2. Screen readers read it aloud.\n- next");
    test_contains('<li><code>alt</code>: image description, because:<ol><li>Shows this text if the image fails to load.</li><li>Screen readers read it aloud.</li></ol></li><li>next</li></ul>', $html);
    test_assert(substr_count($html, 'Screen readers') === 1, 'each nested item is rendered once');
    test_contains('<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>', md("- a\n  - b\n  - c\n- d"));
    test_contains('<ol><li>a<ul><li>b</li></ul></li><li>c</li></ol>', md("1. a\n   - b\n2. c"));
    test_contains('<ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li><li>d</li></ul>', md("- a\n  - b\n    - c\n- d"));
    test_contains('<ul><li>a<ul><li>b</li></ul></li></ul><ul><li>c</li></ul>', md("- a\n  - b\n\n- c")); // a blank line still ends the list
    test_contains('<ul><li>a<ul><li>b</li></ul></li></ul><p>after</p>', md("- a\n  - b\n\nafter"));
}

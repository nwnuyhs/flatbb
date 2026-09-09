<?php
/**
 * Hello — the smallest possible FlatBB plugin. Shows a badge in the footer and adds a page at /hello.
 * Copy this folder to start your own plugin. Full rules: docs/PLUGIN.md
 */
if (!defined('FLATBB')) exit;

function hello_footer(string $html, array $ctx): string
{
    return $html . '<span class="hello-badge">' . h((string)plugin_setting('hello', 'text', 'Hello')) . '</span>';
}

function hello_page(): never
{
    page('Hello', '<div class="card"><div class="card-body"><h1>Hello from a plugin</h1><p>This page is registered by <code>plugins/hello/plugin.php</code>.</p></div></div>');
}

function hello_css(): string
{
    return '.hello-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:var(--brand-soft);color:var(--brand);font-size:var(--font-size-xs)}';
}

return [
    'id' => 'hello',
    'name' => 'Hello Badge',
    'version' => '1.0.1',
    'description' => 'Shows a small greeting badge in the footer and a demo page at /hello.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.0'],
    'hooks' => ['region.footer.right' => 'hello_footer'],
    'routes' => ['/hello' => 'hello_page'],
    'settings' => [
        'text' => ['type' => 'text', 'label' => 'Badge text', 'default' => 'Hello', 'max' => 40],
    ],
    'assets' => ['css' => ['hello_css']],
];

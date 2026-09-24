<?php
/** A fresh copy, before the installer ran: the setup page draws without touching the database. Run with: php flatbb test */

function test_setup_page_renders_before_installation(): void
{
    $installed = config();
    foreach (['plugins', 'themes', 'theme_preview'] as $key) request_cache($key, null, true);
    config(null, null, []); // no data/config.php yet
    try {
        test_same(false, is_installed(), 'a fresh copy is not installed');
        test_same([], plugins(), 'no plugins before the tables exist');
        $html = view('setup', ['checks' => setup_checks(), 'errors' => [], 'values' => ['driver' => 'sqlite', 'mysql_host' => '127.0.0.1', 'mysql_port' => '3306', 'mysql_name' => '', 'mysql_user' => '', 'mysql_pass' => '', 'site_name' => 'My Forum', 'lang' => 'en', 'admin_name' => 'admin', 'admin_email' => '', 'admin_pass' => '']]);
        test_same(true, str_contains($html, 'name="admin_name"'), 'the installer form is drawn');
    } finally {
        config(null, null, $installed);
        foreach (['plugins', 'themes', 'theme_preview'] as $key) request_cache($key, null, true);
    }
}

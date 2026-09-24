<?php
/** The header logo (Settings → General → Logo): the three styles, the fallbacks, what phones and dark themes get. Run with: php flatbb test */

function test_logo_style_and_markup(): void
{
    $keep = ['logo_style' => setting('logo_style'), 'site_logo' => setting('site_logo'), 'site_logo_dark' => setting('site_logo_dark'), 'site_icon' => setting('site_icon'), 'logo_phone_name' => setting('logo_phone_name')];
    try {
        save_settings(['logo_style' => '', 'site_logo' => '', 'site_logo_dark' => '', 'site_icon' => '', 'logo_phone_name' => '0']);
        test_same('icon', logo_style(), 'a fresh site: the icon and the name');
        test_same(true, str_contains(site_logo_html(), 'logo-mark') && str_contains(site_logo_html(), 'logo-text'), 'the FlatBB mark and the site name');
        save_settings(['site_logo' => 'site/logo.png']);
        test_same('image', logo_style(), 'a site that uploaded a logo before keeps showing it');
        test_same(false, str_contains(site_logo_html(), 'logo-phone') || str_contains(site_logo_html(), 'logo-img-dark'), 'no icon, no dark version: nothing to swap');
        save_settings(['site_icon' => 'site/icon.png', 'site_logo_dark' => 'site/logo-dark.png']);
        $html = site_logo_html();
        test_same(true, str_contains($html, 'has-phone') && str_contains($html, 'logo-img-dark') && str_contains($html, 'has-dark'), 'phones get the icon, dark themes the dark logo');
        save_settings(['logo_style' => 'image', 'site_logo' => '']);
        test_same('icon', logo_style(), 'the full logo without a logo falls back to the icon');
        test_same(true, str_contains(site_icon_html(), 'site/icon.png'), 'the uploaded icon replaces the mark');
        save_settings(['logo_style' => 'name']);
        test_same(false, str_contains(site_logo_html(), '<img') || str_contains(site_logo_html(), 'logo-mark'), 'the name alone');
        save_settings(['logo_style' => 'icon', 'logo_phone_name' => '1']);
        test_same(true, str_contains(site_logo_html(), 'logo-text keep'), 'the name can stay on phones');
    } finally {
        save_settings($keep);
    }
}

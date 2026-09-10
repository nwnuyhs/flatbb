<?php
/**
 * Page shell. Variables: title, main, left, right, class, description, canonical, robots, breadcrumbs, head.
 * Regions here: head, header.left, header.nav, header.right (list: search, new topic, language, theme, notifications, account;
 * the older header.right.before_search / after_search sit inside it), header.user_menu, main.before, main.after, footer.left, footer.links, footer.right, body.end
 */
$me = me();
$site = setting('site_name');
$theme = user_pref('theme', setting('theme', 'auto'));
$has_left = $left !== false;
$has_right = $right !== false;
$flash = flash_take();
$nav = region_list('header.nav', [], []);
$user_menu = $me ? region_list('header.user_menu', [
    'profile' => ['label' => t('Profile'), 'url' => user_url($me), 'icon' => 'user'],
    'bookmarks' => ['label' => t('Bookmarks'), 'url' => user_url($me) . '/bookmarks', 'icon' => 'bookmark'],
    'points' => ['label' => t('Points') . ' · ' . human_number((int)$me['points']), 'url' => url('/points'), 'icon' => 'coin'],
    'settings' => ['label' => t('Settings'), 'url' => url('/settings'), 'icon' => 'settings'],
] + (is_admin() ? ['admin' => ['label' => t('Admin'), 'url' => admin_url(), 'icon' => 'shield', 'weight' => 100]] : []), ['user' => $me]) : [];
$footer_links = region_list('footer.links', [
    'categories' => ['label' => t('Categories'), 'url' => url('/categories')],
    'tags' => ['label' => t('Tags'), 'url' => url('/tags')],
    'rss' => ['label' => 'RSS', 'url' => url('/rss')],
], []);
$brand = preg_match('/^#[0-9a-f]{6}$/i', setting('brand_color', '#e7672e')) ? setting('brand_color') : '#e7672e';
$unread = notifications_unread();
?>
<!doctype html>
<html lang="<?= h(lang_code()) ?>" dir="<?= h(lang_direction()) ?>" data-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title_full !== '' ? $title_full : (current_path() !== '/' ? $title . ' - ' . $site : $site . ' - ' . setting('site_tagline'))) ?></title>
<?php $description = $description !== '' ? $description : setting('site_description'); if ($description !== ''): ?><meta name="description" content="<?= h(cut($description, 200)) ?>"><?php endif; ?>
<?php if (setting('seo_keywords') !== ''): ?><meta name="keywords" content="<?= h(setting('seo_keywords')) ?>"><?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= h($canonical) ?>"><?php endif; ?>
<?php if ($robots !== ''): ?><meta name="robots" content="<?= h($robots) ?>"><?php endif; ?>
<meta name="theme-color" content="<?= h($brand) ?>">
<meta name="application-name" content="<?= h($site) ?>">
<meta name="apple-mobile-web-app-title" content="<?= h($site) ?>">
<link rel="manifest" href="<?= h(url('/manifest.webmanifest')) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= h($site) ?>" href="<?= h(url('/rss')) ?>">
<?php $fav = setting('site_favicon'); $fav_url = $fav !== '' ? upload_url($fav) : base_path() . '/assets/favicon.svg'; $fav_type = str_contains($fav_url, '.svg') ? 'image/svg+xml' : (str_contains($fav_url, '.ico') ? 'image/x-icon' : 'image/png'); ?>
<link rel="icon" href="<?= h($fav_url) ?>" type="<?= h($fav_type) ?>">
<?php if ($fav !== '' && $fav_type === 'image/png'): ?><link rel="apple-touch-icon" href="<?= h($fav_url) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= h(asset_url('app.css')) ?>">
<?php if (strtolower($brand) !== '#e7672e'): [$br, $bg, $bb] = sscanf($brand, '#%02x%02x%02x'); $hover = sprintf('#%02x%02x%02x', (int)($br * .88), (int)($bg * .88), (int)($bb * .88)); ?>
<style>:root{--brand:<?= h($brand) ?>;--brand-hover:<?= h($hover) ?>;--brand-soft:rgba(<?= (int)$br ?>,<?= (int)$bg ?>,<?= (int)$bb ?>,.12)}[data-theme="dark"]{--brand-soft:rgba(<?= (int)$br ?>,<?= (int)$bg ?>,<?= (int)$bb ?>,.16)}@media (prefers-color-scheme:dark){[data-theme="auto"]{--brand-soft:rgba(<?= (int)$br ?>,<?= (int)$bg ?>,<?= (int)$bb ?>,.16)}}</style>
<?php endif; ?>
<?= plugin_assets_tag('css') ?>
<?= region('head', ['title' => $title], '', false) ?>
<?= raw(str_replace('{nonce}', csp_nonce(), setting('head_code'))) ?>
<?= raw($head) ?>
</head>
<body class="<?= h($class) ?><?= $has_left ? '' : ' no-left' ?><?= $has_right ? '' : ' no-right' ?>"<?= (int)setting('post_image_max', '0') > 0 ? ' style="--post-img-max:' . (int)setting('post_image_max') . 'px"' : '' ?>>
<header class="topbar" data-slot="header">
  <div class="container topbar-inner">
    <?php if ($has_left): ?><button class="icon-btn drawer-toggle" type="button" aria-label="<?= t('Menu') ?>" data-toggle="drawer"><?= icon('menu') ?></button><?php endif; ?>
    <a class="logo" href="<?= h(url('/')) ?>">
      <?php if (setting('site_logo') !== ''): ?><img src="<?= h(upload_url(setting('site_logo'))) ?>" alt="<?= h($site) ?>"><?php else: ?><?= logo_mark() ?><span class="logo-text"><?= h($site) ?></span><?php endif; ?>
    </a>
    <?= region('header.left') ?>
    <nav class="topnav" data-slot="header.nav">
      <?php foreach ($nav as $item): ?><a href="<?= h((string)($item['url'] ?? '#')) ?>"<?= !empty($item['active']) ? ' class="active"' : '' ?><?= !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>><?= !empty($item['icon']) ? icon((string)$item['icon']) : '' ?><span><?= h((string)($item['label'] ?? '')) ?></span></a><?php endforeach; ?>
    </nav>
    <div class="topbar-right" data-slot="header.right">
      <?php foreach (header_right_items($me, (int)$unread, $user_menu) as $item): ?><?= raw(is_array($item) ? (string)($item['html'] ?? '') : (string)$item) ?><?php endforeach; ?>
    </div>
  </div>
</header>
<div class="container page-grid">
  <?php if ($has_left): ?><aside class="col-left" data-slot="sidebar.left">
    <?php if ($nav !== []): ?><nav class="side-nav drawer-nav" aria-label="<?= t('Site') ?>"><?php foreach ($nav as $item): ?><a class="side-link<?= !empty($item['active']) ? ' active' : '' ?>" href="<?= h((string)($item['url'] ?? '#')) ?>"<?= !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>><?= icon((string)($item['icon'] ?? 'external')) ?><span><?= h((string)($item['label'] ?? '')) ?></span></a><?php endforeach; ?></nav><?php endif; ?>
    <?= raw($left) ?>
  </aside><?php endif; ?>
  <main class="col-main" data-slot="main">
    <?php if ($flash): ?><div class="flash flash-<?= h($flash['type']) ?>" data-flash><?= h($flash['message']) ?></div><?php endif; ?>
    <?= raw($top) ?>
    <?php if ($breadcrumbs !== []): ?><nav class="breadcrumbs"><a href="<?= h(url('/')) ?>"><?= t('Home') ?></a><?php foreach ($breadcrumbs as $b): ?><span>/</span><?= $b[1] !== '' ? '<a href="' . h($b[1]) . '">' . h($b[0]) . '</a>' : '<span>' . h($b[0]) . '</span>' ?><?php endforeach; ?></nav><?php endif; ?>
    <?= region('main.before', ['title' => $title]) ?>
    <?= raw($main) ?>
    <?= region('main.after', ['title' => $title]) ?>
  </main>
  <?php if ($has_right): ?><aside class="col-right" data-slot="sidebar.right"><?= raw($right) ?></aside><?php endif; ?>
</div>
<footer class="footer" data-slot="footer">
  <div class="container footer-inner">
    <?= region('footer.left', [], '<span>&copy; ' . date('Y') . ' ' . h($site) . '</span>' . (setting('footer_text') !== '' ? '<span>' . h(setting('footer_text')) . '</span>' : '')) ?>
    <nav class="footer-links" data-slot="footer.links"><?php foreach ($footer_links as $l): ?><a href="<?= h((string)$l['url']) ?>"><?= h((string)$l['label']) ?></a><?php endforeach; ?></nav>
    <?= region('footer.right', [], '<span class="powered">Powered by <a href="https://www.flatbb.com" rel="noopener">FlatBB</a></span>') ?>
  </div>
</footer>
<div class="drawer-backdrop" data-toggle="drawer"></div>
<script nonce="<?= h(csp_nonce()) ?>">window.FB=<?= json_encode_value(['base' => base_path(), 'csrf' => csrf_token(), 'uid' => uid(), 'rewrite' => rewrite_enabled(), 'api' => url('/api/preview'), 'upload' => url('/upload'), 'users' => url('/api/users'), 'i18n' => ['confirm' => t('Are you sure?'), 'uploading' => t('Uploading…'), 'failed' => t('Request failed.'), 'copied' => t('Link copied'), 'nothing' => t('Nothing to preview.')]]) ?></script>
<script src="<?= h(asset_url('app.js')) ?>" defer></script>
<?= plugin_assets_tag('js') ?>
<?= region('body.end', [], '', false) ?>
<?= raw(str_replace('{nonce}', csp_nonce(), setting('foot_code'))) ?>
</body>
</html>

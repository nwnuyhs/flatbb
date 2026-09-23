<?php
/**
 * Left column: main navigation and categories (the tags are a card of the right column). Regions: sidebar.left.top/nav/categories/bottom
 * sidebar.left.nav items may carry group: none (the main links), "community", "tools", or an id of their own with group_label.
 * The main links come first, then each group under a small heading. Past setting nav_visible links (8; 0 = no limit) the rest
 * fold under More, where the active link is never hidden.
 */
$me = me();
// Latest stays as the way home; Top and Unread live in the head of the list
$nav = [
    'latest' => ['label' => t('Latest'), 'url' => url('/latest'), 'icon' => 'clock', 'active' => in_array(current_path(), ['/', '/latest'], true), 'weight' => -30],
];
$nav['categories'] = ['label' => t('Categories'), 'url' => url('/categories'), 'icon' => 'folder', 'active' => is_active_path('/categories')];
$nav['tags'] = ['label' => t('Tags'), 'url' => url('/tags'), 'icon' => 'tag', 'active' => is_active_path('/tags')];
$nav = region_list('sidebar.left.nav', $nav, []);
$heads = ['community' => t('Community'), 'tools' => t('Tools')];
$groups = [];
foreach ($nav as $item) {
    if (!is_array($item)) continue;
    $g = (string)($item['group'] ?? '');
    if ($g !== '' && !isset($heads[$g])) $heads[$g] = (string)($item['group_label'] ?? ucfirst($g));
    $groups[$g][] = $item;
}
request_cache('left_nav_urls', static fn(): array => array_values(array_filter(array_map(static fn($it): string => is_array($it) ? (string)($it['url'] ?? '') : '', $nav))));
$limit = max(0, (int)setting('nav_visible', '8'));
$seen = 0;
$shown = $more = '';
foreach (array_unique(array_merge([''], array_keys($heads))) as $g) { // the main links, Community, Tools, then groups of plugins' own
    $in = $out = '';
    foreach ($groups[$g] ?? [] as $item) {
        $a = '<a class="side-link' . (!empty($item['active']) ? ' active' : '') . '" href="' . h((string)$item['url']) . '">' . (!empty($item['icon']) ? icon_any((string)$item['icon']) : '')
            . '<span>' . h((string)$item['label']) . '</span>' . (!empty($item['badge']) ? '<b class="badge">' . h((string)$item['badge']) . '</b>' : '') . '</a>';
        if ($limit > 0 && ++$seen > $limit && empty($item['active'])) $out .= $a; else $in .= $a;
    }
    $head = $g !== '' ? '<h4 class="side-group">' . h($heads[$g]) . '</h4>' : '';
    if ($in !== '') $shown .= $head . $in;
    if ($out !== '') $more .= $head . $out;
}
$tree = category_tree();
$cur = current_path();
?>
<?= region('sidebar.left.top') ?>
<nav class="side-nav" data-slot="sidebar.left.nav">
  <?= raw($shown) ?>
  <?php if ($more !== ''): ?><details class="side-more"><summary class="side-link"><?= icon('more') ?><span><?= t('More') ?></span></summary><?= raw($more) ?></details><?php endif; ?>
</nav>
<?php if (!empty($tree[0])): ?>
<div class="side-section" data-slot="sidebar.left.categories">
  <h4><?= t('Categories') ?></h4>
  <?php foreach ($tree[0] as $c): ?>
    <a class="side-link cat-link<?= $cur === '/c/' . $c['slug'] ? ' active' : '' ?>" href="<?= h(category_url($c)) ?>"><?= raw(category_icon($c)) ?><span><?= h($c['name']) ?></span><small><?= human_number((int)$c['topic_count']) ?></small></a>
    <?php foreach ($tree[(int)$c['id']] ?? [] as $child): ?>
      <a class="side-link cat-link cat-child<?= $cur === '/c/' . $child['slug'] ? ' active' : '' ?>" href="<?= h(category_url($child)) ?>"><?= raw(category_icon($child)) ?><span><?= h($child['name']) ?></span><small><?= human_number((int)$child['topic_count']) ?></small></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?= region('sidebar.left.bottom') ?>

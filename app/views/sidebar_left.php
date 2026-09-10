<?php
/** Left column: main navigation, categories, tags. Regions: sidebar.left.top/nav/categories/bottom */
$me = me();
$nav = [
    'latest' => ['label' => t('Latest'), 'url' => url('/latest'), 'icon' => 'clock', 'active' => in_array(current_path(), ['/', '/latest'], true), 'weight' => -30],
    'top' => ['label' => t('Top'), 'url' => url('/top'), 'icon' => 'flame', 'active' => is_active_path('/top'), 'weight' => -20],
];
if ($me) $nav['unread'] = ['label' => t('Unread'), 'url' => url('/unread'), 'icon' => 'dot', 'active' => is_active_path('/unread'), 'weight' => -10];
$nav['categories'] = ['label' => t('Categories'), 'url' => url('/categories'), 'icon' => 'folder', 'active' => is_active_path('/categories')];
$nav['tags'] = ['label' => t('Tags'), 'url' => url('/tags'), 'icon' => 'tag', 'active' => is_active_path('/tags')];
$nav = region_list('sidebar.left.nav', $nav, []);
$tree = category_tree();
$top_tags = request_cache('top_tags', static fn(): array => all('SELECT name,slug,topic_count FROM fb_tags WHERE topic_count>0 ORDER BY topic_count DESC LIMIT 12')) ?? [];
$cur = current_path();
?>
<?= region('sidebar.left.top') ?>
<nav class="side-nav" data-slot="sidebar.left.nav">
  <?php foreach ($nav as $key => $item): ?>
    <a class="side-link<?= !empty($item['active']) ? ' active' : '' ?>" href="<?= h((string)$item['url']) ?>"><?= !empty($item['icon']) ? icon((string)$item['icon']) : '' ?><span><?= h((string)$item['label']) ?></span><?= !empty($item['badge']) ? '<b class="badge">' . h((string)$item['badge']) . '</b>' : '' ?></a>
  <?php endforeach; ?>
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
<?php if ($top_tags !== []): ?>
<div class="side-section side-tags" data-slot="sidebar.left.tags">
  <h4><?= t('Tags') ?></h4>
  <div class="tag-cloud"><?php foreach ($top_tags as $tg): ?><a class="tag-badge" href="<?= h(tag_url($tg)) ?>"><?= h($tg['name']) ?></a><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?= region('sidebar.left.bottom') ?>

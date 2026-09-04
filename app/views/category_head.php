<?php /** Category page heading (plain, no card). Variables: category, children, can_post */ ?>
<header class="category-head">
  <h1><?= h($category['name']) ?></h1>
  <?php if ($category['description']): ?><p class="muted"><?= h($category['description']) ?></p><?php endif; ?>
  <?php if ($children !== []): ?><div class="cat-children"><?php foreach ($children as $ch): ?><a class="row-cat" href="<?= h(category_url($ch)) ?>"><?= h($ch['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
</header>

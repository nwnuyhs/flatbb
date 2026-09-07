<?php /** Category index. Variables: tree, last_topics, users, tabs */ ?>
<?= raw($tabs) ?>
<div class="category-list">
<?php foreach ($tree[0] ?? [] as $c): $lt = $last_topics[(int)$c['last_topic_id']] ?? null; ?>
  <article class="category-row">
    <div class="cat-main">
      <h3><a href="<?= h(category_url($c)) ?>"><?= raw(category_icon($c)) ?><?= h($c['name']) ?></a></h3>
      <?php if ($c['description']): ?><p class="muted"><?= h($c['description']) ?></p><?php endif; ?>
      <?php if (!empty($tree[(int)$c['id']])): ?><div class="cat-children"><?php foreach ($tree[(int)$c['id']] as $ch): ?><a class="cat-badge" href="<?= h(category_url($ch)) ?>"><?= raw(category_icon($ch)) ?><?= h($ch['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="cat-stats"><span><b><?= human_number((int)$c['topic_count']) ?></b> <?= t('topics') ?></span><span><b><?= human_number((int)$c['post_count']) ?></b> <?= t('replies') ?></span></div>
    <div class="cat-last">
      <?php if ($lt && !(int)$lt['is_deleted']): ?><a href="<?= h(topic_url($lt)) ?>"><?= h(cut($lt['title'], 50)) ?></a><small><?= isset($users[(int)$lt['last_user_id']]) ? user_link($users[(int)$lt['last_user_id']]) : '' ?> · <?= time_tag((int)$lt['last_post_at']) ?></small><?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

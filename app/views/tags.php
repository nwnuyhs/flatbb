<?php /** Tag index. Variables: tags, tabs */ ?>
<?= raw($tabs) ?>
<div class="tag-page">
  <?php if ($tags === []): ?><div class="empty"><?= icon('tag') ?><p><?= t('No tags yet.') ?></p></div><?php endif; ?>
  <div class="tag-grid">
  <?php foreach ($tags as $tg): ?><a class="tag-card" href="<?= h(tag_url($tg)) ?>"><span class="tag-badge"><?= h($tg['name']) ?></span><small><?= t('%d topics', (int)$tg['topic_count']) ?></small></a><?php endforeach; ?>
  </div>
</div>

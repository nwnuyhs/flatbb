<?php /** Related topics card. Variable: topics */ ?>
<?php if ($topics !== []): ?>
<section class="card card-related">
  <header class="card-head"><h3><?= t('Related topics') ?></h3></header>
  <ul class="link-list">
    <?php foreach ($topics as $t): ?><li><a href="<?= h(topic_url($t)) ?>"><?= h($t['title']) ?></a><small><?= (int)$t['reply_count'] ?> · <?= time_tag((int)$t['last_post_at']) ?></small></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

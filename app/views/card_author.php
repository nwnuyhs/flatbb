<?php /** Author card for topic pages and profiles. Variable: user (may be null) */ ?>
<?php if ($user): $g = group_by_id((int)$user['group_id']); ?>
<section class="card card-author">
  <div class="card-body">
    <div class="me-head"><?= avatar($user, 48) ?><div><strong><?= user_link($user) ?></strong><small><?= h($g['name'] ?? '') ?></small></div></div>
    <div class="me-stats">
      <a href="<?= h(user_url($user)) ?>"><b><?= (int)$user['topic_count'] ?></b><span><?= t('Topics') ?></span></a>
      <a href="<?= h(user_url($user)) ?>/replies"><b><?= (int)$user['post_count'] ?></b><span><?= t('Replies') ?></span></a>
      <span><b><?= (int)$user['like_count'] ?></b><span><?= t('Likes') ?></span></span>
    </div>
    <div class="muted small"><?= t('Joined %s', time_tag((int)$user['created_at'], 'month')) ?> · <?= t('Seen %s', time_tag((int)$user['last_seen'])) ?></div>
  </div>
</section>
<?php endif; ?>

<?php /** Right column user card: sign-in box for guests, quick profile for members. Variable: me */ ?>
<?php if ($me === null): ?>
<section class="card card-login">
  <div class="card-body">
    <p><?= t('Join the conversation. Sign in to post and reply.') ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="<?= h(url('/login')) ?>"><?= t('Sign in') ?></a>
      <?php if (setting('allow_register', '1') === '1'): ?><a class="btn" href="<?= h(url('/register')) ?>"><?= t('Sign up') ?></a><?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<section class="card card-me">
  <div class="card-body">
    <div class="me-head"><?= avatar($me, 48) ?><div><strong><?= user_link($me) ?></strong><small><?= h(group_by_id((int)$me['group_id'])['name'] ?? '') ?></small></div></div>
    <div class="me-stats">
      <a href="<?= h(user_url($me)) ?>"><b><?= (int)$me['topic_count'] ?></b><span><?= t('Topics') ?></span></a>
      <a href="<?= h(user_url($me)) ?>/replies"><b><?= (int)$me['post_count'] ?></b><span><?= t('Replies') ?></span></a>
      <a href="<?= h(url('/notifications')) ?>"><b><?= (int)$me['like_count'] ?></b><span><?= t('Likes') ?></span></a>
    </div>
    <?php if (can('post')): ?><a class="btn btn-primary btn-block" href="<?= h(url('/new-topic')) ?>"><?= icon('plus') ?><?= t('New Topic') ?></a><?php endif; ?>
  </div>
</section>
<?php endif; ?>

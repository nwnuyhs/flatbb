<?php /** Edit reply. Variables: post, topic, action */ ?>
<div class="compose">
  <h1><?= t('Edit Reply') ?></h1>
  <p class="muted"><?= t('In topic') ?>: <a href="<?= h(topic_url($topic)) ?>"><?= h($topic['title']) ?></a></p>
  <form method="post" action="<?= h($action) ?>" data-ajax="1" data-composer>
    <?= csrf_field() ?>
    <?= editor('body', (string)$post['body'], '', ['scope' => 'post-' . (int)$post['id'], 'ctx' => ['topic' => $topic, 'post' => $post]]) ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= t('Save changes') ?></button>
      <a class="btn btn-ghost" href="<?= h(url('/post/' . $post['id'])) ?>"><?= t('Cancel') ?></a>
    </div>
  </form>
</div>

<?php /** New/edit topic. Variables: topic (null for new), post, categories, category_id, tags, action; title and body (optional prefill for a new topic) */ ?>
<div class="compose">
  <h1><?= $topic ? t('Edit Topic') : t('New Topic') ?></h1>
  <form method="post" action="<?= h($action) ?>" data-ajax="1" data-composer>
    <?= csrf_field() ?>
    <div class="form-row"><input type="text" name="title" class="input-lg" placeholder="<?= t('Title') ?>" value="<?= h($topic['title'] ?? $title ?? '') ?>" maxlength="200" required autofocus></div>
    <div class="form-grid">
      <div class="form-row"><label><?= t('Category') ?></label>
        <select name="category_id" required>
          <option value=""><?= t('Choose a category') ?></option>
          <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $category_id ? ' selected' : '' ?>><?= (int)$c['parent_id'] ? '— ' : '' ?><?= h($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-row"><label><?= t('Tags') ?></label><input type="text" name="tags" value="<?= h($tags) ?>" placeholder="<?= t('comma, separated, up to 5') ?>"></div>
    </div>
    <?= editor('body', (string)($post['body'] ?? $body ?? ''), t('Write your post…'), ['scope' => $topic ? 'topic-' . (int)$topic['id'] : 'topic-new', 'ctx' => ['topic' => $topic]]) ?>
    <?= region('composer.extra', ['topic' => $topic]) ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $topic ? t('Save changes') : t('Create Topic') ?></button>
      <a class="btn btn-ghost" href="<?= h($topic ? topic_url($topic) : url('/')) ?>"><?= t('Cancel') ?></a>
    </div>
  </form>
</div>

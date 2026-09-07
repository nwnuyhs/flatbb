<?php
/**
 * Topic page. Variables: topic, posts, page, pagination, can_reply.
 * Regions: topic.header, topic.actions, topic.replies_after, composer.extra
 */
$me = me();
$manage = can_manage_topic($topic);
$first = $posts[0] ?? null;
$can_edit_topic = $first !== null && (int)$first['floor'] === 0 && can_edit_post($first);
$actions = [];
if ($me) $actions['bookmark'] = ['html' => action_form(url('/t/' . $topic['id'] . '/bookmark'), '<button type="submit" class="btn btn-sm' . ($topic['bookmarked'] ? ' active' : '') . '" data-bookmark>' . icon('bookmark') . '<span>' . ($topic['bookmarked'] ? t('Bookmarked') : t('Bookmark')) . '</span></button>', [], 'inline')];
if ($can_edit_topic) $actions['edit'] = ['html' => '<a class="btn btn-sm" href="' . h(url('/t/' . $topic['id'] . '/edit')) . '">' . icon('edit') . '<span>' . t('Edit') . '</span></a>'];
$actions = region_list('topic.actions', $actions, ['topic' => $topic]);
?>
<article class="topic-page" data-topic-id="<?= (int)$topic['id'] ?>">
  <header class="topic-head" data-slot="topic.header">
    <h1 class="topic-title" dir="auto">
      <?php if ((int)$topic['is_pinned']): ?><span class="row-icon" title="<?= t('Pinned') ?>"><?= icon('pin') ?></span><?php endif; ?>
      <?php if ((int)$topic['is_locked']): ?><span class="row-icon" title="<?= t('Locked') ?>"><?= icon('lock') ?></span><?php endif; ?>
      <?= raw(hook('topic.title', h($topic['title']), ['topic' => $topic, 'where' => 'page'])) ?>
    </h1>
    <div class="topic-meta">
      <?= category_badge($topic['category']) ?>
      <span class="stat"><?= icon('reply') ?><?= (int)$topic['reply_count'] ?></span>
      <span class="stat"><?= icon('eye') ?><?= (int)$topic['view_count'] ?></span>
      <span class="stat"><?= icon('heart') ?><?= (int)$topic['like_count'] ?></span>
      <?php if ((int)$topic['is_deleted']): ?><span class="flag flag-danger"><?= t('Deleted') ?></span><?php endif; ?>
    </div>
    <div class="topic-tools" data-slot="topic.actions">
      <?php foreach ($actions as $a): ?><?= raw($a['html'] ?? '') ?><?php endforeach; ?>
      <?php if (is_mod() || $manage): ?>
      <div class="dropdown" data-dropdown>
        <button type="button" class="btn btn-sm dropdown-toggle"><?= icon('more') ?><span><?= t('Manage') ?></span></button>
        <div class="dropdown-menu">
          <?php if (is_mod()): ?>
            <?= action_form(url('/t/' . $topic['id'] . '/action'), '<button type="submit">' . icon('pin') . ((int)$topic['is_pinned'] ? t('Unpin') : t('Pin')) . '</button>', ['action' => (int)$topic['is_pinned'] ? 'unpin' : 'pin']) ?>
            <?php if ((int)$topic['is_pinned']): ?><?= action_form(url('/t/' . $topic['id'] . '/action'), '<button type="submit">' . icon('arrow-up') . t('Move to top') . '</button>', ['action' => 'pin']) ?><?php endif; ?>
            <?= action_form(url('/t/' . $topic['id'] . '/action'), '<button type="submit">' . icon('lock') . ((int)$topic['is_locked'] ? t('Unlock') : t('Lock')) . '</button>', ['action' => (int)$topic['is_locked'] ? 'unlock' : 'lock']) ?>
            <form method="post" action="<?= h(url('/t/' . $topic['id'] . '/action')) ?>" class="dropdown-form"><?= csrf_field() ?><input type="hidden" name="action" value="move"><select name="category_id" onchange="this.form.submit()"><option value=""><?= t('Move to…') ?></option><?php foreach (categories() as $c): if ((int)$c['id'] === (int)$topic['category_id']) continue; ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></form>
          <?php endif; ?>
          <?php if ((int)$topic['is_deleted'] && is_mod()): ?>
            <?= action_form(url('/t/' . $topic['id'] . '/action'), '<button type="submit">' . icon('refresh') . t('Restore') . '</button>', ['action' => 'restore']) ?>
          <?php elseif (!(int)$topic['is_deleted']): ?>
            <?= action_form(url('/t/' . $topic['id'] . '/action'), '<button type="submit" class="danger">' . icon('trash') . t('Delete topic') . '</button>', ['action' => 'delete'], '', t('Delete this topic?')) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </header>
  <?= region('topic.header', ['topic' => $topic]) ?>
  <div class="post-stream" data-slot="topic.posts">
    <?php foreach ($posts as $p): ?><?= view('post', ['post' => $p, 'topic' => $topic]) ?><?php endforeach; ?>
  </div>
  <?= raw($pagination) ?>
  <?= region('topic.replies_after', ['topic' => $topic]) ?>
  <?php if ($can_reply): ?>
  <section class="composer" id="reply" data-slot="composer">
    <h2><?= t('Reply') ?></h2>
    <form method="post" action="<?= h(url('/t/' . $topic['id'] . '/reply')) ?>" data-ajax="1" data-composer>
      <?= csrf_field() ?>
      <input type="hidden" name="reply_to" value="" data-reply-to>
      <div class="reply-target hidden" data-reply-target><?= icon('reply') ?><span></span><button type="button" class="link" data-clear-reply><?= t('cancel') ?></button></div>
      <?php $reply_vals = (array)hook('composer.values', ['body' => ''], ['mode' => 'reply', 'topic' => $topic]); ?>
      <?= editor('body', (string)($reply_vals['body'] ?? ''), t('Write your reply…'), ['scope' => 'reply-' . (int)$topic['id'], 'ctx' => ['topic' => $topic]]) ?>
      <?= region('composer.extra', ['topic' => $topic]) ?>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('reply') ?><?= t('Post reply') ?></button></div>
    </form>
  </section>
  <?php elseif ($me === null): ?>
  <div class="composer-locked"><?= icon('user') ?><span><?= t('Sign in to reply.') ?></span><a class="btn btn-primary btn-sm" href="<?= h(url('/login', ['back' => current_path()])) ?>"><?= t('Sign in') ?></a></div>
  <?php elseif ((int)$topic['is_locked']): ?>
  <div class="composer-locked"><?= icon('lock') ?><span><?= t('This topic is locked.') ?></span></div>
  <?php endif; ?>
</article>

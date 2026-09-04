<?php
/**
 * One post in the stream. Variables: post, topic.
 * In-loop slots (no DB in hooks): post.before, post.content_after, post.actions (list), post.after
 */
$u = $post['user'];
$deleted = (int)$post['is_deleted'] === 1;
$ctx = ['post' => $post, 'topic' => $topic];
$actions = [];
if (uid() > 0 && !$deleted) {
    $actions['like'] = (int)$post['user_id'] === uid()
        ? ['html' => '<span class="act act-static" title="' . t('Your own post') . '">' . icon('heart') . '<span>' . ((int)$post['like_count'] ?: '') . '</span></span>']
        : ['html' => action_form(url('/post/' . $post['id'] . '/like'), '<button type="submit" class="act' . ($post['liked'] ? ' active' : '') . '" data-like title="' . ($post['liked'] ? t('Unlike') : t('Like')) . '">' . icon('heart') . '<span data-count>' . ((int)$post['like_count'] ?: '') . '</span></button>', [], 'inline')];
    if (can('reply') && ((int)$topic['is_locked'] === 0 || is_mod())) {
        $actions['reply'] = ['html' => '<button type="button" class="act" data-reply-to-post="' . (int)$post['id'] . '" data-username="' . h($u['username'] ?? '') . '">' . icon('reply') . '<span>' . t('Reply') . '</span></button>'];
        $actions['quote'] = ['html' => '<button type="button" class="act" data-quote-post="' . (int)$post['id'] . '">' . icon('quote') . '<span>' . t('Quote') . '</span></button>'];
    }
    if (can_edit_post($post)) {
        $actions['edit'] = ['html' => '<a class="act" href="' . h((int)$post['floor'] === 0 ? url('/t/' . $topic['id'] . '/edit') : url('/post/' . $post['id'] . '/edit')) . '">' . icon('edit') . '<span>' . t('Edit') . '</span></a>'];
        if ((int)$post['floor'] > 0) $actions['delete'] = ['html' => action_form(url('/post/' . $post['id'] . '/delete'), '<button type="submit" class="act danger">' . icon('trash') . '<span>' . t('Delete') . '</span></button>', [], 'inline', t('Delete this reply?'))];
    }
} elseif ((int)$post['like_count'] > 0) {
    $actions['like'] = ['html' => '<span class="act">' . icon('heart') . '<span>' . (int)$post['like_count'] . '</span></span>'];
}
if ($deleted && is_mod()) $actions['restore'] = ['html' => action_form(url('/post/' . $post['id'] . '/delete'), '<button type="submit" class="act">' . icon('refresh') . '<span>' . t('Restore') . '</span></button>', ['action' => 'restore'], 'inline')];
$actions['link'] = ['html' => '<a class="act" href="' . h(url('/post/' . $post['id'])) . '" data-copy="' . h(absolute_url('/post/' . $post['id'])) . '" title="' . t('Copy link to this post') . '">' . icon('link') . '<span>' . t('Link') . '</span></a>'];
$actions = region_list('post.actions', $actions, $ctx);
?>
<?= slot('post.before', $ctx) ?>
<article class="post<?= $deleted ? ' deleted' : '' ?><?= (int)$post['floor'] === 0 ? ' first' : '' ?>" id="post-<?= (int)$post['id'] ?>" data-post-id="<?= (int)$post['id'] ?>" data-floor="<?= (int)$post['floor'] ?>" data-slot="post">
  <div class="post-avatar"><?= avatar($u, 40) ?></div>
  <div class="post-body">
    <header class="post-head">
      <?= user_link($u) ?>
      <?php $g = $u ? group_by_id((int)$u['group_id']) : null; if ($g && ((int)$g['is_admin'] || (int)$g['is_mod'])): ?><span class="flag" style="<?= !empty($g['color']) ? 'color:' . h($g['color']) : '' ?>"><?= h($g['name']) ?></span><?php endif; ?>
      <a class="post-time" href="<?= h(url('/post/' . $post['id'])) ?>" title="<?= date('Y-m-d H:i', (int)$post['created_at']) ?>"><?= human_time((int)$post['created_at']) ?></a>
      <?php if ((int)$post['edit_count'] > 0): ?><span class="post-edited" title="<?= t('Edited %s', human_time((int)$post['edited_at'])) ?>"><?= icon('edit') ?></span><?php endif; ?>
      <span class="post-floor">#<?= (int)$post['floor'] + 1 ?></span>
    </header>
    <?php if ($post['reply_to']): ?>
      <a class="reply-quote" href="#post-<?= (int)$post['reply_to']['id'] ?>"><?= icon('reply') ?><b><?= h($post['reply_to']['user']['username'] ?? t('deleted')) ?></b><span><?= h($post['reply_to']['excerpt']) ?></span></a>
    <?php endif; ?>
    <?php if ($deleted): ?>
      <div class="post-content muted"><em><?= t('This reply was deleted.') ?></em></div>
      <?php if (is_mod()): ?><div class="post-content"><?= raw($post['body_html']) ?></div><?php endif; ?>
    <?php else: ?>
      <div class="post-content"><?= raw($post['body_html']) ?></div>
    <?php endif; ?>
    <?= slot('post.content_after', $ctx) ?>
    <footer class="post-actions" data-slot="post.actions">
      <?php foreach ($actions as $a): ?><?= raw($a['html'] ?? '') ?><?php endforeach; ?>
    </footer>
  </div>
</article>
<?= slot('post.after', $ctx) ?>

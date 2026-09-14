<?php
/**
 * Topic page. Variables: topic, posts, page, pagination, can_reply, new_from.
 * Regions: topic.header, topic.actions, topic.replies_after, composer.extra
 * On the page that holds the opening post, the title, the topic's numbers, its buttons and the topic.header region sit inside
 * that post (post.php receives them as `head`), so the title and what it introduces read as one card. Later pages keep a
 * compact header above the replies.
 */
$me = me();
$manage = can_manage_topic($topic);
$first = $posts[0] ?? null;
$merge = $first !== null && (int)$first['floor'] === 0;
$actions = [];
if ($me) $actions['bookmark'] = ['html' => action_form(url('/t/' . $topic['id'] . '/bookmark'), '<button type="submit" class="btn btn-sm' . ($topic['bookmarked'] ? ' active' : '') . '" data-bookmark>' . icon('bookmark') . '<span>' . ($topic['bookmarked'] ? t('Bookmarked') : t('Bookmark')) . '</span></button>', [], 'inline')];
// Edit is not repeated here: the opening post carries its own Edit, which opens the topic editor
$actions = region_list('topic.actions', $actions, ['topic' => $topic]);
ob_start(); ?>
    <h1 class="topic-title" dir="auto">
      <?php if ((int)$topic['is_pinned']): ?><span class="row-icon row-icon-pin" title="<?= t('Pinned') ?>"><?= icon('pin') ?></span><?php endif; ?>
      <?php if ((int)$topic['is_locked']): ?><span class="row-icon" title="<?= t('Locked') ?>"><?= icon('lock') ?></span><?php endif; ?>
      <?= raw(hook('topic.title', h($topic['title']), ['topic' => $topic, 'where' => 'page'])) ?>
    </h1>
<?php $title_html = (string)ob_get_clean(); ob_start(); ?>
      <?= category_badge($topic['category']) ?>
      <span class="stat" title="<?= t('Replies') ?>"><?= icon('reply') ?><?= (int)$topic['reply_count'] ?></span>
      <span class="stat" title="<?= t('Views') ?>"><?= icon('eye') ?><?= (int)$topic['view_count'] ?></span>
      <?php if (!$merge): ?><span class="stat"><?= icon('heart') ?><?= (int)$topic['like_count'] ?></span><?php endif; ?>
      <?php if ((int)$topic['is_deleted']): ?><span class="flag flag-danger"><?= t('Deleted') ?></span><?php endif; ?>
<?php $stats_html = (string)ob_get_clean(); ob_start(); ?>
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
<?php $tools_html = (string)ob_get_clean(); $header_region = region('topic.header', ['topic' => $topic]); ?>
<article class="topic-page<?= $merge ? ' topic-merged' : '' ?>" data-topic-id="<?= (int)$topic['id'] ?>">
  <?php if (!$merge): ?>
  <header class="topic-head" data-slot="topic.header">
    <?= raw($title_html) ?>
    <div class="topic-meta"><?= raw($stats_html) ?></div>
    <?= raw($tools_html) ?>
  </header>
  <?= raw($header_region) ?>
  <?php endif; ?>
  <div class="post-stream" data-slot="topic.posts">
    <?php foreach ($posts as $p): ?><?php if ((int)$p['id'] === (int)($new_from ?? 0)): ?><div class="posts-new-line" id="new"><span><?= t('New replies') ?></span></div><?php endif; ?><?= view('post', ['post' => $p, 'topic' => $topic, 'head' => $merge && (int)$p['floor'] === 0 ? ['title' => $title_html, 'stats' => $stats_html, 'tools' => $tools_html, 'region' => $header_region] : null]) ?><?php endforeach; ?>
  </div>
  <?= raw($pagination) ?>
  <?= region('topic.replies_after', ['topic' => $topic]) ?>
  <?php if (!$can_reply && ($reply_denied ?? '') !== ''): ?>
  <div class="empty reply-denied"><?= icon('lock') ?><p><?= h((string)$reply_denied) ?></p></div>
<?php elseif (!$can_reply && ($hold ?? []) !== []): ?>
  <?= raw(post_hold_notice($hold)) ?>
<?php endif; ?>
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

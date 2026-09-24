<?php
/**
 * Review queue (/review, moderators). Variables: rows (fb_review), posts, topics, users (by id), full (joined, counts, status
 * of the authors), tab (waiting|approved|rejected), tabs (html), pagination. The buttons post to /review/act; assets/app.js
 * sends them without a reload ([data-review] forms) and asks for the note before a rejection.
 */
?>
<div class="list-card review-card">
  <div class="review-head"><h1><?= t('Review queue') ?></h1><span class="muted small"><?= t('Posts wait here until a moderator approves them.') ?></span></div>
  <div class="list-head"><?= raw($tabs) ?></div>
  <?php if ($rows === []): ?><div class="empty"><?= icon('check') ?><p><?= $tab === 'waiting' ? t('Nothing is waiting for review.') : t('Nothing here yet.') ?></p></div><?php endif; ?>
  <?php foreach ($rows as $r):
    $p = $posts[(int)$r['post_id']] ?? null;
    $tp = $topics[(int)$r['topic_id']] ?? null;
    $u = $users[(int)$r['user_id']] ?? null;
    $info = $full[(int)$r['user_id']] ?? null;
    $staff = $u !== null && ($g = group_by_id((int)$u['group_id'])) !== null && ((int)$g['is_admin'] === 1 || (int)$g['is_mod'] === 1);
    $cat = $tp !== null ? category_by_id((int)$tp['category_id']) : null;
  ?>
  <div class="review-item" data-review-item="<?= (int)$r['id'] ?>">
    <?= avatar($u, 36) ?>
    <div class="review-main">
      <div class="review-who"><?= user_link($u) ?><?php if ($info): ?><span class="muted small"> · <?= t('joined %s', time_tag((int)$info['created_at'])) ?> · <?= h(t('public posts: %d', (int)$info['topic_count'] + (int)$info['post_count'])) ?><?= (int)$info['status'] !== 1 ? ' · ' . t('suspended') : '' ?></span><?php endif; ?></div>
      <div class="review-where">
        <span class="muted small"><?= $r['kind'] === 'topic' ? t('New topic in') : t('Reply in') ?></span>
        <?php if ($r['kind'] === 'topic'): ?><?php if ($cat): ?><span class="review-chip"><?= h($cat['name']) ?></span><?php endif; ?>
        <?php elseif ($tp): ?><a class="review-chip" href="<?= h(topic_url($tp)) ?>"><?= h(cut((string)$tp['title'], 50)) ?></a><?php endif; ?>
        <span class="review-reason"><?= h(t((string)$r['reason'])) ?></span>
      </div>
      <?php if ($r['kind'] === 'topic' && $tp): ?><a class="review-title" href="<?= h(topic_url($tp)) ?>"><?= h((string)$tp['title']) ?></a><?php endif; ?>
      <?php if ($p): ?><div class="review-text"><?= h(md_excerpt((string)$p['body'], 280)) ?></div><?php endif; ?>
      <?php if ($tab === 'waiting'): ?>
      <form method="post" action="<?= h(url('/review/act')) ?>" class="review-actions" data-review>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button type="submit" name="do" value="approve" class="btn btn-sm btn-primary"><?= t('Approve') ?></button>
        <button type="submit" name="do" value="trust" class="btn btn-sm" title="<?= h(t('Approve everything this member has waiting; their posts no longer wait for review.')) ?>"><?= t('Approve and trust') ?></button>
        <button type="button" class="btn btn-sm review-reject" data-review-reject><?= t('Reject…') ?></button>
        <?php if (!$staff): ?><button type="submit" name="do" value="ban" class="btn btn-sm review-danger" data-confirm="<?= h(t('Suspend this member and delete everything they wrote?')) ?>"><?= t('Ban and delete all') ?></button><?php endif; ?>
        <span class="review-note" hidden><input type="text" name="note" maxlength="190" placeholder="<?= h(t('Reason shown to the author (optional)')) ?>"><button type="submit" name="do" value="reject" class="btn btn-sm review-danger"><?= t('Reject') ?></button></span>
      </form>
      <?php else: $by = $users[(int)$r['decided_by']] ?? null; ?>
      <div class="muted small review-decided"><?= $tab === 'approved' ? t('Approved by %s', user_link($by)) : t('Rejected by %s', user_link($by)) ?> · <?= time_tag((int)$r['decided_at']) ?><?php if ((string)$r['note'] !== ''): ?> · “<?= h((string)$r['note']) ?>”<?php endif; ?></div>
      <?php endif; ?>
    </div>
    <span class="muted small review-time"><?= time_tag((int)$r['created_at']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?= raw($pagination) ?>

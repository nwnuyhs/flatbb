<?php /** Reply list for profile pages. Variables: posts, topics, user, empty */ ?>
<div class="post-rows">
<?php if ($posts === []): ?><div class="empty"><?= icon('message') ?><p><?= h($empty) ?></p></div><?php endif; ?>
<?php foreach ($posts as $p): $t = $topics[(int)$p['topic_id']] ?? null; if ($t === null || (int)$t['is_deleted'] === 1) continue; ?>
  <article class="post-row">
    <h3 class="row-title"><a href="<?= h(url('/post/' . $p['id'])) ?>"><?= h($t['title']) ?></a></h3>
    <div class="post-excerpt"><?= h(md_excerpt((string)$p['body'], 220)) ?></div>
    <div class="row-meta"><?= category_badge(category_by_id((int)$t['category_id'])) ?><span class="row-time"><?= time_tag((int)$p['created_at']) ?></span><?php if ((int)$p['like_count'] > 0): ?><span class="stat"><?= icon('heart') ?><?= (int)$p['like_count'] ?></span><?php endif; ?></div>
  </article>
<?php endforeach; ?>
</div>

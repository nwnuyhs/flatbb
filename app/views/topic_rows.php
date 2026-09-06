<?php
/**
 * Topic rows: avatar, title, one meta line under the title, category tag on the right.
 * Variables: topics, empty.
 * In-loop slots and filters (no DB queries allowed in their hooks): topic.title, topic_list.item.title_suffix, topic_list.item.meta, topic_list.item.after
 */
?>
<div class="topic-rows" data-slot="topic_list">
<?php if ($topics === []): ?>
  <div class="empty"><?= icon('message') ?><p><?= h($empty) ?></p></div>
<?php endif; ?>
<?php foreach ($topics as $t): $ctx = ['topic' => $t]; ?>
  <article class="topic-row<?= uid() > 0 ? ($t['unread'] ? ' unread' : ' read') : '' ?><?= (int)$t['is_pinned'] ? ' pinned' : '' ?>" data-topic-id="<?= (int)$t['id'] ?>" data-slot="topic_list.item">
    <div class="row-avatar"><?= avatar($t['user'], 40) ?></div>
    <div class="row-main">
      <h3 class="row-title">
        <?php if ((int)$t['is_pinned']): ?><span class="row-icon" title="<?= t('Pinned') ?>"><?= icon('pin') ?></span><?php endif; ?>
        <?php if ((int)$t['is_locked']): ?><span class="row-icon" title="<?= t('Locked') ?>"><?= icon('lock') ?></span><?php endif; ?>
        <a href="<?= h(topic_url($t)) ?>"><?= raw(hook('topic.title', h($t['title']), ['topic' => $t, 'where' => 'list'])) ?></a>
        <?= slot('topic_list.item.title_suffix', $ctx) ?>
      </h3>
      <div class="row-meta">
        <span class="meta"><?= icon('user') ?><?= user_link($t['user'], 'user-link plain') ?></span>
        <span class="meta" title="<?= t('Views') ?>"><?= icon('eye') ?><?= human_number((int)$t['view_count']) ?></span>
        <span class="meta" title="<?= t('Replies') ?>"><?= icon('message') ?><?= human_number((int)$t['reply_count']) ?></span>
        <?php if ((int)$t['reply_count'] > 0 && $t['last_user']): ?><span class="meta" title="<?= t('Last reply') ?>"><?= icon('reply') ?><?= user_link($t['last_user'], 'user-link plain') ?></span><?php endif; ?>
        <a class="meta row-time" href="<?= h(topic_url($t, 1, (int)$t['last_post_id'])) ?>" title="<?= date('Y-m-d H:i', (int)$t['last_post_at']) ?>"><?= human_time((int)$t['last_post_at']) ?></a>
        <?php foreach ($t['tags'] as $tg): ?><a class="meta row-tag" href="<?= h(tag_url($tg)) ?>">#<?= h($tg['name']) ?></a><?php endforeach; ?>
        <?php if ($t['category']): ?><a class="meta meta-cat" href="<?= h(category_url($t['category'])) ?>"><?= h($t['category']['name']) ?></a><?php endif; ?>
        <?= slot('topic_list.item.meta', $ctx) ?>
      </div>
    </div>
    <?php if ($t['category']): ?><a class="row-cat" href="<?= h(category_url($t['category'])) ?>"><?= h($t['category']['name']) ?></a><?php endif; ?>
    <?= slot('topic_list.item.after', $ctx) ?>
  </article>
<?php endforeach; ?>
</div>

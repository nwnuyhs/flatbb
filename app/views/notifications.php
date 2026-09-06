<?php /** Notification list. Variables: rows, pagination */ ?>
<div class="notifications">
  <h1><?= t('Notifications') ?></h1>
  <?php if ($rows === []): ?><div class="empty"><?= icon('bell') ?><p><?= t('No notifications yet.') ?></p></div><?php endif; ?>
  <div class="notif-list">
  <?php $kinds = (array)hook('notification.kinds', ['reply' => ['reply', t('replied')], 'mention' => ['user', t('mentioned you')], 'like' => ['heart', t('liked your post')], 'system' => ['info', '']], []); ?>
  <?php foreach ($rows as $n): [$ic, $verb] = $kinds[$n['kind']] ?? ['info', $n['kind']]; ?>
    <a class="notif<?= (int)$n['is_read'] ? '' : ' unread' ?>" href="<?= h($n['url'] ?: '#') ?>">
      <span class="notif-icon"><?= icon($ic) ?></span>
      <span class="notif-body">
        <span class="notif-title"><?php if ($n['from']): ?><b><?= h($n['from']['username']) ?></b> <?php endif; ?><?= h($verb) ?><?php if ($n['topic']): ?> · <em><?= h(cut($n['topic']['title'], 60)) ?></em><?php endif; ?></span>
        <?php if ($n['content'] !== ''): ?><span class="notif-excerpt"><?= h(cut((string)$n['content'], 140)) ?></span><?php endif; ?>
      </span>
      <span class="notif-time"><?= human_time((int)$n['created_at']) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
  <?= raw($pagination) ?>
</div>

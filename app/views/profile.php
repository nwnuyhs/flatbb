<?php
/**
 * Public profile: a centred card (avatar, name, labels, bio, details, buttons, number tiles, sections from plugins) with the tabs
 * and their lists under it; plugin cards follow. A phone shows the same card, tighter.
 * Variables: user, group, self, tabs (html), body, pagination, stats, cards.
 * Regions: user.profile.labels, user.profile.actions, user.profile.meta, user.profile.stats (list: label, value, url, sub, progress 0..1),
 * user.profile.after (sections inside the card), user.profile.cards (list, under the card)
 */
$ctx = ['user' => $user, 'self' => $self];
?>
<div class="profile">
  <aside class="profile-card">
    <header class="profile-head">
      <?= avatar($user, 96, false) ?>
      <div class="profile-id">
        <h1 class="profile-name" dir="auto"><?= h($user['username']) ?></h1>
        <div class="profile-labels"><?= raw(hook('user.link_after', '', ['user' => $user, 'class' => 'profile-name'])) ?><?php if ($group): ?><span class="flag" style="<?= !empty($group['color']) ? 'color:' . h($group['color']) : '' ?>"><?= h($group['name']) ?></span><?php endif; ?><?php if ((int)$user['status'] !== 1): ?><span class="flag flag-danger"><?= t('Suspended') ?></span><?php endif; ?><?= region('user.profile.labels', $ctx, '', false) ?></div>
      </div>
      <?php if ($user['bio'] !== '' && $user['bio'] !== null): ?><p class="profile-bio"><?= raw(bio_html((string)$user['bio'])) ?></p><?php endif; ?>
      <div class="profile-meta">
        <span><?= icon('clock') ?><?= t('Joined %s', time_tag((int)$user['created_at'], 'date')) ?></span>
        <span><?= icon('eye') ?><?= t('Seen %s', time_tag((int)$user['last_seen'])) ?></span>
        <?php if (!empty($user['location'])): ?><span><?= icon('flag') ?><?= h($user['location']) ?></span><?php endif; ?>
        <?php if (!empty($user['website'])): ?><span><?= icon('external') ?><a href="<?= h($user['website']) ?>" rel="nofollow ugc noopener" target="_blank"><?= h(preg_replace('#^https?://#', '', $user['website'])) ?></a></span><?php endif; ?>
        <?= slot('user.profile.meta', $ctx) ?>
      </div>
      <div class="profile-side">
        <?php if ($self): ?><a class="btn btn-sm" href="<?= h(url('/settings')) ?>" title="<?= t('Edit profile') ?>"><?= icon('settings') ?><span><?= t('Edit profile') ?></span></a><?php endif; ?>
        <?php if (is_admin() && !$self): ?><a class="btn btn-sm" href="<?= h(admin_url('users', ['q' => $user['username'], 'edit' => $user['id']])) ?>" title="<?= t('Manage') ?>"><?= icon('shield') ?><span><?= t('Manage') ?></span></a><?php endif; ?>
        <?= region('user.profile.actions', $ctx, '', false) ?>
      </div>
    </header>
    <?php if ($stats !== []): ?>
    <div class="profile-stats" data-slot="user.profile.stats">
      <?php foreach ($stats as $st): $tag = !empty($st['url']) ? 'a' : 'div'; $bar = isset($st['progress']) ? (int)round(max(0, min(1, (float)$st['progress'])) * 100) : null; ?><<?= h($tag) ?><?= !empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '' ?> class="pstat<?= h($bar !== null ? ' pstat-wide' : '') ?>"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span><?php if (!empty($st['sub'])): ?><small><?= h((string)$st['sub']) ?></small><?php endif; ?><?php if ($bar !== null): ?><i class="pstat-bar" style="--p:<?= h($bar . '%') ?>"></i><?php endif; ?></<?= h($tag) ?>><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?= region('user.profile.after', $ctx) ?>
  </aside>
  <div class="profile-main">
    <div class="list-card">
      <div class="list-head" data-slot="user.profile.tabs"><?= raw($tabs) ?></div>
      <?= raw($body) ?>
    </div>
    <?= raw($pagination) ?>
  </div>
  <?php if ($cards !== []): ?><div class="profile-cards"><?= view('sidebar_right', ['cards' => $cards]) ?></div><?php endif; ?>
</div>

<?php /** Public profile. Variables: user, group, self, tabs (html), body, pagination, stats. Regions: user.profile.stats (list), user.profile.after */ ?>
<div class="profile">
  <header class="profile-head">
    <?= avatar($user, 72, false) ?>
    <div class="profile-info">
      <h1><?= h($user['username']) ?><?= raw(hook('user.link_after', '', ['user' => $user, 'class' => 'profile-name'])) ?> <?php if ($group): ?><span class="flag" style="<?= !empty($group['color']) ? 'color:' . h($group['color']) : '' ?>"><?= h($group['name']) ?></span><?php endif; ?><?php if ((int)$user['status'] !== 1): ?><span class="flag flag-danger"><?= t('Suspended') ?></span><?php endif; ?></h1>
      <?php if ($user['bio'] !== '' && $user['bio'] !== null): ?><p class="profile-bio"><?= h($user['bio']) ?></p><?php endif; ?>
      <div class="profile-meta">
        <span><?= icon('clock') ?><?= t('Joined %s', time_tag((int)$user['created_at'], 'date')) ?></span>
        <span><?= icon('eye') ?><?= t('Seen %s', time_tag((int)$user['last_seen'])) ?></span>
        <?php if (!empty($user['location'])): ?><span><?= icon('flag') ?><?= h($user['location']) ?></span><?php endif; ?>
        <?php if (!empty($user['website'])): ?><span><?= icon('external') ?><a href="<?= h($user['website']) ?>" rel="nofollow ugc noopener" target="_blank"><?= h(preg_replace('#^https?://#', '', $user['website'])) ?></a></span><?php endif; ?>
        <?= slot('user.profile.meta', ['user' => $user, 'self' => $self]) ?>
      </div>
    </div>
    <div class="profile-side">
      <?php if ($self): ?><a class="btn btn-sm" href="<?= h(url('/settings')) ?>"><?= icon('settings') ?><?= t('Edit profile') ?></a><?php endif; ?>
      <?php if (is_admin() && !$self): ?><a class="btn btn-sm" href="<?= h(admin_url('users', ['q' => $user['username'], 'edit' => $user['id']])) ?>"><?= icon('shield') ?><?= t('Manage') ?></a><?php endif; ?>
      <?= region('user.profile.actions', ['user' => $user, 'self' => $self], '', false) ?>
    </div>
    <div class="profile-stats" data-slot="user.profile.stats">
      <?php foreach ($stats as $st): $tag = !empty($st['url']) ? 'a' : 'span'; ?><<?= h($tag) ?><?= !empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '' ?> class="pstat"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span><?php if (!empty($st['sub'])): ?><small><?= h((string)$st['sub']) ?></small><?php endif; ?></<?= h($tag) ?>><?php endforeach; ?>
    </div>
  </header>
  <?= region('user.profile.after', ['user' => $user, 'self' => $self]) ?>
  <div class="list-head" data-slot="user.profile.tabs"><?= raw($tabs) ?></div>
  <?= raw($body) ?>
  <?= raw($pagination) ?>
</div>

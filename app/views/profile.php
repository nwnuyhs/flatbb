<?php
/**
 * Public profile, option A: a head card across the page (avatar, name and labels, bio, details, buttons; the number tiles in a row
 * under them), then two columns: the tabs and their lists, and beside them the sections plugins add (progress bars, badges,
 * member.sections, the older user.profile.after and user.profile.cards). Phones stack it: head, sections, lists.
 * Variables: user, group, self, tabs (html), body, pagination, stats, cards.
 * Regions: user.profile.labels, user.profile.actions, user.profile.meta, user.profile.stats (list: label, value, url, sub,
 * progress 0..1), user.profile.after, user.profile.cards (list); the shared member.labels / member.actions and
 * member.sections (list: title, html, url, link, weight; place "profile")
 */
$ctx = ['user' => $user, 'self' => $self];
$mctx = $ctx + ['place' => 'profile'];
$actions = region_list('member.actions', [], $mctx);
$tiles = array_filter($stats, static fn($st): bool => is_array($st) && !isset($st['progress']));
$bars = array_filter($stats, static fn($st): bool => is_array($st) && isset($st['progress']));
$sections = region_list('member.sections', [], $mctx);
$after = region('user.profile.after', $ctx);
$aside = $bars !== [] || $sections !== [] || region_visible($after) || $cards !== [];
?>
<div class="profile">
  <section class="profile-card">
    <header class="profile-head">
      <?= avatar($user, 96, false) ?>
      <div class="profile-id">
        <h1 class="profile-name" dir="auto"><?= h(user_name($user)) ?></h1>
        <?php if (display_names_on()): // every profile names its account while display names are on, set or not ?><div class="profile-handle">@<?= h($user['username']) ?></div><?php endif; ?>
        <div class="profile-labels"><?= raw(hook('user.link_after', '', ['user' => $user, 'class' => 'profile-name'])) ?><?php if ($group): ?><span class="flag" style="<?= !empty($group['color']) ? 'color:' . h($group['color']) : '' ?>"><?= h($group['name']) ?></span><?php endif; ?><?php if ((int)$user['status'] !== 1): ?><span class="flag flag-danger"><?= t('Suspended') ?></span><?php endif; ?><?= region('user.profile.labels', $ctx, '', false) ?><?= region('member.labels', $mctx, '', false) ?></div>
        <?php if ($user['bio'] !== '' && $user['bio'] !== null): ?><p class="profile-bio"><?= raw(bio_html((string)$user['bio'])) ?></p><?php endif; ?>
        <div class="profile-meta">
          <span><?= icon('clock') ?><?= t('Joined %s', time_tag((int)$user['created_at'], 'date')) ?></span>
          <span><?= icon('eye') ?><?= t('Seen %s', time_tag((int)$user['last_seen'])) ?></span>
          <?php if (!empty($user['location'])): ?><span><?= icon('flag') ?><?= h($user['location']) ?></span><?php endif; ?>
          <?php if (!empty($user['website'])): ?><span><?= icon('external') ?><a href="<?= h($user['website']) ?>" rel="nofollow ugc noopener" target="_blank"><?= h(preg_replace('#^https?://#', '', $user['website'])) ?></a></span><?php endif; ?>
          <?= slot('user.profile.meta', $ctx) ?>
        </div>
      </div>
      <div class="profile-side">
        <?php if ($self): ?><a class="btn btn-sm" href="<?= h(url('/settings')) ?>" title="<?= t('Edit profile') ?>"><?= icon('settings') ?><span><?= t('Edit profile') ?></span></a><?php endif; ?>
        <?php if (is_admin() && !$self): ?><a class="btn btn-sm" href="<?= h(admin_url('users', ['q' => $user['username'], 'edit' => $user['id']])) ?>" title="<?= t('Manage') ?>"><?= icon('shield') ?><span><?= t('Manage') ?></span></a><?php endif; ?>
        <?= region('user.profile.actions', $ctx, '', false) ?>
        <?php foreach ($actions as $id => $a): if (!is_array($a)) continue; ?><?= raw(member_action_html((string)$id, $a, 'btn btn-sm' . (!empty($a['primary']) ? ' btn-primary' : ''))) ?><?php endforeach; ?>
      </div>
    </header>
    <?php if ($tiles !== []): ?>
    <div class="profile-stats" data-slot="user.profile.stats">
      <?php foreach ($tiles as $st): $tag = !empty($st['url']) ? 'a' : 'div'; ?><<?= h($tag) ?><?= raw(!empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '') ?> class="pstat"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span><?php if (!empty($st['sub'])): ?><small><?= h((string)$st['sub']) ?></small><?php endif; ?></<?= h($tag) ?>><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <div class="profile-body<?= h($aside ? '' : ' profile-body-full') ?>">
    <div class="profile-main">
      <div class="list-card">
        <div class="list-head" data-slot="user.profile.tabs"><?= raw($tabs) ?></div>
        <?= raw($body) ?>
      </div>
      <?= raw($pagination) ?>
    </div>
    <?php if ($aside): ?>
    <aside class="profile-aside card-stack" data-slot="member.sections">
      <?php if ($bars !== []): ?><section class="card profile-progress"><div class="card-body"><?php foreach ($bars as $st): $tag = !empty($st['url']) ? 'a' : 'div'; $bar = (int)round(max(0, min(1, (float)$st['progress'])) * 100); ?><<?= h($tag) ?><?= raw(!empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '') ?> class="pstat pstat-wide"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span><?php if (!empty($st['sub'])): ?><small><?= h((string)$st['sub']) ?></small><?php endif; ?><i class="pstat-bar" style="--p:<?= h($bar . '%') ?>"></i></<?= h($tag) ?>><?php endforeach; ?></div></section><?php endif; ?>
      <?php foreach ($sections as $id => $sec): if (!is_array($sec)) continue; ?>
      <section class="card profile-section" data-section="<?= h((string)$id) ?>">
        <?php if (!empty($sec['title'])): ?><header class="card-head"><h3><?= h((string)$sec['title']) ?></h3><?php if (!empty($sec['url'])): ?><a class="small" href="<?= h((string)$sec['url']) ?>"><?= h((string)($sec['link'] ?? t('View all'))) ?></a><?php endif; ?></header><?php endif; ?>
        <div class="card-body"><?= raw((string)($sec['html'] ?? '')) ?></div>
      </section>
      <?php endforeach; ?>
      <?php if (region_visible($after)): ?><section class="card profile-section"><div class="card-body"><?= raw($after) ?></div></section><?php endif; ?>
      <?php if ($cards !== []): ?><?= view('sidebar_right', ['cards' => $cards]) ?><?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>
</div>

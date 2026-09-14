<?php
/**
 * The account dropdown in the header: who is signed in (avatar, name, labels), a strip of their numbers, the menu in groups, Sign out.
 * Variables: me, items (the header.user_menu list: label, url, icon, count, group "you" or "site", weight).
 * Regions: header.user_menu.labels (short labels under the name), header.user_menu.stats (list: label, value, url)
 */
$ctx = ['user' => $me];
$g = group_by_id((int)$me['group_id']);
$stats = region_list('header.user_menu.stats', [
    'points' => ['label' => t('Points'), 'value' => human_number((int)$me['points']), 'url' => url('/points')],
    'topics' => ['label' => t('Topics'), 'value' => human_number((int)$me['topic_count']), 'url' => user_url($me)],
    'replies' => ['label' => t('Replies'), 'value' => human_number((int)$me['post_count']), 'url' => user_url($me) . '/replies'],
], $ctx);
$groups = [];
foreach ($items as $id => $it) $groups[(string)($it['group'] ?? 'you')][$id] = $it; // groups follow the order of their first item
?>
<div class="dropdown user-menu" data-dropdown>
  <button class="dropdown-toggle" type="button" aria-haspopup="true" aria-label="<?= t('Account menu') ?>"><?= avatar($me, 32, false) ?></button>
  <div class="dropdown-menu" data-slot="header.user_menu">
    <a class="um-head" href="<?= h(user_url($me)) ?>"><?= avatar($me, 40, false) ?><span class="um-id"><b><?= h((string)$me['username']) ?></b><span class="um-labels"><?php if ($g && ((int)$g['is_admin'] || (int)$g['is_mod'])): ?><span class="flag" style="<?= !empty($g['color']) ? 'color:' . h($g['color']) : '' ?>"><?= h($g['name']) ?></span><?php endif; ?><?= region('header.user_menu.labels', $ctx, '', false) ?></span></span></a>
    <?php if ($stats !== []): ?><div class="um-stats"><?php foreach ($stats as $st): ?><a href="<?= h((string)($st['url'] ?? user_url($me))) ?>"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
    <?php foreach ($groups as $name => $list): ?><div class="um-group" data-group="<?= h($name) ?>"><?php foreach ($list as $it): ?><a href="<?= h((string)$it['url']) ?>"><?= !empty($it['icon']) ? icon((string)$it['icon']) : '' ?><span><?= h((string)$it['label']) ?></span><?php if (!empty($it['count'])): ?><b class="um-count"><?= h((string)$it['count']) ?></b><?php endif; ?></a><?php endforeach; ?></div><?php endforeach; ?>
    <form class="um-group" method="post" action="<?= h(url('/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?><?= t('Sign out') ?></button></form>
  </div>
</div>

<?php
/**
 * One member as a card: avatar, name, group and labels, number tiles (a number with progress becomes a bar across the card),
 * an optional line under them, then the buttons (primary ones full width, the others sharing a row).
 * Used by the sidebar member card (place "card") and the topic author card (place "author").
 * Variables: user, place, self, stats (the core's numbers), actions (the core's buttons), foot (escaped HTML under the numbers, may be ''),
 * links (account menu items shown as a grid of shortcuts; [] for none)
 * Regions (ctx: user, self, place): member.labels, member.stats, member.actions (label, url, icon, primary, count, title, post, done)
 */
$ctx = ['user' => $user, 'self' => $self, 'place' => $place];
$g = group_by_id((int)$user['group_id']);
$stats = region_list('member.stats', $stats, $ctx);
$tiles = array_filter($stats, static fn($st): bool => is_array($st) && !isset($st['progress']));
$bars = array_filter($stats, static fn($st): bool => is_array($st) && isset($st['progress']));
$actions = region_list('member.actions', $actions, $ctx);
$primary = array_filter($actions, static fn($a): bool => is_array($a) && !empty($a['primary']));
$more = array_filter($actions, static fn($a): bool => is_array($a) && empty($a['primary']));
?>
<section class="card card-me card-me-<?= h($place) ?>">
  <div class="card-body">
    <div class="me-head"><?= avatar($user, 40) ?><div class="me-id"><strong><?= user_link($user) ?></strong><span class="me-labels"><?php if ($g): ?><span class="me-group"<?= raw(!empty($g['color']) && ((int)$g['is_admin'] || (int)$g['is_mod']) ? ' style="color:' . h($g['color']) . '"' : '') ?>><?= h($g['name']) ?></span><?php endif; ?><?= region('member.labels', $ctx, '', false) ?></span></div></div>
    <?php if ($tiles !== []): ?><div class="me-stats" data-slot="member.stats"><?php foreach ($tiles as $st): $tag = !empty($st['url']) ? 'a' : 'div'; ?><<?= h($tag) ?><?= raw(!empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '') ?><?= raw(!empty($st['sub']) ? ' title="' . h((string)$st['sub']) . '"' : '') ?>><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span></<?= h($tag) ?>><?php endforeach; ?></div><?php endif; ?>
    <?php foreach ($bars as $st): $tag = !empty($st['url']) ? 'a' : 'div'; $pct = (int)round(max(0, min(1, (float)$st['progress'])) * 100); ?>
    <<?= h($tag) ?> class="me-bar"<?= raw(!empty($st['url']) ? ' href="' . h((string)$st['url']) . '"' : '') ?>><span class="me-bar-top"><b><?= h((string)$st['value']) ?></b><span><?= h((string)$st['label']) ?></span><?php if (!empty($st['sub'])): ?><small><?= h((string)$st['sub']) ?></small><?php endif; ?></span><span class="me-bar-track"><i style="width:<?= h((string)$pct) ?>%"></i></span></<?= h($tag) ?>>
    <?php endforeach; ?>
    <?php if ($foot !== ''): ?><div class="me-foot"><?= raw($foot) ?></div><?php endif; ?>
    <?php if ($links !== []): ?>
    <nav class="me-links" data-slot="header.user_menu"><?php foreach ($links as $id => $it): ?><a href="<?= h((string)$it['url']) ?>" data-link="<?= h((string)$id) ?>"><?= !empty($it['icon']) ? icon_any((string)$it['icon']) : '' ?><span><?= h((string)$it['label']) ?></span><?php if (!empty($it['count'])): ?><b class="me-count"><?= h((string)$it['count']) ?></b><?php endif; ?></a><?php endforeach; ?></nav>
    <?php endif; ?>
    <?php if ($actions !== []): ?>
    <div class="me-actions" data-slot="member.actions">
      <?php foreach ($primary as $id => $a): ?><?= raw(member_action_html((string)$id, $a, 'btn btn-primary btn-block')) ?><?php endforeach; ?>
      <?php if ($more !== []): ?><div class="me-more"><?php foreach ($more as $id => $a): ?><?= raw(member_action_html((string)$id, $a, 'btn btn-sm')) ?><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

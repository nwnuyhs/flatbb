<?php
/**
 * /points: the balance card, the ways to earn as tiles, the history grouped by day, the rule table.
 * Variables: me, earned, spent, summary, actions, kind, history (html), rules (html).
 * Regions: points.summary (list: label, sub, progress 0..1, url), points.actions (list: label, title, sub, url, icon, primary, done)
 */
$link = static fn(string $k): string => url('/points', $k !== '' ? ['kind' => $k] : []);
?>
<section class="card points-hero">
  <div class="points-hero-main">
    <small><?= t('Balance') ?></small>
    <b class="points-hero-balance"><?= h(human_number((int)$me['points'])) ?><span><?= t('points') ?></span></b>
    <?php if ($summary !== []): ?>
    <div class="points-hero-summary" data-slot="points.summary">
      <?php foreach ($summary as $s): ?><?php if (is_array($s)): $bar = isset($s['progress']) ? (int)round(max(0, min(1, (float)$s['progress'])) * 100) : null; $tag = !empty($s['url']) ? 'a' : 'span'; ?><<?= h($tag) ?> class="points-chip"<?= !empty($s['url']) ? ' href="' . h((string)$s['url']) . '"' : '' ?>><b><?= h((string)$s['label']) ?></b><?php if (!empty($s['sub'])): ?><small><?= h((string)$s['sub']) ?></small><?php endif; ?><?php if ($bar !== null): ?><i class="points-chip-bar" style="--p:<?= h($bar . '%') ?>"></i><?php endif; ?></<?= h($tag) ?>><?php else: ?><?= raw((string)$s) ?><?php endif; ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="points-hero-month">
    <div><b class="up">+<?= h(human_number($earned)) ?></b><small><?= t('Earned this month') ?></small></div>
    <div><b class="down">−<?= h(human_number($spent)) ?></b><small><?= t('Spent this month') ?></small></div>
  </div>
</section>
<?php if ($actions !== []): ?>
<section class="card points-earn">
  <header class="card-head"><h3><?= t('Ways to earn') ?></h3></header>
  <div class="points-tiles" data-slot="points.actions">
    <?php foreach ($actions as $a): ?><?php if (is_array($a)): ?><a class="points-tile<?= h((!empty($a['primary']) ? ' is-primary' : '') . (!empty($a['done']) ? ' is-done' : '')) ?>" href="<?= h((string)$a['url']) ?>"><span class="points-tile-icon"><?= icon(!empty($a['done']) ? 'check' : (string)($a['icon'] ?? 'coin')) ?></span><span class="points-tile-text"><b><?= h((string)($a['title'] ?? $a['label'])) ?></b><?php if (!empty($a['sub'])): ?><small><?= h((string)$a['sub']) ?></small><?php endif; ?></span></a><?php else: ?><?= raw((string)$a) ?><?php endif; ?><?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="card points-history">
  <header class="card-head"><h3><?= t('History') ?></h3><?= tabs(['all' => ['label' => t('All'), 'url' => $link(''), 'active' => $kind === ''], 'in' => ['label' => t('Earned'), 'url' => $link('in'), 'active' => $kind === 'in'], 'out' => ['label' => t('Spent'), 'url' => $link('out'), 'active' => $kind === 'out']], 'tabs tabs-sub') ?></header>
  <div class="card-body"><?= raw($history) ?></div>
</section>
<?= raw($rules) ?>

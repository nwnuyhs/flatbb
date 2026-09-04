<?php /** Right column: a stack of cards. Variables: cards (array of html keyed by id). Regions: sidebar.right.top/cards/bottom */ ?>
<?= region('sidebar.right.top') ?>
<div class="card-stack" data-slot="sidebar.right.cards">
<?php foreach ($cards as $key => $html): ?>
  <div class="card-slot" data-card="<?= h((string)$key) ?>"><?= is_array($html) ? ($html['html'] ?? '') : $html ?></div>
<?php endforeach; ?>
</div>
<?= region('sidebar.right.bottom') ?>

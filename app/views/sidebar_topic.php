<?php /** Topic page right column. Variables: topic, cards. Regions: topic.sidebar.top/cards/bottom */ ?>
<?= region('topic.sidebar.top', ['topic' => $topic]) ?>
<div class="card-stack" data-slot="topic.sidebar.cards">
<?php foreach ($cards as $key => $html): ?>
  <div class="card-slot" data-card="<?= h((string)$key) ?>"><?= is_array($html) ? ($html['html'] ?? '') : $html ?></div>
<?php endforeach; ?>
</div>
<?= region('topic.sidebar.bottom', ['topic' => $topic]) ?>

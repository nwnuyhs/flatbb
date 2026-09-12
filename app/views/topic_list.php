<?php /** List page body. Variables: title, tabs (html), sub_tabs, topics, pagination, heading, empty, new (Latest lists: since, cats) */ ?>
<?= raw($heading) ?>
<div class="list-card">
  <?= raw($tabs) ?>
  <?= raw($sub_tabs) ?>
  <?php $new = $new ?? null; if (is_array($new)): ?>
  <div class="list-new" data-new-topics="<?= h(url('/api/new_topics', ['since' => (int)$new['since']] + ($new['cats'] !== [] ? ['c' => implode(',', $new['cats'])] : []))) ?>" data-one="<?= h(t('See 1 new or updated topic')) ?>" data-many="<?= h(t('See %d new or updated topics')) ?>" hidden><button type="button" class="list-new-btn"></button></div>
  <?php endif; ?>
  <?= region('topic_list.before', ['topics' => $topics]) ?>
  <?= view('topic_rows', ['topics' => $topics, 'empty' => $empty]) ?>
</div>
<?= region('topic_list.after', ['topics' => $topics]) ?>
<?= raw($pagination) ?>

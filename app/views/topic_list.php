<?php /** List page body. Variables: title, tabs (html), sub_tabs, topics, pagination, heading, empty */ ?>
<?= raw($heading) ?>
<div class="list-card">
  <?= raw($tabs) ?>
  <?= raw($sub_tabs) ?>
  <?= region('topic_list.before', ['topics' => $topics]) ?>
  <?= view('topic_rows', ['topics' => $topics, 'empty' => $empty]) ?>
</div>
<?= region('topic_list.after', ['topics' => $topics]) ?>
<?= raw($pagination) ?>

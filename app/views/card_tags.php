<?php /** Right column card: the most used tags, linking to the full list. Variable: tags (name, slug, topic_count), most used first */ ?>
<?php if ($tags !== []): ?>
<section class="card card-tags">
  <header class="card-head"><h3><?= t('Tags') ?></h3><a class="small" href="<?= h(url('/tags')) ?>"><?= t('View all') ?></a></header>
  <div class="card-body"><div class="tag-cloud"><?php foreach ($tags as $tg): ?><a class="tag-badge" href="<?= h(tag_url($tg)) ?>" title="<?= h(t('%d topics', (int)$tg['topic_count'])) ?>"><?= h($tg['name']) ?></a><?php endforeach; ?></div></div>
</section>
<?php endif; ?>

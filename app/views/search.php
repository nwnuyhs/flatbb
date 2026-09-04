<?php /** Search page. Variables: q, results, total, pagination */ ?>
<div class="search-page">
  <form class="search-big" action="<?= h(url('/search')) ?>" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= t('Search topics and replies…') ?>" autofocus><button type="submit" class="btn btn-primary"><?= t('Search') ?></button>
  </form>
  <?php if ($q !== ''): ?>
    <p class="muted"><?= t('%d results for "%s"', $total, $q) ?></p>
    <div class="search-results">
    <?php foreach ($results as $r): ?>
      <article class="search-result">
        <h3 class="row-title"><a href="<?= h(url('/post/' . $r['post']['id'])) ?>"><?= h($r['topic']['title']) ?></a><?php if ((int)$r['post']['floor'] > 0): ?><small class="muted"> #<?= (int)$r['post']['floor'] + 1 ?></small><?php endif; ?></h3>
        <p class="snippet"><?= raw($r['snippet']) ?></p>
        <div class="row-meta"><?= category_badge($r['category']) ?><?= user_link($r['user']) ?><span class="row-time"><?= human_time((int)$r['post']['created_at']) ?></span></div>
      </article>
    <?php endforeach; ?>
    <?php if ($results === []): ?><div class="empty"><?= icon('search') ?><p><?= t('Nothing found. Try different words.') ?></p></div><?php endif; ?>
    </div>
    <?= raw($pagination) ?>
  <?php endif; ?>
</div>

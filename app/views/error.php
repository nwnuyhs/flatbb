<div class="error-box">
  <h1><?= h($title) ?></h1>
  <p><?= h($message) ?></p>
  <a class="btn" href="<?= h(url('/')) ?>"><?= icon('arrow-left') ?><?= t('Back to home') ?></a>
</div>

<?php /** Forgot password form. Variable: sent */ ?>
<div class="auth-box">
  <h1><?= t('Forgot password') ?></h1>
  <?php if ($sent): ?>
    <div class="flash flash-success"><?= t('If that email address belongs to an account, a reset link is on its way. Check your spam folder too.') ?></div>
    <p class="auth-alt"><a href="<?= h(url('/login')) ?>"><?= t('Back to sign in') ?></a></p>
  <?php else: ?>
    <p class="muted"><?= t('Enter the email address of your account and we will send you a link to choose a new password.') ?></p>
    <form method="post" action="<?= h(url('/forgot')) ?>">
      <?= csrf_field() ?>
      <div class="form-row"><label><?= t('Email') ?></label><input type="email" name="email" required autofocus autocomplete="email"></div>
      <button type="submit" class="btn btn-primary btn-block"><?= t('Send reset link') ?></button>
    </form>
    <p class="auth-alt"><a href="<?= h(url('/login')) ?>"><?= t('Back to sign in') ?></a></p>
  <?php endif; ?>
</div>

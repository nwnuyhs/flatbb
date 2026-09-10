<?php /** New password form. Variables: token, user */ ?>
<div class="auth-box">
  <h1><?= t('Choose a new password') ?></h1>
  <p class="muted"><?= t('Account: %s', $user['username']) ?></p>
  <form method="post" action="<?= h(url('/reset/' . $token)) ?>">
    <?= csrf_field() ?>
    <div class="form-row"><label><?= t('New password') ?></label><input type="password" name="password" required minlength="<?= h((string)password_min()) ?>" autofocus autocomplete="new-password"></div>
    <button type="submit" class="btn btn-primary btn-block"><?= t('Save password') ?></button>
  </form>
</div>

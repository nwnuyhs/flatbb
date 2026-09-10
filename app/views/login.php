<?php /** Sign-in form. Variable: back. Region: auth.login.extra */ ?>
<div class="auth-box">
  <h1><?= t('Sign in') ?></h1>
  <form method="post" action="<?= h(url('/login', $back !== '' ? ['back' => $back] : [])) ?>">
    <?= csrf_field() ?>
    <div class="form-row"><label><?= t('Username or email') ?></label><input type="text" name="username" required autofocus autocomplete="username"></div>
    <div class="form-row"><label><?= t('Password') ?></label><div class="pw-wrap"><input type="password" name="password" required autocomplete="current-password"><button type="button" class="pw-toggle" data-pw-toggle aria-label="<?= t('Show password') ?>"><?= icon('eye') ?><?= icon('eye-off') ?></button></div></div>
    <div class="form-row" style="display:flex;justify-content:space-between;align-items:center"><?= checkbox('remember', true, t('Keep me signed in')) ?><a class="small" href="<?= h(url('/forgot')) ?>"><?= t('Forgot password?') ?></a></div>
    <?= region('auth.login.extra') ?>
    <button type="submit" class="btn btn-primary btn-block"><?= t('Sign in') ?></button>
  </form>
  <?php if (setting('allow_register', '1') === '1'): ?><p class="auth-alt"><?= t('No account yet?') ?> <a href="<?= h(url('/register')) ?>"><?= t('Create one') ?></a></p><?php endif; ?>
  <?= raw((string)hook('auth.login.after', '', ['back' => $back])) ?>
</div>

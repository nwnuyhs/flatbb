<?php /** Registration form. Variables: errors, values. Region: auth.register.extra */ ?>
<div class="auth-box">
  <h1><?= t('Create account') ?></h1>
  <?php if ($errors !== []): ?><div class="flash flash-error"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" action="<?= h(url('/register')) ?>">
    <?= csrf_field() ?>
    <div class="form-row"><label><?= t('Username') ?></label><input type="text" name="username" value="<?= h($values['username']) ?>" required autofocus maxlength="30" autocomplete="username" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{1,29}"></div>
    <?php if (register_verify_on()): ?>
    <div class="form-row"><label><?= t('Email') ?></label><div class="code-row"><input type="email" name="email" value="<?= h($values['email']) ?>" required autocomplete="email"><button type="button" class="btn" data-send-code="<?= h(url('/api/send_code')) ?>" data-email="email"><?= t('Send code') ?></button></div></div>
    <div class="form-row"><label><?= t('Verification code') ?></label><input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456"><div class="form-help"><?= t('We email you a six-digit code; it is valid for ten minutes.') ?></div></div>
    <?php else: ?>
    <div class="form-row"><label><?= t('Email') ?> <small class="muted">(<?= t('optional') ?>)</small></label><input type="email" name="email" value="<?= h($values['email']) ?>" autocomplete="email"></div>
    <?php endif; ?>
    <div class="form-row"><label><?= t('Password') ?></label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
    <?php if (setting('invite_code', '') !== ''): ?><div class="form-row"><label><?= t('Invite code') ?></label><input type="text" name="invite" required></div><?php endif; ?>
    <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
    <?= region('auth.register.extra') ?>
    <button type="submit" class="btn btn-primary btn-block"><?= t('Create account') ?></button>
  </form>
  <p class="auth-alt"><?= t('Already have an account?') ?> <a href="<?= h(url('/login')) ?>"><?= t('Sign in') ?></a></p>
</div>

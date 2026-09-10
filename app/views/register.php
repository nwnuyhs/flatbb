<?php /** Registration form. Variables: errors, values. Region: auth.register.extra */ ?>
<div class="auth-box">
  <h1><?= t('Create account') ?></h1>
  <?php if ($errors !== []): ?><div class="flash flash-error"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" action="<?= h(url('/register')) ?>">
    <?= csrf_field() ?>
    <?php [$umin, $umax] = username_rule(); ?>
    <div class="form-row"><label><?= t('Username') ?> <span class="req">*</span></label><input type="text" name="username" value="<?= h($values['username']) ?>" required autofocus minlength="<?= h((string)$umin) ?>" maxlength="<?= h((string)$umax) ?>" autocomplete="username" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{<?= h((string)($umin - 1)) ?>,<?= h((string)($umax - 1)) ?>}"></div>
    <?php if (register_verify_on()): ?>
    <div class="form-row"><label><?= t('Email') ?> <span class="req">*</span></label><div class="code-row"><input type="email" name="email" value="<?= h($values['email']) ?>" required autocomplete="email"><button type="button" class="btn" data-send-code="<?= h(url('/api/send_code')) ?>" data-email="email"><?= t('Send code') ?></button></div></div>
    <div class="form-row"><label><?= t('Verification code') ?> <span class="req">*</span></label><input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456"><div class="form-help"><?= t('We email you a six-digit code; it is valid for ten minutes.') ?></div></div>
    <?php else: ?>
    <div class="form-row"><label><?= t('Email') ?> <span class="req">*</span></label><input type="email" name="email" value="<?= h($values['email']) ?>" required autocomplete="email"></div>
    <?php endif; ?>
    <div class="form-row"><label><?= t('Password') ?> <span class="req">*</span></label><div class="pw-wrap"><input type="password" name="password" required minlength="<?= h((string)password_min()) ?>" autocomplete="new-password"><button type="button" class="pw-toggle" data-pw-toggle aria-label="<?= t('Show password') ?>"><?= icon('eye') ?><?= icon('eye-off') ?></button></div><div class="form-help"><?= t('At least %d characters.', password_min()) ?></div></div>
    <?php if (setting('invite_code', '') !== ''): ?><div class="form-row"><label><?= t('Invite code') ?> <span class="req">*</span></label><input type="text" name="invite" required></div><?php endif; ?>
    <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
    <?= region('auth.register.extra') ?>
    <button type="submit" class="btn btn-primary btn-block"><?= t('Create account') ?></button>
  </form>
  <p class="auth-alt"><?= t('Already have an account?') ?> <a href="<?= h(url('/login')) ?>"><?= t('Sign in') ?></a></p>
  <?= raw((string)hook('auth.register.after', '', [])) ?>
</div>

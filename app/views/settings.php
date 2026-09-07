<?php /** Account settings. Variables: user, tab, tabs (id => label, group, weight), prefs, extra */
$groups = ['account' => t('Account'), 'preferences' => t('Preferences'), 'security' => t('Security'), 'community' => t('Community'), 'developer' => t('Developer'), 'more' => t('More')];
$by = [];
foreach ($tabs as $k => $item) $by[(string)($item['group'] ?? 'more')][$k] = $item;
foreach (array_keys($by) as $g) if (!isset($groups[$g])) $groups[$g] = ucfirst($g); // a group only plugins know
?>
<div class="settings">
  <h1><?= t('Settings') ?></h1>
  <div class="settings-grid">
  <nav class="settings-menu" data-slot="user.settings.tabs">
    <?php foreach ($groups as $g => $label): if (empty($by[$g])) continue; ?>
    <h4><?= h($label) ?></h4>
    <?php foreach ($by[$g] as $k => $item): ?><a class="side-link<?= $k === $tab ? ' active' : '' ?>" href="<?= h(url('/settings/' . $k)) ?>"><span><?= h((string)$item['label']) ?></span></a><?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="settings-body">
  <select class="settings-select" data-jump aria-label="<?= t('Settings') ?>">
    <?php foreach ($groups as $g => $label): if (empty($by[$g])) continue; ?><optgroup label="<?= h($label) ?>"><?php foreach ($by[$g] as $k => $item): ?><option value="<?= h(url('/settings/' . $k)) ?>"<?= $k === $tab ? ' selected' : '' ?>><?= h((string)$item['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
  </select>
  <?php if ($tab === 'points'): ?>
  <div class="settings-form settings-points"><?= raw($extra) ?></div>
  <?php else: ?>
  <form method="post" action="<?= h(url('/settings/' . $tab)) ?>" enctype="multipart/form-data" class="settings-form">
    <?= csrf_field() ?>
    <?php if ($tab === 'profile'): ?>
      <?php if (user_rename_allowed()): ?>
      <?= form_row(t('Username'), input('username', (string)$user['username'], ['maxlength' => 30, 'pattern' => '[A-Za-z0-9][A-Za-z0-9_.-]{1,29}']), user_rename_next($user) > now() ? t('You can change your username again on %s.', date('Y-m-d', user_rename_next($user))) : t('Letters, numbers, dot, dash or underscore. Links to your old profile name keep working.')) ?>
      <?php endif; ?>
      <?php if (register_verify_on()): ?>
      <div class="form-row"><label><?= t('Email') ?></label><div class="code-row"><input type="email" name="email" value="<?= h((string)$user['email']) ?>" autocomplete="email"><button type="button" class="btn" data-send-code="<?= h(url('/api/send_code')) ?>" data-email="email"><?= t('Send code') ?></button></div><div class="form-help"><?= (int)$user['email_verified'] === 1 ? t('Verified.') : t('Not verified yet.') ?> <?= t('A code is only needed when you change the address.') ?></div></div>
      <?= form_row(t('Verification code'), input('code', '', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 6, 'placeholder' => '123456'])) ?>
      <?php else: ?>
      <?= form_row(t('Email'), input('email', (string)$user['email'], ['type' => 'email'])) ?>
      <?php endif; ?>
      <?= form_row(t('Bio'), textarea('bio', (string)$user['bio'], ['rows' => 3, 'maxlength' => 1000])) ?>
      <?= form_row(t('Website'), input('website', (string)$user['website'], ['placeholder' => 'https://'])) ?>
      <?= form_row(t('Location'), input('location', (string)$user['location'])) ?>
      <?= form_row(t('Signature'), textarea('signature', (string)$user['signature'], ['rows' => 2, 'maxlength' => 300])) ?>
    <?php elseif ($tab === 'avatar'): ?>
      <div class="form-row"><?= avatar($user, 96, false) ?></div>
      <?= form_row(t('Upload a new avatar'), input('avatar', '', ['type' => 'file', 'accept' => 'image/*']), t('JPG, PNG or WebP, up to 4 MB. It will be cropped to a square.')) ?>
      <?php if ($user['avatar'] !== ''): ?><div class="form-row"><?= checkbox('remove', false, t('Remove current avatar')) ?></div><?php endif; ?>
    <?php elseif ($tab === 'password'): ?>
      <?php if ((string)$user['password'] === ''): ?><p class="muted"><?= t('You signed up through a connected account. Set a password to sign in with it as well.') ?></p><?php else: ?><?= form_row(t('Current password'), input('old_password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])) ?><?php endif; ?>
      <?= form_row(t('New password'), input('password', '', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password'])) ?>
    <?php elseif ($tab === 'preferences'): ?>
      <?= form_row(t('Theme'), select('theme', ['auto' => t('Follow system'), 'light' => t('Light'), 'dark' => t('Dark')], (string)($prefs['theme'] ?? 'auto'))) ?>
      <div class="form-row"><?= checkbox('notify_reply', (int)($prefs['notify_reply'] ?? 1) === 1, t('Notify me when someone replies to my topics')) ?></div>
      <div class="form-row"><?= checkbox('notify_mention', (int)($prefs['notify_mention'] ?? 1) === 1, t('Notify me when someone mentions me')) ?></div>
      <div class="form-row"><?= checkbox('show_points', (int)($prefs['show_points'] ?? 1) === 1, t('Show my points on my public profile')) ?></div>
    <?php endif; ?>
    <?= raw($extra) ?>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('Save') ?></button></div>
  </form>
  <?php endif; ?>
  </div>
  </div>
  </div>

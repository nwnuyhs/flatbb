<?php /** Account settings. Variables: user, tab, tabs (id => label, group, weight), prefs, extra, index (true on /settings: phones show the list, wide screens the first form) */
$groups = ['account' => t('Account'), 'preferences' => t('Preferences'), 'security' => t('Security'), 'community' => t('Community'), 'developer' => t('Developer'), 'more' => t('More')];
$by = [];
foreach ($tabs as $k => $item) $by[(string)($item['group'] ?? 'more')][$k] = $item;
foreach (array_keys($by) as $g) if (!isset($groups[$g])) $groups[$g] = ucfirst($g); // a group only plugins know
?>
<div class="settings<?= !empty($index) ? ' settings-index' : '' ?>">
  <h1><?= t('Settings') ?></h1>
  <div class="settings-grid">
  <nav class="settings-menu" data-slot="user.settings.tabs">
    <?php foreach ($groups as $g => $label): if (empty($by[$g])) continue; ?>
    <h4><?= h($label) ?></h4>
    <?php foreach ($by[$g] as $k => $item): ?><a class="side-link<?= $k === $tab ? ' active' : '' ?>" href="<?= h(url('/settings/' . $k)) ?>"><span><?= h((string)$item['label']) ?></span><?= icon('chevron-right', 'settings-chev') ?></a><?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="settings-body">
  <a class="settings-back" href="<?= h(url('/settings')) ?>"><?= icon('chevron-left') ?><span><?= t('Settings') ?></span></a>
  <h2 class="settings-current"><?= h((string)($tabs[$tab]['label'] ?? '')) ?></h2>
  <?php if ($tab === 'points'): ?>
  <div class="settings-form settings-points"><?= raw($extra) ?></div>
  <?php elseif ($tab === 'email'): $verify = register_verify_on(); $addr = (string)$user['email']; $verified = (int)$user['email_verified'] === 1; $open = get_int('change') === 1 || $addr === ''; ?>
  <div class="settings-form settings-email">
    <div class="email-now">
      <span class="email-now-icon"><?= icon('mail') ?></span>
      <div class="email-now-main"><span class="muted small"><?= t('Current email') ?></span><b><?= h($addr !== '' ? $addr : t('None yet')) ?></b></div>
      <?php if ($verify && $addr !== ''): ?><span class="email-badge<?= $verified ? ' ok' : '' ?>"><?= icon($verified ? 'check' : 'info') ?><?= $verified ? t('Verified') : t('Not verified') ?></span><?php endif; ?>
    </div>
    <?php if ($verify && !$verified && $addr !== ''): ?>
    <form method="post" action="<?= h(url('/settings/email')) ?>" class="email-step">
      <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="email" value="<?= h($addr) ?>">
      <p class="email-step-lead"><?= t('Verify this address to secure your account: we send it a 6-digit code.') ?></p>
      <div class="code-row"><?= input('code', '', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 6, 'placeholder' => '123456', 'aria-label' => t('Verification code')]) ?><button type="button" class="btn" data-send-code="<?= h(url('/api/send_code')) ?>" data-email="email"><?= t('Send code') ?></button></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('Verify') ?></button></div>
    </form>
    <?php endif; ?>
    <details class="email-change"<?= $open ? ' open' : '' ?>>
      <summary class="btn"><?= icon('edit') ?><?= $addr !== '' ? t('Change email') : t('Add an email') ?></summary>
      <form method="post" action="<?= h(url('/settings/email')) ?>" class="email-step">
        <?= csrf_field() ?><input type="hidden" name="action" value="change">
        <?php if ((string)$user['password'] !== ''): ?><?= form_row(t('Current password'), input('password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])) ?><?php endif; ?>
        <div class="form-row"><label for="fb-new-email"><?= t('New email') ?></label><div class="code-row"><?= input('new_email', '', ['id' => 'fb-new-email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'placeholder' => 'name@example.com']) ?><?php if ($verify): ?><button type="button" class="btn" data-send-code="<?= h(url('/api/send_code')) ?>" data-email="new_email"><?= t('Send code') ?></button><?php endif; ?></div></div>
        <?php if ($verify): ?><?= form_row(t('Verification code'), input('code', '', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 6, 'required' => true, 'placeholder' => '123456']), t('Sent to the new address; valid for 10 minutes.')) ?><?php endif; ?>
        <p class="form-help"><?= $addr !== '' ? t('Your current address stays until the change is confirmed. It then gets a notice with a link that undoes the change for 7 days.') : '' ?></p>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= $addr !== '' ? t('Change email') : t('Add email') ?></button></div>
      </form>
    </details>
  </div>
  <?php else: ?>
  <form method="post" action="<?= h(url('/settings/' . $tab)) ?>" enctype="multipart/form-data" class="settings-form">
    <?= csrf_field() ?>
    <?php if ($tab === 'profile'): ?>
      <?php if (user_rename_allowed()): ?>
      <?= form_row(t('Username'), input('username', (string)$user['username'], ['maxlength' => 30, 'pattern' => '[A-Za-z0-9][A-Za-z0-9_.-]{1,29}']), user_rename_next($user) > now() ? t('You can change your username again on %s.', date('Y-m-d', user_rename_next($user))) : t('Letters, numbers, dot, dash or underscore. Links to your old profile name keep working.')) ?>
      <?php endif; ?>
      <div class="form-row"><label><?= t('Email') ?></label><div class="email-line"><span><?= h((string)$user['email']) ?></span><a class="btn btn-sm" href="<?= h(url('/settings/email')) ?>"><?= t('Manage') ?></a></div></div>
      <?= form_row(t('Bio'), textarea('bio', (string)$user['bio'], ['rows' => 3, 'maxlength' => 1000])) ?>
      <?= form_row(t('Website'), input('website', (string)$user['website'], ['placeholder' => 'https://'])) ?>
      <?= form_row(t('Location'), input('location', (string)$user['location'])) ?>
    <?php elseif ($tab === 'avatar'): ?>
      <?= form_row(t('Avatar'), raw(avatar_field($user, url('/settings/avatar'))), t('JPG, PNG or WebP, up to 4 MB. It will be cropped to a square.') . ' ' . t('Changes to the picture are saved at once.')) ?>
    <?php elseif ($tab === 'password'): ?>
      <?php if ((string)$user['password'] === ''): ?><p class="muted"><?= t('You signed up through a connected account. Set a password to sign in with it as well.') ?></p><?php else: ?><?= form_row(t('Current password'), input('old_password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])) ?><?php endif; ?>
      <?= form_row(t('New password'), input('password', '', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password'])) ?>
    <?php elseif ($tab === 'preferences'): ?>
      <?= form_row(t('Theme'), select('theme', ['auto' => t('Follow system'), 'light' => t('Light'), 'dark' => t('Dark')], (string)($prefs['theme'] ?? 'auto'))) ?>
      <?php if (count($langs = lang_available()) > 1): ?><?= form_row(t('Language'), select('lang', ['' => t('Site default') . ' (' . ($langs[lang_site_code()] ?? 'English') . ')'] + $langs, (string)($prefs['lang'] ?? ''))) ?><?php endif; ?>
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

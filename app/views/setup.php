<?php /** Installer page (standalone, no layout). Variables: checks, errors, values */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install FlatBB</title>
<link rel="stylesheet" href="<?= h(base_path()) ?>/assets/app.css?v=<?= FLATBB_VERSION ?>.<?= (int)@filemtime(ROOT . "/assets/app.css") ?>">
</head>
<body class="page-setup">
<div class="setup">
  <div class="setup-head"><?= logo_mark() ?><h1>Install FlatBB <small>v<?= FLATBB_VERSION ?></small></h1></div>
  <section class="card"><header class="card-head"><h3>Environment</h3></header><div class="card-body">
    <ul class="check-list">
    <?php foreach ($checks as $c): ?><li class="<?= $c['ok'] ? 'ok' : ($c['fatal'] ? 'bad' : 'warn') ?>"><span><?= h($c['label']) ?></span><small><?= h($c['detail']) ?></small></li><?php endforeach; ?>
    </ul>
  </div></section>
  <?php if ($errors !== []): ?><div class="flash flash-error"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" action="<?= h(url('/setup')) ?>" class="setup-form">
    <section class="card"><header class="card-head"><h3>Database</h3></header><div class="card-body">
      <div class="form-row"><label>Engine</label>
        <div class="radio-row">
          <label class="check"><input type="radio" name="driver" value="mysql"<?= $values['driver'] === 'mysql' ? ' checked' : '' ?><?= extension_loaded('pdo_mysql') ? '' : ' disabled' ?>> MySQL 5.7+ / MariaDB <small class="muted">(recommended)</small></label>
          <label class="check"><input type="radio" name="driver" value="sqlite"<?= $values['driver'] === 'sqlite' ? ' checked' : '' ?><?= extension_loaded('pdo_sqlite') ? '' : ' disabled' ?>> SQLite <small class="muted">(zero config, fine for small sites)</small></label>
        </div>
      </div>
      <div class="form-grid" data-mysql>
        <div class="form-row"><label>Host</label><input name="mysql_host" value="<?= h($values['mysql_host']) ?>"></div>
        <div class="form-row"><label>Port</label><input name="mysql_port" value="<?= h($values['mysql_port']) ?>"></div>
        <div class="form-row"><label>Database</label><input name="mysql_name" value="<?= h($values['mysql_name']) ?>"></div>
        <div class="form-row"><label>User</label><input name="mysql_user" value="<?= h($values['mysql_user']) ?>" autocomplete="off"></div>
        <div class="form-row"><label>Password</label><input name="mysql_pass" type="password" value="<?= h($values['mysql_pass']) ?>" autocomplete="off" data-lpignore="true"></div>
      </div>
    </div></section>
    <section class="card"><header class="card-head"><h3>Site & administrator</h3></header><div class="card-body">
      <div class="form-grid">
        <div class="form-row"><label>Site name</label><input name="site_name" value="<?= h($values['site_name']) ?>" required></div>
        <div class="form-row"><label>Language</label><select name="lang"><?php foreach (lang_available() as $code => $label): ?><option value="<?= h($code) ?>"<?= $values['lang'] === $code ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-grid">
        <div class="form-row"><label>Admin username</label><input name="admin_name" value="<?= h($values['admin_name']) ?>" required></div>
        <div class="form-row"><label>Admin email</label><input name="admin_email" type="email" value="<?= h($values['admin_email']) ?>"></div>
        <div class="form-row"><label>Admin password</label><input name="admin_pass" type="password" minlength="8" required autocomplete="new-password"></div>
      </div>
    </div></section>
    <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg">Install</button></div>
  </form>
</div>
<script>
(function(){var r=document.querySelectorAll('[name=driver]'),b=document.querySelector('[data-mysql]');function u(){var v=document.querySelector('[name=driver]:checked');b.style.display=v&&v.value==='mysql'?'':'none';}r.forEach(function(x){x.addEventListener('change',u)});u();})();
</script>
</body>
</html>

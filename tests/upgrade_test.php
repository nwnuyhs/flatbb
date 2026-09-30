<?php
/** One-click upgrades: a site's own language pack comes back, and an interrupted upgrade puts the previous files back. Run with: php flatbb test */

function test_upgrade_keeps_a_sites_own_language_pack(): void
{
    $base = sys_get_temp_dir() . '/flatbb-upgrade-lang-' . getmypid();
    $old = $base . '/backup/lang';
    $new = $base . '/lang';
    @mkdir($old, 0777, true);
    @mkdir($new, 0777, true);
    try {
        file_put_contents($old . '/bg.php', "<?php return ['__name' => 'Български'];\n");   // the site's own pack
        file_put_contents($old . '/pt-br.php', "<?php return ['__name' => 'Português (Brasil)'];\n");
        file_put_contents($old . '/de.php', "<?php return ['__name' => 'old'];\n");       // shipped by the release too
        file_put_contents($old . '/notes.txt', 'not a pack');
        file_put_contents($new . '/de.php', "<?php return ['__name' => 'Deutsch'];\n");
        $kept = upgrade_keep_language_packs($old, $new);
        sort($kept);
        test_same(['bg.php', 'pt-br.php'], $kept, 'packs put back');
        test_same('Български', (include $new . '/bg.php')['__name'], 'the own pack is back');
        test_same('Deutsch', (include $new . '/de.php')['__name'], 'the release keeps its own version of a pack it ships');
        test_same(false, is_file($new . '/notes.txt'), 'only language packs');
        test_same([], upgrade_keep_language_packs($base . '/missing', $new), 'no backup folder: nothing to do');
    } finally {
        upgrade_rmdir($base);
    }
}

function test_upgrade_restore_puts_back_what_an_interrupted_upgrade_replaced(): void
{
    $base = sys_get_temp_dir() . '/flatbb-upgrade-restore-' . getmypid();
    $root = $base . '/site';
    $backup = $base . '/backup';
    @mkdir($root . '/core', 0777, true);
    @mkdir($backup . '/core', 0777, true);
    try {
        file_put_contents($backup . '/index.php', 'old index');       // replaced by the upgrade before it failed
        file_put_contents($backup . '/core/a.php', 'old a');
        file_put_contents($root . '/index.php', 'new index');
        file_put_contents($root . '/core/a.php', 'new a');
        file_put_contents($root . '/core/b.php', 'new b');            // a file only the new release has
        file_put_contents($root . '/Dockerfile', 'new file');         // a path the site did not have before
        upgrade_restore(['index.php' => true, 'core' => true, 'Dockerfile' => false], $backup, $root);
        test_same('old index', file_get_contents($root . '/index.php'), 'a file comes back');
        test_same('old a', file_get_contents($root . '/core/a.php'), 'a folder comes back');
        test_same(false, is_file($root . '/core/b.php'), 'as it was, without the new files');
        test_same(false, file_exists($root . '/Dockerfile'), 'a path that did not exist goes');
    } finally {
        upgrade_rmdir($base);
    }
}

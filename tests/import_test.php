<?php
/** Importing another forum (core/import.php) with a made-up importer plugin. Everything runs in one transaction that is rolled back. Run with: php flatbb test */

/** The made-up importer: one phase per kind of row, each phase done in one step. */
function importtest_step(array $job): array
{
    $i = (int)$job['phase'];
    $key = (string)$job['phases'][$i]['key'];
    if (!empty($job['data']['explode']) && $key === 'topics') throw new RuntimeException('source went away');
    $map = (array)($job['data']['map'] ?? []);
    if ($key === 'users') {
        $admin = user_by_id((int)$job['admin_id']);
        $map['u1'] = import_user(['username' => 'old_admin', 'email' => strtoupper((string)$admin['email']), 'password' => password_hash('x', PASSWORD_DEFAULT), 'old_id' => 1]);
        $map['u2'] = import_user(['username' => '_Jane Doe', 'email' => 'jane@example.invalid', 'password' => password_hash('jane-secret-1', PASSWORD_BCRYPT), 'display_name' => 'Jane 简', 'created_at' => 1600000000, 'old_id' => 2]);
        $map['u3'] = import_user(['username' => 'Jane_Doe', 'email' => 'jane2@example.invalid', 'password' => 'not-a-hash', 'old_id' => 3]);
        $map['cat'] = import_category(['name' => 'Support', 'slug' => 'support', 'description' => 'Ask here']);
        $map['tag'] = import_tag(['name' => 'php', 'slug' => 'php']);
    } elseif ($key === 'topics') {
        import_topic(['id' => 100, 'category_id' => $map['cat'], 'user_id' => $map['u2'], 'title' => 'Hello quokkaflux', 'created_at' => 1600000100, 'tags' => [$map['tag'], $map['tag']], 'view_count' => 42]);
        import_topic(['id' => 101, 'category_id' => $map['cat'], 'user_id' => $map['u3'], 'title' => 'Waiting topic', 'created_at' => 1600000200, 'is_deleted' => REVIEW_PENDING]);
        import_post(['id' => 500, 'topic_id' => 100, 'user_id' => $map['u2'], 'floor' => 0, 'body' => 'First **post** about quokkaflux', 'created_at' => 1600000100]);
        import_post(['id' => 501, 'topic_id' => 100, 'user_id' => $map['u1'], 'floor' => 1, 'body' => 'A reply', 'created_at' => 1600000300, 'reply_to_id' => 500]);
        import_post(['id' => 502, 'topic_id' => 100, 'user_id' => $map['u3'], 'floor' => 2, 'body' => 'Hidden reply', 'created_at' => 1600000400, 'is_deleted' => 1]);
        import_post(['id' => 503, 'topic_id' => 100, 'user_id' => $map['u3'], 'floor' => 3, 'body' => 'Waiting reply', 'created_at' => 1600000500, 'is_deleted' => REVIEW_PENDING]);
        import_post(['id' => 504, 'topic_id' => 101, 'user_id' => $map['u3'], 'floor' => 0, 'body' => 'Waiting first post', 'created_at' => 1600000200, 'is_deleted' => REVIEW_PENDING]);
        import_like($map['u1'], 500, 100, 1600000600);
        import_like($map['u3'], 500, 100, 1600000700);
        import_like($map['u3'], 500, 100, 1600000800); // the same like twice counts once
        import_read($map['u2'], 100, 501, 1600000900);
        import_note('2 posts were empty and skipped.');
    }
    $job['phases'][$i]['done'] = $job['phases'][$i]['total'];
    $job['data']['map'] = $map;
    return import_phase_next($job);
}

/** Run $fn with a made-up importer plugin registered, inside a transaction that is rolled back afterwards. */
function importtest_with_importer(callable $fn): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $saved = plugin_manifests();
    try {
        $admin = (int)val("SELECT MIN(u.id) FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE g.is_admin=1");
        q('DELETE FROM fb_users WHERE id<>?', [$admin]); // a new forum: only the administrator
        save_settings(['import_job' => '']);
        db_insert('fb_plugins', ['id' => 'importtest', 'name' => 'Test Importer', 'version' => '1.0.0', 'enabled' => 1, 'installed' => 1, 'settings' => '{}', 'manifest' => '{}', 'sort' => 0, 'updated_at' => now()]);
        plugin_manifests($saved + ['importtest' => ['id' => 'importtest', 'name' => 'Test Importer', 'description' => 'Made up.', 'importer' => ['from' => 'testsrc', 'label' => 'TestSrc', 'page' => 'run', 'step' => 'importtest_step']]]);
        plugins(true);
        $fn($admin);
    } finally {
        $pdo->rollBack();
        plugin_manifests($saved);
        plugins(true);
        foreach (['settings', 'categories', 'groups', 'import_notes'] as $k) request_cache($k, null, true);
    }
}

function importtest_run_to_end(): array
{
    for ($n = 0; $n < 20; $n++) {
        $job = import_run(5.0);
        if (($job['status'] ?? '') !== 'running') return $job;
    }
    throw new RuntimeException('the job never ended');
}

function test_import_finds_the_importer_plugin(): void
{
    importtest_with_importer(static function (): void {
        test_same('importtest', importer('testsrc')['plugin'] ?? null, 'by source name');
        test_same('TestSrc', importer('importtest')['label'] ?? null, 'by plugin id');
        test_same(null, importer('phpbb'), 'no importer');
    });
}

function test_import_only_goes_into_an_empty_forum(): void
{
    importtest_with_importer(static function (int $admin): void {
        test_same(true, import_ready()['ok'], 'a new forum is ready');
        user_create('someone_else', 'someone@example.invalid', 'password-123');
        $r = import_ready();
        test_same(false, $r['ok'], 'a forum with members is not');
        test_same(2, $r['members'], 'members counted');
        $failed = false;
        try { import_start('importtest', [['key' => 'users', 'label' => 'Members', 'total' => 3]]); } catch (RuntimeException) { $failed = true; }
        test_same(true, $failed, 'import_start refuses');
    });
}

function test_import_runs_a_job_and_fills_the_forum(): void
{
    importtest_with_importer(static function (int $admin): void {
        $starter = (int)val('SELECT COUNT(*) FROM fb_topics');
        test_assert($starter > 0, 'the starter topic exists');
        import_start('importtest', [['key' => 'users', 'label' => 'Members', 'total' => 3], ['key' => 'topics', 'label' => 'Topics', 'total' => 2]], ['source' => 'x'], ['password' => 'db-secret']);
        test_same(0, (int)val('SELECT COUNT(*) FROM fb_topics'), 'starter content removed');
        test_same(0, (int)val('SELECT COUNT(*) FROM fb_categories'), 'starter categories removed');
        test_same('db-secret', import_job()['secret']['password'] ?? null, 'the secret is kept while running');
        $job = importtest_run_to_end();
        test_same('done', $job['status'], 'status');
        test_same([], $job['secret'], 'the secret is gone');
        test_same(['Members', 'Topics', 'Counters', 'Search index'], array_column($job['phases'], 'label'), 'core phases added');
        $map = $job['data']['map'];
        test_same($admin, (int)$map['u1'], 'same email: matched to the administrator');
        $jane = user_by_id((int)$map['u2']);
        test_same('Jane_Doe', $jane['username'], 'username made valid');
        test_same('Jane 简', $jane['display_name'], 'display name kept');
        test_same(true, password_verify('jane-secret-1', (string)$jane['password']), 'the old password still works');
        test_same(1600000000, (int)$jane['created_at'], 'join date kept');
        $u3 = user_by_id((int)$map['u3']);
        test_same('Jane_Doe_2', $u3['username'], 'taken username gets a number');
        test_same(false, password_verify('not-a-hash', (string)$u3['password']), 'no usable hash: a random password');
        $t = one('SELECT * FROM fb_topics WHERE id=100');
        test_same(500, (int)$t['first_post_id'], 'first post');
        test_same(501, (int)$t['last_post_id'], 'last visible reply');
        test_same(1600000300, (int)$t['last_post_at'], 'last reply time');
        test_same((int)$map['u1'], (int)$t['last_user_id'], 'last replier');
        test_same(1, (int)$t['reply_count'], 'only the visible reply counts');
        test_same(2, (int)$t['like_count'], 'likes of the topic');
        test_same(42, (int)$t['view_count'], 'views kept');
        test_same(1, (int)val('SELECT COUNT(*) FROM fb_topic_tags WHERE topic_id=100'), 'a tag once');
        test_same(2, (int)val('SELECT like_count FROM fb_posts WHERE id=500'), 'post likes');
        test_same(2, (int)$jane['like_count'], 'likes received');
        test_same(1, (int)val('SELECT topic_count FROM fb_users WHERE id=?', [(int)$map['u2']]), 'topics of a member');
        test_same(1, (int)val('SELECT topic_count FROM fb_tags WHERE id=?', [(int)$map['tag']]), 'tag count');
        test_same(1, (int)val('SELECT topic_count FROM fb_categories WHERE id=?', [(int)$map['cat']]), 'category count leaves out the waiting topic');
        test_contains('<strong>post</strong>', (string)val('SELECT body_html FROM fb_posts WHERE id=500'), 'Markdown rendered');
        test_same(2, (int)val('SELECT COUNT(*) FROM fb_review WHERE status=0'), 'waiting topic and reply are in the review queue');
        test_same('topic', (string)val('SELECT kind FROM fb_review WHERE post_id=504'), 'the waiting topic');
        test_same(501, (int)val('SELECT last_post_id FROM fb_topic_reads WHERE user_id=? AND topic_id=100', [(int)$map['u2']]), 'read mark');
        test_assert((int)search_query('quokkaflux')['total'] > 0, 'searchable');
        test_same(true, in_array('2 posts were empty and skipped.', $job['notes'], true), 'notes kept');
        test_same(true, (bool)array_filter($job['notes'], static fn($n) => str_contains((string)$n, 'matched to the existing account')), 'the match is noted');
        $again = import_ready();
        test_same(true, $again['ok'] && $again['again'], 'importing again is allowed after an import');
        import_start('importtest', [['key' => 'users', 'label' => 'Members', 'total' => 3]]);
        test_same(1, (int)val('SELECT COUNT(*) FROM fb_users'), 'starting again removes the members of the last import');
        test_same($admin, (int)val('SELECT id FROM fb_users'), 'the administrator stays');
    });
}

function test_import_stops_on_an_error_and_continues(): void
{
    importtest_with_importer(static function (): void {
        import_start('importtest', [['key' => 'users', 'label' => 'Members', 'total' => 3], ['key' => 'topics', 'label' => 'Topics', 'total' => 2]], ['explode' => true]);
        $job = importtest_run_to_end();
        test_same('failed', $job['status'], 'failed');
        test_same('source went away', $job['error'], 'the reason');
        test_same(1, (int)$job['phase'], 'stopped at the failing phase');
        $j = import_job(); $j['data']['explode'] = false; import_job_save($j);
        import_retry();
        test_same('done', importtest_run_to_end()['status'], 'continued to the end');
        test_same(2, (int)val('SELECT COUNT(*) FROM fb_topics'), 'topics imported once');
    });
}

function test_import_cancel_drops_the_secret(): void
{
    importtest_with_importer(static function (): void {
        import_start('importtest', [['key' => 'users', 'label' => 'Members', 'total' => 3]], [], ['password' => 'p']);
        import_cancel();
        $job = import_job();
        test_same('cancelled', $job['status'], 'cancelled');
        test_same([], $job['secret'], 'secret removed');
        test_same('cancelled', import_run(1.0)['status'], 'a cancelled job does not run');
    });
}

function test_import_username_and_slug(): void
{
    test_same('bob', import_username('bob', 'member1'), 'valid name kept');
    test_same('Ann_Lee', import_username('Ann Lee', 'member1'), 'space');
    test_same('member7', import_username('张三', 'member7'), 'nothing usable: fallback');
    test_same('general-2', import_slug('fb_categories', 'general', 'General'), 'taken slug gets a number');
}

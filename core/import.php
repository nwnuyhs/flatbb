<?php
/**
 * Importing another forum: Admin → Import and `php flatbb import <from>`.
 *
 * An importer is a plugin with the manifest key 'importer'. It reads its source forum and hands rows to the import_*
 * writers below, the only code that fills fb_* tables with imported content. The core keeps the job: the phases with
 * their counts, a cursor the importer owns, and the notes for the report. import_run() calls the importer's step
 * callback in short slices, each batch one transaction together with the saved job, from the browser (a page that
 * polls) or the command line, so closing the page only pauses the import.
 *
 * Imports go into an empty forum only (import_ready()): starting one removes the starter content, and the importer
 * may keep the source ids of topics and posts, so its old links can be redirected.
 *
 *   'importer' => ['from' => 'flarum', 'label' => 'Flarum', 'page' => 'run', 'step' => 'myid_step', 'cli' => 'myid_cli']
 *
 * step(array $job): array  runs one batch of $job['phases'][$job['phase']] (a few hundred rows), adds to its 'done',
 *                          keeps its place in $job['cursor'] and returns import_phase_next($job) when the phase is empty.
 * cli(array $opts, callable $out): int  the command line's start (php flatbb import flarum --host=…): validate, call import_start(), return 0.
 */

// Labels importers use for their steps and phases, and the review queue's reason, are shown through t(); listed here so the
// language packs carry them: t('Connect') t('Check') t('Done') t('Counters') t('Search index') t('Categories and tags')
// t('Members') t('Categories') t('Tags') t('Topics') t('Posts') t('Likes') t('Read marks') t('Was waiting for approval in the old forum')

/** Importer plugins, installed or enabled: plugin id => ['plugin', 'from', 'label', 'description', 'page', 'enabled', 'name', 'version']. */
function importers(): array
{
    $out = [];
    foreach (plugins() as $id => $row) {
        $enabled = (int)$row['enabled'] === 1;
        $m = $enabled ? (plugin_manifest((string)$id) ?? []) : (array)$row['manifest'];
        $imp = $m['importer'] ?? null;
        if (!is_array($imp) || (string)($imp['from'] ?? '') === '') continue;
        $out[(string)$id] = [
            'plugin' => (string)$id, 'from' => strtolower((string)$imp['from']), 'label' => (string)($imp['label'] ?? $imp['from']),
            'page' => (string)($imp['page'] ?? ''), 'step' => (string)($imp['step'] ?? ''), 'cli' => (string)($imp['cli'] ?? ''),
            'enabled' => $enabled, 'name' => (string)($m['name'] ?? $row['name'] ?? $id), 'version' => (string)($row['version'] ?? ''),
            'description' => (string)($m['description'] ?? ''),
        ];
    }
    return $out;
}

/** The importer for a source name ('flarum') or a plugin id. */
function importer(string $key): ?array
{
    $key = strtolower($key);
    foreach (importers() as $id => $imp) if ($id === $key || $imp['from'] === $key) return $imp;
    return null;
}

/** The current or last job ([] when none): plugin, status (running|done|failed|cancelled), phases, phase, cursor, data, secret, notes, ... */
function import_job(): array
{
    return json_decode_array(setting('import_job', ''));
}

function import_job_save(array $job): void
{
    save_settings(['import_job' => $job === [] ? '' : json_encode_value($job)]);
}

/**
 * Whether this forum may receive an import: ['ok' => bool, 'reason' => text, 'members', 'topics', 'categories', 'tags'].
 * A new forum has one member (you) and the starter content, which the import replaces. After an import, starting
 * again is allowed too: the members and content of the last import are removed first.
 */
function import_ready(): array
{
    $job = import_job();
    $r = [
        'ok' => true, 'reason' => '', 'again' => $job !== [] && ($job['status'] ?? '') !== 'running',
        'members' => (int)val('SELECT COUNT(*) FROM fb_users'), 'topics' => (int)val('SELECT COUNT(*) FROM fb_topics'),
        'categories' => (int)val('SELECT COUNT(*) FROM fb_categories'), 'tags' => (int)val('SELECT COUNT(*) FROM fb_tags'),
    ];
    if (($job['status'] ?? '') === 'running') return ['ok' => false, 'reason' => t('An import is running. Finish or cancel it first.')] + $r;
    if (!$r['again'] && $r['members'] > 1) return ['ok' => false, 'reason' => t('This forum already has members. Imports go into a new, empty forum: install a fresh copy of FlatBB and import there.')] + $r;
    return $r;
}

/**
 * Start a job for an importer plugin. $phases: [['key' => 'users', 'label' => 'Members', 'total' => 3412], ...] in the
 * order they run (labels are English source text, shown through t()). $data is the importer's own state; $secret holds
 * what must not outlive the job (a database password): it is removed when the job ends.
 */
function import_start(string $plugin, array $phases, array $data = [], array $secret = []): void
{
    $ready = import_ready();
    if (!$ready['ok']) throw new RuntimeException($ready['reason']);
    $last = import_job();
    $admin = (int)($last['admin_id'] ?? 0) ?: (uid() ?: (int)val('SELECT MIN(id) FROM fb_users'));
    import_reset($ready['again'] ? $admin : 0);
    $list = [];
    foreach ($phases as $p) $list[] = ['key' => (string)$p['key'], 'label' => (string)$p['label'], 'total' => max(0, (int)($p['total'] ?? 0)), 'done' => 0];
    $list[] = ['key' => '_counts', 'label' => 'Counters', 'total' => 0, 'done' => 0];
    $list[] = ['key' => '_search', 'label' => 'Search index', 'total' => 0, 'done' => 0];
    import_job_save([
        'plugin' => $plugin, 'status' => 'running', 'phases' => $list, 'phase' => 0, 'cursor' => [],
        'data' => $data, 'secret' => $secret, 'notes' => [], 'error' => '', 'admin_id' => $admin,
        'started_at' => now(), 'finished_at' => 0, 'seconds' => 0.0,
    ]);
    admin_log('import.start', $plugin);
}

/**
 * Empty the content tables for an import: topics, posts, categories, tags, likes and everything hanging off them.
 * $keep_user > 0 (starting again after an import) also removes every member except that one.
 */
function import_reset(int $keep_user = 0): void
{
    foreach (['fb_topics', 'fb_posts', 'fb_topic_tags', 'fb_tags', 'fb_categories', 'fb_likes', 'fb_review', 'fb_bookmarks', 'fb_topic_reads', 'fb_notifications', 'fb_search', 'fb_points_log', 'fb_link_previews'] as $table) q("DELETE FROM `{$table}`");
    if (search_backend() === 'fts5') q('DELETE FROM fb_search_fts');
    if ($keep_user > 0) q('DELETE FROM fb_users WHERE id<>?', [$keep_user]);
    q('UPDATE fb_users SET topic_count=0, post_count=0, like_count=0, points=0, unread_notifications=0, last_post_at=0');
    fire('import.reset', ['keep_user' => $keep_user]); // plugins clear rows that point at topics, posts or members
    foreach (['categories', 'groups', 'settings'] as $key) request_cache($key, null, true);
    save_settings(['stats_cache' => '']);
}

/** Move a job to its next phase (importers return this from their step when the phase has no rows left). */
function import_phase_next(array $job): array
{
    $job['phase'] = (int)$job['phase'] + 1;
    $job['cursor'] = [];
    return $job;
}

/** A line for the report ("12 posts were empty and skipped"). Collected per batch and saved with the job. */
function import_note(string $text): void
{
    $notes = request_cache('import_notes') ?? [];
    $notes[] = $text;
    request_cache('import_notes', null, true);
    request_cache('import_notes', static fn(): array => $notes);
}

/**
 * Run the job for about $seconds: batch after batch, each in one transaction with the saved job. Returns the job.
 * Two callers at once (two tabs, the page and the command line) are kept apart by a lock: the second one just reads.
 */
function import_run(float $seconds = 8.0): array
{
    $lock = @fopen(CACHE_DIR . '/import.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return import_job() + ['busy' => true];
    try {
        $job = import_job();
        if (($job['status'] ?? '') !== 'running') return $job;
        $imp = importer((string)$job['plugin']);
        $step = $imp !== null && $imp['enabled'] ? $imp['step'] : '';
        $start = microtime(true);
        $deadline = $start + max(0.5, $seconds);
        @set_time_limit((int)ceil($seconds) + 60);
        try {
            while (microtime(true) < $deadline && ($job['status'] ?? '') === 'running') {
                $i = (int)$job['phase'];
                if (!isset($job['phases'][$i])) { $job = import_done($job); break; }
                $key = (string)$job['phases'][$i]['key'];
                if ($key[0] !== '_' && !is_callable($step)) throw new RuntimeException('The importer plugin ' . $job['plugin'] . ' is not enabled.');
                $job = tx(static function () use ($job, $key, $step): array {
                    request_cache('import_notes', null, true);
                    $job = match ($key) { '_counts' => import_phase_counts($job), '_search' => import_phase_search($job), default => $step($job) };
                    foreach (request_cache('import_notes') ?? [] as $n) if (count($job['notes']) < 500) $job['notes'][] = $n;
                    import_job_save($job);
                    return $job;
                });
            }
        } catch (Throwable $e) {
            $job = import_job(); // the failed batch was rolled back: the saved job is where it stopped
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
            @file_put_contents(DATA_DIR . '/error.log', date('c') . ' import: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n\n", FILE_APPEND | LOCK_EX);
        }
        $job['seconds'] = round((float)($job['seconds'] ?? 0) + microtime(true) - $start, 1);
        import_job_save($job);
        return $job;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function import_done(array $job): array
{
    $job['status'] = 'done';
    $job['finished_at'] = now();
    $job['secret'] = [];
    foreach (['categories', 'groups', 'settings'] as $key) request_cache($key, null, true);
    save_settings(['stats_cache' => '']);
    admin_log('import.done', (string)$job['plugin']);
    fire('import.done', ['job' => $job]);
    return $job;
}

/** Stop a job: what was imported stays, the secret goes. Starting again removes it and begins anew. */
function import_cancel(): void
{
    $job = import_job();
    if ($job === [] || ($job['status'] ?? '') === 'done') return;
    $job['status'] = 'cancelled';
    $job['secret'] = [];
    import_job_save($job);
    admin_log('import.cancel', (string)$job['plugin']);
}

/** Pick up a failed job where it stopped. */
function import_retry(): void
{
    $job = import_job();
    if (($job['status'] ?? '') !== 'failed') return;
    $job['status'] = 'running';
    $job['error'] = '';
    import_job_save($job);
}

/** Counters of everything that was imported, in a few set-wise statements. */
function import_phase_counts(array $job): array
{
    q('UPDATE fb_posts SET like_count=(SELECT COUNT(*) FROM fb_likes WHERE fb_likes.post_id=fb_posts.id)');
    q('UPDATE fb_topics SET last_post_id=COALESCE((SELECT p.id FROM fb_posts p WHERE p.topic_id=fb_topics.id AND p.is_deleted=0 ORDER BY p.floor DESC LIMIT 1), first_post_id)');
    q('UPDATE fb_topics SET reply_count=(SELECT COUNT(*) FROM fb_posts p WHERE p.topic_id=fb_topics.id AND p.is_deleted=0 AND p.floor>0),'
        . ' like_count=(SELECT COUNT(*) FROM fb_likes l WHERE l.topic_id=fb_topics.id),'
        . ' last_post_at=COALESCE((SELECT p.created_at FROM fb_posts p WHERE p.id=fb_topics.last_post_id), created_at),'
        . ' last_user_id=COALESCE((SELECT p.user_id FROM fb_posts p WHERE p.id=fb_topics.last_post_id), user_id)');
    q('UPDATE fb_topics SET updated_at=last_post_at');
    q('UPDATE fb_users SET topic_count=(SELECT COUNT(*) FROM fb_topics t WHERE t.user_id=fb_users.id AND t.is_deleted=0),'
        . ' post_count=(SELECT COUNT(*) FROM fb_posts p WHERE p.user_id=fb_users.id AND p.is_deleted=0 AND p.floor>0),'
        . ' like_count=(SELECT COUNT(*) FROM fb_likes l JOIN fb_posts p ON p.id=l.post_id WHERE p.user_id=fb_users.id),'
        . ' last_post_at=COALESCE((SELECT MAX(p.created_at) FROM fb_posts p WHERE p.user_id=fb_users.id), 0)');
    q('UPDATE fb_tags SET topic_count=(SELECT COUNT(*) FROM fb_topic_tags tt JOIN fb_topics t ON t.id=tt.topic_id WHERE tt.tag_id=fb_tags.id AND t.is_deleted=0)');
    request_cache('categories', null, true);
    foreach (col('SELECT id FROM fb_categories') as $cid) category_refresh_stats((int)$cid);
    cron_hot_scores();
    return import_phase_next($job);
}

/** The search index, 500 posts a batch. */
function import_phase_search(array $job): array
{
    $i = (int)$job['phase'];
    $last = (int)($job['cursor']['id'] ?? 0);
    if ($last === 0) {
        $job['phases'][$i]['total'] = (int)val('SELECT COUNT(*) FROM fb_posts p JOIN fb_topics t ON t.id=p.topic_id WHERE p.is_deleted=0 AND t.is_deleted=0');
    }
    $rows = all('SELECT p.id,p.topic_id,p.body,t.title FROM fb_posts p JOIN fb_topics t ON t.id=p.topic_id WHERE p.id>? AND p.is_deleted=0 AND t.is_deleted=0 ORDER BY p.id LIMIT 500', [$last]);
    if ($rows === []) return import_phase_next($job);
    foreach ($rows as $r) search_index_post((int)$r['id'], (int)$r['topic_id'], (string)$r['title'], (string)$r['body']);
    $job['phases'][$i]['done'] += count($rows);
    $job['cursor']['id'] = (int)end($rows)['id'];
    return $job;
}

/* ---------------------------------------------------------------- writers */

/** A username that follows the site's rule and is not taken: "Jane Doe" → "Jane_Doe", "_x" → "x", taken → "name_2". */
function import_username(string $name, string $fallback): string
{
    [$min, $max] = username_rule();
    $s = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?? '';
    $s = ltrim($s, '_.-');
    if (strlen($s) < $min) $s = $fallback;
    $s = substr($s, 0, $max);
    $base = $s;
    for ($n = 2; val('SELECT 1 FROM fb_users WHERE username_lower=?', [mb_strtolower($s)]); $n++) $s = substr($base, 0, $max - strlen('_' . $n)) . '_' . $n;
    return $s;
}

/** A slug that is not taken in $table ('fb_categories', 'fb_tags'). */
function import_slug(string $table, string $slug, string $fallback): string
{
    $s = slugify($slug) ?: slugify($fallback) ?: $fallback;
    $base = $s;
    for ($n = 2; val("SELECT 1 FROM `{$table}` WHERE slug=?", [$s]); $n++) $s = $base . '-' . $n;
    return $s;
}

/**
 * Add a member. $u: username, email, password (a bcrypt/argon hash from the source, kept so members sign in as before),
 * display_name, group_id, avatar_file (a picture on this server, stored as the avatar), bio, created_at, last_seen,
 * status (0 banned), email_verified, old_id (for fallbacks).
 * A member whose email is already here (you) is matched to that account instead. Returns the member's id.
 */
function import_user(array $u): int
{
    $email = mb_strtolower(trim((string)($u['email'] ?? '')));
    if ($email !== '' && ($id = (int)(val('SELECT id FROM fb_users WHERE email=?', [$email]) ?? 0)) > 0) {
        import_note(sprintf('%s was matched to the existing account #%d (same email).', (string)$u['username'], $id));
        return $id;
    }
    $name = import_username((string)$u['username'], 'member' . (int)($u['old_id'] ?? 0));
    if ($name !== (string)$u['username']) import_note(sprintf('Username %s became %s.', (string)$u['username'], $name));
    $hash = (string)($u['password'] ?? '');
    if (password_get_info($hash)['algo'] === null) $hash = password_hash(random_token(16), PASSWORD_DEFAULT); // no usable hash: they set a new password with "Forgot password"
    $display = trim((string)($u['display_name'] ?? ''));
    $id = db_insert('fb_users', [
        'username' => $name, 'username_lower' => mb_strtolower($name), 'display_name' => $display === $name ? '' : mb_substr($display, 0, 30),
        'email' => $email, 'password' => $hash, 'group_id' => (int)($u['group_id'] ?? 0) ?: (int)val("SELECT id FROM fb_groups WHERE slug='member'"),
        'avatar' => '', 'bio' => (string)($u['bio'] ?? ''), 'status' => (int)($u['status'] ?? 1),
        'created_at' => (int)($u['created_at'] ?? 0) ?: now(), 'created_ip' => '', 'last_seen' => (int)($u['last_seen'] ?? 0),
        'email_verified' => (int)($u['email_verified'] ?? 0), 'prefs' => '{}',
    ]);
    $file = (string)($u['avatar_file'] ?? '');
    if ($file !== '' && is_file($file)) {
        try { db_update('fb_users', ['avatar' => avatar_store($id, $file)], 'id=?', [$id]); } catch (Throwable) { import_note(sprintf('The avatar of %s could not be read.', $name)); }
    }
    return $id;
}

/** The id of a group by slug, created with the members' permissions when missing. */
function import_group(string $name, string $color = ''): int
{
    $slug = slugify($name) ?: 'group';
    $id = (int)(val('SELECT id FROM fb_groups WHERE slug=?', [$slug]) ?? 0);
    if ($id > 0) return $id;
    $perms = (string)(val("SELECT permissions FROM fb_groups WHERE slug='member'") ?? json_encode_value(['post', 'reply', 'upload', 'edit_own', 'delete_own']));
    $id = db_insert('fb_groups', ['name' => $name, 'slug' => $slug, 'color' => $color, 'is_admin' => 0, 'is_mod' => 0, 'permissions' => $perms, 'sort' => 10]);
    request_cache('groups', null, true);
    return $id;
}

/** Add a category: name, slug, description, color, parent_id, sort, is_hidden, view_groups, post_groups. Returns its id. */
function import_category(array $c): int
{
    return db_insert('fb_categories', [
        'parent_id' => (int)($c['parent_id'] ?? 0), 'name' => cut((string)$c['name'], 190, ''), 'slug' => import_slug('fb_categories', (string)($c['slug'] ?? ''), (string)$c['name']),
        'description' => (string)($c['description'] ?? ''), 'color' => (string)($c['color'] ?? ''), 'icon' => (string)($c['icon'] ?? ''),
        'sort' => (int)($c['sort'] ?? 0), 'is_hidden' => (int)($c['is_hidden'] ?? 0), 'view_groups' => (string)($c['view_groups'] ?? ''), 'post_groups' => (string)($c['post_groups'] ?? ''),
    ]);
}

/** Add a tag: name, slug. Returns its id (an existing tag with the same name is reused). */
function import_tag(array $tag): int
{
    $name = cut(trim((string)$tag['name']), 60, '');
    $id = (int)(val('SELECT id FROM fb_tags WHERE name=?', [$name]) ?? 0);
    return $id > 0 ? $id : db_insert('fb_tags', ['name' => $name, 'slug' => import_slug('fb_tags', (string)($tag['slug'] ?? ''), $name)]);
}

/**
 * Add a topic without its posts: id (optional, keeps the source id), category_id, user_id, title, created_at, is_pinned,
 * is_locked, is_deleted (0, 1 deleted, REVIEW_PENDING waiting for approval), view_count, tags (tag ids).
 * Its first post, last reply and counts come from import_post() and the Counters phase.
 */
function import_topic(array $t): int
{
    $title = cut(trim((string)$t['title']), 250, '') ?: 'Untitled';
    $at = (int)($t['created_at'] ?? 0) ?: now();
    $row = [
        'category_id' => (int)$t['category_id'], 'user_id' => (int)$t['user_id'], 'title' => $title, 'slug' => slugify($title),
        'is_pinned' => (int)($t['is_pinned'] ?? 0), 'pinned_at' => !empty($t['is_pinned']) ? $at : 0, 'is_locked' => (int)($t['is_locked'] ?? 0),
        'is_deleted' => (int)($t['is_deleted'] ?? 0), 'view_count' => (int)($t['view_count'] ?? 0),
        'created_at' => $at, 'updated_at' => $at, 'last_post_at' => $at, 'last_user_id' => (int)$t['user_id'], 'meta' => '{}',
    ];
    if ((int)($t['id'] ?? 0) > 0) $row = ['id' => (int)$t['id']] + $row;
    $tid = db_insert('fb_topics', $row);
    $tid = (int)($t['id'] ?? 0) ?: $tid;
    foreach (array_unique(array_map('intval', (array)($t['tags'] ?? []))) as $tag) if ($tag > 0) db_insert_ignore('fb_topic_tags', ['topic_id' => $tid, 'tag_id' => $tag]);
    return $tid;
}

/**
 * Add a post (Markdown body): id (optional), topic_id, user_id, floor (0 = the topic's first post), body, created_at,
 * reply_to_id, edited_at, edited_by, is_deleted (0, 1, REVIEW_PENDING), created_ip. A waiting post also gets its row in
 * the review queue. Returns the post id.
 */
function import_post(array $p): int
{
    $tid = (int)$p['topic_id'];
    $floor = (int)($p['floor'] ?? 1);
    $deleted = (int)($p['is_deleted'] ?? 0);
    $body = (string)$p['body'];
    $row = [
        'topic_id' => $tid, 'user_id' => (int)$p['user_id'], 'floor' => $floor, 'reply_to_id' => (int)($p['reply_to_id'] ?? 0),
        'body' => $body, 'body_html' => md($body), 'is_deleted' => $deleted,
        'edit_count' => (int)($p['edited_at'] ?? 0) > 0 ? 1 : 0, 'edited_at' => (int)($p['edited_at'] ?? 0), 'edited_by' => (int)($p['edited_by'] ?? 0),
        'created_at' => (int)($p['created_at'] ?? 0) ?: now(), 'created_ip' => (string)($p['created_ip'] ?? ''), 'meta' => '{}',
    ];
    if ((int)($p['id'] ?? 0) > 0) $row = ['id' => (int)$p['id']] + $row;
    $pid = db_insert('fb_posts', $row);
    $pid = (int)($p['id'] ?? 0) ?: $pid;
    if ($floor === 0) db_update('fb_topics', ['first_post_id' => $pid, 'last_post_id' => $pid], 'id=?', [$tid]);
    if ($deleted === REVIEW_PENDING) {
        db_insert('fb_review', ['kind' => $floor === 0 ? 'topic' : 'reply', 'topic_id' => $tid, 'post_id' => $pid, 'user_id' => (int)$p['user_id'],
            'reason' => 'Was waiting for approval in the old forum', 'status' => 0, 'note' => '', 'created_at' => $row['created_at'], 'decided_at' => 0, 'decided_by' => 0]);
    }
    return $pid;
}

/** A like of a post. */
function import_like(int $user_id, int $post_id, int $topic_id, int $at = 0): void
{
    db_insert_ignore('fb_likes', ['user_id' => $user_id, 'post_id' => $post_id, 'topic_id' => $topic_id, 'created_at' => $at ?: now()]);
}

/** How far a member has read a topic (keeps imported topics from all showing as unread). */
function import_read(int $user_id, int $topic_id, int $last_post_id, int $at = 0): void
{
    db_upsert('fb_topic_reads', ['user_id' => $user_id, 'topic_id' => $topic_id, 'last_post_id' => $last_post_id, 'read_at' => $at ?: now()], ['user_id', 'topic_id']);
}

/**
 * Copy a file of the source forum into uploads/import/<dir>/ under a new name. Pictures only, plus common documents
 * and archives; never anything a server could run. Returns its public URL ('' when the file is missing or refused).
 */
function import_copy_file(string $file, string $dir): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!is_file($file) || !in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'zip', 'gz', '7z', 'rar', 'mp3', 'mp4', 'webm'], true)) return '';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && @getimagesize($file) === false) return '';
    $rel = 'import/' . trim(preg_replace('/[^a-z0-9_-]+/', '', strtolower($dir)) ?: 'files', '/') . '/' . random_token(8) . '.' . $ext;
    $to = UPLOAD_DIR . '/' . $rel;
    if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
    return @copy($file, $to) ? upload_url($rel) : '';
}

/* ---------------------------------------------------------------- command line */

/**
 * php flatbb import [from] [--options]: without a source, list the importers and the job. With one, continue its
 * job, or start a new one through the importer's cli callback (its --options), then run it to the end. Returns the exit code.
 */
function import_cli(string $from, array $opts, callable $out): int
{
    $job = import_job();
    if ($from === '') {
        foreach (importers() as $imp) $out(str_pad($imp['from'], 12) . str_pad($imp['name'], 24) . ($imp['enabled'] ? 'enabled' : 'disabled'));
        if (importers() === []) $out('No importer plugins. Install one from the marketplace (Admin → Import).');
        if ($job !== []) $out('Last job: ' . $job['plugin'] . ', ' . $job['status']);
        return 0;
    }
    $imp = importer($from);
    if ($imp === null || !$imp['enabled']) { $out('No enabled importer for ' . $from . '. Enable it under Admin → Plugins.'); return 1; }
    if (isset($opts['cancel'])) { import_cancel(); $out('Cancelled.'); return 0; }
    $mine = ($job['plugin'] ?? '') === $imp['plugin'];
    if ($mine && ($job['status'] ?? '') === 'failed') { import_retry(); $job = import_job(); }
    if (!$mine || ($job['status'] ?? '') !== 'running') {
        if (!is_callable($imp['cli'])) { $out('Start this import on its page under Admin → Import, then run this command to continue it.'); return 1; }
        try {
            $code = (int)($imp['cli'])($opts, $out);
        } catch (Throwable $e) {
            $out($e->getMessage());
            return 1;
        }
        if ($code !== 0) return $code;
    }
    $shown = [];
    do {
        $job = import_run(10.0);
        if (!empty($job['busy'])) { $out('Another run is busy with this import (a browser tab?). Waiting…'); sleep(5); continue; }
        foreach ((array)$job['phases'] as $n => $p) { // every phase that moved since the last slice, with its numbers
            $past = $n < (int)$job['phase'] || $job['status'] === 'done';
            if (!$past && (int)$p['done'] === 0) continue;
            $line = str_pad((string)$p['label'], 24) . ((int)$p['total'] > 0 ? number_format((int)$p['done']) . ' / ' . number_format((int)$p['total']) : ($past ? 'done' : number_format((int)$p['done'])));
            if (($shown[$n] ?? '') !== $line) { $out('  ' . $line); $shown[$n] = $line; }
        }
    } while (($job['status'] ?? '') === 'running');
    if ($job['status'] === 'failed') { $out('Stopped: ' . $job['error'] . ' (run the command again to continue)'); return 1; }
    $out(ucfirst((string)$job['status']) . ' in ' . $job['seconds'] . ' s. ' . count((array)$job['notes']) . ' notes; the report is under Admin → Import.');
    return 0;
}

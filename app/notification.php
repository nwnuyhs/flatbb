<?php
/**
 * Notifications: reply, mention, like, system. Stored in fb_notifications; unread count is
 * cached on fb_users.unread_notifications so the header badge costs no extra query.
 */

/**
 * Whether the member wants this kind of notification (Settings → Preferences): the preference notify_<kind> set to 0 turns
 * a kind off, so a plugin's own kind gets a switch by saving that key (user.prefs_save). Kinds nobody switched off are sent.
 */
function notify_wanted(int $to, string $kind): bool
{
    $u = user_by_id($to); // request-cached: a page full of mentions reads each member once
    return $u === null || notify_pref_on((string)$u['prefs'], $kind);
}

function notify_pref_on(string $prefs, string $kind): bool
{
    return (int)(json_decode_array($prefs)['notify_' . $kind] ?? 1) === 1;
}

/** Create a notification. Returns false when suppressed (self, duplicate, preference, hook veto). */
function notify(int $to, int $from, string $kind, string $content = '', int $topic_id = 0, int $post_id = 0): bool
{
    if ($to <= 0 || $to === $from || !notify_wanted($to, $kind)) return false;
    $data = hook('notification.before_create', ['user_id' => $to, 'from_user_id' => $from, 'kind' => $kind, 'content' => cut($content, 500, ''), 'topic_id' => $topic_id, 'post_id' => $post_id], []);
    if (!is_array($data)) return false;
    if ($post_id > 0 && $kind !== 'like' && val('SELECT 1 FROM fb_notifications WHERE user_id=? AND post_id=? AND kind=?', [$to, $post_id, $kind])) return false;
    $id = db_insert('fb_notifications', $data + ['is_read' => 0, 'created_at' => now()]);
    db_increment('fb_users', 'unread_notifications', 1, 'id=?', [$to]);
    fire('notification.after_create', ['id' => $id] + $data);
    return true;
}

/**
 * Notify many members of the same thing (a new topic for everyone who follows its author) in a few queries: recipients are
 * read 100 at a time (active accounts that did not switch the kind off), written with one INSERT per 100 and their unread
 * counts raised with one UPDATE each. The filter notification.before_create runs for every recipient (no DB there);
 * notification.after_create_many fires once. Returns how many were notified.
 */
function notify_many(array $to, int $from, string $kind, string $content = '', int $topic_id = 0, int $post_id = 0): int
{
    $ids = array_values(array_filter(array_unique(array_map('intval', $to)), static fn(int $id): bool => $id > 0 && $id !== $from));
    $content = cut($content, 500, '');
    $done = [];
    foreach (array_chunk($ids, 100) as $chunk) {
        $rows = [];
        foreach (all('SELECT id, prefs FROM fb_users WHERE status=1 AND id IN (' . sql_marks(count($chunk)) . ')', $chunk) as $u) {
            if (!notify_pref_on((string)$u['prefs'], $kind)) continue;
            $data = hook('notification.before_create', ['user_id' => (int)$u['id'], 'from_user_id' => $from, 'kind' => $kind, 'content' => $content, 'topic_id' => $topic_id, 'post_id' => $post_id], ['many' => true]);
            if (is_array($data)) $rows[] = $data + ['is_read' => 0, 'created_at' => now()];
        }
        if ($rows === []) continue;
        $cols = array_keys($rows[0]);
        $params = [];
        foreach ($rows as $r) foreach ($cols as $c) $params[] = $r[$c] ?? '';
        q('INSERT INTO fb_notifications (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($rows), '(' . sql_marks(count($cols)) . ')')), $params);
        $uids = array_map(static fn(array $r): int => (int)$r['user_id'], $rows);
        q('UPDATE fb_users SET unread_notifications=unread_notifications+1 WHERE id IN (' . sql_marks(count($uids)) . ')', $uids);
        array_push($done, ...$uids);
    }
    if ($done !== []) fire('notification.after_create_many', ['user_ids' => $done, 'from_user_id' => $from, 'kind' => $kind, 'topic_id' => $topic_id, 'post_id' => $post_id]);
    return count($done);
}

function notify_reply(array $topic, int $post_id, int $from, int $reply_to_id): void
{
    $excerpt = md_excerpt((string)val('SELECT body FROM fb_posts WHERE id=?', [$post_id]), 120);
    if ($reply_to_id > 0) {
        $parent_uid = (int)val('SELECT user_id FROM fb_posts WHERE id=?', [$reply_to_id]);
        notify($parent_uid, $from, 'reply', $excerpt, (int)$topic['id'], $post_id);
        if ($parent_uid === (int)$topic['user_id']) return;
    }
    notify((int)$topic['user_id'], $from, 'reply', $excerpt, (int)$topic['id'], $post_id);
}

function notify_mentions(int $topic_id, int $post_id, string $body, int $from): void
{
    $names = md_mentions($body);
    if ($names === []) return;
    $lower = array_map('mb_strtolower', array_slice($names, 0, 10));
    $rows = all('SELECT id FROM fb_users WHERE username_lower IN (' . sql_marks(count($lower)) . ')', $lower);
    $excerpt = md_excerpt($body, 120);
    foreach ($rows as $r) notify((int)$r['id'], $from, 'mention', $excerpt, $topic_id, $post_id);
}

function notify_like(array $post, int $from): void
{
    if (val('SELECT 1 FROM fb_notifications WHERE user_id=? AND from_user_id=? AND post_id=? AND kind=?', [(int)$post['user_id'], $from, (int)$post['id'], 'like'])) return;
    notify((int)$post['user_id'], $from, 'like', md_excerpt((string)$post['body'], 80), (int)$post['topic_id'], (int)$post['id']);
}

function notifications_unread(): int
{
    return (int)(me()['unread_notifications'] ?? 0);
}

/** GET /notifications */
function notification_index(): never
{
    $me = need_login();
    $pg = paginate_calc((int)val('SELECT COUNT(*) FROM fb_notifications WHERE user_id=?', [(int)$me['id']]), get_int('page', 1, 1, 10000), 30);
    $rows = all('SELECT * FROM fb_notifications WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [(int)$me['id']]);
    $users = users_by_ids(array_column($rows, 'from_user_id'));
    $topics = rows_by_ids('fb_topics', array_column($rows, 'topic_id'), 'id,title,slug');
    foreach ($rows as &$n) {
        $n['from'] = $users[(int)$n['from_user_id']] ?? null;
        $n['topic'] = $topics[(int)$n['topic_id']] ?? null;
        $n['url'] = (int)$n['post_id'] > 0 ? url('/post/' . $n['post_id']) : ($n['topic'] ? topic_url($n['topic']) : '');
        if ($n['kind'] === 'review_waiting') $n['url'] = url('/review');
        if ($n['kind'] === 'review_rejected') $n['url'] = ''; // the post is gone
    }
    unset($n);
    $rows = hook('notifications.rows', $rows, []);
    if (notifications_unread() > 0 || (int)$me['unread_notifications'] > 0) {
        db_update('fb_notifications', ['is_read' => 1], 'user_id=? AND is_read=0', [(int)$me['id']]);
        db_update('fb_users', ['unread_notifications' => 0], 'id=?', [(int)$me['id']]);
    }
    page(t('Notifications'), view('notifications', ['rows' => $rows, 'pagination' => pagination($pg, static fn(int $n): string => url('/notifications', $n > 1 ? ['page' => $n] : []))]), ['class' => 'page-notifications']);
}

/** POST /notifications/read — mark all read (AJAX) */
function notification_read(): never
{
    $me = need_login();
    require_post();
    db_update('fb_notifications', ['is_read' => 1], 'user_id=? AND is_read=0', [(int)$me['id']]);
    db_update('fb_users', ['unread_notifications' => 0], 'id=?', [(int)$me['id']]);
    json_ok();
}

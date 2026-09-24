<?php
/**
 * Review queue: a topic or reply can wait for a moderator before anyone else sees it. The content is stored at once with
 * is_deleted = REVIEW_PENDING (2), so every list, count, search and plugin query that asks for is_deleted = 0 leaves it out
 * without knowing about the queue; its author and the moderators still see it, marked "Awaiting approval". What a post sets
 * off when it goes public (counts, search, notifications, topic.after_save / post.after_save, points, link previews) waits
 * for the approval: topic_go_public() and post_go_public() in app/topic.php.
 *
 * Why something waits: review_hold_reason() — a category whose new topics need approval (fb_categories.review_topics), the
 * first posts of a new member (setting review_first_posts), or a plugin through the filter review.hold (Antispam). Admins
 * and moderators never wait. Moderators work the queue at /review; fb_review keeps one row per held item and its outcome.
 */

const REVIEW_PENDING = 2; // fb_topics / fb_posts is_deleted: 0 shown, 1 deleted, 2 waiting for review

/**
 * Why a new topic ('topic') or reply ('reply') by $user_id waits for review, '' when it goes out at once. The reason is an
 * English source text, shown through t(). Plugins add their own with the filter review.hold (value: the reason so far; ctx:
 * kind, user, category, text); administrators and moderators are never held.
 */
function review_hold_reason(string $kind, int $user_id, int $category_id, string $text): string
{
    $user = user_by_id($user_id);
    if ($user === null) return '';
    $group = group_by_id((int)$user['group_id']);
    if ($group !== null && ((int)$group['is_admin'] === 1 || (int)$group['is_mod'] === 1)) return '';
    $cat = category_by_id($category_id);
    $why = ''; // English source text, shown through t() in the queue: t('New topics in this category need approval') t('First posts of a new member')
    if ($kind === 'topic' && $cat !== null && (int)($cat['review_topics'] ?? 0) === 1) $why = 'New topics in this category need approval';
    $first = max(0, (int)setting('review_first_posts', '0'));
    if ($why === '' && $first > 0 && !review_trusted($user) && (int)$user['topic_count'] + (int)$user['post_count'] < $first) $why = 'First posts of a new member';
    $why = hook('review.hold', $why, ['kind' => $kind, 'user' => $user, 'category' => $cat, 'text' => $text]);
    return is_string($why) ? $why : '';
}

/** A member a moderator approved "and trusted": their posts no longer wait for the new-member rule. */
function review_trusted(array $user): bool
{
    return (int)(json_decode_array((string)($user['prefs'] ?? ''))['review_trusted'] ?? 0) === 1;
}

/** Put a held topic or reply in the queue and tell the moderators (one unread notice each, however many wait). */
function review_hold(string $kind, int $topic_id, int $post_id, int $user_id, string $reason): void
{
    $id = db_insert('fb_review', ['kind' => $kind, 'topic_id' => $topic_id, 'post_id' => $post_id, 'user_id' => $user_id, 'reason' => cut($reason, 190, ''), 'status' => 0, 'note' => '', 'created_at' => now(), 'decided_at' => 0, 'decided_by' => 0]);
    request_cache('review_count', null, true);
    fire('review.held', ['id' => $id, 'kind' => $kind, 'topic_id' => $topic_id, 'post_id' => $post_id, 'user_id' => $user_id, 'reason' => $reason]);
    if (setting('review_notify', '1') !== '1') return;
    $staff = review_staff_ids();
    if ($staff === []) return;
    $told = array_map('intval', col('SELECT user_id FROM fb_notifications WHERE kind=? AND is_read=0 AND user_id IN (' . sql_marks(count($staff)) . ')', array_merge(['review_waiting'], $staff)));
    $to = array_values(array_diff($staff, $told));
    if ($to !== []) notify_many($to, $user_id, 'review_waiting');
}

/** Active administrators and moderators. */
function review_staff_ids(): array
{
    return array_map('intval', col('SELECT u.id FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE u.status=1 AND (g.is_admin=1 OR g.is_mod=1)'));
}

/** How many items wait (one query a request; the account menu shows it to moderators). Items whose post was deleted meanwhile do not count. */
function review_count(): int
{
    return (int)(request_cache('review_count', static fn(): int => (int)val('SELECT COUNT(*) FROM fb_review r JOIN fb_posts p ON p.id=r.post_id AND p.is_deleted=? WHERE r.status=0', [REVIEW_PENDING])) ?? 0);
}

/* ---------------------------------------------------------------- decisions */

/** Approve a waiting item: the content goes public and sets off what a new post sets off; its author is told. */
function review_approve(array $r, int $by): bool
{
    $post = post_by_id((int)$r['post_id']);
    if ((int)$r['status'] !== 0 || $post === null || (int)$post['is_deleted'] !== REVIEW_PENDING) return false;
    tx(static function () use ($r, $post, $by): void {
        db_update('fb_review', ['status' => 1, 'decided_at' => now(), 'decided_by' => $by], 'id=?', [(int)$r['id']]);
        db_update('fb_posts', ['is_deleted' => 0], 'id=?', [(int)$post['id']]);
        if ($r['kind'] === 'topic') {
            db_update('fb_topics', ['is_deleted' => 0, 'last_post_at' => now(), 'updated_at' => now()], 'id=?', [(int)$r['topic_id']]); // it arrives at the top of Latest
            topic_go_public((int)$r['topic_id']);
        } else {
            post_go_public((int)$post['id']);
        }
        notify((int)$r['user_id'], $by, 'review_approved', md_excerpt((string)$post['body'], 120), (int)$r['topic_id'], (int)$post['id']);
    });
    request_cache('review_count', null, true);
    fire('review.decided', ['id' => (int)$r['id'], 'status' => 1, 'by' => $by]);
    return true;
}

/** Reject a waiting item: the content is deleted (moderators can still see it) and its author gets the note. */
function review_reject(array $r, int $by, string $note): bool
{
    $post = post_by_id((int)$r['post_id']);
    if ((int)$r['status'] !== 0 || $post === null || (int)$post['is_deleted'] !== REVIEW_PENDING) return false;
    tx(static function () use ($r, $post, $by, $note): void {
        db_update('fb_review', ['status' => 2, 'note' => cut($note, 190, ''), 'decided_at' => now(), 'decided_by' => $by], 'id=?', [(int)$r['id']]);
        db_update('fb_posts', ['is_deleted' => 1], 'id=?', [(int)$post['id']]);
        if ($r['kind'] === 'topic') db_update('fb_topics', ['is_deleted' => 1], 'id=?', [(int)$r['topic_id']]);
        notify((int)$r['user_id'], $by, 'review_rejected', $note, (int)$r['topic_id'], 0);
    });
    request_cache('review_count', null, true);
    fire('review.decided', ['id' => (int)$r['id'], 'status' => 2, 'by' => $by]);
    return true;
}

/** Approve every waiting item of this member and trust them: the new-member rule no longer holds their posts. */
function review_trust(array $r, int $by): int
{
    $prefs = json_decode_array((string)val('SELECT prefs FROM fb_users WHERE id=?', [(int)$r['user_id']]));
    $prefs['review_trusted'] = 1;
    db_update('fb_users', ['prefs' => json_encode_value($prefs)], 'id=?', [(int)$r['user_id']]);
    request_cache('users_full', null, true);
    $n = 0;
    foreach (all('SELECT * FROM fb_review WHERE user_id=? AND status=0 ORDER BY id', [(int)$r['user_id']]) as $item) $n += review_approve($item, $by) ? 1 : 0;
    return $n;
}

/**
 * A spammer: suspend the account, delete everything they wrote (their waiting items are rejected), and bring the counts of
 * the topics and categories they wrote in up to date.
 */
function review_ban(array $r, int $by): bool
{
    $uid = (int)$r['user_id'];
    $user = user_by_id($uid);
    if ($user === null || $uid === $by) return false;
    $group = group_by_id((int)$user['group_id']);
    if ($group !== null && ((int)$group['is_admin'] === 1 || (int)$group['is_mod'] === 1)) return false; // staff is never banned from the queue
    $topics = array_map('intval', col('SELECT DISTINCT topic_id FROM fb_posts WHERE user_id=? AND is_deleted<>1', [$uid]));
    $posts = array_map('intval', col('SELECT id FROM fb_posts WHERE user_id=? AND is_deleted=0', [$uid])); // the ones in the search index
    $cats = $topics !== [] ? array_map('intval', col('SELECT DISTINCT category_id FROM fb_topics WHERE id IN (' . sql_marks(count($topics)) . ')', $topics)) : [];
    tx(static function () use ($uid, $by): void {
        q("UPDATE fb_review SET status=2, note='', decided_at=?, decided_by=? WHERE user_id=? AND status=0", [now(), $by, $uid]);
        q('UPDATE fb_posts SET is_deleted=1 WHERE user_id=? AND is_deleted<>1', [$uid]);
        q('UPDATE fb_topics SET is_deleted=1 WHERE user_id=? AND is_deleted<>1', [$uid]);
        db_update('fb_users', ['status' => 0, 'topic_count' => 0, 'post_count' => 0], 'id=?', [$uid]);
    });
    // a rare moderator action, not a page: the few queries per topic touched are fine here
    foreach ($posts as $pid) search_delete_post($pid);
    foreach ($topics as $tid) topic_stats_refresh($tid);
    foreach ($cats as $cid) category_refresh_stats($cid);
    request_cache('review_count', null, true);
    admin_log('review.ban', '#' . $uid . ' ' . (string)$user['username'], count($topics) . ' topics touched');
    return true;
}

/* ---------------------------------------------------------------- pages */

/** GET /review[?tab=waiting|approved|rejected]: the queue, for moderators. */
function review_page(): never
{
    need_login();
    if (!is_mod()) forbidden();
    $tab = in_array(get_str('tab', 10), ['approved', 'rejected'], true) ? get_str('tab', 10) : 'waiting';
    $status = ['waiting' => 0, 'approved' => 1, 'rejected' => 2][$tab];
    $join = $status === 0 ? ' JOIN fb_posts p ON p.id=r.post_id AND p.is_deleted=' . REVIEW_PENDING : ''; // withdrawn while waiting: gone from the queue
    $pg = paginate_calc((int)val("SELECT COUNT(*) FROM fb_review r{$join} WHERE r.status=?", [$status]), get_int('page', 1, 1, 100000), 30);
    $rows = all("SELECT r.* FROM fb_review r{$join} WHERE r.status=? ORDER BY r.id " . ($status === 0 ? 'ASC' : 'DESC') . ' LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [$status]);
    $posts = rows_by_ids('fb_posts', array_column($rows, 'post_id'), 'id,topic_id,user_id,body,is_deleted,created_at');
    $topics = rows_by_ids('fb_topics', array_column($rows, 'topic_id'), 'id,title,slug,category_id,is_deleted');
    $users = users_by_ids(array_merge(array_column($rows, 'user_id'), array_column($rows, 'decided_by')));
    $full = $rows !== [] ? rows_by_ids('fb_users', array_column($rows, 'user_id'), 'id,created_at,topic_count,post_count,status') : [];
    $counts = ['waiting' => review_count(), 'approved' => (int)val('SELECT COUNT(*) FROM fb_review WHERE status=1'), 'rejected' => (int)val('SELECT COUNT(*) FROM fb_review WHERE status=2')];
    $tabs = [];
    foreach (['waiting' => t('Waiting'), 'approved' => t('Approved'), 'rejected' => t('Rejected')] as $k => $label) $tabs[$k] = ['label' => $label, 'url' => url('/review', $k === 'waiting' ? [] : ['tab' => $k]), 'active' => $k === $tab, 'badge' => $counts[$k] ?: ''];
    page(t('Review queue'), view('review', ['rows' => $rows, 'posts' => $posts, 'topics' => $topics, 'users' => $users, 'full' => $full, 'tab' => $tab, 'tabs' => tabs($tabs),
        'pagination' => pagination($pg, static fn(int $n): string => url('/review', array_filter(['tab' => $tab === 'waiting' ? null : $tab, 'page' => $n > 1 ? $n : null])))]), ['class' => 'page-review', 'robots' => 'noindex']);
}

/** POST /review/act: id, do = approve | trust | reject | ban, note (reject). Answers JSON to the page's script, else goes back. */
function review_act(): never
{
    $me = need_login();
    require_post();
    if (!is_mod()) forbidden();
    $r = one('SELECT * FROM fb_review WHERE id=?', [post_int('id')]);
    if ($r === null) not_found();
    $do = post_str('do', 10);
    $ok = match ($do) {
        'approve' => review_approve($r, (int)$me['id']),
        'trust' => review_trust($r, (int)$me['id']) > 0,
        'reject' => review_reject($r, (int)$me['id'], trim(post_str('note', 190))),
        'ban' => review_ban($r, (int)$me['id']),
        default => false,
    };
    $message = match ($do) {
        'approve' => t('Approved.'), 'trust' => t('Approved. This member no longer waits for review.'), 'reject' => t('Rejected.'),
        'ban' => t('The member is suspended and everything they wrote is deleted.'), default => t('Request failed.'),
    };
    if (!$ok) $message = $do === 'ban' ? t('This member cannot be suspended from here.') : t('This item was already handled.');
    if (is_ajax()) json_ok(['message' => $message, 'done' => $ok, 'count' => review_count()]);
    flash($message);
    redirect(url('/review'));
}

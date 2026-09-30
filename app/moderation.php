<?php
/**
 * Dealing with a spammer: ban (suspend + sign out everywhere), ban and remove what they wrote (soft, restorable post by
 * post), or delete the member and everything they wrote (administrators, cannot be undone). One confirmation page,
 * /u/{name}/moderate, reached from the profile's Manage menu, a post's actions and Admin → Users (one member or many).
 * Moderators act on members; administrators also on moderators; nobody on an administrator or on themselves.
 * Events: user.after_moderate (ctx: user_ids, action ban|remove|delete|unban, by, ips: user id => sign-up address) — a plugin adds its own step there (Guard
 * bans the address); region user.moderate.options adds fields to the confirmation form.
 */

/** Why $by may not act on $target ('' = may). */
function user_moderate_refusal(array $target, array $by): string
{
    if ((int)$target['id'] === (int)$by['id']) return t('You cannot do this to your own account.');
    $tg = group_by_id((int)$target['group_id']);
    $bg = group_by_id((int)$by['group_id']);
    if ($bg === null || ((int)$bg['is_admin'] !== 1 && (int)$bg['is_mod'] !== 1)) return t('You do not have permission to do that.');
    if ($tg !== null && (int)$tg['is_admin'] === 1) return t('An administrator cannot be banned or deleted here.');
    if ($tg !== null && (int)$tg['is_mod'] === 1 && (int)$bg['is_admin'] !== 1) return t('Only an administrator can do this to a moderator.');
    return '';
}

/** Suspend a member and sign them out on every device. */
function user_ban(int $uid): void
{
    db_update('fb_users', ['status' => 0], 'id=?', [$uid]);
    user_logout_everywhere($uid);
    save_settings(['stats_cache' => '']);
}

function user_unban(int $uid): void
{
    db_update('fb_users', ['status' => 1], 'id=?', [$uid]);
    save_settings(['stats_cache' => '']);
}

/**
 * Remove everything a member wrote (soft: moderators can restore a post) and reject what waits in the review queue.
 * Counts of the topics, categories and tags touched are brought up to date. Returns ['topics' => n, 'replies' => n].
 */
function user_remove_content(int $uid, int $by): array
{
    $topics = array_map('intval', col('SELECT id FROM fb_topics WHERE user_id=? AND is_deleted<>1', [$uid]));
    $touched = array_map('intval', col('SELECT DISTINCT topic_id FROM fb_posts WHERE user_id=? AND is_deleted<>1', [$uid]));
    $posts = array_map('intval', col('SELECT id FROM fb_posts WHERE user_id=? AND is_deleted=0', [$uid])); // the ones in the search index
    $replies = (int)val('SELECT COUNT(*) FROM fb_posts WHERE user_id=? AND is_deleted<>1 AND floor>0', [$uid]);
    $all = array_values(array_unique(array_merge($topics, $touched)));
    $cats = $all !== [] ? array_map('intval', col('SELECT DISTINCT category_id FROM fb_topics WHERE id IN (' . sql_marks(count($all)) . ')', $all)) : [];
    $tags = $topics !== [] ? array_map('intval', col('SELECT DISTINCT tag_id FROM fb_topic_tags WHERE topic_id IN (' . sql_marks(count($topics)) . ')', $topics)) : [];
    tx(static function () use ($uid, $by): void {
        q("UPDATE fb_review SET status=2, note='', decided_at=?, decided_by=? WHERE user_id=? AND status=0", [now(), $by, $uid]);
        q('UPDATE fb_posts SET is_deleted=1 WHERE user_id=? AND is_deleted<>1', [$uid]);
        q('UPDATE fb_topics SET is_deleted=1 WHERE user_id=? AND is_deleted<>1', [$uid]);
        db_update('fb_users', ['topic_count' => 0, 'post_count' => 0], 'id=?', [$uid]);
    });
    // a rare moderator action, not a page: a few queries per topic touched are fine here
    foreach ($posts as $pid) search_delete_post($pid);
    foreach ($topics as $tid) { search_delete_topic($tid); fire('topic.after_delete', ['topic_id' => $tid]); }
    foreach (array_diff($touched, $topics) as $tid) topic_stats_refresh($tid);
    foreach ($cats as $cid) category_refresh_stats($cid);
    if ($tags !== []) q('UPDATE fb_tags SET topic_count=(SELECT COUNT(*) FROM fb_topic_tags tt JOIN fb_topics t ON t.id=tt.topic_id WHERE tt.tag_id=fb_tags.id AND t.is_deleted=0) WHERE id IN (' . sql_marks(count($tags)) . ')', $tags);
    request_cache('review_count', null, true);
    save_settings(['stats_cache' => '']);
    return ['topics' => count($topics), 'replies' => $replies];
}

/**
 * Delete a member and everything they wrote, for good: their topics (with the replies in them), their replies elsewhere,
 * likes, bookmarks, read marks, notifications to and from them, uploaded files, points and review items. Counts of what
 * stays are brought up to date. Plugins clear their own rows on user.after_delete (ctx: user_id, topic_ids, post_ids).
 * Returns ['topics' => n, 'replies' => n, 'files' => n].
 */
function user_delete(int $uid): array
{
    $user = user_by_id($uid);
    if ($user === null) return ['topics' => 0, 'replies' => 0, 'files' => 0];
    $topics = array_map('intval', col('SELECT id FROM fb_topics WHERE user_id=?', [$uid]));
    $in_topics = $topics !== [] ? array_map('intval', col('SELECT id FROM fb_posts WHERE topic_id IN (' . sql_marks(count($topics)) . ')', $topics)) : [];
    $own = array_map('intval', col('SELECT id FROM fb_posts WHERE user_id=?', [$uid]));
    $posts = array_values(array_unique(array_merge($in_topics, $own)));
    $replies = (int)val('SELECT COUNT(*) FROM fb_posts WHERE user_id=? AND floor>0', [$uid]);
    $touched = array_values(array_diff(array_map('intval', col('SELECT DISTINCT topic_id FROM fb_posts WHERE user_id=?', [$uid])), $topics));
    $all = array_values(array_unique(array_merge($topics, $touched)));
    $cats = $all !== [] ? array_map('intval', col('SELECT DISTINCT category_id FROM fb_topics WHERE id IN (' . sql_marks(count($all)) . ')', $all)) : [];
    $tags = $topics !== [] ? array_map('intval', col('SELECT DISTINCT tag_id FROM fb_topic_tags WHERE topic_id IN (' . sql_marks(count($topics)) . ')', $topics)) : [];
    // whose counts change: authors of the posts the member liked, authors of the other posts in the member's topics
    $liked_posts = array_map('intval', col('SELECT post_id FROM fb_likes WHERE user_id=?', [$uid]));
    $others = array_values(array_unique(array_merge(
        $liked_posts !== [] ? array_map('intval', col('SELECT user_id FROM fb_posts WHERE id IN (' . sql_marks(count($liked_posts)) . ')', $liked_posts)) : [],
        $posts !== [] ? array_map('intval', col('SELECT user_id FROM fb_posts WHERE id IN (' . sql_marks(count($posts)) . ') AND user_id<>?', array_merge($posts, [$uid]))) : [],
        $posts !== [] ? array_map('intval', col('SELECT user_id FROM fb_likes WHERE post_id IN (' . sql_marks(count($posts)) . ') AND user_id<>?', array_merge($posts, [$uid]))) : []
    )));
    $files = all('SELECT path FROM fb_attachments WHERE user_id=?' . ($posts !== [] ? ' OR post_id IN (' . sql_marks(count($posts)) . ')' : ''), array_merge([$uid], $posts));
    tx(static function () use ($uid, $user, $topics, $posts): void {
        $pin = $posts !== [] ? ' IN (' . sql_marks(count($posts)) . ')' : ' IN (0)';
        $tin = $topics !== [] ? ' IN (' . sql_marks(count($topics)) . ')' : ' IN (0)';
        q('DELETE FROM fb_likes WHERE user_id=? OR post_id' . $pin, array_merge([$uid], $posts));
        q('DELETE FROM fb_notifications WHERE user_id=? OR from_user_id=? OR post_id' . $pin . ' OR topic_id' . $tin, array_merge([$uid, $uid], $posts, $topics));
        q('DELETE FROM fb_review WHERE user_id=? OR post_id' . $pin, array_merge([$uid], $posts));
        q('DELETE FROM fb_bookmarks WHERE user_id=? OR topic_id' . $tin, array_merge([$uid], $topics));
        q('DELETE FROM fb_topic_reads WHERE user_id=? OR topic_id' . $tin, array_merge([$uid], $topics));
        q('DELETE FROM fb_topic_tags WHERE topic_id' . $tin, $topics);
        q('DELETE FROM fb_attachments WHERE user_id=? OR post_id' . $pin, array_merge([$uid], $posts));
        q('DELETE FROM fb_posts WHERE id' . $pin, $posts);
        q('DELETE FROM fb_topics WHERE id' . $tin, $topics);
        q('DELETE FROM fb_points_log WHERE user_id=?', [$uid]);
        q('DELETE FROM fb_email_codes WHERE email=?', [(string)$user['email']]);
        q('DELETE FROM fb_users WHERE id=?', [$uid]);
    });
    foreach ($posts as $pid) search_delete_post($pid);
    foreach ($topics as $tid) fire('topic.after_delete', ['topic_id' => $tid]);
    fire('user.after_delete', ['user_id' => $uid, 'topic_ids' => $topics, 'post_ids' => $posts]);
    $n = 0;
    foreach ($files as $f) if (upload_file_delete((string)$f['path'])) $n++;
    if ((string)$user['avatar'] !== '' && !preg_match('#^https?://#', (string)$user['avatar'])) upload_file_delete((string)$user['avatar']);
    // what stays: the counts of the topics the member replied in, of the posts they liked, and of the members around them
    if ($liked_posts !== []) q('UPDATE fb_posts SET like_count=(SELECT COUNT(*) FROM fb_likes WHERE fb_likes.post_id=fb_posts.id) WHERE id IN (' . sql_marks(count($liked_posts)) . ')', $liked_posts);
    foreach ($touched as $tid) topic_stats_refresh($tid);
    foreach ($cats as $cid) category_refresh_stats($cid);
    if ($tags !== []) q('UPDATE fb_tags SET topic_count=(SELECT COUNT(*) FROM fb_topic_tags tt JOIN fb_topics t ON t.id=tt.topic_id WHERE tt.tag_id=fb_tags.id AND t.is_deleted=0) WHERE id IN (' . sql_marks(count($tags)) . ')', $tags);
    if ($others !== []) {
        q('UPDATE fb_users SET topic_count=(SELECT COUNT(*) FROM fb_topics t WHERE t.user_id=fb_users.id AND t.is_deleted=0),'
            . ' post_count=(SELECT COUNT(*) FROM fb_posts p WHERE p.user_id=fb_users.id AND p.is_deleted=0 AND p.floor>0),'
            . ' like_count=(SELECT COUNT(*) FROM fb_likes l JOIN fb_posts p ON p.id=l.post_id WHERE p.user_id=fb_users.id) WHERE id IN (' . sql_marks(count($others)) . ')', $others);
    }
    request_cache('review_count', null, true);
    request_cache('users_full', null, true);
    save_settings(['stats_cache' => '']);
    return ['topics' => count($topics), 'replies' => $replies, 'files' => $n];
}

/** Remove a file under uploads/ (a stored path, with or without its ?v= stamp). Never leaves uploads/. */
function upload_file_delete(string $path): bool
{
    $path = ltrim((string)preg_replace('/[?#].*$/', '', $path), '/');
    if ($path === '' || str_contains($path, '..')) return false;
    $file = UPLOAD_DIR . '/' . $path;
    return is_file($file) && @unlink($file);
}

/**
 * Apply an action (ban | remove | delete | unban) to members, as $by. Members $by may not act on are skipped.
 * Returns ['done' => [uid, …], 'skipped' => [uid => reason], 'topics' => n, 'replies' => n].
 */
function user_moderate(array $uids, string $action, array $by): array
{
    $out = ['done' => [], 'skipped' => [], 'topics' => 0, 'replies' => 0];
    $ips = [];
    if (!in_array($action, ['ban', 'remove', 'delete', 'unban'], true)) return $out;
    $admin = (int)(group_by_id((int)$by['group_id'])['is_admin'] ?? 0) === 1;
    foreach (users_by_ids(array_slice(array_values(array_unique(array_map('intval', $uids))), 0, 100)) as $u) {
        $uid = (int)$u['id'];
        $full = user_by_id($uid);
        if ($full === null) continue;
        $why = user_moderate_refusal($full, $by);
        if ($why === '' && $action === 'delete' && !$admin) $why = t('Only an administrator can delete a member.');
        if ($why !== '') { $out['skipped'][$uid] = $why; continue; }
        $ips[$uid] = (string)($full['created_ip'] ?? ''); // read before a delete takes the row
        if ($action === 'unban') {
            user_unban($uid);
        } elseif ($action === 'delete') {
            $r = user_delete($uid);
            $out['topics'] += $r['topics'];
            $out['replies'] += $r['replies'];
        } else {
            user_ban($uid);
            if ($action === 'remove') {
                $r = user_remove_content($uid, (int)$by['id']);
                $out['topics'] += $r['topics'];
                $out['replies'] += $r['replies'];
            }
        }
        admin_log('user.' . $action, '#' . $uid . ' ' . (string)$full['username'], (string)($full['created_ip'] ?? ''));
        $out['done'][] = $uid;
    }
    if ($out['done'] !== []) fire('user.after_moderate', ['user_ids' => $out['done'], 'action' => $action, 'by' => (int)$by['id'], 'ips' => $ips]);
    return $out;
}

/** The message after an action. */
function user_moderate_message(array $r, string $action): string
{
    $n = count($r['done']);
    $msg = match ($action) {
        'ban' => t('Banned: %d members. They were signed out everywhere.', $n),
        'remove' => t('Banned: %d members; %d topics and %d replies removed.', $n, $r['topics'], $r['replies']),
        'delete' => t('Deleted: %d members with %d topics and %d replies.', $n, $r['topics'], $r['replies']),
        default => t('Ban lifted: %d members.', $n),
    };
    return $msg . ($r['skipped'] !== [] ? ' ' . t('Skipped: %s', implode(' ', array_unique(array_values($r['skipped'])))) : '');
}

/* ---------------------------------------------------------------- the page */

/** GET|POST /u/{name}/moderate — what the member did, and the actions. Moderators and administrators. */
function user_moderate_page(string $name): never
{
    $me = need_login();
    if (!is_mod()) forbidden();
    $u = user_by_name(rawurldecode($name));
    if ($u === null) not_found();
    $admin = is_admin();
    $back = url('/u/' . rawurlencode((string)$u['username']) . '/moderate');
    if (is_post()) {
        require_post();
        $action = post_str('action', 10);
        if ($action === 'delete') need_sudo();
        $ids = array_merge([(int)$u['id']], array_map('intval', post_list('also')));
        $r = user_moderate($ids, $action, $me);
        if ($r['done'] === [] && $r['skipped'] !== []) fail(implode(' ', array_unique(array_values($r['skipped']))), $back);
        flash(user_moderate_message($r, $action));
        redirect($action === 'delete' ? url('/') : $back);
    }
    $refusal = user_moderate_refusal($u, $me);
    $group = group_by_id((int)$u['group_id']);
    $topics = (int)val('SELECT COUNT(*) FROM fb_topics WHERE user_id=? AND is_deleted<>1', [(int)$u['id']]);
    $replies = (int)val('SELECT COUNT(*) FROM fb_posts WHERE user_id=? AND is_deleted<>1 AND floor>0', [(int)$u['id']]);
    $recent = all('SELECT id,title,slug,created_at,is_deleted FROM fb_topics WHERE user_id=? ORDER BY id DESC LIMIT 5', [(int)$u['id']]);
    $ip = (string)($u['created_ip'] ?? '');
    $same_ip = $ip !== '' ? all('SELECT id,username,display_name,avatar,created_at,status,topic_count,post_count,group_id FROM fb_users WHERE created_ip=? AND id<>? ORDER BY id DESC LIMIT 20', [$ip, (int)$u['id']]) : [];
    $suspended = (int)$u['status'] !== 1;
    $h = '<section class="card mod-page"><div class="card-body">'
        . '<div class="mod-who">' . avatar($u, 48, false) . '<div><h2>' . h(user_name($u)) . ' <small class="muted">@' . h((string)$u['username']) . '</small></h2>'
        . '<div class="muted small">' . h($group['name'] ?? '') . ' · ' . t('Joined %s', time_tag((int)$u['created_at'])) . ' · ' . t('Seen %s', time_tag((int)$u['last_seen']))
        . ($suspended ? ' · <span class="flag flag-danger">' . t('suspended') . '</span>' : '') . '</div>'
        . ($admin ? '<div class="muted small">' . h((string)$u['email']) . ($ip !== '' ? ' · ' . t('signed up from %s', h($ip)) : '') . '</div>' : '') . '</div></div>'
        . '<div class="mod-stats"><div><b>' . $topics . '</b><span>' . t('Topics') . '</span></div><div><b>' . $replies . '</b><span>' . t('Replies') . '</span></div><div><b>' . count($same_ip) . '</b><span>' . t('Other accounts from this address') . '</span></div></div>';
    if ($recent !== []) {
        $h .= '<h3>' . t('Latest topics') . '</h3><ul class="mod-recent">';
        foreach ($recent as $t) $h .= '<li' . ((int)$t['is_deleted'] === 1 ? ' class="muted"' : '') . '><a href="' . h(topic_url($t)) . '">' . h((string)$t['title']) . '</a> <small class="muted">' . time_tag((int)$t['created_at']) . '</small></li>';
        $h .= '</ul>';
    }
    if ($refusal !== '') {
        $h .= '<p class="flash flash-error">' . h($refusal) . '</p>';
    } else {
        $h .= '<form method="post" action="' . h($back) . '" class="mod-form">' . csrf_field();
        if ($same_ip !== []) {
            $h .= '<h3>' . t('Other accounts from this address') . '</h3><p class="muted small">' . t('Tick the ones that belong to the same person; the action applies to them too.') . '</p><div class="mod-others">';
            foreach ($same_ip as $o) {
                $h .= '<label class="mod-other">' . '<input type="checkbox" name="also[]" value="' . (int)$o['id'] . '">' . avatar($o, 24, false) . '<span>' . h(user_name($o)) . ' <small class="muted">' . t('%d topics', (int)$o['topic_count']) . ' · ' . time_tag((int)$o['created_at']) . ((int)$o['status'] !== 1 ? ' · ' . t('suspended') : '') . '</small></span></label>';
            }
            $h .= '</div>';
        }
        $h .= region('user.moderate.options', ['user' => $u], '', false);
        $h .= '<div class="mod-actions">';
        if ($suspended) $h .= '<button class="btn" name="action" value="unban">' . icon('refresh') . t('Lift the ban') . '</button>';
        else $h .= '<button class="btn" name="action" value="ban" data-confirm="' . h(t('Ban %s? They are signed out and can no longer sign in.', user_name($u))) . '">' . icon('lock') . t('Ban') . '</button>';
        $h .= '<button class="btn btn-danger" name="action" value="remove" data-confirm="' . h(t('Ban %s and remove their %d topics and %d replies? Moderators can still restore a post one by one.', user_name($u), $topics, $replies)) . '">' . icon('trash') . t('Ban and remove all they wrote') . '</button>';
        if ($admin) $h .= '<button class="btn btn-danger" name="action" value="delete" data-confirm="' . h(t('Delete %s and everything they wrote, for good? This cannot be undone.', user_name($u))) . '">' . icon('x') . t('Delete member and all they wrote') . '</button>';
        $h .= '</div><p class="muted small">' . t('Ban: the account is suspended and signed out on every device. Remove: every topic and reply of theirs is deleted and can be restored post by post. Delete: the account and everything in it is gone for good, uploaded files included.') . '</p></form>';
    }
    $h .= '</div></section>';
    page(t('Deal with %s', user_name($u)), $h, ['class' => 'page-moderate', 'robots' => 'noindex']);
}

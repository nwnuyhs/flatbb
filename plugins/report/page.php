<?php
/** Report — the report form, the moderators' queue (/reports and Admin → Reports) and its actions. Loaded by plugin.php. */
if (!defined('FLATBB')) exit;

/** GET|POST /report/{post} — a member reports one post. */
function report_form(string $id): never
{
    $me = need_login();
    $post = post_by_id((int)$id);
    $topic = $post !== null ? post_topic_visible($post) : null;
    if ($post === null || $topic === null || (int)$post['is_deleted'] === 1) not_found();
    $back = url('/post/' . (int)$post['id']);
    if ((int)$post['user_id'] === (int)$me['id']) fail(t('You cannot report your own post.'), $back);
    $reasons = report_reasons();

    if (is_post()) {
        require_post();
        $reason = post_str('reason', 60);
        if (!in_array($reason, $reasons, true)) fail(t('Pick a reason.'));
        if (one('SELECT id FROM plugin_report_reports WHERE post_id=? AND user_id=?', [(int)$post['id'], (int)$me['id']]) !== null) fail(t('You already reported this post.'), $back);
        $limit = max(1, (int)plugin_setting('report', 'per_day', 10));
        if ((int)val('SELECT COUNT(*) FROM plugin_report_reports WHERE user_id=? AND created_at>?', [(int)$me['id'], now() - 86400]) >= $limit) fail(t('You reported enough for today; please try again tomorrow.'), $back);
        $note = cut(trim(post_str('note', 1000)), 1000, '');
        db_insert('plugin_report_reports', [
            'post_id' => (int)$post['id'], 'topic_id' => (int)$topic['id'], 'user_id' => (int)$me['id'], 'reason' => $reason, 'note' => $note,
            'status' => 'open', 'outcome' => '', 'handled_by' => 0, 'handled_at' => 0, 'created_at' => now(),
        ]);
        if ((int)plugin_setting('report', 'notify', 1) === 1) {
            // one notification per moderator: the staff is a handful of people, capped at 50 in report_staff_ids()
            $content = $reason . ($note !== '' ? ' — ' . cut($note, 100) : '');
            foreach (report_staff_ids() as $to) if ($to !== (int)$me['id']) notify($to, (int)$me['id'], 'report', $content, (int)$topic['id'], (int)$post['id']);
        }
        flash(t('Thanks — a moderator will take a look.'));
        redirect($back);
    }

    $author = users_by_ids([(int)$post['user_id']])[(int)$post['user_id']] ?? null;
    $options = [];
    foreach ($reasons as $r) $options[$r] = t($r); // the value stays the setting's text; the label is translated
    $quote = '<blockquote class="report-quote"><b>' . h($author['username'] ?? t('Unknown')) . '</b> · <a href="' . h(topic_url($topic)) . '">' . h(cut((string)$topic['title'], 80)) . '</a><br>' . h(md_excerpt((string)$post['body'], 300)) . '</blockquote>';
    $form = '<form method="post" action="' . h(url('/report/' . (int)$post['id'])) . '" class="admin-form" data-ajax="1">' . csrf_field()
        . form_row(t('Reason'), select('reason', $options, (string)($reasons[0] ?? '')))
        . form_row(t('Note for the moderators (optional)'), textarea('note', '', ['rows' => 4, 'maxlength' => 1000, 'placeholder' => t('What is wrong with this post?')]))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . icon('flag') . t('Send report') . '</button><a class="btn btn-ghost" href="' . h($back) . '">' . t('Cancel') . '</a></div></form>';
    page(t('Report a post'), '<div class="compose"><h1>' . t('Report a post') . '</h1><p class="muted">' . t('Only the moderators see reports. Reporting is not a vote: one report is enough for them to look.') . '</p>' . $quote . $form . '</div>', ['class' => 'page-compose', 'right' => false, 'robots' => 'noindex']);
}

/* ---------------------------------------------------------------- the queue */

/** GET /reports — the moderators' queue on the site. */
function report_queue(): never
{
    need_login();
    if (!is_mod()) forbidden();
    page(t('Reports'), '<div class="compose"><h1>' . t('Reports') . '</h1>' . report_queue_html(url('/reports')) . '</div>', ['class' => 'page-compose', 'right' => false, 'robots' => 'noindex']);
}

/** GET /admin/ext/report/queue — the same queue inside the admin panel. */
function report_admin(string $page): never
{
    need_admin();
    admin_page(t('Reports'), report_queue_html(url('/admin/ext/report/queue')), 'ext.report.queue');
}

/** The queue: open or closed reports grouped by post, newest activity first; ?post= narrows it to one post. */
function report_queue_html(string $base): string
{
    $tab = get_str('tab', 10) === 'closed' ? 'closed' : 'open';
    $only = get_int('post', 0);
    $where = 'status=?';
    $params = [$tab];
    if ($only > 0) { $where .= ' AND post_id=?'; $params[] = $only; }
    $total = (int)val("SELECT COUNT(DISTINCT post_id) FROM plugin_report_reports WHERE {$where}", $params);
    $counts = q("SELECT status, COUNT(DISTINCT post_id) FROM plugin_report_reports GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $nav = tabs([
        'open' => ['label' => t('Open'), 'badge' => (int)($counts['open'] ?? 0) > 0 ? (string)(int)$counts['open'] : '', 'url' => report_url_with($base, ['tab' => 'open', 'post' => $only ?: null]), 'active' => $tab === 'open'],
        'closed' => ['label' => t('Closed'), 'badge' => (int)($counts['closed'] ?? 0) > 0 ? (string)(int)$counts['closed'] : '', 'url' => report_url_with($base, ['tab' => 'closed', 'post' => $only ?: null]), 'active' => $tab === 'closed'],
    ], 'tabs tabs-sub');
    if ($only > 0) $nav .= '<p class="muted small">' . t('Showing one post.') . ' <a href="' . h(report_url_with($base, ['tab' => $tab])) . '">' . t('Show all') . '</a></p>';
    if ($total === 0) return $nav . '<p class="muted">' . ($tab === 'open' ? t('Nothing to look at — no open reports.') : t('No closed reports yet.')) . '</p>';

    $p = paginate_calc($total, max(1, get_int('page', 1)), 30);
    $groups = all("SELECT post_id, MAX(created_at) last_at, COUNT(*) n FROM plugin_report_reports WHERE {$where} GROUP BY post_id ORDER BY last_at DESC LIMIT " . (int)$p['per_page'] . ' OFFSET ' . (int)$p['offset'], $params);
    $post_ids = array_map(static fn(array $g): int => (int)$g['post_id'], $groups);
    $rows = all("SELECT * FROM plugin_report_reports WHERE status=? AND post_id IN (" . sql_marks(count($post_ids)) . ') ORDER BY created_at DESC', array_merge([$tab], $post_ids));
    $posts = rows_by_ids('fb_posts', $post_ids, 'id,topic_id,user_id,body,floor,is_deleted');
    $topics = rows_by_ids('fb_topics', array_map(static fn(array $r): int => (int)$r['topic_id'], $rows), 'id,title,slug');
    $user_ids = [];
    foreach ($rows as $r) { $user_ids[] = (int)$r['user_id']; $user_ids[] = (int)$r['handled_by']; }
    foreach ($posts as $po) $user_ids[] = (int)$po['user_id'];
    $users = users_by_ids($user_ids);
    $by_post = [];
    foreach ($rows as $r) $by_post[(int)$r['post_id']][] = $r;

    $html = $nav;
    foreach ($groups as $g) {
        $pid = (int)$g['post_id'];
        $po = $posts[$pid] ?? null;
        $list = $by_post[$pid] ?? [];
        $topic = $topics[(int)($list[0]['topic_id'] ?? 0)] ?? null;
        $author = $po !== null ? ($users[(int)$po['user_id']] ?? null) : null;
        $html .= '<section class="report-group" id="report-post-' . $pid . '"><header class="report-group-head">'
            . ($topic !== null ? '<a href="' . h(url('/post/' . $pid)) . '"><b>' . h(cut((string)$topic['title'], 70)) . '</b></a>' : '<b class="muted">' . t('Post removed') . '</b>')
            . ($po !== null ? '<span class="muted small">' . ((int)$po['floor'] === 0 ? t('first post') : t('reply #%d', (int)$po['floor'])) . ' · ' . h($author['username'] ?? t('Unknown')) . ((int)$po['is_deleted'] === 1 ? ' · ' . t('deleted') : '') . '</span>' : '')
            . '<span class="flag' . ($tab === 'open' ? ' flag-danger' : '') . '">' . h(t('%d report(s)', (int)$g['n'])) . '</span></header>';
        if ($po !== null) $html .= '<div class="report-excerpt">' . h(md_excerpt((string)$po['body'], 220)) . '</div>';
        $html .= '<ul class="report-list">';
        foreach ($list as $r) {
            $who = $users[(int)$r['user_id']] ?? null;
            $html .= '<li><b>' . h($who['username'] ?? t('Unknown')) . '</b><span>' . h(t((string)$r['reason'])) . '</span><span class="muted small">' . h(human_time((int)$r['created_at'])) . '</span>'
                . ((string)$r['note'] !== '' ? '<span class="report-note">' . h((string)$r['note']) . '</span>' : '')
                . ($tab === 'closed' ? '<span class="muted small">' . h((string)$r['outcome'] === 'dismissed' ? t('dismissed') : t('resolved')) . ' · ' . h(($users[(int)$r['handled_by']]['username'] ?? '')) . ' · ' . h(human_time((int)$r['handled_at'])) . '</span>' : '')
                . '</li>';
        }
        $html .= '</ul><div class="report-ops">';
        $hidden = ['post_id' => $pid, 'tab' => $tab, 'admin' => str_starts_with($base, url('/admin/')) ? 1 : 0];
        if ($tab === 'open') {
            $html .= action_form(url('/reports/act'), '<button class="btn btn-sm btn-primary">' . icon('check') . t('Resolve') . '</button>', $hidden + ['action' => 'resolve'], '', '')
                . action_form(url('/reports/act'), '<button class="btn btn-sm">' . icon('x') . t('Dismiss') . '</button>', $hidden + ['action' => 'dismiss'], '', '')
                . ($po !== null ? '<a class="btn btn-sm btn-ghost" href="' . h(url('/post/' . $pid)) . '">' . icon('eye') . t('Open the post') . '</a>' : '');
        } else {
            $html .= action_form(url('/reports/act'), '<button class="btn btn-sm">' . icon('refresh') . t('Reopen') . '</button>', $hidden + ['action' => 'reopen'], '', '');
        }
        $html .= '</div></section>';
    }
    return $html . pagination($p, static fn(int $n): string => report_url_with($base, ['tab' => $tab, 'post' => $only ?: null, 'page' => $n]));
}

/** POST /reports/act — resolve, dismiss or reopen every report on one post. */
function report_act(): never
{
    $me = need_login();
    if (!is_mod()) forbidden();
    require_post();
    $action = post_str('action', 10);
    $pid = post_int('post_id');
    // back is one of the two queue pages, nothing else
    $back = report_url_with(post_int('admin') === 1 ? url('/admin/ext/report/queue') : url('/reports'), ['tab' => post_str('tab', 10) === 'closed' ? 'closed' : 'open']);
    if ($pid <= 0) fail(t('Unknown post.'), $back);
    if ($action === 'reopen') {
        $n = db_update('plugin_report_reports', ['status' => 'open', 'outcome' => '', 'handled_by' => 0, 'handled_at' => 0], 'post_id=? AND status=?', [$pid, 'closed']);
        flash(t('%d report(s) reopened.', $n));
        redirect($back);
    }
    if ($action !== 'resolve' && $action !== 'dismiss') fail(t('Unknown action.'), $back);
    $n = db_update('plugin_report_reports', ['status' => 'closed', 'outcome' => $action === 'resolve' ? 'resolved' : 'dismissed', 'handled_by' => (int)$me['id'], 'handled_at' => now()], 'post_id=? AND status=?', [$pid, 'open']);
    admin_log('report.' . $action, 'post:' . $pid, t('%d report(s)', $n));
    flash($action === 'resolve' ? t('%d report(s) resolved.', $n) : t('%d report(s) dismissed.', $n));
    redirect($back);
}

/** $url with these query values added (null removes one); the url may already carry a query string. */
function report_url_with(string $url, array $params): string
{
    $parts = parse_url($url);
    $q = [];
    parse_str((string)($parts['query'] ?? ''), $q);
    foreach ($params as $k => $v) { if ($v === null || $v === '') unset($q[$k]); else $q[$k] = $v; }
    return (string)($parts['path'] ?? '/') . ($q !== [] ? '?' . http_build_query($q) : '');
}

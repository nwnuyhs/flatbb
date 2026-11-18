<?php
/**
 * Report — members flag a post or a reply for the moderators.
 *
 * Every post gets a "Report" action; it opens a small page with a reason and an optional note. Reports land in a queue
 * (/reports for moderators, Admin → Reports for admins) grouped by post, where a moderator resolves or dismisses them,
 * and the moderators are notified when a report comes in. On a topic page a moderator sees "Reports (n)" on a post
 * that carries open reports. One report per member per post; a member may file a limited number per day.
 */
if (!defined('FLATBB')) exit;

require_once __DIR__ . '/page.php';

function report_install(array $manifest): void
{
    db_create_table('plugin_report_reports', [
        'id' => 'id', 'post_id' => 'uint', 'topic_id' => 'uint', 'user_id' => 'uint', 'reason' => 'string', 'note' => 'text',
        'status' => 'key', 'outcome' => 'key', 'handled_by' => 'uint', 'handled_at' => 'uint', 'created_at' => 'uint',
    ]);
    db_create_index('plugin_report_reports', 'ux_report_post_user', ['post_id', 'user_id'], true);
    db_create_index('plugin_report_reports', 'ix_report_status', ['status', 'created_at']);
    db_create_index('plugin_report_reports', 'ix_report_user', ['user_id', 'created_at']);
}

function report_uninstall(array $manifest): void
{
    db_drop_table('plugin_report_reports');
}

/* ---------------------------------------------------------------- data */

/** The reasons a member can pick (setting reasons, comma separated). */
function report_reasons(): array
{
    $out = [];
    foreach (explode(',', (string)plugin_setting('report', 'reasons', 'Spam, Harassment or abuse, Off topic, Other')) as $r) {
        $r = trim($r);
        if ($r !== '') $out[] = cut($r, 60, '');
    }
    return $out !== [] ? $out : [t('Other')];
}

/** Ids of every active moderator and admin (the people a new report is sent to). */
function report_staff_ids(): array
{
    return array_map('intval', col('SELECT u.id FROM fb_users u JOIN fb_groups g ON g.id=u.group_id WHERE (g.is_admin=1 OR g.is_mod=1) AND u.status=1 LIMIT 50'));
}

/** Open reports per post for a set of posts: [post_id => n]. */
function report_open_counts(array $post_ids): array
{
    $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
    if ($post_ids === []) return [];
    $out = [];
    foreach (all("SELECT post_id, COUNT(*) n FROM plugin_report_reports WHERE status='open' AND post_id IN (" . sql_marks(count($post_ids)) . ') GROUP BY post_id', $post_ids) as $r) $out[(int)$r['post_id']] = (int)$r['n'];
    return $out;
}

/* ---------------------------------------------------------------- the topic page */

/** topic.view: for a moderator, the open-report counts of the posts on this page (one query, read in the post loop). */
function report_topic_view(array $topic, array $ctx): array
{
    if (!is_mod()) return $topic;
    $ids = array_map(static fn(array $p): int => (int)$p['id'], (array)($ctx['posts'] ?? []));
    $counts = report_open_counts($ids);
    request_cache('report_counts', static fn(): array => $counts);
    return $topic;
}

/** region.post.actions (loop, no DB): "Report" for members, "Reports (n)" for a moderator when the post has open reports. */
function report_post_actions(array $items, array $ctx): array
{
    $post = (array)($ctx['post'] ?? []);
    $id = (int)($post['id'] ?? 0);
    if (uid() <= 0 || $id <= 0 || !empty($post['is_deleted'])) return $items;
    if (is_mod()) {
        $n = (int)((request_cache('report_counts') ?? [])[$id] ?? 0);
        if ($n > 0) $items['report'] = ['html' => '<a class="act report-act report-flagged" href="' . h(url('/reports', ['post' => $id])) . '" title="' . h(t('Open reports on this post')) . '">' . icon('flag') . '<span>' . h(t('Reports (%d)', $n)) . '</span></a>', 'weight' => 40];
        return $items;
    }
    if ((int)($post['user_id'] ?? 0) === uid()) return $items;
    $items['report'] = ['html' => '<a class="act report-act" href="' . h(url('/report/' . $id)) . '" title="' . h(t('Report this post to the moderators')) . '">' . icon('flag') . '<span>' . h(t('Report')) . '</span></a>', 'weight' => 40];
    return $items;
}

function report_notification_kinds(array $kinds, array $ctx): array
{
    $kinds['report'] = ['flag', t('reported a post')];
    return $kinds;
}

function report_css(): string
{
    return '.report-act.report-flagged{color:var(--danger)}'
        . '.report-quote{border-inline-start:3px solid var(--line);padding:8px 12px;margin:0 0 16px;color:var(--text-muted);background:var(--panel-2);border-radius:var(--radius-sm)}'
        . '.report-quote b{color:var(--text)}'
        . '.report-group{border:1px solid var(--line);border-radius:var(--radius);background:var(--panel);margin-bottom:14px}'
        . '.report-group-head{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line)}'
        . '.report-group-head .flag{margin-inline-start:auto}'
        . '.report-excerpt{padding:10px 14px;color:var(--text-muted);font-size:var(--font-size-sm);border-bottom:1px solid var(--line)}'
        . '.report-list{list-style:none;margin:0;padding:6px 14px}'
        . '.report-list li{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline;padding:6px 0;border-bottom:1px dashed var(--line)}'
        . '.report-list li:last-child{border-bottom:0}'
        . '.report-note{flex-basis:100%;color:var(--text)}'
        . '.report-ops{display:flex;gap:8px;padding:10px 14px;border-top:1px solid var(--line);flex-wrap:wrap}'
        . '.report-ops .action-form{display:inline}';
}

return [
    'id' => 'report',
    'name' => 'Report',
    'version' => '1.0.1',
    'description' => 'Members report a post or a reply with a reason; moderators get a notification and a queue where they resolve or dismiss reports, and see "Reports (n)" on a flagged post.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.23'],
    'install' => 'report_install',
    'uninstall' => 'report_uninstall',
    'routes' => [
        '/report/{id}' => 'report_form',
        '/reports' => 'report_queue',
        '/reports/act' => 'report_act',
    ],
    'admin_pages' => ['queue' => ['label' => 'Reports', 'callback' => 'report_admin']],
    'hooks' => [
        'topic.view' => 'report_topic_view',
        'region.post.actions' => 'report_post_actions',
        'notification.kinds' => 'report_notification_kinds',
    ],
    'assets' => ['css' => ['report_css']],
    'settings' => [
        'reasons' => ['type' => 'text', 'label' => 'Reasons (comma separated)', 'default' => 'Spam, Harassment or abuse, Off topic, Other', 'max' => 300],
        'per_day' => ['type' => 'number', 'label' => 'Reports a member may file per day', 'default' => 10, 'min' => 1, 'max' => 100],
        'notify' => ['type' => 'checkbox', 'label' => 'Notify moderators of every new report', 'default' => 1],
    ],
];

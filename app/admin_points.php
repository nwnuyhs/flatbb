<?php
/**
 * Admin → Points: the rule table (what each action pays, its daily cap, on/off) and a few numbers about the economy.
 * Rules are declared by the core and by plugins (hook points.rules); this page only stores the admin's numbers.
 */

/** GET|POST /admin/points */
function admin_page_points(): never
{
    $list_url = admin_url('points');
    if (is_post()) {
        check_csrf();
        $saved = [];
        foreach (points_rules() as $id => $r) {
            $saved[$id] = ['amount' => max(-100000, min(100000, post_int('amount_' . $id))), 'cap' => max(0, min(100000, post_int('cap_' . $id))), 'enabled' => post_int('on_' . $id) === 1 ? 1 : 0];
        }
        save_settings(['points_rules' => json_encode_value($saved)]);
        request_cache('points_rules', null, true);
        admin_log('points.rules', implode(',', array_keys($saved)));
        flash(t('Points rules saved.'));
        redirect($list_url);
    }
    $day = points_day_start();
    $month = (int)strtotime('first day of this month 00:00:00');
    $today = one('SELECT COALESCE(SUM(CASE WHEN delta>0 THEN delta ELSE 0 END),0) AS i, COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) AS o, COUNT(*) AS n FROM fb_points_log WHERE created_at>=?', [$day]) ?? [];
    $this_month = one('SELECT COALESCE(SUM(CASE WHEN delta>0 THEN delta ELSE 0 END),0) AS i, COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) AS o FROM fb_points_log WHERE created_at>=?', [$month]) ?? [];
    $total = (int)val('SELECT COALESCE(SUM(points),0) FROM fb_users WHERE status=1');
    $cards = '<div class="admin-cards">'
        . card('', '<b>' . human_number($total) . '</b><span>' . t('Points in circulation') . '</span>')
        . card('', '<b class="up">+' . human_number((int)($today['i'] ?? 0)) . '</b><span>' . t('Earned today') . ' · ' . (int)($today['n'] ?? 0) . ' ' . t('entries') . '</span>')
        . card('', '<b class="down">-' . human_number((int)($today['o'] ?? 0)) . '</b><span>' . t('Spent today') . '</span>')
        . card('', '<b>+' . human_number((int)($this_month['i'] ?? 0)) . ' / -' . human_number((int)($this_month['o'] ?? 0)) . '</b><span>' . t('This month') . '</span>')
        . '</div>';
    $rows = '';
    $group = null;
    foreach (points_rules() as $id => $r) {
        if ($r['group'] !== $group) { $group = $r['group']; $rows .= '<tr class="points-group"><th colspan="4">' . h($group) . '</th></tr>'; }
        $rows .= '<tr><td><b>' . h($r['label']) . '</b><br><code class="muted small">' . h($id) . '</code>' . ($r['once'] ? '<br><small class="muted">' . t('once per item') . '</small>' : '') . '</td>'
            . '<td>' . input('amount_' . $id, (string)$r['amount'], ['type' => 'number', 'min' => -100000, 'max' => 100000, 'class' => 'points-num']) . '</td>'
            . '<td>' . input('cap_' . $id, (string)$r['cap'], ['type' => 'number', 'min' => 0, 'max' => 100000, 'class' => 'points-num']) . '</td>'
            . '<td><label class="check"><input type="checkbox" name="on_' . h($id) . '" value="1"' . ($r['enabled'] ? ' checked' : '') . '> ' . t('On') . '</label></td></tr>';
    }
    $html = $cards . '<form method="post" action="' . h($list_url) . '" class="admin-form" style="margin-top:14px">' . csrf_field()
        . '<p class="muted small">' . t('What each action pays, how many times a day it can pay one member (0 = no limit), and whether it is on. Plugins add their own rows; members see the active rows on their points page.') . '</p>'
        . '<div class="table-wrap"><table class="admin"><thead><tr><th>' . t('Rule') . '</th><th>' . t('Points') . '</th><th>' . t('Daily cap') . '</th><th>' . t('Enabled') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . t('Save rules') . '</button></div></form>'
        . '<style>.points-num{width:110px}.points-group th{background:var(--panel-2);font-weight:600;color:var(--text-muted);text-transform:uppercase;font-size:var(--font-size-xs);letter-spacing:.04em}</style>';
    admin_page(t('Points'), $html, 'points');
}

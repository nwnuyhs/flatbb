<?php
/**
 * /points — a member's points page: balance, this month's earnings and spending, the history (all / earned / spent)
 * with links to the posts behind each line, and the table of ways to earn (the rule table from Admin → Points).
 * Plugins add their entry points (check-in, tasks, leaderboard) through the list region points.actions.
 */

/** GET /points[?kind=in|out&page=n] */
function points_wallet(): never
{
    $me = need_login();
    $kind = get_str('kind', 3);
    if (!in_array($kind, ['in', 'out'], true)) $kind = '';
    [$earned, $spent] = points_month((int)$me['id']);
    $log = points_log((int)$me['id'], get_int('page', 1, 1, 10000), 30, $kind);
    $actions = region_list('points.actions', [], ['user' => $me]);
    $buttons = '';
    foreach ($actions as $a) $buttons .= is_array($a) ? '<a class="btn' . (!empty($a['primary']) ? ' btn-primary' : '') . '" href="' . h((string)$a['url']) . '">' . (!empty($a['icon']) ? icon((string)$a['icon']) : '') . h((string)$a['label']) . '</a>' : (string)$a;
    $html = '<section class="card points-head"><div class="card-body"><div class="points-summary"><div class="points-big"><small>' . t('Balance') . '</small><b>' . human_number((int)$me['points']) . '</b></div>'
        . '<div class="points-month"><div><small>' . t('Earned this month') . '</small><b class="up">+' . human_number($earned) . '</b></div><div><small>' . t('Spent this month') . '</small><b class="down">-' . human_number($spent) . '</b></div></div></div>'
        . ($buttons !== '' ? '<div class="btn-row points-actions" data-slot="points.actions">' . $buttons . '</div>' : '') . '</div></section>';
    $link = static fn(string $k): string => url('/points', $k !== '' ? ['kind' => $k] : []);
    $html .= '<section class="card"><header class="card-head"><h3>' . t('History') . '</h3>' . tabs(['all' => ['label' => t('All'), 'url' => $link(''), 'active' => $kind === ''], 'in' => ['label' => t('Earned'), 'url' => $link('in'), 'active' => $kind === 'in'], 'out' => ['label' => t('Spent'), 'url' => $link('out'), 'active' => $kind === 'out']], 'tabs tabs-sub') . '</header>'
        . '<div class="card-body">' . points_log_html($log['rows'], $kind === 'out' ? t('Nothing spent yet.') : ($kind === 'in' ? t('Nothing earned yet.') : '')) . pagination($log['pagination'], static fn(int $n): string => url('/points', array_filter(['kind' => $kind, 'page' => $n > 1 ? $n : null]))) . '</div></section>';
    $rows = '';
    foreach (points_rules() as $r) {
        if (!$r['enabled'] || $r['amount'] === 0) continue;
        $rows .= '<tr><td>' . h($r['label']) . '<br><small class="muted">' . h($r['group']) . '</small></td><td class="points-amount"><b class="' . ($r['amount'] > 0 ? 'up' : 'down') . '">' . ($r['amount'] > 0 ? '+' : '') . $r['amount'] . '</b></td><td class="muted small">' . ($r['cap'] > 0 ? t('up to %d times a day', $r['cap']) : t('no daily limit')) . ($r['once'] ? ' · ' . t('once per item') : '') . '</td></tr>';
    }
    if ($rows !== '') $html .= '<section class="card points-rules"><header class="card-head"><h3>' . t('How to earn points') . '</h3></header><div class="table-wrap"><table class="admin"><tbody>' . $rows . '</tbody></table></div></section>';
    page(t('My points'), $html, ['class' => 'page-points', 'right' => false]);
}

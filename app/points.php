<?php
/**
 * /points — a member's points page: a balance card (this month's earnings and spending, summary items such as the level),
 * the ways to earn as tiles, the history grouped by day with links to the posts behind each line, and the rule table.
 * Plugins add their entry points (check-in, tasks, leaderboard) through the list region points.actions and short facts
 * next to the balance through points.summary. The markup is app/views/points_wallet.php.
 */

/** GET /points[?kind=in|out&page=n] */
function points_wallet(): never
{
    $me = need_login();
    $kind = get_str('kind', 3);
    if (!in_array($kind, ['in', 'out'], true)) $kind = '';
    [$earned, $spent] = points_month((int)$me['id']);
    $log = points_log((int)$me['id'], get_int('page', 1, 1, 10000), 30, $kind);
    $ctx = ['user' => $me];
    $history = points_log_html($log['rows'], $kind === 'out' ? t('Nothing spent yet.') : ($kind === 'in' ? t('Nothing earned yet.') : ''), true)
        . pagination($log['pagination'], static fn(int $n): string => url('/points', array_filter(['kind' => $kind, 'page' => $n > 1 ? $n : null])));
    $rows = '';
    foreach (points_rules() as $r) {
        if (!$r['enabled'] || $r['amount'] === 0) continue;
        $rows .= '<tr><td>' . h($r['label']) . '<br><small class="muted">' . h($r['group']) . '</small></td><td class="points-amount"><b class="' . ($r['amount'] > 0 ? 'up' : 'down') . '">' . ($r['amount'] > 0 ? '+' : '') . $r['amount'] . '</b></td><td class="muted small">' . ($r['cap'] > 0 ? t('up to %d times a day', $r['cap']) : t('no daily limit')) . ($r['once'] ? ' · ' . t('once per item') : '') . '</td></tr>';
    }
    $rules = $rows !== '' ? '<section class="card points-rules"><header class="card-head"><h3>' . t('How to earn points') . '</h3></header><div class="table-wrap"><table class="admin"><tbody>' . $rows . '</tbody></table></div></section>' : '';
    page(t('My points'), view('points_wallet', [
        'me' => $me, 'earned' => $earned, 'spent' => $spent, 'kind' => $kind, 'history' => $history, 'rules' => $rules,
        'summary' => region_list('points.summary', [], $ctx),
        'actions' => region_list('points.actions', [], $ctx),
    ]), ['class' => 'page-points', 'right' => false]);
}

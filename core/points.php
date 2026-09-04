<?php
/**
 * Points ledger. The core keeps the balance (fb_users.points) and the history (fb_points_log) and
 * exposes a small API; earning rules, leaderboards and any economy live in plugins.
 *
 *   points_add($user_id, +5, 'checkin')          add or remove points (negative delta), logged
 *   points_of($user_id)                           current balance
 *   points_log($user_id, $page)                   history, newest first
 *   points_reasons()                              reason code => label; plugins register their codes via hook points.reasons
 *
 * Hooks: points.reasons (filter labels), points.before_change (filter ['user_id','delta','reason','ref_id','note'];
 * return false to veto), points.after_change (event, same fields + 'balance').
 * The history is private: only the user (Settings → Points) and admins see it.
 */

function points_reasons(): array
{
    return request_cache('points_reasons', static fn(): array => (array)hook('points.reasons', [
        'manual' => t('Adjustment by staff'),
    ], [])) ?? [];
}

function points_label(string $reason): string
{
    return points_reasons()[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
}

/** Change a user's balance. Returns false when vetoed or nothing to do. */
function points_add(int $user_id, int $delta, string $reason, int $ref_id = 0, string $note = ''): bool
{
    if ($user_id <= 0 || $delta === 0 || !preg_match('/^[a-z0-9_]{1,40}$/', $reason)) return false;
    $change = hook('points.before_change', ['user_id' => $user_id, 'delta' => $delta, 'reason' => $reason, 'ref_id' => $ref_id, 'note' => cut($note, 200, '')], []);
    if (!is_array($change) || (int)$change['delta'] === 0) return false;
    $balance = tx(static function () use ($change): int {
        db_increment('fb_users', 'points', (int)$change['delta'], 'id=?', [(int)$change['user_id']]);
        db_insert('fb_points_log', ['user_id' => (int)$change['user_id'], 'delta' => (int)$change['delta'], 'reason' => (string)$change['reason'], 'ref_id' => (int)$change['ref_id'], 'note' => (string)$change['note'], 'created_at' => now()]);
        return (int)val('SELECT points FROM fb_users WHERE id=?', [(int)$change['user_id']]);
    });
    request_cache('users_full', null, true);
    request_cache('users', null, true);
    if ((int)$change['user_id'] === uid()) request_cache('me', null, true);
    fire('points.after_change', $change + ['balance' => $balance]);
    return true;
}

function points_of(int $user_id): int
{
    return (int)(val('SELECT points FROM fb_users WHERE id=?', [$user_id]) ?? 0);
}

/** ['rows' => [...], 'pagination' => paginate_calc()] */
function points_log(int $user_id, int $page = 1, int $per_page = 30): array
{
    $pg = paginate_calc((int)val('SELECT COUNT(*) FROM fb_points_log WHERE user_id=?', [$user_id]), $page, $per_page);
    $rows = all('SELECT * FROM fb_points_log WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [$user_id]);
    foreach ($rows as &$r) $r['label'] = points_label((string)$r['reason']);
    return ['rows' => $rows, 'pagination' => $pg];
}

/** Top balances (public columns). Used by leaderboard plugins. */
function points_top(int $limit = 10): array
{
    return all('SELECT ' . user_public_columns() . ' FROM fb_users WHERE status=1 AND points>0 ORDER BY points DESC, id LIMIT ' . max(1, min(500, $limit)));
}

/** Whether a user shows the balance on the public profile (preference, default on). */
function points_public(array $user): bool
{
    return (int)(json_decode_array((string)($user['prefs'] ?? ''))['show_points'] ?? 1) === 1;
}

/** HTML list of history rows (shared by Settings → Points and the admin user drawer). */
function points_log_html(array $rows, string $empty = ''): string
{
    if ($rows === []) return '<div class="empty">' . icon('star') . '<p>' . h($empty !== '' ? $empty : t('No points activity yet.')) . '</p></div>';
    $h = '<ul class="points-log">';
    foreach ($rows as $r) {
        $d = (int)$r['delta'];
        $h .= '<li><span>' . h($r['label']) . ($r['note'] !== '' && $r['note'] !== null ? ' <small class="muted">· ' . h((string)$r['note']) . '</small>' : '') . '</span><b class="' . ($d >= 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . $d . '</b><small>' . human_time((int)$r['created_at']) . '</small></li>';
    }
    return $h . '</ul>';
}

<?php
/**
 * Points: the ledger, the earning rules and what members see of it.
 *
 * The core keeps the balance (fb_users.points), the history (fb_points_log) and the rule table (setting points_rules,
 * edited under Admin → Points). Plugins earn or spend points through this API; the economy itself (tasks, check-in,
 * rewards, the marketplace) lives in plugins.
 *
 *   points_award($user_id, 'reply', $post_id)     give what the rule "reply" says, within its daily cap, once per object
 *   points_add($user_id, -20, 'shop', $ref)        add or remove a fixed amount (negative delta), logged
 *   points_of($user_id)                            current balance
 *   points_log($user_id, $page, $per_page, $kind)  history, newest first ('' | 'in' | 'out')
 *   points_rules()                                 id => rule (label, amount, cap per day, once, enabled, group)
 *
 * Hooks: points.rules (filter the rule table: plugins add ['id' => ['label', 'amount', 'cap', 'once', 'group']] defaults;
 * the admin's numbers win), points.reasons (labels for reasons that are not rules), points.ref_url (filter: the URL
 * behind a ledger line, ctx reason + ref_id), points.before_change (filter ['user_id','delta','reason','ref_id','note'];
 * return false to veto), points.after_change (event, same fields + 'balance').
 * The history is private: only the member (their /points page) and admins see it.
 */

/* ---------------------------------------------------------------- rules */

/** The rule table: defaults from the core and the plugins (hook points.rules), numbers and switches from Admin → Points. */
function points_rules(): array
{
    return request_cache('points_rules', static function (): array {
        $defaults = [
            'topic' => ['label' => t('New topic'), 'amount' => 5, 'cap' => 10, 'once' => true, 'group' => t('Posting')],
            'reply' => ['label' => t('Reply'), 'amount' => 2, 'cap' => 20, 'once' => true, 'group' => t('Posting')],
            'like' => ['label' => t('Like received'), 'amount' => 1, 'cap' => 50, 'once' => true, 'group' => t('Community')],
            'liked' => ['label' => t('Like given'), 'amount' => 0, 'cap' => 20, 'once' => true, 'group' => t('Community')],
        ];
        $legacy = plugin_settings('points'); // the old Points plugin's numbers, taken over once
        foreach (['topic', 'reply', 'like'] as $k) if (isset($legacy[$k])) $defaults[$k]['amount'] = (int)$legacy[$k];
        $rules = (array)hook('points.rules', $defaults, []);
        $saved = json_decode_array(setting('points_rules', '{}'));
        $out = [];
        foreach ($rules as $id => $r) {
            if (!is_array($r) || !preg_match('/^[a-z0-9_]{1,40}$/', (string)$id)) continue;
            $s = (array)($saved[$id] ?? []);
            $out[$id] = ['label' => (string)($r['label'] ?? $id), 'amount' => (int)($s['amount'] ?? $r['amount'] ?? 0), 'cap' => (int)($s['cap'] ?? $r['cap'] ?? 0), 'once' => !empty($r['once']), 'enabled' => (int)($s['enabled'] ?? 1) === 1, 'group' => (string)($r['group'] ?? t('Plugins'))];
        }
        return $out;
    }) ?? [];
}

function points_rule(string $id): ?array
{
    return points_rules()[$id] ?? null;
}

/** Midnight today, for the daily caps. */
function points_day_start(): int
{
    return (int)strtotime('today');
}

/**
 * Give a member what a rule says: nothing when the rule is off or worth 0, when the daily cap is reached, or when this
 * object (a post, a day, a task) already paid this member under the same rule. Returns true when points moved.
 */
function points_award(int $user_id, string $rule_id, int $ref_id = 0, string $note = ''): bool
{
    $r = points_rule($rule_id);
    if ($r === null || !$r['enabled'] || $r['amount'] === 0 || $user_id <= 0) return false;
    if ($r['once'] && $ref_id > 0 && val('SELECT 1 FROM fb_points_log WHERE user_id=? AND reason=? AND ref_id=? AND delta>0', [$user_id, $rule_id, $ref_id])) return false;
    if ($r['cap'] > 0 && (int)val('SELECT COUNT(*) FROM fb_points_log WHERE user_id=? AND reason=? AND delta>0 AND created_at>=?', [$user_id, $rule_id, points_day_start()]) >= $r['cap']) return false;
    return points_add($user_id, $r['amount'], $rule_id, $ref_id, $note);
}

/** Take back what a rule paid for an object (a like undone, a post deleted): only what was really paid, once. */
function points_revoke(int $user_id, string $rule_id, int $ref_id, string $reason, string $note = ''): bool
{
    $paid = (int)val('SELECT COALESCE(SUM(delta),0) FROM fb_points_log WHERE user_id=? AND reason=? AND ref_id=? AND delta>0', [$user_id, $rule_id, $ref_id]);
    $taken = (int)val('SELECT COALESCE(SUM(delta),0) FROM fb_points_log WHERE user_id=? AND reason=? AND ref_id=? AND delta<0', [$user_id, $reason, $ref_id]);
    $left = $paid + $taken; // what is still in the member's pocket for this object
    return $left > 0 && points_add($user_id, -$left, $reason, $ref_id, $note);
}

/* ---------------------------------------------------------------- ledger */

function points_reasons(): array
{
    return request_cache('points_reasons', static function (): array {
        $labels = ['manual' => t('Adjustment by staff'), 'unlike' => t('Like removed')];
        foreach (points_rules() as $id => $r) $labels[$id] = $r['label'];
        return (array)hook('points.reasons', $labels, []);
    }) ?? [];
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

/** ['rows' => [...], 'pagination' => paginate_calc()]; $kind '' = everything, 'in' = earned, 'out' = spent. Rows carry label and url. */
function points_log(int $user_id, int $page = 1, int $per_page = 30, string $kind = ''): array
{
    $where = 'user_id=?' . ($kind === 'in' ? ' AND delta>0' : ($kind === 'out' ? ' AND delta<0' : ''));
    $pg = paginate_calc((int)val("SELECT COUNT(*) FROM fb_points_log WHERE {$where}", [$user_id]), $page, $per_page);
    $rows = all("SELECT * FROM fb_points_log WHERE {$where} ORDER BY id DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [$user_id]);
    return ['rows' => points_log_links($rows), 'pagination' => $pg];
}

/** [earned, spent] this month, as positive numbers. */
function points_month(int $user_id): array
{
    $r = one('SELECT COALESCE(SUM(CASE WHEN delta>0 THEN delta ELSE 0 END),0) AS i, COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) AS o FROM fb_points_log WHERE user_id=? AND created_at>=?', [$user_id, (int)strtotime('first day of this month 00:00:00')]);
    return [(int)($r['i'] ?? 0), (int)($r['o'] ?? 0)];
}

/**
 * Adds 'label' and 'url' to ledger rows in two queries at most: reasons about a post (reply, like…) link to the post,
 * "topic" to the topic; plugins answer for their own reasons through points.ref_url.
 */
function points_log_links(array $rows): array
{
    $post_reasons = ['reply', 'like', 'unlike', 'liked', 'reward_reply', 'qa_answer', 'qa_accept', 'qa_withdraw'];
    $post_ids = $topic_ids = [];
    foreach ($rows as $r) {
        if ((int)$r['ref_id'] <= 0) continue;
        if (in_array((string)$r['reason'], $post_reasons, true)) $post_ids[] = (int)$r['ref_id'];
        elseif (in_array((string)$r['reason'], ['topic', 'reward_set', 'reward_back'], true)) $topic_ids[] = (int)$r['ref_id'];
    }
    $posts = $post_ids !== [] ? rows_by_ids('fb_posts', array_unique($post_ids)) : [];
    foreach ($posts as $p) $topic_ids[] = (int)$p['topic_id'];
    $topics = $topic_ids !== [] ? rows_by_ids('fb_topics', array_unique($topic_ids)) : [];
    foreach ($rows as &$r) {
        $r['label'] = points_label((string)$r['reason']);
        $r['url'] = '';
        $r['title'] = '';
        $ref = (int)$r['ref_id'];
        if (in_array((string)$r['reason'], $post_reasons, true) && isset($posts[$ref], $topics[(int)$posts[$ref]['topic_id']])) { $t = $topics[(int)$posts[$ref]['topic_id']]; $r['url'] = topic_url($t, 1, $ref); $r['title'] = (string)$t['title']; }
        elseif (isset($topics[$ref]) && in_array((string)$r['reason'], ['topic', 'reward_set', 'reward_back'], true)) { $r['url'] = topic_url($topics[$ref]); $r['title'] = (string)$topics[$ref]['title']; }
        else { $r['url'] = (string)hook('points.ref_url', '', ['reason' => (string)$r['reason'], 'ref_id' => $ref, 'row' => $r]); }
    }
    return $rows;
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

/** HTML list of history rows (the /points page, Settings → Points and the admin user drawer). */
function points_log_html(array $rows, string $empty = ''): string
{
    if ($rows === []) return '<div class="empty">' . icon('coin') . '<p>' . h($empty !== '' ? $empty : t('No points activity yet.')) . '</p></div>';
    $h = '<ul class="points-log">';
    foreach ($rows as $r) {
        $d = (int)$r['delta'];
        $what = h((string)($r['label'] ?? points_label((string)$r['reason'])));
        $title = (string)($r['title'] ?? '');
        $url = (string)($r['url'] ?? '');
        $sub = $title !== '' ? cut($title, 60) : (string)($r['note'] ?? '');
        $h .= '<li><span class="points-what">' . ($url !== '' ? '<a href="' . h($url) . '">' . $what . '</a>' : $what) . ($sub !== '' ? ' <small class="muted">· ' . h($sub) . '</small>' : '') . '</span><b class="' . ($d >= 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . $d . '</b><small>' . time_tag((int)$r['created_at']) . '</small></li>';
    }
    return $h . '</ul>';
}

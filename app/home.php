<?php
/**
 * Home and topic lists: Latest, Top, Unread. Also the shared topic-list loader used by
 * category, tag, user and search pages.
 */

/** GET / */
function home_index(): never
{
    home_latest();
}

function home_latest(): never
{
    $p = topic_list_page();
    $list = topic_list_fetch('', [], 'is_pinned DESC, pinned_at DESC, last_post_at DESC', $p, $p['page'] === 1);
    topic_list_page_render(t('Latest'), $list, 'latest', static fn(int $n): string => url('/latest', $n > 1 ? ['page' => $n] : []));
}

function home_top(string $period = 'week'): never
{
    [$where, $params] = top_scope($period);
    $p = topic_list_page();
    $list = topic_list_fetch($where, $params, top_order(), $p);
    topic_list_page_render(t('Top'), $list, 'top', static fn(int $n): string => url('/top/' . $period, $n > 1 ? ['page' => $n] : []), top_sub_tabs($period, '/top'));
}

function home_unread(): never
{
    [$join, $where, $params] = unread_scope(need_login());
    $p = topic_list_page();
    $list = topic_list_fetch($where, $params, 't.last_post_at DESC', $p, false, $join);
    topic_list_page_render(t('Unread'), $list, 'unread', static fn(int $n): string => url('/unread', $n > 1 ? ['page' => $n] : []));
}

/** Top periods: key => seconds back (0 = all time). */
function top_periods(): array
{
    return ['day' => 86400, 'week' => 604800, 'month' => 2592000, 'year' => 31536000, 'all' => 0];
}

/** Where clause and params for a Top period (404 on an unknown one); '' means no time limit. */
function top_scope(string $period): array
{
    $periods = top_periods();
    if (!isset($periods[$period])) not_found();
    return $periods[$period] > 0 ? ['created_at>?', [now() - $periods[$period]]] : ['', []];
}

function top_order(): string
{
    return '(like_count*3+reply_count*2+view_count/20.0) DESC, last_post_at DESC';
}

/** Period tabs under Top; $base is '/top' or '/c/<slug>/top'. */
function top_sub_tabs(string $period, string $base): string
{
    // spelled out so that lang:sync finds them: a label built with ucfirst() never reaches a language pack
    $labels = ['day' => t('Day'), 'week' => t('Week'), 'month' => t('Month'), 'year' => t('Year'), 'all' => t('All')];
    $items = [];
    foreach (array_keys(top_periods()) as $k) $items[$k] = ['label' => $labels[$k] ?? ucfirst($k), 'url' => url($base . '/' . $k), 'active' => $k === $period];
    return tabs($items, 'tabs tabs-sub');
}

/** Unread for a member: [join, where, params] — topics with posts they have not seen, last 30 days. */
function unread_scope(array $me): array
{
    $join = 'LEFT JOIN fb_topic_reads r ON r.topic_id=t.id AND r.user_id=' . (int)$me['id'];
    return [$join, '(r.topic_id IS NULL OR r.last_post_id<t.last_post_id) AND t.last_post_at>?', [max((int)$me['created_at'], now() - 86400 * 30)]];
}

function topic_list_page(): array
{
    return ['page' => get_int('page', 1, 1, 100000), 'per_page' => max(5, min(100, (int)setting('per_page', '25')))];
}

/**
 * Load a page of topics with users, categories and tags attached (batched, no N+1).
 * $where uses alias t for fb_topics. Returns ['topics'=>[], 'pagination'=>[]].
 */
function topic_list_fetch(string $where, array $params, string $order, array $p, bool $pinned_first = false, string $join = ''): array
{
    $visible = category_visible_ids();
    $conds = ['t.is_deleted=0'];
    if ($visible !== null) $conds[] = $visible === [] ? '0' : 't.category_id IN (' . implode(',', $visible) . ')';
    if ($where !== '') $conds[] = '(' . $where . ')';
    $sql_where = implode(' AND ', $conds);
    $total = (int)val("SELECT COUNT(*) FROM fb_topics t {$join} WHERE {$sql_where}", $params);
    $pg = paginate_calc($total, $p['page'], $p['per_page']);
    // callers may write "last_post_at DESC"; qualify with the t alias so joins stay unambiguous
    $order = preg_replace('/(?<![.\w])(is_pinned|pinned_at|last_post_at|created_at|like_count|reply_count|view_count|hot_score|id)\b/', 't.$1', $order) ?? $order;
    $rows = all("SELECT t.* FROM fb_topics t {$join} WHERE {$sql_where} ORDER BY {$order} LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
    return ['topics' => topic_list_attach($rows), 'pagination' => $pg];
}

/** Attach user, last_user, category, tags and (for members) unread flag to topic rows. */
function topic_list_attach(array $rows): array
{
    if ($rows === []) return [];
    $uids = [];
    foreach ($rows as $r) { $uids[] = (int)$r['user_id']; $uids[] = (int)$r['last_user_id']; }
    $users = users_by_ids($uids);
    $tags = tags_for_topics(array_column($rows, 'id'));
    $reads = [];
    if (uid() > 0) {
        $ids = array_map('intval', array_column($rows, 'id'));
        foreach (all('SELECT topic_id,last_post_id FROM fb_topic_reads WHERE user_id=? AND topic_id IN (' . sql_marks(count($ids)) . ')', array_merge([uid()], $ids)) as $r) $reads[(int)$r['topic_id']] = (int)$r['last_post_id'];
    }
    foreach ($rows as &$t) {
        $t['user'] = $users[(int)$t['user_id']] ?? null;
        $t['last_user'] = $users[(int)$t['last_user_id']] ?? null;
        $t['category'] = category_by_id((int)$t['category_id']);
        $t['tags'] = $tags[(int)$t['id']] ?? [];
        $t['unread'] = uid() > 0 && (int)$t['last_post_at'] > (int)(me()['created_at'] ?? 0) && (!isset($reads[(int)$t['id']]) || $reads[(int)$t['id']] < (int)$t['last_post_id']);
    }
    unset($t);
    return (array)hook('topic_list.rows', $rows, []);
}

/** Render a standard list page with tabs. */
function topic_list_page_render(string $title, array $list, string $active, callable $url_fn, string $sub_tabs = '', array $opts = []): never
{
    $main = view('topic_list', [
        'title' => $title,
        'tabs' => list_tabs($active),
        'sub_tabs' => $sub_tabs,
        'topics' => $list['topics'],
        'pagination' => pagination($list['pagination'], $url_fn),
        'heading' => $opts['heading'] ?? '',
        'empty' => $opts['empty'] ?? t('No topics yet.'),
    ]);
    page($title, $main, ['class' => 'page-list page-' . $active, 'top' => category_bar($active)] + $opts);
}

/**
 * Category bar at the top of list pages (page option 'top'; region main.categories, list): All + top-level categories, the
 * current one highlighted (a child page highlights its parent). Setting category_bar: mobile (default; the left column is
 * hidden there) | always | off. Icons appear only for categories that have one.
 */
function category_bar(string $active): string
{
    $mode = setting('category_bar', 'mobile');
    if ($mode === 'off' || $active === 'categories') return '';
    $cur = current_path();
    $here = str_starts_with($cur, '/c/') ? category_by_slug(substr($cur, 3)) : null;
    $here_id = $here !== null ? ((int)$here['parent_id'] > 0 ? (int)$here['parent_id'] : (int)$here['id']) : 0;
    $items = ['all' => ['label' => t('All'), 'url' => url('/'), 'active' => $here_id === 0, 'weight' => 0]];
    foreach (category_tree()[0] ?? [] as $i => $c) $items['c' . (int)$c['id']] = ['label' => $c['name'], 'url' => category_url($c), 'icon' => (string)($c['icon'] ?? ''), 'active' => (int)$c['id'] === $here_id, 'weight' => $i + 1];
    $items = region_list('main.categories', $items, ['active' => $active, 'category' => $here]);
    if (count($items) < 2) return '';
    $html = '';
    foreach ($items as $it) $html .= '<a class="cat-item' . (!empty($it['active']) ? ' active' : '') . '" href="' . h((string)$it['url']) . '">' . (!empty($it['icon']) && isset(icon_paths()[(string)$it['icon']]) ? icon((string)$it['icon']) : '') . '<span>' . h((string)$it['label']) . '</span></a>';
    return '<nav class="cat-bar' . ($mode === 'mobile' ? ' cat-bar-mobile' : '') . '" data-slot="main.categories">' . $html . '</nav>';
}

/** Tabs above topic lists (region main.tabs). Inside a category the tabs and New Topic stay in that category. */
function list_tabs(string $active, array $extra = [], ?array $category = null): string
{
    $base = $category !== null ? '/c/' . $category['slug'] : '';
    $items = [
        'latest' => ['label' => t('Latest'), 'url' => url($base !== '' ? $base : '/latest'), 'icon' => 'clock', 'active' => $active === 'latest'],
        'top' => ['label' => t('Top'), 'url' => url($base . '/top'), 'icon' => 'flame', 'active' => $active === 'top'],
    ];
    if (uid() > 0) $items['unread'] = ['label' => t('Unread'), 'url' => url($base . '/unread'), 'icon' => 'dot', 'active' => $active === 'unread'];
    $items += $extra;
    $ctx = ['active' => $active, 'category' => $category];
    $items = region_list('main.tabs', $items, $ctx);
    $new = url('/new-topic', $category !== null ? ['category' => (int)$category['id']] : []);
    $toolbar = region('main.toolbar', $ctx, uid() > 0 && can('post') ? '<a class="btn btn-primary" href="' . h($new) . '">' . icon('plus') . '<span>' . t('New Topic') . '</span></a>' : '');
    return '<div class="list-head" data-slot="main.tabs">' . tabs($items) . $toolbar . '</div>';
}

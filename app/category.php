<?php
/**
 * Categories.
 */

/** All categories keyed by id, ordered by sort. Cached per request. */
function categories(): array
{
    return request_cache('categories', static function (): array {
        $out = [];
        foreach (all('SELECT * FROM fb_categories ORDER BY sort,id') as $c) $out[(int)$c['id']] = $c;
        return $out;
    }) ?? [];
}

function category_by_id(int $id): ?array
{
    return categories()[$id] ?? null;
}

function category_by_slug(string $slug): ?array
{
    foreach (categories() as $c) if ($c['slug'] === $slug) return $c;
    return null;
}

function category_can_view(array $c): bool
{
    if ((int)$c['is_hidden'] === 1 && !is_admin()) return false;
    return group_allowed((string)$c['view_groups']);
}

function category_can_post(array $c): bool
{
    if (uid() === 0 || !can('post')) return false;
    if (is_admin()) return true;
    return group_allowed((string)$c['post_groups'], false);
}

/** Ids of categories the current user may see; null means "no restriction". */
function category_visible_ids(): ?array
{
    return request_cache('category_visible', static function (): ?array {
        $all = categories();
        $ok = array_keys(array_filter($all, 'category_can_view'));
        return count($ok) === count($all) ? null : $ok;
    });
}

/** Visible categories grouped as parent => children for menus. */
function category_tree(): array
{
    $tree = [];
    foreach (categories() as $c) {
        if (!category_can_view($c)) continue;
        $tree[(int)$c['parent_id']][] = $c;
    }
    return $tree;
}

function category_refresh_stats(int $id): void
{
    $t = (int)val('SELECT COUNT(*) FROM fb_topics WHERE category_id=? AND is_deleted=0', [$id]);
    $p = (int)val('SELECT COALESCE(SUM(reply_count),0) FROM fb_topics WHERE category_id=? AND is_deleted=0', [$id]);
    $last = (int)val('SELECT id FROM fb_topics WHERE category_id=? AND is_deleted=0 ORDER BY last_post_at DESC LIMIT 1', [$id]);
    db_update('fb_categories', ['topic_count' => $t, 'post_count' => $p, 'last_topic_id' => $last], 'id=?', [$id]);
    request_cache('categories', null, true);
}

/** GET /categories */
function category_index(): never
{
    $tree = category_tree();
    $last_ids = [];
    foreach (categories() as $c) if ((int)$c['last_topic_id'] > 0) $last_ids[] = (int)$c['last_topic_id'];
    $last_topics = rows_by_ids('fb_topics', $last_ids, 'id,title,slug,last_post_at,last_user_id,is_deleted');
    $users = users_by_ids(array_column($last_topics, 'last_user_id'));
    $main = view('categories', ['tree' => $tree, 'last_topics' => $last_topics, 'users' => $users, 'tabs' => list_tabs('categories', ['categories' => ['label' => t('Categories'), 'url' => url('/categories'), 'icon' => 'folder', 'active' => true]])]);
    page(t('Categories'), $main, ['class' => 'page-categories']);
}

/** GET /c/{slug} */
function category_view(string $slug): never
{
    $c = category_by_slug($slug);
    if ($c === null || !category_can_view($c)) not_found();
    $p = topic_list_page();
    $ids = [(int)$c['id']];
    foreach (categories() as $child) if ((int)$child['parent_id'] === (int)$c['id'] && category_can_view($child)) $ids[] = (int)$child['id'];
    $list = topic_list_fetch('category_id IN (' . implode(',', $ids) . ')', [], 'is_pinned DESC, last_post_at DESC', $p, $p['page'] === 1);
    $children = array_filter(categories(), static fn(array $x): bool => (int)$x['parent_id'] === (int)$c['id'] && category_can_view($x));
    $heading = view('category_head', ['category' => $c, 'children' => $children, 'can_post' => category_can_post($c)]);
    $extra = ['category' => ['label' => $c['name'], 'url' => category_url($c), 'icon' => 'folder', 'active' => true]];
    $main = view('topic_list', [
        'title' => $c['name'], 'tabs' => list_tabs('category', $extra), 'sub_tabs' => '', 'topics' => $list['topics'],
        'pagination' => pagination($list['pagination'], static fn(int $n): string => url('/c/' . $c['slug'], $n > 1 ? ['page' => $n] : [])),
        'heading' => $heading, 'empty' => t('No topics in this category yet.'),
    ]);
    page($c['name'], $main, ['class' => 'page-list page-category', 'description' => (string)$c['description'], 'breadcrumbs' => [[t('Categories'), url('/categories')], [$c['name'], '']]]);
}

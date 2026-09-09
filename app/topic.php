<?php
/**
 * Topics and posts: viewing, creating, replying, editing, moderating, likes, bookmarks.
 */

function topic_by_id(int $id): ?array
{
    if ($id <= 0) return null;
    return request_cache('topic_' . $id, static fn(): ?array => one('SELECT * FROM fb_topics WHERE id=?', [$id]));
}

function post_by_id(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM fb_posts WHERE id=?', [$id]) : null;
}

function topic_title_valid(string $title): bool
{
    $len = mb_strlen($title);
    return $len >= 3 && $len <= 200;
}

function post_body_valid(string $body): bool
{
    $len = mb_strlen(trim($body));
    return $len >= 2 && $len <= 65535;
}

/** Create a topic with its first post. Returns topic id. */
function topic_create(int $category_id, int $user_id, string $title, string $body, array $tags = [], bool $pinned = false): int
{
    return tx(static function () use ($category_id, $user_id, $title, $body, $tags, $pinned): int {
        $data = hook('topic.before_save', ['category_id' => $category_id, 'user_id' => $user_id, 'title' => $title, 'body' => $body, 'tags' => $tags], []);
        $tid = db_insert('fb_topics', [
            'category_id' => (int)$data['category_id'], 'user_id' => $user_id, 'title' => $data['title'], 'slug' => slugify($data['title']),
            'is_pinned' => $pinned ? 1 : 0, 'pinned_at' => $pinned ? now() : 0, 'created_at' => now(), 'updated_at' => now(), 'last_post_at' => now(), 'last_user_id' => $user_id, 'meta' => '{}',
        ]);
        $pid = db_insert('fb_posts', [
            'topic_id' => $tid, 'user_id' => $user_id, 'floor' => 0, 'body' => $data['body'], 'body_html' => md($data['body']),
            'created_at' => now(), 'created_ip' => client_ip(), 'meta' => '{}',
        ]);
        db_update('fb_topics', ['first_post_id' => $pid, 'last_post_id' => $pid], 'id=?', [$tid]);
        topic_set_tags($tid, (array)$data['tags']);
        db_increment('fb_users', 'topic_count', 1, 'id=?', [$user_id]);
        db_update('fb_users', ['last_post_at' => now()], 'id=?', [$user_id]);
        category_refresh_stats((int)$data['category_id']);
        search_index_post($pid, $tid, $data['title'], $data['body']);
        attachments_link_to_post($pid, $user_id, $data['body']);
        notify_mentions($tid, $pid, $data['body'], $user_id);
        fire('topic.after_save', ['topic_id' => $tid, 'post_id' => $pid, 'new' => true]);
        points_award($user_id, 'topic', $tid);
        return $tid;
    });
}

/** Add a reply. Returns post id. */
function post_create(array $topic, int $user_id, string $body, int $reply_to = 0): int
{
    return tx(static function () use ($topic, $user_id, $body, $reply_to): int {
        $data = hook('post.before_save', ['body' => $body, 'reply_to_id' => $reply_to], ['topic' => $topic]);
        $floor = (int)val('SELECT COALESCE(MAX(floor),0)+1 FROM fb_posts WHERE topic_id=?', [(int)$topic['id']]);
        $pid = db_insert('fb_posts', [
            'topic_id' => (int)$topic['id'], 'user_id' => $user_id, 'floor' => $floor, 'reply_to_id' => (int)$data['reply_to_id'],
            'body' => $data['body'], 'body_html' => md($data['body']), 'created_at' => now(), 'created_ip' => client_ip(), 'meta' => '{}',
        ]);
        db_update('fb_topics', ['last_post_id' => $pid, 'last_post_at' => now(), 'last_user_id' => $user_id, 'updated_at' => now()], 'id=?', [(int)$topic['id']]);
        db_increment('fb_topics', 'reply_count', 1, 'id=?', [(int)$topic['id']]);
        db_increment('fb_users', 'post_count', 1, 'id=?', [$user_id]);
        db_update('fb_users', ['last_post_at' => now()], 'id=?', [$user_id]);
        db_increment('fb_categories', 'post_count', 1, 'id=?', [(int)$topic['category_id']]);
        db_update('fb_categories', ['last_topic_id' => (int)$topic['id']], 'id=?', [(int)$topic['category_id']]);
        search_index_post($pid, (int)$topic['id'], (string)$topic['title'], $data['body']);
        attachments_link_to_post($pid, $user_id, $data['body']);
        notify_reply($topic, $pid, $user_id, (int)$data['reply_to_id']);
        notify_mentions((int)$topic['id'], $pid, $data['body'], $user_id);
        topic_mark_read((int)$topic['id'], $pid);
        fire('post.after_save', ['topic_id' => (int)$topic['id'], 'post_id' => $pid, 'new' => true]);
        points_award($user_id, 'reply', $pid);
        request_cache('categories', null, true);
        return $pid;
    });
}

function topic_stats_refresh(int $tid): void
{
    $t = topic_by_id($tid);
    if ($t === null) return;
    $last = one('SELECT id,user_id,created_at FROM fb_posts WHERE topic_id=? AND is_deleted=0 ORDER BY id DESC LIMIT 1', [$tid]);
    $replies = (int)val('SELECT COUNT(*) FROM fb_posts WHERE topic_id=? AND is_deleted=0 AND floor>0', [$tid]);
    $likes = (int)val('SELECT COALESCE(SUM(like_count),0) FROM fb_posts WHERE topic_id=? AND is_deleted=0', [$tid]);
    db_update('fb_topics', [
        'reply_count' => $replies, 'like_count' => $likes,
        'last_post_id' => (int)($last['id'] ?? 0), 'last_user_id' => (int)($last['user_id'] ?? 0), 'last_post_at' => (int)($last['created_at'] ?? $t['created_at']),
    ], 'id=?', [$tid]);
    request_cache('topic_' . $tid, null, true);
    category_refresh_stats((int)$t['category_id']);
}

function topic_mark_read(int $tid, int $last_post_id): void
{
    if (uid() === 0) return;
    db_upsert('fb_topic_reads', ['user_id' => uid(), 'topic_id' => $tid, 'last_post_id' => $last_post_id, 'read_at' => now()], ['user_id', 'topic_id']);
}

function can_manage_topic(array $topic): bool
{
    return is_mod() || (uid() > 0 && (int)$topic['user_id'] === uid() && can('delete_own'));
}

/** The topic of a post when the current user may see it (topic not deleted unless mod, category viewable), else null. */
function post_topic_visible(array $post): ?array
{
    $topic = topic_by_id((int)$post['topic_id']);
    if ($topic === null || ((int)$topic['is_deleted'] === 1 && !is_mod())) return null;
    $cat = category_by_id((int)$topic['category_id']);
    return $cat !== null && !category_can_view($cat) ? null : $topic;
}

function can_edit_post(array $post): bool
{
    if (is_mod()) return true;
    return uid() > 0 && (int)$post['user_id'] === uid() && can('edit_own');
}

/* ---------------------------------------------------------------- pages */

/** GET /t/{slug}-{id} */
function topic_view(string $id): never
{
    $topic = topic_by_id((int)$id);
    if ($topic === null || ((int)$topic['is_deleted'] === 1 && !is_mod())) not_found();
    $cat = category_by_id((int)$topic['category_id']);
    if ($cat !== null && !category_can_view($cat)) not_found();
    if (($why = topic_access_denied($topic)) !== '') topic_no_access($topic, $cat, $why);
    $per = max(5, min(100, (int)setting('posts_per_page', '20')));
    $show_deleted = is_mod() ? '' : ' AND is_deleted=0';
    $total = (int)val("SELECT COUNT(*) FROM fb_posts WHERE topic_id=?{$show_deleted}", [(int)$topic['id']]);
    $pg = paginate_calc($total, get_int('page', 1, 1, 100000), $per);
    $posts = all("SELECT * FROM fb_posts WHERE topic_id=?{$show_deleted} ORDER BY id LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [(int)$topic['id']]);
    $posts = posts_attach($posts, $topic);
    db_increment('fb_topics', 'view_count', 1, 'id=?', [(int)$topic['id']]);
    if ($posts !== []) topic_mark_read((int)$topic['id'], (int)end($posts)['id']);
    $topic['category'] = $cat;
    $topic['tags'] = tags_for_topics([(int)$topic['id']])[(int)$topic['id']] ?? [];
    $topic['user'] = $posts[0]['user'] ?? user_by_id((int)$topic['user_id']);
    $topic['bookmarked'] = uid() > 0 && (bool)val('SELECT 1 FROM fb_bookmarks WHERE user_id=? AND topic_id=?', [uid(), (int)$topic['id']]);
    $topic = hook('topic.view', $topic, ['posts' => $posts]);
    $main = view('topic', [
        'topic' => $topic, 'posts' => $posts, 'page' => $pg,
        'pagination' => pagination($pg, static fn(int $n): string => topic_url($topic, $n)),
        'can_reply' => uid() > 0 && can('reply') && ((int)$topic['is_locked'] === 0 || is_mod()) && topic_reply_denied($topic) === '' && ($hold = post_hold(me() ?? [])) === [],
        'reply_denied' => uid() > 0 ? topic_reply_denied($topic) : '',
        'hold' => uid() > 0 ? ($hold ?? post_hold(me() ?? [])) : [],
    ]);
    $right = view('sidebar_topic', ['topic' => $topic, 'cards' => region_list('topic.sidebar.cards', ['author' => view('card_author', ['user' => $topic['user']]), 'related' => view('card_related', ['topics' => topic_related($topic)])], ['topic' => $topic])]);
    page($topic['title'], $main, ['class' => 'page-topic', 'right' => $right, 'description' => md_excerpt((string)($posts[0]['body'] ?? '')), 'canonical' => absolute_url('/t/' . $topic['slug'] . '-' . $topic['id'], $pg['page'] > 1 ? ['page' => $pg['page']] : []), 'breadcrumbs' => $cat ? [[$cat['name'], category_url($cat)]] : []]);
}

/** Why the visitor may not reply to this topic, '' when they may (hook topic.can_reply); moderators and the author always pass. */
function topic_reply_denied(array $topic): string
{
    if (is_mod() || (int)$topic['user_id'] === uid()) return '';
    return (string)hook('topic.can_reply', '', ['topic' => $topic]);
}

/**
 * Why the visitor may not read this topic, '' when they may. Plugins answer with the reason to show
 * (hook topic.access); moderators and the author always pass. The same reason keeps a topic out of search and the feeds.
 */
function topic_access_denied(array $topic): string
{
    if (is_mod() || (int)$topic['user_id'] === uid()) return '';
    return (string)hook('topic.access', '', ['topic' => $topic]);
}

/** The page a refused visitor gets: the title stays readable, the body is the reason. */
function topic_no_access(array $topic, ?array $cat, string $why): never
{
    $body = '<article class="topic-page"><header class="topic-head"><h1 class="topic-title" dir="auto">' . h((string)$topic['title']) . '</h1></header>'
        . '<div class="empty">' . icon('lock') . '<p>' . h($why) . '</p>'
        . (uid() <= 0 ? '<p><a class="btn btn-primary" href="' . h(url('/login', ['back' => current_path()])) . '">' . t('Sign in') . '</a></p>' : '')
        . region('topic.no_access', ['topic' => $topic, 'reason' => $why]) . '</div></article>';
    page((string)$topic['title'], $body, ['class' => 'page-topic', 'right' => false, 'robots' => 'noindex', 'breadcrumbs' => $cat ? [[$cat['name'], category_url($cat)]] : []]);
}

/** Attach user rows, liked flag and reply-to previews to posts (batched). */
function posts_attach(array $posts, array $topic): array
{
    if ($posts === []) return [];
    $users = users_by_ids(array_merge(array_column($posts, 'user_id'), array_column($posts, 'edited_by')));
    $liked = [];
    if (uid() > 0) {
        $ids = array_map('intval', array_column($posts, 'id'));
        $liked = array_flip(array_map('intval', col('SELECT post_id FROM fb_likes WHERE user_id=? AND post_id IN (' . sql_marks(count($ids)) . ')', array_merge([uid()], $ids))));
    }
    $reply_ids = array_filter(array_map('intval', array_column($posts, 'reply_to_id')));
    $parents = $reply_ids !== [] ? rows_by_ids('fb_posts', $reply_ids, 'id,user_id,floor,body') : [];
    $parent_users = users_by_ids(array_column($parents, 'user_id'));
    foreach ($posts as &$p) {
        $p['user'] = $users[(int)$p['user_id']] ?? null;
        $p['liked'] = isset($liked[(int)$p['id']]);
        $p['editor'] = (int)$p['edited_by'] > 0 ? ($users[(int)$p['edited_by']] ?? null) : null;
        $parent = $parents[(int)$p['reply_to_id']] ?? null;
        $p['reply_to'] = $parent ? ['id' => (int)$parent['id'], 'floor' => (int)$parent['floor'], 'user' => $parent_users[(int)$parent['user_id']] ?? null, 'excerpt' => md_excerpt((string)$parent['body'], 100)] : null;
    }
    unset($p);
    return (array)hook('topic.posts', $posts, ['topic' => $topic]);
}

function topic_related(array $topic): array
{
    $rows = all('SELECT id,title,slug,reply_count,last_post_at FROM fb_topics WHERE category_id=? AND id<>? AND is_deleted=0 ORDER BY last_post_at DESC LIMIT 6', [(int)$topic['category_id'], (int)$topic['id']]);
    return $rows;
}

/** GET|POST /new-topic */
function topic_new(): never
{
    $me = need_login();
    if (!can('post')) forbidden(t('Your group cannot create topics.'));
    $cats = array_filter(categories(), 'category_can_post');
    if ($cats === []) forbidden(t('There is no category you can post in.'));
    if (is_post()) {
        check_csrf();
        $title = post_str('title', 200);
        $body = post_str('body');
        $cat = category_by_id(post_int('category_id'));
        $tags = tags_parse(post_str('tags', 200));
        if (!topic_title_valid($title)) fail(t('Title must be between 3 and 200 characters.'));
        if (!post_body_valid($body)) fail(t('Post body is too short.'));
        if ($cat === null || !category_can_post($cat)) fail(t('Please choose a category.'));
        if (($hold = post_hold($me)) !== []) fail((string)$hold['message']);
        $tid = topic_create((int)$cat['id'], (int)$me['id'], $title, $body, $tags);
        redirect(topic_url(topic_by_id($tid)));
    }
    if (($hold = post_hold($me)) !== []) { // do not let them write a whole topic and lose it to a limit
        page(t('New Topic'), '<div class="list-head"><h1 style="margin:0">' . t('New Topic') . '</h1></div>' . post_hold_notice($hold), ['class' => 'page-compose', 'right' => false]);
    }
    $pre = category_by_id(get_int('category', 0));
    $vals = (array)hook('composer.values', ['title' => '', 'body' => '', 'category_id' => (int)($pre['id'] ?? 0), 'tags' => ''], ['mode' => 'new']); // a drafts plugin fills these
    page(t('New Topic'), view('topic_form', ['topic' => null, 'post' => null, 'categories' => $cats, 'category_id' => (int)$vals['category_id'], 'tags' => (string)$vals['tags'], 'title' => (string)$vals['title'], 'body' => (string)$vals['body'], 'action' => url('/new-topic')]), ['class' => 'page-compose', 'right' => false]);
}

/** POST /t/{id}/reply */
function topic_reply(string $id): never
{
    $me = need_login();
    require_post();
    $topic = topic_by_id((int)$id);
    if ($topic === null || (int)$topic['is_deleted'] === 1) not_found();
    $cat = category_by_id((int)$topic['category_id']);
    if ($cat !== null && !category_can_view($cat)) not_found();
    if (!can('reply')) fail(t('Your group cannot reply.'));
    if ((int)$topic['is_locked'] === 1 && !is_mod()) fail(t('This topic is locked.'));
    if (($why = topic_access_denied($topic)) !== '') fail($why);
    if (($why = topic_reply_denied($topic)) !== '') fail($why);
    $body = post_str('body');
    if (!post_body_valid($body)) fail(t('Reply is too short.'));
    if (($hold = post_hold($me)) !== []) fail((string)$hold['message']);
    $reply_to = post_int('reply_to');
    if ($reply_to > 0 && (int)(val('SELECT topic_id FROM fb_posts WHERE id=?', [$reply_to]) ?? 0) !== (int)$topic['id']) $reply_to = 0;
    $pid = post_create($topic, (int)$me['id'], $body, $reply_to);
    $total = (int)val('SELECT COUNT(*) FROM fb_posts WHERE topic_id=? AND is_deleted=0', [(int)$topic['id']]);
    $last_page = max(1, (int)ceil($total / max(5, (int)setting('posts_per_page', '20'))));
    redirect(topic_url($topic, $last_page, $pid));
}

/** GET|POST /t/{id}/edit — title, category, tags and first post. */
function topic_edit(string $id): never
{
    $me = need_login();
    $topic = topic_by_id((int)$id);
    if ($topic === null) not_found();
    $post = post_by_id((int)$topic['first_post_id']);
    if ($post === null || !can_edit_post($post)) forbidden();
    $cats = is_mod() ? categories() : array_filter(categories(), 'category_can_post');
    if (is_post()) {
        check_csrf();
        $title = post_str('title', 200);
        $body = post_str('body');
        $cat = category_by_id(post_int('category_id')) ?? category_by_id((int)$topic['category_id']);
        if (!topic_title_valid($title)) fail(t('Title must be between 3 and 200 characters.'));
        if (!post_body_valid($body)) fail(t('Post body is too short.'));
        tx(static function () use ($topic, $post, $me, $title, $body, $cat): void {
            $old_cat = (int)$topic['category_id'];
            db_update('fb_topics', ['title' => $title, 'slug' => slugify($title), 'category_id' => (int)$cat['id'], 'updated_at' => now()], 'id=?', [(int)$topic['id']]);
            post_update($post, $body, (int)$me['id']);
            topic_set_tags((int)$topic['id'], tags_parse(post_str('tags', 200)));
            search_update_title((int)$topic['id'], $title);
            if ($old_cat !== (int)$cat['id']) { category_refresh_stats($old_cat); category_refresh_stats((int)$cat['id']); }
            fire('topic.after_save', ['topic_id' => (int)$topic['id'], 'post_id' => (int)$post['id'], 'new' => false]);
        });
        request_cache('topic_' . $topic['id'], null, true);
        redirect(topic_url(topic_by_id((int)$topic['id'])));
    }
    $tags = implode(', ', array_column(tags_for_topics([(int)$topic['id']])[(int)$topic['id']] ?? [], 'name'));
    page(t('Edit Topic'), view('topic_form', ['topic' => $topic, 'post' => $post, 'categories' => $cats, 'category_id' => (int)$topic['category_id'], 'tags' => $tags, 'action' => url('/t/' . $topic['id'] . '/edit')]), ['class' => 'page-compose', 'right' => false]);
}

function post_update(array $post, string $body, int $editor_id): void
{
    $body = (string)hook('post.before_update', $body, ['post' => $post]);
    db_update('fb_posts', ['body' => $body, 'body_html' => md($body), 'edit_count' => (int)$post['edit_count'] + 1, 'edited_at' => now(), 'edited_by' => $editor_id], 'id=?', [(int)$post['id']]);
    $topic = topic_by_id((int)$post['topic_id']);
    search_index_post((int)$post['id'], (int)$post['topic_id'], (string)($topic['title'] ?? ''), $body);
    attachments_link_to_post((int)$post['id'], (int)$post['user_id'], $body);
    fire('post.after_save', ['topic_id' => (int)$post['topic_id'], 'post_id' => (int)$post['id'], 'new' => false]);
}

/** POST /t/{id}/action  action=pin|unpin|lock|unlock|delete|restore|move (pin on a pinned topic moves it to the top of the pinned ones) */
function topic_action(string $id): never
{
    need_login();
    require_post();
    $topic = topic_by_id((int)$id);
    if ($topic === null) not_found();
    $action = post_str('action', 20);
    $own_ok = $action === 'delete' && can_manage_topic($topic);
    if (!is_mod() && !$own_ok) forbidden();
    switch ($action) {
        case 'pin': db_update('fb_topics', ['is_pinned' => 1, 'pinned_at' => now()], 'id=?', [(int)$topic['id']]); break;
        case 'unpin': db_update('fb_topics', ['is_pinned' => 0, 'pinned_at' => 0], 'id=?', [(int)$topic['id']]); break;
        case 'lock': db_update('fb_topics', ['is_locked' => 1], 'id=?', [(int)$topic['id']]); break;
        case 'unlock': db_update('fb_topics', ['is_locked' => 0], 'id=?', [(int)$topic['id']]); break;
        case 'delete':
            db_update('fb_topics', ['is_deleted' => 1], 'id=?', [(int)$topic['id']]);
            search_delete_topic((int)$topic['id']);
            category_refresh_stats((int)$topic['category_id']);
            fire('topic.after_delete', ['topic_id' => (int)$topic['id']]);
            flash(t('Topic deleted.'));
            redirect(url('/'));
        case 'restore':
            db_update('fb_topics', ['is_deleted' => 0], 'id=?', [(int)$topic['id']]);
            category_refresh_stats((int)$topic['category_id']);
            foreach (all('SELECT id,body FROM fb_posts WHERE topic_id=? AND is_deleted=0', [(int)$topic['id']]) as $p) search_index_post((int)$p['id'], (int)$topic['id'], (string)$topic['title'], (string)$p['body']);
            break;
        case 'move':
            $cat = category_by_id(post_int('category_id'));
            if ($cat === null) fail(t('Please choose a category.'));
            db_update('fb_topics', ['category_id' => (int)$cat['id']], 'id=?', [(int)$topic['id']]);
            category_refresh_stats((int)$topic['category_id']);
            category_refresh_stats((int)$cat['id']);
            break;
        default:
            fail(t('Unknown action.'));
    }
    fire('topic.after_action', ['topic_id' => (int)$topic['id'], 'action' => $action]);
    request_cache('topic_' . $topic['id'], null, true);
    redirect(topic_url($topic));
}

/** POST /t/{id}/bookmark — toggle */
function topic_bookmark(string $id): never
{
    $me = need_login();
    require_post();
    $topic = topic_by_id((int)$id);
    if ($topic === null) not_found();
    $exists = (bool)val('SELECT 1 FROM fb_bookmarks WHERE user_id=? AND topic_id=?', [(int)$me['id'], (int)$topic['id']]);
    if ($exists) db_delete('fb_bookmarks', 'user_id=? AND topic_id=?', [(int)$me['id'], (int)$topic['id']]);
    else db_insert_ignore('fb_bookmarks', ['user_id' => (int)$me['id'], 'topic_id' => (int)$topic['id'], 'created_at' => now()]);
    if (is_ajax()) json_ok(['bookmarked' => !$exists]);
    redirect(topic_url($topic));
}

/** GET /post/{id} — jump to the page containing the post */
function post_permalink(string $id): never
{
    $post = post_by_id((int)$id);
    $topic = $post ? post_topic_visible($post) : null;
    if ($post === null || $topic === null) not_found();
    $before = (int)val('SELECT COUNT(*) FROM fb_posts WHERE topic_id=? AND is_deleted=0 AND id<?', [(int)$topic['id'], (int)$post['id']]);
    $page = (int)floor($before / max(5, (int)setting('posts_per_page', '20'))) + 1;
    redirect(topic_url($topic, $page, (int)$post['id']));
}

/** GET|POST /post/{id}/edit */
function post_edit(string $id): never
{
    $me = need_login();
    $post = post_by_id((int)$id);
    $topic = $post ? post_topic_visible($post) : null;
    if ($post === null || $topic === null) not_found();
    if (!can_edit_post($post)) forbidden();
    if ((int)$post['floor'] === 0) redirect(url('/t/' . $topic['id'] . '/edit'));
    if (is_post()) {
        check_csrf();
        $body = post_str('body');
        if (!post_body_valid($body)) fail(t('Reply is too short.'));
        post_update($post, $body, (int)$me['id']);
        redirect(url('/post/' . $post['id']));
    }
    page(t('Edit Reply'), view('post_form', ['post' => $post, 'topic' => $topic, 'action' => url('/post/' . $post['id'] . '/edit')]), ['class' => 'page-compose', 'right' => false]);
}

/** POST /post/{id}/like — toggle */
function post_like(string $id): never
{
    $me = need_login();
    require_post();
    $post = post_by_id((int)$id);
    if ($post === null || (int)$post['is_deleted'] === 1 || post_topic_visible($post) === null) not_found();
    $liked = (bool)val('SELECT 1 FROM fb_likes WHERE user_id=? AND post_id=?', [(int)$me['id'], (int)$post['id']]);
    tx(static function () use ($liked, $me, $post): void {
        if ($liked) {
            db_delete('fb_likes', 'user_id=? AND post_id=?', [(int)$me['id'], (int)$post['id']]);
            db_increment('fb_posts', 'like_count', -1, 'id=? AND like_count>0', [(int)$post['id']]);
            db_increment('fb_topics', 'like_count', -1, 'id=? AND like_count>0', [(int)$post['topic_id']]);
            db_increment('fb_users', 'like_count', -1, 'id=? AND like_count>0', [(int)$post['user_id']]);
        } else {
            if (!db_insert_ignore('fb_likes', ['user_id' => (int)$me['id'], 'post_id' => (int)$post['id'], 'topic_id' => (int)$post['topic_id'], 'created_at' => now()])) return;
            db_increment('fb_posts', 'like_count', 1, 'id=?', [(int)$post['id']]);
            db_increment('fb_topics', 'like_count', 1, 'id=?', [(int)$post['topic_id']]);
            db_increment('fb_users', 'like_count', 1, 'id=?', [(int)$post['user_id']]);
            notify_like($post, (int)$me['id']);
        }
    });
    $count = (int)val('SELECT like_count FROM fb_posts WHERE id=?', [(int)$post['id']]);
    fire('post.after_like', ['post_id' => (int)$post['id'], 'liked' => !$liked]);
    if (!$liked) { points_award((int)$post['user_id'], 'like', (int)$post['id']); points_award((int)$me['id'], 'liked', (int)$post['id']); }
    else points_revoke((int)$post['user_id'], 'like', (int)$post['id'], 'unlike');
    if (is_ajax()) json_ok(['liked' => !$liked, 'count' => $count]);
    redirect(url('/post/' . $post['id']));
}

/** POST /post/{id}/delete — soft delete (mods can restore with action=restore) */
function post_delete(string $id): never
{
    need_login();
    require_post();
    $post = post_by_id((int)$id);
    $topic = $post ? post_topic_visible($post) : null;
    if ($post === null || $topic === null) not_found();
    if ((int)$post['floor'] === 0) fail(t('Delete the topic instead.'));
    $restore = post_str('action', 20) === 'restore';
    if ($restore ? !is_mod() : !can_edit_post($post)) forbidden();
    db_update('fb_posts', ['is_deleted' => $restore ? 0 : 1], 'id=?', [(int)$post['id']]);
    if ($restore) search_index_post((int)$post['id'], (int)$topic['id'], (string)$topic['title'], (string)$post['body']);
    else search_delete_post((int)$post['id']);
    topic_stats_refresh((int)$topic['id']);
    fire('post.after_delete', ['post_id' => (int)$post['id'], 'restored' => $restore]);
    flash($restore ? t('Reply restored.') : t('Reply deleted.'));
    redirect(topic_url($topic));
}

/** GET /post/{id}/raw — markdown source for quoting */
function post_raw(string $id): never
{
    $post = post_by_id((int)$id);
    if ($post === null || (int)$post['is_deleted'] === 1 || post_topic_visible($post) === null) json_error('not found', 404);
    $user = user_by_id((int)$post['user_id']);
    json_ok(['body' => $post['body'], 'username' => $user['username'] ?? '', 'floor' => (int)$post['floor']]);
}

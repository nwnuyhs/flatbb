<?php
/**
 * GET /search?q=
 */
function search_page(): never
{
    $q = get_str('q', 200);
    $page = get_int('page', 1, 1, 1000);
    $results = [];
    $pg = paginate_calc(0, 1, 20);
    if ($q !== '') {
        $r = search_query($q, $page, 20);
        $pg = paginate_calc($r['total'], $page, 20);
        $post_ids = array_map('intval', array_column($r['rows'], 'post_id'));
        $posts = rows_by_ids('fb_posts', $post_ids, 'id,topic_id,user_id,floor,body,created_at,is_deleted');
        $topics = rows_by_ids('fb_topics', array_column($posts, 'topic_id'), 'id,user_id,title,slug,category_id,reply_count,is_deleted,meta'); // meta and user_id: plugins filtering the results need the topic's own rules
        $users = users_by_ids(array_column($posts, 'user_id'));
        $visible = category_visible_ids();
        foreach ($post_ids as $pid) {
            $p = $posts[$pid] ?? null;
            $t = $p ? ($topics[(int)$p['topic_id']] ?? null) : null;
            if ($p === null || $t === null || (int)$p['is_deleted'] === 1 || (int)$t['is_deleted'] === 1) continue;
            if ($visible !== null && !in_array((int)$t['category_id'], $visible, true)) continue;
            $results[] = ['post' => $p, 'topic' => $t, 'user' => $users[(int)$p['user_id']] ?? null, 'category' => category_by_id((int)$t['category_id']), 'snippet' => search_snippet((string)$p['body'], $q)];
        }
    }
    $results = array_values((array)hook('search.results', $results, ['q' => $q])); // plugins drop what this visitor may not read
    $main = view('search', ['q' => $q, 'results' => $results, 'total' => $pg['total'], 'pagination' => pagination($pg, static fn(int $n): string => url('/search', ['q' => $q, 'page' => $n]))]);
    page($q !== '' ? t('Search: %s', $q) : t('Search'), $main, ['class' => 'page-search', 'robots' => 'noindex']);
}

/** Excerpt around the first matching term, with <mark> highlights (safe HTML). */
function search_snippet(string $body, string $q): string
{
    $text = md_excerpt($body, 5000);
    $terms = array_filter(preg_split('/\s+/', mb_strtolower($q)) ?: [], static fn(string $t): bool => mb_strlen($t) >= 2);
    $pos = null;
    foreach ($terms as $t) {
        $p = mb_stripos($text, $t);
        if ($p !== false && ($pos === null || $p < $pos)) $pos = $p;
    }
    $start = max(0, ($pos ?? 0) - 60);
    $snippet = ($start > 0 ? '…' : '') . cut(mb_substr($text, $start), 220);
    $html = h($snippet);
    foreach ($terms as $t) {
        $html = preg_replace('/(' . preg_quote(h($t), '/') . ')/iu', '<mark>$1</mark>', $html) ?? $html;
    }
    return $html;
}

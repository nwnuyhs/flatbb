<?php
/**
 * Full-text search. One row per post in fb_search (title + body plain text).
 * SQLite: FTS5 virtual table fb_search_fts when available, else LIKE on fb_search.
 * MySQL: FULLTEXT index on fb_search (InnoDB, 5.7+), else LIKE.
 */

function search_backend(): string
{
    return request_cache('search_backend', static function (): string {
        if (db_is_mysql()) return db_index_exists('fb_search', 'ft_search') ? 'mysql' : 'like';
        return db_table_exists('fb_search_fts') ? 'fts5' : 'like';
    }) ?? 'like';
}

/** Create the SQLite FTS5 table when the extension is available. Called from schema_install(). */
function search_index_install(): void
{
    if (db_is_mysql() || db_table_exists('fb_search_fts')) return;
    try {
        q("CREATE VIRTUAL TABLE fb_search_fts USING fts5(post_id UNINDEXED, topic_id UNINDEXED, title, body, tokenize='unicode61')");
    } catch (Throwable) {
        // FTS5 missing; LIKE fallback will be used.
    }
    request_cache('search_backend', null, true);
}

function search_index_post(int $post_id, int $topic_id, string $title, string $body): void
{
    $plain = md_excerpt($body, 20000);
    db_upsert('fb_search', ['post_id' => $post_id, 'topic_id' => $topic_id, 'title' => $title, 'body' => $plain], ['post_id']);
    if (search_backend() === 'fts5') {
        q('DELETE FROM fb_search_fts WHERE post_id=?', [$post_id]);
        q('INSERT INTO fb_search_fts (post_id,topic_id,title,body) VALUES (?,?,?,?)', [$post_id, $topic_id, $title, $plain]);
    }
}

function search_delete_post(int $post_id): void
{
    db_delete('fb_search', 'post_id=?', [$post_id]);
    if (search_backend() === 'fts5') q('DELETE FROM fb_search_fts WHERE post_id=?', [$post_id]);
}

function search_delete_topic(int $topic_id): void
{
    db_delete('fb_search', 'topic_id=?', [$topic_id]);
    if (search_backend() === 'fts5') q('DELETE FROM fb_search_fts WHERE topic_id=?', [$topic_id]);
}

/** Retitle every indexed post of a topic. */
function search_update_title(int $topic_id, string $title): void
{
    db_update('fb_search', ['title' => $title], 'topic_id=?', [$topic_id]);
    if (search_backend() === 'fts5') q('UPDATE fb_search_fts SET title=? WHERE topic_id=?', [$title, $topic_id]);
}

/**
 * Returns ['total' => n, 'rows' => [['post_id'=>..,'topic_id'=>..], ...]].
 * $filters: ['topic_id' => int, 'user_id' => int] (user filter joins fb_posts).
 */
function search_query(string $q, int $page = 1, int $per_page = 20): array
{
    $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
    if (mb_strlen($q) < 2) return ['total' => 0, 'rows' => []];
    $offset = max(0, $page - 1) * $per_page;
    $backend = search_backend();
    if ($backend === 'fts5') {
        $terms = array_filter(preg_split('/\s+/', $q) ?: []);
        $match = implode(' ', array_map(static fn(string $t): string => '"' . str_replace('"', '', $t) . '"', $terms));
        $total = (int)val('SELECT COUNT(*) FROM fb_search_fts WHERE fb_search_fts MATCH ?', [$match]);
        $rows = all('SELECT post_id,topic_id FROM fb_search_fts WHERE fb_search_fts MATCH ? ORDER BY rank LIMIT ? OFFSET ?', [$match, $per_page, $offset]);
        return ['total' => $total, 'rows' => $rows];
    }
    if ($backend === 'mysql') {
        $terms = array_filter(preg_split('/\s+/', $q) ?: [], static fn(string $t): bool => mb_strlen($t) >= 2);
        $bool = implode(' ', array_map(static fn(string $t): string => '+' . preg_replace('/[+\-<>()~*"@]/', '', $t) . '*', $terms));
        $total = (int)val('SELECT COUNT(*) FROM fb_search WHERE MATCH(title,body) AGAINST(? IN BOOLEAN MODE)', [$bool]);
        $rows = all('SELECT post_id,topic_id FROM fb_search WHERE MATCH(title,body) AGAINST(? IN BOOLEAN MODE) ORDER BY MATCH(title,body) AGAINST(? IN BOOLEAN MODE) DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset, [$bool, $bool]);
        return ['total' => $total, 'rows' => $rows];
    }
    $like = db_like($q);
    $total = (int)val("SELECT COUNT(*) FROM fb_search WHERE title LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!'", [$like, $like]);
    $rows = all("SELECT post_id,topic_id FROM fb_search WHERE title LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!' ORDER BY post_id DESC LIMIT " . (int)$per_page . ' OFFSET ' . (int)$offset, [$like, $like]);
    return ['total' => $total, 'rows' => $rows];
}

/** Rebuild the whole index from fb_posts. Returns number of posts indexed. */
function search_rebuild(): int
{
    q('DELETE FROM fb_search');
    if (search_backend() === 'fts5') q('DELETE FROM fb_search_fts');
    $n = 0; $last = 0;
    while (true) {
        $rows = all('SELECT p.id,p.topic_id,p.body,t.title FROM fb_posts p JOIN fb_topics t ON t.id=p.topic_id WHERE p.id>? AND p.is_deleted=0 AND t.is_deleted=0 ORDER BY p.id LIMIT 500', [$last]);
        if ($rows === []) break;
        tx(static function () use ($rows, &$n, &$last): void {
            foreach ($rows as $r) {
                search_index_post((int)$r['id'], (int)$r['topic_id'], (string)$r['title'], (string)$r['body']);
                $n++; $last = (int)$r['id'];
            }
        });
    }
    return $n;
}

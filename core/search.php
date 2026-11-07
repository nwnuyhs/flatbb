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
    ['index' => $index, 'like' => $like] = search_terms($q, $backend);
    if ($index === [] && $like === []) return ['total' => 0, 'rows' => []];
    // words the index cannot find are matched with LIKE on fb_search, every word required
    $where = []; $args = [];
    foreach ($like as $t) { $where[] = "(s.title LIKE ? ESCAPE '!' OR s.body LIKE ? ESCAPE '!')"; $args[] = db_like($t); $args[] = db_like($t); }
    if ($backend === 'fts5' && $index !== []) {
        $match = implode(' ', array_map(static fn(string $t): string => '"' . $t . '"', $index));
        $join = $like !== [] ? ' JOIN fb_search s ON s.post_id=f.post_id' : '';
        $cond = 'fb_search_fts MATCH ?' . ($where !== [] ? ' AND ' . implode(' AND ', $where) : '');
        $total = (int)val("SELECT COUNT(*) FROM fb_search_fts f{$join} WHERE {$cond}", array_merge([$match], $args));
        $rows = all("SELECT f.post_id,f.topic_id FROM fb_search_fts f{$join} WHERE {$cond} ORDER BY f.rank LIMIT " . (int)$per_page . ' OFFSET ' . (int)$offset, array_merge([$match], $args));
        return ['total' => $total, 'rows' => $rows];
    }
    if ($backend === 'mysql' && $index !== []) {
        $bool = implode(' ', array_map(static fn(string $t): string => '+' . $t . '*', $index));
        $cond = 'MATCH(s.title,s.body) AGAINST(? IN BOOLEAN MODE)' . ($where !== [] ? ' AND ' . implode(' AND ', $where) : '');
        $total = (int)val("SELECT COUNT(*) FROM fb_search s WHERE {$cond}", array_merge([$bool], $args));
        $rows = all("SELECT s.post_id,s.topic_id FROM fb_search s WHERE {$cond} ORDER BY MATCH(s.title,s.body) AGAINST(? IN BOOLEAN MODE) DESC LIMIT " . (int)$per_page . ' OFFSET ' . (int)$offset, array_merge([$bool], $args, [$bool]));
        return ['total' => $total, 'rows' => $rows];
    }
    foreach ($index as $t) { $where[] = "(s.title LIKE ? ESCAPE '!' OR s.body LIKE ? ESCAPE '!')"; $args[] = db_like($t); $args[] = db_like($t); }
    $cond = implode(' AND ', $where);
    $total = (int)val("SELECT COUNT(*) FROM fb_search s WHERE {$cond}", $args);
    $rows = all("SELECT s.post_id,s.topic_id FROM fb_search s WHERE {$cond} ORDER BY s.post_id DESC LIMIT " . (int)$per_page . ' OFFSET ' . (int)$offset, $args);
    return ['total' => $total, 'rows' => $rows];
}

/**
 * Split a search into the words the full-text index can find and the words it cannot, which are matched with LIKE:
 * Chinese, Japanese, Korean and Thai (written without spaces, so the index never splits them into words), and on MySQL
 * two-letter words (InnoDB indexes three letters and up). Stopwords (MySQL's list: "the", "how", …) and single letters are
 * dropped when other words remain: MySQL never indexes them, and requiring one returned nothing ("how to install"). Returns ['index' => [...], 'like' => [...]], at most 8 words in all.
 */
function search_terms(string $q, string $backend): array
{
    static $stop = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
    // the index backends treat + - " * ( ) … as operators: there they separate words; LIKE takes the words as typed
    $words = preg_split($backend === 'like' ? '/\s+/u' : '/[\s+\-<>()~*"@]+/u', $q) ?: [];
    $index = $like = $stops = [];
    foreach (array_unique(array_filter($words, static fn(string $t): bool => $t !== '')) as $t) {
        if (count($index) + count($like) >= 8) break;
        if ($backend === 'like') $like[] = $t;
        elseif (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}]/u', $t)) $like[] = $t;
        elseif (in_array(mb_strtolower($t), $stop, true) || mb_strlen($t) === 1) $stops[] = $t; // "how to install" means "install"
        elseif ($backend === 'mysql' && mb_strlen($t) < 3) $like[] = $t;
        else $index[] = $t;
    }
    if ($index === [] && $like === []) $like = $stops; // only stopwords: look for them as text
    return ['index' => array_values($index), 'like' => array_values($like)];
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

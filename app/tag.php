<?php
/**
 * Tags. Free-form, lowercase, up to 5 per topic.
 */

function tag_normalize(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/[^\p{L}\p{N}. -]+/u', '', $name) ?? '';
    $name = preg_replace('/\s+/', '-', trim($name)) ?? '';
    return cut($name, 30, '');
}

/** Parse "a, b, c" into normalized unique names (max 5). */
function tags_parse(string $csv): array
{
    $out = [];
    foreach (preg_split('/[,\s]+/', $csv) ?: [] as $raw) {
        $n = tag_normalize($raw);
        if (mb_strlen($n) >= 2 && !in_array($n, $out, true)) $out[] = $n;
        if (count($out) >= 5) break;
    }
    return $out;
}

/** Tags for many topics: [topic_id => [tag rows]]. One query. */
function tags_for_topics(array $topic_ids): array
{
    $ids = array_values(array_unique(array_map('intval', $topic_ids)));
    if ($ids === []) return [];
    $out = [];
    $rows = all('SELECT tt.topic_id,g.id,g.name,g.slug FROM fb_topic_tags tt JOIN fb_tags g ON g.id=tt.tag_id WHERE tt.topic_id IN (' . sql_marks(count($ids)) . ') ORDER BY g.name', $ids);
    foreach ($rows as $r) $out[(int)$r['topic_id']][] = $r;
    return $out;
}

/** Replace a topic's tags. Creates missing tags and keeps counts accurate. */
function topic_set_tags(int $topic_id, array $names): void
{
    $old = array_map('intval', col('SELECT tag_id FROM fb_topic_tags WHERE topic_id=?', [$topic_id]));
    $new = [];
    foreach ($names as $n) {
        $slug = tag_normalize($n);
        if ($slug === '') continue;
        $id = (int)(val('SELECT id FROM fb_tags WHERE slug=?', [$slug]) ?? 0);
        if ($id === 0) $id = db_insert('fb_tags', ['name' => $slug, 'slug' => $slug, 'topic_count' => 0]);
        $new[] = $id;
    }
    foreach (array_diff($old, $new) as $id) {
        db_delete('fb_topic_tags', 'topic_id=? AND tag_id=?', [$topic_id, $id]);
        db_increment('fb_tags', 'topic_count', -1, 'id=? AND topic_count>0', [$id]);
    }
    foreach (array_diff($new, $old) as $id) {
        if (db_insert_ignore('fb_topic_tags', ['topic_id' => $topic_id, 'tag_id' => $id])) db_increment('fb_tags', 'topic_count', 1, 'id=?', [$id]);
    }
}

/** GET /tags */
function tag_index(): never
{
    $tags = all('SELECT * FROM fb_tags WHERE topic_count>0 ORDER BY topic_count DESC, name LIMIT 300');
    $main = view('tags', ['tags' => $tags, 'tabs' => list_tabs('tags', ['tags' => ['label' => t('Tags'), 'url' => url('/tags'), 'icon' => 'tag', 'active' => true]])]);
    page(t('Tags'), $main, ['class' => 'page-tags']);
}

/** GET /tag/{name} */
function tag_view(string $name): never
{
    $tag = one('SELECT * FROM fb_tags WHERE slug=?', [tag_normalize($name)]);
    if ($tag === null) not_found();
    $p = topic_list_page();
    $list = topic_list_fetch('t.id IN (SELECT topic_id FROM fb_topic_tags WHERE tag_id=?)', [(int)$tag['id']], 'last_post_at DESC', $p);
    $extra = ['tag' => ['label' => '#' . $tag['name'], 'url' => tag_url($tag), 'icon' => 'tag', 'active' => true]];
    $main = view('topic_list', [
        'title' => '#' . $tag['name'], 'tabs' => list_tabs('tag', $extra), 'sub_tabs' => '', 'topics' => $list['topics'],
        'pagination' => pagination($list['pagination'], static fn(int $n): string => url('/tag/' . $tag['slug'], $n > 1 ? ['page' => $n] : [])),
        'heading' => '', 'empty' => t('No topics with this tag yet.'),
    ]);
    page('#' . $tag['name'], $main, ['class' => 'page-list page-tag']);
}

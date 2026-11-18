<?php
/**
 * SEO Meta — Open Graph / Twitter cards and JSON-LD for topics, plus site verification tags.
 */
if (!defined('FLATBB')) exit;

function seo_meta_head(string $html, array $ctx): string
{
    $title = (string)($ctx['title'] ?? setting('site_name'));
    $site = setting('site_name');
    $path = current_path();
    $desc = setting('site_description') ?: setting('site_tagline');
    $image = (string)plugin_setting('seo_meta', 'image', '');
    $type = 'website';
    $ld = '';
    if ($path === '/') {
        // home: the site itself is the subject, not the "Latest" tab
        $title = setting('site_tagline') !== '' ? $site . ' - ' . setting('site_tagline') : $site;
        $ld = json_encode_value(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $site, 'url' => base_url() . '/', 'description' => cut($desc, 200),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => absolute_url('/search') . '?q={search_term_string}'], 'query-input' => 'required name=search_term_string']]);
    }
    if (preg_match('~^/t/(?:[^/]*-)?(\d+)$~', $path, $m)) {
        $topic = topic_by_id((int)$m[1]);
        // the 404 page of a hidden or deleted topic renders this region too, and so does the page of a topic a plugin
        // gated (Topic Access): neither may leak the title or the excerpt
        if ($topic !== null && ((int)$topic['is_deleted'] === 1 || (($cat = category_by_id((int)$topic['category_id'])) !== null && !category_can_view($cat)))) $topic = null;
        if ($topic !== null && function_exists('topic_access_denied') && topic_access_denied($topic) !== '') $topic = null;
        if ($topic !== null) {
            $type = 'article';
            $first = post_by_id((int)$topic['first_post_id']);
            $desc = $first ? md_excerpt((string)$first['body'], 200) : $desc;
            $author = user_by_id((int)$topic['user_id']);
            if ($image === '' && $first && preg_match('#!\[[^\]]*\]\(([^)\s]+)#', (string)$first['body'], $im)) $image = str_starts_with($im[1], '/') ? base_url() . $im[1] : $im[1];
            $ld = json_encode_value(['@context' => 'https://schema.org', '@type' => 'DiscussionForumPosting', 'headline' => $topic['title'], 'url' => absolute_url('/t/' . $topic['slug'] . '-' . $topic['id']),
                'datePublished' => date('c', (int)$topic['created_at']), 'dateModified' => date('c', (int)$topic['updated_at']), 'author' => ['@type' => 'Person', 'name' => is_array($author) ? (function_exists('user_name') ? user_name($author) : (string)($author['username'] ?? '')) : ''],
                'interactionStatistic' => ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => (int)$topic['reply_count']]]);
        }
    }
    $tags = '<link rel="canonical" href="' . h(base_url() . $path) . '">' . '<meta property="og:site_name" content="' . h($site) . '"><meta property="og:type" content="' . $type . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:url" content="' . h(base_url() . $path) . '">';
    if ($desc !== '') $tags .= '<meta property="og:description" content="' . h(cut($desc, 200)) . '">';
    if ($image !== '') $tags .= '<meta property="og:image" content="' . h($image) . '"><meta name="twitter:card" content="summary_large_image">';
    else $tags .= '<meta name="twitter:card" content="summary">';
    if ((string)plugin_setting('seo_meta', 'twitter', '') !== '') $tags .= '<meta name="twitter:site" content="' . h((string)plugin_setting('seo_meta', 'twitter')) . '">';
    foreach (['google' => 'google-site-verification', 'bing' => 'msvalidate.01'] as $k => $name) {
        if ((string)plugin_setting('seo_meta', $k, '') !== '') $tags .= '<meta name="' . $name . '" content="' . h((string)plugin_setting('seo_meta', $k)) . '">';
    }
    if ($ld !== '') $tags .= '<script type="application/ld+json">' . str_replace('</', '<\/', $ld) . '</script>'; // a title containing </script> must not end the block
    return $html . $tags;
}

return [
    'id' => 'seo_meta',
    'name' => 'SEO Meta',
    'version' => '1.0.5',
    'description' => 'Superseded by SEO Pro, which does all of this and more. Kept for forums that have not switched: canonical links, Open Graph and Twitter cards, structured data, verification tags.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.1'],
    'settings' => [
        'image' => ['type' => 'text', 'label' => 'Default share image URL', 'default' => '', 'max' => 300, 'help' => 'Shown when a topic has no image. 1200×630 works best.'],
        'twitter' => ['type' => 'text', 'label' => 'Twitter/X handle', 'default' => '', 'max' => 40, 'help' => 'e.g. @flatbb'],
        'google' => ['type' => 'text', 'label' => 'Google verification code', 'default' => '', 'max' => 120],
        'bing' => ['type' => 'text', 'label' => 'Bing verification code', 'default' => '', 'max' => 120],
    ],
    'hooks' => ['region.head' => 'seo_meta_head'],
];

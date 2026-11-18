<?php
/**
 * Share — a Share button on topic pages (and optionally on every post) with the platforms you choose:
 * X, Facebook, Reddit, Telegram, WhatsApp, LinkedIn, Tumblr, Pinterest, email and Copy link. Phones use their own share sheet when they have one.
 * No tables, no keys: every entry is the platform's public share link. Previews come from the SEO Meta plugin's Open Graph tags.
 */
if (!defined('FLATBB')) exit;

/** Every platform this plugin knows: id => [label, url template with {url} and {title}, brand mark]. */
function share_platforms(): array
{
    return [
        'x' => ['X', 'https://x.com/intent/post?text={title}&url={url}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
        'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97H15.83c-1.491 0-1.956.93-1.956 1.886v2.248h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>'],
        'reddit' => ['Reddit', 'https://www.reddit.com/submit?url={url}&title={title}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/></svg>'],
        'telegram' => ['Telegram', 'https://t.me/share/url?url={url}&text={title}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
        'whatsapp' => ['WhatsApp', 'https://api.whatsapp.com/send?text={title}%20{url}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>'],
        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url={url}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
        'tumblr' => ['Tumblr', 'https://www.tumblr.com/widgets/share/tool?canonicalUrl={url}&title={title}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14.563 24c-5.093 0-7.031-3.756-7.031-6.411V9.747H5.116V6.648c3.63-1.313 4.512-4.596 4.71-6.469C9.84.051 9.941 0 9.999 0h3.517v6.114h4.801v3.633h-4.82v7.47c.016 1.001.375 2.371 2.207 2.371h.09c.631-.02 1.486-.205 1.936-.419l1.156 3.425c-.436.636-2.4 1.374-4.156 1.404h-.178z"/></svg>'],
        // Pinterest picks the image from the page itself (the og:image the SEO Meta plugin prints)
        'pinterest' => ['Pinterest', 'https://www.pinterest.com/pin/create/button/?url={url}&description={title}', '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z"/></svg>'],
        'email' => ['Email', 'mailto:?subject={title}&body={url}', '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>'],
        'copy' => ['Copy link', '', ''],
    ];
}

/** The platforms the admin enabled, in their order (setting platforms: comma separated ids). */
function share_enabled(): array
{
    return request_cache('share_enabled', static function (): array {
        $all = share_platforms();
        $out = [];
        foreach (explode(',', (string)plugin_setting('share', 'platforms', 'x,facebook,reddit,telegram,whatsapp,linkedin,tumblr,pinterest,email,copy')) as $id) {
            $id = strtolower(trim($id));
            if (isset($all[$id])) $out[$id] = $all[$id];
        }
        return $out;
    }) ?? [];
}

/**
 * install (runs when the plugin is enabled and after every version change): a platform list saved before Pinterest existed gets
 * "pinterest" once, after Tumblr (else before Email, else before Copy link, else at the end). Removing it later is respected.
 */
function share_install(array $m): void
{
    $s = plugin_settings('share');
    if (!empty($s['pinterest_offered'])) return;
    $list = array_values(array_filter(array_map(static fn(string $p): string => strtolower(trim($p)), explode(',', (string)($s['platforms'] ?? '')))));
    if ($list !== [] && !in_array('pinterest', $list, true)) {
        $after = array_search('tumblr', $list, true);
        $before = array_search('email', $list, true);
        if ($before === false) $before = array_search('copy', $list, true);
        $at = $after !== false ? $after + 1 : ($before !== false ? $before : count($list));
        array_splice($list, (int)$at, 0, ['pinterest']);
        $s['platforms'] = implode(',', $list);
    }
    $s['pinterest_offered'] = 1;
    plugin_save_settings('share', $s);
}

function share_icon(): string
{
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>';
}

/** The button + menu. $url is absolute; $class styles the toggle (btn btn-sm on the topic, act on a post). */
function share_menu(string $url, string $title, string $class): string
{
    $items = share_enabled();
    if ($items === []) return '';
    $u = rawurlencode($url);
    $t = rawurlencode($title);
    $html = '';
    foreach ($items as $id => [$label, $tpl, $mark]) {
        $label = $id === 'copy' ? t('Copy link') : $label;
        if ($id === 'copy') {
            $html .= '<button type="button" data-copy="' . h($url) . '">' . icon('link') . '<span>' . h($label) . '</span></button>';
            continue;
        }
        $href = str_replace(['{url}', '{title}'], [$u, $t], $tpl);
        $html .= '<a href="' . h($href) . '" target="_blank" rel="noopener nofollow">' . $mark . '<span>' . h($label) . '</span></a>';
    }
    $native = (int)plugin_setting('share', 'native', 1) === 1 ? ' data-share-native="1"' : '';
    return '<div class="dropdown share-menu" data-dropdown><button type="button" class="' . h($class) . ' dropdown-toggle" data-share-url="' . h($url) . '" data-share-title="' . h($title) . '"' . $native . ' aria-haspopup="true" title="' . t('Share') . '">'
        . share_icon() . '<span>' . t('Share') . '</span></button><div class="dropdown-menu" data-slot="share.menu">' . $html . '</div></div>';
}

/** region.topic.actions: the Share button next to Bookmark / Edit. */
function share_topic_actions(array $items, array $ctx): array
{
    $topic = (array)($ctx['topic'] ?? []);
    if ((int)($topic['id'] ?? 0) <= 0) return $items;
    $html = share_menu(absolute_url(topic_url($topic)), (string)$topic['title'], 'btn btn-sm');
    if ($html !== '') $items['share'] = ['html' => $html, 'weight' => 30];
    return $items;
}

/** region.post.actions (loop, no DB): a Share entry on every post when the setting is on; the link carries the post anchor. */
function share_post_actions(array $items, array $ctx): array
{
    if ((int)plugin_setting('share', 'posts', 0) !== 1) return $items;
    $post = (array)($ctx['post'] ?? []);
    $topic = (array)($ctx['topic'] ?? []);
    if ((int)($post['id'] ?? 0) <= 0 || !empty($post['is_deleted'])) return $items;
    $html = share_menu(absolute_url('/post/' . (int)$post['id']), (string)($topic['title'] ?? ''), 'act');
    if ($html !== '') $items['share'] = ['html' => $html, 'weight' => 35];
    return $items;
}

function share_css(): string
{
    // under a post the button sits at the start of the row, so the menu opens from there; in the topic bar it sits at the end,
    // so the menu opens back over the card (both follow the language direction, reported by milad for right-to-left)
    return '.share-menu .dropdown-menu{min-width:190px;inset-inline-end:auto;inset-inline-start:0}.topic-bar .share-menu .dropdown-menu{inset-inline-start:auto;inset-inline-end:0}.share-menu .dropdown-menu a{text-decoration:none}.share-menu .dropdown-menu .icon{width:18px;height:18px;flex:none}'
        . '.post-actions .share-menu{display:inline-block}.post-actions .share-menu .dropdown-toggle{padding:4px 8px}';
}

/** Phones with a share sheet (navigator.share) use it instead of the menu; runs in the capture phase so it precedes the core dropdown handler. */
function share_js(): string
{
    return "document.addEventListener('click',function(e){var b=e.target.closest('[data-share-native]');if(!b||!navigator.share||!window.matchMedia('(max-width: 992px)').matches)return;"
        . "e.preventDefault();e.stopPropagation();navigator.share({title:b.getAttribute('data-share-title'),url:b.getAttribute('data-share-url')}).catch(function(){});},true);";
}

return [
    'id' => 'share',
    'name' => 'Share',
    'version' => '1.2.2',
    'description' => 'A Share button on topics and posts: X, Facebook, Reddit, Telegram, WhatsApp, LinkedIn, Tumblr, Pinterest, email and Copy link, in your order. Phones open their own share sheet.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.40'],
    'install' => 'share_install',
    'hooks' => [
        'region.topic.actions' => 'share_topic_actions',
        'region.post.actions' => 'share_post_actions',
    ],
    'assets' => ['css' => ['share_css'], 'js' => ['share_js']],
    'settings' => [
        'platforms' => ['type' => 'text', 'label' => 'Platforms, in order (comma separated)', 'default' => 'x,facebook,reddit,telegram,whatsapp,linkedin,tumblr,pinterest,email,copy', 'max' => 200, 'help' => 'Known: x, facebook, reddit, telegram, whatsapp, linkedin, tumblr, pinterest, email, copy. Remove one to hide it; a list saved before Pinterest existed needs "pinterest" added.'],
        'posts' => ['type' => 'checkbox', 'label' => 'Also show Share on every post (links to that post)', 'default' => 0],
        'native' => ['type' => 'checkbox', 'label' => "Use the phone's own share sheet when it has one", 'default' => 1],
    ],
];

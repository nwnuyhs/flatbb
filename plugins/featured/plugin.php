<?php
/**
 * Featured — moderators mark a topic as featured and style its title: a Featured label, a colour, bold. They can also bump
 * a topic to the top of Latest without replying to it (an idea from Arruba).
 * The style lives in the topic's own meta JSON (no table, no extra queries in lists) and is rendered through the
 * core topic.title filter, so it shows in every topic list and on the topic page.
 */
if (!defined('FLATBB')) exit;

/** Title colours a moderator can pick: key => [label, css colour]. */
function featured_colors(): array
{
    return ['red' => [t('Red'), '#d93025'], 'orange' => [t('Orange'), '#e7672e'], 'green' => [t('Green'), '#188038'], 'blue' => [t('Blue'), '#1a73e8'], 'purple' => [t('Purple'), '#7b3fe4']];
}

/** The style saved on a topic row: featured 0|1, color key or '', bold 0|1. */
function featured_of(array $topic): array
{
    $f = (array)(json_decode_array((string)($topic['meta'] ?? ''))['featured'] ?? []);
    $color = (string)($f['color'] ?? '');
    return ['featured' => (int)!empty($f['featured']), 'color' => isset(featured_colors()[$color]) ? $color : '', 'bold' => (int)!empty($f['bold'])];
}

/** topic.title (loop, no DB): a "Featured" label in front, colour and weight around the escaped title. */
function featured_title(string $html, array $ctx): string
{
    $f = featured_of((array)($ctx['topic'] ?? []));
    if ($f['featured'] === 0 && $f['color'] === '' && $f['bold'] === 0) return $html;
    $class = 'featured-title' . ($f['bold'] ? ' featured-bold' : '') . ($f['color'] !== '' ? ' featured-' . $f['color'] : '');
    $badge = $f['featured'] ? '<span class="featured-label">' . h(t('Featured')) . '</span>' : '';
    return $badge . '<span class="' . $class . '">' . $html . '</span>';
}

/** The Feature button's icon: a medal, so it is not taken for Bookmark. Same markup as core icon(). */
function featured_icon(): string
{
    return '<svg class="icon icon-award" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="9" r="6"/><path d="M12 15l3.4 5.89 1.6-3.23 3.6.23-3.4-5.89"/><path d="M6.8 12l-3.4 5.89 3.6-.23 1.6 3.23 3.4-5.89"/></svg>';
}

/** region.topic.actions: a "Feature" button for moderators that opens a small form (no JavaScript needed). */
function featured_actions(array $items, array $ctx): array
{
    $topic = (array)($ctx['topic'] ?? []);
    if (!is_mod() || (int)($topic['id'] ?? 0) <= 0) return $items;
    $f = featured_of($topic);
    $colors = ['' => t('— default')];
    foreach (featured_colors() as $k => [$label]) $colors[$k] = $label;
    $form = '<form method="post" action="' . h(url('/t/' . (int)$topic['id'] . '/featured')) . '" class="featured-form">' . csrf_field()
        . '<div class="form-row">' . checkbox('featured', $f['featured'] === 1, t('Featured (a label before the title)')) . '</div>'
        . '<div class="form-row"><label>' . t('Title colour') . '</label>' . select('color', $colors, $f['color']) . '</div>'
        . '<div class="form-row">' . checkbox('bold', $f['bold'] === 1, t('Bold title')) . '</div>'
        . '<div class="featured-buttons"><button type="submit" class="btn btn-primary btn-sm">' . t('Save') . '</button>'
        . '<button type="submit" name="bump" value="1" class="btn btn-sm" title="' . h(t('Move this topic to the top of Latest without replying to it')) . '">' . icon('arrow-up') . t('Bump to top') . '</button></div></form>';
    $active = $f['featured'] === 1 || $f['color'] !== '' || $f['bold'] === 1;
    $items['featured'] = ['html' => '<details class="featured-menu"><summary class="btn btn-sm' . ($active ? ' active' : '') . '" title="' . t('Feature') . '">' . featured_icon() . '<span>' . t('Feature') . '</span></summary>' . $form . '</details>', 'weight' => 40];
    return $items;
}

/** POST /t/{id}/featured — save the style into the topic's meta (moderators). */
function featured_save(string $id): never
{
    need_login();
    require_post();
    if (!is_mod()) forbidden();
    $topic = topic_by_id((int)$id);
    if ($topic === null) not_found();
    $meta = json_decode_array((string)($topic['meta'] ?? ''));
    if (post_int('bump') === 1) { // to the top of Latest, as a new reply would put it, without one; the style is left as it is
        if ((int)$topic['is_deleted'] !== 0) fail(t('This topic is not public.'), topic_url($topic));
        $meta['bumped'] = ['by' => uid(), 'at' => now()];
        db_update('fb_topics', ['last_post_at' => now(), 'updated_at' => now(), 'meta' => json_encode_value($meta)], 'id=?', [(int)$topic['id']]);
        fire('topic.after_action', ['topic_id' => (int)$topic['id'], 'action' => 'bump']);
        flash(t('Moved to the top of Latest.'));
        redirect(topic_url($topic));
    }
    $color = post_str('color', 20);
    $style = ['featured' => post_int('featured') ? 1 : 0, 'color' => isset(featured_colors()[$color]) ? $color : '', 'bold' => post_int('bold') ? 1 : 0];
    if ($style === ['featured' => 0, 'color' => '', 'bold' => 0]) unset($meta['featured']);
    else $meta['featured'] = $style + ['by' => uid(), 'at' => now()];
    db_update('fb_topics', ['meta' => $meta === [] ? '{}' : json_encode_value($meta)], 'id=?', [(int)$topic['id']]);
    fire('topic.after_action', ['topic_id' => (int)$topic['id'], 'action' => 'featured']);
    flash(isset($meta['featured']) ? t('Topic style saved.') : t('Topic style removed.'));
    redirect(topic_url($topic));
}

function featured_css(): string
{
    $css = '.featured-label{display:inline-block;margin-inline-end:6px;padding:0 6px;border-radius:5px;background:color-mix(in srgb,var(--warning) 16%,transparent);color:color-mix(in srgb,var(--warning) 72%,var(--text));font-size:var(--font-size-xs);font-weight:600;line-height:1.7;vertical-align:.12em;white-space:nowrap}.topic-title .featured-label{font-size:var(--font-size-sm);vertical-align:.28em}.featured-bold{font-weight:700}';
    foreach (featured_colors() as $k => [, $hex]) $css .= '.featured-' . $k . ',.featured-' . $k . ' a{color:' . $hex . '}';
    return $css . '.featured-menu{position:relative;display:inline-block}.featured-menu summary{list-style:none;cursor:pointer}.featured-menu summary::-webkit-details-marker{display:none}'
        . '.featured-form{position:absolute;inset-inline-start:0;top:calc(100% + 6px);z-index:30;min-width:230px;padding:12px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 8px 24px rgba(0,0,0,.12)}.featured-form .form-row{margin-bottom:8px}.featured-form select{width:100%}.featured-buttons{display:flex;flex-wrap:wrap;gap:6px}.topic-bar .featured-form{inset-inline-start:auto;inset-inline-end:0}';
}

return [
    'id' => 'featured',
    'name' => 'Featured Topics',
    'version' => '1.1.0',
    'description' => 'Moderators mark topics as featured and style their titles (a Featured label, a colour, bold), and bump a topic to the top of Latest without replying. No extra queries.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.32'],
    'routes' => ['/t/{id}/featured' => 'featured_save'],
    'hooks' => [
        'topic.title' => 'featured_title',
        'region.topic.actions' => 'featured_actions',
    ],
    'assets' => ['css' => ['featured_css']],
];

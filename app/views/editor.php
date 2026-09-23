<?php
/**
 * Markdown editor. Variables: name, value, placeholder, scope (draft key such as "topic-new", "reply-12", "post-34"), ctx.
 * Extension points (see docs/PLUGIN.md "Editor"):
 *   editor.options (filter config)  composer.toolbar (list of buttons)  composer.modes (list of tabs)  editor.emoji (list)  editor.help (rows)
 *   composer.modes: the tabs above the toolbar. Core: write (the Markdown textarea) and preview. A plugin adds a mode with
 *   label, icon, cmd (the editor command that switches it on and off) and class (on .editor while it is on), e.g. a visual editor.
 *   JS: FB.editor.register('cmd', fn(api, arg)); event fb:editor (cancelable) before every command.
 */
$cfg = hook('editor.options', [
    'preview' => setting('editor_preview', '1') === '1',
    'emoji' => setting('editor_emoji', '1') === '1',
    'fullscreen' => setting('editor_fullscreen', '1') === '1',
    'draft_days' => max(0, min(90, (int)setting('editor_draft_days', '7'))),
    'help' => true,
    'upload' => uid() > 0 && can('upload'),
    'accept' => '',
    'scope' => $scope ?? '',
], ['name' => $name, 'ctx' => $ctx ?? []]);
$groups = [
    ['bold' => ['icon' => 'bold', 'title' => t('Bold'), 'cmd' => 'bold'], 'italic' => ['icon' => 'italic', 'title' => t('Italic'), 'cmd' => 'italic'], 'strike' => ['icon' => 'strike', 'title' => t('Strikethrough'), 'cmd' => 'strike'], 'heading' => ['icon' => 'heading', 'title' => t('Heading'), 'cmd' => 'heading'], 'quote' => ['icon' => 'quote', 'title' => t('Quote'), 'cmd' => 'quote']],
    ['code' => ['icon' => 'code', 'title' => t('Inline code'), 'cmd' => 'code'], 'code_block' => ['icon' => 'terminal', 'title' => t('Code block'), 'cmd' => 'code_block'], 'ul' => ['icon' => 'list', 'title' => t('Bullet list'), 'cmd' => 'ul'], 'ol' => ['icon' => 'list-ol', 'title' => t('Numbered list'), 'cmd' => 'ol']],
    ['link' => ['icon' => 'link', 'title' => t('Link'), 'cmd' => 'link'], 'image' => ['icon' => 'image', 'title' => $cfg['upload'] ? t('Upload image or file') : t('Image'), 'cmd' => $cfg['upload'] ? 'upload' : 'image'], 'table' => ['icon' => 'table', 'title' => t('Table'), 'cmd' => 'table'], 'hr' => ['icon' => 'minus', 'title' => t('Horizontal rule'), 'cmd' => 'hr']],
];
$buttons = [];
foreach ($groups as $i => $g) { if ($i > 0) $buttons['sep' . $i] = ['html' => '<span class="tb-sep"></span>']; $buttons += $g; }
$buttons = region_list('composer.toolbar', $buttons, ['config' => $cfg]);
$right = [];
if ($cfg['emoji']) $right['emoji'] = ['icon' => 'smile', 'title' => t('Emoji'), 'cmd' => 'emoji'];
// the ways to look at the text are tabs above the toolbar: Write, Preview, and what plugins add (a visual editor)
$modes = ['write' => ['label' => t('Write'), 'icon' => 'hash', 'weight' => 10]];
if ($cfg['preview']) $modes['preview'] = ['label' => t('Preview'), 'icon' => 'eye', 'weight' => 90];
$modes = array_filter(region_list('composer.modes', $modes, ['config' => $cfg]), 'is_array');
$fullscreen = $cfg['fullscreen'] ? '<button type="button" class="tb-btn editor-fs-btn" title="' . h(t('Fullscreen')) . '" data-cmd="fullscreen">' . icon('maximize') . '</button>' : '';
$emoji = $cfg['emoji'] ? (array)hook('editor.emoji', ['😀', '😄', '😂', '🤣', '😊', '😍', '🤔', '😅', '😎', '🙂', '😉', '😢', '😭', '😡', '🙄', '😴', '🤯', '🥳', '🤝', '👍', '👎', '👏', '🙏', '💪', '👀', '❤️', '🔥', '✨', '🎉', '🚀', '💡', '⚡', '✅', '❌', '⚠️', '❓', '💬', '📌', '📎', '🔗', '📷', '🎯', '🏆', '🐛', '🔧', '💻', '📱', '☕', '🍕', '🌟'], []) : [];
$help = $cfg['help'] ? (array)hook('editor.help', [
    ['**bold**  *italic*  ~~strike~~', t('Emphasis')], ['## Heading', t('Headings (## to ######)')], ['> quoted text', t('Quote')],
    ['- item / 1. item', t('Lists (indent two spaces to nest)')], ['`code`  ```lang … ```', t('Inline code and code blocks')],
    ['[text](https://…)  ![alt](image-url)', t('Links and images')], ['![alt|300](image-url)', t('Image width in pixels')], ['| a | b |  then  |---|---|', t('Tables')], ['@username', t('Mention a member')],
], []) : [];
$btn = static fn(string $k, array $b): string => !empty($b['html']) ? $b['html'] : '<button type="button" class="tb-btn' . (!empty($b['label']) ? ' tb-labeled' : '') . '" title="' . h((string)$b['title']) . '" data-cmd="' . h((string)$b['cmd']) . '" data-arg="' . h((string)($b['arg'] ?? '')) . '">' . icon((string)$b['icon']) . (!empty($b['label']) ? '<span>' . h((string)$b['label']) . '</span>' : '') . '</button>';
?>
<div class="editor" data-editor data-scope="<?= h((string)$cfg['scope']) ?>" data-draft-days="<?= (int)$cfg['draft_days'] ?>" data-preview="<?= $cfg['preview'] ? 1 : 0 ?>">
  <div class="editor-draft hidden" data-draft-banner><?= icon('clock') ?><span><?= t('You have an unsent draft.') ?></span><button type="button" class="link" data-draft-restore><?= t('Restore') ?></button><button type="button" class="link muted" data-draft-discard><?= t('Discard') ?></button></div>
  <?php if (count($modes) > 1): ?>
  <div class="editor-tabs" role="tablist" data-slot="composer.modes">
    <?php $first = true; foreach ($modes as $k => $m): ?><button type="button" class="editor-tab<?= $first ? ' active' : '' ?>" role="tab" aria-selected="<?= $first ? 'true' : 'false' ?>" data-mode="<?= h((string)$k) ?>" data-mode-cmd="<?= h((string)($m['cmd'] ?? '')) ?>" data-mode-class="<?= h((string)($m['class'] ?? '')) ?>"><?= icon((string)($m['icon'] ?? 'edit')) ?><span><?= h((string)($m['label'] ?? $k)) ?></span></button><?php $first = false; endforeach; ?>
    <span class="tb-spacer"></span><?= raw($fullscreen) ?>
  </div>
  <?php endif; ?>
  <div class="editor-toolbar" data-slot="composer.toolbar">
    <?php foreach ($buttons as $k => $b): ?><?= raw($btn((string)$k, $b)) ?><?php endforeach; ?>
    <span class="tb-spacer"></span>
    <?php foreach ($right as $k => $b): ?><?= raw($btn((string)$k, $b)) ?><?php endforeach; ?><?= count($modes) > 1 ? '' : raw($fullscreen) ?>
  </div>
  <?php if ($emoji !== []): ?><div class="editor-emoji hidden" data-emoji><?php foreach ($emoji as $e): ?><button type="button" data-emoji-char="<?= h((string)$e) ?>"><?= h((string)$e) ?></button><?php endforeach; ?></div><?php endif; ?>
  <div class="editor-body">
    <textarea name="<?= h($name) ?>" class="editor-input" rows="10" dir="auto" placeholder="<?= h($placeholder) ?>" required><?= h($value) ?></textarea>
    <div class="editor-preview post-content" data-preview hidden></div>
  </div>
  <?php if ($cfg['upload']): ?><input type="file" class="hidden" data-upload-input multiple<?= $cfg['accept'] !== '' ? ' accept="' . h((string)$cfg['accept']) . '"' : '' ?>><?php endif; ?>
  <div class="editor-foot">
    <small><?= $cfg['upload'] ? t('Markdown supported. Drag, drop or paste images to upload.') : t('Markdown supported.') ?><?php if ($help !== []): ?> · <button type="button" class="link" data-cmd="help"><?= t('Formatting help') ?></button><?php endif; ?></small>
    <span class="editor-status" data-status></span>
  </div>
  <?php if ($help !== []): ?><div class="editor-help hidden" data-help><table><?php foreach ($help as [$syntax, $desc]): ?><tr><td><code><?= h((string)$syntax) ?></code></td><td><?= h((string)$desc) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
</div>

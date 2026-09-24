<?php
/**
 * Admin → Settings → AI: the site's AI connections, a main one and up to two backups (core/ai.php asks them in this
 * order and moves on when one fails). Each card has its own test; the state line under it says how the connection did.
 */
if (!defined('FLATBB')) exit;

function admin_ai_providers(): array
{
    return ['' => t('Off'), 'openai' => t('OpenAI-compatible (OpenAI, DeepSeek, Qwen, Moonshot, OpenRouter, Ollama…)'), 'anthropic' => t('Anthropic (Claude)')];
}

/** GET and POST of the AI tab. $sections: the settings tabs, for the tab bar. */
function admin_ai_settings(array $sections): never
{
    $back = admin_url('settings', ['section' => 'ai']);
    if (is_post()) {
        if (post_str('do', 10) !== '') { // Remove / Undo on a saved key, at once (secret_field())
            $field = post_str('field', 60);
            $keys = array_map(static fn(int $n): string => ai_setting_name($n, 'key'), range(1, AI_SLOTS));
            if (!in_array($field, $keys, true)) json_error(t('Request failed.'));
            field_action('setting:' . $field, setting($field), static function (string $v) use ($field): void { save_settings([$field => $v]); admin_log('settings', 'ai', $field); }, null, t('API key'));
        }
        $save = ['ai_timeout' => (string)max(5, min(120, post_int('ai_timeout')))];
        for ($n = 1; $n <= AI_SLOTS; $n++) {
            $provider = post_str(ai_setting_name($n, 'provider'), 20);
            $save[ai_setting_name($n, 'provider')] = isset(admin_ai_providers()[$provider]) ? $provider : '';
            $save[ai_setting_name($n, 'base_url')] = cut(trim(post_str(ai_setting_name($n, 'base_url'), 500)), 500, '');
            $save[ai_setting_name($n, 'model')] = cut(trim(post_str(ai_setting_name($n, 'model'), 200)), 200, '');
            $key = trim(post_secret(ai_setting_name($n, 'key'), 500)); // never shown again: empty keeps the saved one, the box clears it
            if ($key !== '') $save[ai_setting_name($n, 'key')] = $key;
            elseif (post_int(ai_setting_name($n, 'key') . '_clear') === 1) $save[ai_setting_name($n, 'key')] = '';
        }
        save_settings(hook('admin.settings_save', $save, ['section' => 'ai']));
        admin_log('settings', 'ai', implode(', ', array_keys(array_filter($save, static fn(string $k): bool => !str_ends_with($k, '_key'), ARRAY_FILTER_USE_KEY))));
        $up = post_int('ai_up');
        if ($up > 1 && $up <= AI_SLOTS) admin_ai_swap($up - 1, $up);
        request_cache('ai.connections', null, true);
        $test = post_str('ai_test', 10);
        if ($test !== '') admin_ai_test($test === 'all' ? array_keys(ai_connections()) : [(int)$test]);
        flash(t('Settings saved.'));
        redirect($back);
    }
    $tabs = [];
    foreach ($sections as $k => [$l]) $tabs[$k] = ['label' => $l, 'url' => admin_url('settings', ['section' => $k]), 'active' => $k === 'ai'];
    $html = tabs($tabs) . '<form method="post" action="' . h(admin_url('settings')) . '" class="admin-form" style="margin-top:14px">' . csrf_field() . '<input type="hidden" name="section" value="ai">'
        . '<p class="muted">' . t('One connection for the whole site: plugins that use AI (tagging, translation, moderation…) call it instead of asking for their own key. What they send (the text of a post, say) goes to this service.') . '</p>'
        . '<p class="muted">' . t('When the main connection fails (unreachable, key refused, out of credit, rate limited, server error), the backups are asked in order at once. A connection that failed rests for 5 minutes: requests go to the others first.') . '</p>';
    $state = ai_state()['conn'];
    $ready = ai_connections();
    for ($n = 1; $n <= AI_SLOTS; $n++) {
        $name = static fn(string $f): string => ai_setting_name($n, $f);
        $key = setting($name('key'), '');
        $body = form_row(t('AI service'), select($name('provider'), admin_ai_providers(), setting($name('provider'), '')))
            . form_row(t('API address'), input($name('base_url'), setting($name('base_url'), '')), h(t('Empty: the service default (https://api.openai.com/v1 or https://api.anthropic.com). DeepSeek: https://api.deepseek.com, a local Ollama: http://127.0.0.1:11434/v1.')))
            . form_row(t('Model'), input($name('model'), setting($name('model'), '')), h(t('e.g. gpt-4o-mini, deepseek-chat, qwen-plus, claude-haiku-4-5. A small, cheap model is enough for tagging.')))
            . form_row(t('API key'), secret_field($name('key'), $key, ['action' => $back]), h(t('Stays on the server and is never shown again.')))
            . '<div class="form-actions" style="margin-top:4px">'
            . (isset($ready[$n]) ? '<button type="submit" class="btn btn-sm" name="ai_test" value="' . $n . '">' . icon('check') . t('Save and test') . '</button>' : '')
            . ($n > 1 ? ' <button type="submit" class="btn btn-sm" name="ai_up" value="' . $n . '">' . icon('arrow-up') . t('Move up') . '</button>' : '')
            . '</div>';
        $html .= card($n === 1 ? t('Main connection') : t('Backup %d', $n - 1), $body, 'admin-ai-conn', admin_ai_status($ready[$n] ?? null, (array)($state[$n] ?? [])));
    }
    $html .= form_row(t('Timeout per connection (seconds)'), input('ai_timeout', setting('ai_timeout', '30'), ['type' => 'number', 'min' => 5, 'max' => 120]), h(t('A writer never waits more than twice this long, however many connections are tried.')))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . t('Save') . '</button>'
        . (count($ready) > 1 ? ' <button type="submit" class="btn" name="ai_test" value="all">' . icon('check') . t('Save and test all') . '</button>' : '') . '</div></form>';
    admin_page(t('Settings'), $html, 'settings');
}

/** The flag in a connection card's head: not set up, resting after an error, last answer, last error. */
function admin_ai_status(?array $conn, array $s): string
{
    if ($conn === null) return '<span class="flag flag-muted">' . t('Not set up') . '</span>';
    $ok = (int)($s['ok_at'] ?? 0);
    $fail = (int)($s['fail_at'] ?? 0);
    if ((int)($s['rest_until'] ?? 0) > now()) $flag = ['flag-warning', t('Resting after an error: %s', (string)($s['error'] ?? ''))];
    elseif ($fail > $ok) $flag = ['flag-danger', t('Last error %s: %s', human_time($fail), (string)($s['error'] ?? ''))];
    elseif ($ok > 0) $flag = ['flag-success', t('Answered %s', human_time($ok))];
    else return '<span class="flag flag-muted">' . t('Not used yet') . '</span>';
    return '<span class="flag ' . $flag[0] . '" title="' . h($flag[1]) . '">' . h(cut($flag[1], 90)) . '</span>';
}

/** Move a connection up: swap every field of $a and $b, and what the site remembers about them. */
function admin_ai_swap(int $a, int $b): void
{
    $save = [];
    foreach (['provider', 'base_url', 'model', 'key'] as $f) {
        $save[ai_setting_name($a, $f)] = setting(ai_setting_name($b, $f), '');
        $save[ai_setting_name($b, $f)] = setting(ai_setting_name($a, $f), '');
    }
    save_settings($save);
    ai_state_update(static function (array $s) use ($a, $b): array {
        [$ca, $cb] = [$s['conn'][$a] ?? null, $s['conn'][$b] ?? null];
        unset($s['conn'][$a], $s['conn'][$b]);
        if ($cb !== null) $s['conn'][$a] = $cb;
        if ($ca !== null) $s['conn'][$b] = $ca;
        return $s;
    });
}

/** Ask each connection one tiny question and flash one line for all of them; the cards show the details. */
function admin_ai_test(array $numbers): never
{
    $lines = [];
    $failed = false;
    foreach ($numbers as $n) {
        $t = microtime(true);
        $r = ai_chat('Answer with the single word: ok', 'ping', ['purpose' => 'test', 'max_tokens' => 16, 'connection' => $n]);
        $label = $n === 1 ? t('Main connection') : t('Backup %d', $n - 1);
        $lines[] = $r['ok'] ? t('%s answered (%s, %s s)', $label, (string)$r['model'], number_format(microtime(true) - $t, 1)) : t('%s failed: %s', $label, cut((string)$r['error'], 120));
        $failed = $failed || !$r['ok'];
    }
    flash(t('Settings saved.') . ' ' . implode(' · ', $lines), $failed ? 'error' : 'success');
    redirect(admin_url('settings', ['section' => 'ai']));
}

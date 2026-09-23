<?php
/** The AI connections: a main one and backups, asked in order; a connection that fails rests and goes last. Run with: php flatbb test */

function ai_failover_run(callable $fn): void
{
    $file = CACHE_DIR . '/ai.json';
    $saved = is_file($file) ? (string)file_get_contents($file) : null;
    $hooks = hook_registry();
    @unlink($file);
    try { $fn(); } finally {
        hook_registry($hooks);
        $reset = ['ai_timeout' => '30'];
        for ($n = 1; $n <= AI_SLOTS; $n++) foreach (['provider', 'base_url', 'model', 'key'] as $f) $reset[ai_setting_name($n, $f)] = '';
        save_settings($reset);
        request_cache('ai.connections', null, true);
        $saved === null ? @unlink($file) : file_put_contents($file, $saved);
    }
}

function test_ai_connections_follow_the_settings(): void
{
    ai_failover_run(static function (): void {
        test_same('ai_model', ai_setting_name(1, 'model'), 'the main connection keeps the plain names');
        test_same('ai3_key', ai_setting_name(3, 'key'), 'backups are numbered');
        save_settings(['ai_provider' => 'openai', 'ai_model' => 'm1', 'ai_key' => 'k1', 'ai2_provider' => 'openai', 'ai2_model' => '', 'ai3_provider' => 'anthropic', 'ai3_model' => 'm3', 'ai3_key' => 'k3']);
        request_cache('ai.connections', null, true);
        test_same([1, 3], array_keys(ai_connections()), 'a connection without a model is not set up');
        test_same('https://api.anthropic.com', ai_connections()[3]['base_url'], 'an empty address is the service default');
        test_same(true, ai_ready(), 'one connection is enough');
    });
}

function test_ai_falls_back_and_rests_a_failing_connection(): void
{
    ai_failover_run(static function (): void {
        // port 1 on this machine refuses at once: two connections that both fail, no network needed
        save_settings(['ai_timeout' => '5', 'ai_provider' => 'openai', 'ai_base_url' => 'http://127.0.0.1:1/v1', 'ai_model' => 'a', 'ai2_provider' => 'openai', 'ai2_base_url' => 'http://127.0.0.1:1/v1', 'ai2_model' => 'b']);
        request_cache('ai.connections', null, true);
        $tried = [];
        hook_add('ai.response', static function ($v, array $ctx) use (&$tried) { $tried[] = $ctx['connection']; return $v; });
        $r = ai_chat('system', 'user');
        test_same(false, $r['ok'], 'every connection failed');
        test_same([1, 2], $tried, 'the main connection first, then the backup');
        test_same(true, str_contains((string)$r['error'], '#1') && str_contains((string)$r['error'], '#2'), 'the error names each connection');
        $state = ai_state()['conn'];
        test_same(true, (int)($state[1]['rest_until'] ?? 0) > now() && (int)($state[2]['rest_until'] ?? 0) > now(), 'both rest after failing on their own');
        test_same([1, 2], array_column(ai_order(ai_connections()), 'n'), 'all resting: still tried, in order');
        ai_note(2, ['ok' => true, 'model' => 'b']);
        test_same([2, 1], array_column(ai_order(ai_connections()), 'n'), 'a resting main connection goes after the backup that answers');
        ai_note(1, ['ok' => false, 'error' => 'HTTP 400: bad request', 'rest' => false]);
        test_same(true, (int)(ai_state()['conn'][1]['rest_until'] ?? 0) > now(), 'a failure keeps the rest it already has');
    });
}

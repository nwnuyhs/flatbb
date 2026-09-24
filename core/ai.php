<?php
/**
 * The site's AI connection, shared by every plugin: the admin enters up to three connections (provider, address, model,
 * key) under Admin → Settings → AI, and plugins call ai_chat() instead of shipping their own client and key field.
 * Two protocols cover nearly every service: "openai" (the Chat Completions API that OpenAI, DeepSeek, Qwen, Moonshot,
 * OpenRouter, Ollama and most others speak) and "anthropic" (Claude's Messages API).
 *
 * Several connections are a main one and backups: a request goes to the first, and when it fails to the next. A connection
 * that fails with an error of its own (unreachable, key refused, out of credit, rate limited, server error) rests for
 * AI_REST seconds: requests go to the others first, so nobody waits for a dead service on every post.
 * Filter ai.request may change or refuse a request (return ['error' => …]); event ai.response reports every attempt
 * (purpose, connection, model, usage, ok) for plugins that count or log.
 */
if (!defined('FLATBB')) exit;

define('AI_SLOTS', 3);
define('AI_REST', 300);

/** The setting that holds one field of connection $n: the first keeps the plain names (ai_provider…), the others ai2_…, ai3_…. */
function ai_setting_name(int $n, string $field): string
{
    return ($n === 1 ? 'ai_' : 'ai' . $n . '_') . $field;
}

/** The connections that are set up, in the admin's order: n => [n, provider, base_url, model, key]. */
function ai_connections(): array
{
    return request_cache('ai.connections', static function (): array {
        $out = [];
        for ($n = 1; $n <= AI_SLOTS; $n++) {
            $provider = setting(ai_setting_name($n, 'provider'), '');
            if (!in_array($provider, ['openai', 'anthropic'], true)) continue;
            $c = [
                'n' => $n, 'provider' => $provider,
                'base_url' => rtrim(trim(setting(ai_setting_name($n, 'base_url'), '')), '/'),
                'model' => trim(setting(ai_setting_name($n, 'model'), '')),
                'key' => setting(ai_setting_name($n, 'key'), ''),
            ];
            if ($c['model'] === '' || ($c['key'] === '' && $c['base_url'] === '')) continue; // a key, unless a custom address (a local Ollama) needs none
            if ($c['base_url'] === '') $c['base_url'] = $provider === 'anthropic' ? 'https://api.anthropic.com' : 'https://api.openai.com/v1';
            $out[$n] = $c;
        }
        return $out;
    });
}

/** Whether a plugin can call ai_chat(): at least one connection is set up. */
function ai_ready(): bool
{
    return ai_connections() !== [];
}

/** What the site remembers about its connections (data/cache/ai.json): per connection, the last answer, the last failure and a rest. */
function ai_state(): array
{
    $raw = @file_get_contents(CACHE_DIR . '/ai.json');
    $state = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($state) ? $state + ['conn' => []] : ['conn' => []];
}

/** Change the state under a lock: $fn receives it and returns it changed. */
function ai_state_update(callable $fn): void
{
    $fp = @fopen(CACHE_DIR . '/ai.json', 'c+');
    if ($fp === false) return;
    flock($fp, LOCK_EX);
    $state = json_decode((string)stream_get_contents($fp), true);
    $state = $fn(is_array($state) ? $state + ['conn' => []] : ['conn' => []]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode_value($state));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** The order to try the connections in: the admin's order, the resting ones last (still tried when all others failed). */
function ai_order(array $conns): array
{
    $conn = ai_state()['conn'];
    $ready = $resting = [];
    foreach ($conns as $c) {
        if ((int)($conn[$c['n']]['rest_until'] ?? 0) > now()) $resting[] = $c; else $ready[] = $c;
    }
    return array_merge($ready, $resting);
}

/**
 * One question to the model. $opts: purpose (the plugin id, for ai.request / ai.response), max_tokens (the longest answer
 * you expect, default 800), temperature (default 0.2; OpenAI-compatible services only, current Claude models take none),
 * json (true asks for a JSON object where the API supports it),
 * connection (ask only that one, e.g. to test it). Returns ['ok' => true, 'text' => …, 'model' => …, 'connection' => n,
 * 'usage' => ['in' => n, 'out' => n]] or ['ok' => false, 'error' => …] after every connection failed.
 * Never call it from a loop or inside a transaction: it is an HTTPS request that may take seconds (more when it falls back).
 */
function ai_chat(string $system, string $user, array $opts = []): array
{
    $conns = ai_connections();
    if (isset($opts['connection'])) $conns = array_intersect_key($conns, [(int)$opts['connection'] => true]);
    if ($conns === []) return ['ok' => false, 'error' => t('The AI connection is not set up (Admin → Settings → AI).')];
    $req = hook('ai.request', ['system' => $system, 'user' => $user, 'opts' => $opts], ['purpose' => (string)($opts['purpose'] ?? '')]);
    if (!is_array($req) || isset($req['error'])) return ['ok' => false, 'error' => (string)($req['error'] ?? t('The request was refused.'))];
    [$system, $user, $opts] = [(string)$req['system'], (string)$req['user'], (array)$req['opts']];
    $timeout = max(5, min(120, (int)setting('ai_timeout', '30')));
    $deadline = microtime(true) + 2 * $timeout; // falling back never makes the writer wait more than twice the timeout
    $errors = [];
    foreach (count($conns) > 1 ? ai_order($conns) : array_values($conns) as $c) {
        $left = (int)floor($deadline - microtime(true));
        if ($errors !== [] && $left < 5) break;
        $r = ai_call($c, $system, $user, $opts, min($timeout, max(5, $left)));
        ai_note((int)$c['n'], $r);
        fire('ai.response', ['purpose' => (string)($opts['purpose'] ?? ''), 'connection' => (int)$c['n'], 'model' => (string)($r['model'] ?? $c['model']), 'usage' => $r['usage'] ?? ['in' => 0, 'out' => 0], 'ok' => $r['ok']]);
        if ($r['ok']) return $r + ['connection' => (int)$c['n']];
        $errors[(int)$c['n']] = (string)$r['error'];
    }
    if (count($errors) === 1) return ['ok' => false, 'error' => (string)reset($errors)];
    $parts = [];
    foreach ($errors as $n => $e) $parts[] = '#' . $n . ' ' . cut($e, 120);
    return ['ok' => false, 'error' => cut(implode('; ', $parts), 400)];
}

/** Ask one connection. The answer as ai_chat() gives it, and on failure 'status' (HTTP code, 0: no answer) and 'rest' (its own fault). */
function ai_call(array $c, string $system, string $user, array $opts, int $timeout): array
{
    $max = max(16, min(8000, (int)($opts['max_tokens'] ?? 800)));
    $temp = max(0.0, min(2.0, (float)($opts['temperature'] ?? 0.2)));
    if ($c['provider'] === 'anthropic') {
        $url = $c['base_url'] . '/v1/messages';
        $headers = ['Content-Type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . $c['key']];
        // current Claude models think before they answer and count it in max_tokens, and reject sampling parameters (temperature)
        $body = ['model' => $c['model'], 'max_tokens' => max($max, 2048), 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]];
    } else {
        $url = $c['base_url'] . '/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($c['key'] !== '') $headers[] = 'Authorization: Bearer ' . $c['key'];
        // reasoning models (deepseek-v4-pro, o-series, qwq…) spend max_tokens on thinking before the answer: leave room for both.
        // Only generated tokens are billed, so a model that answers at once costs the same.
        $body = ['model' => $c['model'], 'max_tokens' => max($max, 2048), 'temperature' => $temp, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]];
        if (!empty($opts['json'])) $body['response_format'] = ['type' => 'json_object'];
    }
    [$status, $data, $error] = ai_http($url, $headers, json_encode_value($body), $timeout);
    if ($data === null) return ['ok' => false, 'error' => $error, 'status' => $status, 'rest' => true];
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? $data['message'] ?? $data['error'] ?? '';
        // 400/413/422: this request was the problem, not the connection; anything else (401, 402, 403, 404, 429, 5xx) is the connection's
        return ['ok' => false, 'error' => 'HTTP ' . $status . (is_string($msg) && $msg !== '' ? ': ' . cut($msg, 200) : ''), 'status' => $status, 'rest' => !in_array($status, [400, 413, 422], true)];
    }
    $thought = false;
    if ($c['provider'] === 'anthropic') {
        $text = '';
        $stop = (string)($data['stop_reason'] ?? '');
        if ($stop === 'refusal') return ['ok' => false, 'error' => t('The request was refused.'), 'status' => $status, 'rest' => false]; // the request, not the connection: the next one may answer
        foreach ((array)($data['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $text .= (string)$part['text'];
        $thought = $stop === 'max_tokens' && trim($text) === '';
        $usage = ['in' => (int)($data['usage']['input_tokens'] ?? 0), 'out' => (int)($data['usage']['output_tokens'] ?? 0)];
    } else {
        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        $thought = ($data['choices'][0]['finish_reason'] ?? '') === 'length' && trim($text) === '';
        $usage = ['in' => (int)($data['usage']['prompt_tokens'] ?? 0), 'out' => (int)($data['usage']['completion_tokens'] ?? 0)];
    }
    $answer = ['ok' => trim($text) !== '', 'text' => trim($text), 'model' => (string)($data['model'] ?? $c['model']), 'usage' => $usage];
    if (!$answer['ok']) $answer += ['error' => $thought ? t('The model used its whole answer length thinking. Choose a model that answers directly (e.g. deepseek-chat) or a faster one.') : t('The AI gave an empty answer.'), 'status' => $status, 'rest' => false];
    return $answer;
}

/** Remember how a connection did: the last answer, the last failure and, when the failure was its own, a rest. */
function ai_note(int $n, array $r): void
{
    ai_state_update(static function (array $s) use ($n, $r): array {
        $c = (array)($s['conn'][$n] ?? []);
        if ($r['ok']) {
            $c['ok_at'] = now();
            $c['model'] = (string)$r['model'];
            unset($c['rest_until']);
        } else {
            $c['fail_at'] = now();
            $c['error'] = cut((string)$r['error'], 300);
            if (!empty($r['rest'])) $c['rest_until'] = now() + AI_REST;
        }
        $s['conn'][$n] = $c;
        return $s;
    });
}

/** The first JSON object in an answer (models like to wrap it in ```json fences or a sentence), or null. */
function ai_json(string $text): ?array
{
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $data = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($data) ? $data : null;
}

/** POST JSON over HTTPS with the certificate checked: [status, decoded body or null, error]. */
function ai_http(string $url, array $headers, string $body, int $timeout): array
{
    if (!function_exists('curl_init')) return [0, null, 'curl extension missing'];
    if (!preg_match('~^https?://~i', $url)) return [0, null, t('The AI address must start with http:// or https://.')];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION,
    ]);
    // Windows PHP often ships without a CA bundle (curl.cainfo empty): check the certificate against the system store instead of not at all
    if (PHP_OS_FAMILY === 'Windows' && (string)ini_get('curl.cainfo') === '' && (string)ini_get('openssl.cafile') === '') curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 16);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($raw === false) return [0, null, $err !== '' ? $err : 'connection failed'];
    $data = json_decode((string)$raw, true);
    return is_array($data) ? [$status, $data, ''] : [$status, null, 'HTTP ' . $status . ': ' . t('unreadable answer')];
}

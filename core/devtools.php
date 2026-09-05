<?php
/**
 * Developer tools used by the CLI (flatbb) and by the admin plugin page:
 * plugin linting, packaging, publishing to www.flatbb.com, and documentation generators.
 * Nothing here runs during normal page requests.
 */

const FLATBB_MARKET_ENDPOINT = 'https://www.flatbb.com/api/market';

/** Static checks a plugin must pass before it is accepted by the marketplace. */
function plugin_check(string $id): array
{
    $errors = [];
    $warnings = [];
    if (!plugin_id_valid($id)) return ['errors' => ['Invalid plugin id "' . $id . '" (lowercase letters, digits, underscore; 2-40 chars)'], 'warnings' => [], 'manifest' => null];
    $file = plugin_path($id, 'plugin.php');
    if (!is_file($file)) return ['errors' => ['plugins/' . $id . '/plugin.php not found'], 'warnings' => [], 'manifest' => null];
    $src = (string)file_get_contents($file);
    $php = PHP_BINARY ?: 'php';
    $lint = shell_exec_safe([$php, '-l', $file]);
    if ($lint !== null && !str_contains($lint, 'No syntax errors')) $errors[] = trim($lint);
    if (!preg_match('/defined\(\s*[\'"]FLATBB[\'"]\s*\)/', $src)) $errors[] = "Missing guard: if (!defined('FLATBB')) exit;";
    $m = plugin_read_manifest($id);
    if ($m === null) {
        $errors[] = 'plugin.php must return a manifest array with "id" => "' . $id . '"';
        return ['errors' => $errors, 'warnings' => $warnings, 'manifest' => null];
    }
    foreach (['name', 'version', 'description', 'author'] as $k) if (trim((string)($m[$k] ?? '')) === '') $errors[] = 'Manifest is missing "' . $k . '"';
    if (!preg_match('/^\d+\.\d+\.\d+$/', (string)$m['version'])) $errors[] = 'version must be semantic (x.y.z), got "' . $m['version'] . '"';
    if (mb_strlen((string)$m['description']) > 200) $warnings[] = 'description is longer than 200 characters';
    // every function defined by the plugin must carry the id prefix
    preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $src, $fns);
    foreach ($fns[1] as $fn) if (!str_starts_with($fn, $id . '_')) $errors[] = 'Function ' . $fn . '() must be prefixed with "' . $id . '_"';
    preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $cls);
    $studly = str_replace('_', '', ucwords($id, '_'));
    foreach ($cls[1] as $c) if (!str_starts_with($c, $studly)) $errors[] = 'Class ' . $c . ' must be prefixed with "' . $studly . '"';
    preg_match_all('/\bdefine\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $src, $consts);
    foreach ($consts[1] as $c) if (!str_starts_with($c, strtoupper($id) . '_')) $errors[] = 'Constant ' . $c . ' must be prefixed with "' . strtoupper($id) . '_"';
    // callbacks referenced by the manifest must exist
    $cbs = [];
    foreach ((array)$m['hooks'] as $h => $fn) foreach ((array)$fn as $f) $cbs['hooks.' . $h] = $f;
    foreach ((array)$m['routes'] as $r => $fn) $cbs['routes.' . $r] = $fn;
    foreach ((array)$m['cron'] as $n => $job) $cbs['cron.' . $n] = $job['callback'] ?? '';
    foreach (['install', 'uninstall'] as $k) if (isset($m[$k])) $cbs[$k] = $m[$k];
    foreach ((array)($m['assets']['css'] ?? []) as $a) if (!str_contains((string)$a, '.')) $cbs['assets.css'] = $a;
    foreach ((array)($m['assets']['js'] ?? []) as $a) if (!str_contains((string)$a, '.')) $cbs['assets.js'] = $a;
    foreach ($cbs as $where => $fn) if (!is_string($fn) || !function_exists($fn)) $errors[] = 'Callback "' . (is_string($fn) ? $fn : '?') . '" for ' . $where . ' is not defined';
    // table names
    preg_match_all('/db_create_table\(\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $tables);
    foreach ($tables[1] as $t) if (!str_starts_with($t, 'plugin_' . $id . '_')) $errors[] = 'Table ' . $t . ' must be named plugin_' . $id . '_*';
    foreach (security_scan(security_files(plugin_path($id))) as $f) $errors[] = $f;
    if (preg_match('/(foreach|for|while)\s*\([^)]*\)\s*\{[^}]*\b(q|one|val|all)\s*\(/s', $src)) $warnings[] = 'Possible database query inside a loop (N+1). Batch with rows_by_ids()/IN (...)';
    $installed = version_compare((string)FLATBB_VERSION, (string)($m['requires']['flatbb'] ?? '0'), '>=');
    if (!$installed) $warnings[] = 'requires flatbb ' . $m['requires']['flatbb'] . ' but this is ' . FLATBB_VERSION;
    return ['errors' => $errors, 'warnings' => $warnings, 'manifest' => $m];
}

function shell_exec_safe(array $cmd): ?string
{
    if (!function_exists('proc_open')) return null;
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

/** Zip plugins/<id>/ into dist/<id>-<version>.zip. Returns the zip path. */
function plugin_package(string $id): string
{
    $r = plugin_check($id);
    if ($r['errors'] !== []) throw new RuntimeException("Plugin check failed:\n - " . implode("\n - ", $r['errors']));
    if (!class_exists('ZipArchive')) throw new RuntimeException('The zip PHP extension is required');
    $dir = plugin_path($id);
    $dist = ROOT . '/dist';
    if (!is_dir($dist)) @mkdir($dist, 0755, true);
    $file = $dist . '/' . $id . '-' . $r['manifest']['version'] . '.zip';
    @unlink($file);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) throw new RuntimeException('Cannot create ' . $file);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if (preg_match('#(^|/)(\.git|node_modules|\.DS_Store|\.idea)(/|$)#', $rel)) continue;
        $zip->addFile($f->getPathname(), $id . '/' . $rel);
    }
    $zip->close();
    return $file;
}

/**
 * Publish a plugin to the marketplace. POST multipart: token, id, version, changelog, manifest (json), file (zip).
 * Returns ['ok' => bool, 'message' => string, 'url' => string].
 */
function plugin_publish(string $id, string $token, string $changelog = '', string $endpoint = '', bool $insecure = false): array
{
    if ($token === '') return ['ok' => false, 'message' => 'Missing token. Create one at https://www.flatbb.com/settings/developer and pass --token=... or set FLATBB_TOKEN.'];
    try {
        $zip = plugin_package($id);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $m = plugin_read_manifest($id);
    $readme = is_file(plugin_path($id, 'README.md')) ? (string)file_get_contents(plugin_path($id, 'README.md')) : '';
    $endpoint = rtrim($endpoint !== '' ? $endpoint : (string)config('market_endpoint', FLATBB_MARKET_ENDPOINT), '/') . '/publish';
    $token = trim($token);
    if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'The curl PHP extension is required to publish'];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Expect:', 'User-Agent: flatbb/' . FLATBB_VERSION, 'X-Flatbb-Site: ' . base_url(), 'X-Flatbb-Version: ' . FLATBB_VERSION],
        CURLOPT_SSL_VERIFYPEER => !$insecure, CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_POSTFIELDS => [
            'id' => $id, 'version' => (string)$m['version'], 'changelog' => $changelog, 'readme' => $readme,
            'manifest' => json_encode_value(array_intersect_key($m, array_flip(['id', 'name', 'version', 'description', 'author', 'url', 'requires', 'price']))),
            'flatbb_version' => FLATBB_VERSION,
            'file' => new CURLFile($zip, 'application/zip', basename($zip)),
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'message' => 'Upload failed: ' . $err . (str_contains($err, 'certificate') ? ' (no CA bundle for PHP curl? set curl.cainfo in php.ini or pass --insecure)' : '')];
    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['ok' => false, 'message' => 'Unexpected response (' . $status . '): ' . cut((string)$body, 300)];
    return ['ok' => !empty($json['ok']), 'message' => (string)($json['message'] ?? $json['error'] ?? ($json['ok'] ? 'Published' : 'Failed')), 'url' => (string)($json['url'] ?? '')];
}

/* ---------------------------------------------------------------- docs generators */

/** Scan core and app for hook()/fire()/region()/region_list()/slot() calls and render docs/HOOKS.md. */
function docs_hooks_markdown(): string
{
    $found = [];
    foreach (array_merge(glob(CORE_DIR . '/*.php') ?: [], glob(APP_DIR . '/*.php') ?: [], glob(VIEW_DIR . '/*.php') ?: []) as $file) {
        $src = (string)file_get_contents($file);
        $rel = ltrim(str_replace(str_replace('\\', '/', ROOT), '', str_replace('\\', '/', $file)), '/');
        preg_match_all('/\b(hook|fire|region|region_list|slot)\(\s*[\'"]([a-z0-9_.]+)[\'"]/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (str_ends_with($hit[2], '.')) $hit[2] .= '<action>'; // dynamic names such as api.<action>
            $name = $hit[1] === 'hook' || $hit[1] === 'fire' ? $hit[2] : 'region.' . $hit[2];
            $kind = match ($hit[1]) { 'fire' => 'event', 'region_list' => 'list region', 'slot' => 'inline region (loop, no DB)', 'region' => 'html region', default => 'filter' };
            $found[$name]['kind'] = $found[$name]['kind'] ?? $kind;
            $found[$name]['files'][$rel] = true;
        }
    }
    ksort($found);
    $regions = regions_known();
    $md = "# Hooks and regions\n\nGenerated by `php flatbb hooks:list` on " . date('Y-m-d') . ". Do not edit by hand.\n\n";
    $md .= "Filters receive `(\$value, array \$ctx)` and return the new value (or null to keep it). Events receive `(null, array \$ctx)`.\n";
    $md .= "Regions are filters named `region.<position>` whose value is HTML (or an array for list regions). Inline regions run inside loops: **no database queries** in their callbacks.\n\n";
    $md .= "| Hook | Kind | Description | Where |\n| --- | --- | --- | --- |\n";
    foreach ($found as $name => $info) {
        $desc = str_starts_with($name, 'region.') ? ($regions[substr($name, 7)] ?? '') : (docs_hook_descriptions()[$name] ?? '');
        $md .= '| `' . $name . '` | ' . $info['kind'] . ' | ' . $desc . ' | ' . implode(', ', array_map(static fn(string $f): string => '`' . $f . '`', array_keys($info['files']))) . " |\n";
    }
    return $md;
}

function docs_hook_descriptions(): array
{
    return [
        'app.boot' => 'Every request after plugins are loaded. Preload data here.',
        'schema.install' => 'After core tables are created/upgraded.',
        'page.options' => 'Filter the page() options (left/right columns, class, meta).',
        'page.before_output' => 'The whole HTML document before it is sent. Use for page-level placeholder replacement.',
        'markdown.before' => 'Markdown source before rendering.',
        'markdown.after' => 'Rendered HTML of a post.',
        'icon.paths' => 'Add SVG icons: name => path markup.',
        'regions.known' => 'Register extra regions for Admin → Layout.',
        'topic.before_save' => 'New topic data (category_id, user_id, title, body, tags) before insert.',
        'topic.after_save' => 'After a topic was created or edited (ctx: topic_id, post_id, new).',
        'topic.after_delete' => 'After a topic was soft-deleted.',
        'topic.after_action' => 'After pin/lock/move/restore (ctx: topic_id, action).',
        'topic.view' => 'Filter the topic row shown on the topic page (ctx: posts).',
        'topic.posts' => 'Filter the posts of the current page (batch-loaded, attach extra data here).',
        'topic_list.rows' => 'Filter topic rows of any list (batch-loaded, attach extra data here).',
        'post.before_save' => 'New reply data (body, reply_to_id) before insert.',
        'post.before_update' => 'Reply body before an edit is saved.',
        'post.after_save' => 'After a post was created or edited.',
        'post.after_delete' => 'After a reply was deleted or restored.',
        'post.after_like' => 'After a like toggle.',
        'notification.before_create' => 'Filter/veto a notification (return null to skip).',
        'notification.after_create' => 'After a notification was stored.',
        'notifications.rows' => 'Filter rows on the notifications page.',
        'account.after_login' => 'After a successful login.',
        'account.after_register' => 'After a new account was created.',
        'account.register_validate' => 'Add validation errors to registration.',
        'user.before_save' => 'Profile fields before saving.',
        'user.after_save' => 'After the profile was saved.',
        'user.prefs_save' => 'Preferences array before saving.',
        'user.profile_tab' => 'HTML for a custom profile tab (ctx: user, tab).',
        'user.settings_tab' => 'Extra HTML for a settings tab.',
        'user.settings_post' => 'POST handler for a custom settings tab.',
        'cron.jobs' => 'Filter the list of scheduled jobs.',
        'points.reasons' => 'Register point reason codes: $value[\'checkin\'] = \'Daily check-in\'. Labels show in the private history.',
        'points.before_change' => 'Filter or veto a points change (return false to block).',
        'points.after_change' => 'After points were added or removed (ctx: user_id, delta, reason, ref_id, balance).',
        'api.<action>' => 'Handle /api/<action>; return an array to respond as JSON.',
        'permissions.known' => 'Add permission keys shown in Admin → Groups.',
        'admin.settings_fields' => 'Add a settings section: $value[\'myid\'] = [\'Label\', [\'key\' => [type, label, help, options, min, max]]]. Each section is a tab in Admin → Settings.',
        'admin.settings_save' => 'Filter settings before they are saved.',
        'admin.category_save' => 'Filter category data before saving.',
        'admin.user_saved' => 'After an admin edited a user.',
        'user.after_rename' => 'After a username changed (ctx: user_id, old, new, by). Old profile URLs redirect automatically.',
        'admin.plugin_ops' => 'Extra buttons on a plugin row.',
        'admin.tools' => 'Add rows to Admin → Tools.',
        'admin.tool' => 'Handle a custom tool action.',
        'plugin.settings_saved' => 'After plugin settings were saved (ctx: id).',
        'auth.login.after' => 'HTML below the sign-in form.',
    ];
}

/** Render docs/API.md from the docblocks and signatures of core functions. */
function docs_api_markdown(): string
{
    $md = "# Core API\n\nGenerated by `php flatbb api:list` on " . date('Y-m-d') . ". Every function below is global and available to plugins.\n\n";
    foreach (array_merge(glob(CORE_DIR . '/*.php') ?: [], glob(APP_DIR . '/*.php') ?: []) as $file) {
        $src = (string)file_get_contents($file);
        $rel = ltrim(str_replace(str_replace('\\', '/', ROOT), '', str_replace('\\', '/', $file)), '/');
        preg_match_all('/(?:\/\*\*\s*((?:(?!\*\/).)*?)\s*\*\/\n)?^function\s+([a-z_0-9]+)\s*\(([^)]*)\)\s*(?::\s*([^\s{]+))?/ms', $src, $m, PREG_SET_ORDER);
        if ($m === []) continue;
        $md .= '## ' . $rel . "\n\n";
        foreach ($m as $f) {
            if (str_starts_with($f[2], 'admin_page_') || str_starts_with($f[2], 'docs_')) continue;
            $doc = trim(preg_replace('/^\s*\*\s?/m', '', $f[1] ?? '') ?? '');
            $doc = trim((string)preg_replace('/\s+/', ' ', explode("\n\n", $doc)[0]));
            $sig = $f[2] . '(' . trim(preg_replace('/\s+/', ' ', $f[3]) ?? '') . ')' . (isset($f[4]) && $f[4] !== '' ? ': ' . $f[4] : '');
            $md .= '- `' . $sig . '`' . ($doc !== '' ? ' — ' . $doc : '') . "\n";
        }
        $md .= "\n";
    }
    return $md;
}

/* ---------------------------------------------------------------- language packs */

/** Every t('...') source string in core/ and app/ (sorted). */
function lang_keys(): array
{
    $keys = [];
    $files = array_merge(glob(ROOT . '/core/*.php') ?: [], glob(ROOT . '/app/*.php') ?: [], glob(ROOT . '/app/views/*.php') ?: [], [ROOT . '/index.php', ROOT . '/flatbb']);
    foreach ($files as $file) {
        $src = (string)@file_get_contents($file);
        if (preg_match_all('/\bt\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $keys[str_replace("\\'", "'", $hit[1] !== '' ? $hit[1] : ($hit[2] ?? ''))] = true;
        }
    }
    unset($keys[''], $keys['...']);
    ksort($keys, SORT_STRING);
    return array_keys($keys);
}

/**
 * Write lang/<code>.php: keeps existing translations, adds missing keys with an empty value,
 * drops keys that no longer exist. Returns [added, removed, untranslated].
 */
function lang_sync(string $code): array
{
    if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) || $code === 'en') throw new RuntimeException('Language code must look like "de" or "pt-br" (not "en").');
    $file = LANG_DIR . '/' . $code . '.php';
    $old = is_file($file) ? (array)include $file : [];
    $keys = lang_keys();
    $new = ['__name' => (string)($old['__name'] ?? $code)];
    $added = $removed = $empty = 0;
    foreach ($keys as $k) {
        $new[$k] = (string)($old[$k] ?? '');
        if (!array_key_exists($k, $old)) $added++;
        if ($new[$k] === '') $empty++;
    }
    foreach ($old as $k => $v) if ($k !== '__name' && !in_array($k, $keys, true)) $removed++;
    $php = "<?php\n/** " . $new['__name'] . " translation of flatbb. Keys are the English source strings; empty = not translated yet. Regenerate with: php flatbb lang:sync " . $code . " */\nreturn [\n";
    foreach ($new as $k => $v) $php .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
    $php .= "];\n";
    file_put_contents($file, $php);
    return [$added, $removed, $empty];
}

/* ---------------------------------------------------------------- static security check */

/** PHP files under a directory (recursive). */
function security_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) $out[] = str_replace('\\', '/', $f->getPathname());
    sort($out);
    return $out;
}

/**
 * Rules every file must pass (core, app and plugins alike). Returns "file:line: message" findings.
 *  - no direct $_GET/$_POST/$_REQUEST/$_COOKIE reads (use post_str/post_int/post_list/post_secret/get_str/get_int)
 *  - no eval/exec/system/passthru/shell_exec/popen/proc_open/unserialize
 *  - in views: <?= ... ?> may only start with h(), t(), raw() or another known safe helper, never a bare variable
 */
function security_scan(array $files): array
{
    $found = [];
    $root = str_replace('\\', '/', ROOT) . '/';
    $allow_globals = ['core/helpers.php', 'core/auth.php', 'core/router.php', 'core/upload.php'];
    $allow_exec = ['core/devtools.php'];
    $safe = ['h', 't', 'raw', 'icon', 'region', 'slot', 'csrf_field', 'form_row', 'input', 'select', 'textarea', 'checkbox', 'avatar', 'user_link', 'human_time', 'human_number', 'human_size', 'action_form', 'editor', 'category_badge', 'tag_badge', 'tabs', 'view', 'plugin_assets_tag', 'logo_mark', 'pagination', 'md', 'date', 'json_encode_value', 'uid', 'is_array', 'isset', 'extension_loaded', 'count', 'number_format', 'layout_blocks_html'];
    foreach ($files as $file) {
        $rel = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        $src = (string)file_get_contents($file);
        $is_view = str_contains($rel, '/views/');
        foreach (explode("\n", $src) as $i => $line) {
            $n = $i + 1;
            if (!in_array($rel, $allow_globals, true) && preg_match('/\$_(GET|POST|REQUEST|COOKIE)\s*\[/', $line)) {
                $found[] = "$rel:$n: direct \$_GET/\$_POST access; use post_str(), post_int(), post_list(), post_secret(), get_str() or get_int()";
            }
            if (!in_array($rel, $allow_exec, true) && preg_match('/(?<![\w>:$])(eval|exec|system|passthru|shell_exec|popen|proc_open|unserialize)\s*\(/', $line, $d)) {
                $found[] = "$rel:$n: dangerous function call (" . $d[1] . ")";
            }
            if ($is_view && preg_match_all('/<\?=\s*(.*?)\?>/', $line, $m)) {
                foreach ($m[1] as $expr) {
                    $expr = trim($expr);
                    if ($expr === '' || preg_match('/^[A-Z][A-Z0-9_]+\b/', $expr) || preg_match('/^\((int|float)\)/', $expr) || str_contains($expr, ' ? ')) continue; // constants, numeric casts, ternaries
                    if (preg_match('/^\$[A-Za-z_]\w*(\[[^\]]*\]|->\w+)*(\s*\?\?\s*(\'[^\']*\'|"[^"]*"))?$/', $expr)) {
                        $found[] = "$rel:$n: raw variable in a template (<?= " . cut($expr, 40, '…') . " ?>); wrap it in h() or mark it raw()";
                        continue;
                    }
                    if (!preg_match('/^([a-z_]\w*)\s*\(/i', $expr, $fn) || !in_array($fn[1], $safe, true)) {
                        $found[] = "$rel:$n: unescaped output in a template (<?= " . cut($expr, 40, '…') . " ?>); use h(), t() or raw()";
                    }
                }
            }
        }
    }
    return $found;
}

/* ---------------------------------------------------------------- tests (php flatbb test) */

/**
 * Minimal test runner, no dependencies: every tests/*.php file defines test_* functions that throw on failure.
 * test_boot() gives each run a fresh SQLite database in the system temp dir, so tests never touch data/.
 */
function test_boot(): void
{
    $dir = sys_get_temp_dir() . '/flatbb-test-' . getmypid();
    @mkdir($dir, 0777, true);
    config(null, null, ['db' => ['driver' => 'sqlite', 'path' => $dir . '/test.sqlite'], 'secret' => str_repeat('t', 32), 'debug' => true, 'lang' => 'en']);
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    schema_install();
    if (val('SELECT COUNT(*) FROM fb_users') == 0) schema_seed('admin', 'admin@example.com', 'admin-password-1');
}

function test_assert(bool $ok, string $message = 'assertion failed'): void
{
    if (!$ok) throw new RuntimeException($message);
}

function test_same(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) throw new RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function test_contains(string $needle, string $haystack, string $what = 'output'): void
{
    if (!str_contains($haystack, $needle)) throw new RuntimeException($what . ' does not contain ' . var_export($needle, true) . ' (got ' . var_export(cut($haystack, 120), true) . ')');
}

function test_not_contains(string $needle, string $haystack, string $what = 'output'): void
{
    if (str_contains($haystack, $needle)) throw new RuntimeException($what . ' must not contain ' . var_export($needle, true));
}

/** Run every test_* function found in tests/*.php (or one file). Returns [passed, failed, [failures]]. */
function test_run(string $only = '', ?callable $out = null): array
{
    $out ??= static fn(string $s) => null;
    test_boot();
    $files = $only !== '' ? [ROOT . '/tests/' . basename($only)] : (glob(ROOT . '/tests/*_test.php') ?: []);
    $passed = 0; $failed = 0; $failures = [];
    foreach ($files as $file) {
        if (!is_file($file)) { $failures[] = basename($file) . ': file not found'; $failed++; continue; }
        $before = get_defined_functions()['user'];
        require_once $file;
        $new = array_diff(get_defined_functions()['user'], $before);
        foreach ($new as $fn) {
            if (!str_starts_with($fn, 'test_') || in_array($fn, ['test_boot', 'test_assert', 'test_same', 'test_contains', 'test_not_contains', 'test_run'], true)) continue;
            try {
                $fn();
                $passed++;
                $out('  ok   ' . $fn);
            } catch (Throwable $e) {
                $failed++;
                $failures[] = $fn . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                $out('  FAIL ' . $fn . ' - ' . $e->getMessage());
            }
        }
    }
    return [$passed, $failed, $failures];
}

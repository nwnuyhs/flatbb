<?php
/**
 * flatbb bootstrap.
 *
 * Load order: constants -> helpers -> db -> lang -> auth -> hook -> plugin -> render -> markdown -> upload -> search -> cron -> router.
 * Everything is plain functions. No autoloader, no framework.
 */
declare(strict_types=1);

define('FLATBB', true);
define('FLATBB_VERSION', '0.1.50');
define('ROOT', dirname(__DIR__));
define('CORE_DIR', ROOT . '/core');
define('APP_DIR', ROOT . '/app');
define('VIEW_DIR', ROOT . '/app/views');
define('DATA_DIR', ROOT . '/data');
define('CACHE_DIR', ROOT . '/data/cache');
define('PLUGIN_DIR', ROOT . '/plugins');
define('UPLOAD_DIR', ROOT . '/uploads');
define('LANG_DIR', ROOT . '/lang');
define('CONFIG_FILE', ROOT . '/data/config.php');
define('REQUEST_TIME', time());
define('IS_CLI', PHP_SAPI === 'cli');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

/** Site configuration from data/config.php. Empty array before installation. */
function config(?string $key = null, mixed $default = null, ?array $override = null): mixed
{
    static $config = null;
    if ($override !== null) $config = $override;
    if ($config === null) {
        $config = is_file(CONFIG_FILE) ? (array)(include CONFIG_FILE) : [];
    }
    if ($key === null) return $config;
    return $config[$key] ?? $default;
}

function is_installed(): bool
{
    return config('db') !== null;
}

function debug_mode(): bool
{
    return (bool)config('debug', false);
}

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $detail = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
    @error_log('[flatbb] ' . $detail);
    if (defined('DATA_DIR') && is_dir(DATA_DIR)) @file_put_contents(DATA_DIR . '/error.log', date('c') . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n" . $detail . "\n\n", FILE_APPEND | LOCK_EX);
    if (IS_CLI) {
        fwrite(STDERR, $detail . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $show = debug_mode();
    if (!$show) { try { $show = function_exists('is_admin') && is_admin(); } catch (Throwable) { $show = false; } } // admins see the cause; visitors do not
    $body = $show ? '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre><p>Also written to data/error.log.</p>' : '<p>Something went wrong. Please try again later.</p>';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:system-ui;padding:40px;max-width:900px;margin:auto"><h1>500</h1>' . $body;
    exit;
});

foreach (['helpers', 'db', 'schema', 'lang', 'auth', 'security', 'verify', 'hook', 'manifest', 'plugin', 'render', 'markdown', 'upload', 'search', 'cron', 'router', 'points', 'devtools', 'migrate', 'upgrade'] as $file) {
    require CORE_DIR . '/' . $file . '.php';
}

foreach (glob(APP_DIR . '/*.php') ?: [] as $file) {
    require $file;
}

/** Boot the application for a web request or CLI command. */
function app_boot(): void
{
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    upload_protect_dirs();
    if (!is_installed()) return;
    // an in-place upgrade copies the files while the old code is still loaded: new tables, columns and indexes are created here,
    // on the first request that runs the new code (schema helpers are idempotent; SCHEMA_VERSION is bumped with every schema change)
    if (setting('schema_version', '') !== (string)SCHEMA_VERSION) schema_install();
    $tz = setting('site_tz', 'UTC');
    if ($tz !== 'UTC' && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz); // server-side dates (emails, feeds, logs); browsers show their own zone
    plugins_load();
    fire('app.boot', []);
}

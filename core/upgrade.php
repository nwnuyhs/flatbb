<?php
/**
 * Core updates: check the marketplace for the latest release and upgrade in place.
 *
 *   php flatbb upgrade:check
 *   php flatbb upgrade            (downloads, verifies, replaces core files, runs schema upgrade)
 *   php flatbb release            (maintainers: build dist/flatbb-<version>.zip + sha256 for publishing)
 *   Admin → Tools → Updates
 *
 * The package replaces index.php, flatbb, core/, app/, assets/, lang/, docs/, the bundled plugins
 * (hello, market) and top-level docs. It never touches data/, uploads/ or other plugins.
 */

function upgrade_endpoint(): string
{
    return rtrim((string)config('market_endpoint', FLATBB_MARKET_ENDPOINT), '/');
}

/** ['version','url','download','sha256'] or ['error' => ...]. Cached for 6 hours unless $force. */
function upgrade_check(bool $force = false): array
{
    $cached = json_decode_array(setting('core_update_cache', ''));
    if (!$force && !empty($cached['checked_at']) && now() - (int)$cached['checked_at'] < 21600) return $cached;
    $body = upgrade_http_get(upgrade_endpoint() . '/core/latest', 65536, $error);
    if ($body === null) return ['error' => $error ?? 'unreachable', 'checked_at' => now()];
    $d = json_decode_array($body);
    $info = ['version' => (string)($d['version'] ?? ''), 'url' => (string)($d['url'] ?? ''), 'download' => (string)($d['download'] ?? ''), 'sha256' => (string)($d['sha256'] ?? ''), 'checked_at' => now()];
    if (!preg_match('/^\d+\.\d+\.\d+$/', $info['version'])) return ['error' => 'bad response', 'checked_at' => now()];
    save_settings(['core_update_cache' => json_encode_value($info)]);
    return $info;
}

function upgrade_available(): ?array
{
    $i = json_decode_array(setting('core_update_cache', ''));
    return !empty($i['version']) && version_compare((string)$i['version'], FLATBB_VERSION, '>') ? $i : null;
}

function upgrade_http_get(string $url, int $max_bytes, ?string &$error = null): ?string
{
    if (!function_exists('curl_init')) { $error = 'curl extension missing'; return null; }
    if (http_self_request_blocked($url)) { $error = 'self request skipped on the dev server'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' upgrade', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => static fn($r, $t, $dl): int => $dl > $max_bytes ? 1 : 0]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) { $error = $err !== '' ? $err : 'HTTP ' . $status; return null; }
    return (string)$body;
}

/** Files and directories replaced by an upgrade (relative to ROOT). */
function upgrade_paths(): array
{
    return ['index.php', 'flatbb', '.htaccess', 'nginx.conf.example', 'CLAUDE.md', 'AGENTS.md', 'README.md', 'LICENSE', 'core', 'app', 'assets', 'lang', 'docs', 'plugins/hello', 'plugins/market', 'plugins/nav_menu', '.claude', '.github', 'tests'];
}

/**
 * Download and apply the latest release (or a given zip file). Returns the new version string.
 * Throws RuntimeException with a user-readable message; the site is only modified after the package was verified.
 */
function upgrade_apply(?string $zip_file = null, ?callable $log = null): string
{
    $log ??= static function (string $s): void {};
    if (!class_exists('ZipArchive')) throw new RuntimeException('The zip PHP extension is required');
    $info = null;
    if ($zip_file === null) {
        $info = upgrade_check(true);
        if (!empty($info['error'])) throw new RuntimeException('Update check failed: ' . $info['error']);
        if (version_compare((string)$info['version'], FLATBB_VERSION, '<=')) throw new RuntimeException('Already up to date (' . FLATBB_VERSION . ')');
        if ($info['download'] === '') throw new RuntimeException('The release has no download URL');
        $log('Downloading ' . $info['download']);
        $body = upgrade_http_get((string)$info['download'], 50 * 1048576, $error);
        if ($body === null) throw new RuntimeException('Download failed: ' . $error);
        $zip_file = CACHE_DIR . '/flatbb-' . $info['version'] . '.zip';
        file_put_contents($zip_file, $body, LOCK_EX);
        if ($info['sha256'] !== '' && !hash_equals(strtolower((string)$info['sha256']), hash_file('sha256', $zip_file))) { @unlink($zip_file); throw new RuntimeException('Checksum mismatch, download discarded'); }
    }
    if (!is_file($zip_file)) throw new RuntimeException('Package not found: ' . $zip_file);
    $tmp = CACHE_DIR . '/upgrade_' . random_token(4);
    @mkdir($tmp, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) throw new RuntimeException('Invalid zip');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (str_contains($n, '..')) { $zip->close(); throw new RuntimeException('Unsafe path in package: ' . $n); }
    }
    $zip->extractTo($tmp);
    $zip->close();
    // the package root may be the files directly or a single folder such as flatbb/
    $root = $tmp;
    if (!is_file($root . '/index.php') && !is_file($root . '/core/boot.php')) {
        foreach (glob($tmp . '/*', GLOB_ONLYDIR) ?: [] as $d) if (is_file($d . '/core/boot.php')) { $root = $d; break; }
    }
    if (!is_file($root . '/core/boot.php') || !is_file($root . '/index.php')) { upgrade_rmdir($tmp); throw new RuntimeException('Package does not look like a flatbb release'); }
    $new_version = '';
    if (preg_match("/define\('FLATBB_VERSION',\s*'([^']+)'\)/", (string)file_get_contents($root . '/core/boot.php'), $m)) $new_version = $m[1];
    if ($new_version === '') { upgrade_rmdir($tmp); throw new RuntimeException('Cannot read the version of the package'); }
    if ($info === null && version_compare($new_version, FLATBB_VERSION, '<')) { upgrade_rmdir($tmp); throw new RuntimeException('Package ' . $new_version . ' is older than the installed ' . FLATBB_VERSION); }
    // writable check before touching anything
    foreach (upgrade_paths() as $rel) {
        $target = ROOT . '/' . $rel;
        if (file_exists($target) && !is_writable($target)) { upgrade_rmdir($tmp); throw new RuntimeException('Not writable: ' . $rel); }
    }
    $log('Installing ' . $new_version);
    $backup = DATA_DIR . '/backup-' . FLATBB_VERSION . '-' . date('Ymd-His');
    @mkdir($backup, 0755, true);
    foreach (upgrade_paths() as $rel) {
        $from = $root . '/' . $rel;
        $to = ROOT . '/' . $rel;
        if (!file_exists($from)) continue;
        if (file_exists($to)) upgrade_copy($to, $backup . '/' . $rel);
        if (file_exists($to)) upgrade_rmdir($to); // also removes a stray directory where a file is expected (or vice versa)
        if (is_dir($from)) { upgrade_copy($from, $to); }
        else { @mkdir(dirname($to), 0755, true); if (!@copy($from, $to)) throw new RuntimeException('Cannot write ' . $rel); }
    }
    upgrade_rmdir($tmp);
    $log('Backup of the previous files: ' . $backup);
    // finish: caches. This process still runs the old code, so the schema is brought up to date by app_boot() on the first request
    // with the new code (SCHEMA_VERSION differs from the stored one); schema_install() here only covers helpers that already exist.
    schema_install();
    foreach (glob(CACHE_DIR . '/*') ?: [] as $f) if (is_file($f) && !str_starts_with(basename($f), 'plugins.')) @unlink($f);
    plugin_sync();
    save_settings(['stats_cache' => '', 'core_update_cache' => '']);
    if (function_exists('opcache_reset')) @opcache_reset();
    fire('upgrade.after_apply', ['from' => FLATBB_VERSION, 'to' => $new_version]);
    return $new_version;
}

function upgrade_copy(string $from, string $to): void
{
    if (is_file($from)) { @mkdir(dirname($to), 0755, true); copy($from, $to); return; }
    @mkdir($to, 0755, true);
    foreach (scandir($from) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        upgrade_copy($from . '/' . $e, $to . '/' . $e);
    }
}

function upgrade_rmdir(string $dir): void
{
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        upgrade_rmdir($dir . '/' . $e);
    }
    @rmdir($dir);
}

/** Maintainers: build dist/flatbb-<version>.zip from this checkout and print its sha256. */
function upgrade_build_release(): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('zip extension missing');
    $dist = ROOT . '/dist';
    @mkdir($dist, 0755, true);
    $file = $dist . '/flatbb-' . FLATBB_VERSION . '.zip';
    @unlink($file);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) throw new RuntimeException('cannot create ' . $file);
    $top = 'flatbb-' . FLATBB_VERSION . '/'; // versioned folder: moving its contents up never collides with the CLI file named flatbb
    foreach (upgrade_paths() as $rel) {
        $path = ROOT . '/' . $rel;
        if (!file_exists($path)) continue;
        if (is_file($path)) { $zip->addFile($path, $top . $rel); continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $r = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT) + 1));
            if ($r === 'docs/PLAN.md') continue; // internal planning notes stay out of the public package
            $zip->addFile($f->getPathname(), $top . $r);
        }
    }
    $zip->addEmptyDir($top . 'data');
    $zip->addEmptyDir($top . 'uploads');
    $zip->close();
    return ['file' => $file, 'sha256' => hash_file('sha256', $file), 'size' => (int)filesize($file), 'version' => FLATBB_VERSION];
}

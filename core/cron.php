<?php
/**
 * Scheduled jobs. Trigger every minute with either:
 *   GET  /cron?key=<cron_key setting>
 *   php flatbb cron
 * Core jobs live in cron_jobs(); plugins declare 'cron' => ['name' => ['callback' => fn, 'interval' => seconds|fn]].
 * Jobs must be idempotent; the runner holds a lock file so overlapping triggers are skipped.
 */

function cron_jobs(): array
{
    $jobs = [
        'core.hot_scores' => ['callback' => 'cron_hot_scores', 'interval' => 1800, 'plugin' => ''],
        'core.cleanup' => ['callback' => 'cron_cleanup', 'interval' => 86400, 'plugin' => ''],
        'core.stats' => ['callback' => 'cron_stats', 'interval' => 300, 'plugin' => ''],
    ];
    foreach (plugin_manifests() as $id => $m) {
        foreach ((array)($m['cron'] ?? []) as $name => $job) {
            if (empty($job['callback'])) continue;
            $jobs[$id . '.' . $name] = ['callback' => $job['callback'], 'interval' => $job['interval'] ?? 3600, 'plugin' => $id];
        }
    }
    return (array)hook('cron.jobs', $jobs, []);
}

function cron_interval(array $job): int
{
    $i = $job['interval'];
    if (is_string($i) && function_exists($i)) $i = $i();
    return max(60, min(31536000, (int)$i));
}

/** Run every due job. Returns [name => status]. */
function cron_run(bool $force = false): array
{
    $lock = CACHE_DIR . '/cron.lock';
    $fp = @fopen($lock, 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) return ['_' => 'locked'];
    $state = [];
    foreach (all('SELECT * FROM fb_cron') as $r) $state[$r['name']] = $r;
    $out = [];
    foreach (cron_jobs() as $name => $job) {
        $last = (int)($state[$name]['last_run'] ?? 0);
        if (!$force && now() - $last < cron_interval($job)) continue;
        $status = 'ok';
        try {
            $r = is_callable($job['callback']) ? ($job['callback'])($job) : 'missing callback';
            if (is_string($r) && $r !== '') $status = cut($r, 200, '');
        } catch (Throwable $e) {
            $status = 'error: ' . cut($e->getMessage(), 180, '');
            @error_log('[flatbb cron] ' . $name . ' ' . $e->getMessage());
        }
        db_upsert('fb_cron', ['name' => $name, 'last_run' => now(), 'last_status' => $status, 'run_count' => (int)($state[$name]['run_count'] ?? 0) + 1], ['name']);
        $out[$name] = $status;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $out;
}

function cron_run_web(): never
{
    $key = get_str('key', 64);
    if ($key === '' || !hash_equals(setting('cron_key', ''), $key)) json_error('invalid key', 403);
    json_ok(['ran' => cron_run()]);
}

/* ---------------------------------------------------------------- core jobs */

/** Recompute hot_score for topics active in the last 30 days. */
function cron_hot_scores(): string
{
    $since = now() - 86400 * 30;
    $rows = all('SELECT id,reply_count,view_count,like_count,created_at FROM fb_topics WHERE is_deleted=0 AND last_post_at>? ', [$since]);
    tx(static function () use ($rows): void {
        foreach ($rows as $t) {
            $hours = max(0, (now() - (int)$t['created_at']) / 3600);
            $score = ((int)$t['like_count'] * 3 + (int)$t['reply_count'] * 2 + (int)$t['view_count'] / 20) / (($hours + 2) ** 1.4);
            db_update('fb_topics', ['hot_score' => round($score, 4)], 'id=?', [(int)$t['id']]);
        }
    });
    return count($rows) . ' topics';
}

function cron_cleanup(): string
{
    $n = db_delete('fb_notifications', 'is_read=1 AND created_at<?', [now() - 86400 * 90]);
    $n += db_delete('fb_attachments', 'post_id=0 AND created_at<?', [now() - 86400 * 2]);
    $n += db_delete('fb_admin_log', 'created_at<?', [now() - 86400 * 180]);
    return $n . ' rows';
}

function cron_stats(): string
{
    save_settings(['stats_cache' => '']);
    request_cache('site_stats', null, true);
    site_stats();
    return 'refreshed';
}

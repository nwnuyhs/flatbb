<?php
/**
 * Admin → Import: the importer plugins (core/import.php) and the progress of the current job.
 * The importer's own page (connect, check) starts a job with import_start(); both pages show it with import_job_html(),
 * whose page script calls POST /admin/import action=step until the job ends.
 */

/** GET|POST /admin/import */
function admin_page_import(): never
{
    if (is_post()) {
        $action = post_str('action', 20);
        if ($action === 'step') json_ok(['job' => import_job_view(import_run(6.0))]);
        match ($action) {
            'cancel' => import_cancel(),
            'retry' => import_retry(),
            default => null,
        };
        $back = post_str('back', 300);
        redirect(str_starts_with($back, base_path() . '/admin') ? $back : admin_url('import'));
    }
    $job = import_job();
    if (get_str('report', 1) === '1' && $job !== []) import_report_download($job);
    $html = '<p class="muted">' . t('Bring members, topics and replies over from another forum. Each source forum has its own importer plugin.') . '</p>';
    if ($job !== []) {
        $imp = importer((string)$job['plugin']);
        $html .= '<div class="admin-form import-current"><h3>' . t('Import from %s', $imp['label'] ?? (string)$job['plugin']) . '</h3>' . import_job_html($job, admin_url('import')) . '</div>';
    }
    $rows = '';
    foreach (importers() as $imp) {
        $open = $imp['enabled'] && $imp['page'] !== '' ? url('/admin/ext/' . $imp['plugin'] . '/' . $imp['page']) : '';
        $rows .= '<div class="import-row"><span class="import-icon">' . icon('message') . '</span><div class="import-row-main"><b>' . h($imp['name']) . '</b> '
            . ($imp['enabled'] ? '<span class="import-pill is-on">' . t('Enabled') . '</span>' : '<span class="import-pill">' . t('Disabled') . '</span>')
            . '<div class="muted small">' . h($imp['description']) . '</div></div>'
            . ($open !== '' ? '<a class="btn btn-primary" href="' . h($open) . '">' . t('Run importer') . '</a>' : '<a class="btn" href="' . h(admin_url('plugins')) . '">' . t('Enable in Plugins') . '</a>') . '</div>';
    }
    $market = plugin_enabled('market') ? url('/admin/ext/market/market') : 'https://www.flatbb.com/market';
    $rows .= '<div class="import-row"><span class="import-icon">' . icon('puzzle') . '</span><div class="import-row-main"><b>' . t('More importers') . '</b><div class="muted small">'
        . t('Importers for other forums appear here once installed from the marketplace.') . '</div></div><a class="btn" href="' . h($market) . '">' . t('Browse marketplace') . '</a></div>';
    $html .= '<div class="import-list">' . $rows . '</div>';
    $html .= '<p class="muted small">' . t('Moving this FlatBB to MySQL instead? Use Tools → Import from SQLite.') . '</p>';
    admin_page(t('Import'), $html, 'import');
}

/** The job without the importer's state and secret, for the page script. */
function import_job_view(array $job): array
{
    $phases = [];
    foreach ((array)($job['phases'] ?? []) as $p) $phases[] = ['key' => (string)$p['key'], 'done' => (int)$p['done'], 'total' => (int)$p['total']];
    return ['status' => (string)($job['status'] ?? ''), 'phase' => (int)($job['phase'] ?? 0), 'phases' => $phases, 'busy' => !empty($job['busy'])];
}

/** One phase's numbers: "31,200 / 74,590", "waiting", "done". */
function import_phase_text(array $p, bool $current, bool $past): string
{
    if ((int)$p['total'] > 0) return number_format((int)$p['done']) . ' / ' . number_format((int)$p['total']);
    if ($past) return (int)$p['done'] > 0 ? number_format((int)$p['done']) : t('done');
    return $current ? t('running') : t('waiting');
}

/** Progress bars, status and the job's buttons; a running job carries data-import, which the page script keeps going. */
function import_job_html(array $job, string $back): string
{
    $status = (string)($job['status'] ?? '');
    $cur = (int)($job['phase'] ?? 0);
    $bars = '';
    foreach ((array)$job['phases'] as $i => $p) {
        $past = $i < $cur || $status === 'done';
        $pct = $past ? 100 : ((int)$p['total'] > 0 ? min(100, (int)floor((int)$p['done'] * 100 / (int)$p['total'])) : 0);
        $bars .= '<div class="import-phase" data-key="' . h((string)$p['key']) . '"><div class="import-phase-head"><span>' . h(t((string)$p['label'])) . '</span>'
            . '<span class="muted import-count">' . h(import_phase_text($p, $i === $cur, $past)) . '</span></div><div class="import-bar"><i style="width:' . $pct . '%"></i></div></div>';
    }
    $imp = importer((string)$job['plugin']);
    $hidden = ['back' => $back];
    $html = '<div class="import-job" data-import="' . h($status) . '" data-url="' . h(admin_url('import')) . '">' . $bars;
    if ($status === 'running') {
        $html .= '<p class="muted small import-status">' . t('Importing… Closing this page pauses the import; it continues when you open it again.') . '</p>'
            . '<p class="muted small">' . t('A large forum? Run it from the command line instead:') . ' <code>php flatbb import ' . h($imp['from'] ?? (string)$job['plugin']) . '</code></p>'
            . action_form(admin_url('import'), '<button class="btn btn-sm">' . t('Cancel import') . '</button>', ['action' => 'cancel'] + $hidden, '', t('Stop the import? What was imported so far stays until you start again.'));
    } elseif ($status === 'failed') {
        $html .= '<div class="flash flash-error">' . t('The import stopped: %s', (string)$job['error']) . '</div><div class="btn-row">'
            . action_form(admin_url('import'), '<button class="btn btn-primary">' . t('Try again') . '</button>', ['action' => 'retry'] + $hidden, 'inline')
            . action_form(admin_url('import'), '<button class="btn">' . t('Cancel import') . '</button>', ['action' => 'cancel'] + $hidden, 'inline', t('Stop the import? What was imported so far stays until you start again.')) . '</div>';
    } elseif ($status === 'cancelled') {
        $html .= '<p class="muted small">' . t('Cancelled. What was imported so far stays; starting the import again removes it first.') . '</p>';
    } elseif ($status === 'done') {
        $html .= '<p class="import-done">' . icon('check') . ' <b>' . t('Done in %s', import_duration((float)$job['seconds'])) . '</b></p>';
        $notes = (array)($job['notes'] ?? []);
        if ($notes !== []) {
            $html .= '<details class="import-notes"><summary>' . t('%d notes', count($notes)) . '</summary><ul>';
            foreach (array_slice($notes, 0, 50) as $n) $html .= '<li>' . h((string)$n) . '</li>';
            $html .= '</ul></details>';
        }
        $html .= '<div class="btn-row"><a class="btn" href="' . h(admin_url('import', ['report' => 1])) . '">' . icon('download') . t('Download report') . '</a><a class="btn" href="' . h(url('/')) . '">' . t('View forum') . '</a></div>';
    }
    return $html . '</div>';
}

/** Step pills for an importer page: import_steps_html(['Connect', 'Check', 'Import', 'Done'], 2) (English labels, 1-based current step). */
function import_steps_html(array $labels, int $current): string
{
    $h = '<div class="import-steps">';
    foreach (array_values($labels) as $i => $label) {
        $n = $i + 1;
        $h .= '<span class="' . ($n === $current ? 'is-on' : ($n < $current ? 'is-done' : '')) . '">' . ($n < $current ? icon('check') : $n . ' ') . h(t((string)$label)) . '</span>';
    }
    return $h . '</div>';
}

/** Numbers found in the source forum: import_stats_html(['Members' => 3412, 'Topics' => 9806]). */
function import_stats_html(array $stats): string
{
    $h = '<div class="import-stats">';
    foreach ($stats as $label => $n) $h .= '<div><span class="muted small">' . h(t((string)$label)) . '</span><b>' . number_format((int)$n) . '</b></div>';
    return $h . '</div>';
}

function import_duration(float $s): string
{
    $s = (int)round($s);
    return $s < 60 ? t('%d s', $s) : t('%d min %d s', intdiv($s, 60), $s % 60);
}

/** The report as a text file: what went where, and every note. */
function import_report_download(array $job): never
{
    $imp = importer((string)$job['plugin']);
    $lines = ['FlatBB import report', 'Source: ' . ($imp['name'] ?? $job['plugin']), 'Status: ' . $job['status'], 'Started: ' . date('Y-m-d H:i', (int)$job['started_at']),
        'Time: ' . import_duration((float)$job['seconds']), ''];
    foreach ((array)$job['phases'] as $p) $lines[] = str_pad((string)$p['label'], 24) . (int)$p['done'] . ((int)$p['total'] > 0 ? ' / ' . (int)$p['total'] : '');
    $lines[] = '';
    foreach ((array)($job['notes'] ?? []) as $n) $lines[] = '- ' . $n;
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="import-report-' . date('Ymd-His', (int)$job['started_at']) . '.txt"');
    echo implode("\n", $lines) . "\n";
    exit;
}

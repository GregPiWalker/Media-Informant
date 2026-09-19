<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/parser.php';
require dirname(__DIR__) . '/lib/tmdb.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
set_time_limit(60);
grok_request_cancel();

$lock = scan_lock_open();
if (scan_lock_try($lock)) {
    $job = grok_job_read();
    if (($job['state'] ?? '') === 'running') {
        grok_job_halt($job);
    } else {
        grok_clear_cancel();
        $status = grok_status_read();
        if (($status['state'] ?? '') === 'running') {
            grok_status_write([
                'state' => 'stopped',
                'cancel_requested' => false,
                'pending' => 0,
                'kind' => 'grok',
                'message' => 'Grok stopped.',
            ]);
        }
    }
    scan_lock_release($lock);
} elseif (is_resource($lock)) {
    fclose($lock);
}

$ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
if ($ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(grok_status_reconcile(), [
        'ok' => true,
        'kind' => 'grok',
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Location: ' . app_href('video/resolve.php'), true, 303);
exit;

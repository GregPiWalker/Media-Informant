<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/parser.php';
require dirname(__DIR__) . '/lib/scanner.php';

cache_init();
set_time_limit(120);
scan_request_cancel();

$lock = scan_lock_open();
if (scan_lock_try($lock)) {
    $job = scan_job_read();
    if (($job['state'] ?? '') === 'running') {
        try {
            scan_job_finalize($job, true);
        } catch (Throwable $e) {
            scan_status_write([
                'state' => 'error',
                'cancel_requested' => false,
                'catalog' => scan_job_catalog($job),
                'message' => $e->getMessage(),
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
    echo json_encode(array_merge(scan_status_read(), ['ok' => true]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

render_start('Stopping music scan · Media Informant');
render_header(['section' => 'music', 'branches' => true]);
?>
<main class="page scan-page">
  <section class="panel">
    <p class="scan-kicker">Stopping</p>
    <h1>Music scan stop requested</h1>
    <p>If a scan is running, it will halt as soon as it finishes the current step. Music scanning is not available yet.</p>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('music/scan.php')) ?>">Back to music scan</a>
  </section>
</main>
<?php
render_end();

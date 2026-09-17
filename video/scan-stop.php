<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/parser.php';
require dirname(__DIR__) . '/lib/tmdb.php';
require dirname(__DIR__) . '/lib/scanner.php';

cache_init();
set_time_limit(120);
scan_request_cancel();

$lockPath = CACHE_DIR . '/scan.lock';
$lock = @fopen($lockPath, 'c');
if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
    $job = scan_job_read();
    if (($job['state'] ?? '') === 'running') {
        try {
            scan_job_finalize($job, true);
        } catch (Throwable $e) {
            scan_status_write([
                'state' => 'error',
                'cancel_requested' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

$ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
if ($ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(scan_status_read(), ['ok' => true]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

render_start('Stopping scan · Media Informant');
render_header(['section' => 'video', 'branches' => true]);
?>
<main class="page scan-page">
  <section class="panel">
    <p class="scan-kicker">Stopping</p>
    <h1>Stop requested</h1>
    <p>The scan will halt as soon as it finishes the title it is on. Lookups already done stay in the catalog. New files not looked up yet are listed from their names.</p>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('video/index.php')) ?>">Back to Video</a>
  </section>
</main>
<?php
render_end();

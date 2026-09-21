<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
header('Cache-Control: no-store');

$catalog = 'video';
$status = scan_status_read();
$running = ($status['state'] ?? '') === 'running';
$stopping = $running && (!empty($status['cancel_requested']) || ($status['phase'] ?? '') === 'stopping');
$runMode = (string) ($_GET['run'] ?? '');
if ($runMode === '1' || $runMode === 'unidentified' || $runMode === 'retry') {
    $runMode = 'retry';
} else {
    $runMode = '';
}
$grokAvailable = grok_resolve_available();
$grokOn = grok_live_enabled();
$showGrokUi = $grokAvailable && $grokOn;
$scanEnabled = true;
$scanOptions = scan_options_read($catalog);
$idleCopy = $showGrokUi
    ? 'Scan looks up files already in the catalog. TMDB queues Grok work. Every 25 queued titles, Grok runs a batch. Continue after each Grok batch. New folders are indexed when you save them in Config.'
    : 'Scan looks up files already in the catalog with TMDB. Unmatched titles stay unmatched unless you turn Grok on. New folders are indexed when you save them in Config.';

render_start('Video scan · Media Informant');
render_header(['section' => 'config', 'branches' => true]);
require app_view('scan');
render_end();

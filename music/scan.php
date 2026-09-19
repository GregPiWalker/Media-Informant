<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
header('Cache-Control: no-store');

$catalog = 'music';
$status = scan_status_read();
$running = ($status['state'] ?? '') === 'running';
$stopping = $running && (!empty($status['cancel_requested']) || ($status['phase'] ?? '') === 'stopping');
$runMode = '';
$grokAvailable = false;
$grokOn = false;
$showGrokUi = false;
$scanEnabled = false;
$scanOptions = scan_options_read($catalog);
$idleCopy = 'Music scanning is not available yet. You can set music folders in Config and review options here. A video scan and a music scan cannot run at the same time.';

render_start('Music scan · Media Informant');
render_header(['section' => 'config', 'branches' => true]);
require app_view('scan');
render_end();

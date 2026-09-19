<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$lock = scan_lock_open();
if ($lock === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cache']);
    exit;
}
if (!scan_lock_try($lock)) {
    echo json_encode(scan_busy_status(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fclose($lock);
    exit;
}

$status = scan_status_read();
$payload = array_merge($status, [
    'ok' => false,
    'busy' => false,
    'unsupported' => true,
    'state' => (($status['state'] ?? '') === 'running') ? $status['state'] : 'idle',
    'catalog' => 'music',
    'kind' => 'scan',
    'message' => 'Music scanning is not available yet.',
]);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
scan_lock_release($lock);

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
set_time_limit(120);
ignore_user_abort(true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

if (function_exists('session_write_close')) {
    session_write_close();
}

$lockPath = CACHE_DIR . '/scan.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cache']);
    exit;
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(array_merge(scan_status_read(), [
        'ok' => true,
        'busy' => true,
        'kind' => 'scan',
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fclose($lock);
    exit;
}

try {
    $allowStart = isset($_GET['start']) || isset($_POST['start']);
    $resumePause = isset($_GET['continue']) || isset($_POST['continue']);
    $mode = (string) ($_GET['mode'] ?? $_POST['mode'] ?? 'retry');
    $status = scan_tick($allowStart, $mode, $resumePause);
    echo json_encode(array_merge($status, [
        'ok' => true,
        'busy' => false,
        'kind' => 'scan',
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    scan_status_write([
        'state' => 'error',
        'cancel_requested' => false,
        'message' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'state' => 'error', 'error' => $e->getMessage(), 'message' => $e->getMessage()]);
}

flock($lock, LOCK_UN);
fclose($lock);

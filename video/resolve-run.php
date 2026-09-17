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
    $scan = scan_status_read();
    if (($scan['state'] ?? '') === 'running') {
        echo json_encode(array_merge($scan, [
            'ok' => true,
            'busy' => true,
            'kind' => 'tmdb',
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(array_merge(grok_status_read(), [
            'ok' => true,
            'busy' => true,
            'kind' => 'grok',
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    fclose($lock);
    exit;
}

try {
    $allowStart = isset($_GET['start']) || isset($_POST['start']);
    $status = grok_tick($allowStart);
    echo json_encode(array_merge($status, [
        'ok' => true,
        'busy' => false,
        'kind' => 'grok',
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    grok_status_write([
        'state' => 'error',
        'message' => grok_scrub($e->getMessage()),
    ]);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'state' => 'error',
        'error' => grok_scrub($e->getMessage()),
        'message' => grok_scrub($e->getMessage()),
    ]);
}

flock($lock, LOCK_UN);
fclose($lock);

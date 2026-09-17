<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}
$on = ($data['enabled'] ?? '') === '1' || ($data['enabled'] ?? '') === 1 || ($data['enabled'] ?? '') === true;
grok_pause_each_batch_write($on);
$pause = grok_dev_pause_each_batch();
if (function_exists('app_log')) {
    app_log('grok', 'Grok pause-after-batch ' . ($pause ? 'on' : 'off') . '.');
}
$status = scan_status_read();
echo json_encode(array_merge($status, [
    'ok' => true,
    'pause' => $pause,
    'grok_pause' => $pause,
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$since = isset($_GET['since']) ? (float) $_GET['since'] : 0.0;
$events = app_log_read($since, 250);
echo json_encode([
    'ok' => true,
    'events' => $events,
    'now' => microtime(true),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

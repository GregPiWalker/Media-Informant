<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

echo json_encode(array_merge(scan_status_read(), [
    'ok' => true,
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

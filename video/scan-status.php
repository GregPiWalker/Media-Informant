<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$scan = scan_status_read();
$grok = grok_status_reconcile();
if (($grok['state'] ?? '') === 'running') {
    echo json_encode(array_merge($grok, [
        'kind' => 'grok',
        'ok' => true,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
echo json_encode(array_merge($scan, [
    'kind' => 'tmdb',
    'ok' => true,
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

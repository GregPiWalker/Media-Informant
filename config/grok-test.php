<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/grok.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$send = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"message":"Could not encode the response."}' : $json;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $send(['ok' => false, 'valid' => false, 'rejected' => false, 'message' => 'Use POST.'], 405);
}

cache_init();

$key = isset($_POST['xai_api_key']) && is_string($_POST['xai_api_key']) ? $_POST['xai_api_key'] : '';

try {
    $result = grok_key_test($key);
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('grok', 'Grok key test failed.', [], 'warn');
    }
    $send([
        'ok' => false,
        'valid' => false,
        'rejected' => false,
        'message' => 'The key test stopped before it finished.',
    ], 500);
}

$send($result, !empty($result['ok']) || !empty($result['rejected']) ? 200 : 400);

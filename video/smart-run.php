<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/smart_search.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$send = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"error":"Could not encode the response."}' : $json;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $send(['ok' => false, 'error' => 'Use POST.'], 405);
}

cache_init();

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
if (!in_array($action, ['send', 'continue', 'retry', 'discard'], true)) {
    $send(['ok' => false, 'error' => 'Unknown action.'], 400);
}
$text = isset($_POST['text']) && is_string($_POST['text']) ? $_POST['text'] : '';

try {
    $state = smart_search_handle($action, $text);
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('smart-search', 'Smart search failed.', [], 'error');
    }
    $send(['ok' => false, 'error' => 'Smart Search stopped before it could finish.', 'status' => 'error', 'messages' => []], 500);
}

$status = !empty($state['ok']) ? 200 : 400;
$send($state, $status);

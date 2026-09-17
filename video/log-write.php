<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';

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
$topic = (string) ($data['topic'] ?? 'ui');
$message = (string) ($data['message'] ?? '');
$level = (string) ($data['level'] ?? 'info');
$context = $data['context'] ?? [];
if (!is_array($context)) {
    $context = [];
}
if (trim($message) === '') {
    echo json_encode(['ok' => false]);
    exit;
}
app_log($topic, $message, $context, $level);
echo json_encode(['ok' => true]);

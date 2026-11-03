<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';

$file = isset($_GET['file']) && is_string($_GET['file']) ? $_GET['file'] : '';
if (!preg_match('/^[a-f0-9]{16}\.(jpg|png|webp|gif)$/', $file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

$path = CACHE_DIR . '/posters/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

$ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
$size = filesize($path);
if ($size !== false) {
    header('Content-Length: ' . (string) $size);
}
readfile($path);

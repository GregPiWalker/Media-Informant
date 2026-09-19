<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/tools.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

cache_init();
$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

$src = (string) ($data['src'] ?? '');
$rel = (string) ($data['dir'] ?? '');
$find = (string) ($data['find'] ?? '');
$replace = (string) ($data['replace'] ?? '');
$extensions = $data['extensions'] ?? [];
if (!is_array($extensions)) {
    $extensions = [];
}

$source = tools_source_by_id($src);
if ($source === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown source.']);
    exit;
}
if (empty($source['present'])) {
    echo json_encode(['ok' => false, 'error' => 'That source is absent.']);
    exit;
}
if ($find === '') {
    echo json_encode(['ok' => false, 'error' => 'Enter the text to find in filenames.']);
    exit;
}

$abs = tools_resolve_dir((string) $source['path'], $rel);
if ($abs === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Folder not found or not inside the source.']);
    exit;
}
if (!is_writable($abs)) {
    echo json_encode(['ok' => false, 'error' => 'Folder is not writable. Check share permissions.']);
    exit;
}

$lock = function_exists('scan_lock_open') ? scan_lock_open() : false;
if (function_exists('scan_lock_try') && !scan_lock_try($lock)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    echo json_encode(['ok' => false, 'error' => 'A scan is running. Wait until it finishes.']);
    exit;
}

try {
    $result = tools_rename_group(
        $abs,
        $find,
        $replace,
        $extensions,
        (string) $source['catalog'],
        (string) $source['path']
    );
} finally {
    if (function_exists('scan_lock_release')) {
        scan_lock_release($lock);
    } elseif (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

$relNorm = tools_normalize_rel($rel) ?? '';
if (function_exists('app_log')) {
    app_log('tools', 'Rename group in ' . ($relNorm !== '' ? $relNorm : (string) $source['path'])
        . ': ' . (int) $result['renamed'] . ' renamed, '
        . (int) $result['catalog'] . ' catalog rows updated, '
        . (int) $result['skipped'] . ' skipped, '
        . (int) $result['errors'] . ' errors.', [
        'src' => $src,
        'dir' => $relNorm,
        'find' => $find,
        'renamed' => $result['renamed'],
        'catalog' => $result['catalog'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
    ]);
}

echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
@set_time_limit(180);
$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

$src = (string) ($data['src'] ?? '');
$plans = $data['plans'] ?? [];
if (!is_array($plans)) {
    $plans = [];
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

$lock = function_exists('scan_lock_open') ? scan_lock_open() : false;
if (function_exists('scan_lock_try') && !scan_lock_try($lock)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    echo json_encode(['ok' => false, 'error' => 'A scan is running. Wait until it finishes.']);
    exit;
}

$renamed = 0;
$skipped = 0;
$errors = 0;
$catalogN = 0;
$folders = [];
try {
    foreach ($plans as $plan) {
        if (!is_array($plan)) {
            continue;
        }
        $find = (string) ($plan['find'] ?? '');
        $rel = (string) ($plan['dir'] ?? '');
        $exts = $plan['extensions'] ?? [];
        if (!is_array($exts) || $find === '') {
            continue;
        }
        $abs = tools_resolve_dir((string) $source['path'], $rel);
        if ($abs === null) {
            $errors++;
            $folders[] = [
                'label' => (string) ($plan['label'] ?? $rel),
                'ok' => false,
                'error' => 'Folder not found.',
                'changes' => [],
            ];
            continue;
        }
        $result = tools_rename_group(
            $abs,
            $find,
            '',
            $exts,
            (string) $source['catalog'],
            (string) $source['path']
        );
        $renamed += (int) ($result['renamed'] ?? 0);
        $skipped += (int) ($result['skipped'] ?? 0);
        $errors += (int) ($result['errors'] ?? 0);
        $catalogN += (int) ($result['catalog'] ?? 0);
        $folders[] = [
            'label' => (string) ($plan['label'] ?? ($rel !== '' ? $rel : basename($abs))),
            'dir' => $rel,
            'ok' => true,
            'renamed' => (int) ($result['renamed'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'catalog' => (int) ($result['catalog'] ?? 0),
            'changes' => $result['changes'] ?? [],
        ];
    }
} finally {
    if (function_exists('scan_lock_release')) {
        scan_lock_release($lock);
    } elseif (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (function_exists('app_log')) {
    app_log('tools', 'Smart Rename finished: ' . $renamed . ' renamed, ' . $catalogN . ' catalog rows, '
        . $skipped . ' skipped, ' . $errors . ' errors.', [
        'src' => $src,
        'folders' => count($folders),
        'renamed' => $renamed,
        'catalog' => $catalogN,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
}

echo json_encode([
    'ok' => true,
    'renamed' => $renamed,
    'catalog' => $catalogN,
    'skipped' => $skipped,
    'errors' => $errors,
    'folders' => $folders,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

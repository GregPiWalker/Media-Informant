<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/grok.php';
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
$rel = (string) ($data['dir'] ?? '');
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
$abs = tools_resolve_dir((string) $source['path'], $rel);
if ($abs === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Folder not found or not inside the source.']);
    exit;
}
$relNorm = tools_normalize_rel($rel);
if ($relNorm === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid folder.']);
    exit;
}

$excludes = tools_excludes_for((string) $source['catalog']);
$flags = tools_dir_flags($abs, $excludes);
if (!empty($flags['has_media']) || empty($flags['has_folders'])) {
    echo json_encode(['ok' => false, 'error' => 'Smart Rename is only available on folders that contain other folders, not media files.']);
    exit;
}

$groups = tools_collect_leaf_groups($abs, $relNorm, $excludes);
$plan = tools_smart_rename_plan($groups);
if (empty($plan['ok'])) {
    echo json_encode($plan);
    exit;
}
echo json_encode([
    'ok' => true,
    'src' => $source['id'],
    'dir' => $relNorm,
    'plans' => $plan['plans'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

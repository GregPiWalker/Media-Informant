<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/tools.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

cache_init();
$src = (string) ($_GET['src'] ?? $_POST['src'] ?? '');
$rel = (string) ($_GET['dir'] ?? $_POST['dir'] ?? '');
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
$list = tools_list_dir($abs, tools_excludes_for((string) $source['catalog']));
$parent = $relNorm === '' ? null : dirname($relNorm);
if ($parent === '.' || $parent === '\\') {
    $parent = '';
}
echo json_encode([
    'ok' => true,
    'src' => $source['id'],
    'dir' => $relNorm,
    'parent' => $parent,
    'folders' => $list['folders'],
    'files' => $list['files'],
    'extensions' => $list['extensions'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

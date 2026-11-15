<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/kodi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array{ok: bool, error?: string, message?: string} $payload
 */
function kodi_play_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"error":"Could not encode the response."}' : $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    kodi_play_json(['ok' => false, 'error' => 'Use POST.'], 405);
}
if (!kodi_enabled()) {
    kodi_play_json(['ok' => false, 'error' => 'Kodi is not configured.'], 404);
}

$id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    kodi_play_json(['ok' => false, 'error' => 'That title was not found.'], 404);
}

$item = function_exists('catalog_store_find') ? catalog_store_find('video', $id) : null;
if ($item === null && function_exists('catalog_store_find')) {
    $item = catalog_store_find('music', $id);
}
if ($item === null) {
    kodi_play_json(['ok' => false, 'error' => 'That title was not found.'], 404);
}

$host = kodi_selected_host();
if ($host === null) {
    kodi_play_json(['ok' => false, 'error' => 'Kodi is not configured.'], 404);
}

$path = kodi_play_path($item);
if ($path === '') {
    kodi_play_json(['ok' => false, 'error' => 'This item has no file to play.']);
}

$result = kodi_player_open($host, $path);
if (empty($result['ok'])) {
    kodi_play_json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Kodi did not start playback.')]);
}
kodi_play_json(['ok' => true, 'message' => (string) ($result['message'] ?? 'Playing.')]);

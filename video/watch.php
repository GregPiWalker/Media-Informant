<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array{ok: bool, error?: string, state?: string, count?: int} $payload
 */
function watch_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"error":"Could not encode the response."}' : $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    watch_json(['ok' => false, 'error' => 'Use POST.'], 405);
}

cache_init();

$raw = isset($_POST['ids']) && is_string($_POST['ids']) ? $_POST['ids'] : '';
$state = isset($_POST['state']) && is_string($_POST['state']) ? $_POST['state'] : '';
if ($state !== 'yes' && $state !== 'no') {
    watch_json(['ok' => false, 'error' => 'Choose watched or unwatched.'], 400);
}
$label = isset($_POST['label']) && is_string($_POST['label']) ? $_POST['label'] : '';
$label = trim((string) (preg_replace('/\s+/', ' ', $label) ?? $label));
if (strlen($label) > 200) {
    $label = substr($label, 0, 200);
}

$parts = preg_split('/[\s,]+/', $raw) ?: [];
$ids = [];
foreach ($parts as $id) {
    $id = strtolower(trim((string) $id));
    if (preg_match('/^[a-f0-9]{16}$/', $id) === 1 && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}
if ($ids === []) {
    watch_json(['ok' => false, 'error' => 'That title was not found.'], 404);
}

$ok = catalog_set_watched_many('video', $ids, $state === 'yes', $label);
if (!$ok) {
    watch_json(['ok' => false, 'error' => 'Could not update watched state.']);
}
watch_json(['ok' => true, 'state' => $state, 'count' => count($ids)]);

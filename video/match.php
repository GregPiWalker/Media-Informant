<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/tmdb.php';

cache_init();

$id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
if ($id === '' && isset($_POST['id']) && is_string($_POST['id'])) {
    $id = $_POST['id'];
}
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    $id = '';
}

$library = cache_read_library();
$item = $id !== '' ? cache_find_item($library, $id) : null;
if ($item !== null && !settings_item_in_roots($item, settings_video_roots(), $library)) {
    $item = null;
}

$error = '';

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!tmdb_has_key() && $action === 'choose') {
        $error = 'Set a TMDB API key in Config before matching titles.';
    } elseif ($action === 'clear') {
        $updated = cache_update_item($id, static function (array $row): array {
            return cache_apply_tmdb_match($row, null, 'manual');
        });
        if ($updated === null) {
            $error = 'Could not update the catalog. Check cache/ permissions.';
        } else {
            if (function_exists('app_log')) {
                app_log('match', 'Cleared TMDB match for ' . (string) ($item['display_title'] ?? $item['title'] ?? $id) . '.', ['id' => $id]);
            }
            header('Location: title.php?id=' . rawurlencode($id), true, 303);
            exit;
        }
    } elseif ($action === 'choose') {
        $tmdbId = (int) ($_POST['tmdb_id'] ?? 0);
        $meta = $tmdbId > 0 ? cache_read_title($tmdbId) : null;
        if ($meta === null && $tmdbId > 0) {
            $postedType = (string) ($_POST['media_type'] ?? '');
            $mediaType = $postedType === 'tv' || $postedType === 'movie'
                ? $postedType
                : ((string) ($item['kind'] ?? '') === 'show' ? 'tv' : 'movie');
            $meta = tmdb_fetch_details($tmdbId, $mediaType);
            if ($meta !== null) {
                cache_write_title($tmdbId, $meta);
            }
        }
        if ($meta === null) {
            $error = 'Could not load that title from TMDB. Try another result.';
        } else {
            $updated = cache_update_item($id, static function (array $row) use ($meta): array {
                return cache_apply_tmdb_match($row, $meta, 'manual');
            });
            if ($updated === null) {
                $error = 'Could not update the catalog. Check cache/ permissions.';
            } else {
                if (function_exists('app_log')) {
                    app_log('match', 'Manual match: ' . (string) ($meta['title'] ?? $tmdbId) . '.', [
                        'id' => $id,
                        'tmdb_id' => $tmdbId,
                    ]);
                }
                header('Location: title.php?id=' . rawurlencode($id), true, 303);
                exit;
            }
        }
    }
}

$parsedTitle = is_array($item) ? (string) ($item['title'] ?? '') : '';
$parsedYear = is_array($item) && isset($item['year']) ? (int) $item['year'] : null;
if ($parsedYear !== null && $parsedYear < 1870) {
    $parsedYear = null;
}

$browse = (string) ($_GET['browse'] ?? '') === 'popular';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : ($browse ? '' : $parsedTitle);
$yearRaw = isset($_GET['year']) ? trim((string) $_GET['year']) : '';
$isShow = is_array($item) && (string) ($item['kind'] ?? '') === 'show';
$searchMedia = $isShow ? 'tv' : 'movie';
if ($yearRaw === '' && !isset($_GET['q']) && !isset($_GET['browse']) && $parsedYear !== null && !$isShow) {
    $yearRaw = (string) $parsedYear;
}
$year = $yearRaw !== '' && ctype_digit($yearRaw) ? (int) $yearRaw : null;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$mode = 'search';
$payload = ['results' => [], 'page' => 1, 'total_pages' => 1];
$relaxedYear = false;
$hasKey = tmdb_has_key();

if ($item !== null && $hasKey) {
    if ($q !== '') {
        $payload = tmdb_search_results($q, $year, $page, $searchMedia);
        $relaxedYear = !empty($payload['relaxed_year']);
    } else {
        $mode = 'popular';
        $payload = tmdb_popular_results($page, $searchMedia);
    }
}

$results = $payload['results'];
$page = (int) ($payload['page'] ?? $page);
$totalPages = (int) ($payload['total_pages'] ?? 1);
$currentTmdb = is_array($item) && !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : 0;

require app_view('match');

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';

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
$editing = isset($_GET['edit']);

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'clear_match') {
    $updated = cache_update_item($id, static function (array $row): array {
        return library_clear_match($row);
    });
    if ($updated === null) {
        $error = 'Could not clear the match. Check that cache/ is writable.';
    } else {
        $item = $updated;
        if (function_exists('app_log')) {
            app_log('match', 'Cleared match; set unidentified: ' . (string) ($item['display_title'] ?? $item['title'] ?? $id) . '.', ['id' => $id]);
        }
        header('Location: title.php?id=' . rawurlencode($id), true, 303);
        exit;
    }
}

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'save_data') {
    $displayTitle = trim((string) ($_POST['display_title'] ?? ''));
    if (strlen($displayTitle) > 200) {
        $displayTitle = substr($displayTitle, 0, 200);
    }
    if ($displayTitle === '') {
        $displayTitle = trim((string) ($item['display_title'] ?? $item['title'] ?? 'Untitled'));
        if ($displayTitle === '') {
            $displayTitle = 'Untitled';
        }
    }
    $overview = trim((string) ($_POST['overview'] ?? ''));
    if (strlen($overview) > 8000) {
        $overview = substr($overview, 0, 8000);
    }
    $genres = [];
    $seen = [];
    $rawGenres = $_POST['genres'] ?? [];
    if (is_array($rawGenres)) {
        foreach ($rawGenres as $genre) {
            if (!is_string($genre)) {
                continue;
            }
            $genre = trim($genre);
            if ($genre === '' || strlen($genre) > 48) {
                continue;
            }
            $key = lower($genre);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $genres[] = $genre;
        }
    }
    $updated = cache_update_item($id, static function (array $row) use ($displayTitle, $overview, $genres): array {
        $row['display_title'] = $displayTitle;
        $row['title_source'] = 'user';
        $row['overview'] = $overview;
        $row['genres'] = $genres;
        return $row;
    });
    if ($updated === null) {
        $error = 'Could not save. Check that cache/ is writable.';
        $editing = true;
        $item['display_title'] = $displayTitle;
        $item['title_source'] = 'user';
        $item['overview'] = $overview;
        $item['genres'] = $genres;
    } else {
        $tmdbId = (int) ($updated['tmdb_id'] ?? 0);
        if ($tmdbId > 0) {
            $titleMeta = cache_read_title($tmdbId) ?? ['tmdb_id' => $tmdbId];
            $titleMeta['overview'] = $overview;
            $titleMeta['genres'] = $genres;
            cache_write_title($tmdbId, $titleMeta);
        }
        if (function_exists('app_log')) {
            app_log('title', 'Saved edits for “' . $displayTitle . '”.', ['id' => $id, 'genres' => count($genres)]);
        }
        header('Location: title.php?id=' . rawurlencode($id), true, 303);
        exit;
    }
}

$meta = null;
if ($item !== null && !empty($item['tmdb_id'])) {
    $meta = cache_read_title((int) $item['tmdb_id']);
}

require app_view('detail');

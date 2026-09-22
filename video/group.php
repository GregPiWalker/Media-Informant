<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/catalog.php';

cache_init();

$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if ($key === '' && isset($_POST['key']) && is_string($_POST['key'])) {
    $key = $_POST['key'];
}
if (strlen($key) > 400) {
    $key = '';
}

$library = cache_read_library();
$allowedRoots = settings_video_roots();
$records = [];
foreach ($library['items'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (!settings_item_in_roots($item, $allowedRoots, $library)) {
        continue;
    }
    if (!settings_show_hidden() && library_item_hidden($item)) {
        continue;
    }
    $records[] = CatalogRecord::fromVideo($item);
}
$group = $key !== '' ? catalog_find_group_by_id($records, $key) : null;

$error = '';
$editing = isset($_GET['edit']);

function group_member_ids(CatalogGroup $group): array
{
    $ids = [];
    foreach ($group->members as $member) {
        if ($member->id !== '') {
            $ids[] = $member->id;
        }
    }
    return $ids;
}

function group_reload(string $key): void
{
    header('Location: group.php?key=' . rawurlencode($key), true, 303);
    exit;
}

if ($group !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'clear_matches') {
    $n = cache_update_items(group_member_ids($group), static function (array $row): array {
        return library_clear_match($row);
    });
    if ($n < 1) {
        $error = 'Could not clear matches. Check that cache/ is writable.';
    } else {
        if (function_exists('app_log')) {
            app_log('match', 'Cleared matches for group “' . $group->head->seriesTitle . '” (' . $n . ' files).', [
                'key' => $key,
                'count' => $n,
            ]);
        }
        group_reload($key);
    }
}

if ($group !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'save_data') {
    $displayTitle = trim((string) ($_POST['display_title'] ?? ''));
    if (strlen($displayTitle) > 200) {
        $displayTitle = substr($displayTitle, 0, 200);
    }
    if ($displayTitle === '') {
        $displayTitle = trim($group->head->seriesTitle !== '' ? $group->head->seriesTitle : $group->head->title);
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
            $gkey = lower($genre);
            if (isset($seen[$gkey])) {
                continue;
            }
            $seen[$gkey] = true;
            $genres[] = $genre;
        }
    }
    $n = cache_update_items(group_member_ids($group), static function (array $row) use ($displayTitle, $overview, $genres): array {
        $row['title'] = $displayTitle;
        $row['display_title'] = $displayTitle;
        $row['title_source'] = 'user';
        $row['overview'] = $overview;
        $row['genres'] = $genres;
        return $row;
    });
    if ($n < 1) {
        $error = 'Could not save. Check that cache/ is writable.';
        $editing = true;
    } else {
        $headItem = $group->head->id !== '' ? catalog_store_find('video', $group->head->id) : null;
        if (is_array($headItem) && !empty($headItem['tmdb_id'])) {
            $tmdbId = (int) $headItem['tmdb_id'];
            $titleMeta = cache_read_title($tmdbId) ?? ['tmdb_id' => $tmdbId];
            $titleMeta['overview'] = $overview;
            $titleMeta['genres'] = $genres;
            $titleMeta['title'] = $displayTitle;
            cache_write_title($tmdbId, $titleMeta);
        }
        if (function_exists('app_log')) {
            app_log('title', 'Saved group edits for “' . $displayTitle . '” (' . $n . ' files).', [
                'key' => $key,
                'count' => $n,
            ]);
        }
        $newKey = 'series:' . $group->kind . ':' . lower($displayTitle);
        header('Location: group.php?key=' . rawurlencode($newKey), true, 303);
        exit;
    }
}

$meta = null;
if ($group !== null && $group->head->id !== '') {
    $headItem = catalog_store_find('video', $group->head->id);
    if (is_array($headItem) && !empty($headItem['tmdb_id'])) {
        $meta = cache_read_title((int) $headItem['tmdb_id']);
    }
}

require app_view('group-detail');

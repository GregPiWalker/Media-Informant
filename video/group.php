<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/catalog.php';
require_once dirname(__DIR__) . '/lib/tmdb.php';

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
$isFolder = str_starts_with($key, 'folder:');
$folder = $isFolder ? catalog_find_folder($records, $key) : null;
$group = !$isFolder && $key !== '' ? catalog_find_group_by_id($records, $key) : null;

$error = '';
$editing = isset($_GET['edit']);
$editDraft = null;

if ($group !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'That image is too large to upload.';
    $editing = true;
}

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

function group_from_query(): string
{
    $from = '';
    if (isset($_POST['from']) && is_string($_POST['from'])) {
        $from = $_POST['from'];
    } elseif (isset($_GET['from']) && is_string($_GET['from'])) {
        $from = $_GET['from'];
    }
    if (!str_starts_with($from, 'folder:') || strlen($from) > 80) {
        return '';
    }
    return $from;
}

function group_reload(string $key): void
{
    $from = group_from_query();
    $extra = $from !== '' ? '&from=' . rawurlencode($from) : '';
    header('Location: group.php?key=' . rawurlencode($key) . $extra, true, 303);
    exit;
}

$configuring = $isFolder && isset($_GET['configure']);
$scanServices = [];
$scanServiceSaved = '';
$scanServiceActive = '';

if ($folder !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'save_scan_service') {
    if (!function_exists('lookup_services')) {
        require_once dirname(__DIR__) . '/lib/lookup.php';
    }
    $choice = function_exists('catalog_lookup_service_name')
        ? catalog_lookup_service_name((string) ($_POST['scan_service'] ?? ''))
        : '';
    $folderLabel = trim((string) ($folder['label'] ?? ''));
    if ($folderLabel === '') {
        $folderLabel = 'Folder';
    }
    if ($choice === '') {
        $error = 'Choose a lookup service.';
        $configuring = true;
    } elseif (!function_exists('catalog_folder_scan_service_write') || !catalog_folder_scan_service_write($key, $folderLabel, $choice, catalog_folder_file_ids($folder))) {
        $error = 'Could not save the scan service. Check that cache/ is writable.';
        $configuring = true;
    } else {
        if (function_exists('app_log')) {
            app_log('scan', 'Folder “' . $folderLabel . '” scan service set to ' . $choice . '.', [
                'key' => $key,
                'service' => $choice,
            ]);
        }
        group_reload($key);
    }
}

if ($folder !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'clear_matches') {
    $folderLabel = trim((string) ($folder['label'] ?? ''));
    if ($folderLabel === '') {
        $folderLabel = 'folder';
    }
    $n = cache_update_items(catalog_folder_file_ids($folder), static function (array $row): array {
        return library_clear_match($row);
    });
    if ($n < 1) {
        $error = 'Could not clear matches. Check that cache/ is writable.';
    } else {
        if (function_exists('app_log')) {
            app_log('match', 'Cleared matches for folder “' . $folderLabel . '” (' . $n . ' files).', [
                'key' => $key,
                'count' => $n,
            ]);
        }
        group_reload($key);
    }
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
    $genres = library_posted_genres($_POST['genres'] ?? []);
    $yearParsed = library_posted_year($_POST['year'] ?? '');
    $posterOwner = $group->head->id;
    $posterParsed = $yearParsed['error'] === ''
        ? library_take_poster_upload($posterOwner, isset($_POST['remove_poster']))
        : ['ok' => true, 'error' => '', 'apply' => false, 'poster_path' => null];
    $postedYear = $yearParsed['year'];
    $applyPoster = (bool) $posterParsed['apply'];
    $posterPath = $posterParsed['poster_path'];
    $editDraft = [
        'title' => $displayTitle,
        'overview' => $overview,
        'genres' => $genres,
        'year' => $yearParsed['error'] === '' ? $postedYear : trim((string) ($_POST['year'] ?? '')),
    ];
    if ($yearParsed['error'] !== '') {
        $error = $yearParsed['error'];
        $editing = true;
    } elseif ($posterParsed['error'] !== '') {
        $error = $posterParsed['error'];
        $editing = true;
    } else {
    $partEpisodeSave = str_starts_with($key, 'parts::');
    $n = cache_update_items(group_member_ids($group), static function (array $row) use ($displayTitle, $overview, $genres, $partEpisodeSave, $postedYear, $applyPoster, $posterPath): array {
        if ($partEpisodeSave) {
            $row['episode_title'] = $displayTitle;
        } else {
            $row['title'] = $displayTitle;
            $row['display_title'] = $displayTitle;
            $row['title_source'] = 'user';
        }
        $row['overview'] = $overview;
        $row['genres'] = $genres;
        return library_apply_edit_media($row, $postedYear, $applyPoster, is_string($posterPath) ? $posterPath : null);
    });
    if ($n < 1) {
        $error = 'Could not save. Check that cache/ is writable.';
        $editing = true;
    } else {
        $headItem = $group->head->id !== '' ? catalog_store_find('video', $group->head->id) : null;
        if (!$partEpisodeSave && is_array($headItem) && !empty($headItem['tmdb_id'])) {
            $tmdbId = (int) $headItem['tmdb_id'];
            $mediaType = tmdb_item_media_type($headItem);
            $titleMeta = (function_exists('cache_read_title_for')
                ? cache_read_title_for($tmdbId, $mediaType)
                : cache_read_title($tmdbId)) ?? ['tmdb_id' => $tmdbId, 'media_type' => $mediaType];
            $titleMeta['overview'] = $overview;
            $titleMeta['genres'] = $genres;
            $titleMeta['title'] = $displayTitle;
            if (function_exists('cache_write_title_for')) {
                cache_write_title_for($tmdbId, $titleMeta, $mediaType);
            } else {
                cache_write_title($tmdbId, $titleMeta);
            }
        }
        if (function_exists('app_log')) {
            app_log('title', 'Saved group edits for “' . $displayTitle . '” (' . $n . ' files).', [
                'key' => $key,
                'count' => $n,
            ]);
        }
        if ($partEpisodeSave) {
            $rest = substr($key, strlen('parts::'));
            $markerPos = strpos($rest, '::ep::');
            $seriesId = $markerPos === false ? '' : substr($rest, 0, $markerPos);
            $season = $group->head->season ?? 0;
            $newKey = 'parts::' . $seriesId . '::ep::' . sprintf('%02d|%s', $season, lower($displayTitle));
        } else {
            $newKey = 'series:' . $group->kind . ':' . lower($displayTitle);
        }
        group_reload($newKey);
    }
    }
}

if ($group !== null) {
    $pending = [];
    foreach ($group->members as $member) {
        if ($member->id !== '' && $member->status !== 'matched') {
            $pending[$member->id] = true;
        }
    }
    if ($pending !== []) {
        $rows = [];
        foreach ($library['items'] as $libItem) {
            if (!is_array($libItem)) {
                continue;
            }
            $libId = (string) ($libItem['id'] ?? '');
            if ($libId !== '' && isset($pending[$libId])) {
                $rows[$libId] = $libItem;
                if (count($rows) === count($pending)) {
                    break;
                }
            }
        }
        foreach ($group->members as $index => $member) {
            if (!isset($rows[$member->id])) {
                continue;
            }
            $before = $rows[$member->id];
            $healed = library_heal_filename_year($member->id, $before);
            $beforeYear = isset($before['year']) && is_numeric($before['year']) ? (int) $before['year'] : null;
            $afterYear = isset($healed['year']) && is_numeric($healed['year']) ? (int) $healed['year'] : null;
            if ($beforeYear === $afterYear) {
                continue;
            }
            $fresh = CatalogRecord::fromVideo($healed);
            $group->members[$index] = $fresh;
            if ($group->head->id === $member->id) {
                $group->head = $fresh;
            }
        }
    }
}

$meta = null;
if ($group !== null && $group->head->id !== '') {
    $headItem = catalog_store_find('video', $group->head->id);
    if (is_array($headItem) && !empty($headItem['tmdb_id'])) {
        $meta = tmdb_ensure_title_details((int) $headItem['tmdb_id'], tmdb_item_media_type($headItem));
    }
    if (is_array($headItem) && str_starts_with((string) $group->id, 'parts::')) {
        $headItem = tmdb_remember_item_runtime((string) ($headItem['id'] ?? ''), $headItem, is_array($meta) ? $meta : null);
    }
}

if ($isFolder && $folder !== null) {
    if (!function_exists('lookup_services')) {
        require_once dirname(__DIR__) . '/lib/lookup.php';
    }
    if (function_exists('lookup_services')) {
        foreach (array_keys(lookup_services()) as $serviceName) {
            $scanServices[] = (string) $serviceName;
        }
    }
    $folderLabel = trim((string) ($folder['label'] ?? ''));
    if (function_exists('catalog_folder_scan_service_saved')) {
        $scanServiceSaved = catalog_folder_scan_service_saved($key);
    }
    if (function_exists('catalog_folder_scan_service_for_rescan')) {
        $scanServiceActive = catalog_folder_scan_service_for_rescan($key, $folderLabel);
    }
}

if ($isFolder) {
    require app_view('folder-detail');
    return;
}

require app_view('group-detail');

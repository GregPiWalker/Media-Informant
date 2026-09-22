<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/folders.php';

cache_init();

$id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
if ($id === '' && isset($_POST['id']) && is_string($_POST['id'])) {
    $id = $_POST['id'];
}
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    $id = '';
}
$gkey = isset($_GET['gkey']) && is_string($_GET['gkey']) ? $_GET['gkey'] : '';
if ($gkey === '' && isset($_POST['gkey']) && is_string($_POST['gkey'])) {
    $gkey = $_POST['gkey'];
}
if (strlen($gkey) > 400) {
    $gkey = '';
}

function title_self_url(string $id, string $gkey, array $extra = []): string
{
    $q = array_merge(['id' => $id], $extra);
    if ($gkey !== '') {
        $q['gkey'] = $gkey;
    }
    return 'title.php?' . http_build_query($q);
}
$library = cache_read_library();
$item = $id !== '' ? cache_find_item($library, $id) : null;
if ($item !== null && !settings_item_in_roots($item, settings_video_roots(), $library)) {
    $item = null;
}

$error = '';
$editing = isset($_GET['edit']);

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ((string) ($_POST['action'] ?? '') === 'treat_individual' || (string) ($_POST['action'] ?? '') === 'treat_series')) {
    $grouped = (string) ($_POST['action'] ?? '') === 'treat_series';
    $updated = cache_update_item($id, static function (array $row) use ($grouped): array {
        return library_set_item_grouped($row, $grouped);
    });
    if ($updated === null) {
        $error = 'Could not update how this file is treated. Check that cache/ is writable.';
    } else {
        $item = $updated;
        if (function_exists('app_log')) {
            app_log('title', ($grouped ? 'Treat as series: ' : 'Treat individually: ')
                . (string) ($item['display_title'] ?? $item['title'] ?? $id) . '.', ['id' => $id, 'grouped' => $grouped]);
        }
        header('Location: ' . title_self_url($id, $gkey), true, 303);
        exit;
    }
}

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ((string) ($_POST['action'] ?? '') === 'folder_as_show' || (string) ($_POST['action'] ?? '') === 'folder_as_movie')) {
    $home = folder_home_for_file((string) ($item['path'] ?? ''));
    $root = (string) ($item['root'] ?? '');
    $kind = (string) ($_POST['action'] ?? '') === 'folder_as_movie' ? 'movie' : 'show';
    if ($home === '' || $root === '') {
        $error = 'This file is not inside a show folder.';
    } elseif (!folder_set_user_kind($root, $home, $kind)) {
        $error = 'Could not save the folder type. Check that cache/ is writable.';
    } else {
        $n = library_reclassify_folder($root, $home);
        if (function_exists('app_log')) {
            app_log('title', 'Set folder “' . $home . '” as ' . $kind . ' (' . $n . ' files).', [
                'id' => $id,
                'kind' => $kind,
            ]);
        }
        header('Location: index.php', true, 303);
        exit;
    }
}

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'exclude_parent') {
    $parent = settings_item_parent_dir($item);
    $fail = settings_add_video_exclude($parent);
    if ($fail !== null) {
        $error = $fail;
    } else {
        require_once dirname(__DIR__) . '/lib/scanner.php';
        $dropped = cache_prune_excluded_items();
        if (function_exists('scan_job_strip_excluded')) {
            scan_job_strip_excluded();
        }
        if (function_exists('app_log')) {
            app_log('config', 'Excluded parent folder ' . $parent . '.', [
                'id' => $id,
                'dropped' => $dropped,
            ]);
        }
        header('Location: index.php', true, 303);
        exit;
    }
}

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'hide') {
    $updated = cache_update_item($id, static function (array $row): array {
        return library_set_hidden($row, true);
    });
    if ($updated === null) {
        $error = 'Could not hide this file. Check that cache/ is writable.';
    } else {
        $item = $updated;
        if (function_exists('app_log')) {
            app_log('title', 'Hid “' . (string) ($item['display_title'] ?? $item['title'] ?? $id) . '”.', ['id' => $id]);
        }
        if (!settings_show_hidden()) {
            header('Location: index.php', true, 303);
            exit;
        }
        header('Location: ' . title_self_url($id, $gkey), true, 303);
        exit;
    }
}

if ($item !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'unhide') {
    $updated = cache_update_item($id, static function (array $row): array {
        return library_set_hidden($row, false);
    });
    if ($updated === null) {
        $error = 'Could not unhide this file. Check that cache/ is writable.';
    } else {
        $item = $updated;
        if (function_exists('app_log')) {
            app_log('title', 'Unhid “' . (string) ($item['display_title'] ?? $item['title'] ?? $id) . '”.', ['id' => $id]);
        }
        header('Location: ' . title_self_url($id, $gkey), true, 303);
        exit;
    }
}

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
        header('Location: ' . title_self_url($id, $gkey), true, 303);
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
        header('Location: ' . title_self_url($id, $gkey), true, 303);
        exit;
    }
}

$meta = null;
if ($item !== null && !empty($item['tmdb_id'])) {
    $meta = cache_read_title((int) $item['tmdb_id']);
}

require app_view('detail');

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/parser.php';
require dirname(__DIR__) . '/lib/tmdb.php';

/**
 * True when the stored title still appears in the filename. Dots, underscores,
 * and hyphens count as spaces so "The Matrix" matches The.Matrix.1999.mkv.
 */
function match_filename_contains_title(string $filename, string $title): bool
{
    $title = trim($title);
    if ($filename === '' || $title === '') {
        return false;
    }
    $hay = lower(str_replace(['.', '_', '-'], ' ', strip_extension($filename)));
    $hay = trim(preg_replace('/\s+/', ' ', $hay) ?? $hay);
    $needle = lower(str_replace(['.', '_', '-'], ' ', $title));
    $needle = trim(preg_replace('/\s+/', ' ', $needle) ?? $needle);
    return $needle !== '' && str_contains($hay, $needle);
}

/** Year written in the filename itself, not a year copied from an old match. */
function match_filename_year(string $filename): ?int
{
    if ($filename === '') {
        return null;
    }
    $parsed = parse_name(strip_extension($filename));
    $year = $parsed['year'] ?? null;
    if (!is_numeric($year)) {
        return null;
    }
    $year = (int) $year;
    return $year >= 1870 && $year <= 2100 ? $year : null;
}

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
        $postedType = (string) ($_POST['media_type'] ?? '');
        $mediaType = $postedType === 'tv' || $postedType === 'movie'
            ? $postedType
            : ((string) ($item['kind'] ?? '') === 'show' ? 'tv' : 'movie');
        $meta = $tmdbId > 0
            ? (function_exists('cache_read_title_for') ? cache_read_title_for($tmdbId, $mediaType) : cache_read_title($tmdbId))
            : null;
        $missingOverview = !is_array($meta) || trim((string) ($meta['overview'] ?? '')) === '';
        $missingRating = !tmdb_has_rating($meta);
        $missingRuntime = !tmdb_has_runtime($meta);
        if ($tmdbId > 0 && ($missingOverview || $missingRating || $missingRuntime)) {
            $fetched = tmdb_fetch_details($tmdbId, $mediaType);
            if (is_array($fetched) && (string) ($fetched['media_type'] ?? '') === $mediaType) {
                if ($missingOverview) {
                    $meta = is_array($meta) ? array_merge($meta, $fetched) : $fetched;
                } else {
                    if ($missingRating) {
                        $meta = tmdb_copy_rating($meta, $fetched);
                    }
                    if ($missingRuntime && tmdb_has_runtime($fetched)) {
                        $meta['runtime'] = tmdb_minutes_value($fetched['runtime'] ?? null);
                    }
                }
                if (function_exists('cache_write_title_for')) {
                    cache_write_title_for($tmdbId, $meta, $mediaType);
                } else {
                    cache_write_title($tmdbId, $meta);
                }
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

$filename = '';
if (is_array($item)) {
    $filename = (string) ($item['filename'] ?? '');
    if ($filename === '') {
        $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
        $filename = $path !== '' ? basename($path) : '';
    }
}
$storedTitle = is_array($item) ? trim((string) ($item['title'] ?? '')) : '';
$fileParsed = $filename !== '' ? parse_name(strip_extension($filename)) : ['title' => '', 'year' => null];
$parsedTitle = trim((string) ($fileParsed['title'] ?? ''));
$parsedYear = match_filename_year($filename);
$titleInFile = match_filename_contains_title($filename, $storedTitle);

$browse = (string) ($_GET['browse'] ?? '') === 'popular';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : (($browse || !$titleInFile) ? '' : $storedTitle);
$yearRaw = isset($_GET['year']) ? trim((string) $_GET['year']) : '';
$isShow = is_array($item) && (string) ($item['kind'] ?? '') === 'show';
$searchMedia = $isShow ? 'tv' : 'movie';
if ($yearRaw === '' && !isset($_GET['q']) && !isset($_GET['browse']) && $parsedYear !== null) {
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

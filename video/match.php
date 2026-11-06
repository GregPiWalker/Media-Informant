<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/parser.php';
require_once dirname(__DIR__) . '/lib/tmdb.php';
require_once dirname(__DIR__) . '/lib/lookup.php';

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
    $postedSource = (string) ($_POST['lookup_source'] ?? 'TMDB');
    $postedSite = '';
    if (strcasecmp($postedSource, 'RiffTrax') === 0) {
        $postedSite = 'RiffTrax';
    } elseif (strcasecmp($postedSource, 'MST3K') === 0) {
        $postedSite = 'MST3K';
    }
    if ($action === 'choose' && $postedSite !== '') {
        $nid = trim((string) ($_POST['lookup_id'] ?? ''));
        $service = function_exists('lookup_service') ? lookup_service($postedSite) : null;
        $picked = ($service && function_exists('curl_init')) ? $service->fetch($nid) : null;
        if (!$picked instanceof VideoMetadata) {
            $error = 'Could not load that title from ' . $postedSite . '. Try another result.';
        } else {
            $updated = cache_update_item($id, static function (array $row) use ($picked): array {
                return lookup_apply_metadata($row, $picked, 'manual');
            });
            if ($updated === null) {
                $error = 'Could not update the catalog. Check cache/ permissions.';
            } else {
                if (function_exists('app_log')) {
                    app_log('match', 'Manual ' . $postedSite . ' match: ' . (string) $picked->title . '.', [
                        'id' => $id,
                        'lookup_id' => $picked->lookupId,
                    ]);
                }
                header('Location: title.php?id=' . rawurlencode($id), true, 303);
                exit;
            }
        }
    } elseif (!tmdb_has_key() && $action === 'choose') {
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

$riffFile = ($item !== null && function_exists('rifftrax_identify'))
    ? rifftrax_identify((string) ($item['root'] ?? ''), (string) ($item['path'] ?? ''), $filename)
    : ['match' => false, 'title' => '', 'via' => '', 'fallback' => false];
$mstFile = ($item !== null && function_exists('mst3k_identify'))
    ? mst3k_identify((string) ($item['root'] ?? ''), (string) ($item['path'] ?? ''), $filename)
    : ['match' => false, 'title' => '', 'via' => '', 'fallback' => false];
$itemService = 'TMDB';
if (is_array($item) && function_exists('catalog_item_scan_service')) {
    $resolvedService = catalog_item_scan_service($item);
    if ($resolvedService !== '') {
        $itemService = $resolvedService;
    }
}
$sourceRaw = isset($_GET['source']) ? strtolower(trim((string) $_GET['source'])) : '';
$scanServiceNote = '';
if ($sourceRaw !== 'tmdb' && $sourceRaw !== 'rifftrax' && $sourceRaw !== 'mst3k') {
    if (strcasecmp($itemService, 'RiffTrax') === 0) {
        $sourceRaw = 'rifftrax';
    } elseif (strcasecmp($itemService, 'MST3K') === 0) {
        $sourceRaw = 'mst3k';
    } elseif (strcasecmp($itemService, 'TMDB') === 0) {
        $sourceRaw = 'tmdb';
    } else {
        $sourceRaw = '';
        $scanServiceNote = 'Scan service "' . $itemService . '" has no match page yet.';
    }
}
$searchSource = $sourceRaw === 'rifftrax' ? 'RiffTrax' : ($sourceRaw === 'mst3k' ? 'MST3K' : ($sourceRaw === 'tmdb' ? 'TMDB' : $itemService));

$browse = (string) ($_GET['browse'] ?? '') === 'popular';
if ($browse) {
    $searchSource = 'TMDB';
    $scanServiceNote = '';
}
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : (($browse || !$titleInFile) ? '' : $storedTitle);
if ($searchSource === 'MST3K' && !isset($_GET['q']) && !empty($mstFile['title'])) {
    $q = (string) $mstFile['title'];
} elseif ($searchSource === 'RiffTrax' && !isset($_GET['q']) && !empty($riffFile['title'])) {
    $q = (string) $riffFile['title'];
}
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
$grokNote = '';
$hasKey = tmdb_has_key();
$siteSearch = $searchSource === 'RiffTrax' || $searchSource === 'MST3K';
$canSearch = $scanServiceNote !== ''
    ? false
    : ($siteSearch ? function_exists('curl_init') : $hasKey);

if ($item !== null && $siteSearch && $canSearch) {
    $prefFile = $searchSource === 'MST3K' ? $mstFile : $riffFile;
    $queryText = $q !== '' ? $q : (string) ($prefFile['title'] ?? '');
    if ($queryText === '') {
        $queryText = $parsedTitle;
    }
    $service = lookup_service($searchSource);
    $hits = ($service && $queryText !== '') ? $service->search($queryText, null, 12, [
        'filename' => $filename,
        'path' => (string) ($item['path'] ?? ''),
    ]) : [];
    $rows = lookup_candidate_rows($hits);
    $via = is_object($service) && method_exists($service, 'lastVia') ? $service->lastVia() : 'none';
    $logTopic = $searchSource === 'MST3K' ? 'mst3k' : 'rifftrax';
    if (function_exists('app_log')) {
        app_log($logTopic, $searchSource . ' search for "' . $queryText . '" via ' . $via
            . ' returned ' . count($rows) . ' title(s). file=' . $filename);
    }
    if ($rows !== []) {
        require_once dirname(__DIR__) . '/lib/grok.php';
        $grokOn = function_exists('grok_resolve_available') && grok_resolve_available();
        if (!$grokOn) {
            if (function_exists('app_log')) {
                app_log('grok', 'Grok is not configured, so ' . count($rows) . ' ' . $searchSource . ' title(s) for "' . $queryText . '" were not sent. file=' . $filename);
            }
        } else {
            if (function_exists('app_log')) {
                app_log('grok', 'Asking Grok to choose among ' . count($rows) . ' ' . $searchSource . ' title(s) for "' . $queryText . '" via ' . $via . '. file=' . $filename);
            }
            $choice = function_exists('grok_choose_for_item') ? grok_choose_for_item($item, $rows, $queryText) : null;
            if (is_array($choice) && $service instanceof VideoLookupService) {
                $picked = $service->fetch((string) $choice['id']);
                if ($picked instanceof VideoMetadata) {
                    $updated = cache_update_item($id, static function (array $row) use ($picked): array {
                        return lookup_apply_metadata($row, $picked, 'grok');
                    });
                    if ($updated !== null) {
                        if (function_exists('app_log')) {
                            app_log('grok', 'Grok chose ' . $searchSource . ' "' . $picked->title . '" for ' . $filename . '.', [
                                'id' => $id,
                                'lookup_id' => $picked->lookupId,
                            ]);
                        }
                        header('Location: title.php?id=' . rawurlencode($id), true, 303);
                        exit;
                    }
                    $error = 'Could not update the catalog. Check cache/ permissions.';
                }
            }
            if ($error === '') {
                $grokNote = 'Grok could not choose a title from these results. Pick one below.';
            }
        }
    }
    $payload = [
        'results' => $rows,
        'page' => 1,
        'total_pages' => 1,
    ];
} elseif ($item !== null && $hasKey && $scanServiceNote === '' && $searchSource === 'TMDB') {
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
$currentLookup = is_array($item) ? (string) ($item['lookup_id'] ?? '') : '';

require app_view('match');

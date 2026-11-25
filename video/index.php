<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/catalog.php';
require dirname(__DIR__) . '/lib/parser.php';

cache_init();
$dbError = function_exists('db_last_error') ? db_last_error() : '';

$library = cache_read_library();
$allowedRoots = settings_video_roots();
$items = [];
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
    $items[] = $item;
}

$schema = catalog_video_schema();
$filterCats = settings_video_filter_categories();
$categoryList = $filterCats['categories'];
$categoryHasNone = $filterCats['has_none'];
$filterIds = settings_category_ids($categoryList);
$prefs = CatalogPreferences::load($schema, $filterIds);
$records = [];
foreach ($items as $item) {
    $records[] = CatalogRecord::fromVideo($item);
}
$groups = catalog_sort_groups(catalog_collect_groups($records), $prefs);
$genreFocus = isset($_GET['genre']) ? catalog_genre_focus_name((string) $_GET['genre']) : '';
if ($genreFocus !== '') {
    $requested = strtolower((string) ($_GET['view'] ?? ''));
    if ($requested === 'list' || $requested === 'poster') {
        $prefs->view = $requested;
    } elseif ($prefs->view === 'list' || $prefs->browse === 'list') {
        $prefs->view = 'list';
    } else {
        $prefs->view = 'poster';
    }
    $groups = catalog_groups_for_genre($groups, $genreFocus);
}
if ($prefs->view === 'poster') {
    $groups = catalog_sort_poster_groups($groups, $prefs);
}
$genreGroups = ($prefs->view === 'genre' && $genreFocus === '')
    ? catalog_genre_rails($groups, $prefs->genreSort)
    : [];
$scannedAt = isset($library['scanned_at']) ? (int) $library['scanned_at'] : null;
$unscannedRoots = function_exists('source_roots_without_titles')
    ? source_roots_without_titles($allowedRoots, $library['items'] ?? [])
    : [];

require app_view('list');

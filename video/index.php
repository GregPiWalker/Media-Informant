<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/catalog.php';

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
$genreGroups = $prefs->view === 'genre' ? catalog_genre_rails($groups) : [];
$scannedAt = isset($library['scanned_at']) ? (int) $library['scanned_at'] : null;

require app_view('list');

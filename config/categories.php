<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';

cache_init();

$saved = isset($_GET['saved']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $current = settings_get();
    $payload = [
        'video_category_ids' => $_POST['video_category_ids'] ?? [],
        'video_category_labels' => $_POST['video_category_labels'] ?? [],
        'music_category_ids' => $_POST['music_category_ids'] ?? [],
        'music_category_labels' => $_POST['music_category_labels'] ?? [],
        'video_roots' => $current['video_roots'] ?? [],
        'music_roots' => $current['music_roots'] ?? [],
        'video_excludes' => $current['video_excludes'] ?? [],
        'music_excludes' => $current['music_excludes'] ?? [],
        'tmdb_api_key' => $current['tmdb_api_key'] ?? '',
        'tmdb_language' => $current['tmdb_language'] ?? TMDB_LANGUAGE,
        'xai_api_key' => $current['xai_api_key'] ?? '',
    ];
    if (!is_array($payload['video_category_ids'])) {
        $payload['video_category_ids'] = [];
    }
    if (!is_array($payload['video_category_labels'])) {
        $payload['video_category_labels'] = [];
    }
    if (!is_array($payload['music_category_ids'])) {
        $payload['music_category_ids'] = [];
    }
    if (!is_array($payload['music_category_labels'])) {
        $payload['music_category_labels'] = [];
    }

    if (!cache_writable()) {
        $error = 'cache/ is not writable. Grant the http user write access so settings can be saved.';
    } elseif (!settings_save($payload)) {
        $error = 'Could not write settings.json. Check cache/ permissions.';
    } else {
        header('Location: categories.php?saved=1', true, 303);
        exit;
    }
}

$settings = settings_get(true);
$videoCategories = $settings['video_categories'] ?? settings_default_categories();
$musicCategories = $settings['music_categories'] ?? [];
if ($videoCategories === []) {
    $videoCategories = [['id' => '', 'label' => '']];
}
if ($musicCategories === []) {
    $musicCategories = [['id' => '', 'label' => '']];
}

require app_view('config-categories');

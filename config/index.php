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
        'video_categories' => $current['video_categories'] ?? settings_default_categories(),
        'music_categories' => $current['music_categories'] ?? [],
        'video_roots' => $_POST['video_roots'] ?? [],
        'video_root_categories' => $_POST['video_root_categories'] ?? [],
        'music_roots' => $_POST['music_roots'] ?? [],
        'music_root_categories' => $_POST['music_root_categories'] ?? [],
        'video_excludes' => $_POST['video_excludes'] ?? [],
        'music_excludes' => $_POST['music_excludes'] ?? [],
        'tmdb_api_key' => $_POST['tmdb_api_key'] ?? '',
        'tmdb_language' => $_POST['tmdb_language'] ?? TMDB_LANGUAGE,
        'xai_api_key' => $_POST['xai_api_key'] ?? '',
        'show_hidden' => isset($_POST['show_hidden']),
    ];
    if (!is_array($payload['video_root_categories'])) {
        $payload['video_root_categories'] = [];
    }
    if (!is_array($payload['music_root_categories'])) {
        $payload['music_root_categories'] = [];
    }
    if (!is_array($payload['video_roots'])) {
        $payload['video_roots'] = [];
    }
    if (!is_array($payload['music_roots'])) {
        $payload['music_roots'] = [];
    }
    if (!is_array($payload['video_excludes'])) {
        $payload['video_excludes'] = [];
    }
    if (!is_array($payload['music_excludes'])) {
        $payload['music_excludes'] = [];
    }

    if (!cache_writable()) {
        $error = 'cache/ is not writable. Grant the http user write access so settings can be saved.';
    } elseif (!settings_save($payload)) {
        $error = 'Could not write settings.json. Check cache/ permissions.';
    } else {
        if (function_exists('app_log')) {
            app_log('config', 'Settings saved.', [
                'video_roots' => is_array($payload['video_roots']) ? count($payload['video_roots']) : 0,
                'music_roots' => is_array($payload['music_roots']) ? count($payload['music_roots']) : 0,
            ]);
        }
        $tab = (string) ($_POST['config_tab'] ?? 'video');
        if ($tab !== 'music' && $tab !== 'general') {
            $tab = 'video';
        }
        header('Location: index.php?saved=1&tab=' . rawurlencode($tab), true, 303);
        exit;
    }
}

$settings = settings_get(true);
$videoCategories = $settings['video_categories'] ?? settings_default_categories();
$musicCategories = $settings['music_categories'] ?? [];
$videoSources = $settings['video_roots'];
$musicSources = $settings['music_roots'];
$videoExcludes = $settings['video_excludes'] ?? [];
$musicExcludes = $settings['music_excludes'] ?? [];
if ($videoSources === []) {
    $videoSources = [['path' => '', 'category' => '']];
}
if ($musicSources === []) {
    $musicSources = [['path' => '', 'category' => '']];
}
if ($videoExcludes === []) {
    $videoExcludes = [''];
}
if ($musicExcludes === []) {
    $musicExcludes = [''];
}

$configTab = (string) ($_GET['tab'] ?? $_POST['config_tab'] ?? 'video');
if ($configTab !== 'music' && $configTab !== 'general') {
    $configTab = 'video';
}

require app_view('config');

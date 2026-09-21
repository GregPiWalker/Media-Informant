<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';

cache_init();

$saved = isset($_GET['saved']);
$discoverAdded = isset($_GET['added']) ? (int) $_GET['added'] : null;
$discoverFiles = isset($_GET['files']) ? (int) $_GET['files'] : null;
$discoverBusy = (string) ($_GET['discover'] ?? '') === 'busy';
$discoverError = (string) ($_GET['discover'] ?? '') === 'error';
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
        $oldVideo = settings_source_paths(is_array($current['video_roots'] ?? null) ? $current['video_roots'] : []);
        $oldMusic = settings_source_paths(is_array($current['music_roots'] ?? null) ? $current['music_roots'] : []);
        if (function_exists('catalog_store_drop_root')) {
            $newVideo = settings_video_roots();
            $newMusic = settings_music_roots();
            $newVideoSet = array_fill_keys($newVideo, true);
            $newMusicSet = array_fill_keys($newMusic, true);
            foreach ($oldVideo as $root) {
                if ($root !== '' && !isset($newVideoSet[$root])) {
                    catalog_store_drop_root('video', $root);
                }
            }
            foreach ($oldMusic as $root) {
                if ($root !== '' && !isset($newMusicSet[$root])) {
                    catalog_store_drop_root('music', $root);
                }
            }
        }
        $discoverQuery = '';
        $newVideo = settings_video_roots();
        if (function_exists('source_presence_refresh')) {
            source_presence_refresh('video', $newVideo);
        }
        $toDiscover = [];
        $emptyRoots = [];
        if (function_exists('source_roots_without_titles')) {
            $lib = function_exists('cache_read_library') ? cache_read_library() : ['items' => []];
            $emptyRoots = array_fill_keys(source_roots_without_titles($newVideo, $lib['items'] ?? []), true);
        }
        foreach ($newVideo as $root) {
            $present = function_exists('source_is_present') ? source_is_present($root) : (is_dir($root) && is_readable($root));
            if (!$present) {
                continue;
            }
            $wasConfigured = in_array($root, $oldVideo, true);
            if (!$wasConfigured || isset($emptyRoots[$root])) {
                $toDiscover[] = $root;
            }
        }
        if ($toDiscover !== [] && function_exists('catalog_discover_roots')) {
            $lock = function_exists('scan_lock_open') ? scan_lock_open() : false;
            if (function_exists('scan_lock_try') && !scan_lock_try($lock)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $discoverQuery = '&discover=busy';
            } else {
                try {
                    @set_time_limit(180);
                    $result = catalog_discover_roots($toDiscover, 'video');
                    $discoverQuery = '&added=' . (int) ($result['added'] ?? 0)
                        . '&kept=' . (int) ($result['kept'] ?? 0)
                        . '&files=' . (int) ($result['files'] ?? 0);
                    if (empty($result['ok'])) {
                        $discoverQuery .= '&discover=error';
                    }
                } finally {
                    if (function_exists('scan_lock_release')) {
                        scan_lock_release($lock);
                    } elseif (is_resource($lock)) {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            }
        }
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
        header('Location: index.php?saved=1&tab=' . rawurlencode($tab) . $discoverQuery, true, 303);
        exit;
    }
}

$settings = settings_get(true);
if (function_exists('source_presence_refresh')) {
    source_presence_refresh('video', settings_source_paths(is_array($settings['video_roots'] ?? null) ? $settings['video_roots'] : []));
    source_presence_refresh('music', settings_source_paths(is_array($settings['music_roots'] ?? null) ? $settings['music_roots'] : []));
}
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

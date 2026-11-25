<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require_once dirname(__DIR__) . '/lib/grok.php';

cache_init();

$saved = isset($_GET['saved']);
$refreshed = (string) ($_GET['refreshed'] ?? '');
$refreshAdded = isset($_GET['added']) ? (int) $_GET['added'] : 0;
$refreshDropped = isset($_GET['dropped']) ? (int) $_GET['dropped'] : 0;
$refreshFiles = isset($_GET['files']) ? (int) $_GET['files'] : 0;
$refreshSkipped = isset($_GET['skipped']) ? (int) $_GET['skipped'] : 0;
$refreshWalked = isset($_GET['walked']) ? (int) $_GET['walked'] : 0;
$error = '';
$kodiCheck = null;
$macroCheck = null;
$payload = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'refresh_sources') {
    $refreshCatalog = (string) ($_POST['catalog'] ?? '') === 'music' ? 'music' : 'video';
    $refreshRoots = $refreshCatalog === 'music' ? settings_music_roots() : settings_video_roots();
    if ($refreshRoots === []) {
        header('Location: index.php?tab=' . rawurlencode($refreshCatalog) . '&refreshed=empty', true, 303);
        exit;
    }
    if (function_exists('source_presence_refresh')) {
        source_presence_refresh($refreshCatalog, $refreshRoots);
    }
    $lock = function_exists('scan_lock_open') ? scan_lock_open() : false;
    if (function_exists('scan_lock_try') && !scan_lock_try($lock)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        header('Location: index.php?tab=' . rawurlencode($refreshCatalog) . '&refreshed=busy', true, 303);
        exit;
    }
    try {
        @set_time_limit(180);
        $result = catalog_discover_roots($refreshRoots, $refreshCatalog);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
        if (function_exists('app_log')) {
            app_log('scan', 'Refresh source folders failed.', [
                'catalog' => $refreshCatalog,
                'error' => $e->getMessage(),
            ], 'error');
        }
    } finally {
        if (function_exists('scan_lock_release')) {
            scan_lock_release($lock);
        } elseif (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    if (empty($result['ok'])) {
        header('Location: index.php?tab=' . rawurlencode($refreshCatalog) . '&refreshed=error', true, 303);
        exit;
    }
    $query = http_build_query([
        'tab' => $refreshCatalog,
        'refreshed' => $refreshCatalog,
        'added' => (int) ($result['added'] ?? 0),
        'dropped' => (int) ($result['dropped'] ?? 0),
        'kept' => (int) ($result['kept'] ?? 0),
        'files' => (int) ($result['files'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'walked' => count(is_array($result['roots'] ?? null) ? $result['roots'] : []),
    ]);
    header('Location: index.php?' . $query, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $current = settings_get();
    $hasKodiForm = isset($_POST['kodi_host_names']) || isset($_POST['kodi_host_addresses']) || array_key_exists('kodi_path_prefix', $_POST);
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
        'catalog_mode' => $_POST['catalog_mode'] ?? ($current['catalog_mode'] ?? 'both'),
    ];
    if ($hasKodiForm) {
        $payload['kodi_host_ids'] = $_POST['kodi_host_ids'] ?? [];
        $payload['kodi_host_names'] = $_POST['kodi_host_names'] ?? [];
        $payload['kodi_host_addresses'] = $_POST['kodi_host_addresses'] ?? [];
        $payload['kodi_host_ports'] = $_POST['kodi_host_ports'] ?? [];
        $payload['kodi_host_users'] = $_POST['kodi_host_users'] ?? [];
        $payload['kodi_host_passes'] = $_POST['kodi_host_passes'] ?? [];
        $payload['kodi_path_prefix'] = $_POST['kodi_path_prefix'] ?? '';
        $payload['kodi_macrodroid'] = isset($_POST['kodi_macrodroid']);
        $payload['kodi_macrodroid_port'] = $_POST['kodi_macrodroid_port'] ?? '';
        $payload['kodi_macrodroid_path'] = $_POST['kodi_macrodroid_path'] ?? '';
        $payload['kodi_macrodroid_delay'] = $_POST['kodi_macrodroid_delay'] ?? '';
    } else {
        $payload['kodi_hosts'] = $current['kodi_hosts'] ?? [];
        $payload['kodi_path_prefix'] = $current['kodi_path_prefix'] ?? '';
        $payload['kodi_macrodroid'] = $current['kodi_macrodroid'] ?? false;
        $payload['kodi_macrodroid_port'] = $current['kodi_macrodroid_port'] ?? 9091;
        $payload['kodi_macrodroid_path'] = $current['kodi_macrodroid_path'] ?? '/launch-kodi';
        $payload['kodi_macrodroid_delay'] = $current['kodi_macrodroid_delay'] ?? 3;
    }
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
    $payload['video_root_smb'] = settings_posted_smb(
        $payload['video_roots'],
        array_key_exists('video_root_smb', $_POST) ? $_POST['video_root_smb'] : null,
        is_array($current['video_roots'] ?? null) ? $current['video_roots'] : []
    );
    $payload['music_root_smb'] = settings_posted_smb(
        $payload['music_roots'],
        array_key_exists('music_root_smb', $_POST) ? $_POST['music_root_smb'] : null,
        is_array($current['music_roots'] ?? null) ? $current['music_roots'] : []
    );
    if ($hasKodiForm) {
        foreach (['kodi_host_ids', 'kodi_host_names', 'kodi_host_addresses', 'kodi_host_ports', 'kodi_host_users', 'kodi_host_passes'] as $kodiKey) {
            if (!is_array($payload[$kodiKey] ?? null)) {
                $payload[$kodiKey] = [];
            }
        }
    }

    $kodiCheck = $hasKodiForm ? settings_parse_kodi($payload, true) : null;
    $macroCheck = $hasKodiForm ? settings_kodi_macrodroid($payload, true) : null;
    if (!cache_writable()) {
        $error = 'cache/ is not writable. Grant the http user write access so settings can be saved.';
    } elseif (is_array($kodiCheck) && ($kodiCheck['error'] ?? '') !== '') {
        $error = (string) $kodiCheck['error'];
    } elseif (is_array($macroCheck) && ($macroCheck['error'] ?? '') !== '') {
        $error = (string) $macroCheck['error'];
    } elseif (($smbError = settings_smb_paths_error([$payload['video_root_smb'], $payload['music_root_smb']])) !== '') {
        $error = $smbError;
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
        if (function_exists('app_log')) {
            app_log('config', 'Settings saved.', [
                'video_roots' => is_array($payload['video_roots']) ? count($payload['video_roots']) : 0,
                'music_roots' => is_array($payload['music_roots']) ? count($payload['music_roots']) : 0,
            ]);
        }
        $tab = (string) ($_POST['config_tab'] ?? 'general');
        if ($tab !== 'video' && $tab !== 'music' && $tab !== 'general') {
            $tab = 'general';
        }
        header('Location: index.php?saved=1&tab=' . rawurlencode($tab), true, 303);
        exit;
    }
}

$settings = ($error !== '' && is_array($payload)) ? settings_normalize($payload) : settings_get(true);
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
$kodiPrefix = (string) ($settings['kodi_path_prefix'] ?? '');
$kodiRows = [];
$storedKodi = $settings['kodi_hosts'] ?? [];
if (is_array($storedKodi)) {
    foreach ($storedKodi as $kodiHost) {
        if (!is_array($kodiHost)) {
            continue;
        }
        $kodiRows[] = [
            'id' => (string) ($kodiHost['id'] ?? ''),
            'name' => (string) ($kodiHost['name'] ?? ''),
            'host' => (string) ($kodiHost['host'] ?? ''),
            'port' => (string) ($kodiHost['port'] ?? ''),
            'user' => (string) ($kodiHost['user'] ?? ''),
            'pass' => (string) ($kodiHost['pass'] ?? ''),
        ];
    }
}
if (is_array($kodiCheck) && $error !== '') {
    $postedRows = $kodiCheck['rows'] ?? [];
    $kodiRows = is_array($postedRows) ? $postedRows : [];
    $kodiPrefix = (string) ($kodiCheck['prefix'] ?? $kodiPrefix);
}
if (is_array($macroCheck) && $error !== '') {
    $settings['kodi_macrodroid'] = !empty($macroCheck['enabled']);
    $settings['kodi_macrodroid_port'] = (string) ($macroCheck['port_text'] ?? '');
    $settings['kodi_macrodroid_path'] = (string) ($macroCheck['path_text'] ?? '');
    $settings['kodi_macrodroid_delay'] = (string) ($macroCheck['delay_text'] ?? '');
}
if ($kodiRows === []) {
    $kodiRows[] = ['id' => '', 'name' => '', 'host' => '', 'port' => '', 'user' => '', 'pass' => ''];
}

$grokKeyState = function_exists('grok_key_check_state')
    ? grok_key_check_state((string) ($settings['xai_api_key'] ?? ''))
    : '';

$configTab = (string) ($_GET['tab'] ?? $_POST['config_tab'] ?? 'general');
if ($configTab !== 'video' && $configTab !== 'music' && $configTab !== 'general') {
    $configTab = 'general';
}

require app_view('config');

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/cache.php';
require_once dirname(__DIR__) . '/lib/smart_search.php';

cache_init();

$configured = smart_search_available();
$chat = $configured ? smart_search_current() : null;
$state = $configured ? smart_search_resume($chat) : smart_search_empty_state();
$kodiOn = $configured && smart_search_kodi_on();
$kodiPlayLabel = 'Play on Kodi';
if ($kodiOn && function_exists('kodi_selected_host')) {
    $host = kodi_selected_host();
    $name = is_array($host) ? trim((string) ($host['name'] ?? '')) : '';
    if ($name === '' && is_array($host)) {
        $name = trim((string) ($host['host'] ?? ''));
    }
    if ($name !== '') {
        $kodiPlayLabel = 'Play on ' . $name;
    }
}

require app_view('smart');

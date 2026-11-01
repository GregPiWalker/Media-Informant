<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';

$catalogMode = function_exists('settings_catalog_mode') ? settings_catalog_mode() : 'both';
if ($catalogMode === 'video') {
    header('Location: ' . app_href('video/index.php'), true, 302);
    exit;
}
if ($catalogMode === 'music') {
    header('Location: ' . app_href('music/index.php'), true, 302);
    exit;
}

render_start('Media Informant');
render_header(['section' => 'home']);
require app_view('home');
render_end();

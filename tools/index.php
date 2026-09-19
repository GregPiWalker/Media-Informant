<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/tools.php';

cache_init();
$sources = tools_sources();

render_start('Tools · Media Informant');
render_header(['section' => 'tools', 'branches' => true]);
require app_view('tools');
render_end();

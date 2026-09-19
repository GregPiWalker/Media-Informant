<?php
declare(strict_types=1);

// Music catalog stub. Scan page exists at music/scan.php; Start stays disabled until Music is built out.

require dirname(__DIR__) . '/lib/config.php';

render_start('Music · Media Informant');
render_header(['section' => 'music', 'branches' => true]);
require app_view('music');
render_end();

<?php
declare(strict_types=1);

// Music feature stub. Future: music/scan.php, album/track detail, lib parser + cache under cache/music/.

require dirname(__DIR__) . '/lib/config.php';

render_start('Music · Media Informant');
render_header(['section' => 'music', 'branches' => true]);
require app_view('music');
render_end();

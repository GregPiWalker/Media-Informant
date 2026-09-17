<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';

render_start('Media Informant');
render_header(['section' => 'home']);
require app_view('home');
render_end();

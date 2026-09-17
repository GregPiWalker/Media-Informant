<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
header('Location: ' . app_href('video/scan.php'), true, 302);
exit;

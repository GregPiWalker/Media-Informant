<?php
declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: video/title.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;

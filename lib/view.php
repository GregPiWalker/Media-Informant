<?php
declare(strict_types=1);

/**
 * Layout shell. Mobile is the default. The client records desktop vs mobile
 * from pointer/hover capabilities (not the user-agent) so PHP can pick a
 * view on the next request. Desktop templates are not built yet; app_view()
 * falls back to views/mobile/.
 */

function app_shell(): string
{
    $raw = $_COOKIE['media_shell'] ?? 'mobile';
    return $raw === 'desktop' ? 'desktop' : 'mobile';
}

function app_view(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = ltrim($name, '/');
    if ($name === '' || str_contains($name, '..')) {
        return APP_ROOT . '/views/mobile/home.php';
    }
    $shell = app_shell();
    $file = APP_ROOT . '/views/' . $shell . '/' . $name . '.php';
    if (!is_file($file)) {
        $file = APP_ROOT . '/views/mobile/' . $name . '.php';
    }
    return $file;
}

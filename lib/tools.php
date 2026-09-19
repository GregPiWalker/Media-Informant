<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sources.php';

function tools_junk_name(string $name): bool
{
    $l = strtolower($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return true;
    }
    if (str_starts_with($name, '.')) {
        return true;
    }
    return in_array($l, ['@eadir', '#recycle', '@recycle', '#snapshot', 'thumbs.db', 'desktop.ini'], true);
}

function tools_sources(): array
{
    $out = [];
    $seen = [];
    $add = static function (string $prefix, array $paths, string $catalog) use (&$out, &$seen): void {
        foreach ($paths as $i => $path) {
            $path = settings_normalize_path((string) $path);
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $present = function_exists('source_is_present')
                ? source_is_present($path)
                : (is_dir($path) && is_readable($path));
            $out[] = [
                'id' => $prefix . $i,
                'path' => $path,
                'catalog' => $catalog,
                'label' => $path,
                'present' => $present,
            ];
        }
    };
    $add('v', settings_video_roots(), 'video');
    $add('m', settings_music_roots(), 'music');
    return $out;
}

function tools_source_by_id(string $id): ?array
{
    foreach (tools_sources() as $source) {
        if ($source['id'] === $id) {
            return $source;
        }
    }
    return null;
}

function tools_excludes_for(string $catalog): array
{
    return $catalog === 'music' ? settings_music_excludes() : settings_video_excludes();
}

function tools_normalize_rel(string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    $rel = trim($rel, '/');
    if ($rel === '') {
        return '';
    }
    if (str_contains($rel, "\0")) {
        return null;
    }
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }
    return $rel;
}

function tools_rel_under_root(string $root, string $full): ?string
{
    $root = settings_normalize_path($root);
    $full = settings_normalize_path($full);
    if ($root === '' || $full === '') {
        return null;
    }
    if ($full === $root) {
        return '';
    }
    if (!str_starts_with($full, $root . '/')) {
        return null;
    }
    return substr($full, strlen($root) + 1);
}

function tools_resolve_dir(string $root, string $rel): ?string
{
    $root = settings_normalize_path($root);
    if ($root === '' || settings_path_blocked($root) || !is_dir($root)) {
        return null;
    }
    $rel = tools_normalize_rel($rel);
    if ($rel === null) {
        return null;
    }
    $full = $rel === '' ? $root : $root . '/' . $rel;
    $full = settings_normalize_path($full);
    if (!is_dir($full) || !is_readable($full)) {
        return null;
    }
    $rootReal = realpath($root);
    $fullReal = realpath($full);
    if ($rootReal === false || $fullReal === false) {
        return null;
    }
    $rootN = settings_normalize_path($rootReal);
    $fullN = settings_normalize_path($fullReal);
    if ($fullN !== $rootN && !str_starts_with($fullN, $rootN . '/')) {
        return null;
    }
    return $fullN;
}

function tools_dir_has_children(string $abs, array $excludes): bool
{
    $dh = @opendir($abs);
    if ($dh === false) {
        return false;
    }
    while (($name = readdir($dh)) !== false) {
        if (tools_junk_name($name)) {
            continue;
        }
        $child = $abs . '/' . $name;
        if (is_dir($child) && settings_path_is_excluded($child, $excludes)) {
            continue;
        }
        closedir($dh);
        return true;
    }
    closedir($dh);
    return false;
}

function tools_list_dir(string $abs, array $excludes): array
{
    $folders = [];
    $files = [];
    $exts = [];
    $names = @scandir($abs);
    if (!is_array($names)) {
        return ['folders' => [], 'files' => [], 'extensions' => []];
    }
    foreach ($names as $name) {
        if (tools_junk_name($name)) {
            continue;
        }
        $child = $abs . '/' . $name;
        if (is_dir($child)) {
            if (settings_path_is_excluded($child, $excludes)) {
                continue;
            }
            $folders[] = [
                'name' => $name,
                'has_children' => tools_dir_has_children($child, $excludes),
            ];
            continue;
        }
        if (!is_file($child)) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $files[] = [
            'name' => $name,
            'ext' => $ext,
        ];
        if ($ext !== '' && !isset($exts[$ext])) {
            $exts[$ext] = true;
        }
    }
    usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    $extensions = array_keys($exts);
    natcasesort($extensions);
    $extensions = array_values($extensions);
    return [
        'folders' => $folders,
        'files' => $files,
        'extensions' => $extensions,
    ];
}

function tools_safe_filename(string $name): bool
{
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return false;
    }
    return true;
}

/**
 * @param list<string> $extensions
 * @return array{renamed: int, skipped: int, errors: int, catalog: int, changes: list<array{from: string, to: string, ok: bool, error?: string, catalog?: bool}>}
 */
function tools_rename_group(string $dir, string $find, string $replace, array $extensions, string $catalog = '', string $root = ''): array
{
    $changes = [];
    $renamed = 0;
    $skipped = 0;
    $errors = 0;
    $catalogN = 0;
    $wanted = [];
    foreach ($extensions as $ext) {
        $ext = strtolower(ltrim(trim((string) $ext), '.'));
        if ($ext !== '') {
            $wanted[$ext] = true;
        }
    }
    if ($wanted === [] || $find === '' || !is_dir($dir) || !is_writable($dir)) {
        return ['renamed' => 0, 'skipped' => 0, 'errors' => 0, 'catalog' => 0, 'changes' => []];
    }

    $names = @scandir($dir);
    if (!is_array($names)) {
        return ['renamed' => 0, 'skipped' => 0, 'errors' => 1, 'catalog' => 0, 'changes' => []];
    }

    foreach (array_keys($wanted) as $ext) {
        foreach ($names as $name) {
            if (tools_junk_name($name) || !is_file($dir . '/' . $name)) {
                continue;
            }
            $have = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($have !== $ext) {
                continue;
            }
            if (!str_contains($name, $find)) {
                continue;
            }
            $to = str_replace($find, $replace, $name);
            if ($to === $name) {
                continue;
            }
            if (!tools_safe_filename($to)) {
                $errors++;
                $changes[] = ['from' => $name, 'to' => $to, 'ok' => false, 'error' => 'Invalid name'];
                continue;
            }
            $fromPath = $dir . '/' . $name;
            $toPath = $dir . '/' . $to;
            if (file_exists($toPath) && strcasecmp($fromPath, $toPath) !== 0) {
                $skipped++;
                $changes[] = ['from' => $name, 'to' => $to, 'ok' => false, 'error' => 'Already exists'];
                continue;
            }
            if (!@rename($fromPath, $toPath)) {
                $errors++;
                $changes[] = ['from' => $name, 'to' => $to, 'ok' => false, 'error' => 'Rename failed'];
                continue;
            }
            $renamed++;
            $inCatalog = false;
            if ($catalog !== '' && $root !== '' && function_exists('catalog_store_rename_file')) {
                $oldRel = tools_rel_under_root($root, $fromPath);
                $newRel = tools_rel_under_root($root, $toPath);
                if ($oldRel !== null && $oldRel !== '' && $newRel !== null && $newRel !== '') {
                    $inCatalog = catalog_store_rename_file($catalog, $root, $oldRel, $newRel);
                    if ($inCatalog) {
                        $catalogN++;
                    }
                }
            }
            $row = ['from' => $name, 'to' => $to, 'ok' => true, 'catalog' => $inCatalog];
            if (!$inCatalog && $catalog !== '') {
                $row['error'] = 'File renamed; catalog record not found';
            }
            $changes[] = $row;
        }
    }

    return [
        'renamed' => $renamed,
        'skipped' => $skipped,
        'errors' => $errors,
        'catalog' => $catalogN,
        'changes' => $changes,
    ];
}

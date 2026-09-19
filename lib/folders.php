<?php
declare(strict_types=1);

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/parser.php';

function folder_cache_path(): string
{
    return CACHE_DIR . '/folders.json';
}

function folder_id(string $root, string $relDir): string
{
    return substr(hash('sha256', $root . "\n" . $relDir), 0, 16);
}

function folder_parent_rel(string $relPath): string
{
    $relPath = str_replace('\\', '/', $relPath);
    $dir = dirname($relPath);
    if ($dir === '.' || $dir === '/' || $dir === '') {
        return '';
    }
    return $dir;
}

function folder_home_for_file(string $relPath): string
{
    $parent = folder_parent_rel($relPath);
    if ($parent === '') {
        return '';
    }
    $base = basename($parent);
    if (function_exists('season_folder_number') && season_folder_number($base) !== null) {
        return folder_parent_rel($parent);
    }
    return $parent;
}

function folder_read_all(): array
{
    $data = cache_read_json(folder_cache_path());
    if (!is_array($data)) {
        return ['version' => 1, 'folders' => []];
    }
    if (!isset($data['folders']) || !is_array($data['folders'])) {
        $data['folders'] = [];
    }
    $data['version'] = 1;
    return $data;
}

function folder_write_all(array $data): bool
{
    $data['version'] = 1;
    if (!isset($data['folders']) || !is_array($data['folders'])) {
        $data['folders'] = [];
    }
    return cache_write_atomic(folder_cache_path(), $data);
}

function folder_get(string $root, string $relDir): ?array
{
    if ($relDir === '') {
        return null;
    }
    $all = folder_read_all();
    $row = $all['folders'][folder_id($root, $relDir)] ?? null;
    return is_array($row) ? $row : null;
}

function folder_kind_for_file(string $root, string $relPath): string
{
    $home = folder_home_for_file($relPath);
    if ($home === '') {
        return '';
    }
    $row = folder_get($root, $home);
    $kind = (string) ($row['kind'] ?? '');
    return $kind === 'show' || $kind === 'movie' ? $kind : '';
}

function scan_parse_path(string $root, string $path, string $category = ''): array
{
    $folderKind = folder_kind_for_file($root, $path);
    $parsed = parse_media($path, 'video', $folderKind);
    $kind = (string) ($parsed['kind'] ?? 'movie');
    if ($kind !== 'show' && $kind !== 'movie' && $kind !== 'documentary') {
        $kind = 'movie';
    }
    $parsedKind = $kind;
    $catKind = function_exists('settings_content_kind_from_category')
        ? settings_content_kind_from_category($category)
        : '';
    if ($catKind === 'documentary') {
        $kind = 'documentary';
    } elseif ($kind !== 'show' && $kind !== 'documentary' && $catKind === 'show') {
        $kind = 'show';
    }
    $grouped = $kind === 'show' || $parsedKind === 'show' || $folderKind === 'show';
    $parsed['kind'] = $kind;
    $parsed['grouped'] = $grouped;
    return $parsed;
}

function folder_detect_kind(array $filenames): string
{
    $n = count($filenames);
    if ($n < 2) {
        return '';
    }
    $coded = 0;
    $hints = [];
    foreach ($filenames as $fn) {
        $base = strip_extension((string) $fn);
        $tokens = parse_name($base);
        $hasCode = (bool) preg_match('/\b(?:S\d{1,2}E\d{1,3}|\d{1,2}x\d{1,3}|(?:e|ep|episode)\.?\s*\d{1,3})\b/i', $base);
        if ($hasCode) {
            $coded++;
        }
        if ($tokens['episode'] !== null) {
            $hints[(int) $tokens['episode']] = true;
            continue;
        }
        $lead = parse_leading_episode($base);
        if ($lead !== null) {
            $hints[(int) $lead['episode']] = true;
        }
    }
    if ($coded >= 2) {
        return 'show';
    }
    $eps = count($hints);
    if ($n >= 5 && $eps >= max(4, (int) ceil($n * 0.6))) {
        $nums = array_keys($hints);
        sort($nums);
        $span = $nums[count($nums) - 1] - $nums[0] + 1;
        if ($nums[0] >= 1 && $nums[0] <= 30 && $span <= max($eps + 2, (int) ($eps * 1.5))) {
            return 'show';
        }
    }
    return '';
}

function folder_learn_from_files(array $files, array $walkedRoots = []): void
{
    $byHome = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $root = (string) ($file['root'] ?? '');
        $path = (string) ($file['path'] ?? '');
        if ($root === '' || $path === '') {
            continue;
        }
        $parent = folder_parent_rel($path);
        if ($parent === '') {
            continue;
        }
        $home = folder_home_for_file($path);
        if ($home === '') {
            continue;
        }
        $key = $root . "\n" . $home;
        if (!isset($byHome[$key])) {
            $byHome[$key] = ['root' => $root, 'path' => $home, 'names' => [], 'seasoned' => false];
        }
        $byHome[$key]['names'][] = (string) ($file['filename'] ?? '');
        $leaf = basename($parent);
        if (season_folder_number($leaf) !== null) {
            $byHome[$key]['seasoned'] = true;
        }
    }

    $all = folder_read_all();
    $seen = [];
    foreach ($byHome as $key => $row) {
        $seen[$key] = true;
        $id = folder_id($row['root'], $row['path']);
        $existing = $all['folders'][$id] ?? null;
        if (is_array($existing) && ($existing['kind_source'] ?? '') === 'user') {
            continue;
        }
        $kind = $row['seasoned'] ? 'show' : folder_detect_kind($row['names']);
        if ($kind === '') {
            if (is_array($existing) && ($existing['kind_source'] ?? '') !== 'user') {
                unset($all['folders'][$id]);
            }
            continue;
        }
        $title = parse_name(basename((string) $row['path']))['title'] ?? '';
        if ($title === '') {
            $title = basename((string) $row['path']);
        }
        $all['folders'][$id] = [
            'root' => $row['root'],
            'path' => $row['path'],
            'kind' => $kind,
            'kind_source' => 'auto',
            'title' => $title,
            'updated_at' => time(),
        ];
    }
    $walkedSet = [];
    foreach ($walkedRoots as $root) {
        $walkedSet[function_exists('settings_normalize_path') ? settings_normalize_path((string) $root) : (string) $root] = true;
    }
    foreach ($all['folders'] as $id => $row) {
        if (!is_array($row) || ($row['kind_source'] ?? '') === 'user') {
            continue;
        }
        $rowRoot = function_exists('settings_normalize_path')
            ? settings_normalize_path((string) ($row['root'] ?? ''))
            : (string) ($row['root'] ?? '');
        if ($walkedSet !== [] && $rowRoot !== '' && !isset($walkedSet[$rowRoot])) {
            continue;
        }
        $key = (string) ($row['root'] ?? '') . "\n" . (string) ($row['path'] ?? '');
        if (!isset($seen[$key])) {
            unset($all['folders'][$id]);
        }
    }
    folder_write_all($all);
}

function folder_set_user_kind(string $root, string $relDir, string $kind): bool
{
    $relDir = str_replace('\\', '/', $relDir);
    if ($root === '' || $relDir === '' || ($kind !== 'show' && $kind !== 'movie')) {
        return false;
    }
    $all = folder_read_all();
    $id = folder_id($root, $relDir);
    $title = parse_name(basename($relDir))['title'] ?? '';
    if ($title === '') {
        $title = basename($relDir);
    }
    $all['folders'][$id] = [
        'root' => $root,
        'path' => $relDir,
        'kind' => $kind,
        'kind_source' => 'user',
        'title' => $title,
        'updated_at' => time(),
    ];
    return folder_write_all($all);
}

function library_reclassify_folder(string $root, string $relDir): int
{
    $library = cache_read_library();
    $items = is_array($library['items'] ?? null) ? $library['items'] : [];
    $prefix = $relDir . '/';
    $changed = 0;
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['root'] ?? '') !== $root) {
            continue;
        }
        $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
        if ($path !== $relDir && !str_starts_with($path, $prefix)) {
            continue;
        }
        if (library_has_user_kind($item)) {
            continue;
        }
        $parsed = scan_parse_path($root, $path, (string) ($item['category'] ?? ''));
        $item['kind'] = (string) ($parsed['kind'] ?? $item['kind'] ?? 'movie');
        $item['season'] = $parsed['season'];
        $item['episode'] = $parsed['episode'];
        $item['episode_title'] = (string) ($parsed['episode_title'] ?? '');
        $item['part'] = (string) ($parsed['part'] ?? '');
        if (library_item_kind($item) === 'show') {
            $item['grouped'] = true;
        } elseif (!library_has_user_grouped($item)) {
            $item['grouped'] = !empty($parsed['grouped']);
        }
        if ($parsed['title'] !== '') {
            $item['title'] = $parsed['title'];
            if (($item['status'] ?? '') !== 'matched' || !library_has_custom_title($item)) {
                $item = library_set_auto_title($item, $parsed['title']);
            }
        }
        $items[$i] = $item;
        $changed++;
    }
    if ($changed > 0) {
        $library['items'] = $items;
        cache_write_library($library);
    }
    return $changed;
}

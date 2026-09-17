<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog_store.php';

function cache_init(): void
{
    foreach ([CACHE_DIR, CACHE_DIR . '/titles'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    if (function_exists('db_migrate_on_open')) {
        db_migrate_on_open();
    }
}

function cache_writable(): bool
{
    cache_init();
    return is_dir(CACHE_DIR) && is_writable(CACHE_DIR) && is_writable(CACHE_DIR . '/titles');
}

function cache_library_path(): string
{
    return CACHE_DIR . '/library.json';
}

function cache_title_path(int $tmdbId): string
{
    return CACHE_DIR . '/titles/' . $tmdbId . '.json';
}

function cache_write_atomic(string $path, array $data, bool $pretty = false): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (@rename($tmp, $path)) {
        return true;
    }

    @unlink($path);
    $ok = @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}

function cache_read_json(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cache_read_library(): array
{
    $out = [
        'version' => 1,
        'scanned_at' => null,
        'video_roots' => function_exists('settings_video_roots') ? settings_video_roots() : [VIDEO_ROOT],
        'items' => [],
    ];
    $items = catalog_store_load('video');
    $meta = catalog_store_meta('video');
    $out['items'] = $items;
    if (isset($meta['updated_at']) && $meta['updated_at'] !== null && $meta['updated_at'] !== '') {
        $out['scanned_at'] = (int) $meta['updated_at'];
    }
    return $out;
}

function cache_write_library(array $library): bool
{
    $items = is_array($library['items'] ?? null) ? $library['items'] : [];
    $scanned = isset($library['scanned_at']) ? (int) $library['scanned_at'] : time();
    return catalog_store_save('video', $items, $scanned);
}

function cache_read_title(int $tmdbId): ?array
{
    return cache_read_json(cache_title_path($tmdbId));
}

function cache_write_title(int $tmdbId, array $title): bool
{
    $title['tmdb_id'] = $tmdbId;
    return cache_write_atomic(cache_title_path($tmdbId), $title);
}

function cache_delete_title(int $tmdbId): void
{
    if ($tmdbId < 1) {
        return;
    }
    $path = cache_title_path($tmdbId);
    clearstatcache(true, $path);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Remove cached TMDB title JSON that no remaining library item uses.
 *
 * @param list<array<string, mixed>> $items
 */
function cache_prune_excluded_items(?array $excludes = null): int
{
    $excludes ??= function_exists('settings_video_excludes') ? settings_video_excludes() : [];
    $library = cache_read_library();
    $items = is_array($library['items'] ?? null) ? $library['items'] : [];
    $kept = [];
    $dropped = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (function_exists('settings_item_excluded') && settings_item_excluded($item, $excludes, $library)) {
            $dropped++;
            continue;
        }
        $kept[] = $item;
    }
    if ($dropped < 1) {
        return 0;
    }
    $library['items'] = $kept;
    cache_write_library($library);
    cache_prune_orphan_titles($kept);
    return $dropped;
}

function cache_prune_orphan_titles(array $items): int
{
    $used = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['tmdb_id'] ?? 0);
        if ($id > 0) {
            $used[$id] = true;
        }
    }
    $dir = CACHE_DIR . '/titles';
    if (!is_dir($dir)) {
        return 0;
    }
    $removed = 0;
    $names = @scandir($dir);
    if (!is_array($names)) {
        return 0;
    }
    foreach ($names as $name) {
        if (!preg_match('/^(\d+)\.json$/', $name, $m)) {
            continue;
        }
        $id = (int) $m[1];
        if ($id < 1 || isset($used[$id])) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (@unlink($path)) {
            $removed++;
        }
    }
    return $removed;
}

function cache_find_item(array $library, string $id): ?array
{
    $row = catalog_store_find('video', $id);
    if ($row !== null) {
        return $row;
    }
    foreach ($library['items'] as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function cache_item_id(string $relativePath, string $root = ''): string
{
    return substr(hash('sha256', $root . "\n" . $relativePath), 0, 16);
}

function library_item_status(array $item): string
{
    $status = (string) ($item['status'] ?? '');
    if ($status === 'matched' || $status === 'unmatched' || $status === 'unidentified') {
        return $status;
    }
    return 'unidentified';
}

function library_item_hidden(array $item): bool
{
    $value = $item['hidden'] ?? false;
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    $s = strtolower(trim((string) $value));
    return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
}

function library_set_hidden(array $item, bool $hidden): array
{
    if ($hidden) {
        $item['hidden'] = true;
    } else {
        unset($item['hidden']);
    }
    return $item;
}

function library_flag_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    $s = strtolower(trim((string) $value));
    return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
}

function library_item_kind(array $item): string
{
    $kind = (string) ($item['kind'] ?? '');
    if ($kind === 'show' || $kind === 'movie' || $kind === 'documentary') {
        return $kind;
    }
    if (($item['season'] ?? null) !== null && $item['season'] !== '') {
        return 'show';
    }
    if (($item['episode'] ?? null) !== null && $item['episode'] !== '') {
        return 'show';
    }
    return 'movie';
}

function library_has_user_kind(array $item): bool
{
    return ($item['kind_source'] ?? '') === 'user';
}

function library_has_user_grouped(array $item): bool
{
    return ($item['grouped_source'] ?? '') === 'user';
}

function library_item_grouped(array $item): bool
{
    if (library_item_kind($item) === 'show') {
        return true;
    }
    if (array_key_exists('grouped', $item)) {
        return library_flag_bool($item['grouped']);
    }
    return false;
}

function library_kind_from_genres(array $genres, string $current): string
{
    if ($current === 'show') {
        return 'show';
    }
    foreach ($genres as $genre) {
        if (strcasecmp(trim((string) $genre), 'Documentary') === 0) {
            return 'documentary';
        }
    }
    return $current === 'documentary' ? 'documentary' : ($current === 'movie' ? 'movie' : $current);
}

function library_set_item_kind(array $item, string $kind): array
{
    if ($kind !== 'show' && $kind !== 'documentary') {
        $kind = 'movie';
    }
    $item['kind'] = $kind;
    $item['kind_source'] = 'user';
    if ($kind === 'show') {
        $item['grouped'] = true;
    }
    return $item;
}

function library_set_item_grouped(array $item, bool $grouped): array
{
    if (library_item_kind($item) === 'show') {
        $item['grouped'] = true;
        $item['grouped_source'] = 'user';
        return $item;
    }
    $item['grouped'] = $grouped;
    $item['grouped_source'] = 'user';
    $root = (string) ($item['root'] ?? '');
    $path = (string) ($item['path'] ?? '');
    if (!$grouped) {
        $label = trim((string) ($item['episode_title'] ?? ''));
        if ($label === '') {
            $fn = (string) ($item['filename'] ?? '');
            $label = $fn !== '' ? pathinfo($fn, PATHINFO_FILENAME) : '';
        }
        if ($label === '') {
            $label = (string) ($item['display_title'] ?? $item['title'] ?? 'Untitled');
        }
        $item['title'] = $label;
        if (!library_has_custom_title($item)) {
            $item = library_set_auto_title($item, $label);
        }
        return $item;
    }

    $series = '';
    if ($root !== '' && $path !== '' && function_exists('folder_home_for_file')) {
        $home = folder_home_for_file($path);
        if ($home !== '') {
            $series = function_exists('parse_name') ? (string) (parse_name(basename($home))['title'] ?? '') : '';
            if ($series === '') {
                $series = basename($home);
            }
        }
    }
    if ($series === '') {
        $series = (string) ($item['title'] ?? $item['display_title'] ?? 'Untitled');
    }
    $item['title'] = $series;
    if (function_exists('scan_parse_path') && $root !== '' && $path !== '') {
        $parsed = scan_parse_path($root, $path);
        if (($item['season'] ?? null) === null || $item['season'] === '') {
            $item['season'] = $parsed['season'] ?? 1;
        }
        if (($item['episode'] ?? null) === null || $item['episode'] === '') {
            $item['episode'] = $parsed['episode'] ?? null;
        }
        if (trim((string) ($item['episode_title'] ?? '')) === '' && !empty($parsed['episode_title'])) {
            $item['episode_title'] = (string) $parsed['episode_title'];
        }
    }
    if (($item['season'] ?? null) === null || $item['season'] === '') {
        $item['season'] = 1;
    }
    if (!library_has_custom_title($item)) {
        $item = library_set_auto_title($item, $series);
    }
    return $item;
}

function library_status_label(string $status): string
{
    return match ($status) {
        'matched' => 'Matched',
        'unmatched' => 'Unmatched',
        default => 'Unidentified',
    };
}

function library_normalize_match_source(string $source): string
{
    $source = strtolower(trim($source));
    if ($source === 'tmdb' || $source === 'auto') {
        return 'direct';
    }
    if ($source === 'direct' || $source === 'grok' || $source === 'manual' || $source === 'none') {
        return $source;
    }
    return 'none';
}

function library_match_source(array $item): string
{
    $status = library_item_status($item);
    $source = library_normalize_match_source((string) ($item['match_source'] ?? ''));
    if ($status !== 'matched') {
        return 'none';
    }
    if ($source === 'none') {
        return 'direct';
    }
    return $source;
}

function library_match_source_label(string $source): string
{
    return match (library_normalize_match_source($source)) {
        'grok' => 'Grok',
        'direct' => 'Direct',
        'manual' => 'Manual',
        default => 'None',
    };
}

function library_status_display(array $item): string
{
    $status = library_status_label(library_item_status($item));
    $subs = [];
    $source = library_match_source($item);
    if ($source !== 'none') {
        $subs[] = library_match_source_label($source);
    }
    if ($subs === []) {
        return $status;
    }
    return $status . ' (' . implode(', ', $subs) . ')';
}

function library_status_sort_key(array $item): string
{
    return library_item_status($item) . '|' . library_match_source($item);
}

function library_has_custom_title(array $item): bool
{
    return ($item['title_source'] ?? '') === 'user'
        && trim((string) ($item['display_title'] ?? '')) !== '';
}

function library_set_auto_title(array $item, string $title): array
{
    if (library_has_custom_title($item)) {
        return $item;
    }
    $item['display_title'] = $title;
    return $item;
}

function cache_string_list(mixed $value): array
{
    $out = [];
    if (!is_array($value)) {
        return $out;
    }
    foreach ($value as $entry) {
        if (is_string($entry) && $entry !== '') {
            $out[] = $entry;
        }
    }
    return $out;
}

function cache_update_item(string $id, callable $mutator): ?array
{
    $item = catalog_store_find('video', $id);
    if ($item === null) {
        return null;
    }
    $next = $mutator($item);
    if (!is_array($next)) {
        return null;
    }
    if (!catalog_store_update_one('video', $next)) {
        return null;
    }
    return $next;
}

function cache_apply_tmdb_match(array $item, ?array $meta, string $source = 'direct'): array
{
    if ($meta === null || empty($meta['tmdb_id'])) {
        $item['match_source'] = 'none';
        $item['tmdb_id'] = null;
        $item['status'] = 'unmatched';
        $item['poster_path'] = null;
        $item = library_set_auto_title($item, (string) ($item['title'] ?? 'Untitled'));
        $item['genres'] = [];
        return $item;
    }
    $item['match_source'] = library_normalize_match_source($source);
    if ($item['match_source'] === 'none') {
        $item['match_source'] = 'direct';
    }
    $item['tmdb_id'] = (int) $meta['tmdb_id'];
    $item['status'] = 'matched';
    $item['poster_path'] = $meta['poster_path'] ?? null;
    $item = library_set_auto_title($item, (string) ($meta['title'] ?? $item['title'] ?? 'Untitled'));
    if (!empty($meta['year'])) {
        $item['year'] = (int) $meta['year'];
    }
    $item['genres'] = cache_string_list($meta['genres'] ?? []);
    if (!library_has_user_kind($item)) {
        $item['kind'] = library_kind_from_genres($item['genres'], library_item_kind($item));
    }
    if (library_item_kind($item) === 'show') {
        $item['grouped'] = true;
    }
    return $item;
}

function library_clear_match(array $item): array
{
    $item['tmdb_id'] = null;
    $item['status'] = 'unidentified';
    $item['match_source'] = 'none';
    $item['poster_path'] = null;
    $item['tmdb_candidates'] = [];
    unset($item['overview']);
    $item['genres'] = [];
    if (!library_has_custom_title($item)) {
        $item['display_title'] = (string) ($item['title'] ?? 'Untitled');
        unset($item['title_source']);
    }
    return $item;
}

<?php
declare(strict_types=1);

function cache_init(): void
{
    foreach ([CACHE_DIR, CACHE_DIR . '/titles'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
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
    $data = cache_read_json(cache_library_path());
    if ($data === null) {
        return [
            'version' => 1,
            'scanned_at' => null,
            'video_roots' => function_exists('settings_video_roots') ? settings_video_roots() : [VIDEO_ROOT],
            'items' => [],
        ];
    }
    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
    }
    return $data;
}

function cache_write_library(array $library): bool
{
    $library['version'] = 1;
    return cache_write_atomic(cache_library_path(), $library);
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

function cache_find_item(array $library, string $id): ?array
{
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
    $library = cache_read_library();
    foreach ($library['items'] as $i => $item) {
        if (!is_array($item) || ($item['id'] ?? '') !== $id) {
            continue;
        }
        $next = $mutator($item);
        if (!is_array($next)) {
            return null;
        }
        $library['items'][$i] = $next;
        if (!cache_write_library($library)) {
            return null;
        }
        return $next;
    }
    return null;
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

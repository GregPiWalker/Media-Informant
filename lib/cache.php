<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog_store.php';
require_once __DIR__ . '/sources.php';

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

function cache_title_kind(?array $title): string
{
    if (!is_array($title)) {
        return '';
    }
    $kind = (string) ($title['media_type'] ?? '');
    return $kind === 'tv' || $kind === 'movie' ? $kind : '';
}

function cache_title_typed_path(int $tmdbId, string $mediaType): string
{
    $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
    return CACHE_DIR . '/titles/' . $mediaType . '-' . $tmdbId . '.json';
}

/**
 * Movie and TV ids are separate TMDB namespaces. titles/{id}.json is shared,
 * so it is used only when its media_type matches. A conflicting file is left
 * alone and the other type is stored as titles/{tv|movie}-{id}.json.
 */
function cache_read_title_for(int $tmdbId, string $mediaType): ?array
{
    if ($tmdbId < 1) {
        return null;
    }
    $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
    $typed = cache_read_json(cache_title_typed_path($tmdbId, $mediaType));
    if (is_array($typed)) {
        $typedKind = cache_title_kind($typed);
        if ($typedKind === '' || $typedKind === $mediaType) {
            return $typed;
        }
    }
    $legacy = cache_read_title($tmdbId);
    if (!is_array($legacy)) {
        return null;
    }
    $kind = cache_title_kind($legacy);
    if ($kind === '' || $kind === $mediaType) {
        return $legacy;
    }
    return null;
}

function cache_title_conflicts(?array $existing, array $incoming): bool
{
    if (!is_array($existing)) {
        return false;
    }
    $oldKind = cache_title_kind($existing);
    $newKind = cache_title_kind($incoming);
    if ($oldKind !== '' && $newKind !== '' && $oldKind !== $newKind) {
        return true;
    }
    $oldTitle = lower(trim((string) ($existing['title'] ?? '')));
    $newTitle = lower(trim((string) ($incoming['title'] ?? '')));
    if ($oldTitle !== '' && $newTitle !== '' && $oldTitle !== $newTitle) {
        return true;
    }
    $oldYear = isset($existing['year']) && is_numeric($existing['year']) ? (int) $existing['year'] : 0;
    $newYear = isset($incoming['year']) && is_numeric($incoming['year']) ? (int) $incoming['year'] : 0;
    return $oldYear > 0 && $newYear > 0 && $oldYear !== $newYear;
}

function cache_title_blocked(int $tmdbId, string $mediaType): bool
{
    if ($tmdbId < 1) {
        return false;
    }
    $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
    if (is_array(cache_read_title_for($tmdbId, $mediaType))) {
        return false;
    }
    $legacy = cache_read_title($tmdbId);
    $kind = cache_title_kind($legacy);
    return $kind !== '' && $kind !== $mediaType;
}

function cache_write_title_for(int $tmdbId, array $title, string $mediaType): bool
{
    if ($tmdbId < 1) {
        return false;
    }
    $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
    $title['tmdb_id'] = $tmdbId;
    $title['media_type'] = $mediaType;
    $legacy = cache_read_title($tmdbId);
    if (is_array($legacy) && cache_title_conflicts($legacy, $title)) {
        return cache_write_atomic(cache_title_typed_path($tmdbId, $mediaType), $title);
    }
    return cache_write_title($tmdbId, $title);
}

function cache_title_media_for_match(array $item, ?array $source = null): string
{
    if (library_item_kind($item) === 'show') {
        return 'tv';
    }
    if (is_array($source) && library_item_kind($source) === 'show') {
        return 'tv';
    }
    $hint = is_array($source) ? (string) ($source['media_type'] ?? '') : '';
    if ($hint !== 'tv' && $hint !== 'movie') {
        $hint = (string) ($item['media_type'] ?? '');
    }
    return $hint === 'tv' || $hint === 'movie' ? $hint : 'movie';
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
        if (preg_match('/^(?:tv|movie)-(\d+)\.json$/', $name, $m) !== 1
            && preg_match('/^(\d+)\.json$/', $name, $m) !== 1) {
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
    if ($source === 'direct' || $source === 'grok' || $source === 'manual' || $source === 'rifftrax' || $source === 'none') {
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
        'rifftrax' => 'RiffTrax',
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

/** @param list<string> $ids */
function cache_update_items(array $ids, callable $mutator): int
{
    $n = 0;
    $seen = [];
    foreach ($ids as $id) {
        $id = (string) $id;
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        if (cache_update_item($id, $mutator) !== null) {
            $n++;
        }
    }
    return $n;
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
        unset(
            $item['vote_average'],
            $item['vote_count'],
            $item['runtime'],
            $item['lookup_source'],
            $item['lookup_id'],
            $item['detail_url'],
            $item['original_year'],
            $item['lookup_candidates']
        );
        $item['year'] = library_filename_year($item);
        return $item;
    }
    $previousId = (int) ($item['tmdb_id'] ?? 0);
    $item['match_source'] = library_normalize_match_source($source);
    if ($item['match_source'] === 'none') {
        $item['match_source'] = 'direct';
    }
    $item['tmdb_id'] = (int) $meta['tmdb_id'];
    $item['status'] = 'matched';
    $item['poster_path'] = $meta['poster_path'] ?? null;
    if (!empty($meta['poster_path'])) {
        unset($item['poster_cleared']);
    }
    $item = library_set_auto_title($item, (string) ($meta['title'] ?? $item['title'] ?? 'Untitled'));
    if (!empty($meta['year'])) {
        $item['year'] = (int) $meta['year'];
        unset($item['year_source']);
    }
    $item['genres'] = cache_string_list($meta['genres'] ?? []);
    $item['overview'] = trim((string) ($meta['overview'] ?? ''));
    if (function_exists('tmdb_has_rating') && tmdb_has_rating($meta)) {
        $item = tmdb_copy_rating($item, $meta);
    } elseif ($previousId !== (int) $meta['tmdb_id']) {
        unset($item['vote_average'], $item['vote_count']);
    }
    if (function_exists('tmdb_copy_runtime')) {
        $item = tmdb_copy_runtime($item, $meta);
    }
    if ($previousId !== (int) $meta['tmdb_id']
        && (!function_exists('tmdb_minutes_value') || tmdb_minutes_value($meta['runtime'] ?? null) === null)) {
        unset($item['runtime']);
    }
    if (!empty($meta['media_type']) && is_string($meta['media_type'])) {
        $item['media_type'] = $meta['media_type'];
    }
    unset(
        $item['lookup_source'],
        $item['lookup_id'],
        $item['detail_url'],
        $item['original_year'],
        $item['lookup_candidates']
    );
    if (!library_has_user_kind($item)) {
        $item['kind'] = library_kind_from_genres($item['genres'], library_item_kind($item));
    }
    if (library_item_kind($item) === 'show') {
        $item['grouped'] = true;
        if (function_exists('tmdb_enrich_item_episode') && !empty($item['tmdb_id'])) {
            $item = tmdb_enrich_item_episode($item, (int) $item['tmdb_id']);
        }
    }
    return $item;
}

function library_filename_year(array $item): ?int
{
    if (!function_exists('scan_parse_path')) {
        require_once __DIR__ . '/folders.php';
    }
    $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
    if ($path === '') {
        $path = (string) ($item['filename'] ?? '');
    }
    if ($path === '' || !function_exists('scan_parse_path')) {
        return null;
    }
    $parsed = scan_parse_path(
        (string) ($item['root'] ?? ''),
        $path,
        (string) ($item['category'] ?? '')
    );
    $year = $parsed['year'] ?? null;
    if (!is_numeric($year)) {
        return null;
    }
    $year = (int) $year;
    return $year >= 1870 && $year <= 2100 ? $year : null;
}

/** @return list<string> */
function library_posted_genres(mixed $raw): array
{
    $genres = [];
    $seen = [];
    if (!is_array($raw)) {
        return $genres;
    }
    foreach ($raw as $genre) {
        if (!is_string($genre)) {
            continue;
        }
        $genre = trim($genre);
        if ($genre === '' || strlen($genre) > 48) {
            continue;
        }
        $key = lower($genre);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $genres[] = $genre;
    }
    return $genres;
}

/** @return array{year: ?int, error: string} */
function library_posted_year(mixed $raw): array
{
    $text = trim((string) $raw);
    if ($text === '') {
        return ['year' => null, 'error' => ''];
    }
    if (!preg_match('/^\d{4}$/', $text)) {
        return ['year' => null, 'error' => 'Enter a year from 1870 to 2100, or leave Year blank.'];
    }
    $year = (int) $text;
    if ($year < 1870 || $year > 2100) {
        return ['year' => null, 'error' => 'Enter a year from 1870 to 2100, or leave Year blank.'];
    }
    return ['year' => $year, 'error' => ''];
}

/** @return list<string> */
function library_known_genres(array $library): array
{
    $seen = [];
    $out = [];
    $items = $library['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (cache_string_list($item['genres'] ?? []) as $genre) {
            $genre = trim($genre);
            if ($genre === '' || strlen($genre) > 48) {
                continue;
            }
            $key = lower($genre);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $genre;
        }
    }
    usort($out, static function (string $a, string $b): int {
        return strcasecmp($a, $b);
    });
    return $out;
}

function library_delete_poster_owner(string $ownerId): void
{
    if (!preg_match('/^[a-f0-9]{16}$/', $ownerId)) {
        return;
    }
    $dir = CACHE_DIR . '/posters';
    foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
        $path = $dir . '/' . $ownerId . '.' . $ext;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @param array<string, mixed> $file
 * @return array{ok: bool, error: string, apply: bool, poster_path: ?string}
 */
function library_store_poster_upload(string $ownerId, array $file): array
{
    $fail = static function (string $error): array {
        return ['ok' => false, 'error' => $error, 'apply' => false, 'poster_path' => null];
    };
    if (!preg_match('/^[a-f0-9]{16}$/', $ownerId)) {
        return $fail('Could not store the poster.');
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return $fail('That image is too large to upload.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $fail('Could not read that image.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1) {
        return $fail('Could not read that image.');
    }
    if ($size > 8 * 1024 * 1024) {
        return $fail('That image is too large to upload. The limit is 8 MB.');
    }
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        default => '',
    };
    if ($ext === '') {
        return $fail('Choose a JPEG, PNG, WebP, or GIF image.');
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
        return $fail('That image is too large to use as a poster.');
    }
    $dir = CACHE_DIR . '/posters';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $fail('Could not store the poster. Check that cache/ is writable.');
    }
    $dest = $dir . '/' . $ownerId . '.' . $ext;
    if (!@move_uploaded_file($tmp, $dest)) {
        return $fail('Could not store the poster. Check that cache/ is writable.');
    }
    foreach (['jpg', 'png', 'webp', 'gif'] as $other) {
        if ($other === $ext) {
            continue;
        }
        $old = $dir . '/' . $ownerId . '.' . $other;
        if (is_file($old)) {
            @unlink($old);
        }
    }
    return ['ok' => true, 'error' => '', 'apply' => true, 'poster_path' => 'local:' . $ownerId . '.' . $ext];
}

/** @return array{ok: bool, error: string, apply: bool, poster_path: ?string} */
function library_take_poster_upload(string $ownerId, bool $remove): array
{
    $file = $_FILES['poster'] ?? null;
    $hasFile = false;
    if (is_array($file)) {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && $size > 0) || ($err !== UPLOAD_ERR_NO_FILE && $err !== UPLOAD_ERR_OK)) {
            $hasFile = true;
        }
    }
    if ($hasFile && is_array($file)) {
        return library_store_poster_upload($ownerId, $file);
    }
    if (!$remove) {
        return ['ok' => true, 'error' => '', 'apply' => false, 'poster_path' => null];
    }
    if (!preg_match('/^[a-f0-9]{16}$/', $ownerId)) {
        return ['ok' => false, 'error' => 'Could not remove the poster.', 'apply' => false, 'poster_path' => null];
    }
    return ['ok' => true, 'error' => '', 'apply' => true, 'poster_path' => null];
}

function library_clear_owned_poster(array $item): array
{
    $poster = isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : '';
    $id = (string) ($item['id'] ?? '');
    if ($poster !== '' && function_exists('poster_is_local') && poster_is_local($poster) && str_starts_with(substr($poster, 6), $id . '.')) {
        library_delete_poster_owner($id);
    }
    $item['poster_path'] = null;
    $item['poster_source'] = 'file';
    return $item;
}

function library_apply_edit_media(array $row, ?int $year, bool $applyPoster, ?string $posterPath): array
{
    $row['year'] = $year;
    $row['year_source'] = 'user';
    if (!$applyPoster) {
        return $row;
    }
    if ($posterPath === null || $posterPath === '') {
        $row = library_clear_owned_poster($row);
        $row['poster_cleared'] = true;
        return $row;
    }
    $rowId = (string) ($row['id'] ?? '');
    $old = isset($row['poster_path']) && is_string($row['poster_path']) ? $row['poster_path'] : '';
    if ($old !== '' && $old !== $posterPath && function_exists('poster_is_local') && poster_is_local($old) && str_starts_with(substr($old, 6), $rowId . '.')) {
        $disk = poster_local_disk($old);
        if ($disk !== null) {
            @unlink($disk);
        }
    }
    $row['poster_path'] = $posterPath;
    unset($row['poster_cleared'], $row['poster_source']);
    return $row;
}

function library_clear_match(array $item): array
{
    $item['tmdb_id'] = null;
    $item['status'] = 'unidentified';
    $item['match_source'] = 'none';
    $item = library_clear_owned_poster($item);
    $item['tmdb_candidates'] = [];
    unset(
        $item['overview'],
        $item['vote_average'],
        $item['vote_count'],
        $item['runtime'],
        $item['poster_cleared'],
        $item['lookup_source'],
        $item['lookup_id'],
        $item['detail_url'],
        $item['original_year'],
        $item['lookup_candidates']
    );
    $item['genres'] = [];
    unset($item['media_type']);
    $item['year'] = library_filename_year($item);
    $item['year_source'] = 'file';
    if (!library_has_custom_title($item)) {
        $item['display_title'] = (string) ($item['title'] ?? 'Untitled');
        unset($item['title_source']);
    }
    return $item;
}

/**
 * An item cleared before the year was restored stays unidentified, so Clear Match
 * is no longer offered. Opening it again writes the filename year when it differs.
 */
function library_heal_filename_year(string $id, array $item): array
{
    if ($id === '' || ($item['year_source'] ?? '') === 'user' || !empty($item['tmdb_id']) || library_item_status($item) === 'matched') {
        return $item;
    }
    $parsed = library_filename_year($item);
    $stored = isset($item['year']) && is_numeric($item['year']) ? (int) $item['year'] : null;
    if ($stored === $parsed) {
        return $item;
    }
    $updated = cache_update_item($id, static function (array $row): ?array {
        if (($row['year_source'] ?? '') === 'user' || !empty($row['tmdb_id']) || library_item_status($row) === 'matched') {
            return null;
        }
        $fresh = library_filename_year($row);
        $current = isset($row['year']) && is_numeric($row['year']) ? (int) $row['year'] : null;
        if ($current === $fresh) {
            return null;
        }
        $row['year'] = $fresh;
        return $row;
    });
    if (is_array($updated)) {
        return $updated;
    }
    $item['year'] = $parsed;
    return $item;
}

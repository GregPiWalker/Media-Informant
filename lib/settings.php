<?php
declare(strict_types=1);

require_once __DIR__ . '/cache.php';

function settings_path(): string
{
    return CACHE_DIR . '/settings.json';
}

function settings_languages(): array
{
    return [
        'en-US' => 'English (US)',
        'en-GB' => 'English (UK)',
        'de-DE' => 'German',
        'fr-FR' => 'French',
        'es-ES' => 'Spanish',
        'it-IT' => 'Italian',
        'ja-JP' => 'Japanese',
        'ko-KR' => 'Korean',
        'nl-NL' => 'Dutch',
        'pl-PL' => 'Polish',
        'pt-BR' => 'Portuguese (Brazil)',
        'sv-SE' => 'Swedish',
        'zh-CN' => 'Chinese (Simplified)',
    ];
}

function settings_default_categories(): array
{
    return [
        ['id' => 'movies', 'label' => 'Movies'],
        ['id' => 'tv-shows', 'label' => 'TV Shows'],
        ['id' => 'documentaries', 'label' => 'Documentaries'],
        ['id' => 'music-videos', 'label' => 'Music Videos'],
        ['id' => 'interviews', 'label' => 'Interviews'],
        ['id' => 'fitness', 'label' => 'Fitness'],
        ['id' => 'home-videos', 'label' => 'Home Videos'],
    ];
}

function settings_defaults(): array
{
    return [
        'version' => 1,
        'video_categories' => settings_default_categories(),
        'music_categories' => [],
        'video_roots' => [['path' => VIDEO_ROOT, 'category' => 'movies']],
        'music_roots' => [['path' => MUSIC_ROOT, 'category' => '']],
        'video_excludes' => [],
        'music_excludes' => [],
        'tmdb_api_key' => TMDB_API_KEY,
        'tmdb_language' => TMDB_LANGUAGE,
        'xai_api_key' => XAI_API_KEY,
        'show_hidden' => false,
    ];
}

function settings_normalize_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if (preg_match('#^[A-Za-z]:/$#', $path)) {
        return $path;
    }
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    return $path;
}

function settings_path_blocked(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return true;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
            return true;
        }
    }
    $app = rtrim(str_replace('\\', '/', APP_ROOT), '/');
    return $path === $app || str_starts_with($path, $app . '/');
}

function settings_path_exists_exact(string $path): bool
{
    $path = settings_normalize_path($path);
    if ($path === '' || !file_exists($path)) {
        return false;
    }
    if ($path === '/') {
        return true;
    }

    $parts = explode('/', $path);
    $acc = '';
    foreach ($parts as $i => $part) {
        if ($part === '') {
            if ($i === 0) {
                $acc = '';
            }
            continue;
        }
        if ($i === 0 && preg_match('/^[A-Za-z]:$/', $part)) {
            $acc = $part;
            continue;
        }
        $parent = $acc === '' ? '/' : $acc;
        $names = @scandir($parent);
        if ($names === false) {
            return file_exists($path);
        }
        if (!in_array($part, $names, true)) {
            return false;
        }
        $acc = $parent === '/' ? '/' . $part : $parent . '/' . $part;
    }

    return $acc === $path;
}

function settings_clean_roots(array $roots): array
{
    return settings_source_paths(settings_clean_sources($roots, [], []));
}

function settings_slug(string $label): string
{
    $slug = lower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'category';
}

function settings_migrate_category_id(string $id): string
{
    return $id === 'television' ? 'tv-shows' : $id;
}

function settings_clean_categories(array $ids, array $labels, bool $useDefaults = false): array
{
    $out = [];
    $seen = [];
    $count = max(count($ids), count($labels));
    for ($i = 0; $i < $count; $i++) {
        $label = trim((string) ($labels[$i] ?? ''));
        if ($label === '') {
            continue;
        }
        if ($label === 'Television') {
            $label = 'TV Shows';
        }
        $id = settings_migrate_category_id(trim((string) ($ids[$i] ?? '')));
        if ($id === '' || !preg_match('/^[a-z0-9-]+$/', $id)) {
            $id = settings_slug($label);
        }
        $base = $id;
        $n = 2;
        while (isset($seen[$id])) {
            $id = $base . '-' . $n;
            $n++;
        }
        $seen[$id] = true;
        $out[] = ['id' => $id, 'label' => $label];
    }
    if ($out === [] && $useDefaults) {
        return settings_default_categories();
    }
    return $out;
}

function settings_read_category_list(array $data, string $key, string $idKey, string $labelKey, bool $useDefaults): array
{
    $ids = $data[$idKey] ?? [];
    $labels = $data[$labelKey] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    if (!is_array($labels)) {
        $labels = [];
    }
    if (isset($data[$key]) && is_array($data[$key]) && $labels === []) {
        foreach ($data[$key] as $row) {
            if (is_array($row)) {
                $ids[] = (string) ($row['id'] ?? '');
                $labels[] = (string) ($row['label'] ?? '');
            } elseif (is_string($row)) {
                $ids[] = settings_slug($row);
                $labels[] = $row;
            }
        }
    }
    return settings_clean_categories($ids, $labels, $useDefaults);
}

function settings_clean_sources(array $roots, array $categoryIds, array $validIds): array
{
    $valid = array_fill_keys($validIds, true);
    $out = [];
    $seen = [];
    foreach ($roots as $i => $root) {
        $path = '';
        $category = '';
        if (is_array($root)) {
            $path = (string) ($root['path'] ?? '');
            $category = (string) ($root['category'] ?? '');
        } elseif (is_string($root)) {
            $path = $root;
            $category = (string) ($categoryIds[$i] ?? '');
        }
        $path = settings_normalize_path($path);
        if ($path === '' || settings_path_blocked($path) || isset($seen[$path])) {
            continue;
        }
        $category = settings_migrate_category_id($category);
        if ($category !== '' && !isset($valid[$category])) {
            $category = '';
        }
        $seen[$path] = true;
        $out[] = ['path' => $path, 'category' => $category];
    }
    return $out;
}

function settings_source_paths(array $sources): array
{
    $paths = [];
    foreach ($sources as $source) {
        if (is_string($source) && $source !== '') {
            $paths[] = settings_normalize_path($source);
            continue;
        }
        if (is_array($source) && !empty($source['path']) && is_string($source['path'])) {
            $paths[] = settings_normalize_path($source['path']);
        }
    }
    return $paths;
}

function settings_normalize(array $data): array
{
    $defaults = settings_defaults();
    $languages = settings_languages();
    $lang = (string) ($data['tmdb_language'] ?? $defaults['tmdb_language']);
    if (!isset($languages[$lang])) {
        $lang = $defaults['tmdb_language'];
    }

    $videoCategoryData = $data;
    if (empty($data['video_categories']) && empty($data['video_category_labels']) && isset($data['categories'])) {
        $videoCategoryData['video_categories'] = $data['categories'];
    }
    $videoCategories = settings_read_category_list(
        $videoCategoryData,
        'video_categories',
        'video_category_ids',
        'video_category_labels',
        !isset($data['video_categories']) && !isset($data['video_category_labels']) && !isset($data['categories'])
    );
    $musicCategories = settings_read_category_list(
        $data,
        'music_categories',
        'music_category_ids',
        'music_category_labels',
        false
    );
    $videoValidIds = [];
    foreach ($videoCategories as $category) {
        $videoValidIds[] = $category['id'];
    }
    $musicValidIds = [];
    foreach ($musicCategories as $category) {
        $musicValidIds[] = $category['id'];
    }

    $video = $data['video_roots'] ?? $defaults['video_roots'];
    $music = $data['music_roots'] ?? $defaults['music_roots'];
    $videoCats = $data['video_root_categories'] ?? [];
    $musicCats = $data['music_root_categories'] ?? [];
    $videoEx = $data['video_excludes'] ?? $defaults['video_excludes'];
    $musicEx = $data['music_excludes'] ?? $defaults['music_excludes'];
    if (!is_array($video)) {
        $video = $defaults['video_roots'];
    }
    if (!is_array($music)) {
        $music = $defaults['music_roots'];
    }
    if (!is_array($videoCats)) {
        $videoCats = [];
    }
    if (!is_array($musicCats)) {
        $musicCats = [];
    }
    if (!is_array($videoEx)) {
        $videoEx = [];
    }
    if (!is_array($musicEx)) {
        $musicEx = [];
    }

    return [
        'version' => 1,
        'video_categories' => $videoCategories,
        'music_categories' => $musicCategories,
        'video_roots' => settings_clean_sources($video, $videoCats, $videoValidIds),
        'music_roots' => settings_clean_sources($music, $musicCats, $musicValidIds),
        'video_excludes' => settings_clean_roots($videoEx),
        'music_excludes' => settings_clean_roots($musicEx),
        'tmdb_api_key' => trim((string) ($data['tmdb_api_key'] ?? $defaults['tmdb_api_key'])),
        'tmdb_language' => $lang,
        'xai_api_key' => trim((string) ($data['xai_api_key'] ?? $defaults['xai_api_key'])),
        'show_hidden' => settings_bool($data['show_hidden'] ?? $defaults['show_hidden']),
    ];
}

function settings_bool(mixed $value): bool
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

function settings_show_hidden(): bool
{
    return settings_bool(settings_get()['show_hidden'] ?? false);
}

function settings_get(bool $reload = false): array
{
    static $cached = null;
    if ($reload) {
        $cached = null;
    }
    if (is_array($cached)) {
        return $cached;
    }

    cache_init();
    $data = cache_read_json(settings_path());
    $cached = settings_normalize($data ?? settings_defaults());
    return $cached;
}

function settings_save(array $data): bool
{
    cache_init();
    $normalized = settings_normalize($data);
    if (!cache_write_atomic(settings_path(), $normalized, true)) {
        return false;
    }
    settings_get(true);
    return true;
}

function settings_video_roots(): array
{
    $paths = settings_source_paths(settings_get()['video_roots']);
    if ($paths !== []) {
        return $paths;
    }
    if (!is_file(settings_path())) {
        return settings_source_paths([['path' => VIDEO_ROOT, 'category' => 'movies']]);
    }
    return [];
}

function settings_music_roots(): array
{
    return settings_source_paths(settings_get()['music_roots']);
}

function settings_video_categories(): array
{
    $cats = settings_get()['video_categories'] ?? [];
    return is_array($cats) && $cats !== [] ? $cats : settings_default_categories();
}

function settings_video_filter_categories(): array
{
    $usedIds = [];
    $hasNone = false;
    $sources = settings_get()['video_roots'] ?? [];
    if (!is_array($sources) || $sources === []) {
        $hasNone = true;
    } else {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                $hasNone = true;
                continue;
            }
            $id = (string) ($source['category'] ?? '');
            if ($id === '') {
                $hasNone = true;
            } else {
                $usedIds[$id] = true;
            }
        }
    }
    $kindCats = ['movies' => true, 'tv-shows' => true, 'documentaries' => true, 'television' => true, 'docs' => true];
    $categories = [];
    foreach (settings_video_categories() as $category) {
        $id = (string) ($category['id'] ?? '');
        if ($id !== '' && isset($usedIds[$id]) && !isset($kindCats[$id])) {
            $categories[] = $category;
        }
    }
    return [
        'categories' => $categories,
        'has_none' => $hasNone,
    ];
}

function settings_music_categories(): array
{
    $cats = settings_get()['music_categories'] ?? [];
    return is_array($cats) ? $cats : [];
}

function settings_categories(): array
{
    return settings_video_categories();
}

function settings_category_ids(?array $categories = null): array
{
    $ids = [];
    foreach ($categories ?? settings_video_categories() as $category) {
        if (!empty($category['id'])) {
            $ids[] = (string) $category['id'];
        }
    }
    return $ids;
}

function settings_category_for_root(string $root): string
{
    $root = settings_normalize_path($root);
    if ($root === '') {
        return '';
    }
    foreach (['video_roots', 'music_roots'] as $key) {
        $sources = settings_get()[$key] ?? [];
        if (!is_array($sources)) {
            continue;
        }
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if (settings_normalize_path((string) ($source['path'] ?? '')) === $root) {
                return (string) ($source['category'] ?? '');
            }
        }
    }
    return '';
}

function settings_content_kind_from_category(string $categoryId): string
{
    $id = settings_migrate_category_id(strtolower(trim($categoryId)));
    if ($id === 'tv-shows' || $id === 'television' || $id === 'tv' || $id === 'shows') {
        return 'show';
    }
    if ($id === 'documentaries' || $id === 'documentary' || $id === 'docs') {
        return 'documentary';
    }
    if ($id === 'movies' || $id === 'movie' || $id === 'films') {
        return 'movie';
    }
    return '';
}

function settings_video_excludes(): array
{
    return settings_get()['video_excludes'] ?? [];
}

function settings_music_excludes(): array
{
    return settings_get()['music_excludes'] ?? [];
}

function settings_path_is_excluded(string $fullPath, array $excludes): bool
{
    $full = settings_normalize_path($fullPath);
    if ($full === '' || $excludes === []) {
        return false;
    }
    foreach ($excludes as $exclude) {
        if (!is_string($exclude) || $exclude === '') {
            continue;
        }
        $ex = settings_normalize_path($exclude);
        if ($ex === '') {
            continue;
        }
        if ($full === $ex || str_starts_with($full, $ex . '/')) {
            return true;
        }
    }
    return false;
}

function settings_item_excluded(array $item, ?array $excludes = null, array $library = []): bool
{
    $excludes ??= settings_video_excludes();
    return settings_path_is_excluded(format_source_path($item), $excludes);
}

function settings_tmdb_key(): string
{
    $key = trim((string) (settings_get()['tmdb_api_key'] ?? ''));
    return $key !== '' ? $key : TMDB_API_KEY;
}

function settings_tmdb_language(): string
{
    return (string) (settings_get()['tmdb_language'] ?? TMDB_LANGUAGE);
}

function settings_xai_key(): string
{
    $key = trim((string) (settings_get()['xai_api_key'] ?? ''));
    return $key !== '' ? $key : XAI_API_KEY;
}

function settings_path_status(string $path): string
{
    if (settings_path_blocked($path)) {
        return 'blocked';
    }
    if (!file_exists($path)) {
        return 'missing';
    }
    if (!is_dir($path)) {
        return 'not-dir';
    }
    if (!is_readable($path)) {
        return 'unreadable';
    }
    return 'ok';
}

function settings_path_status_label(string $status): string
{
    return match ($status) {
        'ok' => 'Readable',
        'missing' => 'Not found (paths are case-sensitive)',
        'case' => 'Wrong capitalization',
        'not-dir' => 'Not a folder',
        'unreadable' => 'Not readable by http',
        'blocked' => 'Not allowed',
        default => 'Unknown',
    };
}

function settings_item_in_roots(array $item, array $roots, array $library = []): bool
{
    if ($roots === []) {
        return false;
    }
    $allowed = array_fill_keys($roots, true);
    $root = settings_item_root($item, $library);
    if ($root === '' || !isset($allowed[$root])) {
        return false;
    }
    return !settings_item_excluded($item, settings_video_excludes(), $library);
}

function settings_item_root(array $item, array $library = []): string
{
    $root = settings_normalize_path((string) ($item['root'] ?? ''));
    if ($root !== '') {
        return $root;
    }
    if (!empty($library['video_roots']) && is_array($library['video_roots'])) {
        $legacy = settings_clean_roots($library['video_roots']);
        if (isset($legacy[0])) {
            return $legacy[0];
        }
    }
    if (!empty($library['video_root']) && is_string($library['video_root'])) {
        return settings_normalize_path($library['video_root']);
    }
    return '';
}

function format_source_path(array $item): string
{
    $rel = str_replace('\\', '/', (string) ($item['path'] ?? ''));
    $root = rtrim(str_replace('\\', '/', (string) ($item['root'] ?? '')), '/');
    if ($root === '') {
        return $rel;
    }
    return $rel === '' ? $root : $root . '/' . $rel;
}

function settings_parent_dir(string $fullPath): string
{
    $full = settings_normalize_path($fullPath);
    if ($full === '' || $full === '/') {
        return '';
    }
    $pos = strrpos($full, '/');
    if ($pos === false) {
        return '';
    }
    if ($pos === 0) {
        return '/';
    }
    return settings_normalize_path(substr($full, 0, $pos));
}

function settings_item_parent_dir(array $item): string
{
    return settings_parent_dir(format_source_path($item));
}

function settings_is_source_path(string $path): bool
{
    $path = settings_normalize_path($path);
    if ($path === '') {
        return false;
    }
    foreach (['video_roots', 'music_roots'] as $key) {
        foreach (settings_source_paths(settings_get()[$key] ?? []) as $root) {
            if (settings_normalize_path((string) $root) === $path) {
                return true;
            }
        }
    }
    return false;
}

function settings_add_video_exclude(string $path): ?string
{
    $path = settings_normalize_path($path);
    if ($path === '' || settings_path_blocked($path)) {
        return 'That folder cannot be excluded.';
    }
    if (settings_is_source_path($path)) {
        return 'The parent folder is a configured source. Only folders inside a source can be excluded.';
    }
    $current = settings_get();
    $excludes = is_array($current['video_excludes'] ?? null) ? $current['video_excludes'] : [];
    foreach ($excludes as $exclude) {
        if (settings_normalize_path((string) $exclude) === $path) {
            return null;
        }
    }
    $excludes[] = $path;
    $current['video_excludes'] = $excludes;
    if (!settings_save($current)) {
        return 'Could not save the exclusion. Check that cache/ is writable.';
    }
    return null;
}

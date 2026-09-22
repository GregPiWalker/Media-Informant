<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sources.php';

function tools_video_extensions(): array
{
    return defined('VIDEO_EXTENSIONS')
        ? VIDEO_EXTENSIONS
        : ['mkv', 'mp4', 'avi', 'm4v', 'mov', 'wmv', 'ts', 'm2ts'];
}

function tools_audio_extensions(): array
{
    return defined('AUDIO_EXTENSIONS')
        ? AUDIO_EXTENSIONS
        : ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'wma', 'wav', 'aiff'];
}

function tools_is_video_ext(string $ext): bool
{
    return in_array(strtolower($ext), tools_video_extensions(), true);
}

function tools_is_media_ext(string $ext): bool
{
    $ext = strtolower($ext);
    return tools_is_video_ext($ext) || in_array($ext, tools_audio_extensions(), true);
}

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

function tools_dir_flags(string $abs, array $excludes): array
{
    $hasFolders = false;
    $hasMedia = false;
    $names = @scandir($abs);
    if (!is_array($names)) {
        return ['has_folders' => false, 'has_media' => false];
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
            $hasFolders = true;
            if ($hasMedia) {
                break;
            }
            continue;
        }
        if (!is_file($child)) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (tools_is_media_ext($ext)) {
            $hasMedia = true;
            if ($hasFolders) {
                break;
            }
        }
    }
    return ['has_folders' => $hasFolders, 'has_media' => $hasMedia];
}

function tools_list_dir(string $abs, array $excludes): array
{
    $folders = [];
    $files = [];
    $exts = [];
    $hasMedia = false;
    $names = @scandir($abs);
    if (!is_array($names)) {
        return [
            'folders' => [],
            'files' => [],
            'extensions' => [],
            'has_folders' => false,
            'has_media' => false,
        ];
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
            $flags = tools_dir_flags($child, $excludes);
            $folders[] = [
                'name' => $name,
                'has_children' => tools_dir_has_children($child, $excludes),
                'has_folders' => $flags['has_folders'],
                'has_media' => $flags['has_media'],
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
        if (tools_is_media_ext($ext)) {
            $hasMedia = true;
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
        'has_folders' => $folders !== [],
        'has_media' => $hasMedia,
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

function tools_smart_rename_max_groups(): int
{
    return 80;
}

/**
 * @return list<array{dir: string, label: string, path_label: string, samples: list<string>, extensions: list<string>}>
 */
function tools_collect_leaf_groups(string $abs, string $rel, array $excludes, int $depth = 0): array
{
    if ($depth > 16) {
        return [];
    }
    $list = tools_list_dir($abs, $excludes);
    $groups = [];
    $samplesByExt = [];
    foreach ($list['files'] as $file) {
        $ext = (string) ($file['ext'] ?? '');
        if (!tools_is_video_ext($ext) || isset($samplesByExt[$ext])) {
            continue;
        }
        $samplesByExt[$ext] = (string) ($file['name'] ?? '');
    }
    if ($samplesByExt !== []) {
        $label = $rel === '' ? basename($abs) : basename($rel);
        $groups[] = [
            'dir' => $rel,
            'label' => $label !== '' ? $label : basename($abs),
            'path_label' => $rel !== '' ? $rel : ($label !== '' ? $label : basename($abs)),
            'samples' => array_values($samplesByExt),
            'extensions' => array_keys($samplesByExt),
        ];
    }
    $budget = tools_smart_rename_max_groups();
    foreach ($list['folders'] as $folder) {
        if (count($groups) >= $budget) {
            break;
        }
        $name = (string) ($folder['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $childRel = $rel === '' ? $name : $rel . '/' . $name;
        $childAbs = $abs . '/' . $name;
        $childGroups = tools_collect_leaf_groups($childAbs, $childRel, $excludes, $depth + 1);
        foreach ($childGroups as $group) {
            $groups[] = $group;
            if (count($groups) >= $budget) {
                break 2;
            }
        }
    }
    return $groups;
}

function tools_smart_rename_system_prompt(): string
{
    return "You plan filename cleanups for TV episode video files that contain too much information.\n"
        . "Each group is one folder of episodes. Samples are one filename per video extension in that folder.\n"
        . "We will delete a literal substring (the rename-group \"find\" text) from every matching filename. Replacement is always empty.\n"
        . "Keep only the episode number (digits such as 01 or 10, or written words such as One) and the episode title when present, plus the extension.\n"
        . "find must appear in every sample for that group. Do not put the episode number or episode title inside find.\n"
        . "If the samples are already clean, return an empty find.\n"
        . "Reply with JSON only:\n"
        . '[{"id":"g0","find":"Show Name_S01E"}]';
}

function tools_smart_preview_name(string $sample, string $find): string
{
    if ($find === '' || !str_contains($sample, $find)) {
        return $sample;
    }
    $out = str_replace($find, '', $sample);
    return $out !== '' ? $out : $sample;
}

/**
 * @param list<array{dir: string, label: string, path_label: string, samples: list<string>, extensions: list<string>}> $groups
 * @return array{ok: bool, error?: string, plans?: list<array<string, mixed>>}
 */
function tools_smart_rename_plan(array $groups): array
{
    if ($groups === []) {
        return ['ok' => false, 'error' => 'No video episode folders were found under that folder.'];
    }
    if (!function_exists('grok_has_key') || !grok_has_key()) {
        return ['ok' => false, 'error' => 'Set an xAI Grok key in Config to use Smart Rename.'];
    }
    $payload = [];
    foreach ($groups as $i => $group) {
        $id = 'g' . $i;
        $groups[$i]['id'] = $id;
        $payload[] = [
            'id' => $id,
            'folder' => (string) ($group['path_label'] ?? $group['label'] ?? ''),
            'samples' => $group['samples'],
        ];
    }
    $user = "Groups:\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $messages = [
        ['role' => 'system', 'content' => tools_smart_rename_system_prompt()],
        ['role' => 'user', 'content' => $user],
    ];
    $chat = grok_chat_with_fallback($messages, 4000);
    if (empty($chat['ok'])) {
        return ['ok' => false, 'error' => (string) ($chat['error'] ?? 'Grok request failed.')];
    }
    $content = trim((string) ($chat['content'] ?? ''));
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);
    }
    $rows = json_decode($content, true);
    if (!is_array($rows) && preg_match('/\[[\s\S]*\]/', $content, $match)) {
        $rows = json_decode($match[0], true);
    }
    if (is_array($rows) && isset($rows['id'])) {
        $rows = [$rows];
    }
    if (is_array($rows) && isset($rows['results']) && is_array($rows['results'])) {
        $rows = $rows['results'];
    }
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'Grok did not return a usable plan.'];
    }
    $findById = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $findById[$id] = (string) ($row['find'] ?? '');
    }
    $plans = [];
    foreach ($groups as $group) {
        $id = (string) ($group['id'] ?? '');
        $samples = $group['samples'];
        $find = $findById[$id] ?? '';
        if ($find !== '') {
            foreach ($samples as $sample) {
                if (!str_contains((string) $sample, $find)) {
                    $find = '';
                    break;
                }
            }
        }
        $sample = (string) ($samples[0] ?? '');
        $preview = tools_smart_preview_name($sample, $find);
        $plans[] = [
            'id' => $id,
            'dir' => (string) ($group['dir'] ?? ''),
            'label' => (string) ($group['path_label'] ?? $group['label'] ?? ''),
            'find' => $find,
            'sample' => $sample,
            'preview' => $preview,
            'extensions' => $group['extensions'],
            'skip' => $find === '' || $preview === $sample,
        ];
    }
    if (function_exists('app_log')) {
        app_log('tools', 'Smart Rename planned ' . count($plans) . ' folder' . (count($plans) === 1 ? '' : 's') . '.', [
            'groups' => count($plans),
            'model' => (string) ($chat['model'] ?? ''),
        ]);
    }
    return ['ok' => true, 'plans' => $plans];
}

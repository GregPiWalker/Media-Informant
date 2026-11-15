<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function source_presence_live(string $path): string
{
    static $cache = [];
    $path = function_exists('settings_normalize_path') ? settings_normalize_path($path) : $path;
    if ($path === '') {
        return 'absent';
    }
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    if (function_exists('settings_path_blocked') && settings_path_blocked($path)) {
        return $cache[$path] = 'blocked';
    }
    clearstatcache(true, $path);
    if (is_dir($path) && is_readable($path)) {
        return $cache[$path] = 'present';
    }
    return $cache[$path] = 'absent';
}

function source_presence_refresh(string $catalog, array $paths): array
{
    $catalog = db_normalize_catalog($catalog);
    $pdo = db_open($catalog);
    $now = time();
    $map = [];
    $keep = [];
    foreach ($paths as $path) {
        $path = function_exists('settings_normalize_path') ? settings_normalize_path((string) $path) : (string) $path;
        if ($path === '') {
            continue;
        }
        $presence = source_presence_live($path);
        $map[$path] = $presence;
        $keep[$path] = true;
        if ($pdo === null) {
            continue;
        }
        $prev = null;
        try {
            $stmt = $pdo->prepare('SELECT presence, last_present_at, last_absent_at FROM sources WHERE path = ?');
            $stmt->execute([$path]);
            $row = $stmt->fetch();
            $prev = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            continue;
        }
        $lastPresent = $prev['last_present_at'] ?? null;
        $lastAbsent = $prev['last_absent_at'] ?? null;
        if ($presence === 'present') {
            $lastPresent = $now;
        } else {
            $lastAbsent = $now;
        }
        $up = $pdo->prepare('INSERT INTO sources (path, presence, last_checked, last_present_at, last_absent_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(path) DO UPDATE SET
                presence = excluded.presence,
                last_checked = excluded.last_checked,
                last_present_at = excluded.last_present_at,
                last_absent_at = excluded.last_absent_at');
        $up->execute([$path, $presence, $now, $lastPresent, $lastAbsent]);
    }
    if ($pdo !== null && $keep !== []) {
        try {
            $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS keep_src (path TEXT PRIMARY KEY)');
            $pdo->exec('DELETE FROM keep_src');
            $ins = $pdo->prepare('INSERT OR IGNORE INTO keep_src (path) VALUES (?)');
            foreach (array_keys($keep) as $path) {
                $ins->execute([$path]);
            }
            $pdo->exec('DELETE FROM sources WHERE path NOT IN (SELECT path FROM keep_src)');
            $pdo->exec('DROP TABLE IF EXISTS keep_src');
        } catch (Throwable $e) {
            // keep extra source rows if cleanup fails
        }
    }
    return $map;
}

function source_is_present(string $path): bool
{
    return source_presence_live($path) === 'present';
}

function source_is_absent_for_item(array $item): bool
{
    $root = function_exists('settings_item_root')
        ? settings_item_root($item)
        : (string) ($item['root'] ?? '');
    if ($root === '') {
        return false;
    }
    return source_presence_live($root) === 'absent';
}

/**
 * ready: the catalog file is on disk.
 * missing_file: its source root is present and the file is not.
 * missing_source: the source root is not present.
 */
function source_file_availability(array $item): string
{
    $full = function_exists('format_source_path') ? format_source_path($item) : '';
    if (function_exists('settings_normalize_path')) {
        $full = settings_normalize_path($full);
    }
    if ($full !== '') {
        clearstatcache(true, $full);
        if (is_file($full)) {
            return 'ready';
        }
    }
    $root = function_exists('settings_item_root')
        ? settings_item_root($item)
        : (string) ($item['root'] ?? '');
    if ($root !== '' && source_presence_live($root) === 'present') {
        return 'missing_file';
    }
    return 'missing_source';
}

function source_command_label(string $action): string
{
    return match ($action) {
        'clear_match' => 'Clear Match',
        'treat_individual' => 'Treat Individually',
        'treat_series' => 'Treat as Series',
        'folder_as_movie' => 'Treat Folder as Movies',
        'folder_as_show' => 'Treat Folder as TV Show',
        'exclude_parent' => 'Exclude Parent Dir',
        'hide' => 'Hide',
        'unhide' => 'Unhide',
        'edit', 'save_data' => 'Edit Data',
        'match' => 'Match title',
        'change_match' => 'Change match',
        default => 'This command',
    };
}

/**
 * Null when the file is on disk. Otherwise the notice to show, and the command must not run.
 * A missing file on a present source is also written to the log.
 */
function source_command_block(array $item, string $action): ?string
{
    $state = source_file_availability($item);
    if ($state === 'ready') {
        return null;
    }
    $label = source_command_label($action);
    if ($state === 'missing_file') {
        $message = $label . ' could not execute because the file is missing.';
        if (function_exists('app_log')) {
            $path = function_exists('format_source_path') ? format_source_path($item) : '';
            $filename = trim((string) ($item['filename'] ?? ''));
            if ($filename === '') {
                $filename = $path !== '' ? basename(str_replace('\\', '/', $path)) : '';
            }
            $log = $filename !== ''
                ? ($label . ' could not execute because ' . $filename . ' is missing.')
                : $message;
            app_log('title', $log, [
                'id' => (string) ($item['id'] ?? ''),
                'action' => $action,
                'path' => $path,
                'filename' => $filename,
            ], 'warn');
        }
        return $message;
    }
    return $label . ' cannot execute until the source is present.';
}

function source_presence_label(string $presence): string
{
    return match ($presence) {
        'present' => 'Present',
        'blocked' => 'Not allowed',
        default => 'Absent',
    };
}

/**
 * Present configured roots that have no catalog files yet.
 *
 * @param list<string> $roots
 * @param list<array<string, mixed>> $items
 * @return list<string>
 */
function source_roots_without_titles(array $roots, array $items): array
{
    $counts = [];
    foreach ($roots as $root) {
        $root = function_exists('settings_normalize_path')
            ? settings_normalize_path((string) $root)
            : (string) $root;
        if ($root === '') {
            continue;
        }
        if (!source_is_present($root)) {
            continue;
        }
        $counts[$root] = 0;
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $root = function_exists('settings_item_root')
            ? settings_item_root($item)
            : settings_normalize_path((string) ($item['root'] ?? ''));
        if (isset($counts[$root])) {
            $counts[$root]++;
        }
    }
    $out = [];
    foreach ($counts as $root => $n) {
        if ($n < 1) {
            $out[] = $root;
        }
    }
    return $out;
}

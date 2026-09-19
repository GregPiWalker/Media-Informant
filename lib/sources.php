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

function source_presence_label(string $presence): string
{
    return match ($presence) {
        'present' => 'Present',
        'blocked' => 'Not allowed',
        default => 'Absent',
    };
}

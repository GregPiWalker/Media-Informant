<?php
declare(strict_types=1);

require_once __DIR__ . '/db_migrate.php';

function db_last_error(?string $set = null): string
{
    static $err = '';
    if ($set !== null) {
        $err = $set;
    }
    return $err;
}

function db_available(): bool
{
    return class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function db_normalize_catalog(string $catalog): string
{
    return $catalog === 'music' ? 'music' : 'video';
}

function db_path(string $catalog): string
{
    $catalog = db_normalize_catalog($catalog);
    if ($catalog === 'music') {
        return defined('SQLITE_MUSIC_PATH') ? SQLITE_MUSIC_PATH : (CACHE_DIR . '/music.sqlite');
    }
    return defined('SQLITE_VIDEO_PATH') ? SQLITE_VIDEO_PATH : (CACHE_DIR . '/video.sqlite');
}

function db_json_source_path(string $catalog): string
{
    $catalog = db_normalize_catalog($catalog);
    if ($catalog === 'music') {
        return CACHE_DIR . '/music-library.json';
    }
    return CACHE_DIR . '/library.json';
}

function db_imported_flag_path(string $catalog): string
{
    return CACHE_DIR . '/json-imported-' . db_normalize_catalog($catalog) . '.flag';
}

/**
 * @return PDO|null
 */
function db_open(string $catalog)
{
    $catalog = db_normalize_catalog($catalog);
    static $conns = [];
    if (isset($conns[$catalog]) && $conns[$catalog] instanceof PDO) {
        return $conns[$catalog];
    }
    if (!db_available()) {
        db_last_error('PDO sqlite is not available. Enable pdo_sqlite / sqlite3 in PHP.');
        return null;
    }
    if (!defined('CACHE_DIR')) {
        db_last_error('CACHE_DIR is not defined.');
        return null;
    }
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
        db_last_error('Could not create cache/.');
        return null;
    }
    $path = db_path($catalog);
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        db_migrate($pdo, $catalog);
        $conns[$catalog] = $pdo;
        db_last_error('');
        return $pdo;
    } catch (Throwable $e) {
        db_last_error('Could not open SQLite catalog: ' . $e->getMessage());
        return null;
    }
}

function db_migrate_on_open(): void
{
    db_open('video');
    db_open('music');
}

function db_files_count(string $catalog): int
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return 0;
    }
    try {
        $n = $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        return (int) $n;
    } catch (Throwable $e) {
        return 0;
    }
}

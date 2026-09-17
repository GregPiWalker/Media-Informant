<?php
declare(strict_types=1);

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog_store.php';

function json_import_log(string $line): void
{
    $path = CACHE_DIR . '/json-import.log';
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents($path, '[' . $stamp . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function json_import_titles_map(): array
{
    $dir = CACHE_DIR . '/titles';
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $names = @scandir($dir);
    if (!is_array($names)) {
        return $out;
    }
    foreach ($names as $name) {
        if (!preg_match('/^(\d+)\.json$/', $name, $m)) {
            continue;
        }
        $id = (int) $m[1];
        $data = cache_read_json($dir . '/' . $name);
        if (is_array($data)) {
            $out[$id] = $data;
        }
    }
    return $out;
}

/**
 * @return array{ok: bool, error?: string, files: int, items: int, matched: int, unmatched: int, candidates: int, errors: int}
 */
function json_import_catalog(string $catalog, bool $force): array
{
    $catalog = db_normalize_catalog($catalog);
    $pdo = db_open($catalog);
    $result = [
        'ok' => false,
        'files' => 0,
        'items' => 0,
        'matched' => 0,
        'unmatched' => 0,
        'candidates' => 0,
        'errors' => 0,
    ];
    if ($pdo === null) {
        $result['error'] = db_last_error() !== '' ? db_last_error() : 'Could not open SQLite.';
        return $result;
    }
    $count = db_files_count($catalog);
    if ($count > 0 && !$force) {
        $result['error'] = 'files table already has ' . $count . ' row(s). Check Force reimport to replace them.';
        return $result;
    }

    $source = db_json_source_path($catalog);
    $items = [];
    $data = null;
    if ($catalog === 'video' && !is_file($source)) {
        $result['error'] = 'JSON source not found: ' . $source;
        return $result;
    }
    if ($catalog === 'music') {
        $data = is_file($source) ? cache_read_json($source) : null;
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    } else {
        $data = cache_read_json($source);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $titles = json_import_titles_map();
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $tid = (int) ($item['tmdb_id'] ?? 0);
            if ($tid > 0 && isset($titles[$tid]) && is_array($titles[$tid])) {
                $meta = $titles[$tid];
                if (empty($item['overview']) && !empty($meta['overview'])) {
                    $items[$i]['overview'] = $meta['overview'];
                }
                if (empty($item['poster_path']) && !empty($meta['poster_path'])) {
                    $items[$i]['poster_path'] = $meta['poster_path'];
                }
                if (empty($item['genres']) && !empty($meta['genres'])) {
                    $items[$i]['genres'] = $meta['genres'];
                }
                if (empty($item['year']) && !empty($meta['year'])) {
                    $items[$i]['year'] = $meta['year'];
                }
            }
            $kind = function_exists('library_item_kind') ? library_item_kind($items[$i]) : (string) ($items[$i]['kind'] ?? 'movie');
            $cat = (string) ($items[$i]['category'] ?? '');
            if ($kind !== 'show' && function_exists('settings_content_kind_from_category')) {
                $fromCat = settings_content_kind_from_category($cat);
                if ($fromCat === 'documentary') {
                    $items[$i]['kind'] = 'documentary';
                }
            }
        }
    }

    if ($force && $count > 0) {
        $pdo->exec('DELETE FROM file_candidates');
        $pdo->exec('DELETE FROM files');
        $pdo->exec('DELETE FROM items');
    }

    $ok = catalog_store_save($catalog, $items, isset($data['scanned_at']) ? (int) $data['scanned_at'] : time());
    if (!$ok) {
        $result['error'] = db_last_error() !== '' ? db_last_error() : 'Import failed.';
        $result['errors'] = 1;
        return $result;
    }

    $result['ok'] = true;
    $result['files'] = db_files_count($catalog);
    try {
        $result['items'] = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        $result['matched'] = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE state = 'matched'")->fetchColumn();
        $result['unmatched'] = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE state IS NULL OR state <> 'matched'")->fetchColumn();
        $result['candidates'] = (int) $pdo->query('SELECT COUNT(*) FROM file_candidates')->fetchColumn();
    } catch (Throwable $e) {
        $result['errors']++;
    }
    @file_put_contents(db_imported_flag_path($catalog), (string) time());
    return $result;
}

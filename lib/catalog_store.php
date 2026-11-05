<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function catalog_slug_from_kind(string $kind, string $catalog = 'video'): string
{
    $kind = strtolower(trim($kind));
    if ($catalog === 'music') {
        if ($kind === 'album') {
            return 'album';
        }
        return 'track';
    }
    if ($kind === 'show' || $kind === 'tv' || $kind === 'tv_show' || $kind === 'tv-shows') {
        return 'tv_show';
    }
    if ($kind === 'documentary' || $kind === 'documentaries' || $kind === 'docs') {
        return 'documentary';
    }
    return 'movie';
}

function catalog_kind_from_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === 'tv_show' || $slug === 'tv-shows' || $slug === 'show') {
        return 'show';
    }
    if ($slug === 'documentary' || $slug === 'documentaries') {
        return 'documentary';
    }
    if ($slug === 'album') {
        return 'album';
    }
    if ($slug === 'track') {
        return 'track';
    }
    return 'movie';
}

function catalog_db_match_source(?string $appSource): ?string
{
    $s = library_normalize_match_source((string) $appSource);
    if ($s === 'direct') {
        return 'tmdb';
    }
    if ($s === 'grok' || $s === 'manual' || $s === 'rifftrax') {
        return $s;
    }
    return null;
}

function catalog_app_match_source(?string $dbSource): string
{
    $s = strtolower(trim((string) $dbSource));
    if ($s === 'tmdb' || $s === 'direct' || $s === 'auto') {
        return 'direct';
    }
    if ($s === 'grok' || $s === 'manual' || $s === 'rifftrax') {
        return $s;
    }
    return 'none';
}

function catalog_db_state(array $item): string
{
    $status = library_item_status($item);
    if ($status === 'matched') {
        return 'matched';
    }
    if ($status === 'unidentified') {
        return 'unmatched';
    }
    return 'unmatched';
}

function catalog_type_id(PDO $pdo, string $catalog, string $slug): int
{
    $stmt = $pdo->prepare('SELECT id FROM item_types WHERE catalog = ? AND slug = ?');
    $stmt->execute([$catalog, $slug]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM item_types WHERE catalog = ' . $pdo->quote($catalog))->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO item_types (catalog, slug, label, sort) VALUES (?, ?, ?, ?)');
    $label = ucwords(str_replace(['_', '-'], ' ', $slug));
    $ins->execute([$catalog, $slug, $label, $sort]);
    return (int) $pdo->lastInsertId();
}

function catalog_json_encode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}

function catalog_show_season_key(int $season): string
{
    return 's' . $season;
}

function catalog_store_group_extras(PDO $pdo, string $groupId, string $kind): array
{
    $extras = ['kind' => $kind];
    $prev = [];
    $stmt = $pdo->prepare('SELECT extras_json FROM items WHERE id = ?');
    $stmt->execute([$groupId]);
    $raw = $stmt->fetchColumn();
    if (is_string($raw) && $raw !== '') {
        $prev = catalog_json_decode($raw);
        if (isset($prev['seasons']) && is_array($prev['seasons'])) {
            $extras['seasons'] = $prev['seasons'];
        }
    }
    $extras['scan_service'] = catalog_scan_service_from_extras($prev);
    return $extras;
}

function catalog_store_show_season_read(int $tmdbId, int $season): ?array
{
    if ($tmdbId < 1 || $season < 0 || !function_exists('db_open')) {
        return null;
    }
    $pdo = db_open('video');
    if ($pdo === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT extras_json FROM items
         WHERE tmdb_id = ? AND grouping = 'group' AND parent_id IS NULL AND tmdb_media_type = 'tv'
         LIMIT 1"
    );
    $stmt->execute([$tmdbId]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $extras = catalog_json_decode($raw);
    $seasons = $extras['seasons'] ?? null;
    if (!is_array($seasons)) {
        return null;
    }
    $payload = $seasons[catalog_show_season_key($season)] ?? null;
    if (!is_array($payload) || !is_array($payload['episodes'] ?? null) || $payload['episodes'] === []) {
        return null;
    }
    return $payload;
}

function catalog_store_show_season_write(int $tmdbId, int $season, array $payload): void
{
    if ($tmdbId < 1 || $season < 0 || !function_exists('db_open')) {
        return;
    }
    if (!is_array($payload['episodes'] ?? null) || $payload['episodes'] === []) {
        return;
    }
    $pdo = db_open('video');
    if ($pdo === null) {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT id, extras_json FROM items
         WHERE tmdb_id = ? AND grouping = 'group' AND parent_id IS NULL AND tmdb_media_type = 'tv'
         LIMIT 1"
    );
    $stmt->execute([$tmdbId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return;
    }
    $extras = catalog_json_decode(isset($row['extras_json']) ? (string) $row['extras_json'] : null);
    if (!isset($extras['seasons']) || !is_array($extras['seasons'])) {
        $extras['seasons'] = [];
    }
    $extras['seasons'][catalog_show_season_key($season)] = $payload;
    $pdo->prepare('UPDATE items SET extras_json = ?, updated_at = ? WHERE id = ?')->execute([
        catalog_json_encode($extras),
        time(),
        (string) $row['id'],
    ]);
}

function catalog_json_decode(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function catalog_store_meta(string $catalog): array
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return ['updated_at' => null, 'root_path' => ''];
    }
    $stmt = $pdo->prepare('SELECT updated_at, root_path, name FROM catalog_meta WHERE catalog = ?');
    $stmt->execute([db_normalize_catalog($catalog)]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : ['updated_at' => null, 'root_path' => ''];
}

function catalog_store_load(string $catalog = 'video'): array
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return [];
    }
    $sql = 'SELECT f.id AS file_id, f.path AS file_path, f.parsed_title, f.parsed_year, f.size, f.mtime,
            f.state, f.match_source AS file_match_source, f.grok_verified, f.confidence, f.kind AS file_kind,
            i.id AS item_id, i.title AS item_title, i.sort_title, i.year AS item_year, i.tmdb_id, i.tmdb_media_type,
            i.overview, i.poster_path, i.extras_json, i.grouping, i.parent_id,
            t.slug AS type_slug,
            p.title AS parent_title, p.grouping AS parent_grouping, p.extras_json AS parent_extras, p.tmdb_id AS parent_tmdb,
            gp.title AS grand_title, gp.grouping AS grand_grouping
        FROM files f
        LEFT JOIN items i ON i.id = f.item_id
        LEFT JOIN item_types t ON t.id = i.type_id
        LEFT JOIN items p ON p.id = i.parent_id
        LEFT JOIN items gp ON gp.id = p.parent_id
        ORDER BY f.path';
    $rows = $pdo->query($sql)->fetchAll();
    $candStmt = $pdo->prepare('SELECT tmdb_id, title, year, media_type, score, rank FROM file_candidates WHERE file_id = ? ORDER BY rank ASC, tmdb_id ASC');
    $items = [];
    foreach ($rows as $row) {
        $items[] = catalog_hydrate_file($row, $candStmt);
    }
    return $items;
}

function catalog_hydrate_file(array $row, PDOStatement $candStmt): array
{
    $extras = catalog_json_decode($row['extras_json'] ?? null);
    $fileId = (string) ($row['file_id'] ?? '');
    $pathKey = (string) ($row['file_path'] ?? '');
    $root = (string) ($extras['root'] ?? '');
    $path = (string) ($extras['path'] ?? '');
    if ($path === '' && str_contains($pathKey, "\n")) {
        [$root, $path] = explode("\n", $pathKey, 2);
    }
    $slug = (string) ($row['type_slug'] ?? 'movie');
    $kind = catalog_kind_from_slug($slug !== '' ? $slug : (string) ($row['file_kind'] ?? 'movie'));
    $series = (string) ($extras['series_title'] ?? '');
    if ($series === '' && ($row['grand_grouping'] ?? '') === 'group' && ($row['grand_title'] ?? '') !== '') {
        $series = (string) $row['grand_title'];
    } elseif ($series === '' && ($row['parent_grouping'] ?? '') === 'group' && ($row['parent_title'] ?? '') !== '') {
        $series = (string) $row['parent_title'];
    }
    $grouped = !empty($extras['grouped']) || $kind === 'show' || ($row['parent_id'] ?? '') !== '';
    if ($kind === 'show') {
        $grouped = true;
    }
    $title = $series !== '' ? $series : (string) ($row['item_title'] ?? $row['parsed_title'] ?? '');
    $display = (string) ($extras['display_title'] ?? '');
    if ($display === '') {
        $display = (string) ($row['item_title'] ?? $title);
    }
    $state = (string) ($row['state'] ?? 'unmatched');
    $status = (string) ($extras['status'] ?? '');
    if ($status === '') {
        $status = $state === 'matched' ? 'matched' : (string) ($extras['app_status'] ?? 'unmatched');
    }
    $cands = [];
    if ($fileId !== '') {
        $candStmt->execute([$fileId]);
        foreach ($candStmt->fetchAll() as $cand) {
            $cands[] = [
                'id' => (int) ($cand['tmdb_id'] ?? 0),
                'tmdb_id' => (int) ($cand['tmdb_id'] ?? 0),
                'title' => (string) ($cand['title'] ?? ''),
                'year' => $cand['year'] !== null ? (int) $cand['year'] : null,
                'media_type' => (string) ($cand['media_type'] ?? 'movie'),
            ];
        }
    }
    $item = [
        'id' => $fileId,
        'root' => $root,
        'path' => $path,
        'filename' => (string) ($extras['filename'] ?? ''),
        'mtime' => (int) ($row['mtime'] ?? 0),
        'size' => (int) ($row['size'] ?? 0),
        'title' => $title,
        'year' => $row['item_year'] !== null && $row['item_year'] !== '' ? (int) $row['item_year'] : ($row['parsed_year'] !== null ? (int) $row['parsed_year'] : null),
        'season' => $extras['season'] ?? null,
        'episode' => $extras['episode'] ?? null,
        'kind' => $kind,
        'grouped' => $grouped,
        'episode_title' => (string) ($extras['episode_title'] ?? ''),
        'part' => (string) ($extras['part'] ?? ''),
        'category' => (string) ($extras['category'] ?? ''),
        'tmdb_id' => !empty($row['tmdb_id']) ? (int) $row['tmdb_id'] : null,
        'status' => $status,
        'poster_path' => $row['poster_path'] ?? null,
        'display_title' => $display,
        'match_source' => catalog_app_match_source($row['file_match_source'] ?? null),
        'genres' => is_array($extras['genres'] ?? null) ? $extras['genres'] : [],
        'overview' => (string) ($row['overview'] ?? $extras['overview'] ?? ''),
        'tmdb_candidates' => $cands,
        'grok_verified' => (int) ($row['grok_verified'] ?? 0),
    ];
    if (!empty($extras['hidden'])) {
        $item['hidden'] = true;
    }
    if (!empty($extras['kind_source'])) {
        $item['kind_source'] = (string) $extras['kind_source'];
    }
    if (!empty($extras['grouped_source'])) {
        $item['grouped_source'] = (string) $extras['grouped_source'];
    }
    if (!empty($extras['title_source'])) {
        $item['title_source'] = (string) $extras['title_source'];
    }
    if (($extras['year_source'] ?? '') === 'user') {
        $item['year_source'] = 'user';
    }
    if (!empty($extras['poster_cleared'])) {
        $item['poster_cleared'] = true;
    }
    if (isset($extras['media_type']) && is_string($extras['media_type'])) {
        $item['media_type'] = $extras['media_type'];
    } elseif (!empty($row['tmdb_media_type'])) {
        $item['media_type'] = (string) $row['tmdb_media_type'];
    }
    if (isset($extras['vote_average']) && is_numeric($extras['vote_average'])) {
        $item['vote_average'] = (float) $extras['vote_average'];
        if (isset($extras['vote_count']) && is_numeric($extras['vote_count'])) {
            $item['vote_count'] = max(0, (int) $extras['vote_count']);
        }
    }
    if (($extras['lookup_source'] ?? '') !== '') {
        $item['lookup_source'] = (string) $extras['lookup_source'];
    }
    if ((string) ($extras['lookup_id'] ?? '') !== '') {
        $item['lookup_id'] = (string) $extras['lookup_id'];
    }
    if ((string) ($extras['detail_url'] ?? '') !== '') {
        $item['detail_url'] = (string) $extras['detail_url'];
    }
    if (isset($extras['original_year']) && is_numeric($extras['original_year'])) {
        $item['original_year'] = (int) $extras['original_year'];
    }
    if (is_array($extras['lookup_candidates'] ?? null)) {
        $item['lookup_candidates'] = $extras['lookup_candidates'];
    }
    if (isset($extras['runtime']) && is_numeric($extras['runtime']) && (int) $extras['runtime'] > 0) {
        $item['runtime'] = (int) $extras['runtime'];
    }
    if (trim((string) ($extras['scan_service'] ?? '')) !== '') {
        $item['scan_service'] = catalog_scan_service_keep_value($extras['scan_service']);
    }
    return $item;
}

function catalog_folder_id_ok(string $folderId): bool
{
    return preg_match('/^folder:[a-f0-9]{16}$/', $folderId) === 1;
}

/**
 * Registered lookup source, using that source's own spelling.
 */
function catalog_lookup_service_name(string $service): string
{
    $service = trim($service);
    if ($service === '' || !function_exists('lookup_services')) {
        return '';
    }
    foreach (array_keys(lookup_services()) as $name) {
        if (strcasecmp((string) $name, $service) === 0) {
            return (string) $name;
        }
    }
    return '';
}

/**
 * Stored scan service, using the registered spelling when the name is known.
 * An unknown non-empty name is kept so a later save does not silently replace it.
 */
function catalog_scan_service_keep_value($value): string
{
    $stored = trim((string) $value);
    if ($stored === '') {
        return '';
    }
    if (!function_exists('lookup_services')) {
        return $stored;
    }
    $named = catalog_lookup_service_name($stored);
    return $named !== '' ? $named : $stored;
}

function catalog_scan_service_default(): string
{
    $named = catalog_lookup_service_name('TMDB');
    return $named !== '' ? $named : 'TMDB';
}

function catalog_scan_service_from_extras(array $extras): string
{
    $kept = catalog_scan_service_keep_value($extras['scan_service'] ?? '');
    return $kept !== '' ? $kept : catalog_scan_service_default();
}

/**
 * Movie folder for a file, matching the List parent key.
 *
 * @return array{label: string, id: string}
 */
function catalog_file_movie_folder(array $item): array
{
    $none = ['label' => '', 'id' => ''];
    $kind = function_exists('library_item_kind') ? library_item_kind($item) : (string) ($item['kind'] ?? '');
    if ($kind !== 'movie') {
        return $none;
    }
    if (!function_exists('catalog_movie_folder') || !function_exists('catalog_folder_id')) {
        $catalogFile = __DIR__ . '/catalog.php';
        if (is_file($catalogFile)) {
            require_once $catalogFile;
        }
    }
    if (!function_exists('catalog_movie_folder') || !function_exists('catalog_folder_id') || !function_exists('lower')) {
        return $none;
    }
    $label = catalog_movie_folder(
        (string) ($item['root'] ?? ''),
        (string) ($item['path'] ?? ''),
        (string) ($item['filename'] ?? '')
    );
    if ($label === '') {
        return $none;
    }
    $key = lower((string) ($item['root'] ?? '')) . "\n" . lower($label);
    return ['label' => $label, 'id' => catalog_folder_id($key)];
}

/**
 * @return array{saved: string, effective: string}
 */
function catalog_file_folder_services(array $item): array
{
    static $savedCache = [];
    static $effectiveCache = [];
    $folder = catalog_file_movie_folder($item);
    if ($folder['id'] === '') {
        return ['saved' => '', 'effective' => ''];
    }
    $id = $folder['id'];
    if (!array_key_exists($id, $savedCache)) {
        $savedCache[$id] = catalog_folder_scan_service_saved($id);
        $effectiveCache[$id] = catalog_folder_scan_service_for_rescan($id, $folder['label']);
    }
    return ['saved' => $savedCache[$id], 'effective' => $effectiveCache[$id]];
}

function catalog_file_folder_scan_service(array $item): string
{
    return catalog_file_folder_services($item)['effective'];
}

function catalog_file_folder_saved_service(array $item): string
{
    return catalog_file_folder_services($item)['saved'];
}

/**
 * Service Match title, the detail status line, and a file lookup should use.
 * A saved choice on the movie's folder wins, including over an older stored default.
 * Otherwise a stored value wins. Otherwise the folder's effective service, then TMDB.
 */
function catalog_item_scan_service(array $item): string
{
    $saved = catalog_file_folder_saved_service($item);
    if ($saved !== '') {
        return $saved;
    }
    $kept = catalog_scan_service_keep_value($item['scan_service'] ?? '');
    if ($kept !== '') {
        return $kept;
    }
    $folder = catalog_file_folder_scan_service($item);
    if ($folder !== '') {
        return $folder;
    }
    return catalog_scan_service_default();
}

function catalog_file_scan_service_for_store(string $catalog, array $item): string
{
    if ($catalog === 'video') {
        $saved = catalog_file_folder_saved_service($item);
        if ($saved !== '') {
            return $saved;
        }
    }
    $kept = catalog_scan_service_keep_value($item['scan_service'] ?? '');
    if ($kept !== '') {
        return $kept;
    }
    if ($catalog === 'video') {
        $folder = catalog_file_folder_scan_service($item);
        if ($folder !== '') {
            return $folder;
        }
    }
    return catalog_scan_service_default();
}

/**
 * @param list<string> $fileIds
 */
function catalog_files_apply_scan_service(PDO $pdo, array $fileIds, string $service): int
{
    $byFile = $pdo->prepare(
        'SELECT i.id AS item_id, i.extras_json FROM files f INNER JOIN items i ON i.id = f.item_id WHERE f.id = ?'
    );
    $byItem = $pdo->prepare('SELECT id AS item_id, extras_json FROM items WHERE id = ? AND grouping = ?');
    $upd = $pdo->prepare('UPDATE items SET extras_json = ?, updated_at = ? WHERE id = ?');
    $now = time();
    $n = 0;
    $seen = [];
    foreach ($fileIds as $id) {
        $id = strtolower((string) $id);
        if ($id === '' || isset($seen[$id]) || preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
            continue;
        }
        $seen[$id] = true;
        $byFile->execute([$id]);
        $row = $byFile->fetch();
        if (!is_array($row) || (string) ($row['item_id'] ?? '') === '') {
            $byItem->execute([$id, 'standalone']);
            $row = $byItem->fetch();
        }
        if (!is_array($row) || (string) ($row['item_id'] ?? '') === '') {
            continue;
        }
        $raw = $row['extras_json'] ?? null;
        $extras = is_string($raw) && $raw !== '' ? catalog_json_decode($raw) : [];
        if ((string) ($extras['scan_service'] ?? '') !== $service) {
            $extras['scan_service'] = $service;
            $upd->execute([catalog_json_encode($extras), $now, (string) $row['item_id']]);
        }
        $n++;
    }
    return $n;
}

/**
 * Lookup service saved on a folder parent, or an empty string when none is saved.
 */
function catalog_folder_scan_service_saved(string $folderId): string
{
    if (!catalog_folder_id_ok($folderId) || !function_exists('db_open')) {
        return '';
    }
    $pdo = db_open('video');
    if ($pdo === null) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT extras_json FROM items WHERE id = ? AND grouping = ?');
    $stmt->execute([$folderId, 'group']);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $extras = catalog_json_decode($raw);
    return catalog_lookup_service_name((string) ($extras['scan_service'] ?? ''));
}

/**
 * Service a folder Re-scan should use.
 * A saved choice wins. A folder named RiffTrax uses RiffTrax until one is saved.
 * Any other folder with no choice returns an empty string and keeps the usual scan.
 */
function catalog_folder_scan_service_for_rescan(string $folderId, string $label): string
{
    $saved = catalog_folder_scan_service_saved($folderId);
    if ($saved !== '') {
        return $saved;
    }
    if (strcasecmp(trim($label), 'RiffTrax') === 0) {
        return 'RiffTrax';
    }
    return '';
}

/**
 * Store the lookup service on the folder parent and on each child file.
 * Later catalog writes keep the folder row.
 *
 * @param list<string> $fileIds
 */
function catalog_folder_scan_service_write(string $folderId, string $label, string $service, array $fileIds = []): bool
{
    $service = catalog_lookup_service_name($service);
    if (!catalog_folder_id_ok($folderId) || $service === '' || !function_exists('db_open')) {
        return false;
    }
    $pdo = db_open('video');
    if ($pdo === null) {
        return false;
    }
    $label = trim($label);
    if ($label === '') {
        $label = 'Folder';
    }
    if (strlen($label) > 200) {
        $label = substr($label, 0, 200);
    }
    $extras = ['folder' => true, 'scan_service' => $service];
    $stmt = $pdo->prepare('SELECT extras_json FROM items WHERE id = ?');
    $stmt->execute([$folderId]);
    $raw = $stmt->fetchColumn();
    if (is_string($raw) && $raw !== '') {
        $prev = catalog_json_decode($raw);
        if ($prev !== []) {
            $extras = $prev;
            $extras['folder'] = true;
            $extras['scan_service'] = $service;
        }
    }
    $copied = 0;
    try {
        $pdo->beginTransaction();
        $typeId = catalog_type_id($pdo, 'video', 'movie');
        catalog_store_write_item($pdo, [
            'id' => $folderId,
            'type_id' => $typeId,
            'parent_id' => null,
            'grouping' => 'group',
            'title' => $label,
            'sort_title' => function_exists('lower') ? lower($label) : strtolower($label),
            'year' => null,
            'tmdb_id' => null,
            'tmdb_media_type' => null,
            'overview' => '',
            'poster_path' => null,
            'extras_json' => catalog_json_encode($extras),
        ], time());
        $copied = catalog_files_apply_scan_service($pdo, $fileIds, $service);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('db_last_error')) {
            db_last_error('Could not save folder scan service: ' . $e->getMessage());
        }
        return false;
    }
    if (function_exists('app_log')) {
        $level = ($copied === 0 && $fileIds !== []) ? 'warn' : 'info';
        app_log('scan', 'Folder “' . $label . '” scan service ' . $service . ' stored on ' . $copied . ' file' . ($copied === 1 ? '' : 's') . '.', [
            'key' => $folderId,
            'service' => $service,
            'files' => $copied,
        ], $level);
    }
    return true;
}

/**
 * @param array<string, bool> $keepItemIds
 */
function catalog_store_keep_folder_groups(PDO $pdo, array &$keepItemIds): void
{
    $rows = $pdo->query("SELECT id FROM items WHERE grouping = 'group' AND id LIKE 'folder:%'");
    if ($rows === false) {
        return;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if (catalog_folder_id_ok($id)) {
            $keepItemIds[$id] = true;
        }
    }
}

function catalog_store_save(string $catalog, array $items, ?int $scannedAt = null): bool
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return false;
    }
    $catalog = db_normalize_catalog($catalog);
    $now = $scannedAt ?? time();
    try {
        $pdo->beginTransaction();
        $keepFileIds = [];
        $keepItemIds = [];
        foreach ($items as $item) {
            if (!is_array($item) || (string) ($item['id'] ?? '') === '') {
                continue;
            }
            catalog_store_upsert_item($pdo, $catalog, $item, $now, $keepFileIds, $keepItemIds);
        }
        catalog_store_keep_folder_groups($pdo, $keepItemIds);
        catalog_store_delete_missing($pdo, 'files', $keepFileIds);
        catalog_store_delete_missing($pdo, 'items', array_keys($keepItemIds));
        $root = $catalog === 'music'
            ? (function_exists('settings_music_roots') ? implode('; ', settings_music_roots()) : '')
            : (function_exists('settings_video_roots') ? implode('; ', settings_video_roots()) : '');
        $meta = $pdo->prepare('INSERT INTO catalog_meta (catalog, name, root_path, updated_at) VALUES (?, ?, ?, ?)
            ON CONFLICT(catalog) DO UPDATE SET root_path = excluded.root_path, updated_at = excluded.updated_at');
        $meta->execute([$catalog, $catalog === 'music' ? 'Music' : 'Video', $root, $now]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        db_last_error('Could not save catalog: ' . $e->getMessage());
        return false;
    }
}

function catalog_store_delete_missing(PDO $pdo, string $table, array $ids): void
{
    $table = $table === 'items' ? 'items' : 'files';
    if ($ids === []) {
        $pdo->exec('DELETE FROM ' . $table);
        return;
    }
    $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS keep_ids (id TEXT PRIMARY KEY)');
    $pdo->exec('DELETE FROM keep_ids');
    $ins = $pdo->prepare('INSERT OR IGNORE INTO keep_ids (id) VALUES (?)');
    foreach ($ids as $id) {
        $ins->execute([(string) $id]);
    }
    $pdo->exec('DELETE FROM ' . $table . ' WHERE id NOT IN (SELECT id FROM keep_ids)');
    $pdo->exec('DROP TABLE IF EXISTS keep_ids');
}

function catalog_store_keep_user_media(PDO $pdo, array $item): array
{
    $fileId = (string) ($item['id'] ?? '');
    if ($fileId === '' || !preg_match('/^[a-f0-9]{16}$/', $fileId)) {
        return $item;
    }
    $stmt = $pdo->prepare('SELECT i.year AS item_year, i.poster_path, i.extras_json FROM items i INNER JOIN files f ON f.item_id = i.id WHERE f.id = ?');
    $stmt->execute([$fileId]);
    $prev = $stmt->fetch();
    if (!is_array($prev)) {
        return $item;
    }
    $prevExtras = catalog_json_decode(isset($prev['extras_json']) && is_string($prev['extras_json']) ? $prev['extras_json'] : null);
    $yearSource = (string) ($item['year_source'] ?? '');
    $status = function_exists('library_item_status') ? library_item_status($item) : (string) ($item['status'] ?? '');
    if ($yearSource === 'file') {
        unset($item['year_source']);
    } elseif ($yearSource !== 'user' && $status !== 'matched' && ($prevExtras['year_source'] ?? '') === 'user') {
        $prevYear = $prev['item_year'];
        $item['year'] = $prevYear !== null && $prevYear !== '' ? (int) $prevYear : null;
        $item['year_source'] = 'user';
    }
    $incomingPoster = isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : '';
    $dropPoster = ($item['poster_source'] ?? '') === 'file';
    unset($item['poster_source']);
    if ($incomingPoster !== '') {
        unset($item['poster_cleared']);
    } elseif ($dropPoster || !empty($item['poster_cleared']) || !empty($prevExtras['poster_cleared'])) {
        $item['poster_path'] = null;
        if (!empty($item['poster_cleared']) || !empty($prevExtras['poster_cleared'])) {
            $item['poster_cleared'] = true;
        }
    } else {
        $prevPoster = isset($prev['poster_path']) && is_string($prev['poster_path']) ? $prev['poster_path'] : null;
        if (function_exists('poster_local_disk') && poster_local_disk($prevPoster) !== null) {
            $item['poster_path'] = $prevPoster;
        }
    }
    if (trim((string) ($item['scan_service'] ?? '')) === '' && trim((string) ($prevExtras['scan_service'] ?? '')) !== '') {
        $item['scan_service'] = (string) $prevExtras['scan_service'];
    }
    return $item;
}

function catalog_store_upsert_item(PDO $pdo, string $catalog, array $item, int $now, array &$keepFileIds, array &$keepItemIds): void
{
    $item = catalog_store_keep_user_media($pdo, $item);
    $fileId = (string) $item['id'];
    $root = (string) ($item['root'] ?? '');
    $rel = str_replace('\\', '/', (string) ($item['path'] ?? ''));
    $filePath = $root . "\n" . $rel;
    $kind = function_exists('library_item_kind') ? library_item_kind($item) : (string) ($item['kind'] ?? 'movie');
    $grouped = function_exists('library_item_grouped') ? library_item_grouped($item) : ($kind === 'show');
    if ($kind === 'show') {
        $grouped = true;
    }
    $slug = catalog_slug_from_kind($kind, $catalog);
    $typeId = catalog_type_id($pdo, $catalog, $slug);
    $parentId = null;
    $seriesTitle = (string) ($item['title'] ?? '');
    if ($grouped && $seriesTitle !== '') {
        $year = $item['year'] ?? null;
        $groupId = 'g-' . substr(hash('sha256', $catalog . '|' . $slug . '|' . lower($seriesTitle) . '|' . (string) $year), 0, 14);
        catalog_store_write_item($pdo, [
            'id' => $groupId,
            'type_id' => $typeId,
            'parent_id' => null,
            'grouping' => 'group',
            'title' => $seriesTitle,
            'sort_title' => lower($seriesTitle),
            'year' => is_numeric($year) ? (int) $year : null,
            'tmdb_id' => !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null,
            'tmdb_media_type' => $kind === 'show' ? 'tv' : ($kind === 'documentary' ? 'movie' : 'movie'),
            'overview' => (string) ($item['overview'] ?? ''),
            'poster_path' => $item['poster_path'] ?? null,
            'extras_json' => catalog_json_encode(catalog_store_group_extras($pdo, $groupId, $kind)),
        ], $now);
        $keepItemIds[$groupId] = true;
        $parentId = $groupId;
        $season = $item['season'] ?? null;
        if ($season !== null && $season !== '') {
            $seasonId = 's-' . substr(hash('sha256', $groupId . '|s' . (int) $season), 0, 14);
            $seasonPrev = [];
            $seasonStmt = $pdo->prepare('SELECT extras_json FROM items WHERE id = ?');
            $seasonStmt->execute([$seasonId]);
            $seasonRaw = $seasonStmt->fetchColumn();
            if (is_string($seasonRaw) && $seasonRaw !== '') {
                $seasonPrev = catalog_json_decode($seasonRaw);
            }
            $seasonExtras = [
                'season' => (int) $season,
                'scan_service' => catalog_scan_service_from_extras($seasonPrev),
            ];
            catalog_store_write_item($pdo, [
                'id' => $seasonId,
                'type_id' => $typeId,
                'parent_id' => $groupId,
                'grouping' => 'group',
                'title' => 'Season ' . (int) $season,
                'sort_title' => sprintf('%02d', (int) $season),
                'year' => is_numeric($year) ? (int) $year : null,
                'tmdb_id' => null,
                'tmdb_media_type' => null,
                'overview' => '',
                'poster_path' => null,
                'extras_json' => catalog_json_encode($seasonExtras),
            ], $now);
            $keepItemIds[$seasonId] = true;
            $parentId = $seasonId;
        }
    }
    $leafTitle = $grouped
        ? (string) ($item['episode_title'] ?? $item['display_title'] ?? $item['filename'] ?? $seriesTitle)
        : (string) ($item['display_title'] ?? $item['title'] ?? '');
    $extras = [
        'root' => $root,
        'path' => $rel,
        'filename' => (string) ($item['filename'] ?? ''),
        'category' => (string) ($item['category'] ?? ''),
        'season' => $item['season'] ?? null,
        'episode' => $item['episode'] ?? null,
        'episode_title' => (string) ($item['episode_title'] ?? ''),
        'part' => (string) ($item['part'] ?? ''),
        'genres' => cache_string_list($item['genres'] ?? []),
        'display_title' => (string) ($item['display_title'] ?? ''),
        'status' => library_item_status($item),
        'app_status' => library_item_status($item),
        'grouped' => $grouped,
        'series_title' => $seriesTitle,
        'hidden' => function_exists('library_item_hidden') && library_item_hidden($item),
        'kind_source' => (string) ($item['kind_source'] ?? ''),
        'grouped_source' => (string) ($item['grouped_source'] ?? ''),
        'title_source' => (string) ($item['title_source'] ?? ''),
        'overview' => (string) ($item['overview'] ?? ''),
        'media_type' => (string) ($item['media_type'] ?? ''),
    ];
    if (($item['year_source'] ?? '') === 'user') {
        $extras['year_source'] = 'user';
    }
    if (!empty($item['poster_cleared'])) {
        $extras['poster_cleared'] = true;
    }
    if (isset($item['vote_average']) && is_numeric($item['vote_average'])) {
        $extras['vote_average'] = (float) $item['vote_average'];
        if (isset($item['vote_count']) && is_numeric($item['vote_count'])) {
            $extras['vote_count'] = max(0, (int) $item['vote_count']);
        }
    }
    if ((string) ($item['lookup_source'] ?? '') !== '') {
        $extras['lookup_source'] = (string) $item['lookup_source'];
    }
    if ((string) ($item['lookup_id'] ?? '') !== '') {
        $extras['lookup_id'] = (string) $item['lookup_id'];
    }
    if ((string) ($item['detail_url'] ?? '') !== '') {
        $extras['detail_url'] = (string) $item['detail_url'];
    }
    if (isset($item['original_year']) && is_numeric($item['original_year'])) {
        $extras['original_year'] = (int) $item['original_year'];
    }
    if (is_array($item['lookup_candidates'] ?? null) && $item['lookup_candidates'] !== []) {
        $kept = [];
        foreach ($item['lookup_candidates'] as $cand) {
            if (is_array($cand)) {
                $kept[] = $cand;
            }
            if (count($kept) >= 6) {
                break;
            }
        }
        if ($kept !== []) {
            $extras['lookup_candidates'] = $kept;
        }
    }
    if (isset($item['runtime']) && is_numeric($item['runtime']) && (int) $item['runtime'] > 0) {
        $extras['runtime'] = (int) $item['runtime'];
    }
    $item['scan_service'] = catalog_file_scan_service_for_store($catalog, $item);
    $extras['scan_service'] = (string) $item['scan_service'];
    catalog_store_write_item($pdo, [
        'id' => $fileId,
        'type_id' => $typeId,
        'parent_id' => $parentId,
        'grouping' => 'standalone',
        'title' => $leafTitle,
        'sort_title' => lower($leafTitle),
        'year' => isset($item['year']) && $item['year'] !== null && $item['year'] !== '' ? (int) $item['year'] : null,
        'tmdb_id' => !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null,
        'tmdb_media_type' => (string) ($item['media_type'] ?? ($kind === 'show' ? 'tv' : 'movie')),
        'overview' => (string) ($item['overview'] ?? ''),
        'poster_path' => $item['poster_path'] ?? null,
        'extras_json' => catalog_json_encode($extras),
    ], $now);
    $keepItemIds[$fileId] = true;

    $state = catalog_db_state($item);
    $ms = catalog_db_match_source($item['match_source'] ?? null);
    $fileStmt = $pdo->prepare('INSERT INTO files (id, path, item_id, parsed_title, parsed_year, size, mtime, state, match_source, grok_verified, confidence, kind)
        VALUES (:id, :path, :item_id, :parsed_title, :parsed_year, :size, :mtime, :state, :match_source, :grok_verified, :confidence, :kind)
        ON CONFLICT(id) DO UPDATE SET
            path = excluded.path, item_id = excluded.item_id, parsed_title = excluded.parsed_title,
            parsed_year = excluded.parsed_year, size = excluded.size, mtime = excluded.mtime,
            state = excluded.state, match_source = excluded.match_source, grok_verified = excluded.grok_verified,
            confidence = excluded.confidence, kind = excluded.kind');
    $fileStmt->execute([
        ':id' => $fileId,
        ':path' => $filePath,
        ':item_id' => $fileId,
        ':parsed_title' => (string) ($item['title'] ?? ''),
        ':parsed_year' => isset($item['year']) && $item['year'] !== '' && $item['year'] !== null ? (int) $item['year'] : null,
        ':size' => (int) ($item['size'] ?? 0),
        ':mtime' => (int) ($item['mtime'] ?? 0),
        ':state' => $state,
        ':match_source' => $ms,
        ':grok_verified' => !empty($item['grok_verified']) ? 1 : 0,
        ':confidence' => isset($item['confidence']) && $item['confidence'] !== '' ? (float) $item['confidence'] : null,
        ':kind' => $kind,
    ]);
    $keepFileIds[] = $fileId;

    $pdo->prepare('DELETE FROM file_candidates WHERE file_id = ?')->execute([$fileId]);
    $cands = $item['tmdb_candidates'] ?? [];
    if (is_array($cands) && $cands !== []) {
        $cins = $pdo->prepare('INSERT OR REPLACE INTO file_candidates (file_id, tmdb_id, title, year, media_type, score, rank) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $rank = 0;
        foreach ($cands as $cand) {
            if (!is_array($cand)) {
                continue;
            }
            $candSource = (string) ($cand['source'] ?? 'TMDB');
            if ($candSource !== '' && strcasecmp($candSource, 'TMDB') !== 0) {
                continue;
            }
            $tid = (int) ($cand['id'] ?? $cand['tmdb_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $rank++;
            $cins->execute([
                $fileId,
                $tid,
                (string) ($cand['title'] ?? ''),
                isset($cand['year']) && $cand['year'] !== '' && $cand['year'] !== null ? (int) $cand['year'] : null,
                (string) ($cand['media_type'] ?? 'movie'),
                isset($cand['score']) ? (float) $cand['score'] : null,
                $rank,
            ]);
        }
    }
}

function catalog_store_write_item(PDO $pdo, array $row, int $now): void
{
    $stmt = $pdo->prepare('INSERT INTO items (id, type_id, parent_id, grouping, title, sort_title, year, tmdb_id, tmdb_media_type, overview, poster_path, extras_json, created_at, updated_at)
        VALUES (:id, :type_id, :parent_id, :grouping, :title, :sort_title, :year, :tmdb_id, :tmdb_media_type, :overview, :poster_path, :extras_json, :created_at, :updated_at)
        ON CONFLICT(id) DO UPDATE SET
            type_id = excluded.type_id, parent_id = excluded.parent_id, grouping = excluded.grouping,
            title = excluded.title, sort_title = excluded.sort_title, year = excluded.year,
            tmdb_id = excluded.tmdb_id, tmdb_media_type = excluded.tmdb_media_type, overview = excluded.overview,
            poster_path = excluded.poster_path, extras_json = excluded.extras_json, updated_at = excluded.updated_at');
    $stmt->execute([
        ':id' => $row['id'],
        ':type_id' => $row['type_id'],
        ':parent_id' => $row['parent_id'],
        ':grouping' => $row['grouping'],
        ':title' => $row['title'],
        ':sort_title' => $row['sort_title'],
        ':year' => $row['year'],
        ':tmdb_id' => $row['tmdb_id'],
        ':tmdb_media_type' => $row['tmdb_media_type'],
        ':overview' => $row['overview'],
        ':poster_path' => $row['poster_path'],
        ':extras_json' => $row['extras_json'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function catalog_store_find(string $catalog, string $id): ?array
{
    $pdo = db_open($catalog);
    if ($pdo === null || $id === '') {
        return null;
    }
    $sql = 'SELECT f.id AS file_id, f.path AS file_path, f.parsed_title, f.parsed_year, f.size, f.mtime,
            f.state, f.match_source AS file_match_source, f.grok_verified, f.confidence, f.kind AS file_kind,
            i.id AS item_id, i.title AS item_title, i.sort_title, i.year AS item_year, i.tmdb_id, i.tmdb_media_type,
            i.overview, i.poster_path, i.extras_json, i.grouping, i.parent_id,
            t.slug AS type_slug,
            p.title AS parent_title, p.grouping AS parent_grouping, p.extras_json AS parent_extras, p.tmdb_id AS parent_tmdb,
            gp.title AS grand_title, gp.grouping AS grand_grouping
        FROM files f
        LEFT JOIN items i ON i.id = f.item_id
        LEFT JOIN item_types t ON t.id = i.type_id
        LEFT JOIN items p ON p.id = i.parent_id
        LEFT JOIN items gp ON gp.id = p.parent_id
        WHERE f.id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $candStmt = $pdo->prepare('SELECT tmdb_id, title, year, media_type, score, rank FROM file_candidates WHERE file_id = ? ORDER BY rank ASC, tmdb_id ASC');
    return catalog_hydrate_file($row, $candStmt);
}

function catalog_store_rename_file(string $catalog, string $root, string $oldRel, string $newRel): bool
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return false;
    }
    $root = function_exists('settings_normalize_path') ? settings_normalize_path($root) : $root;
    $oldRel = str_replace('\\', '/', ltrim($oldRel, '/'));
    $newRel = str_replace('\\', '/', ltrim($newRel, '/'));
    if ($root === '' || $oldRel === '' || $newRel === '' || $oldRel === $newRel) {
        return false;
    }
    $oldKey = $root . "\n" . $oldRel;
    $newKey = $root . "\n" . $newRel;
    $oldHash = function_exists('cache_item_id') ? cache_item_id($oldRel, $root) : '';
    $newId = function_exists('cache_item_id') ? cache_item_id($newRel, $root) : '';
    $newFilename = basename($newRel);
    $now = time();
    $mtime = null;
    $fullNew = $root . '/' . $newRel;
    if (is_file($fullNew)) {
        $mtime = (int) filemtime($fullNew);
    }

    try {
        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT id, path, item_id FROM files WHERE path = ? OR id = ?');
        $find->execute([$oldKey, $oldHash !== '' ? $oldHash : $oldKey]);
        $file = $find->fetch();
        if (!is_array($file)) {
            $pdo->rollBack();
            return false;
        }
        $fileId = (string) $file['id'];
        $itemId = (string) ($file['item_id'] ?? $fileId);
        if ($itemId === '') {
            $itemId = $fileId;
        }
        if ($newId === '') {
            $newId = $fileId;
        }

        if ($newId !== $fileId) {
            $pdo->prepare('DELETE FROM files WHERE (id = ? OR path = ?) AND id != ?')->execute([$newId, $newKey, $fileId]);
            $pdo->prepare('DELETE FROM items WHERE id = ? AND id NOT IN (SELECT item_id FROM files WHERE item_id IS NOT NULL)')->execute([$newId]);
        }

        $extras = [];
        $exStmt = $pdo->prepare('SELECT extras_json FROM items WHERE id = ?');
        $exStmt->execute([$itemId]);
        $exRow = $exStmt->fetch();
        if (is_array($exRow)) {
            $extras = catalog_json_decode($exRow['extras_json'] ?? null);
        }
        $extras['root'] = $root;
        $extras['path'] = $newRel;
        $extras['filename'] = $newFilename;
        $extrasJson = catalog_json_encode($extras);

        if ($newId === $fileId) {
            $sql = 'UPDATE files SET path = ?';
            $args = [$newKey];
            if ($mtime !== null) {
                $sql .= ', mtime = ?';
                $args[] = $mtime;
            }
            $sql .= ' WHERE id = ?';
            $args[] = $fileId;
            $pdo->prepare($sql)->execute($args);
            $pdo->prepare('UPDATE items SET extras_json = ?, updated_at = ? WHERE id = ?')->execute([$extrasJson, $now, $itemId]);
            $pdo->commit();
            return true;
        }

        $copiedItem = $pdo->prepare('INSERT INTO items (id, type_id, parent_id, grouping, title, sort_title, year, tmdb_id, tmdb_media_type, overview, poster_path, extras_json, created_at, updated_at)
            SELECT ?, type_id, parent_id, grouping, title, sort_title, year, tmdb_id, tmdb_media_type, overview, poster_path, ?, created_at, ?
            FROM items WHERE id = ?');
        $copiedItem->execute([$newId, $extrasJson, $now, $itemId]);
        $leafItem = $copiedItem->rowCount() > 0 ? $newId : ($itemId !== '' ? $itemId : $newId);

        $fileCopy = 'INSERT INTO files (id, path, item_id, parsed_title, parsed_year, size, mtime, state, match_source, grok_verified, confidence, kind)
            SELECT ?, ?, ?, parsed_title, parsed_year, size, ' . ($mtime !== null ? '?' : 'mtime') . ', state, match_source, grok_verified, confidence, kind
            FROM files WHERE id = ?';
        $fileArgs = [$newId, $newKey, $leafItem];
        if ($mtime !== null) {
            $fileArgs[] = $mtime;
        }
        $fileArgs[] = $fileId;
        $pdo->prepare($fileCopy)->execute($fileArgs);

        $pdo->prepare('UPDATE file_candidates SET file_id = ? WHERE file_id = ?')->execute([$newId, $fileId]);
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fileId]);
        if ($itemId === $fileId) {
            $pdo->prepare('UPDATE items SET parent_id = ? WHERE parent_id = ?')->execute([$newId, $fileId]);
            $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$fileId]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        db_last_error('Could not update catalog path: ' . $e->getMessage());
        return false;
    }
}

function catalog_store_update_one(string $catalog, array $item): bool

{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return false;
    }
    $keepFiles = [];
    $keepItems = [];
    try {
        $pdo->beginTransaction();
        catalog_store_upsert_item($pdo, db_normalize_catalog($catalog), $item, time(), $keepFiles, $keepItems);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        db_last_error('Could not update item: ' . $e->getMessage());
        return false;
    }
}

function catalog_store_drop_root(string $catalog, string $root): int
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return 0;
    }
    $root = function_exists('settings_normalize_path') ? settings_normalize_path($root) : $root;
    if ($root === '') {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM files WHERE path = ? OR path LIKE ?');
        $stmt->execute([$root . "\n", $root . "\n%"]);
        $n = $stmt->rowCount();
        $pdo->exec('DELETE FROM items WHERE id NOT IN (SELECT item_id FROM files WHERE item_id IS NOT NULL)
            AND id NOT LIKE \'folder:%\'
            AND (parent_id IS NULL OR parent_id NOT IN (SELECT id FROM items))');
        $pdo->exec('DELETE FROM items WHERE id NOT IN (SELECT item_id FROM files WHERE item_id IS NOT NULL)
            AND id NOT LIKE \'folder:%\'
            AND id NOT IN (SELECT parent_id FROM items WHERE parent_id IS NOT NULL)');
        return $n;
    } catch (Throwable $e) {
        db_last_error('Could not drop source records: ' . $e->getMessage());
        return 0;
    }
}

function catalog_store_record_scan(string $catalog, array $job, bool $cancelled): void
{
    $pdo = db_open($catalog);
    if ($pdo === null) {
        return;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO scan_runs (started_at, finished_at, files_seen, matched, grok_batches, grok_cost_usd, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $job['started_at'] ?? time(),
            time(),
            is_array($job['files'] ?? null) ? count($job['files']) : 0,
            (int) ($job['found'] ?? 0),
            (int) ($job['grok_batches'] ?? 0),
            (float) ($job['grok_cost_usd'] ?? 0),
            $cancelled ? 'stopped' : 'done',
        ]);
    } catch (Throwable $e) {
        // optional table
    }
}

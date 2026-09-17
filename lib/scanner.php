<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/parser.php';

final class SkipJunkFilter extends RecursiveFilterIterator
{
    /** @var list<string> */
    private array $excludes;

    public function __construct(RecursiveIterator $iterator, array $excludes = [])
    {
        parent::__construct($iterator);
        $this->excludes = $excludes;
    }

    public function accept(): bool
    {
        $current = $this->current();
        $name = $current instanceof SplFileInfo ? $current->getFilename() : (string) $current;
        $l = strtolower($name);
        if ($l === '@eadir' || $l === '#recycle' || $l === '@recycle' || $l === '#snapshot') {
            return false;
        }
        if ($this->excludes !== [] && $current instanceof SplFileInfo) {
            $full = str_replace('\\', '/', $current->getPathname());
            if (settings_path_is_excluded($full, $this->excludes)) {
                return false;
            }
        }
        return true;
    }

    public function hasChildren(): bool
    {
        return $this->accept() && parent::hasChildren();
    }

    public function getChildren(): ?RecursiveFilterIterator
    {
        $inner = $this->getInnerIterator();
        if (!$inner instanceof RecursiveIterator || !$inner->hasChildren()) {
            return null;
        }
        $children = $inner->getChildren();
        if (!$children instanceof RecursiveIterator) {
            return null;
        }
        return new self($children, $this->excludes);
    }
}

function scanner_relative(string $root, string $fullPath): string
{
    $root = settings_normalize_path($root);
    $full = str_replace('\\', '/', $fullPath);
    if (str_starts_with($full, $root . '/')) {
        return substr($full, strlen($root) + 1);
    }
    return ltrim($full, '/');
}

function scanner_is_video(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, VIDEO_EXTENSIONS, true);
}

function scan_files(string $root, ?array $excludes = null, ?callable $progress = null): array
{
    $files = [];
    if (!is_dir($root) || !is_readable($root)) {
        return $files;
    }
    $excludes ??= settings_video_excludes();

    try {
        $inner = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
        );
        $filtered = new SkipJunkFilter($inner, $excludes);
        $iterator = new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $iterator->setMaxDepth(24);
    } catch (UnexpectedValueException $e) {
        return $files;
    }

    foreach ($iterator as $fileinfo) {
        if (scan_cancelled()) {
            break;
        }
        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
            continue;
        }
        $name = $fileinfo->getFilename();
        if ($name === '' || $name[0] === '.') {
            continue;
        }
        if (!scanner_is_video($name)) {
            continue;
        }

        try {
            $normalizedRoot = settings_normalize_path($root);
            $files[] = [
                'root' => $normalizedRoot,
                'path' => scanner_relative($root, $fileinfo->getPathname()),
                'filename' => $name,
                'mtime' => (int) $fileinfo->getMTime(),
                'size' => (int) $fileinfo->getSize(),
            ];
            if ($progress && count($files) % 15 === 0) {
                $progress(count($files));
            }
        } catch (RuntimeException $e) {
            continue;
        }
    }

    return $files;
}

function scan_cancel_path(): string
{
    return CACHE_DIR . '/scan.cancel';
}

function scan_request_cancel(): void
{
    cache_init();
    $path = scan_cancel_path();
    clearstatcache(true, $path);
    @file_put_contents($path, (string) time());
    clearstatcache(true, $path);
    $status = scan_status_read();
    if (($status['state'] ?? '') === 'running') {
        scan_status_write([
            'cancel_requested' => true,
            'phase' => 'stopping',
            'message' => 'Stop requested. Finishing the current step…',
        ]);
        if (function_exists('app_log')) {
            app_log('scan', 'Stop requested for the TMDB scan.', [], 'warn');
        }
    }
}

function scan_clear_cancel(): void
{
    $path = scan_cancel_path();
    clearstatcache(true, $path);
    if (is_file($path)) {
        @unlink($path);
        clearstatcache(true, $path);
    }
}

function scan_cancelled(): bool
{
    $path = scan_cancel_path();
    clearstatcache(true, $path);
    return is_file($path);
}

function scan_match_key(string $title, ?int $year): string
{
    return lower($title) . '|' . ($year ?? '');
}

function library_is_manual(array $item): bool
{
    return library_match_source($item) === 'manual';
}

function library_touch(array $item, array $file): array
{
    $item['root'] = (string) ($file['root'] ?? $item['root'] ?? '');
    $item['path'] = (string) ($file['path'] ?? $item['path'] ?? '');
    $item['filename'] = (string) ($file['filename'] ?? $item['filename'] ?? '');
    $item['mtime'] = (int) $file['mtime'];
    $item['size'] = (int) $file['size'];
    $item['category'] = settings_category_for_root($item['root']);
    return $item;
}

function library_carry_file(array $item, array $file): array
{
    $item = library_touch($item, $file);
    $parsed = parse_media($item['path']);
    $item['season'] = $parsed['season'];
    $item['episode'] = $parsed['episode'];
    $item['kind'] = (string) ($parsed['kind'] ?? $item['kind'] ?? 'movie');
    $item['episode_title'] = (string) ($parsed['episode_title'] ?? '');
    $item['part'] = (string) ($parsed['part'] ?? '');
    if ($parsed['title'] !== '') {
        $item['title'] = $parsed['title'];
        if (($item['status'] ?? '') !== 'matched') {
            $item = library_set_auto_title($item, $parsed['title']);
        }
    }
    return $item;
}

function scan_status_path(): string
{
    return CACHE_DIR . '/scan-status.json';
}

function scan_status_defaults(): array
{
    return [
        'state' => 'idle',
        'phase' => '',
        'total' => 0,
        'pending' => 0,
        'processed' => 0,
        'lookups' => 0,
        'matched' => 0,
        'found' => 0,
        'unmatched' => 0,
        'unidentified' => 0,
        'mode' => 'retry',
        'message' => '',
        'started_at' => null,
        'updated_at' => null,
        'cancel_requested' => false,
    ];
}

function scan_status_read(): array
{
    $data = cache_read_json(scan_status_path());
    if (!is_array($data)) {
        return scan_status_defaults();
    }
    return array_merge(scan_status_defaults(), $data);
}

function scan_normalize_mode(string $mode): string
{
    return $mode === 'unidentified' ? 'unidentified' : 'retry';
}

function scan_file_needs_lookup(?array $prev, bool $unchanged, string $mode): bool
{
    if ($prev === null) {
        return true;
    }
    if (library_is_manual($prev)) {
        return false;
    }
    $status = library_item_status($prev);
    if ($status === 'matched' && !empty($prev['tmdb_id'])) {
        if ($unchanged) {
            return false;
        }
        return $mode !== 'unidentified';
    }
    if ($status === 'unidentified') {
        return true;
    }
    if ($status === 'unmatched') {
        return $mode !== 'unidentified';
    }
    return true;
}

function scan_tally_status(array &$job, string $status): void
{
    if ($status === 'unidentified') {
        $job['unidentified'] = (int) ($job['unidentified'] ?? 0) + 1;
        return;
    }
    if ($status !== 'matched') {
        $job['unmatched'] = (int) ($job['unmatched'] ?? 0) + 1;
    }
}

function scan_lookup_message(int $found, int $pending, string $mode = 'retry'): string
{
    if ($pending <= 0) {
        return 'Refreshing catalog…';
    }
    $kind = $mode === 'unidentified' ? 'unidentified' : 'unresolved';
    return 'Performing TMDB look-ups: found ' . $found . ' of ' . $pending . ' ' . $kind . ' titles.';
}

function scan_status_write(array $patch): void
{
    $current = scan_status_read();
    $next = array_merge($current, $patch, ['updated_at' => time()]);
    cache_write_atomic(scan_status_path(), $next);
}

function scan_job_path(): string
{
    return CACHE_DIR . '/scan-job.json';
}

function scan_job_read(): array
{
    $data = cache_read_json(scan_job_path());
    return is_array($data) ? $data : ['state' => 'idle'];
}

function scan_job_write(array $job): void
{
    if (!cache_write_atomic(scan_job_path(), $job)) {
        throw new RuntimeException('Could not write scan job. Check cache/ permissions.');
    }
}

function scan_job_clear(): void
{
    $path = scan_job_path();
    clearstatcache(true, $path);
    if (is_file($path)) {
        @unlink($path);
        clearstatcache(true, $path);
    }
}

function scan_index_library(array $library): array
{
    $oldByPath = [];
    $known = [];
    foreach ($library['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!empty($item['path'])) {
            $rootKey = (string) ($item['root'] ?? '');
            $oldByPath[$rootKey . "\n" . $item['path']] = $item;
            if ($rootKey === '') {
                $oldByPath[$item['path']] = $item;
            }
        }
        if (($item['status'] ?? '') === 'matched' && !empty($item['tmdb_id']) && !empty($item['title'])) {
            $known[scan_match_key((string) $item['title'], $item['year'] ?? null)] = $item;
            if (!empty($item['display_title'])) {
                $known[scan_match_key((string) $item['display_title'], $item['year'] ?? null)] = $item;
            }
        }
    }
    return [$oldByPath, $known];
}

function scan_job_publish(array $job, array $extra = []): void
{
    $stopping = scan_cancelled() || !empty($job['cancel']);
    $files = $job['files'] ?? [];
    $total = is_array($files) ? count($files) : 0;
    $pending = (int) ($job['pending'] ?? 0);
    $found = (int) ($job['found'] ?? 0);
    $phase = (string) ($job['phase'] ?? 'lookup');
    $message = (string) ($job['message'] ?? '');
    if ($message === '') {
        if ($stopping) {
            $message = 'Stop requested. Saving progress…';
        } elseif ($phase === 'walk') {
            $message = 'Walking video folders…';
        } else {
            $message = scan_lookup_message($found, $pending, (string) ($job['mode'] ?? 'retry'));
        }
    }
    scan_status_write(array_merge([
        'state' => 'running',
        'phase' => $stopping ? 'stopping' : $phase,
        'cancel_requested' => $stopping,
        'started_at' => $job['started_at'] ?? time(),
        'total' => $total,
        'pending' => $pending,
        'processed' => (int) ($job['index'] ?? 0),
        'lookups' => (int) ($job['lookups'] ?? 0),
        'matched' => $found,
        'found' => $found,
        'unmatched' => (int) ($job['unmatched'] ?? 0),
        'unidentified' => (int) ($job['unidentified'] ?? 0),
        'mode' => scan_normalize_mode((string) ($job['mode'] ?? 'retry')),
        'message' => $message,
    ], $extra));
}

function scan_job_begin(string $mode = 'retry'): array
{
    cache_init();
    if (!cache_writable()) {
        throw new RuntimeException('cache/ is not writable. Grant the http user write access.');
    }

    $mode = scan_normalize_mode($mode);
    scan_clear_cancel();
    $roots = settings_video_roots();
    if ($roots === []) {
        throw new RuntimeException('No video folders are configured. Add at least one in Config.');
    }

    $started = time();
    if (function_exists('app_log')) {
        app_log('scan', 'TMDB scan started (' . ($mode === 'unidentified' ? 'unidentified only' : 'unmatched and unidentified') . ').', ['mode' => $mode]);
    }
    scan_status_write([
        'state' => 'running',
        'phase' => 'walk',
        'started_at' => $started,
        'cancel_requested' => false,
        'total' => 0,
        'pending' => 0,
        'processed' => 0,
        'lookups' => 0,
        'matched' => 0,
        'found' => 0,
        'unmatched' => 0,
        'unidentified' => 0,
        'mode' => $mode,
        'message' => 'Walking video folders…',
    ]);

    $files = [];
    $unreadable = [];
    foreach ($roots as $root) {
        if (scan_cancelled()) {
            break;
        }
        if (!is_dir($root) || !is_readable($root)) {
            $unreadable[] = $root;
            continue;
        }
        $base = count($files);
        $batch = scan_files($root, null, static function (int $n) use ($base, $started): void {
            $count = $base + $n;
            scan_status_write([
                'state' => 'running',
                'phase' => 'walk',
                'started_at' => $started,
                'processed' => $count,
                'message' => 'Walking video folders… ' . $count . ' files found.',
            ]);
        });
        foreach ($batch as $file) {
            $files[] = $file;
        }
        $walked = count($files);
        scan_status_write([
            'state' => 'running',
            'phase' => 'walk',
            'started_at' => $started,
            'processed' => $walked,
            'message' => 'Walking video folders… ' . $walked . ' files found.',
        ]);
    }

    if (scan_cancelled()) {
        scan_job_clear();
        scan_clear_cancel();
        scan_status_write([
            'state' => 'stopped',
            'phase' => 'walk',
            'cancel_requested' => false,
            'started_at' => $started,
            'message' => 'Scan stopped during the folder walk. The catalog was left as it was.',
        ]);
        if (function_exists('app_log')) {
            app_log('scan', 'Scan stopped during the folder walk.', ['files' => count($files)], 'warn');
        }
        return scan_status_read();
    }

    if ($files === [] && $unreadable !== []) {
        throw new RuntimeException('Cannot read any video folder (' . implode(', ', $unreadable) . '). Check the paths in Config and that the http user has read access.');
    }

    $old = cache_read_library();
    [$oldByPath] = scan_index_library($old);
    $pending = 0;
    foreach ($files as $file) {
        $root = (string) ($file['root'] ?? '');
        $prev = $oldByPath[$root . "\n" . $file['path']] ?? $oldByPath[$file['path']] ?? null;
        $unchanged = $prev
            && (int) ($prev['mtime'] ?? 0) === $file['mtime']
            && (int) ($prev['size'] ?? 0) === $file['size'];
        if (scan_file_needs_lookup($prev, $unchanged, $mode)) {
            $pending++;
        }
    }

    $job = [
        'state' => 'running',
        'phase' => 'lookup',
        'mode' => $mode,
        'started_at' => $started,
        'roots' => $roots,
        'files' => $files,
        'index' => 0,
        'items' => [],
        'lookups' => 0,
        'found' => 0,
        'unmatched' => 0,
        'unidentified' => 0,
        'reused' => 0,
        'pending' => $pending,
        'message' => scan_lookup_message(0, $pending, $mode),
    ];
    scan_job_write($job);
    scan_job_publish($job);
    if (function_exists('app_log')) {
        app_log('scan', 'Folder walk finished: ' . count($files) . ' files, ' . $pending . ' TMDB lookups queued.', [
            'files' => count($files),
            'pending' => $pending,
            'mode' => $mode,
        ]);
    }
    return scan_status_read();
}

function scan_job_process_file(array &$job, array $file, array $oldByPath, array &$knownMatches, bool $skipLookup): bool
{
    $path = (string) ($file['path'] ?? '');
    $root = (string) ($file['root'] ?? '');
    $mode = scan_normalize_mode((string) ($job['mode'] ?? 'retry'));
    $prev = $oldByPath[$root . "\n" . $path] ?? $oldByPath[$path] ?? null;
    $unchanged = $prev
        && (int) ($prev['mtime'] ?? 0) === (int) ($file['mtime'] ?? 0)
        && (int) ($prev['size'] ?? 0) === (int) ($file['size'] ?? 0);
    $needsLookup = scan_file_needs_lookup($prev, $unchanged, $mode);

    if ($prev && (!$needsLookup || $skipLookup)) {
        $kept = ($unchanged && library_item_status($prev) === 'matched')
            ? library_touch($prev, $file)
            : library_carry_file($prev, $file);
        $job['items'][] = $kept;
        $job['reused'] = (int) $job['reused'] + 1;
        scan_tally_status($job, library_item_status($kept));
        return false;
    }

    $parsed = parse_media($path);
    $title = $parsed['title'] !== '' ? $parsed['title'] : strip_extension((string) ($file['filename'] ?? ''));
    $year = $parsed['year'];
    $key = scan_match_key($title, $year);

    $sameParse = $prev
        && ($prev['title'] ?? '') === $title
        && (($prev['year'] ?? null) === $year)
        && ($prev['status'] ?? '') === 'matched'
        && !empty($prev['tmdb_id']);

    $item = [
        'id' => cache_item_id($path, $root),
        'root' => $root,
        'path' => $path,
        'filename' => $file['filename'] ?? '',
        'mtime' => (int) ($file['mtime'] ?? 0),
        'size' => (int) ($file['size'] ?? 0),
        'title' => $title,
        'year' => $year,
        'season' => $parsed['season'],
        'episode' => $parsed['episode'],
        'kind' => (string) ($parsed['kind'] ?? 'movie'),
        'episode_title' => (string) ($parsed['episode_title'] ?? ''),
        'part' => (string) ($parsed['part'] ?? ''),
        'category' => settings_category_for_root($root),
        'tmdb_id' => null,
        'status' => 'unidentified',
        'poster_path' => null,
        'display_title' => $title,
        'match_source' => 'none',
        'genres' => [],
    ];
    if ($prev) {
        if (array_key_exists('overview', $prev) && is_string($prev['overview'])) {
            $item['overview'] = $prev['overview'];
        }
        if (!empty($prev['genres']) && is_array($prev['genres'])) {
            $item['genres'] = cache_string_list($prev['genres']);
        }
        if (library_has_custom_title($prev)) {
            $item['display_title'] = (string) $prev['display_title'];
            $item['title_source'] = 'user';
        }
    }

    $source = null;
    if ($sameParse) {
        $source = $prev;
    } elseif (isset($knownMatches[$key])) {
        $source = $knownMatches[$key];
    }

    if ($source && !empty($source['tmdb_id'])) {
        $tmdbId = (int) $source['tmdb_id'];
        $meta = cache_read_title($tmdbId);
        if ($meta === null && ($source['status'] ?? '') === 'matched') {
            $meta = [
                'tmdb_id' => $tmdbId,
                'title' => (string) ($source['display_title'] ?? $source['title'] ?? $title),
                'year' => $source['year'] ?? $year,
                'overview' => '',
                'poster_path' => $source['poster_path'] ?? null,
                'cast' => [],
                'genres' => $source['genres'] ?? [],
            ];
        }
        if ($meta !== null) {
            $item['tmdb_id'] = $tmdbId;
            $item['status'] = 'matched';
            $item['poster_path'] = $meta['poster_path'] ?? $source['poster_path'] ?? null;
            $item = library_set_auto_title($item, (string) ($meta['title'] ?? $source['display_title'] ?? $title));
            if (!empty($meta['year'])) {
                $item['year'] = (int) $meta['year'];
            }
            $item['genres'] = cache_string_list($meta['genres'] ?? $source['genres'] ?? []);
            $item['match_source'] = 'direct';
            $knownMatches[$key] = $item;
            $job['items'][] = $item;
            $job['found'] = (int) $job['found'] + 1;
            return false;
        }
    }

    if ($skipLookup || !tmdb_has_key() || !function_exists('curl_init')) {
        $item['status'] = 'unidentified';
        $item['match_source'] = 'none';
        scan_tally_status($job, 'unidentified');
        $job['items'][] = $item;
        return false;
    }

    $meta = tmdb_lookup($title, $year, (string) ($file['filename'] ?? $path));
    $job['lookups'] = (int) $job['lookups'] + 1;

    if ($meta !== null && !empty($meta['tmdb_id'])) {
        $tmdbId = (int) $meta['tmdb_id'];
        cache_write_title($tmdbId, $meta);
        $item['tmdb_id'] = $tmdbId;
        $item['status'] = 'matched';
        $item['poster_path'] = $meta['poster_path'] ?? null;
        $item = library_set_auto_title($item, (string) ($meta['title'] ?? $title));
        if (!empty($meta['year'])) {
            $item['year'] = (int) $meta['year'];
        }
        $item['genres'] = cache_string_list($meta['genres'] ?? []);
        $item['match_source'] = 'direct';
        $knownMatches[$key] = $item;
        $knownMatches[scan_match_key($item['display_title'], $item['year'])] = $item;
        $job['found'] = (int) $job['found'] + 1;
    } else {
        $item['status'] = 'unmatched';
        $item['match_source'] = 'none';
        $job['unmatched'] = (int) $job['unmatched'] + 1;
    }

    $job['items'][] = $item;
    return true;
}

function scan_job_finalize(array $job, bool $cancelled): array
{
    $files = is_array($job['files'] ?? null) ? $job['files'] : [];
    $old = cache_read_library();
    [$oldByPath, $known] = scan_index_library($old);
    foreach ($job['items'] as $item) {
        if (is_array($item) && ($item['status'] ?? '') === 'matched' && !empty($item['tmdb_id']) && !empty($item['title'])) {
            $known[scan_match_key((string) $item['title'], $item['year'] ?? null)] = $item;
            if (!empty($item['display_title'])) {
                $known[scan_match_key((string) $item['display_title'], $item['year'] ?? null)] = $item;
            }
        }
    }

    $index = (int) ($job['index'] ?? 0);
    $total = count($files);
    while ($index < $total) {
        $file = $files[$index];
        if (is_array($file)) {
            scan_job_process_file($job, $file, $oldByPath, $known, true);
        }
        $index++;
        $job['index'] = $index;
    }

    $library = [
        'version' => 1,
        'scanned_at' => time(),
        'video_roots' => $job['roots'] ?? settings_video_roots(),
        'items' => $job['items'],
    ];
    if (!cache_write_library($library)) {
        throw new RuntimeException('Could not write library.json. Check cache/ permissions.');
    }

    $found = (int) ($job['found'] ?? 0);
    $pending = (int) ($job['pending'] ?? 0);
    scan_job_clear();
    scan_clear_cancel();
    scan_status_write([
        'state' => $cancelled ? 'stopped' : 'done',
        'phase' => $cancelled ? 'stopped' : 'done',
        'cancel_requested' => false,
        'started_at' => $job['started_at'] ?? null,
        'total' => $total,
        'pending' => $pending,
        'processed' => count($job['items']),
        'lookups' => (int) ($job['lookups'] ?? 0),
        'found' => $found,
        'matched' => $found,
        'unmatched' => (int) ($job['unmatched'] ?? 0),
        'unidentified' => (int) ($job['unidentified'] ?? 0),
        'mode' => scan_normalize_mode((string) ($job['mode'] ?? 'retry')),
        'message' => ($cancelled ? 'Scan stopped. ' : 'Scan finished. ')
            . 'Found ' . $found . ' of ' . $pending . ' '
            . (scan_normalize_mode((string) ($job['mode'] ?? 'retry')) === 'unidentified' ? 'unidentified' : 'unresolved')
            . ' titles.',
    ]);
    if (function_exists('app_log')) {
        app_log('scan', ($cancelled ? 'Scan stopped. ' : 'Scan finished. ')
            . 'Found ' . $found . ' of ' . $pending . ', lookups ' . (int) ($job['lookups'] ?? 0) . '.', [
            'found' => $found,
            'pending' => $pending,
            'lookups' => (int) ($job['lookups'] ?? 0),
            'cancelled' => $cancelled,
        ], $cancelled ? 'warn' : 'info');
    }
    return scan_status_read();
}

function scan_job_continue(): array
{
    $job = scan_job_read();
    if (($job['state'] ?? '') !== 'running') {
        return scan_status_read();
    }

    if (scan_cancelled()) {
        $job['cancel'] = true;
        $job['phase'] = 'stopping';
        $job['message'] = 'Stop requested. Saving progress…';
        scan_job_publish($job);
        return scan_job_finalize($job, true);
    }

    $files = is_array($job['files'] ?? null) ? $job['files'] : [];
    $old = cache_read_library();
    [$oldByPath, $known] = scan_index_library($old);
    foreach ($job['items'] as $item) {
        if (is_array($item) && ($item['status'] ?? '') === 'matched' && !empty($item['tmdb_id']) && !empty($item['title'])) {
            $known[scan_match_key((string) $item['title'], $item['year'] ?? null)] = $item;
            if (!empty($item['display_title'])) {
                $known[scan_match_key((string) $item['display_title'], $item['year'] ?? null)] = $item;
            }
        }
    }

    $deadline = hrtime(true) + 1800000000;
    $lookupsThisTick = 0;
    $progress = 0;
    $total = count($files);
    $index = (int) ($job['index'] ?? 0);

    while ($index < $total) {
        if ($progress > 0 && scan_cancelled()) {
            $job['index'] = $index;
            $job['cancel'] = true;
            scan_job_write($job);
            return scan_job_finalize($job, true);
        }
        if ($progress > 0 && ($lookupsThisTick >= 3 || hrtime(true) >= $deadline)) {
            break;
        }
        $file = $files[$index];
        $didLookup = false;
        if (is_array($file)) {
            $didLookup = scan_job_process_file($job, $file, $oldByPath, $known, false);
        }
        $index++;
        $job['index'] = $index;
        $progress++;
        if ($didLookup) {
            $lookupsThisTick++;
            $job['message'] = scan_lookup_message((int) $job['found'], (int) $job['pending'], (string) ($job['mode'] ?? 'retry'));
            scan_job_publish($job);
        }
    }

    if ($index >= $total) {
        return scan_job_finalize($job, false);
    }

    $job['message'] = scan_lookup_message((int) $job['found'], (int) $job['pending'], (string) ($job['mode'] ?? 'retry'));
    scan_job_write($job);
    scan_job_publish($job);
    return scan_status_read();
}

function scan_grok_is_running(): bool
{
    if (!function_exists('grok_status_read')) {
        return false;
    }
    return (grok_status_read()['state'] ?? '') === 'running';
}

function scan_tick(bool $allowStart = false, string $mode = 'retry'): array
{
    cache_init();
    $mode = scan_normalize_mode($mode);
    $job = scan_job_read();
    if (($job['state'] ?? '') === 'running') {
        return scan_job_continue();
    }
    if ($allowStart && scan_grok_is_running()) {
        $status = scan_status_read();
        $status['ok'] = false;
        $status['blocked'] = 'grok';
        $status['kind'] = 'tmdb';
        $status['message'] = 'Grok resolve is running. Wait for it to finish before starting a TMDB scan.';
        if (function_exists('app_log')) {
            app_log('scan', 'TMDB scan blocked because Grok resolve is running.', [], 'warn');
        }
        return $status;
    }
    if (!$allowStart) {
        $status = scan_status_read();
        if (($status['state'] ?? '') === 'running') {
            scan_status_write([
                'state' => 'error',
                'cancel_requested' => false,
                'message' => 'Scan interrupted. Start again from Config.',
            ]);
            return scan_status_read();
        }
        return $status;
    }
    return scan_job_begin($mode);
}

function scan_build_library(?callable $progress = null): array
{
    $status = scan_tick(true);
    while (($status['state'] ?? '') === 'running') {
        if ($progress && !empty($status['message'])) {
            $progress((string) $status['message']);
        }
        $status = scan_job_continue();
    }
    $old = cache_read_library();
    return [
        'files' => (int) ($status['total'] ?? count($old['items'])),
        'lookups' => (int) ($status['lookups'] ?? 0),
        'unmatched' => (int) ($status['unmatched'] ?? 0),
        'reused' => 0,
        'matched' => (int) ($status['matched'] ?? 0),
        'cancelled' => ($status['state'] ?? '') === 'stopped',
        'phase' => (string) ($status['phase'] ?? ''),
        'library' => $old,
    ];
}

function library_sync_video_catalog(): array
{
    cache_init();
    $roots = settings_video_roots();
    $old = cache_read_library();
    $oldItems = is_array($old['items'] ?? null) ? $old['items'] : [];
    $oldCount = 0;
    $oldByKey = [];
    $knownMatches = [];

    foreach ($oldItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $oldCount++;
        $root = settings_item_root($item, $old);
        $path = (string) ($item['path'] ?? '');
        if ($root !== '' && $path !== '') {
            $oldByKey[$root . "\n" . $path] = $item;
        }
        if (($item['status'] ?? '') === 'matched' && !empty($item['tmdb_id']) && !empty($item['title'])) {
            $knownMatches[scan_match_key((string) $item['title'], $item['year'] ?? null)] = $item;
            if (!empty($item['display_title'])) {
                $knownMatches[scan_match_key((string) $item['display_title'], $item['year'] ?? null)] = $item;
            }
        }
    }

    $items = [];
    $seen = [];
    foreach ($roots as $root) {
        if (settings_path_status($root) !== 'ok') {
            continue;
        }
        foreach (scan_files($root) as $file) {
            $path = $file['path'];
            $fileRoot = (string) $file['root'];
            $key = $fileRoot . "\n" . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $prev = $oldByKey[$key] ?? null;
            $unchanged = $prev
                && (int) ($prev['mtime'] ?? 0) === $file['mtime']
                && (int) ($prev['size'] ?? 0) === $file['size'];
            if ($unchanged || ($prev && library_is_manual($prev))) {
                $items[] = library_carry_file($prev, $file);
                continue;
            }

            $parsed = parse_media($path);
            $title = $parsed['title'] !== '' ? $parsed['title'] : strip_extension($file['filename']);
            $year = $parsed['year'];
            $item = [
                'id' => cache_item_id($path, $fileRoot),
                'root' => $fileRoot,
                'path' => $path,
                'filename' => $file['filename'],
                'mtime' => $file['mtime'],
                'size' => $file['size'],
                'title' => $title,
                'year' => $year,
                'season' => $parsed['season'],
                'episode' => $parsed['episode'],
                'kind' => (string) ($parsed['kind'] ?? 'movie'),
                'episode_title' => (string) ($parsed['episode_title'] ?? ''),
                'part' => (string) ($parsed['part'] ?? ''),
                'category' => settings_category_for_root($fileRoot),
                'tmdb_id' => null,
                'status' => 'unidentified',
                'poster_path' => null,
                'display_title' => $title,
                'match_source' => 'none',
                'genres' => [],
            ];

            $matchKey = scan_match_key($title, $year);
            $source = $knownMatches[$matchKey] ?? null;
            if ($source && !empty($source['tmdb_id'])) {
                $tmdbId = (int) $source['tmdb_id'];
                $meta = cache_read_title($tmdbId);
                $item['tmdb_id'] = $tmdbId;
                $item['status'] = 'matched';
                $item['poster_path'] = $meta['poster_path'] ?? $source['poster_path'] ?? null;
                $item = library_set_auto_title($item, (string) ($meta['title'] ?? $source['display_title'] ?? $title));
                if (!empty($meta['year'])) {
                    $item['year'] = (int) $meta['year'];
                } elseif (!empty($source['year'])) {
                    $item['year'] = (int) $source['year'];
                }
                $item['genres'] = cache_string_list(($meta['genres'] ?? null) ?: ($source['genres'] ?? []));
                $item['match_source'] = 'direct';
            }

            $items[] = $item;
        }
    }

    $library = [
        'version' => 1,
        'scanned_at' => $items === [] ? null : (int) ($old['scanned_at'] ?? time()),
        'video_roots' => $roots,
        'items' => $items,
    ];
    if ($items !== [] && empty($library['scanned_at'])) {
        $library['scanned_at'] = time();
    }

    if (!cache_write_library($library)) {
        throw new RuntimeException('Could not update the video catalog. Check cache/ permissions.');
    }

    $newCount = count($items);
    $added = 0;
    $removed = 0;
    foreach ($seen as $key => $unused) {
        if (!isset($oldByKey[$key])) {
            $added++;
        }
    }
    foreach ($oldByKey as $key => $unused) {
        if (!isset($seen[$key])) {
            $removed++;
        }
    }

    return [
        'before' => $oldCount,
        'after' => $newCount,
        'added' => $added,
        'removed' => $removed,
    ];
}

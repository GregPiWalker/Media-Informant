<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/folders.php';
require_once __DIR__ . '/sources.php';

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
            'message' => 'Stop requested. Finishing the current ' . scan_catalog_label((string) ($status['catalog'] ?? 'video')) . ' step…',
        ]);
        if (function_exists('app_log')) {
            app_log('scan', 'Stop requested for the ' . scan_catalog_title((string) ($status['catalog'] ?? 'video')) . ' scan.', [], 'warn');
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

function scan_item_key(array $row): string
{
    return (string) ($row['root'] ?? '') . "\n" . (string) ($row['path'] ?? '');
}

/**
 * Library rows for this job. Files gone from a walked source are omitted.
 * Items on a configured root we could not read are kept. Roots no longer
 * in Config are omitted.
 *
 * @return list<array<string, mixed>>
 */
function scan_job_catalog_items(array $job): array
{
    $files = is_array($job['files'] ?? null) ? $job['files'] : [];
    $live = [];
    foreach ($files as $file) {
        if (!is_array($file) || (string) ($file['path'] ?? '') === '') {
            continue;
        }
        $live[scan_item_key($file)] = true;
    }

    $walked = [];
    foreach (is_array($job['walked_roots'] ?? null) ? $job['walked_roots'] : [] as $root) {
        $root = (string) $root;
        if ($root !== '') {
            $walked[$root] = true;
        }
    }
    if ($walked === []) {
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $root = (string) ($file['root'] ?? '');
            if ($root !== '') {
                $walked[$root] = true;
            }
        }
    }

    $configured = [];
    foreach (is_array($job['roots'] ?? null) ? $job['roots'] : [] as $root) {
        $root = function_exists('settings_normalize_path')
            ? settings_normalize_path((string) $root)
            : (string) $root;
        if ($root !== '') {
            $configured[$root] = true;
        }
    }

    $processed = [];
    foreach (is_array($job['items'] ?? null) ? $job['items'] : [] as $item) {
        if (!is_array($item) || (string) ($item['path'] ?? '') === '') {
            continue;
        }
        $processed[scan_item_key($item)] = $item;
    }

    $out = [];
    $seen = [];
    foreach ($processed as $key => $item) {
        $out[] = $item;
        $seen[$key] = true;
    }

    $old = cache_read_library();
    foreach (is_array($old['items'] ?? null) ? $old['items'] : [] as $item) {
        if (!is_array($item) || (string) ($item['path'] ?? '') === '') {
            continue;
        }
        $key = scan_item_key($item);
        if (isset($seen[$key])) {
            continue;
        }
        $root = (string) ($item['root'] ?? '');
        if ($root !== '' && isset($walked[$root])) {
            if (!isset($live[$key])) {
                continue;
            }
            $out[] = $item;
            $seen[$key] = true;
            continue;
        }
        if ($root !== '' && isset($configured[$root])) {
            $out[] = $item;
            $seen[$key] = true;
        }
    }
    return $out;
}

function scan_job_strip_excluded(): void
{
    $job = scan_job_read();
    if (($job['state'] ?? '') !== 'running') {
        return;
    }
    $excludes = settings_video_excludes();
    if ($excludes === []) {
        return;
    }
    $changed = false;
    $droppedIds = [];
    if (isset($job['files']) && is_array($job['files'])) {
        $files = [];
        foreach ($job['files'] as $file) {
            if (!is_array($file)) {
                continue;
            }
            if (settings_path_is_excluded(format_source_path($file), $excludes)) {
                $changed = true;
                continue;
            }
            $files[] = $file;
        }
        $job['files'] = $files;
    }
    if (isset($job['items']) && is_array($job['items'])) {
        $items = [];
        foreach ($job['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (settings_item_excluded($item, $excludes, [])) {
                $id = (string) ($item['id'] ?? '');
                if ($id !== '') {
                    $droppedIds[$id] = true;
                }
                $changed = true;
                continue;
            }
            $items[] = $item;
        }
        $job['items'] = $items;
    }
    if (isset($job['grok_queue']) && is_array($job['grok_queue'])) {
        $queue = [];
        $removedBeforeIndex = 0;
        $gIndex = (int) ($job['grok_index'] ?? 0);
        foreach ($job['grok_queue'] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && isset($droppedIds[$id])) {
                if ($i < $gIndex) {
                    $removedBeforeIndex++;
                }
                $changed = true;
                continue;
            }
            $queue[] = $row;
        }
        $job['grok_queue'] = $queue;
        $job['grok_index'] = max(0, $gIndex - $removedBeforeIndex);
        $job['grok_index'] = min((int) $job['grok_index'], count($queue));
    }
    if ($changed) {
        scan_job_write($job);
    }
}

function scan_count_dropped_items(array $before, array $after): int
{
    $keep = [];
    foreach ($after as $item) {
        if (is_array($item)) {
            $keep[scan_item_key($item)] = true;
        }
    }
    $n = 0;
    foreach ($before as $item) {
        if (!is_array($item) || (string) ($item['path'] ?? '') === '') {
            continue;
        }
        if (!isset($keep[scan_item_key($item)])) {
            $n++;
        }
    }
    return $n;
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
    $parsed = scan_parse_path((string) ($item['root'] ?? ''), $item['path'], (string) ($item['category'] ?? ''));
    $item['season'] = $parsed['season'];
    $item['episode'] = $parsed['episode'];
    $item['episode_title'] = (string) ($parsed['episode_title'] ?? '');
    $item['part'] = (string) ($parsed['part'] ?? '');
    if (!library_has_user_kind($item)) {
        $item['kind'] = (string) ($parsed['kind'] ?? $item['kind'] ?? 'movie');
        if ($parsed['title'] !== '') {
            $item['title'] = $parsed['title'];
            if (($item['status'] ?? '') !== 'matched') {
                $item = library_set_auto_title($item, $parsed['title']);
            }
        }
    }
    if (library_item_kind($item) === 'show') {
        $item['grouped'] = true;
    } elseif (!library_has_user_grouped($item)) {
        $item['grouped'] = !empty($parsed['grouped']);
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
        'paused' => false,
        'grok_queued' => 0,
        'grok_attempted' => 0,
        'grok_matched' => 0,
        'grok_cleared' => 0,
        'grok_skipped' => 0,
        'grok_left' => 0,
        'grok_batches' => 0,
        'grok_prompt_tokens' => 0,
        'grok_completion_tokens' => 0,
        'grok_cached_tokens' => 0,
        'grok_cost_usd' => 0.0,
        'grok_cost_label' => '$0.0000',
        'grok_enabled' => false,
        'grok_pause' => true,
        'kind' => 'scan',
        'catalog' => 'video',
        'lookup_targets' => scan_options_defaults(),
    ];
}

function scan_catalog_normalize(string $catalog): string
{
    return $catalog === 'music' ? 'music' : 'video';
}

function scan_catalog_label(string $catalog): string
{
    return scan_catalog_normalize($catalog) === 'music' ? 'music' : 'video';
}

function scan_catalog_title(string $catalog): string
{
    return scan_catalog_normalize($catalog) === 'music' ? 'Music' : 'Video';
}

function scan_job_catalog(array $job): string
{
    return scan_catalog_normalize((string) ($job['catalog'] ?? 'video'));
}

function scan_lock_path(): string
{
    return CACHE_DIR . '/scan.lock';
}

/** @return resource|false */
function scan_lock_open()
{
    return @fopen(scan_lock_path(), 'c');
}

/** @param resource|false $lock */
function scan_lock_try($lock): bool
{
    return is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB);
}

/** @param resource|false $lock */
function scan_lock_release($lock): void
{
    if (!is_resource($lock)) {
        return;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

function scan_busy_status(): array
{
    $scan = scan_status_read();
    if (($scan['state'] ?? '') === 'running') {
        return array_merge($scan, [
            'ok' => true,
            'busy' => true,
            'kind' => 'scan',
        ]);
    }
    if (function_exists('grok_status_read')) {
        $grok = grok_status_read();
        if (($grok['state'] ?? '') === 'running') {
            return array_merge($grok, [
                'ok' => true,
                'busy' => true,
                'kind' => 'grok',
            ]);
        }
    }
    return array_merge($scan, [
        'ok' => true,
        'busy' => true,
        'kind' => 'scan',
    ]);
}

function scan_options_defaults(): array
{
    return [
        'unidentified' => true,
        'unmatched' => true,
        'matched_grok' => false,
        'matched_direct' => false,
    ];
}

function scan_options_path(string $catalog = 'video'): string
{
    return CACHE_DIR . '/scan-options-' . scan_catalog_normalize($catalog) . '.json';
}

function scan_option_bool(mixed $value): bool
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

function scan_options_read(string $catalog = 'video'): array
{
    $defaults = scan_options_defaults();
    $data = function_exists('cache_read_json') ? cache_read_json(scan_options_path($catalog)) : null;
    if (!is_array($data)) {
        return $defaults;
    }
    foreach ($defaults as $key => $fallback) {
        if (array_key_exists($key, $data)) {
            $defaults[$key] = scan_option_bool($data[$key]);
        }
    }
    return $defaults;
}

function scan_options_write(string $catalog, array $options): array
{
    $next = scan_options_write_normalize($options);
    if (function_exists('cache_write_atomic')) {
        cache_write_atomic(scan_options_path($catalog), $next);
    }
    return $next;
}

function scan_options_summary(array $targets): string
{
    $labels = [
        'unidentified' => 'unidentified',
        'unmatched' => 'unmatched',
        'matched_grok' => 'matched (Grok)',
        'matched_direct' => 'matched (Direct)',
    ];
    $on = [];
    foreach ($labels as $key => $label) {
        if (!empty($targets[$key])) {
            $on[] = $label;
        }
    }
    return $on === [] ? 'no file states' : implode(', ', $on);
}

function scan_lookup_targets_from(array $job): array
{
    $targets = $job['lookup_targets'] ?? null;
    if (is_array($targets)) {
        return scan_options_write_normalize($targets);
    }
    $mode = scan_normalize_mode((string) ($job['mode'] ?? 'retry'));
    $defaults = scan_options_defaults();
    if ($mode === 'unidentified') {
        $defaults['unmatched'] = false;
    }
    return $defaults;
}

function scan_options_write_normalize(array $options): array
{
    $next = scan_options_defaults();
    foreach ($next as $key => $_) {
        if (array_key_exists($key, $options)) {
            $next[$key] = scan_option_bool($options[$key]);
        }
    }
    return $next;
}

function scan_grok_cost_fields(array $job): array
{
    $usd = (float) ($job['grok_cost_usd'] ?? 0);
    $label = function_exists('grok_format_usd') ? grok_format_usd($usd) : ('$' . number_format($usd, 4, '.', ''));
    return [
        'grok_batches' => (int) ($job['grok_batches'] ?? 0),
        'grok_prompt_tokens' => (int) ($job['grok_prompt_tokens'] ?? 0),
        'grok_completion_tokens' => (int) ($job['grok_completion_tokens'] ?? 0),
        'grok_cached_tokens' => (int) ($job['grok_cached_tokens'] ?? 0),
        'grok_cost_usd' => $usd,
        'grok_cost_label' => $label,
    ];
}

function scan_status_read(): array
{
    $data = cache_read_json(scan_status_path());
    if (!is_array($data)) {
        $data = scan_status_defaults();
    } else {
        $data = array_merge(scan_status_defaults(), $data);
    }
    if (function_exists('grok_live_enabled')) {
        $data['grok_enabled'] = grok_live_enabled();
    }
    $pause = function_exists('grok_dev_pause_each_batch') ? grok_dev_pause_each_batch() : true;
    $data['grok_pause'] = $pause;
    if (!$pause || !empty($data['cancel_requested'])) {
        $data['paused'] = false;
    } elseif (($data['state'] ?? '') === 'running' && empty($data['paused'])) {
        $job = function_exists('scan_job_read') ? scan_job_read() : [];
        if (($job['state'] ?? '') === 'running' && !empty($job['wait_continue'])) {
            $data['paused'] = true;
        }
    }
    $data['kind'] = 'scan';
    $data['catalog'] = scan_catalog_normalize((string) ($data['catalog'] ?? 'video'));
    $data['lookup_targets'] = scan_lookup_targets_from(['lookup_targets' => $data['lookup_targets'] ?? null, 'mode' => $data['mode'] ?? 'retry']);
    return $data;
}

function scan_normalize_mode(string $mode): string
{
    return $mode === 'unidentified' ? 'unidentified' : 'retry';
}

function scan_file_needs_lookup(?array $prev, bool $unchanged, array $targets): bool
{
    if ($prev === null) {
        return !empty($targets['unidentified']);
    }
    if (library_is_manual($prev)) {
        return false;
    }
    $status = library_item_status($prev);
    if ($status === 'unidentified') {
        return !empty($targets['unidentified']);
    }
    if ($status === 'unmatched') {
        return !empty($targets['unmatched']);
    }
    if ($status === 'matched') {
        $source = library_match_source($prev);
        if ($source === 'grok') {
            return !empty($targets['matched_grok']);
        }
        if ($source === 'direct') {
            return !empty($targets['matched_direct']);
        }
        return false;
    }
    return !empty($targets['unidentified']);
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

function scan_lookup_message(int $found, int $pending, string $catalog = 'video'): string
{
    $noun = scan_catalog_label($catalog);
    if ($pending <= 0) {
        return 'Refreshing ' . $noun . ' catalog…';
    }
    return 'Looking up ' . $noun . ' titles on TMDB: found ' . $found . ' of ' . $pending . '.';
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

function scan_job_write_library(array $job): void
{
    $items = scan_job_catalog_items($job);
    $library = [
        'version' => 1,
        'scanned_at' => time(),
        'video_roots' => $job['roots'] ?? settings_video_roots(),
        'items' => $items,
    ];
    if (!cache_write_library($library)) {
        throw new RuntimeException('Could not write the video catalog database. Check cache/ permissions.');
    }
    if (function_exists('cache_prune_orphan_titles')) {
        cache_prune_orphan_titles($items);
    }
}

function scan_queue_grok(array &$job, array $item, string $mode): void
{
    if (($item['id'] ?? '') === '') {
        return;
    }
    if (!isset($job['grok_queue']) || !is_array($job['grok_queue'])) {
        $job['grok_queue'] = [];
    }
    $job['grok_queue'][] = [
        'id' => (string) $item['id'],
        'mode' => $mode === 'verify' ? 'verify' : 'choose',
    ];
}

function scan_grok_pending(array $job): int
{
    $queue = is_array($job['grok_queue'] ?? null) ? $job['grok_queue'] : [];
    return max(0, count($queue) - (int) ($job['grok_index'] ?? 0));
}

function scan_grok_batch_size(): int
{
    return function_exists('grok_batch_size') ? grok_batch_size() : 25;
}

function scan_grok_on(): bool
{
    return function_exists('grok_live_enabled') && grok_live_enabled();
}

function scan_grok_batch_ready(array $job, bool $filesDone = false): bool
{
    if (!scan_grok_on()) {
        return false;
    }
    $pending = scan_grok_pending($job);
    if ($pending < 1) {
        return false;
    }
    if ($filesDone) {
        return true;
    }
    return $pending >= scan_grok_batch_size();
}

function scan_job_publish(array $job, array $extra = []): void
{
    $stopping = scan_cancelled() || !empty($job['cancel']);
    $files = $job['files'] ?? [];
    $total = is_array($files) ? count($files) : 0;
    $pending = (int) ($job['pending'] ?? 0);
    $found = (int) ($job['found'] ?? 0);
    $phase = (string) ($job['phase'] ?? 'lookup');
    $grokQueue = is_array($job['grok_queue'] ?? null) ? $job['grok_queue'] : [];
    $grokIndex = (int) ($job['grok_index'] ?? 0);
    $grokLeft = max(0, count($grokQueue) - $grokIndex);
    $pauseEach = function_exists('grok_dev_pause_each_batch') && grok_dev_pause_each_batch();
    $paused = !empty($job['wait_continue']) && !$stopping && $pauseEach;
    $catalog = scan_job_catalog($job);
    $noun = scan_catalog_label($catalog);
    $message = (string) ($job['message'] ?? '');
    if ($message === '') {
        if ($stopping) {
            $message = 'Stop requested. Saving ' . $noun . ' catalog…';
        } elseif ($phase === 'walk') {
            $message = 'Walking ' . $noun . ' folders…';
        } else {
            $message = scan_lookup_message($found, $pending, $catalog);
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
        'catalog' => $catalog,
        'lookup_targets' => scan_lookup_targets_from($job),
        'message' => $message,
        'paused' => $paused,
        'kind' => 'scan',
        'grok_queued' => count($grokQueue),
        'grok_attempted' => (int) ($job['grok_attempted'] ?? 0),
        'grok_matched' => (int) ($job['grok_matched'] ?? 0),
        'grok_cleared' => (int) ($job['grok_cleared'] ?? 0),
        'grok_skipped' => (int) ($job['grok_skipped'] ?? 0),
        'grok_left' => $grokLeft,
        'grok_enabled' => function_exists('grok_live_enabled') && grok_live_enabled(),
        'grok_pause' => $pauseEach,
    ], scan_grok_cost_fields($job), $extra));
}

function scan_job_begin(string $mode = 'retry', string $catalog = 'video'): array
{
    cache_init();
    if (!cache_writable()) {
        throw new RuntimeException('cache/ is not writable. Grant the http user write access.');
    }

    $catalog = scan_catalog_normalize($catalog);
    if ($catalog === 'music') {
        throw new RuntimeException('Music scanning is not available yet.');
    }
    $mode = scan_normalize_mode($mode);
    $targets = scan_options_read($catalog);
    $title = scan_catalog_title($catalog);
    $noun = scan_catalog_label($catalog);
    scan_clear_cancel();
    $roots = settings_video_roots();
    if ($roots === []) {
        throw new RuntimeException('No video folders are configured. Add at least one in Config.');
    }

    $started = time();
    if (function_exists('app_log')) {
        app_log('scan', $title . ' scan started (' . scan_options_summary($targets) . ').', [
            'mode' => $mode,
            'catalog' => $catalog,
            'lookup_targets' => $targets,
        ]);
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
        'catalog' => $catalog,
        'lookup_targets' => $targets,
        'paused' => false,
        'grok_queued' => 0,
        'grok_attempted' => 0,
        'grok_matched' => 0,
        'grok_cleared' => 0,
        'grok_skipped' => 0,
        'grok_left' => 0,
        'grok_batches' => 0,
        'grok_prompt_tokens' => 0,
        'grok_completion_tokens' => 0,
        'grok_cached_tokens' => 0,
        'grok_cost_usd' => 0.0,
        'grok_cost_label' => '$0.0000',
        'message' => 'Walking ' . $noun . ' folders…',
    ]);

    $files = [];
    $absentRoots = [];
    $walkedRoots = [];
    if (function_exists('source_presence_refresh')) {
        source_presence_refresh('video', $roots);
    }
    foreach ($roots as $root) {
        if (scan_cancelled()) {
            break;
        }
        $norm = settings_normalize_path($root);
        $present = function_exists('source_is_present') ? source_is_present($root) : (is_dir($root) && is_readable($root));
        if (!$present) {
            $absentRoots[] = $norm;
            if (function_exists('app_log')) {
                app_log('scan', 'Skipping absent source: ' . $norm);
            }
            continue;
        }
        $walkedRoots[] = $norm;
        $base = count($files);
        $batch = scan_files($root, null, static function (int $n) use ($base, $started, $noun, $catalog, $targets): void {
            $count = $base + $n;
            scan_status_write([
                'state' => 'running',
                'phase' => 'walk',
                'started_at' => $started,
                'processed' => $count,
                'catalog' => $catalog,
                'lookup_targets' => $targets,
                'message' => 'Walking ' . $noun . ' folders… ' . $count . ' files found.',
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
            'catalog' => $catalog,
            'lookup_targets' => $targets,
            'message' => 'Walking ' . $noun . ' folders… ' . $walked . ' files found.',
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
            'catalog' => $catalog,
            'lookup_targets' => $targets,
            'message' => $title . ' scan stopped during the folder walk. The catalog was left as it was.',
        ]);
        if (function_exists('app_log')) {
            app_log('scan', $title . ' scan stopped during the folder walk.', ['files' => count($files)], 'warn');
        }
        return scan_status_read();
    }

    if ($walkedRoots === [] && $absentRoots !== []) {
        $msg = 'All ' . $noun . ' sources are absent. Catalog unchanged.';
        scan_status_write([
            'state' => 'done',
            'phase' => 'done',
            'cancel_requested' => false,
            'started_at' => $started,
            'catalog' => $catalog,
            'lookup_targets' => $targets,
            'message' => $msg,
        ]);
        if (function_exists('app_log')) {
            app_log('scan', $msg, ['absent' => $absentRoots]);
        }
        return scan_status_read();
    }

    folder_learn_from_files($files, $walkedRoots);

    $old = cache_read_library();
    [$oldByPath] = scan_index_library($old);
    $pending = 0;
    foreach ($files as $file) {
        $root = (string) ($file['root'] ?? '');
        $prev = $oldByPath[$root . "\n" . $file['path']] ?? $oldByPath[$file['path']] ?? null;
        $unchanged = $prev
            && (int) ($prev['mtime'] ?? 0) === $file['mtime']
            && (int) ($prev['size'] ?? 0) === $file['size'];
        if (scan_file_needs_lookup($prev, $unchanged, $targets)) {
            $pending++;
        }
    }

    $job = [
        'state' => 'running',
        'phase' => 'lookup',
        'mode' => $mode,
        'catalog' => $catalog,
        'lookup_targets' => $targets,
        'started_at' => $started,
        'roots' => $roots,
        'walked_roots' => $walkedRoots,
        'files' => $files,
        'index' => 0,
        'items' => [],
        'lookups' => 0,
        'found' => 0,
        'unmatched' => 0,
        'unidentified' => 0,
        'reused' => 0,
        'pending' => $pending,
        'grok_queue' => [],
        'grok_index' => 0,
        'grok_attempted' => 0,
        'grok_matched' => 0,
        'grok_cleared' => 0,
        'grok_skipped' => 0,
        'grok_batches' => 0,
        'grok_prompt_tokens' => 0,
        'grok_completion_tokens' => 0,
        'grok_cached_tokens' => 0,
        'grok_cost_usd' => 0.0,
        'tmdb_item' => 0,
        'wait_continue' => false,
        'message' => scan_lookup_message(0, $pending, $catalog),
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
    $targets = scan_lookup_targets_from($job);
    $prev = $oldByPath[$root . "\n" . $path] ?? $oldByPath[$path] ?? null;
    $unchanged = $prev
        && (int) ($prev['mtime'] ?? 0) === (int) ($file['mtime'] ?? 0)
        && (int) ($prev['size'] ?? 0) === (int) ($file['size'] ?? 0);
    $needsLookup = scan_file_needs_lookup($prev, $unchanged, $targets);

    if ($prev && (!$needsLookup || $skipLookup)) {
        $kept = library_carry_file($prev, $file);
        $job['items'][] = $kept;
        $job['reused'] = (int) $job['reused'] + 1;
        scan_tally_status($job, library_item_status($kept));
        return false;
    }

    $category = settings_category_for_root($root);
    $parsed = scan_parse_path($root, $path, $category);
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
        'grouped' => library_item_kind(['kind' => (string) ($parsed['kind'] ?? 'movie')]) === 'show' || !empty($parsed['grouped']),
        'episode_title' => (string) ($parsed['episode_title'] ?? ''),
        'part' => (string) ($parsed['part'] ?? ''),
        'category' => $category,
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
        if (library_item_hidden($prev)) {
            $item['hidden'] = true;
        }
        if (library_has_user_kind($prev)) {
            $item['kind'] = library_item_kind($prev);
            $item['kind_source'] = 'user';
            if (!empty($prev['title'])) {
                $item['title'] = (string) $prev['title'];
            }
        }
        if (library_has_user_grouped($prev) && library_item_kind($item) !== 'show') {
            $item['grouped'] = library_item_grouped($prev);
            $item['grouped_source'] = 'user';
        } elseif (library_item_kind($item) === 'show') {
            $item['grouped'] = true;
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

    if ($skipLookup || !$needsLookup || !tmdb_has_key() || !function_exists('curl_init')) {
        $item['status'] = 'unidentified';
        $item['match_source'] = 'none';
        scan_tally_status($job, 'unidentified');
        $job['items'][] = $item;
        return false;
    }

    if (function_exists('grok_live_enabled')) {
        grok_live_enabled();
    }

    $filename = (string) ($file['filename'] ?? $path);
    $job['tmdb_item'] = (int) ($job['tmdb_item'] ?? 0) + 1;
    $itemNo = (int) $job['tmdb_item'];
    if (function_exists('tmdb_scan_item_begin')) {
        tmdb_scan_item_begin($itemNo, $filename, $path);
    }
    if (function_exists('app_log')) {
        app_log('tmdb', 'TMDB item ' . $itemNo . ': ' . $filename);
    }
    try {
        $payload = tmdb_search_first_results($title, $year, $filename, tmdb_search_meta_from_item($item));
        $job['lookups'] = (int) $job['lookups'] + 1;
        $query = (string) ($payload['query'] ?? '');
        $weak = $query !== '' && function_exists('search_query_is_weak') && search_query_is_weak($query);
        $cands = [];
        foreach ($payload['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (int) ($row['tmdb_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $cands[] = [
                'id' => $cid,
                'title' => (string) ($row['title'] ?? ''),
                'year' => $row['year'] ?? null,
                'media_type' => (string) ($row['media_type'] ?? 'movie'),
            ];
            if (count($cands) >= (function_exists('tmdb_shortlist_limit') ? tmdb_shortlist_limit() : 6)) {
                break;
            }
        }
        $usable = ($weak || $cands === []) ? [] : $cands;
        $item['tmdb_candidates'] = $usable;

        if ($usable === []) {
            $item['status'] = 'unmatched';
            $item['match_source'] = 'none';
            $job['unmatched'] = (int) $job['unmatched'] + 1;
            scan_queue_grok($job, $item, 'choose');
            if (function_exists('app_log')) {
                app_log('tmdb', 'TMDB item ' . $itemNo . ' no usable hits; queued for Grok (' . scan_grok_pending($job) . ' waiting) file=' . $filename);
            }
            $job['items'][] = $item;
            return true;
        }

        if (count($usable) === 1) {
            $tmdbId = (int) $usable[0]['id'];
            $meta = cache_read_title($tmdbId);
            if ($meta === null) {
                $mediaType = (string) ($usable[0]['media_type'] ?? (($item['kind'] ?? '') === 'show' ? 'tv' : 'movie'));
                $meta = tmdb_fetch_details($tmdbId, $mediaType);
                if ($meta !== null) {
                    cache_write_title($tmdbId, $meta);
                }
                usleep(TMDB_REQUEST_SLEEP_US);
            }
            if ($meta !== null && !empty($meta['tmdb_id'])) {
                $item['tmdb_id'] = (int) $meta['tmdb_id'];
                $item['status'] = 'matched';
                $item['poster_path'] = $meta['poster_path'] ?? null;
                $item = library_set_auto_title($item, (string) ($meta['title'] ?? $title));
                if (!empty($meta['year'])) {
                    $item['year'] = (int) $meta['year'];
                }
                $item['genres'] = cache_string_list($meta['genres'] ?? []);
                $item['match_source'] = 'tmdb';
                $knownMatches[$key] = $item;
                $knownMatches[scan_match_key($item['display_title'], $item['year'])] = $item;
                $job['found'] = (int) $job['found'] + 1;
                if (defined('GROK_VERIFY_SINGLES') && GROK_VERIFY_SINGLES) {
                    scan_queue_grok($job, $item, 'verify');
                }
                $job['items'][] = $item;
                return true;
            }
            $item['status'] = 'unmatched';
            $item['match_source'] = 'none';
            $job['unmatched'] = (int) $job['unmatched'] + 1;
            scan_queue_grok($job, $item, 'choose');
            $job['items'][] = $item;
            return true;
        }

        $item['status'] = 'unmatched';
        $item['match_source'] = 'none';
        $job['unmatched'] = (int) $job['unmatched'] + 1;
        scan_queue_grok($job, $item, 'choose');
        if (function_exists('app_log')) {
            app_log('tmdb', 'TMDB item ' . $itemNo . ' queued for Grok (' . scan_grok_pending($job) . ' waiting) file=' . $filename);
        }
        $job['items'][] = $item;
        return true;
    } finally {
        if (function_exists('tmdb_scan_item_end')) {
            tmdb_scan_item_end();
        }
    }
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

    $beforeItems = is_array($old['items'] ?? null) ? $old['items'] : [];
    $items = scan_job_catalog_items($job);
    $dropped = scan_count_dropped_items($beforeItems, $items);
    $library = [
        'version' => 1,
        'scanned_at' => time(),
        'video_roots' => $job['roots'] ?? settings_video_roots(),
        'items' => $items,
    ];
    if (!cache_write_library($library)) {
        throw new RuntimeException('Could not write the video catalog database. Check cache/ permissions.');
    }
    $orphans = function_exists('cache_prune_orphan_titles') ? cache_prune_orphan_titles($items) : 0;
    if ($dropped > 0 && function_exists('app_log')) {
        app_log('scan', 'Removed ' . $dropped . ' missing file' . ($dropped === 1 ? '' : 's') . ' from the catalog.', [
            'dropped' => $dropped,
            'title_cache' => $orphans,
        ]);
    }

    $found = (int) ($job['found'] ?? 0);
    $pending = (int) ($job['pending'] ?? 0);
    scan_job_clear();
    scan_clear_cancel();
    scan_status_write(array_merge([
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
        'paused' => false,
        'grok_matched' => (int) ($job['grok_matched'] ?? 0),
        'grok_attempted' => (int) ($job['grok_attempted'] ?? 0),
        'grok_cleared' => (int) ($job['grok_cleared'] ?? 0),
        'grok_queued' => is_array($job['grok_queue'] ?? null) ? count($job['grok_queue']) : 0,
        'grok_left' => 0,
        'catalog' => scan_job_catalog($job),
        'lookup_targets' => scan_lookup_targets_from($job),
        'message' => ($cancelled ? scan_catalog_title(scan_job_catalog($job)) . ' scan stopped. ' : scan_catalog_title(scan_job_catalog($job)) . ' scan finished. ')
            . 'TMDB found ' . $found . '. Grok matched ' . (int) ($job['grok_matched'] ?? 0)
            . ' of ' . (int) ($job['grok_attempted'] ?? 0) . '.',
    ], scan_grok_cost_fields($job)));
    if (function_exists('catalog_store_record_scan')) {
        catalog_store_record_scan('video', $job, $cancelled);
    }
    $batches = (int) ($job['grok_batches'] ?? 0);
    if ($batches > 0 && function_exists('app_log')) {
        $usd = (float) ($job['grok_cost_usd'] ?? 0);
        $label = function_exists('grok_format_usd') ? grok_format_usd($usd) : ('$' . number_format($usd, 4, '.', ''));
        app_log('grok', 'Grok done batches=' . $batches
            . ' prompt=' . (int) ($job['grok_prompt_tokens'] ?? 0)
            . ' completion=' . (int) ($job['grok_completion_tokens'] ?? 0)
            . ' cached=' . (int) ($job['grok_cached_tokens'] ?? 0)
            . ' est=' . $label);
    }
    if (function_exists('app_log')) {
        app_log('scan', ($cancelled ? scan_catalog_title(scan_job_catalog($job)) . ' scan stopped. ' : scan_catalog_title(scan_job_catalog($job)) . ' scan finished. ')
            . 'Found ' . $found . ' of ' . $pending . ', lookups ' . (int) ($job['lookups'] ?? 0) . '.', [
            'found' => $found,
            'pending' => $pending,
            'lookups' => (int) ($job['lookups'] ?? 0),
            'cancelled' => $cancelled,
        ], $cancelled ? 'warn' : 'info');
    }
    return scan_status_read();
}

function scan_job_continue(bool $resumePause = false): array
{
    $job = scan_job_read();
    if (($job['state'] ?? '') !== 'running') {
        return scan_status_read();
    }

    if (scan_cancelled()) {
        $job['cancel'] = true;
        $job['phase'] = 'stopping';
        $job['message'] = 'Stop requested. Saving ' . scan_catalog_label(scan_job_catalog($job)) . ' catalog…';
        scan_job_publish($job);
        scan_job_write_library($job);
        return scan_job_finalize($job, true);
    }

    $pauseOn = function_exists('grok_dev_pause_each_batch') && grok_dev_pause_each_batch();
    if (!empty($job['wait_continue'])) {
        if ($pauseOn && !$resumePause) {
            $job['phase'] = 'lookup';
            scan_job_write($job);
            scan_job_publish($job, ['paused' => true]);
            $status = scan_status_read();
            $status['paused'] = true;
            return $status;
        }
        $job['wait_continue'] = false;
        $job['phase'] = 'lookup';
        $job['message'] = 'Continuing ' . scan_catalog_label(scan_job_catalog($job)) . ' scan…';
        scan_job_write($job);
    }

    $files = is_array($job['files'] ?? null) ? $job['files'] : [];
    $total = count($files);
    $index = (int) ($job['index'] ?? 0);
    $filesDone = $index >= $total;

    if (scan_grok_batch_ready($job, $filesDone)) {
        return scan_job_run_grok_batch($job, $filesDone);
    }
    if ($filesDone) {
        return scan_job_finalize($job, false);
    }

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

    $lookupDeadline = hrtime(true) + 1800000000;
    $drainDeadline = hrtime(true) + 8000000000;
    $lookupsThisTick = 0;
    $progress = 0;
    $pending = (int) ($job['pending'] ?? 0);
    $lookupsDone = (int) ($job['lookups'] ?? 0);
    $draining = $pending > 0 && $lookupsDone >= $pending;

    while ($index < $total) {
        if ($progress > 0 && scan_cancelled()) {
            $job['index'] = $index;
            $job['cancel'] = true;
            scan_job_write($job);
            scan_job_write_library($job);
            return scan_job_finalize($job, true);
        }
        if ($lookupsThisTick >= 3) {
            break;
        }
        if ($lookupsThisTick > 0 && hrtime(true) >= $lookupDeadline) {
            break;
        }
        if ($lookupsThisTick === 0 && $progress > 0 && hrtime(true) >= $drainDeadline) {
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
            $draining = false;
            $waiting = scan_grok_pending($job);
            $job['message'] = scan_lookup_message((int) $job['found'], (int) $job['pending'], scan_job_catalog($job));
            if ($waiting > 0) {
                $job['message'] .= ' Grok queue ' . $waiting . '/' . scan_grok_batch_size() . '.';
            }
            scan_job_publish($job);
            if (scan_grok_batch_ready($job, $index >= $total)) {
                scan_job_write($job);
                return scan_job_run_grok_batch($job, $index >= $total);
            }
        } elseif ($draining && $progress === 1) {
            $job['message'] = 'Updating ' . scan_catalog_label(scan_job_catalog($job)) . ' catalog… ' . $index . ' / ' . $total . ' files.';
            scan_job_publish($job);
        }
    }

    if ($index >= $total) {
        if (scan_grok_batch_ready($job, true)) {
            scan_job_write($job);
            return scan_job_run_grok_batch($job, true);
        }
        return scan_job_finalize($job, false);
    }

    if ($draining) {
        $job['message'] = 'Updating ' . scan_catalog_label(scan_job_catalog($job)) . ' catalog… ' . $index . ' / ' . $total . ' files.';
    } else {
        $waiting = scan_grok_pending($job);
        $job['message'] = scan_lookup_message((int) $job['found'], (int) $job['pending'], scan_job_catalog($job));
        if ($waiting > 0) {
            $job['message'] .= ' Grok queue ' . $waiting . '/' . scan_grok_batch_size() . '.';
        }
    }
    scan_job_write($job);
    scan_job_publish($job, ['paused' => false]);
    return scan_status_read();
}

function scan_job_run_grok_batch(array $job, bool $filesDone): array
{
    if (scan_cancelled()) {
        scan_job_write_library($job);
        return scan_job_finalize($job, true);
    }
    if (!scan_grok_on()) {
        if ($filesDone) {
            return scan_job_finalize($job, false);
        }
        $job['phase'] = 'lookup';
        $job['wait_continue'] = false;
        scan_job_write($job);
        scan_job_publish($job, ['paused' => false]);
        return scan_status_read();
    }
    $pending = scan_grok_pending($job);
    if ($pending < 1) {
        if ($filesDone) {
            return scan_job_finalize($job, false);
        }
        $job['phase'] = 'lookup';
        scan_job_write($job);
        scan_job_publish($job, ['paused' => false]);
        return scan_status_read();
    }

    $batch = min($pending, scan_grok_batch_size());
    $job['phase'] = 'grok';
    $job['wait_continue'] = false;
    $job['message'] = 'Grok: resolving ' . $batch . ' queued titles…';
    scan_job_write($job);
    scan_job_publish($job, ['paused' => false]);
    if (function_exists('app_log')) {
        app_log('grok', 'Grok batch starting (' . $batch . ' titles, ' . $pending . ' waiting).');
    }

    if (function_exists('grok_run_scan_batch')) {
        grok_run_scan_batch($job);
    }
    scan_job_write_library($job);

    $left = scan_grok_pending($job);
    $moreWork = !$filesDone || $left > 0;
    $pause = $moreWork
        && function_exists('grok_dev_pause_each_batch')
        && grok_dev_pause_each_batch();

    if (!$moreWork) {
        $job['phase'] = 'lookup';
        $job['wait_continue'] = false;
        $job['message'] = 'Grok finished. Matched ' . (int) ($job['grok_matched'] ?? 0)
            . ' of ' . (int) ($job['grok_attempted'] ?? 0) . '.';
        scan_job_write($job);
        return scan_job_finalize($job, false);
    }

    $job['phase'] = 'lookup';
    if ($pause) {
        $job['wait_continue'] = true;
        $job['message'] = 'Grok batch done. Matched ' . (int) ($job['grok_matched'] ?? 0)
            . ' of ' . (int) ($job['grok_attempted'] ?? 0)
            . ' (' . $left . ' Grok left). Press Continue.';
        scan_job_write($job);
        scan_job_publish($job, ['paused' => true]);
        if (function_exists('app_log')) {
            app_log('grok', (string) $job['message']);
        }
        $status = scan_status_read();
        $status['paused'] = true;
        return $status;
    }

    $job['wait_continue'] = false;
    $job['message'] = 'Grok batch done. Continuing ' . scan_catalog_label(scan_job_catalog($job)) . ' TMDB…';
    scan_job_write($job);
    scan_job_publish($job, ['paused' => false]);
    $status = scan_status_read();
    $status['paused'] = false;
    return $status;
}

function scan_job_continue_grok(array $job): array
{
    $files = is_array($job['files'] ?? null) ? $job['files'] : [];
    $filesDone = (int) ($job['index'] ?? 0) >= count($files);
    return scan_job_run_grok_batch($job, $filesDone);
}

function scan_grok_is_running(): bool
{
    if (!function_exists('grok_status_read')) {
        return false;
    }
    return (grok_status_read()['state'] ?? '') === 'running';
}

function scan_tick(bool $allowStart = false, string $mode = 'retry', bool $resumePause = false, string $catalog = 'video'): array
{
    cache_init();
    $mode = scan_normalize_mode($mode);
    $catalog = scan_catalog_normalize($catalog);
    $job = scan_job_read();
    if (($job['state'] ?? '') === 'running') {
        return scan_job_continue($resumePause);
    }
    if (!$allowStart) {
        $status = scan_status_read();
        if (($status['state'] ?? '') === 'running') {
            $title = scan_catalog_title((string) ($status['catalog'] ?? 'video'));
            scan_status_write([
                'state' => 'error',
                'cancel_requested' => false,
                'message' => $title . ' scan interrupted. Start again from the scan page.',
            ]);
            return scan_status_read();
        }
        return $status;
    }
    return scan_job_begin($mode, $catalog);
}

function scan_build_library(?callable $progress = null): array
{
    $status = scan_tick(true);
    while (($status['state'] ?? '') === 'running') {
        if ($progress && !empty($status['message'])) {
            $progress((string) $status['message']);
        }
        $status = scan_job_continue(true);
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
    $walkedRoots = [];
    foreach ($roots as $root) {
        if (settings_path_status($root) !== 'ok') {
            continue;
        }
        $walkedRoots[settings_normalize_path($root)] = true;
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

            $category = settings_category_for_root($fileRoot);
            $parsed = scan_parse_path($fileRoot, $path, $category);
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
                'grouped' => ((string) ($parsed['kind'] ?? '') === 'show') || !empty($parsed['grouped']),
                'episode_title' => (string) ($parsed['episode_title'] ?? ''),
                'part' => (string) ($parsed['part'] ?? ''),
                'category' => $category,
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

    $configured = [];
    foreach ($roots as $root) {
        $configured[settings_normalize_path($root)] = true;
    }
    foreach ($oldItems as $item) {
        if (!is_array($item) || (string) ($item['path'] ?? '') === '') {
            continue;
        }
        $root = settings_item_root($item, $old);
        $path = (string) ($item['path'] ?? '');
        $key = $root . "\n" . $path;
        if (isset($seen[$key])) {
            continue;
        }
        if ($root !== '' && isset($walkedRoots[$root])) {
            continue;
        }
        if ($root !== '' && isset($configured[$root])) {
            $items[] = $item;
            $seen[$key] = true;
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
    if (function_exists('cache_prune_orphan_titles')) {
        cache_prune_orphan_titles($items);
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

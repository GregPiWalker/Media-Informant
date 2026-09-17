<?php
declare(strict_types=1);

/**
 * Second-pass unmatched resolver via the xAI Grok chat/completions API.
 *
 * Cost: uses grok-4-1-fast-non-reasoning (fallback grok-4-1-fast), never a
 * flagship/reasoning model. Files are batched (default 25, max 50) with a
 * short JSON payload (path, parsed title/year, up to 5 TMDB candidates).
 * A full ~2500-file unmatched pass is ~100 API calls. At about $0.20/1M
 * input and $0.50/1M output tokens, that stay well under a few dollars.
 *
 * Grok does not replace TMDB. It only runs on unmatched items after TMDB,
 * and only from an explicit resolve action — never on page load.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/scanner.php';

function grok_system_prompt(): string
{
    return "You match video filenames to TMDB records.\n"
        . "For each item pick one candidate id from that item’s list, or null.\n"
        . "Never invent an id.\n"
        . "Prefer title+year agreement when both are present.\n"
        . "Missing year is not a reason to return null if one candidate title clearly matches the filename.\n"
        . "Return null when candidates are unrelated (for example Wall E vs East of Wall).\n"
        . "Reply with JSON only:\n"
        . '[{"path":"...","tmdb_id":123 or null,"confidence":0.0-1.0,"reason":"short"}]';
}

function grok_api_key(): string
{
    return function_exists('settings_xai_key') ? settings_xai_key() : trim((string) XAI_API_KEY);
}

function grok_has_key(): bool
{
    return grok_api_key() !== '' && function_exists('curl_init');
}

function grok_batch_size(): int
{
    $n = (int) GROK_BATCH_SIZE;
    if ($n < 1) {
        $n = 25;
    }
    return min(50, $n);
}

function grok_dev_pause_each_batch(): bool
{
    return defined('GROK_DEV_PAUSE_EACH_BATCH') && GROK_DEV_PAUSE_EACH_BATCH;
}

function grok_min_confidence(): float
{
    $n = (float) GROK_MIN_CONFIDENCE;
    if ($n < 0) {
        return 0.0;
    }
    if ($n > 1) {
        return 1.0;
    }
    return $n;
}

function grok_scrub(string $text): string
{
    $key = grok_api_key();
    if ($key !== '') {
        $text = str_replace($key, '[redacted]', $text);
    }
    $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
    return $text;
}

function grok_log_path(): string
{
    return CACHE_DIR . '/grok-resolve.log';
}

function grok_log_line(string $path, ?int $tmdbId, float $confidence, string $reason): void
{
    cache_init();
    $row = json_encode([
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
        'path' => $path,
        'tmdb_id' => $tmdbId,
        'confidence' => round($confidence, 3),
        'reason' => substr($reason, 0, 80),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($row === false) {
        return;
    }
    @file_put_contents(grok_log_path(), $row . "\n", FILE_APPEND | LOCK_EX);
}

function grok_status_path(): string
{
    return CACHE_DIR . '/grok-status.json';
}

function grok_job_path(): string
{
    return CACHE_DIR . '/grok-job.json';
}

function grok_status_defaults(): array
{
    return [
        'state' => 'idle',
        'message' => '',
        'attempted' => 0,
        'matched' => 0,
        'skipped_low_confidence' => 0,
        'skipped_no_candidates' => 0,
        'invalid_rejected' => 0,
        'errors' => 0,
        'pending' => 0,
        'total' => 0,
        'http_status' => null,
        'started_at' => null,
        'updated_at' => null,
        'cancel_requested' => false,
        'kind' => 'grok',
        'paused' => false,
    ];
}

function grok_status_read(): array
{
    $data = cache_read_json(grok_status_path());
    if (!is_array($data)) {
        $data = grok_status_defaults();
    } else {
        $data = array_merge(grok_status_defaults(), $data);
    }
    if (grok_dev_pause_each_batch()
        && ($data['state'] ?? '') === 'running'
        && empty($data['cancel_requested'])) {
        $data['paused'] = true;
    }
    return $data;
}

function grok_status_write(array $patch): void
{
    $next = array_merge(grok_status_read(), $patch, ['updated_at' => time()]);
    cache_write_atomic(grok_status_path(), $next);
}

function grok_job_read(): array
{
    $data = cache_read_json(grok_job_path());
    return is_array($data) ? $data : ['state' => 'idle'];
}

function grok_job_write(array $job): void
{
    $disk = grok_job_read();
    if (!empty($disk['cancel'])) {
        $job['cancel'] = true;
    }
    cache_write_atomic(grok_job_path(), $job);
}

function grok_job_clear(): void
{
    $path = grok_job_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function grok_cancel_path(): string
{
    return CACHE_DIR . '/grok.cancel';
}

function grok_request_cancel(): void
{
    cache_init();
    $path = grok_cancel_path();
    clearstatcache(true, $path);
    @file_put_contents($path, (string) time());
    clearstatcache(true, $path);
    $job = grok_job_read();
    if (($job['state'] ?? '') === 'running') {
        $job['cancel'] = true;
        cache_write_atomic(grok_job_path(), $job);
    }
    $status = grok_status_read();
    if (($status['state'] ?? '') === 'running') {
        grok_status_write([
            'cancel_requested' => true,
            'state' => 'running',
            'message' => 'Stop requested. Finishing the current Grok batch…',
        ]);
        if (function_exists('app_log')) {
            app_log('grok', 'Stop requested for the Grok resolve.', [], 'warn');
        }
    }
}

function grok_should_stop(): bool
{
    if (grok_cancelled()) {
        return true;
    }
    $job = grok_job_read();
    return !empty($job['cancel']);
}

function grok_clear_cancel(): void
{
    $path = grok_cancel_path();
    clearstatcache(true, $path);
    if (is_file($path)) {
        @unlink($path);
        clearstatcache(true, $path);
    }
}

function grok_cancelled(): bool
{
    $path = grok_cancel_path();
    clearstatcache(true, $path);
    return is_file($path);
}

function grok_work_lock_held(): bool
{
    $path = CACHE_DIR . '/scan.lock';
    $lock = @fopen($path, 'c');
    if ($lock === false) {
        return false;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return true;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return false;
}

function grok_job_halt(array $job): array
{
    $matched = (int) ($job['matched'] ?? 0);
    $attempted = (int) ($job['attempted'] ?? 0);
    grok_job_clear();
    grok_clear_cancel();
    grok_status_write([
        'state' => 'stopped',
        'cancel_requested' => false,
        'pending' => 0,
        'attempted' => $attempted,
        'matched' => $matched,
        'skipped_low_confidence' => (int) ($job['skipped_low_confidence'] ?? 0),
        'skipped_no_candidates' => (int) ($job['skipped_no_candidates'] ?? 0),
        'invalid_rejected' => (int) ($job['invalid_rejected'] ?? 0),
        'errors' => (int) ($job['errors'] ?? 0),
        'started_at' => $job['started_at'] ?? null,
        'kind' => 'grok',
        'message' => 'Grok stopped. Matched ' . $matched . ' of ' . $attempted . ' unmatched titles.',
    ]);
    if (function_exists('app_log')) {
        app_log('grok', 'Grok stopped. Matched ' . $matched . ' of ' . $attempted . ' unmatched titles.', [
            'matched' => $matched,
            'attempted' => $attempted,
        ], 'warn');
    }
    return grok_status_read();
}

function grok_status_reconcile(): array
{
    $status = grok_status_read();
    if (($status['state'] ?? '') !== 'running') {
        return $status;
    }
    $job = grok_job_read();
    $jobRunning = ($job['state'] ?? '') === 'running';
    if (!$jobRunning) {
        grok_clear_cancel();
        grok_status_write([
            'state' => 'stopped',
            'cancel_requested' => false,
            'pending' => 0,
            'kind' => 'grok',
            'message' => (string) (($status['message'] ?? '') !== ''
                ? $status['message']
                : 'Grok resolve is no longer running.'),
        ]);
        return grok_status_read();
    }
    if (grok_should_stop() && !grok_work_lock_held()) {
        return grok_job_halt($job);
    }
    $updated = (int) ($status['updated_at'] ?? 0);
    if (!grok_work_lock_held() && $updated > 0 && (time() - $updated) > 180) {
        return grok_job_halt($job);
    }
    return $status;
}

function grok_compact_candidates(array $candidates): array
{
    $out = [];
    $seen = [];
    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? $row['tmdb_id'] ?? 0);
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $year = $row['year'] ?? null;
        $out[] = [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'year' => is_numeric($year) ? (int) $year : null,
            'media_type' => (string) ($row['media_type'] ?? 'movie'),
        ];
        if (count($out) >= 5) {
            break;
        }
    }
    return $out;
}

function grok_candidate_ids(array $candidates): array
{
    $ids = [];
    foreach (grok_compact_candidates($candidates) as $row) {
        $ids[(int) $row['id']] = true;
    }
    return $ids;
}

function grok_ensure_candidates(array &$item): array
{
    $existing = grok_compact_candidates($item['tmdb_candidates'] ?? []);
    if ($existing !== []) {
        $item['tmdb_candidates'] = $existing;
        return $existing;
    }
    $title = (string) ($item['title'] ?? '');
    $year = isset($item['year']) && $item['year'] !== null && $item['year'] !== ''
        ? (int) $item['year']
        : null;
    $filename = (string) ($item['filename'] ?? $item['path'] ?? '');
    $found = tmdb_search_candidates($title, $year, 5, $filename);
    $item['tmdb_candidates'] = $found;
    return $found;
}

function grok_file_payload(array $item): array
{
    $payload = [
        'path' => (string) ($item['path'] ?? ''),
        'title' => (string) ($item['title'] ?? ''),
        'year' => isset($item['year']) && $item['year'] !== null && $item['year'] !== ''
            ? (int) $item['year']
            : null,
        'candidates' => grok_compact_candidates($item['tmdb_candidates'] ?? []),
    ];
    if (isset($item['season']) && $item['season'] !== null && $item['season'] !== '') {
        $payload['season'] = (int) $item['season'];
    }
    if (isset($item['episode']) && $item['episode'] !== null && $item['episode'] !== '') {
        $payload['episode'] = (int) $item['episode'];
    }
    return $payload;
}

function grok_extract_json(string $content): ?array
{
    $content = trim($content);
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);
    }
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return grok_rows($decoded);
    }
    if (preg_match('/\[[\s\S]*\]/', $content, $match)) {
        $decoded = json_decode($match[0], true);
        if (is_array($decoded)) {
            return grok_rows($decoded);
        }
    }
    return null;
}

function grok_rows(array $decoded): array
{
    if ($decoded === []) {
        return [];
    }
    if (function_exists('array_is_list') && array_is_list($decoded)) {
        return $decoded;
    }
    if (isset($decoded['results']) && is_array($decoded['results'])) {
        return $decoded['results'];
    }
    $vals = array_values($decoded);
    return $vals;
}

function grok_verbose_log(string $message): void
{
    if (!function_exists('app_log') || !function_exists('app_log_verbose') || !app_log_verbose()) {
        return;
    }
    app_log('grok', $message, [], 'debug');
}

function grok_chat(array $messages, string $model): array
{
    $key = grok_api_key();
    if ($key === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'content' => '', 'error' => 'Grok is not configured.'];
    }
    grok_verbose_log('Grok model ' . $model . ' system: ' . (string) ($messages[0]['content'] ?? ''));
    grok_verbose_log('Grok prompt: ' . (string) ($messages[1]['content'] ?? ''));

    $body = json_encode([
        'model' => $model,
        'temperature' => 0,
        'max_tokens' => 2500,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'content' => '', 'error' => 'Could not encode Grok request.'];
    }

    $ch = curl_init(XAI_API_URL);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'content' => '', 'error' => 'Could not start HTTP request.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_USERAGENT => 'MediaInformant/1.0',
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub($curlErr !== '' ? $curlErr : 'HTTP request failed.')];
    }

    $decoded = json_decode((string) $raw, true);
    $content = '';
    if (is_array($decoded)) {
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $apiErr = $decoded['error']['message'] ?? $decoded['error'] ?? null;
        if ($status !== 200) {
            $msg = is_string($apiErr) ? $apiErr : ('HTTP ' . $status);
            return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub($msg), 'model' => $model];
        }
    } elseif ($status !== 200) {
        return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub('HTTP ' . $status), 'model' => $model];
    }

    grok_verbose_log('Grok response (HTTP ' . $status . '): ' . ($content !== '' ? $content : '(empty)'));
    return ['ok' => true, 'status' => $status, 'content' => $content, 'error' => '', 'model' => $model];
}

function grok_chat_with_fallback(array $messages): array
{
    $primary = (string) XAI_MODEL;
    $result = grok_chat($messages, $primary);
    if ($result['ok']) {
        return $result;
    }
    $status = (int) ($result['status'] ?? 0);
    $err = lower((string) ($result['error'] ?? ''));
    $fallback = (string) XAI_MODEL_FALLBACK;
    if ($fallback !== '' && $fallback !== $primary && ($status === 404 || $status === 400 || str_contains($err, 'model'))) {
        return grok_chat($messages, $fallback);
    }
    return $result;
}

function grok_collect_unmatched_ids(array $library): array
{
    $ids = [];
    foreach ($library['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (library_is_manual($item)) {
            continue;
        }
        if (library_item_status($item) !== 'unmatched') {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function grok_apply_choice(array $item, int $tmdbId): array
{
    $meta = cache_read_title($tmdbId);
    if ($meta === null) {
        $meta = tmdb_fetch_details($tmdbId);
        if ($meta !== null) {
            cache_write_title($tmdbId, $meta);
        }
        usleep(TMDB_REQUEST_SLEEP_US);
    }
    if ($meta === null || empty($meta['tmdb_id'])) {
        throw new RuntimeException('Could not load TMDB title ' . $tmdbId . '.');
    }
    return cache_apply_tmdb_match($item, $meta, 'grok');
}

function grok_publish(array $job, array $extra = []): void
{
    grok_status_write(array_merge([
        'state' => (string) ($job['state'] ?? 'running'),
        'message' => (string) ($job['message'] ?? ''),
        'attempted' => (int) ($job['attempted'] ?? 0),
        'matched' => (int) ($job['matched'] ?? 0),
        'skipped_low_confidence' => (int) ($job['skipped_low_confidence'] ?? 0),
        'skipped_no_candidates' => (int) ($job['skipped_no_candidates'] ?? 0),
        'invalid_rejected' => (int) ($job['invalid_rejected'] ?? 0),
        'errors' => (int) ($job['errors'] ?? 0),
        'pending' => max(0, (int) ($job['total'] ?? 0) - (int) ($job['index'] ?? 0)),
        'total' => (int) ($job['total'] ?? 0),
        'http_status' => $job['http_status'] ?? null,
        'started_at' => $job['started_at'] ?? null,
        'kind' => 'grok',
        'cancel_requested' => grok_cancelled(),
        'paused' => grok_dev_pause_each_batch() && ($job['state'] ?? '') === 'running' && !grok_cancelled(),
    ], $extra));
}

function grok_job_begin(): array
{
    grok_clear_cancel();
    if (!grok_has_key()) {
        grok_status_write([
            'state' => 'error',
            'message' => 'Set XAI_API_KEY in Config or lib/config.php. Grok was not called.',
        ]);
        if (function_exists('app_log')) {
            app_log('grok', 'Grok resolve skipped: no API key.', [], 'warn');
        }
        return grok_status_read();
    }
    if ((scan_status_read()['state'] ?? '') === 'running') {
        $status = grok_status_read();
        $status['ok'] = false;
        $status['blocked'] = 'tmdb';
        $status['kind'] = 'grok';
        $status['state'] = $status['state'] === 'running' ? 'running' : 'idle';
        $status['message'] = 'A TMDB scan is running. Stop it before resolving unmatched titles.';
        if (function_exists('app_log')) {
            app_log('grok', 'Grok resolve blocked because a TMDB scan is running.', [], 'warn');
        }
        return $status;
    }

    $library = cache_read_library();
    $queue = grok_collect_unmatched_ids($library);
    $job = [
        'state' => $queue === [] ? 'done' : 'running',
        'queue' => $queue,
        'index' => 0,
        'total' => count($queue),
        'attempted' => 0,
        'matched' => 0,
        'skipped_low_confidence' => 0,
        'skipped_no_candidates' => 0,
        'invalid_rejected' => 0,
        'errors' => 0,
        'http_status' => null,
        'started_at' => time(),
        'message' => $queue === []
            ? 'No unmatched titles to resolve.'
            : 'Resolving ' . count($queue) . ' unmatched titles with Grok…',
    ];
    grok_job_write($job);
    grok_publish($job);
    if (function_exists('app_log')) {
        app_log('grok', $queue === []
            ? 'Grok resolve: no unmatched titles.'
            : 'Grok resolve started for ' . count($queue) . ' unmatched titles.', [
            'total' => count($queue),
        ]);
    }
    if ($queue === []) {
        grok_job_clear();
        return grok_status_read();
    }
    return grok_job_continue();
}

function grok_job_continue(): array
{
    $job = grok_job_read();
    if (($job['state'] ?? '') !== 'running') {
        return grok_status_read();
    }
    if (grok_should_stop()) {
        return grok_job_halt($job);
    }

    $queue = is_array($job['queue'] ?? null) ? $job['queue'] : [];
    $index = (int) ($job['index'] ?? 0);
    $total = count($queue);
    $batchSize = grok_batch_size();
    $maxBatches = grok_dev_pause_each_batch() ? 1 : max(1, (int) GROK_BATCHES_PER_REQUEST);
    $minConf = grok_min_confidence();
    $library = cache_read_library();
    $byId = [];
    foreach ($library['items'] as $i => $item) {
        if (is_array($item) && isset($item['id'])) {
            $byId[(string) $item['id']] = $i;
        }
    }

    $batches = 0;
    while ($index < $total && $batches < $maxBatches) {
        if (grok_should_stop()) {
            $job['index'] = $index;
            return grok_job_halt($job);
        }
        $sliceIds = array_slice($queue, $index, $batchSize);
        $batchItems = [];
        $payload = [];
        foreach ($sliceIds as $id) {
            if (grok_should_stop()) {
                $job['index'] = $index;
                return grok_job_halt($job);
            }
            $id = (string) $id;
            if (!isset($byId[$id])) {
                $job['errors'] = (int) $job['errors'] + 1;
                continue;
            }
            $pos = $byId[$id];
            $item = $library['items'][$pos];
            if (!is_array($item) || library_item_status($item) !== 'unmatched') {
                continue;
            }
            $candidates = grok_ensure_candidates($item);
            $library['items'][$pos] = $item;
            $job['attempted'] = (int) $job['attempted'] + 1;
            if ($candidates === []) {
                $job['skipped_no_candidates'] = (int) $job['skipped_no_candidates'] + 1;
                grok_log_line((string) ($item['path'] ?? ''), null, 0.0, 'no TMDB candidates');
                continue;
            }
            $batchItems[$id] = $pos;
            $payload[] = grok_file_payload($item);
        }

        cache_write_library($library);

        if ($payload === []) {
            $index += count($sliceIds);
            $job['index'] = $index;
            $batches++;
            continue;
        }

        if (grok_should_stop()) {
            $job['index'] = $index;
            return grok_job_halt($job);
        }
        $messages = [
            ['role' => 'system', 'content' => grok_system_prompt()],
            ['role' => 'user', 'content' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];
        $chat = grok_chat_with_fallback($messages);
        if (grok_should_stop()) {
            $job['index'] = $index;
            return grok_job_halt($job);
        }
        $job['http_status'] = $chat['status'] ?? null;
        if (!$chat['ok']) {
            $job['state'] = 'error';
            $job['errors'] = (int) $job['errors'] + 1;
            $job['index'] = $index;
            $job['message'] = 'Grok request failed (HTTP ' . (int) ($chat['status'] ?? 0) . '): ' . grok_scrub((string) ($chat['error'] ?? 'error'));
            grok_job_write($job);
            grok_publish($job);
            if (function_exists('app_log')) {
                app_log('grok', (string) $job['message'], ['http_status' => $job['http_status']], 'error');
            }
            return grok_status_read();
        }

        $decoded = grok_extract_json((string) ($chat['content'] ?? ''));
        $byPath = [];
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = (string) ($row['path'] ?? '');
                if ($path !== '') {
                    $byPath[$path] = $row;
                }
            }
        } else {
            $job['errors'] = (int) $job['errors'] + 1;
        }

        foreach ($batchItems as $id => $pos) {
            $item = $library['items'][$pos];
            $path = (string) ($item['path'] ?? '');
            $row = $byPath[$path] ?? null;
            $allowed = grok_candidate_ids($item['tmdb_candidates'] ?? []);
            if ($row === null) {
                grok_log_line($path, null, 0.0, 'missing from Grok response');
                $job['errors'] = (int) $job['errors'] + 1;
                continue;
            }
            $rawId = $row['tmdb_id'] ?? null;
            $tmdbId = $rawId === null || $rawId === '' || $rawId === false ? null : (int) $rawId;
            $confidence = (float) ($row['confidence'] ?? 0);
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($tmdbId === null || $tmdbId < 1) {
                grok_log_line($path, null, $confidence, $reason !== '' ? $reason : 'null');
                if ($confidence > 0 && $confidence < $minConf) {
                    $job['skipped_low_confidence'] = (int) $job['skipped_low_confidence'] + 1;
                }
                continue;
            }
            if (!isset($allowed[$tmdbId])) {
                grok_log_line($path, null, $confidence, 'invented id rejected');
                $job['invalid_rejected'] = (int) $job['invalid_rejected'] + 1;
                continue;
            }
            if ($confidence < $minConf) {
                grok_log_line($path, null, $confidence, $reason !== '' ? $reason : 'low confidence');
                $job['skipped_low_confidence'] = (int) $job['skipped_low_confidence'] + 1;
                continue;
            }
            try {
                $library['items'][$pos] = grok_apply_choice($item, $tmdbId);
                $job['matched'] = (int) $job['matched'] + 1;
                grok_log_line($path, $tmdbId, $confidence, $reason !== '' ? $reason : 'matched');
            } catch (Throwable $e) {
                $job['errors'] = (int) $job['errors'] + 1;
                grok_log_line($path, $tmdbId, $confidence, grok_scrub($e->getMessage()));
            }
        }

        cache_write_library($library);
        $index += count($sliceIds);
        $job['index'] = $index;
        $batches++;
        $job['message'] = 'Grok resolve: matched ' . (int) $job['matched'] . ' of ' . (int) $job['attempted']
            . ' (' . max(0, $total - $index) . ' left).';
        if (grok_dev_pause_each_batch() && $index < $total) {
            $job['message'] .= ' Paused — press Continue for the next batch.';
        }
        grok_job_write($job);
        grok_publish($job);
        if (function_exists('app_log')) {
            app_log('grok', (string) $job['message'], [
                'matched' => (int) $job['matched'],
                'attempted' => (int) $job['attempted'],
                'left' => max(0, $total - $index),
            ]);
        }
        if ($index < $total) {
            usleep((int) GROK_BATCH_SLEEP_US);
        }
    }

    if ($index >= $total) {
        $job['state'] = 'done';
        $job['message'] = 'Grok finished. Matched ' . (int) $job['matched']
            . ' of ' . (int) $job['attempted'] . ' unmatched titles.';
        grok_publish($job);
        grok_job_clear();
        if (function_exists('app_log')) {
            app_log('grok', (string) $job['message'], [
                'matched' => (int) $job['matched'],
                'attempted' => (int) $job['attempted'],
            ]);
        }
        return grok_status_read();
    }

    $job['state'] = 'running';
    grok_job_write($job);
    grok_publish($job);
    $status = grok_status_read();
    $status['paused'] = grok_dev_pause_each_batch();
    return $status;
}

function grok_tick(bool $allowStart = false): array
{
    cache_init();
    $job = grok_job_read();
    if (($job['state'] ?? '') === 'running') {
        if (grok_should_stop()) {
            return grok_job_halt($job);
        }
        return grok_job_continue();
    }
    if (!$allowStart) {
        return grok_status_read();
    }
    return grok_job_begin();
}

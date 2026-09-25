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
 * Grok does not replace TMDB. During Scan it runs in batches of 25 after
 * TMDB queues that many failures (and leftover titles at the end).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/scanner.php';

function grok_system_prompt(): string
{
    return "You match video filenames to TMDB records.\n"
        . "Never invent an id. Only use an id from that item's candidate list.\n"
        . "mode=choose: pick the best candidate or null.\n"
        . "mode=verify: return that single candidate id unless it is clearly a different work.\n"
        . "Missing year or punctuation is not a reason to reject.\n"
        . "Reply with JSON only:\n"
        . '[{"path":"...","tmdb_id":123 or null,"confidence":0.0-1.0,"reason":"short"}]';
}

function grok_resolve_available(): bool
{
    return GROK_RESOLVE_ENABLED && grok_has_key();
}

function grok_enabled_path(): string
{
    return CACHE_DIR . '/grok.enabled';
}

function grok_live_enabled(): bool
{
    if (!grok_resolve_available()) {
        return false;
    }
    $path = grok_enabled_path();
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return true;
    }
    $raw = trim((string) @file_get_contents($path));
    return $raw === '1';
}

function grok_live_enabled_write(bool $on): void
{
    if (function_exists('cache_init')) {
        cache_init();
    }
    @file_put_contents(grok_enabled_path(), $on ? "1\n" : "0\n", LOCK_EX);
    clearstatcache(true, grok_enabled_path());
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

function grok_pause_path(): string
{
    return CACHE_DIR . '/grok.pause';
}

function grok_dev_pause_each_batch(): bool
{
    $path = grok_pause_path();
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return defined('GROK_DEV_PAUSE_EACH_BATCH') && GROK_DEV_PAUSE_EACH_BATCH;
    }
    $raw = trim((string) @file_get_contents($path));
    return $raw === '1';
}

function grok_pause_each_batch_write(bool $on): void
{
    if (function_exists('cache_init')) {
        cache_init();
    }
    @file_put_contents(grok_pause_path(), $on ? "1\n" : "0\n", LOCK_EX);
    clearstatcache(true, grok_pause_path());
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
    $pause = grok_dev_pause_each_batch();
    $data['grok_pause'] = $pause;
    if (!$pause || !empty($data['cancel_requested'])) {
        $data['paused'] = false;
    } elseif (($data['state'] ?? '') === 'running') {
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
    $path = function_exists('scan_lock_path') ? scan_lock_path() : (CACHE_DIR . '/scan.lock');
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
        if (count($out) >= (function_exists('tmdb_shortlist_limit') ? tmdb_shortlist_limit() : 6)) {
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
    $filename = (string) ($item['filename'] ?? '');
    $found = tmdb_search_candidates($title, $year, function_exists('tmdb_shortlist_limit') ? tmdb_shortlist_limit() : 6, $filename, tmdb_search_meta_from_item($item));
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
    $kind = (string) ($item['kind'] ?? '');
    if ($kind !== '') {
        $payload['kind'] = $kind;
    }
    if (isset($item['season']) && $item['season'] !== null && $item['season'] !== '') {
        $payload['season'] = (int) $item['season'];
    }
    if (isset($item['episode']) && $item['episode'] !== null && $item['episode'] !== '') {
        $payload['episode'] = (int) $item['episode'];
    }
    $episodeTitle = trim((string) ($item['episode_title'] ?? ''));
    if ($episodeTitle !== '') {
        $payload['episode_title'] = $episodeTitle;
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

function grok_parse_usage($decoded): array
{
    $empty = [
        'prompt' => 0,
        'completion' => 0,
        'cached' => 0,
        'total' => 0,
        'absent' => true,
    ];
    if (!is_array($decoded) || !isset($decoded['usage']) || !is_array($decoded['usage'])) {
        return $empty;
    }
    $u = $decoded['usage'];
    $prompt = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0);
    $completion = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
    $cached = 0;
    $details = $u['prompt_tokens_details'] ?? $u['input_tokens_details'] ?? null;
    if (is_array($details)) {
        $cached = (int) ($details['cached_tokens'] ?? $details['cache_read_input_tokens'] ?? 0);
    }
    if ($cached < 1) {
        $cached = (int) ($u['cached_tokens'] ?? $u['cache_read_input_tokens'] ?? 0);
    }
    $total = (int) ($u['total_tokens'] ?? ($prompt + $completion));
    if ($prompt < 1 && $completion < 1 && $total < 1) {
        return $empty;
    }
    if ($cached > $prompt && $prompt > 0) {
        $cached = $prompt;
    }
    return [
        'prompt' => $prompt,
        'completion' => $completion,
        'cached' => max(0, $cached),
        'total' => $total,
        'absent' => false,
    ];
}

function grok_estimate_cost(array $usage, string $model): array
{
    $rates = grok_token_rates($model);
    $prompt = (int) ($usage['prompt'] ?? 0);
    $completion = (int) ($usage['completion'] ?? 0);
    $cached = (int) ($usage['cached'] ?? 0);
    if (!empty($usage['absent'])) {
        return ['usd' => 0.0, 'fallback' => !empty($rates['fallback']), 'rates' => $rates];
    }
    $uncached = max(0, $prompt - $cached);
    $usd = ($uncached * (float) $rates['input']
        + $cached * (float) $rates['cached']
        + $completion * (float) $rates['output']) / 1000000.0;
    return ['usd' => $usd, 'fallback' => !empty($rates['fallback']), 'rates' => $rates];
}

function grok_format_usd(float $usd): string
{
    if ($usd <= 0) {
        return '$0.0000';
    }
    $places = $usd < 0.0001 ? 6 : 4;
    return '$' . number_format($usd, $places, '.', '');
}

function grok_chat(array $messages, string $model, int $maxTokens = 2500): array
{
    $key = grok_api_key();
    if ($key === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'content' => '', 'error' => 'Grok is not configured.', 'usage' => grok_parse_usage(null)];
    }
    grok_verbose_log('Grok model ' . $model . ' system: ' . (string) ($messages[0]['content'] ?? ''));
    grok_verbose_log('Grok prompt: ' . (string) ($messages[1]['content'] ?? ''));
    if ($maxTokens < 256) {
        $maxTokens = 256;
    }
    if ($maxTokens > 8000) {
        $maxTokens = 8000;
    }

    $body = json_encode([
        'model' => $model,
        'temperature' => 0,
        'max_tokens' => $maxTokens,
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
        return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub($curlErr !== '' ? $curlErr : 'HTTP request failed.'), 'usage' => grok_parse_usage(null)];
    }

    $decoded = json_decode((string) $raw, true);
    $content = '';
    $usage = grok_parse_usage(is_array($decoded) ? $decoded : null);
    if (is_array($decoded)) {
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? $decoded['output_text'] ?? '');
        $apiErr = $decoded['error']['message'] ?? $decoded['error'] ?? null;
        if ($status !== 200) {
            $msg = is_string($apiErr) ? $apiErr : ('HTTP ' . $status);
            return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub($msg), 'model' => $model, 'usage' => $usage];
        }
    } elseif ($status !== 200) {
        return ['ok' => false, 'status' => $status, 'content' => '', 'error' => grok_scrub('HTTP ' . $status), 'model' => $model, 'usage' => $usage];
    }

    grok_verbose_log('Grok response (HTTP ' . $status . '): ' . ($content !== '' ? $content : '(empty)'));
    return ['ok' => true, 'status' => $status, 'content' => $content, 'error' => '', 'model' => $model, 'usage' => $usage];
}

function grok_chat_with_fallback(array $messages, int $maxTokens = 2500): array
{
    $primary = (string) XAI_MODEL;
    $result = grok_chat($messages, $primary, $maxTokens);
    if ($result['ok']) {
        return $result;
    }
    $status = (int) ($result['status'] ?? 0);
    $err = lower((string) ($result['error'] ?? ''));
    $fallback = (string) XAI_MODEL_FALLBACK;
    if ($fallback !== '' && $fallback !== $primary && ($status === 404 || $status === 400 || str_contains($err, 'model'))) {
        return grok_chat($messages, $fallback, $maxTokens);
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
        $cands = grok_compact_candidates($item['tmdb_candidates'] ?? []);
        if ($cands === []) {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function grok_choose_season(string $showTitle, string $folder, ?int $parsedSeason, string $sampleFile, array $seasons): ?int
{
    if ($seasons === [] || !grok_has_key() || !function_exists('grok_live_enabled') || !grok_live_enabled()) {
        return null;
    }
    $list = [];
    foreach ($seasons as $row) {
        if (!is_array($row) || !isset($row['season_number'])) {
            continue;
        }
        $list[] = [
            'id' => (int) ($row['id'] ?? 0),
            'season_number' => (int) $row['season_number'],
            'name' => (string) ($row['name'] ?? ''),
            'episode_count' => (int) ($row['episode_count'] ?? 0),
        ];
    }
    if ($list === []) {
        return null;
    }
    $messages = [
        ['role' => 'system', 'content' => "Pick the TMDB season for this TV folder.\n"
            . "Use only a season_number from the list. Do not invent one.\n"
            . "Reply with JSON only: {\"season_number\":1} or {\"season_number\":null}."],
        ['role' => 'user', 'content' => (string) json_encode([
            'show' => $showTitle,
            'folder' => $folder,
            'parsed_season' => $parsedSeason,
            'sample_file' => $sampleFile,
            'seasons' => $list,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
    $chat = grok_chat_with_fallback($messages, 200);
    if (empty($chat['ok'])) {
        return null;
    }
    $content = trim((string) ($chat['content'] ?? ''));
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $content, $match)) {
        $decoded = json_decode($match[0], true);
    }
    if (!is_array($decoded) || !isset($decoded['season_number']) || $decoded['season_number'] === null) {
        return null;
    }
    $n = (int) $decoded['season_number'];
    foreach ($list as $row) {
        if ((int) $row['season_number'] === $n) {
            return $n;
        }
    }
    return null;
}

function grok_apply_choice(array $item, int $tmdbId): array
{
    $meta = cache_read_title($tmdbId);
    if ($meta === null) {
        $mediaType = (string) ($item['kind'] ?? '') === 'show' ? 'tv' : 'movie';
        foreach (grok_compact_candidates($item['tmdb_candidates'] ?? []) as $cand) {
            if ((int) ($cand['id'] ?? 0) === $tmdbId) {
                $hint = (string) ($cand['media_type'] ?? '');
                if ($hint === 'tv' || $hint === 'movie') {
                    $mediaType = $hint;
                }
                break;
            }
        }
        $meta = tmdb_fetch_details($tmdbId, $mediaType);
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

/**
 * One Grok batch for the unified scan job. Mutates $job in place.
 */
function grok_accrue_scan_usage(array &$job, array $chat): void
{
    $model = (string) ($chat['model'] ?? (defined('XAI_MODEL') ? XAI_MODEL : ''));
    $usage = is_array($chat['usage'] ?? null) ? $chat['usage'] : grok_parse_usage(null);
    if (!empty($usage['absent']) && function_exists('app_log')) {
        app_log('grok', 'usage=absent');
    }
    $est = grok_estimate_cost($usage, $model);
    if (!empty($est['fallback']) && empty($job['grok_rate_fallback_logged']) && function_exists('app_log')) {
        $job['grok_rate_fallback_logged'] = 1;
        app_log('grok', 'Grok cost rates: unknown model, using 4.20 fallback.');
    }
    $batchUsd = (float) ($est['usd'] ?? 0);
    $prompt = (int) ($usage['prompt'] ?? 0);
    $completion = (int) ($usage['completion'] ?? 0);
    $cached = (int) ($usage['cached'] ?? 0);
    $job['grok_batches'] = (int) ($job['grok_batches'] ?? 0) + 1;
    $job['grok_prompt_tokens'] = (int) ($job['grok_prompt_tokens'] ?? 0) + $prompt;
    $job['grok_completion_tokens'] = (int) ($job['grok_completion_tokens'] ?? 0) + $completion;
    $job['grok_cached_tokens'] = (int) ($job['grok_cached_tokens'] ?? 0) + $cached;
    $job['grok_cost_usd'] = (float) ($job['grok_cost_usd'] ?? 0) + $batchUsd;

    $queue = is_array($job['grok_queue'] ?? null) ? $job['grok_queue'] : [];
    $batchSize = grok_batch_size();
    $totalEst = $batchSize > 0 ? (int) ceil(count($queue) / $batchSize) : (int) ($job['grok_batches'] ?? 1);
    if ($totalEst < (int) $job['grok_batches']) {
        $totalEst = (int) $job['grok_batches'];
    }
    $totalLabel = $totalEst > 0 ? (string) $totalEst : '…';
    if (function_exists('app_log')) {
        app_log('grok', 'Grok batch ' . (int) $job['grok_batches'] . '/' . $totalLabel
            . ' prompt=' . $prompt
            . ' completion=' . $completion
            . ' cached=' . $cached
            . ' batch=' . grok_format_usd($batchUsd)
            . ' scan_est=' . grok_format_usd((float) $job['grok_cost_usd']));
    }
}

function grok_run_scan_batch(array &$job): void
{
    $queue = is_array($job['grok_queue'] ?? null) ? $job['grok_queue'] : [];
    $gIndex = (int) ($job['grok_index'] ?? 0);
    $batchSize = grok_batch_size();
    $minConf = grok_min_confidence();
    $slice = array_slice($queue, $gIndex, $batchSize);
    if ($slice === []) {
        return;
    }

    $byId = [];
    foreach ($job['items'] as $i => $item) {
        if (is_array($item) && isset($item['id'])) {
            $byId[(string) $item['id']] = $i;
        }
    }

    $payload = [];
    $batchMeta = [];
    foreach ($slice as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $mode = ((string) ($row['mode'] ?? 'choose')) === 'verify' ? 'verify' : 'choose';
        if ($id === '' || !isset($byId[$id])) {
            $job['grok_skipped'] = (int) ($job['grok_skipped'] ?? 0) + 1;
            continue;
        }
        $pos = $byId[$id];
        $item = $job['items'][$pos];
        if (!empty($item['grok_verified'])) {
            continue;
        }
        $cands = grok_ensure_candidates($item);
        $job['items'][$pos] = $item;
        if ($cands === []) {
            $job['grok_skipped'] = (int) ($job['grok_skipped'] ?? 0) + 1;
            continue;
        }
        $entry = grok_file_payload($item);
        $entry['mode'] = $mode;
        $payload[] = $entry;
        $batchMeta[] = ['pos' => $pos, 'mode' => $mode, 'ids' => grok_candidate_ids($cands), 'path' => $entry['path']];
        $job['grok_attempted'] = (int) ($job['grok_attempted'] ?? 0) + 1;
    }

    $job['grok_index'] = $gIndex + count($slice);

    if ($payload === []) {
        if (function_exists('app_log')) {
            app_log('grok', 'Grok batch skipped: no files with TMDB candidates.');
        }
        return;
    }

    $messages = [
        ['role' => 'system', 'content' => grok_system_prompt()],
        ['role' => 'user', 'content' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
    $chat = grok_chat_with_fallback($messages);
    if (!$chat['ok']) {
        $job['grok_errors'] = (int) ($job['grok_errors'] ?? 0) + 1;
        if (function_exists('app_log')) {
            app_log('grok', 'Grok request failed: ' . grok_scrub((string) ($chat['error'] ?? 'error')), [
                'http_status' => $chat['status'] ?? null,
            ], 'error');
        }
        return;
    }
    grok_accrue_scan_usage($job, $chat);

    $decoded = grok_extract_json((string) ($chat['content'] ?? ''));
    $byPath = [];
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (is_array($row) && ($row['path'] ?? '') !== '') {
                $byPath[(string) $row['path']] = $row;
            }
        }
    }

    foreach ($batchMeta as $meta) {
        $pos = $meta['pos'];
        $item = $job['items'][$pos];
        $path = $meta['path'];
        $mode = $meta['mode'];
        $allowed = $meta['ids'];
        $row = $byPath[$path] ?? null;
        $rawId = is_array($row) ? ($row['tmdb_id'] ?? null) : null;
        $tmdbId = $rawId === null || $rawId === '' || $rawId === false ? null : (int) $rawId;
        $confidence = is_array($row) ? (float) ($row['confidence'] ?? 0) : 0.0;
        $reason = is_array($row) ? trim((string) ($row['reason'] ?? '')) : '';

        if ($tmdbId !== null && $tmdbId > 0 && !isset($allowed[$tmdbId])) {
            grok_log_line($path, null, $confidence, 'invented id rejected');
            $job['grok_invalid'] = (int) ($job['grok_invalid'] ?? 0) + 1;
            continue;
        }

        if ($mode === 'verify') {
            if ($tmdbId === null || $tmdbId < 1) {
                $job['items'][$pos] = library_clear_match($item);
                $job['items'][$pos]['status'] = 'unmatched';
                $job['items'][$pos]['grok_verified'] = 1;
                $job['grok_cleared'] = (int) ($job['grok_cleared'] ?? 0) + 1;
                grok_log_line($path, null, $confidence, $reason !== '' ? $reason : 'verify rejected');
                continue;
            }
            $job['items'][$pos]['grok_verified'] = 1;
            $job['grok_verified'] = (int) ($job['grok_verified'] ?? 0) + 1;
            grok_log_line($path, $tmdbId, $confidence, $reason !== '' ? $reason : 'verify kept');
            continue;
        }

        if ($tmdbId === null || $tmdbId < 1 || $confidence < $minConf) {
            grok_log_line($path, null, $confidence, $reason !== '' ? $reason : 'choose skipped');
            $showFolder = (string) ($item['show_folder'] ?? '');
            if ($showFolder !== '' && function_exists('scan_tv_backfill_show')) {
                scan_tv_backfill_show($job, $showFolder, null);
            }
            $partKey = (string) ($item['part_bundle'] ?? '');
            if ($partKey === '' && function_exists('scan_part_bundle_key')) {
                $partKey = scan_part_bundle_key($item);
            }
            if ($partKey !== '' && function_exists('scan_part_remember')) {
                scan_part_remember($job, $item, false, true);
            }
            continue;
        }
        try {
            $prevStatus = (string) ($item['status'] ?? '');
            $updated = grok_apply_choice($item, $tmdbId);
            $updated['grok_verified'] = 1;
            $job['items'][$pos] = $updated;
            $job['grok_matched'] = (int) ($job['grok_matched'] ?? 0) + 1;
            if ($prevStatus === 'unmatched') {
                $job['unmatched'] = max(0, (int) ($job['unmatched'] ?? 0) - 1);
            } elseif ($prevStatus === 'unidentified') {
                $job['unidentified'] = max(0, (int) ($job['unidentified'] ?? 0) - 1);
            }
            grok_log_line($path, $tmdbId, $confidence, $reason !== '' ? $reason : 'choose matched');
            $showFolder = (string) ($item['show_folder'] ?? '');
            if ($showFolder !== '' && function_exists('scan_tv_backfill_show')) {
                scan_tv_backfill_show($job, $showFolder, $tmdbId);
            }
            $partKey = (string) ($item['part_bundle'] ?? '');
            if ($partKey === '' && function_exists('scan_part_bundle_key')) {
                $partKey = scan_part_bundle_key($updated);
            }
            if ($partKey !== '' && function_exists('scan_part_backfill')) {
                scan_part_backfill($job, $partKey, $updated);
            }
        } catch (Throwable $e) {
            $job['grok_errors'] = (int) ($job['grok_errors'] ?? 0) + 1;
            grok_log_line($path, $tmdbId, $confidence, grok_scrub($e->getMessage()));
        }
    }
}

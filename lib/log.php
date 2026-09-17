<?php
declare(strict_types=1);

/**
 * App event log with in-process pub/sub. Entries live in cache/app-events.jsonl
 * for three days, then are pruned. Call app_log() from any PHP code; JS uses
 * the same topics via AppLog.publish / the log-write endpoint.
 */

define('APP_LOG_KEEP_SECONDS', 259200);
define('APP_LOG_PRUNE_EVERY', 20);

/** @var list<callable>|null */
$GLOBALS['app_log_listeners'] = $GLOBALS['app_log_listeners'] ?? [];
$GLOBALS['app_log_writes'] = $GLOBALS['app_log_writes'] ?? 0;

function app_log_path(): string
{
    return CACHE_DIR . '/app-events.jsonl';
}

function app_log_verbose(): bool
{
    $cookie = (string) ($_COOKIE['media_log_verbose'] ?? '');
    return $cookie === '1' || $cookie === 'true';
}

function app_log_subscribe(callable $listener): void
{
    $GLOBALS['app_log_listeners'][] = $listener;
}

function app_log(string $topic, string $message, array $context = [], string $level = 'info'): void
{
    $topic = preg_replace('/[^a-z0-9._-]/i', '', $topic) ?: 'app';
    $level = in_array($level, ['debug', 'info', 'warn', 'error'], true) ? $level : 'info';
    $message = app_log_scrub(trim($message));
    $max = $level === 'debug' ? 12000 : 500;
    if (strlen($message) > $max) {
        $message = substr($message, 0, $max - 3) . '...';
    }
    $event = [
        'id' => uniqid('log', true),
        'ts' => microtime(true),
        'topic' => $topic,
        'level' => $level,
        'message' => $message,
        'context' => app_log_scrub_context($context),
    ];
    foreach ($GLOBALS['app_log_listeners'] as $listener) {
        try {
            $listener($event);
        } catch (Throwable $e) {
        }
    }
    app_log_append($event);
}

function app_log_scrub(string $text): string
{
    if (function_exists('grok_api_key')) {
        $key = grok_api_key();
        if ($key !== '') {
            $text = str_replace($key, '[redacted]', $text);
        }
    }
    if (function_exists('settings_tmdb_key')) {
        $key = settings_tmdb_key();
        if ($key !== '') {
            $text = str_replace($key, '[redacted]', $text);
        }
    }
    if (defined('XAI_API_KEY') && XAI_API_KEY !== '') {
        $text = str_replace(XAI_API_KEY, '[redacted]', $text);
    }
    if (defined('TMDB_API_KEY') && TMDB_API_KEY !== '') {
        $text = str_replace(TMDB_API_KEY, '[redacted]', $text);
    }
    $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
    return $text;
}

function app_log_scrub_context(array $context): array
{
    $out = [];
    $n = 0;
    foreach ($context as $key => $value) {
        if ($n >= 12) {
            break;
        }
        $name = is_string($key) ? $key : (string) $key;
        if (preg_match('/key|secret|token|password|authorization/i', $name)) {
            $out[$name] = '[redacted]';
            $n++;
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $out[$name] = is_string($value) ? app_log_scrub($value) : $value;
            $n++;
        }
    }
    return $out;
}

function app_log_append(array $event): void
{
    if (!defined('CACHE_DIR')) {
        return;
    }
    if (function_exists('cache_init')) {
        cache_init();
    }
    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents(app_log_path(), $line . "\n", FILE_APPEND | LOCK_EX);
    $GLOBALS['app_log_writes'] = (int) $GLOBALS['app_log_writes'] + 1;
    $size = @filesize(app_log_path());
    if ($GLOBALS['app_log_writes'] % APP_LOG_PRUNE_EVERY === 0 || ($size !== false && $size > 350000)) {
        app_log_prune();
    }
}

function app_log_prune(): void
{
    $path = app_log_path();
    if (!is_file($path)) {
        return;
    }
    $cutoff = microtime(true) - APP_LOG_KEEP_SECONDS;
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return;
    }
    $keep = [];
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row) || (float) ($row['ts'] ?? 0) < $cutoff) {
            continue;
        }
        $keep[] = rtrim($line);
        if (count($keep) > 4000) {
            array_shift($keep);
        }
    }
    fclose($handle);
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, $keep === [] ? '' : (implode("\n", $keep) . "\n"), LOCK_EX);
    if (is_file($tmp)) {
        @rename($tmp, $path);
    }
}

function app_log_clear(): bool
{
    $path = app_log_path();
    if (function_exists('cache_init')) {
        cache_init();
    }
    if (!is_file($path)) {
        return true;
    }
    return @file_put_contents($path, '', LOCK_EX) !== false;
}

function app_log_read(float $since = 0.0, int $limit = 250): array
{
    $path = app_log_path();
    if (!is_file($path)) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $rows = [];
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $ts = (float) ($row['ts'] ?? 0);
        if ($ts <= $since) {
            continue;
        }
        $rows[] = $row;
        if (count($rows) > $limit) {
            array_shift($rows);
        }
    }
    fclose($handle);
    return $rows;
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Configured Kodi players. Empty when Play on Kodi is off.
 *
 * @return list<array{id: string, name: string, host: string, port: int, user: string, pass: string}>
 */
function kodi_hosts(): array
{
    $rows = settings_get()['kodi_hosts'] ?? [];
    return is_array($rows) ? $rows : [];
}

function kodi_enabled(): bool
{
    foreach (kodi_hosts() as $host) {
        if (trim((string) ($host['host'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * The player Play on Kodi uses. One configured host is always selected.
 * Two or more use the media_kodi_host cookie, then the first host.
 *
 * @return array{id: string, name: string, host: string, port: int, user: string, pass: string}|null
 */
function kodi_selected_host(): ?array
{
    $hosts = kodi_hosts();
    if ($hosts === []) {
        return null;
    }
    if (count($hosts) === 1) {
        return $hosts[0];
    }
    $want = isset($_COOKIE['media_kodi_host']) ? (string) $_COOKIE['media_kodi_host'] : '';
    if ($want !== '') {
        foreach ($hosts as $host) {
            if ((string) ($host['id'] ?? '') === $want) {
                return $host;
            }
        }
    }
    return $hosts[0];
}

/**
 * Path prefix Kodi should see instead of the NAS source root.
 * The Config field wins. A non-empty KODI_PATH_PREFIX constant is the fallback.
 */
function kodi_path_prefix(): string
{
    $stored = trim((string) (settings_get()['kodi_path_prefix'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    if (defined('KODI_PATH_PREFIX')) {
        return trim((string) KODI_PATH_PREFIX);
    }
    return '';
}

function kodi_join_prefix(string $prefix, string $relative): string
{
    $prefix = rtrim(str_replace('\\', '/', $prefix), '/');
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($prefix === '') {
        return $relative;
    }
    if ($relative === '') {
        return $prefix;
    }
    return $prefix . '/' . $relative;
}

/**
 * Longest configured video or music source that contains $absolute.
 *
 * @return array{root: string, smb: string}
 */
function kodi_matching_source(string $absolute): array
{
    $absolute = settings_normalize_path($absolute);
    $bestRoot = '';
    $bestSmb = '';
    $settings = settings_get();
    foreach (['video_roots', 'music_roots'] as $key) {
        $rows = $settings[$key] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $source) {
            $root = '';
            $smb = '';
            if (is_array($source)) {
                $root = settings_normalize_path((string) ($source['path'] ?? ''));
                $smb = trim((string) ($source['smb'] ?? ''));
            } elseif (is_string($source)) {
                $root = settings_normalize_path($source);
            }
            if ($root === '') {
                continue;
            }
            if ($absolute === $root || str_starts_with($absolute, $root . '/')) {
                if (strlen($root) > strlen($bestRoot)) {
                    $bestRoot = $root;
                    $bestSmb = $smb;
                }
            }
        }
    }
    return ['root' => $bestRoot, 'smb' => $bestSmb];
}

/**
 * File path Kodi's Player.Open can use for a video or music item.
 *
 * The NAS folder configured for a source is not the path Kodi opens. When that
 * source has an SMB path, the NAS folder is removed and the SMB path is put
 * in front. /volume1/video/Movies/Foo.mkv with smb://nas/video becomes
 * smb://nas/video/Movies/Foo.mkv.
 *
 * A source with no SMB path uses the optional path prefix the same way. With
 * neither, the path is smb://{site-host}{absolute-path}. The site host is this
 * app's HTTP host with any port removed. Assumption: that host is the NAS, and
 * Kodi can open the NAS absolute path as an SMB path, for example
 * smb://nas.local/volume1/video/Movies/Foo.mkv. Set the source SMB path when
 * that guess does not match the share Kodi already has.
 */
function kodi_play_path(array $item): string
{
    $relative = str_replace('\\', '/', trim((string) ($item['path'] ?? '')));
    $absolute = function_exists('format_source_path') ? format_source_path($item) : $relative;
    $absolute = settings_normalize_path($absolute);
    if ($relative === '' && ($absolute === '' || $absolute === settings_normalize_path((string) ($item['root'] ?? '')))) {
        return '';
    }
    $match = kodi_matching_source($absolute);
    if ($match['smb'] !== '' && $match['root'] !== '' && ($absolute === $match['root'] || str_starts_with($absolute, $match['root'] . '/'))) {
        $relative = ltrim(substr($absolute, strlen($match['root'])), '/');
        return kodi_join_prefix($match['smb'], $relative);
    }
    $prefix = kodi_path_prefix();
    if ($prefix !== '') {
        $root = $match['root'];
        if ($root !== '' && ($absolute === $root || str_starts_with($absolute, $root . '/'))) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');
        } elseif ($relative === '') {
            $relative = ltrim($absolute, '/');
        }
        return kodi_join_prefix($prefix, $relative);
    }
    $site = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $site = preg_replace('/:\d+$/', '', $site) ?? $site;
    if (str_starts_with($site, '[')) {
        $end = strpos($site, ']');
        $site = $end === false ? '' : substr($site, 0, $end + 1);
    }
    if ($site === '' || $absolute === '') {
        return $absolute;
    }
    return 'smb://' . $site . ($absolute[0] === '/' ? $absolute : '/' . $absolute);
}

/**
 * @param array{id?: string, name?: string, host?: string, port?: int, user?: string, pass?: string} $host
 * @return array{ok: bool, error: string, message: string, path: string}
 */
function kodi_player_open(array $host, string $path): array
{
    $name = trim((string) ($host['name'] ?? 'Kodi'));
    $target = trim((string) ($host['host'] ?? ''));
    $port = (int) ($host['port'] ?? 8080);
    if ($port < 1 || $port > 65535) {
        $port = 8080;
    }
    if ($path === '' || $target === '') {
        return ['ok' => false, 'error' => 'Nothing to play.', 'message' => '', 'path' => $path];
    }
    if (function_exists('app_log')) {
        app_log('kodi', 'Play on ' . $name . ': ' . $path, [
            'host' => $target,
            'port' => $port,
        ]);
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'This PHP build has no curl extension, so Kodi cannot be reached. Path: ' . $path, 'message' => '', 'path' => $path];
    }
    $body = json_encode([
        'jsonrpc' => '2.0',
        'method' => 'Player.Open',
        'params' => ['item' => ['file' => $path]],
        'id' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not build the Kodi request.', 'message' => '', 'path' => $path];
    }
    $httpHost = $target;
    if (str_contains($target, ':') && !str_starts_with($target, '[')) {
        $httpHost = '[' . $target . ']';
    }
    $url = 'http://' . $httpHost . ':' . $port . '/jsonrpc';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Could not start the Kodi request.', 'message' => '', 'path' => $path];
    }
    $headers = ['Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'MediaInformant/1.0',
    ];
    $user = (string) ($host['user'] ?? '');
    $pass = (string) ($host['pass'] ?? '');
    if ($user !== '' || $pass !== '') {
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        $timedOut = $errno === 28;
        $error = ($timedOut ? 'Kodi did not answer in time.' : 'Could not reach ' . $name . '.') . ' Path: ' . $path;
        if (function_exists('app_log')) {
            app_log('kodi', $error, ['host' => $target, 'port' => $port], 'warn');
        }
        return ['ok' => false, 'error' => $error, 'message' => '', 'path' => $path];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $error = ($status === 401
            ? 'Kodi refused the username or password.'
            : 'Kodi returned an unexpected response.') . ' Path: ' . $path;
        if (function_exists('app_log')) {
            app_log('kodi', $error . ' HTTP ' . $status . '.', ['host' => $target], 'warn');
        }
        return ['ok' => false, 'error' => $error, 'message' => '', 'path' => $path];
    }
    if (isset($decoded['error'])) {
        $message = '';
        if (is_array($decoded['error'])) {
            $message = trim((string) ($decoded['error']['message'] ?? ''));
        } elseif (is_string($decoded['error'])) {
            $message = trim($decoded['error']);
        }
        if ($message === '') {
            $message = 'Kodi did not start playback.';
        }
        $error = $message . ' Path: ' . $path;
        if (function_exists('app_log')) {
            app_log('kodi', 'Play failed on ' . $name . ': ' . $error, ['host' => $target], 'warn');
        }
        return ['ok' => false, 'error' => $error, 'message' => '', 'path' => $path];
    }
    if ($status !== 200) {
        $error = 'Kodi returned HTTP ' . $status . '. Path: ' . $path;
        if (function_exists('app_log')) {
            app_log('kodi', $error, ['host' => $target], 'warn');
        }
        return ['ok' => false, 'error' => $error, 'message' => '', 'path' => $path];
    }
    if ($curlErr !== '' && function_exists('app_log')) {
        app_log('kodi', $curlErr, ['host' => $target], 'debug');
    }
    return ['ok' => true, 'error' => '', 'message' => 'Playing on ' . $name . '.', 'path' => $path];
}

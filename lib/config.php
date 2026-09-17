<?php
declare(strict_types=1);

/**
 * Media Informant for Synology Web Station. Use the Config screen to set
 * video and music source folders for this NAS, plus the TMDB and xAI API keys.
 * VIDEO_ROOT, MUSIC_ROOT, and TMDB_* below are defaults until Config is
 * saved. Grant the http user read access on those shares and write access
 * on cache/. Browse reads cache only; scan video folders from Config.
 * Keep media files on the shares, not inside this web folder.
 */

define('APP_ROOT', dirname(__DIR__));
define('CACHE_DIR', APP_ROOT . '/cache');
define('VIDEO_ROOT', '/volume1/video');
define('MUSIC_ROOT', '/volume1/music');
define('TMDB_API_KEY', '');
define('TMDB_LANGUAGE', 'en-US');
define('TMDB_IMAGE_BASE', 'https://image.tmdb.org/t/p/w342');
define('TMDB_REQUEST_SLEEP_US', 250000);
/** xAI Grok key for the unmatched second-pass matcher. Leave empty to disable. Never commit a real key. */
define('XAI_API_KEY', '');
define('XAI_API_URL', 'https://api.x.ai/v1/chat/completions');
define('XAI_MODEL', 'grok-4-1-fast-non-reasoning');
define('XAI_MODEL_FALLBACK', 'grok-4-1-fast');

/**
 * USD per 1M tokens for Scan cost estimates. Not an invoice.
 * Keys are matched against XAI_MODEL / aliases after lowercasing.
 *
 * @return array{input: float, cached: float, output: float}
 */
function grok_token_rate_table(): array
{
    return [
        '4.20' => ['input' => 1.25, 'cached' => 0.20, 'output' => 2.50],
        '4.5' => ['input' => 2.00, 'cached' => 0.50, 'output' => 6.00],
    ];
}

function grok_token_rates(string $model): array
{
    $m = strtolower(str_replace('_', '-', trim($model)));
    $table = grok_token_rate_table();
    $fallback = $table['4.20'];
    if (preg_match('/grok-4[.-]?(5|6)(\b|-|$)/', $m)) {
        return $table['4.5'] + ['fallback' => false];
    }
    if (preg_match('/grok-4[.-]?(20|3)(\b|-|$)/', $m)) {
        return $fallback + ['fallback' => false];
    }
    return $fallback + ['fallback' => true];
}
define('GROK_BATCH_SIZE', 25);
define('GROK_MIN_CONFIDENCE', 0.75);
define('GROK_BATCH_SLEEP_US', 400000);
define('GROK_BATCHES_PER_REQUEST', 3);
/** Effective only when an xAI key is set. */
define('GROK_RESOLVE_ENABLED', true);
define('GROK_VERIFY_SINGLES', true);
/** Default when cache/grok.pause is missing. The Scan page toggle overrides this live. */
define('GROK_DEV_PAUSE_EACH_BATCH', true);

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/log.php';
define('VIDEO_EXTENSIONS', ['mkv', 'mp4', 'avi', 'm4v', 'mov', 'wmv', 'ts', 'm2ts']);
define('AUDIO_EXTENSIONS', ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'wma', 'wav', 'aiff']);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rel_prefix(): string
{
    $root = rtrim(str_replace('\\', '/', APP_ROOT), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === $root || !str_starts_with($dir, $root . '/')) {
        return '';
    }
    $rel = substr($dir, strlen($root) + 1);
    return str_repeat('../', substr_count($rel, '/') + 1);
}

function app_href(string $path): string
{
    return rel_prefix() . ltrim($path, '/');
}

function lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function initial(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '?';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($value, 0, 1));
}

function theme_pref(): string
{
    $raw = $_COOKIE['media_theme'] ?? 'auto';
    return in_array($raw, ['auto', 'light', 'dark'], true) ? $raw : 'auto';
}

function poster_url(?string $posterPath): ?string
{
    if ($posterPath === null || $posterPath === '') {
        return null;
    }
    if (str_starts_with($posterPath, 'http://') || str_starts_with($posterPath, 'https://')) {
        return $posterPath;
    }
    return TMDB_IMAGE_BASE . $posterPath;
}

function format_scanned_at(?int $timestamp): string
{
    if (!$timestamp) {
        return 'Never scanned';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $n = intdiv($diff, 60);
        return $n === 1 ? '1 minute ago' : $n . ' minutes ago';
    }
    if ($diff < 86400) {
        $n = intdiv($diff, 3600);
        return $n === 1 ? '1 hour ago' : $n . ' hours ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }
    if ($diff < 2592000) {
        return intdiv($diff, 86400) . ' days ago';
    }
    return date('M j, Y', $timestamp);
}

function render_start(string $title): void
{
    $pref = theme_pref();
    $resolved = $pref === 'dark' ? 'dark' : 'light';
    $themeColor = $resolved === 'dark' ? '#2c2c31' : '#ffffff';
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en" data-theme="' . h($resolved) . '" data-theme-pref="' . h($pref) . '" data-shell="' . h(app_shell()) . '">' . "\n";
    ?>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= h($themeColor) ?>">
<meta name="color-scheme" content="light dark">
<script>
(function () {
  var root = document.documentElement;
  var pref = root.getAttribute('data-theme-pref') || 'auto';
  try {
    var stored = localStorage.getItem('media_theme');
    if (stored === 'auto' || stored === 'light' || stored === 'dark') pref = stored;
  } catch (e) {}
  var resolved = pref;
  if (pref === 'auto') {
    resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  root.setAttribute('data-theme', resolved === 'dark' ? 'dark' : 'light');
  root.setAttribute('data-theme-pref', pref);
  var meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', resolved === 'dark' ? '#2c2c31' : '#ffffff');

  var shell = 'mobile';
  try {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches
        && !window.matchMedia('(pointer: coarse)').matches) {
      shell = 'desktop';
    }
  } catch (e) {}
  root.setAttribute('data-shell', shell);
  try { localStorage.setItem('media_shell', shell); } catch (e) {}
  document.cookie = 'media_shell=' + encodeURIComponent(shell) + ';path=/;max-age=31536000;SameSite=Lax';
})();
</script>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Media Informant">
<title><?= h($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%233584e4'/%3E%3Cpath fill='white' d='M7 6h2v3H7V6zm0 5h2v3H7v-3zm0 5h2v3H7v-3zm8-10h2v3h-2V6zm0 5h2v3h-2v-3zm0 5h2v3h-2v-3zM10 6h4v12h-4z'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= h(app_href('assets/mobile.css')) ?>?v=35">
</head>
<body>
<?php
}

function render_header(array $opts = []): void
{
    $showSearch = !empty($opts['search']);
    $showBranches = array_key_exists('branches', $opts) ? !empty($opts['branches']) : true;
    $section = (string) ($opts['section'] ?? 'home');
    $pref = theme_pref();
    ?>
<header class="site-header">
  <div class="header-row">
    <a class="brand" href="<?= h(app_href('index.php')) ?>">
      <span class="brand-mark" aria-hidden="true"></span>
      <span class="brand-text">Media Informant</span>
    </a>
    <div class="header-actions">
      <button type="button" class="btn btn-icon scan-live-btn" data-scan-live hidden data-scan-status-url="<?= h(app_href('video/scan-status.php')) ?>" data-scan-run-url="<?= h(app_href('video/scan-run.php')) ?>" data-scan-page-url="<?= h(app_href('video/scan.php')) ?>" data-grok-run-url="<?= h(app_href('video/resolve-run.php')) ?>" data-grok-page-url="<?= h(app_href('video/resolve.php')) ?>" aria-label="Scan in progress" aria-haspopup="dialog" aria-controls="scan-overlay">
        <span class="scan-live-ring" aria-hidden="true"></span>
        <span class="visually-hidden">Scan running</span>
      </button>
      <a class="btn btn-icon<?= $section === 'config' ? ' is-active' : '' ?>" href="<?= h(app_href('config/index.php')) ?>" aria-label="Settings"<?= $section === 'config' ? ' aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.2 7.2 0 0 0-1.63-.94l-.36-2.54A.5.5 0 0 0 13.9 2h-3.8a.5.5 0 0 0-.49.42l-.36 2.54c-.59.24-1.13.55-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.81 8.48a.5.5 0 0 0 .12.64L4.96 10.7c-.04.31-.06.63-.06.94s.02.63.06.94L2.93 14.16a.5.5 0 0 0-.12.64l1.92 3.32c.13.23.4.32.64.22l2.39-.96c.5.39 1.04.7 1.63.94l.36 2.54c.05.24.25.42.49.42h3.8c.24 0 .44-.18.49-.42l.36-2.54c.59-.24 1.13-.55 1.63-.94l2.39.96c.24.1.51 0 .64-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2Z"/>
        </svg>
      </a>
    </div>
  </div>
  <?php if ($showBranches): ?>
  <nav class="branch-nav" aria-label="Libraries">
    <a class="branch-tab<?= $section === 'video' ? ' is-active' : '' ?>" href="<?= h(app_href('video/index.php')) ?>"<?= $section === 'video' ? ' aria-current="page"' : '' ?>>
      <svg class="branch-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 5v14M16 5v14M3 9h5M3 15h5M16 9h5M16 15h5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10.5 9.5 15 12l-4.5 2.5v-5z" fill="currentColor"/></svg>
      Video
    </a>
    <a class="branch-tab<?= $section === 'music' ? ' is-active' : '' ?>" href="<?= h(app_href('music/index.php')) ?>"<?= $section === 'music' ? ' aria-current="page"' : '' ?>>
      <svg class="branch-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="7" cy="17" r="2.6" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="14.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9.6 17V7.2L19.6 5v9.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
      Music
    </a>
  </nav>
  <?php endif; ?>
  <?php if ($showSearch): ?>
  <div class="search-wrap">
    <label class="visually-hidden" for="catalog-search">Search titles</label>
    <input id="catalog-search" type="search" class="search-input" data-search-input placeholder="<?= app_shell() === 'desktop' ? 'Search titles' : 'Search, then press Search' ?>" autocomplete="off" autocapitalize="off" enterkeyhint="search" spellcheck="false">
    <button type="button" class="search-clear" data-search-clear hidden aria-label="Clear search">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
    </button>
  </div>
  <?php endif; ?>
</header>
<?php
}

function render_end(): void
{
    $scanPage = app_href('video/scan.php');
    $grokPage = app_href('video/resolve.php');
    ?>
<div class="overlay" data-overlay="scan" id="scan-overlay" hidden>
  <div class="overlay-backdrop" data-overlay-dismiss></div>
  <button type="button" class="overlay-close" data-overlay-dismiss aria-label="Close">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
  </button>
  <div class="overlay-pane overlay-pane-scan" role="dialog" aria-modal="true" aria-labelledby="scan-overlay-title">
    <p class="scan-kicker" data-scan-overlay-kicker>Scan</p>
    <h2 id="scan-overlay-title" data-scan-overlay-title>Scan progress</h2>
    <p class="overlay-lead" data-scan-overlay-message>No scan is running.</p>
    <dl class="overlay-stats" data-overlay-stats="tmdb">
      <div><dt>Found</dt><dd data-scan-overlay-found>0</dd></div>
      <div><dt>Unresolved</dt><dd data-scan-overlay-pending>0</dd></div>
      <div><dt>API lookups</dt><dd data-scan-overlay-lookups>0</dd></div>
      <div><dt>Unmatched</dt><dd data-scan-overlay-unmatched>0</dd></div>
      <div><dt>Unidentified</dt><dd data-scan-overlay-unidentified>0</dd></div>
      <div><dt>Files</dt><dd data-scan-overlay-files>—</dd></div>
      <div><dt>Elapsed</dt><dd data-scan-overlay-elapsed>—</dd></div>
      <div><dt>Grok matched</dt><dd data-scan-overlay-grok-matched>0</dd></div>
      <div><dt>Grok attempted</dt><dd data-scan-overlay-grok-attempted>0</dd></div>
      <div><dt>Grok left</dt><dd data-scan-overlay-grok-left>0</dd></div>
      <div><dt>Est. Grok cost</dt><dd data-scan-overlay-grok-cost>$0.0000</dd></div>
    </dl>
    <dl class="overlay-stats" data-overlay-stats="grok" hidden>
      <div><dt>Matched</dt><dd data-grok-overlay-matched>0</dd></div>
      <div><dt>Attempted</dt><dd data-grok-overlay-attempted>0</dd></div>
      <div><dt>Left</dt><dd data-grok-overlay-pending>0</dd></div>
      <div><dt>Low confidence</dt><dd data-grok-overlay-low>0</dd></div>
      <div><dt>Rejected ids</dt><dd data-grok-overlay-invalid>0</dd></div>
      <div><dt>Errors</dt><dd data-grok-overlay-errors>0</dd></div>
      <div><dt>Elapsed</dt><dd data-grok-overlay-elapsed>—</dd></div>
    </dl>
    <div class="grok-wait-actions" data-grok-wait-actions hidden>
      <button type="button" class="btn btn-accent btn-block" data-grok-continue>Continue next batch</button>
    </div>
    <a class="btn btn-accent btn-block" data-scan-overlay-link href="<?= h($scanPage) ?>">Open scan page</a>
  </div>
</div>
<div class="overlay" data-overlay="poster" id="poster-overlay" hidden>
  <div class="overlay-backdrop" data-overlay-dismiss></div>
  <button type="button" class="overlay-close" data-overlay-dismiss aria-label="Close">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
  </button>
  <div class="overlay-pane overlay-pane-poster" role="dialog" aria-modal="true" aria-labelledby="poster-overlay-title">
    <div class="poster-overlay-frame">
      <img data-poster-overlay-img alt="" hidden>
      <div class="poster-overlay-fallback poster-fallback" data-poster-overlay-fallback hidden aria-hidden="true"><span data-poster-overlay-initial>?</span></div>
    </div>
    <div class="poster-overlay-caption">
      <h2 id="poster-overlay-title" data-poster-overlay-title></h2>
      <p data-poster-overlay-caption></p>
    </div>
  </div>
</div>
<div class="log-console" data-log-console data-log-feed-url="<?= h(app_href('video/log-feed.php')) ?>" data-log-write-url="<?= h(app_href('video/log-write.php')) ?>" data-log-clear-url="<?= h(app_href('video/log-clear.php')) ?>">
  <button type="button" class="log-console-tab" data-log-toggle aria-expanded="false" aria-controls="log-console-panel">Log</button>
  <div class="log-console-panel" id="log-console-panel" hidden>
    <div class="log-console-head">
      <div>
        <h2>Log</h2>
        <p>Kept for 3 days</p>
      </div>
      <div class="log-console-actions">
        <button type="button" class="btn btn-ghost" data-log-verbose aria-pressed="false">Verbose</button>
        <button type="button" class="btn btn-ghost" data-log-clear>Clear</button>
        <button type="button" class="btn btn-ghost" data-log-toggle>Close</button>
      </div>
    </div>
    <ol class="log-console-list" data-log-list></ol>
  </div>
</div>
<script src="<?= h(app_href('assets/app.js')) ?>?v=40" defer></script>
</body>
</html>
<?php
}

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
header('Cache-Control: no-store');

$status = scan_status_read();
$running = ($status['state'] ?? '') === 'running';
$stopping = $running && (!empty($status['cancel_requested']) || ($status['phase'] ?? '') === 'stopping');
$runMode = (string) ($_GET['run'] ?? '');
if ($runMode === '1') {
    $runMode = 'retry';
}
if ($runMode !== 'unidentified' && $runMode !== 'retry') {
    $runMode = '';
}
$grokStatus = grok_status_read();
$grokHasKey = grok_has_key();

render_start('Scan · Video');
render_header(['section' => 'config', 'branches' => true]);
?>
<main class="page scan-page">
  <a class="back" href="<?= h(app_href('config/index.php')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Config
  </a>
  <section class="panel" data-scan-panel data-scan-run-url="<?= h(app_href('video/scan-run.php')) ?>"<?= $runMode !== '' ? ' data-scan-start="' . h($runMode) . '"' : '' ?>>
    <p class="scan-kicker" data-scan-kicker><?= $stopping ? 'Stopping' : ($running ? 'In progress' : 'Ready') ?></p>
    <h1 data-scan-title><?= $stopping ? 'Stopping' : ($running ? 'Scanning' : 'Video scan') ?></h1>
    <p class="scan-live-copy" data-scan-message><?= h((string) ($status['message'] !== '' ? $status['message'] : 'Unidentified titles have never been looked up. Unmatched titles were looked up and did not match. Matched and manual titles are skipped.')) ?></p>
    <div class="scan-bar<?= $running && empty($status['pending']) ? ' is-indeterminate' : '' ?>" aria-hidden="true"><span class="scan-bar-fill" data-scan-bar style="width: 0%"></span></div>
    <ul class="stat-grid">
      <li>
        <span class="stat-value" data-scan-found><?= (int) ($status['found'] ?? 0) ?></span>
        <span class="stat-label">Found</span>
      </li>
      <li>
        <span class="stat-value" data-scan-pending><?= (int) ($status['pending'] ?? 0) ?></span>
        <span class="stat-label">Unresolved</span>
      </li>
      <li>
        <span class="stat-value" data-scan-lookups><?= (int) ($status['lookups'] ?? 0) ?></span>
        <span class="stat-label">Lookups</span>
      </li>
    </ul>
    <p class="hint" data-scan-hint>Scan unidentified only looks up titles that have never been tried. The second scan also retries unmatched titles. Stop ends the scan after the current step.</p>
    <div class="scan-launch" data-scan-launch-wrap<?= $running ? ' hidden' : '' ?>>
      <a class="btn btn-accent btn-block" href="<?= h(app_href('video/scan.php?run=unidentified')) ?>" data-scan-launch="unidentified">Scan unidentified</a>
      <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/scan.php?run=retry')) ?>" data-scan-launch="retry">Scan unmatched &amp; unidentified</a>
      <?php if ($grokHasKey): ?>
      <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/resolve.php?run=1')) ?>" data-grok-launch>Resolve unmatched (Grok)</a>
      <?php else: ?>
      <button type="button" class="btn btn-ghost btn-block" disabled>Resolve unmatched (Grok)</button>
      <p class="hint">Set XAI_API_KEY in Config or lib/config.php to enable the Grok second pass. It is not called until then.</p>
      <?php endif; ?>
      <?php if (($grokStatus['state'] ?? '') === 'done' || (int) ($grokStatus['attempted'] ?? 0) > 0): ?>
      <p class="hint">Last Grok run: matched <?= (int) ($grokStatus['matched'] ?? 0) ?> of <?= (int) ($grokStatus['attempted'] ?? 0) ?>.</p>
      <?php endif; ?>
    </div>
    <div class="grok-wait-actions" data-grok-wait-actions>
      <button type="button" class="btn btn-accent btn-block" data-grok-continue>Continue next batch</button>
    </div>
    <form method="post" action="<?= h(app_href('video/resolve-stop.php')) ?>" data-grok-stop>
      <button type="submit" class="btn btn-ghost btn-block" data-grok-stop-btn hidden>Stop Grok</button>
    </form>
    <form method="post" action="<?= h(app_href('video/scan-stop.php')) ?>" data-scan-stop>
      <button type="submit" class="btn btn-ghost btn-block" data-scan-stop-btn<?= $running ? '' : ' hidden' ?><?= $stopping ? ' disabled' : '' ?>><?= $stopping ? 'Stopping…' : 'Stop scan' ?></button>
    </form>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('video/index.php')) ?>">Open Video catalog</a>
  </section>
</main>
<div class="scan-live-bar" data-scan-live-bar>
  <span class="scan-live-bar-spin" data-scan-bar-spin<?= $running ? '' : ' hidden' ?> aria-hidden="true"></span>
  <p data-scan-message><?= h((string) ($status['message'] !== '' ? $status['message'] : 'Waiting to start…')) ?></p>
</div>
<?php
render_end();

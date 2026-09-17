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
if ($runMode === '1' || $runMode === 'unidentified' || $runMode === 'retry') {
    $runMode = 'retry';
} else {
    $runMode = '';
}
$grokAvailable = grok_resolve_available();
$grokOn = grok_live_enabled();
$showGrokUi = $grokAvailable && $grokOn;
$idleCopy = $showGrokUi
    ? 'One scan: TMDB lookups queue Grok work. Every 25 queued titles, Grok runs a batch. Continue after each Grok batch.'
    : 'Scan uses TMDB only. Unmatched titles stay unmatched unless you turn Grok on.';

render_start('Scan · Video');
render_header(['section' => 'config', 'branches' => true]);
?>
<main class="page scan-page">
  <a class="back" href="<?= h(app_href('config/index.php')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Config
  </a>
  <section class="panel" data-scan-panel data-scan-run-url="<?= h(app_href('video/scan-run.php')) ?>" data-grok-toggle-url="<?= h(app_href('video/scan-grok-toggle.php')) ?>" data-grok-pause-url="<?= h(app_href('video/scan-grok-pause.php')) ?>"<?= $runMode !== '' ? ' data-scan-start="retry"' : '' ?>>
    <p class="scan-kicker" data-scan-kicker><?= $stopping ? 'Stopping' : ($running ? 'In progress' : 'Ready') ?></p>
    <h1 data-scan-title><?= $stopping ? 'Stopping' : ($running ? 'Scanning' : 'Video scan') ?></h1>
    <p class="scan-live-copy" data-scan-message><?= h((string) ($status['message'] !== '' ? $status['message'] : $idleCopy)) ?></p>
    <div class="scan-bar<?= $running && empty($status['pending']) ? ' is-indeterminate' : '' ?>" aria-hidden="true"><span class="scan-bar-fill" data-scan-bar style="width: 0%"></span></div>
    <ul class="stat-grid">
      <li>
        <span class="stat-value" data-scan-found><?= (int) ($status['found'] ?? 0) ?></span>
        <span class="stat-label">TMDB matched</span>
      </li>
      <li>
        <span class="stat-value" data-scan-lookups><?= (int) ($status['lookups'] ?? 0) ?></span>
        <span class="stat-label">TMDB lookups</span>
      </li>
      <li>
        <span class="stat-value" data-scan-unmatched><?= (int) ($status['unmatched'] ?? 0) ?></span>
        <span class="stat-label">Unmatched</span>
      </li>
    </ul>
    <ul class="stat-grid" data-grok-dependent<?= $showGrokUi ? '' : ' hidden' ?>>
      <li>
        <span class="stat-value" data-scan-grok-matched><?= (int) ($status['grok_matched'] ?? 0) ?></span>
        <span class="stat-label">Grok matched</span>
      </li>
      <li>
        <span class="stat-value" data-scan-grok-attempted><?= (int) ($status['grok_attempted'] ?? 0) ?></span>
        <span class="stat-label">Grok attempted</span>
      </li>
      <li>
        <span class="stat-value" data-scan-grok-left><?= (int) ($status['grok_left'] ?? 0) ?></span>
        <span class="stat-label">Grok left</span>
      </li>
      <li>
        <span class="stat-value" data-scan-grok-cost><?= h((string) ($status['grok_cost_label'] ?? '$0.0000')) ?></span>
        <span class="stat-label">Est. Grok cost</span>
      </li>
    </ul>
    <?php if ($grokAvailable): ?>
    <div class="grok-toggles">
      <label class="grok-toggle">
        <input type="checkbox" data-grok-toggle<?= $grokOn ? ' checked' : '' ?>>
        <span>Use Grok in this scan</span>
      </label>
      <label class="grok-toggle" data-grok-dependent<?= $showGrokUi ? '' : ' hidden' ?>>
        <input type="checkbox" data-grok-pause-toggle<?= grok_dev_pause_each_batch() ? ' checked' : '' ?><?= $showGrokUi ? '' : ' disabled' ?>>
        <span>Pause after each Grok batch</span>
      </label>
    </div>
    <p class="hint" data-grok-hint-on<?= $showGrokUi ? '' : ' hidden' ?>>Grok runs after every 25 TMDB failures. Both toggles apply immediately without stopping the scan; the current xAI request finishes. Pause shows Continue after each batch.</p>
    <p class="hint" data-grok-hint-off<?= $showGrokUi ? ' hidden' : '' ?>>Grok is off. Scan uses TMDB only. You can turn Grok on at any time; the current TMDB work continues.</p>
    <?php else: ?>
    <p class="hint">Set an xAI Grok key in Config to enable stage 2. TMDB still runs on Scan.</p>
    <?php endif; ?>
    <div class="scan-launch" data-scan-launch-wrap<?= $running ? ' hidden' : '' ?>>
      <a class="btn btn-accent btn-block" href="<?= h(app_href('video/scan.php?run=retry')) ?>" data-scan-launch="retry">Scan</a>
    </div>
    <div class="grok-wait-actions<?= !empty($status['paused']) ? ' is-visible' : '' ?>" data-grok-wait-actions<?= empty($status['paused']) ? ' hidden' : '' ?>>
      <button type="button" class="btn btn-accent btn-block" data-grok-continue>Continue next batch</button>
    </div>
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

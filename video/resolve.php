<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/cache.php';
require dirname(__DIR__) . '/lib/settings.php';
require dirname(__DIR__) . '/lib/scanner.php';
require dirname(__DIR__) . '/lib/grok.php';

cache_init();
header('Cache-Control: no-store');

$hasKey = grok_has_key();
$status = grok_status_reconcile();
$running = ($status['state'] ?? '') === 'running';
$stopping = $running && !empty($status['cancel_requested']);
$start = $hasKey && isset($_GET['run']) && !$running;

render_start('Resolve unmatched · Video');
render_header(['section' => 'config', 'branches' => true]);
?>
<main class="page scan-page">
  <a class="back" href="<?= h(app_href('video/scan.php')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Scan
  </a>
  <section class="panel" data-grok-panel data-grok-run-url="<?= h(app_href('video/resolve-run.php')) ?>"<?= grok_dev_pause_each_batch() ? ' data-grok-pause="1"' : '' ?><?= $start ? ' data-grok-start="1"' : '' ?>>
    <p class="scan-kicker" data-grok-kicker><?= $running ? 'In progress' : (($status['state'] ?? '') === 'done' ? 'Done' : (($status['state'] ?? '') === 'error' ? 'Failed' : 'Ready')) ?></p>
    <h1 data-grok-title><?= $running ? 'Resolving unmatched' : 'Resolve unmatched (Grok)' ?></h1>
    <p class="scan-live-copy" data-grok-message><?php
        if (!$hasKey) {
            echo 'Set XAI_API_KEY in Config or lib/config.php. Grok is not called until a key is saved.';
        } elseif (($status['message'] ?? '') !== '') {
            echo h((string) $status['message']);
        } else {
            echo 'TMDB stays first. Grok only looks at unmatched titles and picks from that file’s TMDB candidate list.';
        }
    ?></p>
    <ul class="stat-grid">
      <li>
        <span class="stat-value" data-grok-matched><?= (int) ($status['matched'] ?? 0) ?></span>
        <span class="stat-label">Matched</span>
      </li>
      <li>
        <span class="stat-value" data-grok-attempted><?= (int) ($status['attempted'] ?? 0) ?></span>
        <span class="stat-label">Attempted</span>
      </li>
      <li>
        <span class="stat-value" data-grok-pending><?= (int) ($status['pending'] ?? 0) ?></span>
        <span class="stat-label">Left</span>
      </li>
    </ul>
    <ul class="stat-grid">
      <li>
        <span class="stat-value" data-grok-low><?= (int) ($status['skipped_low_confidence'] ?? 0) ?></span>
        <span class="stat-label">Low confidence</span>
      </li>
      <li>
        <span class="stat-value" data-grok-invalid><?= (int) ($status['invalid_rejected'] ?? 0) ?></span>
        <span class="stat-label">Rejected ids</span>
      </li>
      <li>
        <span class="stat-value" data-grok-errors><?= (int) ($status['errors'] ?? 0) ?></span>
        <span class="stat-label">Errors</span>
      </li>
    </ul>
    <p class="hint">Batches of <?= (int) grok_batch_size() ?> files on <?= h(XAI_MODEL) ?>. Existing matches are not cleared if a request fails.</p>
    <?php if ($hasKey): ?>
    <div class="scan-launch" data-grok-launch-wrap<?= $running ? ' hidden' : '' ?>>
      <a class="btn btn-accent btn-block" href="<?= h(app_href('video/resolve.php?run=1')) ?>" data-grok-launch>Resolve unmatched (Grok)</a>
    </div>
    <div class="grok-wait-actions<?= $running && !$stopping ? ' is-visible' : '' ?>" data-grok-wait-actions>
      <button type="button" class="btn btn-accent btn-block" data-grok-continue>Continue next batch</button>
    </div>
    <form method="post" action="<?= h(app_href('video/resolve-stop.php')) ?>" data-grok-stop>
      <button type="submit" class="btn btn-ghost btn-block" data-grok-stop-btn<?= $running ? '' : ' hidden' ?><?= $stopping ? ' disabled' : '' ?>><?= $stopping ? 'Stopping…' : 'Stop Grok' ?></button>
    </form>
    <?php else: ?>
    <button type="button" class="btn btn-ghost btn-block" disabled>Resolve unmatched (Grok)</button>
    <?php endif; ?>
    <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/index.php')) ?>">Open Video catalog</a>
  </section>
</main>
<?php
render_end();

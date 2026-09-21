<?php
declare(strict_types=1);

/** @var string $catalog */
/** @var bool $scanEnabled */
/** @var bool $running */
/** @var bool $stopping */
/** @var array $status */
/** @var string $idleCopy */
/** @var bool $grokAvailable */
/** @var bool $grokOn */
/** @var bool $showGrokUi */
/** @var array $scanOptions */
/** @var string $runMode */

$catalog = scan_catalog_normalize($catalog ?? 'video');
$title = scan_catalog_title($catalog);
$noun = scan_catalog_label($catalog);
$scanEnabled = !empty($scanEnabled);
$isMusic = $catalog === 'music';
$prefix = $catalog . '/';
$options = is_array($scanOptions ?? null) ? $scanOptions : scan_options_defaults();
$backHref = app_href('config/index.php?tab=' . $catalog);
$launchHref = app_href($prefix . 'scan.php?run=retry');
$stopHref = app_href($prefix . 'scan-stop.php');
$runUrl = app_href($prefix . 'scan-run.php');
$optionsUrl = app_href($prefix . 'scan-options.php');
$grokToggleUrl = app_href('video/scan-grok-toggle.php');
$grokPauseUrl = app_href('video/scan-grok-pause.php');
$launchHidden = $running;
$stopLabel = $stopping ? 'Stopping…' : ('Stop ' . $noun . ' scan');
$h1 = $stopping
    ? ('Stopping ' . $noun . ' scan')
    : ($running ? ('Scanning ' . $noun) : ($title . ' scan'));
$kicker = $stopping ? 'Stopping' : ($running ? 'In progress' : 'Ready');
$optionsHeading = $title . ' Scan Options';
$optionsSummary = $isMusic
    ? 'Music scanning is not available yet. The start button stays off until Music is built out.'
    : 'Grok toggles apply immediately, even during a scan.';
$advancedSummary = $isMusic
    ? 'These choices will pick which files a music scan looks up when scanning is available.'
    : 'Choose which catalog files the next scan looks up. Unidentified and unmatched are on by default. Turn on a matched option only when you want those titles sent to TMDB again. Titles you matched yourself are never looked up. These choices apply when you start a scan.';
$launchLabel = 'Start a ' . $title . ' scan';
?>
<main class="page scan-page">
  <a class="back" href="<?= h($backHref) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Config
  </a>
  <section class="panel" data-scan-panel data-scan-catalog="<?= h($catalog) ?>" data-scan-enabled="<?= $scanEnabled ? '1' : '0' ?>" data-scan-idle="<?= h($idleCopy) ?>" data-scan-run-url="<?= h($runUrl) ?>" data-scan-options-url="<?= h($optionsUrl) ?>" data-grok-toggle-url="<?= h($grokToggleUrl) ?>" data-grok-pause-url="<?= h($grokPauseUrl) ?>"<?= $runMode !== '' && $scanEnabled ? ' data-scan-start="retry"' : '' ?>>
    <p class="scan-kicker" data-scan-kicker><?= h($kicker) ?></p>
    <h1 id="scan-status-heading" data-scan-title><?= h($h1) ?></h1>
    <p class="scan-live-copy" data-scan-message><?= h((string) (($status['message'] ?? '') !== '' ? $status['message'] : $idleCopy)) ?></p>
    <div class="scan-bar<?= $running && empty($status['pending']) ? ' is-indeterminate' : '' ?>" aria-hidden="true"><span class="scan-bar-fill" data-scan-bar style="width: 0%"></span></div>
    <ul class="stat-grid">
      <li>
        <span class="stat-value" data-scan-found><?= (int) ($status['found'] ?? 0) ?></span>
        <span class="stat-label"><?= $isMusic ? 'Matched' : 'TMDB matched' ?></span>
      </li>
      <li>
        <span class="stat-value" data-scan-lookups><?= (int) ($status['lookups'] ?? 0) ?></span>
        <span class="stat-label"><?= $isMusic ? 'Lookups' : 'TMDB lookups' ?></span>
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
    <div class="scan-launch" data-scan-launch-wrap<?= $launchHidden ? ' hidden' : '' ?>>
      <?php if ($scanEnabled): ?>
      <a class="btn btn-accent btn-block" href="<?= h($launchHref) ?>" data-scan-launch="retry"><?= h($launchLabel) ?></a>
      <?php else: ?>
      <button type="button" class="btn btn-accent btn-block" disabled><?= h($launchLabel) ?></button>
      <?php endif; ?>
    </div>
    <div class="grok-wait-actions<?= !empty($status['paused']) ? ' is-visible' : '' ?>" data-grok-wait-actions<?= empty($status['paused']) ? ' hidden' : '' ?>>
      <button type="button" class="btn btn-accent btn-block" data-grok-continue>Continue next batch</button>
    </div>
    <form method="post" action="<?= h($stopHref) ?>" data-scan-stop>
      <button type="submit" class="btn btn-ghost btn-block" data-scan-stop-btn<?= $running ? '' : ' hidden' ?><?= $stopping ? ' disabled' : '' ?>><?= h($stopLabel) ?></button>
    </form>
  </section>

  <section class="panel scan-options-panel" aria-labelledby="scan-options-heading">
    <h2 id="scan-options-heading"><?= h($optionsHeading) ?></h2>
    <p class="hint"><?= h($optionsSummary) ?></p>
    <?php if (!$isMusic): ?>
    <?php if ($grokAvailable): ?>
    <h3 class="scan-options-sub">Grok</h3>
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
    <p class="hint" data-grok-hint-off<?= $showGrokUi ? ' hidden' : '' ?>>Grok is off. Video scan uses TMDB only. You can turn Grok on at any time; the current TMDB work continues.</p>
    <?php else: ?>
    <h3 class="scan-options-sub">Grok</h3>
    <p class="hint">Set an xAI Grok key in Config to enable stage 2. TMDB still runs on a video scan.</p>
    <?php endif; ?>
    <?php endif; ?>
    <details class="scan-advanced">
      <summary>
        <span class="expand-caret" aria-hidden="true"></span>
        Advanced
      </summary>
      <h3 class="scan-options-sub">Files to look up</h3>
      <p class="hint"><?= h($advancedSummary) ?></p>
      <div class="scan-option-grid">
        <label class="grok-toggle">
          <input type="checkbox" data-scan-option="unidentified"<?= !empty($options['unidentified']) ? ' checked' : '' ?>>
          <span>Unidentified</span>
        </label>
        <label class="grok-toggle">
          <input type="checkbox" data-scan-option="unmatched"<?= !empty($options['unmatched']) ? ' checked' : '' ?>>
          <span>Unmatched</span>
        </label>
        <label class="grok-toggle">
          <input type="checkbox" data-scan-option="matched_grok"<?= !empty($options['matched_grok']) ? ' checked' : '' ?>>
          <span>Matched (Grok)</span>
        </label>
        <label class="grok-toggle">
          <input type="checkbox" data-scan-option="matched_direct"<?= !empty($options['matched_direct']) ? ' checked' : '' ?>>
          <span>Matched (Direct)</span>
        </label>
      </div>
    </details>
  </section>
</main>
<div class="scan-live-bar" data-scan-live-bar>
  <span class="scan-live-bar-spin" data-scan-bar-spin<?= $running ? '' : ' hidden' ?> aria-hidden="true"></span>
  <p data-scan-message><?= h((string) (($status['message'] ?? '') !== '' ? $status['message'] : 'Waiting to start…')) ?></p>
</div>
<?php

<?php
declare(strict_types=1);

/** @var array<string, mixed> $state */
/** @var bool $configured */
/** @var bool $kodiOn */
/** @var string $kodiPlayLabel */

$configured = !empty($configured);
$kodiOn = !empty($kodiOn);
$kodiPlayLabel = (string) ($kodiPlayLabel ?? 'Play on Kodi');
$stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($stateJson === false) {
    $stateJson = '{}';
}

render_start('Smart Search · Media Informant');
render_header(['section' => 'video', 'branches' => true]);
?>
<main class="page smart-search-page" data-smart-search data-configured="<?= $configured ? '1' : '0' ?>" data-run-url="<?= h(app_href('video/smart-run.php')) ?>" data-kodi-url="<?= h(app_href('video/play-kodi.php')) ?>" data-kodi="<?= $kodiOn ? '1' : '0' ?>" data-kodi-label="<?= h($kodiPlayLabel) ?>">
  <?php if (!$configured): ?>
  <section class="empty-state">
    <h1>Smart Search</h1>
    <p>Add a Grok API key in Config to ask questions about titles already in your library.</p>
    <a class="btn btn-accent" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
  </section>
  <?php else: ?>
  <div class="smart-search-bar">
    <a class="btn btn-ghost" href="<?= h(app_href('video/index.php')) ?>">Library</a>
    <h1>Smart Search</h1>
    <button type="button" class="btn btn-ghost" data-smart-new>New search</button>
  </div>
  <p class="smart-search-cost" data-smart-cost>This turn <?= h((string) ($state['turn_cost'] ?? '$0.0000')) ?> · Chat <?= h((string) ($state['total_cost'] ?? '$0.0000')) ?></p>
  <div class="smart-search-log" data-smart-log></div>
  <p class="form-banner is-error" data-smart-error hidden></p>
  <div class="smart-search-actions" data-smart-retry-wrap hidden>
    <button type="button" class="btn btn-ghost" data-smart-retry>Try again</button>
  </div>
  <form class="smart-search-form" data-smart-form>
    <label class="visually-hidden" for="smart-q">Ask about your library</label>
    <textarea id="smart-q" data-smart-input rows="2" maxlength="2000" placeholder="sci-fi from the 80s with time travel" autocomplete="off"></textarea>
    <button type="submit" class="btn btn-accent" data-smart-send>Send</button>
  </form>
  <script type="application/json" id="smart-search-state"><?= $stateJson ?></script>
  <?php endif; ?>
</main>
<?php
render_end();

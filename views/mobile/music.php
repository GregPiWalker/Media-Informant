<?php
declare(strict_types=1);
?>
<main class="page">
  <section class="empty-state music-stub">
    <div class="empty-art" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="64" height="64">
        <circle cx="18" cy="46" r="8" fill="none" stroke="currentColor" stroke-width="2"/>
        <circle cx="44" cy="40" r="8" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M26 46V16l26-6v30" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
      </svg>
    </div>
    <p class="scan-kicker">Coming later</p>
    <h1>Music</h1>
    <p>Refresh Source Folders on the Music tab in Config records music files from saved folders that are present. An unplugged drive is skipped and those files stay in the catalog. This page does not list tracks yet, and it does not read artist, album, or cover art.</p>
    <a class="btn btn-accent" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
    <a class="btn btn-ghost" href="<?= h(app_href('music/scan.php')) ?>">Open music scan page</a>
  </section>
</main>

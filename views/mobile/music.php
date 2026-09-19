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
    <p>This catalog will scan the music folders from Config, read artist and album from folders and tags, and show cover art so you can browse tracks on the NAS. Nothing is scanned or cached here yet. You can already set those folders in Config.</p>
    <a class="btn btn-accent" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
    <a class="btn btn-ghost" href="<?= h(app_href('music/scan.php')) ?>">Open music scan page</a>
  </section>
</main>

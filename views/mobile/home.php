<?php
declare(strict_types=1);
?>
<main class="page home-page">
  <header class="home-intro">
    <p class="home-kicker">Libraries</p>
    <h1>What do you want to browse?</h1>
    <p>Two catalogs on this NAS. Open Video or Music. Use the gear for sources and API settings.</p>
  </header>

  <section class="feature-list" aria-label="Libraries">
    <a class="feature-card feature-card-video" href="<?= h(app_href('video/index.php')) ?>">
      <span class="feature-icon" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32">
          <rect x="3" y="6" width="26" height="20" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/>
          <path d="M7 6v20M25 6v20M3 12h4M3 20h4M25 12h4M25 20h4" fill="none" stroke="currentColor" stroke-width="1.8"/>
          <path d="M13 12.5l8 3.5-8 3.5v-7z" fill="currentColor"/>
        </svg>
      </span>
      <h2>Video</h2>
      <p>Movies, TV shows, and other video on your shares. Scan for posters, overviews, and cast.</p>
      <span class="feature-cta">Open Video</span>
    </a>

    <a class="feature-card feature-card-music" href="<?= h(app_href('music/index.php')) ?>">
      <span class="feature-icon" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32">
          <circle cx="9" cy="22" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/>
          <circle cx="22" cy="19" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/>
          <path d="M13 22V8.5l13-3V19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        </svg>
      </span>
      <h2>Music</h2>
      <p>Albums and tracks from your music share. The catalog is a placeholder until Music is built out.</p>
      <span class="feature-cta">Open Music</span>
    </a>
  </section>
</main>

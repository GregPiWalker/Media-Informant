<?php
declare(strict_types=1);

/** @var array<string, mixed>|null $item */
/** @var string $id */
/** @var string $error */
/** @var string $q */
/** @var string $yearRaw */
/** @var int|null $year */
/** @var string $mode */
/** @var list<array<string, mixed>> $results */
/** @var int $page */
/** @var int $totalPages */
/** @var int $currentTmdb */
/** @var string $currentLookup */
/** @var string $searchSource */
/** @var bool $hasKey */
/** @var bool $canSearch */
/** @var bool $relaxedYear */
/** @var string $parsedTitle */
/** @var string $grokNote */
/** @var string $scanServiceNote */

$scanServiceNote = isset($scanServiceNote) ? (string) $scanServiceNote : '';

$searchSource = isset($searchSource) ? (string) $searchSource : 'TMDB';
$canSearch = isset($canSearch) ? (bool) $canSearch : (bool) ($hasKey ?? false);
$currentLookup = isset($currentLookup) ? (string) $currentLookup : '';
$onRiff = $searchSource === 'RiffTrax';
$found = is_array($item);
$display = $found ? (string) ($item['display_title'] ?? $item['title'] ?? 'Untitled') : '';
$filePath = $found ? format_source_path($item) : '';
$status = $found ? (string) ($item['status'] ?? 'unmatched') : 'unmatched';
$manual = $found && (($item['match_source'] ?? '') === 'manual');

render_start('Match title · Media Informant');
render_header(['section' => 'video']);

$query = [
    'id' => $id,
    'q' => $q,
    'year' => $yearRaw,
];
if ($mode === 'popular') {
    $query['browse'] = 'popular';
    unset($query['q'], $query['year']);
}
?>
<main class="page match-page">
  <a class="back" href="<?= h(app_href('video/title.php?id=' . $id)) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Back
  </a>

  <?php if (!$found): ?>
  <section class="empty-state">
    <h1>Title not found</h1>
    <p>This file is not in the cached catalog.</p>
    <a class="btn btn-accent" href="<?= h(app_href('video/index.php')) ?>">Back to Video</a>
  </section>
  <?php else: ?>
  <header class="home-intro match-intro">
    <h1><?= $status === 'matched' ? 'Change match' : 'Match title' ?></h1>
    <p><?= $onRiff
        ? 'Pick the RiffTrax title for this file. Scan will keep a choice you make here.'
        : 'Pick the TMDB title for this file. Scan will keep a choice you make here.' ?></p>
    <p class="file-path"><?= h($filePath !== '' ? $filePath : (string) ($item['filename'] ?? '')) ?></p>
    <?php if ($parsedTitle !== ''): ?>
    <p class="hint">From the filename: <?= h($parsedTitle) ?><?= $parsedYear !== null ? ' (' . h((string) $parsedYear) . ')' : '' ?></p>
    <?php endif; ?>
    <?php if ($status === 'matched'): ?>
    <p class="hint">Current: <?= h($display) ?> · <?= h(library_match_source_label(library_match_source($item))) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($error !== ''): ?>
  <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if (!$canSearch): ?>
  <section class="panel">
    <h1><?php
      if ($onRiff) {
          echo 'RiffTrax lookup unavailable';
      } elseif ($scanServiceNote !== '') {
          echo 'Lookup unavailable';
      } else {
          echo 'TMDB key needed';
      }
    ?></h1>
    <p><?php
      if ($onRiff) {
          echo 'This PHP build has no curl extension, so RiffTrax cannot be reached.';
      } elseif ($scanServiceNote !== '') {
          echo h($scanServiceNote);
      } else {
          echo 'Add an API key in Config to search and browse titles.';
      }
    ?></p>
    <?php if ($scanServiceNote !== ''): ?>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('video/match.php?id=' . rawurlencode($id) . '&source=tmdb')) ?>">Search TMDB instead</a>
    <?php elseif (!$onRiff): ?>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <form class="match-search" method="get" action="<?= h(app_href('video/match.php')) ?>">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <input type="hidden" name="source" value="<?= h($onRiff ? 'rifftrax' : 'tmdb') ?>">
    <label class="visually-hidden" for="match-q"><?= $onRiff ? 'Search RiffTrax' : 'Search TMDB' ?></label>
    <input id="match-q" type="search" name="q" class="folder-input" value="<?= h($q) ?>" placeholder="<?= $onRiff ? 'Search RiffTrax titles' : (((string) ($item['kind'] ?? '') === 'show') ? 'Search TV titles' : 'Search movie titles') ?>" enterkeyhint="search" spellcheck="false" autocapitalize="off">
    <label class="visually-hidden" for="match-year">Year</label>
    <input id="match-year" type="number" name="year" class="folder-input match-year" value="<?= h($yearRaw) ?>" placeholder="Year" inputmode="numeric" min="1870" max="2100">
    <button type="submit" class="btn btn-accent">Search</button>
  </form>
  <p class="match-browse">
    Supply an optional <strong>Year</strong> to improve search results.
    <?php if ($onRiff): ?>
    <a href="<?= h(app_href('video/match.php?id=' . rawurlencode($id) . '&source=tmdb')) ?>">Search TMDB instead</a>
    <?php else: ?>
    <a href="<?= h(app_href('video/match.php?id=' . rawurlencode($id) . '&browse=popular&source=tmdb')) ?>">Browse popular titles</a>
    when the title search fails.
    <a href="<?= h(app_href('video/match.php?id=' . rawurlencode($id) . '&source=rifftrax&q=' . rawurlencode($q))) ?>">Search RiffTrax instead</a>
    <?php endif; ?>
  </p>

  <?php if (($grokNote ?? '') !== ''): ?>
  <p class="hint"><?= h($grokNote) ?></p>
  <?php endif; ?>

  <p class="catalog-meta">
    <?php if ($mode === 'popular'): ?>
    Popular on TMDB
    <?php elseif ($onRiff): ?>
    RiffTrax results
    <?php elseif ($relaxedYear): ?>
    No hits for that year — showing all years
    <?php else: ?>
    Search results
    <?php endif; ?>
  </p>

  <?php if ($results === []): ?>
  <section class="empty-state">
    <h1>No titles</h1>
    <p><?php
      if ($onRiff) {
          echo 'No RiffTrax title matched. Try the movie name on its own, or search TMDB.';
      } elseif ($mode === 'popular') {
          echo 'Could not load popular titles. Check the API key and network.';
      } else {
          echo 'Try a shorter name, drop the year, or browse popular titles.';
      }
    ?></p>
  </section>
  <?php else: ?>
  <ol class="match-list">
    <?php foreach ($results as $row):
        $rowSource = (string) ($row['source'] ?? 'TMDB');
        $rowRiff = strcasecmp($rowSource, 'RiffTrax') === 0;
        $tid = (int) ($row['tmdb_id'] ?? 0);
        $rowLookup = (string) ($row['lookup_id'] ?? '');
        $poster = poster_url(isset($row['poster_path']) && is_string($row['poster_path']) ? $row['poster_path'] : null);
        $isCurrent = $rowRiff
            ? ($currentLookup !== '' && $rowLookup === $currentLookup)
            : ($tid > 0 && $currentTmdb === $tid);
        $kindLabel = (string) ($row['product_kind'] ?? '');
        ?>
    <li>
      <form method="post" action="<?= h(app_href('video/match.php?id=' . rawurlencode($id))) ?>">
        <input type="hidden" name="id" value="<?= h($id) ?>">
        <input type="hidden" name="lookup_source" value="<?= h($rowRiff ? 'RiffTrax' : 'TMDB') ?>">
        <input type="hidden" name="lookup_id" value="<?= h($rowLookup) ?>">
        <input type="hidden" name="tmdb_id" value="<?= (int) $tid ?>">
        <input type="hidden" name="media_type" value="<?= h((string) ($row['media_type'] ?? (((string) ($item['kind'] ?? '') === 'show') ? 'tv' : 'movie'))) ?>">
        <button type="submit" name="action" value="choose" class="match-choice<?= $isCurrent ? ' is-current' : '' ?>">
          <span class="match-choice-poster" aria-hidden="true">
            <?php if ($poster): ?>
            <img src="<?= h($poster) ?>" alt="" width="92" height="138" loading="lazy" decoding="async">
            <?php else: ?>
            <span class="poster-fallback"><?= h(initial((string) $row['title'])) ?></span>
            <?php endif; ?>
          </span>
          <span class="match-choice-body">
            <span class="match-choice-title"><?= h((string) $row['title']) ?></span>
            <span class="match-choice-year">
              <?= !empty($row['year']) ? h((string) $row['year']) : 'Year unknown' ?>
              <?php if ($rowRiff): ?><span class="badge"><?= $kindLabel === 'iriff' ? 'iRiff' : 'RiffTrax' ?></span><?php endif; ?>
              <?php if ($isCurrent): ?><span class="badge">Current</span><?php endif; ?>
            </span>
            <?php if ((string) ($row['overview'] ?? '') !== ''): ?>
            <span class="match-choice-overview"><?= h((string) $row['overview']) ?></span>
            <?php endif; ?>
          </span>
        </button>
      </form>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($page < $totalPages):
      $more = $query;
      $more['id'] = $id;
      $more['page'] = $page + 1;
      if ($mode === 'popular') {
          $more['browse'] = 'popular';
      }
      ?>
  <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/match.php?' . http_build_query($more))) ?>">More titles</a>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($status === 'matched'): ?>
  <form method="post" action="<?= h(app_href('video/match.php?id=' . rawurlencode($id))) ?>" class="match-clear">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <button type="submit" name="action" value="clear" class="btn btn-ghost btn-block">Remove match</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</main>
<?php
render_end();

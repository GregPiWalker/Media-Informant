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
/** @var bool $hasKey */
/** @var bool $relaxedYear */
/** @var string $parsedTitle */

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
    <p>Pick the TMDB title for this file. Scan will keep a choice you make here.</p>
    <p class="file-path"><?= h($filePath !== '' ? $filePath : (string) ($item['filename'] ?? '')) ?></p>
    <?php if ($parsedTitle !== ''): ?>
    <p class="hint">From the filename: <?= h($parsedTitle) ?><?= $item['year'] ?? null ? ' (' . h((string) $item['year']) . ')' : '' ?></p>
    <?php endif; ?>
    <?php if ($status === 'matched'): ?>
    <p class="hint">Current: <?= h($display) ?> · <?= h(library_match_source_label(library_match_source($item))) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($error !== ''): ?>
  <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if (!$hasKey): ?>
  <section class="panel">
    <h1>TMDB key needed</h1>
    <p>Add an API key in Config to search and browse titles.</p>
    <a class="btn btn-accent btn-block" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
  </section>
  <?php else: ?>
  <form class="match-search" method="get" action="<?= h(app_href('video/match.php')) ?>">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <label class="visually-hidden" for="match-q">Search TMDB</label>
    <input id="match-q" type="search" name="q" class="folder-input" value="<?= h($q) ?>" placeholder="<?= (string) ($item['kind'] ?? '') === 'show' ? 'Search TV titles' : 'Search movie titles' ?>" enterkeyhint="search" spellcheck="false" autocapitalize="off">
    <label class="visually-hidden" for="match-year">Year</label>
    <input id="match-year" type="number" name="year" class="folder-input match-year" value="<?= h($yearRaw) ?>" placeholder="Year" inputmode="numeric" min="1870" max="2100">
    <button type="submit" class="btn btn-accent">Search</button>
  </form>
  <p class="match-browse">
    <a href="<?= h(app_href('video/match.php?id=' . rawurlencode($id) . '&browse=popular')) ?>">Browse popular titles</a>
    when the filename doesn’t help.
  </p>

  <p class="catalog-meta">
    <?php if ($mode === 'popular'): ?>
    Popular on TMDB
    <?php elseif ($relaxedYear): ?>
    No hits for that year — showing all years
    <?php else: ?>
    Search results
    <?php endif; ?>
  </p>

  <?php if ($results === []): ?>
  <section class="empty-state">
    <h1>No titles</h1>
    <p><?= $mode === 'popular' ? 'Could not load popular titles. Check the API key and network.' : 'Try a shorter name, drop the year, or browse popular titles.' ?></p>
  </section>
  <?php else: ?>
  <ol class="match-list">
    <?php foreach ($results as $row):
        $tid = (int) $row['tmdb_id'];
        $poster = poster_url(isset($row['poster_path']) && is_string($row['poster_path']) ? $row['poster_path'] : null);
        $isCurrent = $currentTmdb === $tid;
        ?>
    <li>
      <form method="post" action="<?= h(app_href('video/match.php?id=' . rawurlencode($id))) ?>">
        <input type="hidden" name="id" value="<?= h($id) ?>">
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

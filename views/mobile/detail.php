<?php
declare(strict_types=1);

/** @var array<string, mixed>|null $item */
/** @var array<string, mixed>|null $meta */
/** @var bool $editing */
/** @var string $error */

$found = is_array($item);
$display = 'Not found';
$year = null;
$overview = '';
$genres = [];
$cast = [];
$path = '';
$status = 'unmatched';
$matchSource = 'none';
$poster = null;
$manual = false;
$itemId = '';
$sourceAbsent = false;
$editing = !empty($editing);
$error = (string) ($error ?? '');

if ($found) {
    $display = (string) ($item['display_title'] ?? $item['title'] ?? 'Untitled');
    $year = $item['year'] ?? null;
    $path = format_source_path($item);
    if ($path === '') {
        $path = (string) ($item['filename'] ?? '');
    }
    $status = library_item_status($item);
    $matchSource = library_match_source($item);
    $manual = $matchSource === 'manual';
    $itemId = (string) ($item['id'] ?? '');
    $sourceAbsent = function_exists('source_is_absent_for_item') && source_is_absent_for_item($item);
    $poster = poster_url(isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : null);
    if (array_key_exists('genres', $item) && is_array($item['genres'])) {
        $genres = cache_string_list($item['genres']);
    }
    if (array_key_exists('overview', $item) && is_string($item['overview'])) {
        $overview = $item['overview'];
    }

    if (is_array($meta)) {
        if ($display === '' && !empty($meta['title'])) {
            $display = (string) $meta['title'];
        }
        if (!empty($meta['year'])) {
            $year = (int) $meta['year'];
        }
        if ($overview === '' && !array_key_exists('overview', $item)) {
            $overview = (string) ($meta['overview'] ?? '');
        }
        if ($genres === []) {
            $genres = cache_string_list($meta['genres'] ?? []);
        }
        $poster = poster_url(isset($meta['poster_path']) && is_string($meta['poster_path']) ? $meta['poster_path'] : ($item['poster_path'] ?? null));
        if (isset($meta['cast']) && is_array($meta['cast'])) {
            foreach ($meta['cast'] as $name) {
                if (is_string($name) && $name !== '') {
                    $cast[] = $name;
                }
            }
        }
    }
}

$kind = $found ? library_item_kind($item) : 'movie';
$seasonNum = null;
$episodeNum = null;
$episodeTitle = '';
if ($found) {
    if (isset($item['season']) && $item['season'] !== '' && $item['season'] !== null) {
        $seasonNum = (int) $item['season'];
    }
    if (isset($item['episode']) && $item['episode'] !== '' && $item['episode'] !== null) {
        $episodeNum = (int) $item['episode'];
    }
    $episodeTitle = trim((string) ($item['episode_title'] ?? ''));
}
$isEpisode = $kind === 'show' && ($seasonNum !== null || $episodeNum !== null || $episodeTitle !== '');
$showHeading = $display;
if ($isEpisode) {
    $showHeading .= ' - ' . ($year ? (string) $year : 'Year unknown');
}
$episodeCodeBits = [];
if ($seasonNum !== null) {
    $episodeCodeBits[] = 'Season ' . $seasonNum;
}
if ($episodeNum !== null) {
    $episodeCodeBits[] = 'Episode ' . $episodeNum;
}
$episodeCode = implode(' - ', $episodeCodeBits);
$matchNote = '';
$matchClass = 'hint';
if ($found) {
    if ($status === 'unidentified') {
        $matchNote = 'Not looked up yet. Scan unidentified titles to search TMDB, or match it from the menu.';
        $matchClass = 'unmatched-note';
    } elseif ($status !== 'matched') {
        $matchNote = 'No confident TMDB match. Showing the title parsed from the folder or filename.';
        $matchClass = 'unmatched-note';
    } elseif ($matchSource === 'manual') {
        $matchNote = 'Matched by you. Scan will keep this title.';
    } elseif ($matchSource === 'grok') {
        $matchNote = 'Matched by Grok. Scan will keep this title.';
    } elseif ($matchSource === 'direct') {
        $matchNote = 'Matched directly by TMDB.';
    }
}

$pageTitle = $found ? $display . ' · Media Informant' : 'Not found · Media Informant';
$gkey = (string) ($gkey ?? '');
$backHref = $gkey !== ''
    ? app_href('video/group.php?key=' . rawurlencode($gkey))
    : app_href('video/index.php');
$titleQs = $itemId !== '' ? ('id=' . rawurlencode($itemId) . ($gkey !== '' ? '&gkey=' . rawurlencode($gkey) : '')) : '';

render_start($pageTitle);
render_header(['section' => 'video']);
?>
<main class="page detail-page<?= $sourceAbsent ? ' is-source-absent' : '' ?>">
  <div class="detail-toolbar">
    <a class="back" href="<?= h($backHref) ?>">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back
    </a>
    <?php if ($found && $itemId !== ''): ?>
    <div class="page-menu" data-menu>
      <button type="button" class="btn btn-icon" data-menu-btn aria-expanded="false" aria-controls="detail-menu" aria-haspopup="true" aria-label="Title actions">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M4 6.75h16v1.8H4zm0 4.85h16v1.8H4zm0 4.85h16v1.8H4z"/>
        </svg>
      </button>
      <div class="page-menu-panel" id="detail-menu" data-menu-panel hidden role="menu" aria-label="Title actions">
        <a class="page-menu-item" role="menuitem" href="<?= h(app_href('video/match.php?id=' . $itemId . ($gkey !== '' ? '&gkey=' . rawurlencode($gkey) : ''))) ?>">
          <?= $status === 'matched' ? 'Change match' : 'Match title' ?>
        </a>
        <?php if (!$editing): ?>
        <a class="page-menu-item" role="menuitem" href="<?= h(app_href('video/title.php?' . $titleQs . '&edit=1')) ?>">Edit Data</a>
        <?php endif; ?>
        <?php if ($status === 'matched' || $status === 'unmatched'): ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="clear_match">
          <button type="submit" class="page-menu-item" role="menuitem">Clear Match</button>
        </form>
        <?php endif; ?>
        <?php if (library_item_kind($item) !== 'show'): ?>
        <?php if (library_item_grouped($item)): ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="treat_individual">
          <button type="submit" class="page-menu-item" role="menuitem">Treat Individually</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="treat_series">
          <button type="submit" class="page-menu-item" role="menuitem">Treat as Series</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        $folderHome = function_exists('folder_home_for_file') ? folder_home_for_file((string) ($item['path'] ?? '')) : '';
        $folderKind = ($folderHome !== '' && function_exists('folder_kind_for_file'))
            ? folder_kind_for_file((string) ($item['root'] ?? ''), (string) ($item['path'] ?? ''))
            : '';
        if ($folderHome !== ''):
        ?>
        <?php if ($folderKind === 'show'): ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="folder_as_movie">
          <button type="submit" class="page-menu-item" role="menuitem">Treat Folder as Movies</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="folder_as_show">
          <button type="submit" class="page-menu-item" role="menuitem">Treat Folder as TV Show</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="exclude_parent">
          <button type="submit" class="page-menu-item" role="menuitem">Exclude Parent Dir</button>
        </form>
        <?php if (library_item_hidden($item)): ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="unhide">
          <button type="submit" class="page-menu-item" role="menuitem">Unhide</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>" role="none">
          <input type="hidden" name="id" value="<?= h($itemId) ?>">
          <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="hide">
          <button type="submit" class="page-menu-item" role="menuitem">Hide</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$found): ?>
  <section class="empty-state">
    <h1>Title not found</h1>
    <p>This item is not in the cached catalog. Scan again if the file still exists.</p>
    <a class="btn btn-accent" href="<?= h(app_href('video/index.php')) ?>">Back to Video</a>
  </section>
  <?php else: ?>
  <article class="detail<?= $editing ? ' is-editing' : '' ?>">
    <?php if ($error !== ''): ?>
    <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <div class="detail-poster poster">
      <?php if ($poster): ?>
      <img src="<?= h($poster) ?>" alt="" width="342" height="513" decoding="async">
      <?php else: ?>
      <div class="poster-fallback" aria-hidden="true">
        <span><?= h(initial($display)) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <header class="detail-header">
      <?php if ($isEpisode): ?>
      <h1><?= h($showHeading) ?></h1>
      <?php if ($episodeCode !== ''): ?>
      <p class="detail-episode-code"><?= h($episodeCode) ?></p>
      <?php endif; ?>
      <?php if ($episodeTitle !== ''): ?>
      <p class="detail-episode-title"><?= h($episodeTitle) ?></p>
      <?php endif; ?>
      <?php else: ?>
      <?php if (!$editing): ?>
      <h1><?= h($display) ?></h1>
      <?php endif; ?>
      <p class="detail-year"><?= $year ? h((string) $year) : 'Year unknown' ?></p>
      <?php endif; ?>
      <?php if (library_item_hidden($item)): ?>
      <p class="hint">This file is hidden from the catalog.</p>
      <?php endif; ?>
    </header>

    <?php if ($editing): ?>
    <form class="detail-edit" method="post" action="<?= h(app_href('video/title.php?' . $titleQs)) ?>">
      <input type="hidden" name="id" value="<?= h($itemId) ?>">
      <?php if ($gkey !== ''): ?><input type="hidden" name="gkey" value="<?= h($gkey) ?>"><?php endif; ?>
      <input type="hidden" name="action" value="save_data">
      <section class="detail-section">
        <h2>Title</h2>
        <label class="visually-hidden" for="edit-title">Title</label>
        <input id="edit-title" type="text" name="display_title" class="title-input folder-input" value="<?= h($display) ?>" maxlength="200" required autocomplete="off" spellcheck="false">
        <p class="hint">This name is only for this file. Two files can share one TMDB match — for example Part 1 and Part 2 of the same movie.</p>
      </section>
      <section class="detail-section detail-overview">
        <h2>Overview</h2>
        <label class="visually-hidden" for="edit-overview">Overview</label>
        <textarea id="edit-overview" name="overview" class="overview-input" rows="8" maxlength="8000"><?= h($overview) ?></textarea>
      </section>
      <section class="detail-section">
        <h2>Genres</h2>
        <ul class="genre-edit" data-genre-list>
          <?php foreach ($genres as $genre): ?>
          <li class="genre-edit-row" data-genre-row>
            <input type="hidden" name="genres[]" value="<?= h($genre) ?>">
            <span class="genre-edit-label"><?= h($genre) ?></span>
            <button type="button" class="btn btn-ghost" data-remove-genre aria-label="Remove <?= h($genre) ?>">Remove</button>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="genre-add">
          <label class="visually-hidden" for="edit-genre">Add a genre</label>
          <input id="edit-genre" type="text" class="folder-input" data-genre-new maxlength="48" placeholder="Add a genre" autocomplete="off" autocapitalize="words" spellcheck="false">
          <button type="button" class="btn btn-ghost" data-add-genre>Add</button>
        </div>
      </section>
      <div class="detail-edit-actions">
        <button type="submit" class="btn btn-accent btn-block">Save</button>
        <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/title.php?' . $titleQs)) ?>">Cancel</a>
      </div>
    </form>
    <?php else: ?>
    <section class="detail-section detail-overview">
      <h2>Overview</h2>
      <?php if ($overview !== ''): ?>
      <p class="overview"><?= h($overview) ?></p>
      <?php else: ?>
      <p class="overview is-empty"><?= $status === 'matched'
          ? 'No overview is cached for this title.'
          : 'No overview yet.' ?></p>
      <?php endif; ?>
    </section>
    <section class="detail-section">
      <h2>Genres</h2>
      <?php if ($genres !== []): ?>
      <ul class="chip-list">
        <?php foreach ($genres as $genre): ?>
        <li><?= h($genre) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="overview is-empty">No genres.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($cast !== []): ?>
    <section class="detail-section">
      <h2>Cast</h2>
      <ul class="cast">
        <?php foreach ($cast as $name): ?>
        <li><?= h($name) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($sourceAbsent): ?>
    <p class="absent-banner">Source folder is absent. This title stays in the catalog until the drive is mounted again, or you remove the source in Config.</p>
    <?php endif; ?>
    <p class="file-path<?= $sourceAbsent ? ' is-absent' : '' ?>"><?= h($path) ?></p>
    <?php if ($matchNote !== ''): ?>
    <p class="<?= h($matchClass) ?> detail-match"><?= h($matchNote) ?></p>
    <?php endif; ?>
  </article>
  <?php endif; ?>
</main>
<?php
render_end();

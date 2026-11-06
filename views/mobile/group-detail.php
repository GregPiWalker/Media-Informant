<?php
declare(strict_types=1);

/** @var CatalogGroup|null $group */
/** @var array<string, mixed>|null $meta */
/** @var bool $editing */
/** @var string $error */
/** @var string $key */

$found = $group instanceof CatalogGroup && $group->isSeries();
$editing = !empty($editing);
$error = (string) ($error ?? '');
$key = (string) ($key ?? '');
$partEpisode = $found && str_starts_with($group->id, 'parts::');
$partMovie = $found && $group->isPartMovie();
$head = $found ? $group->head : null;
$display = $found ? ($head->seriesTitle !== '' ? $head->seriesTitle : $head->title) : 'Not found';
if ($partEpisode && $head->episodeTitle !== '') {
    $episodeHeading = $head->episodeTitle;
} else {
    $episodeHeading = '';
}
$year = $found && ($head->cells['year'] ?? '—') !== '—' ? $head->cells['year'] : null;
$overview = '';
$genres = $found ? $group->genres() : [];
$cast = [];
$poster = $found ? $head->poster : null;
$sourceAbsent = $found && $head->sourceAbsent;
$ratingLabel = '';
$runtimeLabel = '';
$clusters = $found ? $group->episodeClusters() : [];
$epCount = $clusters !== [] ? count($clusters) : ($found ? count($group->members) : 0);
$fileCount = $found ? count($group->members) : 0;
$matched = 0;
$canClear = false;
if ($found) {
    foreach ($group->members as $member) {
        if ($member->status === 'matched' || $member->status === 'unmatched') {
            $canClear = true;
        }
        if ($member->status === 'matched') {
            $matched++;
        }
    }
    if (is_array($meta)) {
        if ($overview === '' && !empty($meta['overview'])) {
            $overview = (string) $meta['overview'];
        }
        if ($genres === [] && !empty($meta['genres'])) {
            $genres = cache_string_list($meta['genres']);
        }
        if (!$poster && !empty($meta['poster_path'])) {
            $poster = poster_url(is_string($meta['poster_path']) ? $meta['poster_path'] : null);
        }
        if (!empty($meta['year']) && $year === null) {
            $year = (string) (int) $meta['year'];
        }
        if (isset($meta['cast']) && is_array($meta['cast'])) {
            foreach ($meta['cast'] as $name) {
                if (is_string($name) && $name !== '') {
                    $cast[] = $name;
                }
            }
        }
    }
    $headItem = $head->id !== '' ? catalog_store_find('video', $head->id) : null;
    if (is_array($headItem) && $overview === '' && !empty($headItem['overview'])) {
        $overview = (string) $headItem['overview'];
    }
    $metaType = is_array($meta) ? (string) ($meta['media_type'] ?? '') : '';
    $itemType = is_array($headItem) ? (string) ($headItem['media_type'] ?? '') : '';
    if (!$partEpisode && !$partMovie && $group->kind === 'show' && $metaType === 'tv' && $itemType !== 'tv') {
        if (!empty($meta['year'])) {
            $year = (string) (int) $meta['year'];
        }
        if (!empty($meta['poster_path']) && is_string($meta['poster_path'])) {
            $poster = poster_url($meta['poster_path']);
        }
    }
    if (is_array($headItem)) {
        if (($headItem['year_source'] ?? '') === 'user') {
            $year = isset($headItem['year']) && is_numeric($headItem['year']) ? (string) (int) $headItem['year'] : null;
        }
        if (!empty($headItem['poster_cleared'])) {
            $poster = null;
        }
        $headPoster = isset($headItem['poster_path']) && is_string($headItem['poster_path']) ? $headItem['poster_path'] : null;
        if (poster_is_local($headPoster)) {
            $poster = poster_url($headPoster);
        }
    }
    if ($editing && isset($editDraft) && is_array($editDraft)) {
        if (isset($editDraft['title']) && is_string($editDraft['title']) && $editDraft['title'] !== '') {
            if ($partEpisode) {
                $episodeHeading = $editDraft['title'];
            } else {
                $display = $editDraft['title'];
            }
        }
        if (isset($editDraft['overview']) && is_string($editDraft['overview'])) {
            $overview = $editDraft['overview'];
        }
        if (isset($editDraft['genres']) && is_array($editDraft['genres'])) {
            $genres = $editDraft['genres'];
        }
        if (array_key_exists('year', $editDraft)) {
            $draftYear = $editDraft['year'];
            $year = ($draftYear === null || $draftYear === '') ? null : (string) $draftYear;
        }
    }
    $filmYear = null;
    $riffPage = '';
    $headLookup = is_array($headItem) ? (string) ($headItem['lookup_source'] ?? '') : '';
    if (is_array($headItem) && ($headLookup === 'RiffTrax' || $headLookup === 'MST3K')) {
        if (isset($headItem['original_year']) && is_numeric($headItem['original_year'])) {
            $originalYear = (int) $headItem['original_year'];
            $shownYear = is_numeric($year) ? (int) $year : null;
            if ($shownYear === null || $originalYear !== $shownYear) {
                $filmYear = $originalYear;
            }
        }
        if (function_exists('rifftrax_page_url')) {
            $riffPage = rifftrax_page_url((string) ($headItem['detail_url'] ?? ''));
        }
    }
    if (function_exists('tmdb_rating_text')) {
        $ratingLabel = tmdb_rating_text(is_array($meta) ? $meta : null, is_array($headItem) ? $headItem : null);
    }
    if (function_exists('tmdb_runtime_text')) {
        if ($partEpisode) {
            $runtimeLabel = tmdb_runtime_text(is_array($headItem) ? $headItem : null, null);
        } elseif ($partMovie) {
            $runtimeLabel = tmdb_runtime_text(is_array($meta) ? $meta : null, is_array($headItem) ? $headItem : null);
        } elseif (is_array($meta)) {
            $typical = tmdb_runtime_label($meta);
            if ($typical !== '') {
                $runtimeLabel = $typical . ' per episode';
            }
        }
    }
}

$kindLabel = '';
if ($found) {
    if ($partMovie) {
        $kindLabel = $group->kind === 'documentary' ? 'Documentary' : 'Movie';
    } elseif ($partEpisode) {
        $kindLabel = 'Episode';
    } elseif ($group->kind === 'show') {
        $kindLabel = 'TV show';
    } elseif ($group->kind === 'documentary') {
        $kindLabel = 'Documentary series';
    } else {
        $kindLabel = 'Series';
    }
}
$partCount = $found ? count($group->members) : 0;
$seasonSummary = ($found && !$partMovie && !$partEpisode) ? catalog_season_structure_summary($group) : null;
$showHeading = $display;
if ($partEpisode) {
    $showHeading .= ' - ' . ($year ? (string) $year : 'Year unknown');
}
$episodeCodeBits = [];
if ($partEpisode && $head->season !== null) {
    $episodeCodeBits[] = 'Season ' . $head->season;
}
if ($partEpisode && $head->episode !== null) {
    $episodeCodeBits[] = 'Episode ' . $head->episode;
}
$episodeCode = implode(' - ', $episodeCodeBits);
$parentGroupHref = '';
if ($partEpisode) {
    $rest = substr($group->id, strlen('parts::'));
    $markerPos = strpos($rest, '::ep::');
    if ($markerPos !== false) {
        $parentGroupHref = 'group.php?key=' . rawurlencode(substr($rest, 0, $markerPos));
    }
}
$fromFolder = '';
if ($parentGroupHref === '' && isset($_GET['from']) && is_string($_GET['from']) && str_starts_with($_GET['from'], 'folder:') && strlen($_GET['from']) <= 80) {
    $fromFolder = $_GET['from'];
}
$backHref = 'index.php';
if ($parentGroupHref !== '') {
    $backHref = $parentGroupHref;
} elseif ($fromFolder !== '') {
    $backHref = 'group.php?key=' . rawurlencode($fromFolder);
}
$pageTitle = $found ? (($partEpisode && $episodeHeading !== '') ? $episodeHeading : $display) . ' · Media Informant' : 'Not found · Media Informant';

render_start($pageTitle);
render_header(['section' => 'video']);
?>
<main class="page detail-page<?= $sourceAbsent ? ' is-source-absent' : '' ?>" data-group-detail="<?= h($key) ?>">
  <div class="detail-toolbar">
    <a class="back" href="<?= h(app_href('video/' . $backHref)) ?>">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back
    </a>
    <?php if ($found && (!$editing || $canClear)): ?>
    <div class="page-menu" data-menu>
      <button type="button" class="btn btn-icon" data-menu-btn aria-expanded="false" aria-controls="group-menu" aria-haspopup="true" aria-label="Group actions">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M4 6.75h16v1.8H4zm0 4.85h16v1.8H4zm0 4.85h16v1.8H4z"/>
        </svg>
      </button>
      <div class="page-menu-panel" id="group-menu" data-menu-panel hidden role="menu" aria-label="Group actions">
        <?php if (!$editing): ?>
        <a class="page-menu-item" role="menuitem" href="<?= h(app_href('video/group.php?key=' . rawurlencode($key) . '&edit=1' . ($fromFolder !== '' ? '&from=' . rawurlencode($fromFolder) : ''))) ?>">Edit Data</a>
        <button type="button" class="page-menu-item" role="menuitem" data-group-rescan="<?= h($key) ?>" data-group-rescan-label="<?= h($partEpisode && $episodeHeading !== '' ? $episodeHeading : $display) ?>">Re-scan</button>
        <?php endif; ?>
        <?php if ($canClear): ?>
        <form method="post" action="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>" role="none">
          <input type="hidden" name="key" value="<?= h($key) ?>">
          <?php if ($fromFolder !== ''): ?><input type="hidden" name="from" value="<?= h($fromFolder) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="clear_matches">
          <button type="submit" class="page-menu-item" role="menuitem">Clear Matches</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$found): ?>
  <section class="empty-state">
    <h1>Group not found</h1>
    <p>This series is not in the catalog. It may have been renamed or no longer grouped.</p>
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
      <?php if ($partEpisode): ?>
      <h1><?= h($showHeading) ?></h1>
      <?php if ($episodeCode !== ''): ?>
      <p class="detail-episode-code"><?= h($episodeCode) ?></p>
      <?php endif; ?>
      <?php if ($episodeHeading !== ''): ?>
      <p class="detail-episode-title"><?= h($episodeHeading) ?></p>
      <?php endif; ?>
      <?php else: ?>
      <?php if (!$editing): ?>
      <h1><?= h($display) ?></h1>
      <?php endif; ?>
      <p class="detail-year">
        <?= $year ? h((string) $year) : 'Year unknown' ?>
        <?php if ($filmYear !== null): ?>
        · Film <?= h((string) $filmYear) ?>
        <?php endif; ?>
        · <?= h($kindLabel) ?>
        <?php if ($partMovie): ?>
        · <?= (int) $partCount ?> <?= $partCount === 1 ? 'part' : 'parts' ?>
        <?php elseif (is_array($seasonSummary)): ?>
        · <?= h($seasonSummary['label']) ?>
        <?php if ($seasonSummary['episodes'] !== null): ?>
        · <?= (int) $seasonSummary['episodes'] ?> <?= $seasonSummary['episodes'] === 1 ? 'episode' : 'episodes' ?>
        <?php endif; ?>
        <?php if ($seasonSummary['episodes'] === null || (int) $seasonSummary['files'] !== (int) $seasonSummary['episodes']): ?>
        · <?= (int) $seasonSummary['files'] ?> <?= $seasonSummary['files'] === 1 ? 'file' : 'files' ?>
        <?php endif; ?>
        <?php else: ?>
        · <?= (int) $epCount ?> <?= $epCount === 1 ? 'episode' : 'episodes' ?>
        <?php if ($fileCount !== $epCount): ?>
        · <?= (int) $fileCount ?> files
        <?php endif; ?>
        <?php endif; ?>
      </p>
      <?php endif; ?>
      <?php if ($ratingLabel !== ''): ?>
      <p class="detail-rating"><?= $partEpisode ? 'Show rating ' : '' ?><?= h($ratingLabel) ?></p>
      <?php endif; ?>
      <?php if ($runtimeLabel !== ''): ?>
      <p class="detail-runtime"><?= h($runtimeLabel) ?></p>
      <?php endif; ?>
      <p class="hint"><?= (int) $matched ?> of <?= (int) $fileCount ?> matched.</p>
    </header>

    <?php if ($editing): ?>
    <form class="detail-edit" method="post" enctype="multipart/form-data" action="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>">
      <input type="hidden" name="key" value="<?= h($key) ?>">
      <?php if ($fromFolder !== ''): ?><input type="hidden" name="from" value="<?= h($fromFolder) ?>"><?php endif; ?>
      <input type="hidden" name="action" value="save_data">
      <section class="detail-section">
        <h2>Title</h2>
        <label class="visually-hidden" for="edit-title">Title</label>
        <input id="edit-title" type="text" name="display_title" class="title-input folder-input" value="<?= h($partEpisode && $episodeHeading !== '' ? $episodeHeading : $display) ?>" maxlength="200" required autocomplete="off" spellcheck="false">
        <p class="hint"><?= $partEpisode ? 'This name is the episode title shared by these parts.' : ($partMovie ? 'This name applies to every part of this movie.' : 'This name applies to the whole series in the catalog.') ?></p>
      </section>
      <section class="detail-section">
        <h2>Year</h2>
        <label class="visually-hidden" for="edit-year">Year</label>
        <input id="edit-year" type="text" name="year" class="folder-input year-input" value="<?= h($year !== null && $year !== '' && $year !== '—' ? (string) $year : '') ?>" inputmode="numeric" maxlength="4" placeholder="Year" autocomplete="off" spellcheck="false">
      </section>
      <section class="detail-section">
        <h2>Poster</h2>
        <label class="visually-hidden" for="edit-poster">Poster image</label>
        <input id="edit-poster" type="file" name="poster" class="poster-file" accept="image/jpeg,image/png,image/webp,image/gif">
        <p class="hint">Choose a JPEG, PNG, WebP, or GIF to use as the poster. Leave this empty to keep the current image. The same poster is used for every file in this group.</p>
        <?php if ($poster): ?>
        <label class="poster-remove">
          <input type="checkbox" name="remove_poster" value="1">
          Remove the current poster
        </label>
        <?php endif; ?>
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
        <?php
        $knownGenres = isset($library) && is_array($library) ? library_known_genres($library) : [];
        $knownJson = json_encode($knownGenres, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        ?>
        <script type="application/json" id="known-genres"><?= $knownJson !== false ? $knownJson : '[]' ?></script>
      </section>
      <div class="detail-edit-actions">
        <button type="submit" class="btn btn-accent btn-block">Save</button>
        <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>">Cancel</a>
      </div>
    </form>
    <?php else: ?>
    <section class="detail-section detail-overview">
      <h2>Overview</h2>
      <?php if ($overview !== ''): ?>
      <p class="overview"><?= h_paragraphs($overview) ?></p>
      <?php else: ?>
      <p class="overview is-empty">No overview yet.</p>
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
    <p class="absent-banner">Source folder is absent. This series stays in the catalog until the drive is mounted again, or you remove the source in Config.</p>
    <?php endif; ?>
    <?php if (!$editing && $riffPage !== ''): ?>
    <p class="hint detail-match"><a href="<?= h($riffPage) ?>" rel="noopener noreferrer">Open on RiffTrax</a></p>
    <?php endif; ?>

    <?php if (!$editing && ($partMovie || $partEpisode)): ?>
    <section class="detail-section">
      <h2>Parts</h2>
      <div class="group-ep-list">
        <?php foreach ($group->members as $part):
            $label = $part->partLabel !== '' ? $part->partLabel : $part->title;
            $href = catalog_file_href_from_group($part, $key);
            $metaBits = [$part->cells['status']];
            ?>
        <a class="group-ep-row" href="<?= h(app_href('video/' . $href)) ?>">
          <?= catalog_record_thumb_html($part, 'sm', $label) ?>
          <span class="group-ep-text">
            <span class="group-ep-title"><?= h($label) ?></span>
            <span class="group-ep-meta"><?= h(implode(' · ', $metaBits)) ?></span>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php elseif (!$editing): ?>
    <section class="detail-section">
      <h2><?= $group->kind === 'show' ? 'Episodes' : 'Parts' ?></h2>
      <div class="group-ep-list">
        <?php
        $lastSeason = null;
        foreach ($clusters as $cluster):
            $parts = $cluster['parts'];
            $first = $parts[0];
            $season = $first->season;
            $namedParts = count($parts) > 1 && catalog_is_named_part($first->partLabel);
            if ($namedParts) {
                $href = catalog_episode_parts_href($group->id, $first->episodeGroupKey);
                $label = (string) $cluster['label'];
                $metaBits = [];
                if (($first->cells['year'] ?? '—') !== '—') {
                    $metaBits[] = $first->cells['year'];
                }
                $metaBits[] = count($parts) . ' parts';
                $metaBits[] = $first->cells['status'];
                if ($season !== null && $season !== $lastSeason) {
                    $lastSeason = $season;
                    echo '<h3 class="group-ep-season">' . h(catalog_season_label($season)) . '</h3>';
                }
                ?>
        <a class="group-ep-row" href="<?= h(app_href('video/' . $href)) ?>">
          <?= catalog_record_thumb_html($first, 'sm', $label) ?>
          <span class="group-ep-text">
            <span class="group-ep-title"><?= catalog_episode_num_html($first->listEpisodeNumber(), $group->kind === 'show') ?><span><?= h($label) ?></span></span>
            <span class="group-ep-meta"><?= h(implode(' · ', $metaBits)) ?></span>
          </span>
        </a>
                <?php
                continue;
            }
            if ($season !== null && $season !== $lastSeason):
                $lastSeason = $season;
                ?>
        <h3 class="group-ep-season"><?= h(catalog_season_label($season)) ?></h3>
            <?php endif; ?>
            <?php foreach ($parts as $part):
                $label = (string) $cluster['label'];
                if (count($parts) > 1) {
                    $partName = $part->partLabel !== '' ? $part->partLabel : $part->title;
                    $label .= ' · ' . $partName;
                }
                $href = catalog_file_href_from_group($part, $key);
                $metaBits = [];
                if (($part->cells['year'] ?? '—') !== '—') {
                    $metaBits[] = $part->cells['year'];
                }
                $metaBits[] = $part->cells['status'];
                ?>
        <a class="group-ep-row" href="<?= h(app_href('video/' . $href)) ?>">
          <?= catalog_record_thumb_html($part, 'sm', $label) ?>
          <span class="group-ep-text">
            <span class="group-ep-title"><?= catalog_episode_num_html($part->listEpisodeNumber(), $group->kind === 'show') ?><span><?= h($label) ?></span></span>
            <span class="group-ep-meta"><?= h(implode(' · ', $metaBits)) ?></span>
          </span>
        </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </article>
  <?php endif; ?>
</main>
<?php
render_end();

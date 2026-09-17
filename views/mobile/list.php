<?php
declare(strict_types=1);

/** @var list<CatalogRecord> $records */
/** @var list<CatalogGroup> $groups */
/** @var CatalogSchema $schema */
/** @var CatalogPreferences $prefs */
/** @var list<array{id?: string, label?: string}> $categoryList */
/** @var bool $categoryHasNone */
/** @var array<string, list<CatalogGroup>> $genreGroups */
/** @var int|null $scannedAt */

function catalog_thumb(CatalogRecord $record, string $size): string
{
    $class = 'catalog-thumb catalog-thumb-' . $size;
    if ($record->poster) {
        return '<span class="' . $class . '"><img src="' . h($record->poster) . '" alt="" loading="lazy" decoding="async"></span>';
    }
    return '<span class="' . $class . ' poster-fallback" aria-hidden="true"><span>' . h(initial($record->title)) . '</span></span>';
}

function catalog_thumb_button(CatalogRecord $record, string $size, string $label, string $extra = ''): string
{
    $bits = [];
    if ($extra !== '') {
        $bits[] = $extra;
    } else {
        $bits[] = $record->kind === 'show' ? 'TV show' : 'Movie';
    }
    $year = $record->cells['year'] ?? '';
    $genres = $record->cells['genres'] ?? '';
    if ($year !== '' && $year !== '—') {
        $bits[] = $year;
    }
    if ($genres !== '' && $genres !== '—') {
        $bits[] = $genres;
    }
    $caption = implode(' · ', $bits);
    return '<button type="button" class="poster-expand" data-poster-open'
        . ' data-poster-src="' . h((string) ($record->poster ?? '')) . '"'
        . ' data-poster-title="' . h($label) . '"'
        . ' data-poster-caption="' . h($caption) . '"'
        . ' data-poster-initial="' . h(initial($label)) . '"'
        . ' aria-label="View poster for ' . h($label) . '">'
        . catalog_thumb($record, $size)
        . '</button>';
}

function catalog_cat_attr(CatalogRecord $record): string
{
    return $record->category !== '' ? $record->category : 'none';
}

function catalog_sort_attrs(CatalogRecord $record): string
{
    $out = '';
    foreach ($record->sortKeys as $key => $val) {
        $out .= ' data-sort-' . h((string) $key) . '="' . h($val) . '"';
    }
    return $out;
}

render_start('Video · Media Informant');
render_header(['search' => true, 'section' => 'video', 'branches' => true]);

$fileCount = count($records);
$groupCount = count($groups);
$view = $prefs->view;
?>
<main class="page">
  <?php if ($fileCount === 0): ?>
  <section class="empty-state">
    <div class="empty-art" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="64" height="64">
        <rect x="8" y="12" width="48" height="40" rx="6" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M16 12v40M48 12v40M8 24h8M8 40h8M48 24h8M48 40h8" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M28 26l14 8-14 8V26z" fill="currentColor"/>
      </svg>
    </div>
    <h1>Your library is empty</h1>
    <p>Set video folders in Config, then scan the library from there. Browsing never walks the disk on its own.</p>
    <a class="btn btn-accent" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
  </section>
  <?php else: ?>
  <style>
    <?php
    $filterCssIds = settings_category_ids($categoryList);
    if (!empty($categoryHasNone)) {
        $filterCssIds[] = 'none';
    }
    foreach ($filterCssIds as $catId):
        ?>
    .catalog:not([data-categories~="<?= h($catId) ?>"]) [data-category="<?= h($catId) ?>"] { display: none !important; }
    <?php endforeach; ?>
  </style>
  <div class="catalog" data-catalog data-view="<?= h($view) ?>" data-view-rendered="<?= h($view) ?>" data-cols="<?= h($prefs->colsAttr()) ?>" data-sort="<?= h($prefs->sort) ?>" data-dir="<?= h($prefs->dir) ?>" data-kinds="<?= h($prefs->kindsAttr()) ?>" data-categories="<?= h($prefs->categoriesAttr()) ?>">
    <div class="catalog-toolbar">
      <p class="catalog-meta">
        <?= (int) $groupCount ?> <?= $groupCount === 1 ? 'title' : 'titles' ?>
        <?php if ($fileCount !== $groupCount): ?>
        · <?= (int) $fileCount ?> files
        <?php endif; ?>
        · <?= h(format_scanned_at($scannedAt)) ?>
      </p>
      <nav class="view-switch" aria-label="Catalog view">
        <?php foreach (['list' => 'List', 'poster' => 'Poster', 'genre' => 'Genre'] as $id => $label): ?>
        <button type="button" class="view-switch-btn<?= $view === $id ? ' is-active' : '' ?>" data-catalog-view="<?= h($id) ?>"<?= $view === $id ? ' aria-current="true"' : '' ?>><?= h($label) ?></button>
        <?php endforeach; ?>
      </nav>
      <div class="kind-filter" role="group" aria-label="Filter by type">
        <label class="kind-chip">
          <input type="checkbox" data-kind-filter="movie"<?= $prefs->showsKind('movie') ? ' checked' : '' ?>>
          Movies
        </label>
        <label class="kind-chip">
          <input type="checkbox" data-kind-filter="show"<?= $prefs->showsKind('show') ? ' checked' : '' ?>>
          TV Shows
        </label>
        <label class="kind-chip">
          <input type="checkbox" data-kind-filter="documentary"<?= $prefs->showsKind('documentary') ? ' checked' : '' ?>>
          Documentaries
        </label>
      </div>
      <?php if ($categoryList !== [] || !empty($categoryHasNone)): ?>
      <div class="kind-filter" role="group" aria-label="Filter by category">
        <?php foreach ($categoryList as $category):
            $cid = (string) ($category['id'] ?? '');
            if ($cid === '') {
                continue;
            }
            ?>
        <label class="kind-chip">
          <input type="checkbox" data-category-filter="<?= h($cid) ?>"<?= $prefs->showsCategory($cid) ? ' checked' : '' ?>>
          <?= h((string) ($category['label'] ?? $cid)) ?>
        </label>
        <?php endforeach; ?>
        <?php if (!empty($categoryHasNone)): ?>
        <label class="kind-chip">
          <input type="checkbox" data-category-filter="none"<?= $prefs->showsCategory('none') ? ' checked' : '' ?>>
          Uncategorized
        </label>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <p class="empty-search" hidden data-search-empty>No titles match that search.</p>

    <?php if ($view === 'list'): ?>
    <section class="catalog-layout" data-layout="list">
      <div class="catalog-table-wrap">
        <div class="col-panel" id="col-panel" data-col-panel hidden>
          <?php foreach ($schema->columns as $column): ?>
          <label class="col-option">
            <input type="checkbox" data-col-id="<?= h($column->id) ?>"<?= $prefs->shows($column->id) ? ' checked' : '' ?><?= $column->required ? ' disabled' : '' ?>>
            <?= h($column->label) ?><?= $column->required ? ' (always on)' : '' ?>
          </label>
          <?php endforeach; ?>
        </div>
        <table class="catalog-table">
          <thead data-list-head>
            <tr>
              <?php foreach ($schema->columns as $column): ?>
              <th scope="col" data-col="<?= h($column->id) ?>">
                <?php if ($column->sortable()): ?>
                <button type="button" class="sort-btn" data-sort="<?= h($column->id) ?>">
                  <?= h($column->label) ?>
                  <span class="sort-ind" data-sort-ind="<?= h($column->id) ?>"></span>
                </button>
                <?php else: ?>
                <span class="sort-label"><?= h($column->label) ?></span>
                <?php endif; ?>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody data-list-body>
            <?php foreach ($groups as $group):
                $head = $group->head;
                $series = $group->isSeries();
                $clusters = $series ? $group->episodeClusters() : [];
                $epCount = $clusters !== [] ? count($clusters) : count($group->members);
                ?>
            <?php if ($series): ?>
            <tr class="catalog-group" data-group="<?= h($group->id) ?>" data-kind="<?= h($head->kind) ?>" data-category="<?= h(catalog_cat_attr($head)) ?>" data-search="<?= h($group->search()) ?>"<?= catalog_sort_attrs($head) ?>>
              <td data-col="poster"><?= catalog_thumb_button($head, 'sm', $head->seriesTitle, ((int) $epCount) . ' ' . ($epCount === 1 ? 'episode' : 'episodes')) ?></td>
              <td data-col="title">
                <button type="button" class="expand-btn" data-expand="<?= h($group->id) ?>" aria-expanded="false">
                  <span class="expand-caret" aria-hidden="true"></span>
                  <span class="catalog-row-title"><?= h($head->seriesTitle) ?></span>
                  <span class="episode-count"><?= (int) $epCount ?> <?= $epCount === 1 ? 'episode' : 'episodes' ?></span>
                </button>
              </td>
              <td data-col="year"><?= h($head->cells['year']) ?></td>
              <td data-col="status"><?= h($head->cells['status']) ?></td>
              <td data-col="genres"><?= h($head->cells['genres']) ?></td>
            </tr>
            <?php foreach ($clusters as $cluster):
                $parts = $cluster['parts'];
                $first = $parts[0];
                $multi = count($parts) > 1;
                ?>
            <?php if ($multi): ?>
            <tr class="catalog-episode" hidden data-group="<?= h($cluster['id']) ?>" data-parent="<?= h($group->id) ?>" data-kind="<?= h($first->kind) ?>" data-category="<?= h(catalog_cat_attr($first)) ?>" data-search="<?= h($group->search()) ?>"<?= catalog_sort_attrs($first) ?>>
              <td data-col="poster"><?= catalog_thumb_button($first, 'sm', $cluster['label'], count($parts) . ' parts') ?></td>
              <td data-col="title">
                <button type="button" class="expand-btn" data-expand="<?= h($cluster['id']) ?>" aria-expanded="false">
                  <span class="expand-caret" aria-hidden="true"></span>
                  <span class="catalog-row-title"><?= h($cluster['label']) ?></span>
                  <span class="episode-count"><?= count($parts) ?> parts</span>
                </button>
              </td>
              <td data-col="year"><?= h($first->cells['year']) ?></td>
              <td data-col="status"><?= h($first->cells['status']) ?></td>
              <td data-col="genres"><?= h($first->cells['genres']) ?></td>
            </tr>
            <?php foreach ($parts as $record):
                $partName = $record->partLabel !== '' ? $record->partLabel : ($record->episodeLabel !== '' ? $record->episodeLabel : $record->title);
                ?>
            <tr class="catalog-part" hidden data-card data-parent="<?= h($cluster['id']) ?>" data-kind="<?= h($record->kind) ?>" data-category="<?= h(catalog_cat_attr($record)) ?>" data-search="<?= h($record->search) ?>"<?= catalog_sort_attrs($record) ?>>
              <td data-col="poster">
                <?= catalog_thumb_button($record, 'sm', $partName) ?>
              </td>
              <td data-col="title">
                <a class="catalog-row-link catalog-row-title" href="<?= h($record->href) ?>"><?= h($partName) ?></a>
              </td>
              <td data-col="year"><?= h($record->cells['year']) ?></td>
              <td data-col="status"><?= h($record->cells['status']) ?></td>
              <td data-col="genres"><?= h($record->cells['genres']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr class="catalog-episode" hidden data-card data-parent="<?= h($group->id) ?>" data-kind="<?= h($first->kind) ?>" data-category="<?= h(catalog_cat_attr($first)) ?>" data-search="<?= h($first->search) ?>"<?= catalog_sort_attrs($first) ?>>
              <td data-col="poster">
                <?= catalog_thumb_button($first, 'sm', $cluster['label']) ?>
              </td>
              <td data-col="title">
                <a class="catalog-row-link catalog-row-title" href="<?= h($first->href) ?>"><?= h($cluster['label']) ?></a>
              </td>
              <td data-col="year"><?= h($first->cells['year']) ?></td>
              <td data-col="status"><?= h($first->cells['status']) ?></td>
              <td data-col="genres"><?= h($first->cells['genres']) ?></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php else: ?>
            <tr data-card data-kind="<?= h($head->kind) ?>" data-category="<?= h(catalog_cat_attr($head)) ?>" data-search="<?= h($head->search) ?>"<?= catalog_sort_attrs($head) ?>>
              <td data-col="poster">
                <?= catalog_thumb_button($head, 'sm', $head->title) ?>
              </td>
              <td data-col="title">
                <a class="catalog-row-link catalog-row-title" href="<?= h($head->href) ?>"><?= h($head->title) ?></a>
              </td>
              <td data-col="year"><?= h($head->cells['year']) ?></td>
              <td data-col="status"><?= h($head->cells['status']) ?></td>
              <td data-col="genres"><?= h($head->cells['genres']) ?></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'poster'): ?>
    <section class="catalog-layout" data-layout="poster">
      <div class="poster-grid" data-poster-grid>
        <?php foreach ($groups as $group):
            $head = $group->head;
            $title = $group->isSeries() ? $head->seriesTitle : $head->title;
            ?>
        <a class="card" href="<?= h($head->href) ?>" data-card data-kind="<?= h($group->kind) ?>" data-category="<?= h(catalog_cat_attr($head)) ?>" data-search="<?= h($group->search()) ?>"<?= catalog_sort_attrs($head) ?><?= $group->isSeries() ? ' data-open-list="' . h($group->id) . '"' : '' ?>>
          <div class="poster">
            <?php if ($head->poster): ?>
            <img src="<?= h($head->poster) ?>" alt="" width="342" height="513" loading="lazy" decoding="async">
            <?php else: ?>
            <div class="poster-fallback" aria-hidden="true">
              <span><?= h(initial($title)) ?></span>
            </div>
            <?php endif; ?>
            <div class="card-meta">
              <h2 class="card-title"><?= h($title) ?></h2>
              <p class="card-year">
                <?= $head->cells['year'] !== '—' ? h($head->cells['year']) : 'Year unknown' ?>
                <?php if ($group->isSeries()): ?>
                <span class="badge"><?= count($group->episodeClusters()) ?> eps</span>
                <?php elseif ($head->status === 'unidentified'): ?>
                <span class="badge">Unidentified</span>
                <?php elseif ($head->status !== 'matched'): ?>
                <span class="badge">Unmatched</span>
                <?php elseif ($head->matchSource === 'grok'): ?>
                <span class="badge">Grok</span>
                <?php elseif ($head->matchSource === 'manual'): ?>
                <span class="badge">Manual</span>
                <?php endif; ?>
              </p>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'genre'): ?>
    <section class="catalog-layout" data-layout="genre">
      <?php if ($genreGroups === []): ?>
      <p class="hint genre-hint">No titles to group.</p>
      <?php else: ?>
      <p class="hint genre-hint">A series appears once per genre. Unmatched files land in Uncategorized. Scan or match titles to fill genres.</p>
      <?php foreach ($genreGroups as $genre => $rail): ?>
      <section class="genre-block" data-genre-rail>
        <header class="genre-head">
          <h2><?= h($genre) ?></h2>
          <p><?= count($rail) ?></p>
        </header>
        <div class="genre-rail">
          <?php foreach ($rail as $group):
              $head = $group->head;
              $title = $group->isSeries() ? $head->seriesTitle : $head->title;
              ?>
          <a class="card genre-card" href="<?= h($head->href) ?>" data-card data-kind="<?= h($group->kind) ?>" data-category="<?= h(catalog_cat_attr($head)) ?>" data-search="<?= h($group->search()) ?>">
            <div class="poster">
              <?php if ($head->poster): ?>
              <img src="<?= h($head->poster) ?>" alt="" width="342" height="513" loading="lazy" decoding="async">
              <?php else: ?>
              <div class="poster-fallback" aria-hidden="true">
                <span><?= h(initial($title)) ?></span>
              </div>
              <?php endif; ?>
              <div class="card-meta">
                <h2 class="card-title"><?= h($title) ?></h2>
                <p class="card-year">
                  <?= $head->cells['year'] !== '—' ? h($head->cells['year']) : '' ?>
                  <?php if ($group->isSeries()): ?>
                  <span class="badge"><?= count($group->episodeClusters()) ?> eps</span>
                  <?php elseif ($head->matchSource === 'grok'): ?>
                  <span class="badge">Grok</span>
                  <?php elseif ($head->matchSource === 'manual'): ?>
                  <span class="badge">Manual</span>
                  <?php endif; ?>
                </p>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</main>
<?php
render_end();

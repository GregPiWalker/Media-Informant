<?php
declare(strict_types=1);

/** @var array<string, mixed>|null $folder */
/** @var string $error */
/** @var string $key */
/** @var bool $configuring */
/** @var list<string> $scanServices */
/** @var string $scanServiceSaved */
/** @var string $scanServiceActive */

$error = (string) ($error ?? '');
$key = (string) ($key ?? '');
$found = is_array($folder);
$label = $found ? trim((string) ($folder['label'] ?? '')) : '';
if ($label === '') {
    $label = $found ? 'Folder' : 'Not found';
}
$groups = [];
if ($found && is_array($folder['groups'] ?? null)) {
    foreach ($folder['groups'] as $item) {
        if ($item instanceof CatalogGroup) {
            $groups[] = $item;
        }
    }
}
$movieCount = count($groups);
$fileCount = 0;
$matched = 0;
$canClear = false;
$sourceAbsent = $movieCount > 0;
foreach ($groups as $group) {
    foreach ($group->members as $member) {
        $fileCount++;
        if (!$member->sourceAbsent) {
            $sourceAbsent = false;
        }
        if ($member->status === 'matched' || $member->status === 'unmatched') {
            $canClear = true;
        }
        if ($member->status === 'matched') {
            $matched++;
        }
    }
}
if ($fileCount === 0) {
    $sourceAbsent = false;
}
$summary = $movieCount === 1 ? '1 movie' : ((string) $movieCount . ' movies');
if ($fileCount !== $movieCount) {
    $summary .= ' · ' . (string) $fileCount . ' ' . ($fileCount === 1 ? 'file' : 'files');
}
$configuring = !empty($configuring);
$scanServices = is_array($scanServices ?? null) ? $scanServices : [];
$scanServiceSaved = trim((string) ($scanServiceSaved ?? ''));
$scanServiceActive = trim((string) ($scanServiceActive ?? ''));
$folderDefault = 'TMDB';
if (function_exists('mst3k_folder_name') && mst3k_folder_name($label)) {
    $folderDefault = 'MST3K';
} elseif (strcasecmp($label, 'RiffTrax') === 0) {
    $folderDefault = 'RiffTrax';
}
$scanServiceSelected = $scanServiceSaved !== '' ? $scanServiceSaved : $folderDefault;
if ($scanServiceActive === '') {
    $scanServiceLabel = 'Catalog scan';
} else {
    $scanServiceLabel = $scanServiceActive;
}
$pageTitle = ($found ? $label : 'Not found') . ' · Media Informant';

render_start($pageTitle);
render_header(['section' => 'video']);
?>
<main class="page detail-page<?= $sourceAbsent ? ' is-source-absent' : '' ?>" data-group-detail="<?= h($key) ?>">
  <div class="detail-toolbar">
    <a class="back" href="<?= h(app_href('video/index.php')) ?>">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back
    </a>
    <?php if ($found): ?>
    <div class="page-menu" data-menu>
      <button type="button" class="btn btn-icon" data-menu-btn aria-expanded="false" aria-controls="folder-menu" aria-haspopup="true" aria-label="Folder actions">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M4 6.75h16v1.8H4zm0 4.85h16v1.8H4zm0 4.85h16v1.8H4z"/>
        </svg>
      </button>
      <div class="page-menu-panel" id="folder-menu" data-menu-panel hidden role="menu" aria-label="Folder actions">
        <button type="button" class="page-menu-item" role="menuitem" data-group-rescan="<?= h($key) ?>" data-group-rescan-label="<?= h($label) ?>">Re-scan</button>
        <a class="page-menu-item" role="menuitem" href="<?= h(app_href('video/group.php?key=' . rawurlencode($key) . '&configure=1')) ?>">Configure Scan</a>
        <?php if ($canClear): ?>
        <form method="post" action="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>" role="none">
          <input type="hidden" name="key" value="<?= h($key) ?>">
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
    <h1>Folder not found</h1>
    <p>This folder is not in the catalog. It may have been renamed or removed.</p>
    <a class="btn btn-accent" href="<?= h(app_href('video/index.php')) ?>">Back to Video</a>
  </section>
  <?php else: ?>
  <article class="detail">
    <?php if ($error !== ''): ?>
    <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <header class="detail-header">
      <h1><?= h($label) ?></h1>
      <p class="detail-year"><?= h($summary) ?></p>
      <p class="hint"><?= (int) $matched ?> of <?= (int) $fileCount ?> matched.</p>
      <p class="hint">Scan service: <?= h($scanServiceLabel) ?><?= $scanServiceSaved === '' ? '' : ' (saved)' ?>.</p>
    </header>
    <?php if ($configuring): ?>
    <form class="detail-edit" method="post" action="<?= h(app_href('video/group.php?key=' . rawurlencode($key) . '&configure=1')) ?>">
      <input type="hidden" name="key" value="<?= h($key) ?>">
      <input type="hidden" name="action" value="save_scan_service">
      <section class="detail-section">
        <h2>Lookup service</h2>
        <p class="hint">Re-scan uses the service you save here for every movie in this folder, and Match title on one of those movies uses the same service. A folder named RiffTrax uses RiffTrax until you save a different one. A folder named MST3K or Mystery Science Theater 3000 uses MST3K until you save a different one. A file named “MST3K - Title” or “MST3K: Title” uses MST3K when the folder has no saved service. Clear Matches first when a new service should replace titles that are already matched.</p>
        <?php if ($scanServices === []): ?>
        <p class="hint">No lookup services are available.</p>
        <?php else: ?>
        <label class="field">
          <span class="field-label">Service</span>
          <select name="scan_service" class="folder-input">
            <?php foreach ($scanServices as $serviceName): ?>
            <option value="<?= h($serviceName) ?>"<?= strcasecmp($scanServiceSelected, $serviceName) === 0 ? ' selected' : '' ?>><?= h($serviceName) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </section>
      <?php if ($scanServices !== []): ?>
      <div class="detail-edit-actions">
        <button type="submit" class="btn btn-accent btn-block">Save</button>
        <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>">Cancel</a>
      </div>
      <?php else: ?>
      <div class="detail-edit-actions">
        <a class="btn btn-ghost btn-block" href="<?= h(app_href('video/group.php?key=' . rawurlencode($key))) ?>">Cancel</a>
      </div>
      <?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if ($sourceAbsent): ?>
    <p class="absent-banner">Source folder is absent. These movies stay in the catalog until the drive is mounted again, or you remove the source in Config.</p>
    <?php endif; ?>
    <section class="detail-section">
      <h2>Movies</h2>
      <div class="group-ep-list">
        <?php foreach ($groups as $group):
            $head = $group->head;
            $title = catalog_group_list_title($group);
            $partMovie = $group->isPartMovie();
            $href = $group->isSeries()
                ? ('group.php?key=' . rawurlencode($group->id) . '&from=' . rawurlencode($key))
                : catalog_file_href_from_group($head, $key);
            $metaBits = [];
            $year = (string) ($head->cells['year'] ?? '');
            if ($year !== '' && $year !== '—') {
                $metaBits[] = $year;
            }
            if ($partMovie) {
                $partCount = count($group->members);
                $metaBits[] = (string) $partCount . ' ' . ($partCount === 1 ? 'part' : 'parts');
            }
            $status = (string) ($head->cells['status'] ?? '');
            if ($status !== '' && $status !== '—') {
                $metaBits[] = $status;
            }
            ?>
        <a class="group-ep-row" href="<?= h(app_href('video/' . $href)) ?>">
          <?= catalog_record_thumb_html($head, 'sm', $title) ?>
          <span class="group-ep-text">
            <span class="group-ep-title"><span><?= h($title) ?></span></span>
            <?php if ($metaBits !== []): ?>
            <span class="group-ep-meta"><?= h(implode(' · ', $metaBits)) ?></span>
            <?php endif; ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
  </article>
  <?php endif; ?>
</main>
<?php
render_end();

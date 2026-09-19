<?php
declare(strict_types=1);

/** @var list<array{id: string, path: string, catalog: string, label: string, present: bool}> $sources */
$sources = is_array($sources ?? null) ? $sources : [];
$first = null;
foreach ($sources as $source) {
    if (!empty($source['present'])) {
        $first = $source;
        break;
    }
}
?>
<main class="page tools-page" data-tools-page data-tools-browse-url="<?= h(app_href('tools/browse.php')) ?>" data-tools-rename-url="<?= h(app_href('tools/rename-group.php')) ?>">
  <header class="home-intro">
    <p class="scan-kicker">Tools</p>
    <h1>File tools</h1>
    <p>Browse one source folder. Right-click (or press and hold) a folder to rename a group of files that share the same text in their names.</p>
  </header>

  <?php if ($sources === []): ?>
  <section class="empty-state">
    <h2>No sources</h2>
    <p>Add video or music folders in Config first.</p>
    <a class="btn btn-accent" href="<?= h(app_href('config/index.php')) ?>">Open Config</a>
  </section>
  <?php else: ?>
  <label class="field tools-source-field">
    <span class="field-label">Source directory</span>
    <select class="folder-input" data-tools-source>
      <?php foreach ($sources as $source): ?>
      <option value="<?= h($source['id']) ?>"<?= $first && $source['id'] === $first['id'] ? ' selected' : '' ?><?= empty($source['present']) ? ' disabled' : '' ?>>
        <?= h($source['label']) ?><?= empty($source['present']) ? ' (absent)' : '' ?> · <?= $source['catalog'] === 'music' ? 'Music' : 'Video' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="hint" data-tools-status><?= $first ? 'Open a folder, then right-click it for Rename Group.' : 'All configured sources are absent.' ?></p>
  <div class="tools-tree-wrap">
    <ul class="tools-tree" data-tools-tree></ul>
  </div>
  <div class="col-panel" data-tools-menu hidden role="menu" aria-label="Folder actions">
    <button type="button" class="page-menu-item" data-tools-rename role="menuitem">Rename Group</button>
  </div>
  <?php endif; ?>
</main>

<div class="overlay" data-overlay="rename-group" id="rename-overlay" hidden>
  <div class="overlay-backdrop" data-overlay-dismiss></div>
  <button type="button" class="overlay-close" data-overlay-dismiss aria-label="Close">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
  </button>
  <div class="overlay-pane overlay-pane-tools" role="dialog" aria-modal="true" aria-labelledby="rename-overlay-title">
    <h2 id="rename-overlay-title">Rename group</h2>
    <p class="hint" data-rename-folder></p>
    <label class="field">
      <span class="field-label">Find in filenames</span>
      <input type="text" class="folder-input" data-rename-find spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Text shared by the filenames" data-rename-suggest="">
    </label>
    <label class="field">
      <span class="field-label">Replacement</span>
      <input type="text" class="folder-input" data-rename-replace spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Leave empty to remove">
    </label>
    <p class="field-label">File types</p>
    <div class="scan-option-grid" data-rename-exts></div>
    <p class="form-banner is-error" data-rename-error hidden></p>
    <p class="form-banner is-ok" data-rename-ok hidden></p>
    <ol class="tools-rename-log" data-rename-log hidden></ol>
    <button type="button" class="btn btn-accent btn-block" data-rename-run>Rename</button>
  </div>
</div>
<?php

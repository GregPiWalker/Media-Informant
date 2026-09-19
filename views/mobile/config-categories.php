<?php
declare(strict_types=1);

/** @var list<array{id: string, label: string}> $videoCategories */
/** @var list<array{id: string, label: string}> $musicCategories */
/** @var bool $saved */
/** @var string $error */

render_start('Categories · Media Informant');
render_header(['section' => 'config', 'branches' => true]);
?>
<main class="page config-page">
  <a class="back" href="<?= h(app_href('config/index.php?tab=general')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15.5 5.5 9 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Config
  </a>
  <header class="home-intro">
    <h1>Categories</h1>
    <p>Video and Music each have their own list. Assign these on the Config page when you add a source folder.</p>
  </header>

  <?php if ($saved): ?>
  <p class="form-banner is-ok" role="status">Categories saved. Source folders that used a removed name will show as Uncategorized until you assign a new one.</p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <form class="config-form" method="post" action="<?= h(app_href('config/categories.php')) ?>" autocomplete="off">
    <section class="form-section">
      <h2>Video categories</h2>
      <p class="hint">Shown on video sources and as filters in the Video catalog.</p>
      <div class="folder-list" data-category-list="video">
        <?php foreach ($videoCategories as $category): ?>
        <div class="folder-row" data-category-row>
          <div class="folder-fields">
            <input type="hidden" name="video_category_ids[]" value="<?= h((string) ($category['id'] ?? '')) ?>">
            <label class="visually-hidden">Video category name</label>
            <input type="text" name="video_category_labels[]" class="folder-input" value="<?= h((string) ($category['label'] ?? '')) ?>" placeholder="Category name" spellcheck="false">
          </div>
          <button type="button" class="btn btn-ghost" data-remove-category aria-label="Remove video category">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-category="video">Add video category</button>
    </section>

    <section class="form-section">
      <h2>Music categories</h2>
      <p class="hint">Shown on music sources. Add names here before assigning them to a music folder.</p>
      <div class="folder-list" data-category-list="music">
        <?php foreach ($musicCategories as $category): ?>
        <div class="folder-row" data-category-row>
          <div class="folder-fields">
            <input type="hidden" name="music_category_ids[]" value="<?= h((string) ($category['id'] ?? '')) ?>">
            <label class="visually-hidden">Music category name</label>
            <input type="text" name="music_category_labels[]" class="folder-input" value="<?= h((string) ($category['label'] ?? '')) ?>" placeholder="Category name" spellcheck="false">
          </div>
          <button type="button" class="btn btn-ghost" data-remove-category aria-label="Remove music category">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-category="music">Add music category</button>
    </section>

    <button type="submit" class="btn btn-accent btn-block">Save categories</button>
  </form>
</main>
<?php
render_end();

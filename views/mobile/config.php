<?php
declare(strict_types=1);

/** @var array<string, mixed> $settings */
/** @var list<array{id: string, label: string}> $videoCategories */
/** @var list<array{id: string, label: string}> $musicCategories */
/** @var list<array{path: string, category: string}> $videoSources */
/** @var list<array{path: string, category: string}> $musicSources */
/** @var list<string> $videoExcludes */
/** @var list<string> $musicExcludes */
/** @var bool $saved */
/** @var string $error */

function config_category_select(string $fieldName, string $selected, array $categories, string $domain): void
{
    echo '<label class="visually-hidden">Category</label>';
    echo '<select name="' . h($fieldName) . '" class="folder-input folder-category" data-source-category="' . h($domain) . '">';
    echo '<option value="">Uncategorized</option>';
    foreach ($categories as $category) {
        $id = (string) ($category['id'] ?? '');
        $label = (string) ($category['label'] ?? '');
        $sel = $id !== '' && $id === $selected ? ' selected' : '';
        echo '<option value="' . h($id) . '"' . $sel . '>' . h($label) . '</option>';
    }
    echo '</select>';
}

render_start('Config · Media Informant');
render_header(['section' => 'config', 'branches' => true]);

$languages = settings_languages();
?>
<main class="page config-page">
  <header class="home-intro">
    <h1>Configuration</h1>
    <p>Point this install at the shares on this NAS. Assign a category to each source; everything inside inherits it. Edit category names on the Categories page.</p>
  </header>

  <?php if ($saved): ?>
  <p class="form-banner is-ok" role="status">Settings saved. Removed sources disappear from Video immediately. Scan the video library to pick up new folders and match titles.</p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <p class="config-link-row">
    <a class="btn btn-ghost btn-block" href="<?= h(app_href('config/categories.php')) ?>">Edit categories</a>
  </p>

  <form class="config-form" method="post" action="<?= h(app_href('config/index.php')) ?>" autocomplete="off">
    <section class="form-section">
      <h2>Video folders</h2>
      <p class="hint">Absolute paths to video shares, one per row. Pick the category that applies to everything in that folder. Capitalization must match the NAS exactly.</p>
      <div class="folder-list" data-folder-list="video">
        <?php foreach ($videoSources as $source):
            $root = (string) ($source['path'] ?? '');
            $status = $root !== '' ? settings_path_status($root) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Video folder</label>
            <input type="text" name="video_roots[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/video" spellcheck="false" autocapitalize="off">
            <?php config_category_select('video_root_categories[]', (string) ($source['category'] ?? ''), $videoCategories, 'video'); ?>
            <?php if ($status !== ''): ?>
            <p class="folder-status<?= $status === 'ok' ? '' : ' is-warn' ?>"><?= h(settings_path_status_label($status)) ?></p>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove video folder">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="video">Add video folder</button>
    </section>

    <section class="form-section">
      <h2>Excluded video paths</h2>
      <p class="hint">Absolute paths to skip when scanning and browsing Video. A folder excludes everything under it. Example: <code>/volume1/video/Home Videos</code></p>
      <div class="folder-list" data-folder-list="video-exclude">
        <?php foreach ($videoExcludes as $root):
            $status = $root !== '' ? settings_path_status($root) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Excluded video path</label>
            <input type="text" name="video_excludes[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/video/skip-this" spellcheck="false" autocapitalize="off">
            <?php if ($status !== ''): ?>
            <p class="folder-status<?= $status === 'ok' ? '' : ' is-warn' ?>"><?= h(settings_path_status_label($status)) ?></p>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove excluded video path">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="video-exclude">Add excluded path</button>
    </section>

    <section class="form-section">
      <h2>Video library</h2>
      <p class="hint">Walk the video folders above and look up titles on TMDB. Save folder changes first. The scan page lets you choose unidentified-only or unmatched plus unidentified.</p>
      <a class="btn btn-accent btn-block" href="<?= h(app_href('video/scan.php')) ?>">Open scan page</a>
    </section>

    <section class="form-section">
      <h2>Music folders</h2>
      <p class="hint">Absolute paths to music shares. Category options come from the Music list on the Categories page.</p>
      <div class="folder-list" data-folder-list="music">
        <?php foreach ($musicSources as $source):
            $root = (string) ($source['path'] ?? '');
            $status = $root !== '' ? settings_path_status($root) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Music folder</label>
            <input type="text" name="music_roots[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/music" spellcheck="false" autocapitalize="off">
            <?php config_category_select('music_root_categories[]', (string) ($source['category'] ?? ''), $musicCategories, 'music'); ?>
            <?php if ($status !== ''): ?>
            <p class="folder-status<?= $status === 'ok' ? '' : ' is-warn' ?>"><?= h(settings_path_status_label($status)) ?></p>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove music folder">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="music">Add music folder</button>
    </section>

    <section class="form-section">
      <h2>Excluded music paths</h2>
      <p class="hint">Absolute paths to skip in the future Music catalog. A folder excludes everything under it.</p>
      <div class="folder-list" data-folder-list="music-exclude">
        <?php foreach ($musicExcludes as $root):
            $status = $root !== '' ? settings_path_status($root) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Excluded music path</label>
            <input type="text" name="music_excludes[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/music/skip-this" spellcheck="false" autocapitalize="off">
            <?php if ($status !== ''): ?>
            <p class="folder-status<?= $status === 'ok' ? '' : ' is-warn' ?>"><?= h(settings_path_status_label($status)) ?></p>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove excluded music path">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="music-exclude">Add excluded path</button>
    </section>

    <section class="form-section">
      <h2>Appearance</h2>
      <p class="hint">Applies on this device right away. Auto follows the system light or dark setting.</p>
      <div class="theme-picker" role="group" aria-label="Theme">
        <?php foreach (['auto' => 'Auto', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
        <button type="button" class="theme-pick<?= theme_pref() === $value ? ' is-active' : '' ?>" data-theme-set="<?= h($value) ?>" aria-pressed="<?= theme_pref() === $value ? 'true' : 'false' ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="form-section">
      <h2>TMDB</h2>
      <p class="hint">Optional. Without a key, Video still lists files from folder and file names. Get a key at <a href="https://www.themoviedb.org" target="_blank" rel="noopener noreferrer">themoviedb.org</a>.</p>
      <label class="field">
        <span class="field-label">API key</span>
        <input type="text" name="tmdb_api_key" class="folder-input" value="<?= h((string) $settings['tmdb_api_key']) ?>" spellcheck="false" autocapitalize="off" autocomplete="off">
      </label>
      <label class="field">
        <span class="field-label">Metadata language</span>
        <select name="tmdb_language" class="folder-input">
          <?php foreach ($languages as $code => $label): ?>
          <option value="<?= h($code) ?>"<?= $settings['tmdb_language'] === $code ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </section>

    <section class="form-section">
      <h2>xAI Grok</h2>
      <p class="hint">Optional second-pass matcher for titles TMDB did not match. Get a key at <a href="https://console.x.ai" target="_blank" rel="noopener noreferrer">console.x.ai</a>. The key stays on the server.</p>
      <label class="field">
        <span class="field-label">API key</span>
        <input type="password" name="xai_api_key" class="folder-input" value="<?= h((string) ($settings['xai_api_key'] ?? '')) ?>" spellcheck="false" autocapitalize="off" autocomplete="off">
      </label>
    </section>

    <button type="submit" class="btn btn-accent btn-block">Save settings</button>
  </form>
</main>
<?php
render_end();

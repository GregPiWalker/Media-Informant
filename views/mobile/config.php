<?php
declare(strict_types=1);

/** @var array<string, mixed> $settings */
/** @var list<array{id: string, label: string}> $videoCategories */
/** @var list<array{id: string, label: string}> $musicCategories */
/** @var list<array{path: string, category: string}> $videoSources */
/** @var list<array{path: string, category: string}> $musicSources */
/** @var list<string> $videoExcludes */
/** @var list<string> $musicExcludes */
/** @var list<array{id: string, name: string, host: string, port: string, user: string, pass: string}> $kodiRows */
/** @var string $kodiPrefix */
/** @var bool $saved */
/** @var string $refreshed */
/** @var int $refreshAdded */
/** @var int $refreshDropped */
/** @var int $refreshFiles */
/** @var int $refreshSkipped */
/** @var int $refreshWalked */
/** @var string $error */

function config_category_list(array $categories): string
{
    $labels = [];
    foreach ($categories as $category) {
        $label = trim((string) ($category['label'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return $labels === [] ? 'None' : implode(', ', $labels);
}

function config_source_folder_status(string $presence): void
{
    $ok = $presence === 'present' || $presence === 'ok';
    echo '<p class="folder-status' . ($ok ? '' : ' is-absent') . '">';
    if ($presence === 'absent') {
        $guide = app_href(rawurlencode('Media-Informant Install Guide.pdf'));
        echo 'Absent (<a href="' . h($guide) . '" target="_blank" rel="noopener noreferrer">missing PHP \'open_basedir\' value?</a>)';
        echo '</p>';
        return;
    }
    $label = function_exists('source_presence_label')
        ? source_presence_label($presence)
        : settings_path_status_label($presence);
    echo h($label) . '</p>';
}

function config_kodi_row(array $row): void
{
    $id = (string) ($row['id'] ?? '');
    $name = (string) ($row['name'] ?? '');
    $host = (string) ($row['host'] ?? '');
    $port = (string) ($row['port'] ?? '');
    $user = (string) ($row['user'] ?? '');
    $pass = (string) ($row['pass'] ?? '');
    echo '<div class="folder-row" data-kodi-row>';
    echo '<div class="folder-fields">';
    echo '<input type="hidden" name="kodi_host_ids[]" value="' . h($id) . '">';
    echo '<label class="kodi-field"><span class="field-label">Name</span>';
    echo '<input type="text" name="kodi_host_names[]" class="folder-input" value="' . h($name) . '" maxlength="80" placeholder="Living room" spellcheck="false" autocapitalize="words" autocomplete="off">';
    echo '</label>';
    echo '<label class="kodi-field"><span class="field-label">Host</span>';
    echo '<input type="text" name="kodi_host_addresses[]" class="folder-input" value="' . h($host) . '" placeholder="192.168.1.20" spellcheck="false" autocapitalize="off" autocomplete="off">';
    echo '</label>';
    echo '<label class="kodi-field"><span class="field-label">Port</span>';
    echo '<input type="text" name="kodi_host_ports[]" class="folder-input" value="' . h($port) . '" inputmode="numeric" placeholder="8080" spellcheck="false" autocapitalize="off" autocomplete="off">';
    echo '</label>';
    echo '<label class="kodi-field"><span class="field-label">Username</span>';
    echo '<input type="text" name="kodi_host_users[]" class="folder-input" value="' . h($user) . '" spellcheck="false" autocapitalize="off" autocomplete="off">';
    echo '</label>';
    echo '<label class="kodi-field"><span class="field-label">Password</span>';
    echo '<input type="password" name="kodi_host_passes[]" class="folder-input" value="' . h($pass) . '" spellcheck="false" autocomplete="off">';
    echo '</label>';
    echo '</div>';
    echo '<button type="button" class="btn btn-ghost" data-remove-kodi aria-label="Remove Kodi host">Remove</button>';
    echo '</div>';
}

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
$configTab = (string) ($configTab ?? 'video');
if ($configTab !== 'music' && $configTab !== 'general') {
    $configTab = 'video';
}
?>
<main class="page config-page">
  <header class="home-intro">
    <h1>Configuration</h1>
    <p>Point this install at the shares on this NAS. Assign a category to each source; everything inside inherits it.</p>
  </header>

  <?php if ($refreshed === 'busy'): ?>
  <p class="form-banner is-ok" role="status">A scan is running, so source folders were not refreshed. Try again after it finishes.</p>
  <?php elseif ($refreshed === 'error'): ?>
  <p class="form-banner is-error" role="alert">Source folders could not be refreshed. Check cache/ permissions.</p>
  <?php elseif ($refreshed === 'empty'): ?>
  <p class="form-banner is-ok" role="status">Add a folder on this tab and save settings before refreshing source folders.</p>
  <?php elseif ($refreshed === 'video' || $refreshed === 'music'): ?>
  <p class="form-banner is-ok" role="status"><?php
    $refreshNoun = $refreshed === 'music' ? 'music' : 'video';
    if ($refreshWalked < 1) {
        echo 'No ' . h($refreshNoun) . ' sources are present. '
            . (int) $refreshSkipped . ' absent source' . ($refreshSkipped === 1 ? ' was' : 's were')
            . ' skipped, so catalog files on ' . ($refreshSkipped === 1 ? 'that source were' : 'those sources were')
            . ' left in place.';
    } else {
        echo 'Refreshed ' . h($refreshNoun) . ' sources. Added ' . (int) $refreshAdded
            . ' new file' . ($refreshAdded === 1 ? '' : 's')
            . ' and removed ' . (int) $refreshDropped
            . ' missing file' . ($refreshDropped === 1 ? '' : 's')
            . '. ' . (int) $refreshFiles . ' ' . h($refreshNoun)
            . ' file' . ($refreshFiles === 1 ? '' : 's')
            . ' on the sources that were read.';
        if ($refreshSkipped > 0) {
            echo ' ' . (int) $refreshSkipped . ' absent source' . ($refreshSkipped === 1 ? ' was' : 's were')
                . ' skipped, so ' . ($refreshSkipped === 1 ? 'its' : 'their') . ' catalog files were left in place.';
        }
        if ($refreshed === 'video' && $refreshAdded > 0) {
            echo ' New files stay unidentified until you scan for titles.';
        }
    }
  ?></p>
  <?php if ($refreshed === 'video' && $refreshWalked > 0): ?>
  <a class="btn btn-accent btn-block" href="<?= h(app_href('video/scan.php')) ?>">Look up titles</a>
  <?php endif; ?>
  <?php elseif ($saved): ?>
  <p class="form-banner is-ok" role="status">Settings saved. Removed sources leave the catalog immediately. Use Refresh Source Folders to add new files and drop files that are gone. Scan looks up titles.</p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <p class="form-banner is-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <form id="refresh-sources-video" method="post" action="<?= h(app_href('config/index.php')) ?>">
    <input type="hidden" name="action" value="refresh_sources">
    <input type="hidden" name="catalog" value="video">
  </form>
  <form id="refresh-sources-music" method="post" action="<?= h(app_href('config/index.php')) ?>">
    <input type="hidden" name="action" value="refresh_sources">
    <input type="hidden" name="catalog" value="music">
  </form>

  <nav class="branch-nav config-tabs" role="tablist" aria-label="Settings sections">
    <button type="button" class="branch-tab<?= $configTab === 'general' ? ' is-active' : '' ?>" role="tab" id="config-tab-general" data-config-tab="general" aria-controls="config-panel-general" aria-selected="<?= $configTab === 'general' ? 'true' : 'false' ?>">General</button>
    <button type="button" class="branch-tab<?= $configTab === 'video' ? ' is-active' : '' ?>" role="tab" id="config-tab-video" data-config-tab="video" aria-controls="config-panel-video" aria-selected="<?= $configTab === 'video' ? 'true' : 'false' ?>">Video</button>
    <button type="button" class="branch-tab<?= $configTab === 'music' ? ' is-active' : '' ?>" role="tab" id="config-tab-music" data-config-tab="music" aria-controls="config-panel-music" aria-selected="<?= $configTab === 'music' ? 'true' : 'false' ?>">Music</button>
  </nav>

  <form class="config-form" method="post" action="<?= h(app_href('config/index.php')) ?>" autocomplete="off"<?= function_exists('kodi_enabled') && kodi_enabled() ? ' data-kodi-smb' : '' ?>>
    <input type="hidden" name="config_tab" value="<?= h($configTab) ?>" data-config-tab-field>
    <div id="config-panel-video" class="config-panel" data-config-panel="video" role="tabpanel" aria-labelledby="config-tab-video"<?= $configTab === 'video' ? '' : ' hidden' ?>>
    <section class="form-section">
      <h2>Video folders</h2>
      <p class="hint">Absolute paths to video shares, one per row. Save settings to keep this list. Refresh Source Folders reads each saved source that is Present, adds new video files, and removes files that are gone. An Absent source, such as an unplugged drive, is skipped and its catalog files stay. Scan looks up posters and overviews.</p>
      <?php if (function_exists('kodi_enabled') && kodi_enabled()): ?>
      <p class="hint">SMB path is how Kodi reaches that share. It replaces the NAS folder when a file is played. Folder /volume1/video and SMB path smb://nas/video opens /volume1/video/Movies/Foo.mkv as smb://nas/video/Movies/Foo.mkv.</p>
      <?php endif; ?>
      <div class="folder-list" data-folder-list="video">
        <?php foreach ($videoSources as $source):
            $root = (string) ($source['path'] ?? '');
            $presence = $root !== '' ? (function_exists('source_presence_live') ? source_presence_live($root) : settings_path_status($root)) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Video folder</label>
            <input type="text" name="video_roots[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/video" spellcheck="false" autocapitalize="off">
            <?php config_category_select('video_root_categories[]', (string) ($source['category'] ?? ''), $videoCategories, 'video'); ?>
            <?php if (function_exists('kodi_enabled') && kodi_enabled()): ?>
            <label class="kodi-field">
              <span class="field-label">SMB path</span>
              <input type="text" name="video_root_smb[]" class="folder-input" value="<?= h((string) ($source['smb'] ?? '')) ?>" placeholder="smb://nas/video" spellcheck="false" autocapitalize="off" autocomplete="off">
            </label>
            <?php endif; ?>
            <?php if ($presence !== ''): ?>
            <?php config_source_folder_status($presence); ?>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove video folder">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="video">Add video folder</button>
      <button type="submit" class="btn btn-accent btn-block" form="refresh-sources-video" data-refresh-sources>Refresh Source Folders</button>
      <p class="refresh-status" data-refresh-status="video" hidden role="status">Refreshing video folders. This can take a while.</p>
      <p class="hint">Uses the saved video folders and excluded paths. Save settings first if you changed them.</p>
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

    </div>

    <div id="config-panel-music" class="config-panel" data-config-panel="music" role="tabpanel" aria-labelledby="config-tab-music"<?= $configTab === 'music' ? '' : ' hidden' ?>>
    <section class="form-section">
      <h2>Music folders</h2>
      <p class="hint">Absolute paths to music shares. Save settings to keep this list. Refresh Source Folders reads each saved source that is Present, adds new music files, and removes files that are gone. An Absent source, such as a USB drive that is not mounted, is skipped and its catalog files stay.</p>
      <?php if (function_exists('kodi_enabled') && kodi_enabled()): ?>
      <p class="hint">SMB path is how Kodi reaches that share. It replaces the NAS folder when a file is played. Folder /volume1/music and SMB path smb://nas/music opens a track under that folder from the SMB share.</p>
      <?php endif; ?>
      <div class="folder-list" data-folder-list="music">
        <?php foreach ($musicSources as $source):
            $root = (string) ($source['path'] ?? '');
            $presence = $root !== '' ? (function_exists('source_presence_live') ? source_presence_live($root) : settings_path_status($root)) : '';
            ?>
        <div class="folder-row" data-folder-row>
          <div class="folder-fields">
            <label class="visually-hidden">Music folder</label>
            <input type="text" name="music_roots[]" class="folder-input" value="<?= h($root) ?>" placeholder="/volume1/music" spellcheck="false" autocapitalize="off">
            <?php config_category_select('music_root_categories[]', (string) ($source['category'] ?? ''), $musicCategories, 'music'); ?>
            <?php if (function_exists('kodi_enabled') && kodi_enabled()): ?>
            <label class="kodi-field">
              <span class="field-label">SMB path</span>
              <input type="text" name="music_root_smb[]" class="folder-input" value="<?= h((string) ($source['smb'] ?? '')) ?>" placeholder="smb://nas/music" spellcheck="false" autocapitalize="off" autocomplete="off">
            </label>
            <?php endif; ?>
            <?php if ($presence !== ''): ?>
            <?php config_source_folder_status($presence); ?>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-ghost" data-remove-folder aria-label="Remove music folder">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-folder="music">Add music folder</button>
      <button type="submit" class="btn btn-accent btn-block" form="refresh-sources-music" data-refresh-sources>Refresh Source Folders</button>
      <p class="refresh-status" data-refresh-status="music" hidden role="status">Refreshing music folders. This can take a while.</p>
      <p class="hint">Uses the saved music folders and excluded paths. Save settings first if you changed them. An unplugged drive is skipped.</p>
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
      <h2>Music library</h2>
      <p class="hint">Refresh Source Folders records the music files. Looking up artist and album is not available yet. A music scan cannot run at the same time as a video scan.</p>
      <a class="btn btn-accent btn-block" href="<?= h(app_href('music/scan.php')) ?>">Open music scan page</a>
    </section>
    </div>

    <div id="config-panel-general" class="config-panel" data-config-panel="general" role="tabpanel" aria-labelledby="config-tab-general"<?= $configTab === 'general' ? '' : ' hidden' ?>>
    <section class="form-section">
      <h2>Categories</h2>
      <p class="hint">Names used when you assign a category to a source folder.</p>
      <p class="hint">Video: <?= h(config_category_list($videoCategories)) ?><br>Music: <?= h(config_category_list($musicCategories)) ?></p>
      <a class="btn btn-ghost btn-block" href="<?= h(app_href('config/categories.php')) ?>">Edit categories</a>
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
      <h2>Catalog</h2>
      <p class="hint">Choose which libraries are shown. Both keeps the Video and Music tabs. Music or Video hides that tab bar and shows only the selected library.</p>
      <?php $catalogMode = function_exists('settings_catalog_mode_value') ? settings_catalog_mode_value($settings['catalog_mode'] ?? 'both') : 'both'; ?>
      <div class="catalog-mode-slider" role="radiogroup" aria-label="Libraries">
        <span class="catalog-mode-thumb" aria-hidden="true"></span>
        <?php foreach (['music' => 'Music', 'video' => 'Video', 'both' => 'Both'] as $modeValue => $modeLabel): ?>
        <label class="catalog-mode-opt">
          <input type="radio" name="catalog_mode" value="<?= h($modeValue) ?>"<?= $catalogMode === $modeValue ? ' checked' : '' ?>>
          <span><?= h($modeLabel) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Hidden files stay in the library and keep their match data. They are omitted from all catalogs until you turn this on.</p>
      <label class="grok-toggle">
        <input type="checkbox" name="show_hidden" value="1"<?= !empty($settings['show_hidden']) ? ' checked' : '' ?>>
        <span>Show hidden files</span>
      </label>
    </section>

    <section class="form-section">
      <h2>TMDB</h2>
      <p class="hint">Optional, but required for video meta-data searches. Without a key, Video still lists files from folder and file names. Get a key at <a href="https://www.themoviedb.org" target="_blank" rel="noopener noreferrer">themoviedb.org</a>.</p>
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
      <p class="hint">Optional stage 2 of Scan for ambiguous TMDB hits. Get a key at <a href="https://console.x.ai" target="_blank" rel="noopener noreferrer">console.x.ai</a>. Toggle Grok on the scan page without stopping.</p>
      <label class="field">
        <span class="field-label">API key</span>
        <input type="password" name="xai_api_key" class="folder-input" value="<?= h((string) ($settings['xai_api_key'] ?? '')) ?>" spellcheck="false" autocapitalize="off" autocomplete="off">
      </label>
    </section>

    <section class="form-section">
      <h2>Kodi</h2>
      <p class="hint">Optional. Add each Kodi player, such as an NVIDIA Shield, that should be able to play a file from a title page. Leave every row blank to hide Play on Kodi. Name is the label you pick. Host is an IP address or hostname, without http://. Port defaults to 8080. Username and password are optional and are sent only as Basic Auth to that player.</p>
      <div class="folder-list" data-kodi-list>
        <?php foreach ($kodiRows as $kodiRow): ?>
        <?php config_kodi_row($kodiRow); ?>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-block" data-add-kodi>Add Kodi host</button>
      <label class="field">
        <span class="field-label">Path prefix</span>
        <input type="text" name="kodi_path_prefix" class="folder-input" value="<?= h($kodiPrefix) ?>" placeholder="smb://nas/Video" spellcheck="false" autocapitalize="off" autocomplete="off">
      </label>
      <p class="hint">Optional fallback for a source that has no SMB path. The matching NAS folder is removed and this prefix is put in front. A source SMB path on the Video or Music tab is used instead. Leave this empty and a source with no SMB path sends smb:// plus this site’s host and the full NAS path. The log records the path Kodi was asked to open.</p>
    </section>
    </div>

    <button type="submit" class="btn btn-accent btn-block">Save settings</button>
  </form>
</main>
<?php
render_end();

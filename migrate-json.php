<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/json_import.php';

cache_init();
header('Cache-Control: no-store');

$catalog = (string) ($_POST['catalog'] ?? $_GET['catalog'] ?? 'video');
if ($catalog !== 'music') {
    $catalog = 'video';
}
$doSchema = isset($_POST['do_schema']) || isset($_POST['do_import']);
$doImport = isset($_POST['do_import']);
$force = isset($_POST['force']);
$keyNeed = defined('MIGRATE_KEY') ? trim((string) MIGRATE_KEY) : '';
$keyGot = trim((string) ($_POST['migrate_key'] ?? ''));
$ran = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$lines = [];
$errors = [];

if ($ran) {
    if ($keyNeed !== '' && !hash_equals($keyNeed, $keyGot)) {
        $errors[] = 'MIGRATE_KEY did not match.';
        $ran = false;
    }
}

if ($ran) {
    if ($doSchema || $doImport) {
        $pdo = db_open($catalog);
        if ($pdo === null) {
            $errors[] = db_last_error() !== '' ? db_last_error() : 'Could not open or migrate the database.';
        } else {
            $lines[] = 'Schema ready for ' . $catalog . ' at ' . db_path($catalog) . '.';
        }
    } else {
        $errors[] = 'Choose at least Create/migrate schema or Import JSON.';
    }
    if ($doImport && $errors === []) {
        $result = json_import_catalog($catalog, $force);
        if (empty($result['ok'])) {
            $errors[] = (string) ($result['error'] ?? 'Import failed.');
        } else {
            $lines[] = 'Import finished.';
            $lines[] = 'files=' . (int) $result['files']
                . ' items=' . (int) $result['items']
                . ' matched=' . (int) $result['matched']
                . ' unmatched=' . (int) $result['unmatched']
                . ' candidates=' . (int) $result['candidates']
                . ' errors=' . (int) $result['errors'];
        }
        json_import_log(($catalog) . ' schema=' . ($doSchema ? '1' : '0')
            . ' import=' . ($doImport ? '1' : '0')
            . ' force=' . ($force ? '1' : '0')
            . ' files=' . (int) ($result['files'] ?? 0)
            . ' items=' . (int) ($result['items'] ?? 0)
            . ' matched=' . (int) ($result['matched'] ?? 0)
            . ' unmatched=' . (int) ($result['unmatched'] ?? 0)
            . ' candidates=' . (int) ($result['candidates'] ?? 0)
            . ' errors=' . (int) ($result['errors'] ?? 0)
            . ($errors !== [] ? ' error=' . $errors[0] : ' ok'));
    } elseif ($ran && $doSchema && $errors === []) {
        json_import_log($catalog . ' schema=1 import=0 ok');
    }
}

$jsonPath = db_json_source_path($catalog);
$sqlitePath = db_path($catalog);
$fileCount = db_available() ? db_files_count($catalog) : 0;

render_start('Migrate JSON · Media Informant');
render_header(['section' => 'config', 'branches' => false]);
?>
<main class="page config-page">
  <header class="home-intro">
    <h1>Migrate JSON to SQLite</h1>
    <p>One-time import of the JSON catalog into SQLite. This page is not linked from the app. The main catalog never imports JSON by itself.</p>
  </header>

  <?php foreach ($errors as $err): ?>
  <p class="form-banner is-error" role="alert"><?= h($err) ?></p>
  <?php endforeach; ?>
  <?php foreach ($lines as $line): ?>
  <p class="form-banner is-ok" role="status"><?= h($line) ?></p>
  <?php endforeach; ?>

  <form class="config-form" method="post" action="<?= h(app_href('migrate-json.php')) ?>">
    <section class="form-section">
      <h2>Catalog</h2>
      <label class="field">
        <span class="field-label">Catalog</span>
        <select name="catalog" class="folder-input" onchange="this.form.method='get'; this.form.submit();">
          <option value="video"<?= $catalog === 'video' ? ' selected' : '' ?>>video</option>
          <option value="music"<?= $catalog === 'music' ? ' selected' : '' ?>>music</option>
        </select>
      </label>
      <p class="hint">JSON source<br><code><?= h($jsonPath) ?></code><?= is_file($jsonPath) ? '' : ' (missing)' ?></p>
      <p class="hint">SQLite destination<br><code><?= h($sqlitePath) ?></code><?= is_file($sqlitePath) ? '' : ' (not created yet)' ?></p>
      <p class="hint">files table rows now: <?= (int) $fileCount ?></p>
      <?php if ($keyNeed !== ''): ?>
      <label class="field">
        <span class="field-label">MIGRATE_KEY</span>
        <input type="password" name="migrate_key" class="folder-input" value="" autocomplete="off">
      </label>
      <?php endif; ?>
      <label class="grok-toggle">
        <input type="checkbox" name="do_schema" value="1" checked>
        <span>Create / migrate schema</span>
      </label>
      <label class="grok-toggle">
        <input type="checkbox" name="do_import" value="1" checked>
        <span>Import JSON into this catalog DB</span>
      </label>
      <label class="grok-toggle">
        <input type="checkbox" name="force" value="1">
        <span>Force reimport (required if files already has rows)</span>
      </label>
    </section>
    <button type="submit" class="btn btn-accent btn-block">Run</button>
  </form>
</main>
<?php
render_end();

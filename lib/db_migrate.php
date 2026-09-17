<?php
declare(strict_types=1);

function db_schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS catalog_meta (
  catalog TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  root_path TEXT,
  updated_at INTEGER
);
CREATE TABLE IF NOT EXISTS item_types (
  id INTEGER PRIMARY KEY,
  catalog TEXT NOT NULL,
  slug TEXT NOT NULL,
  label TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0,
  UNIQUE (catalog, slug)
);
CREATE TABLE IF NOT EXISTS items (
  id TEXT PRIMARY KEY,
  type_id INTEGER NOT NULL REFERENCES item_types(id),
  parent_id TEXT REFERENCES items(id) ON DELETE SET NULL,
  grouping TEXT NOT NULL DEFAULT 'standalone',
  title TEXT,
  sort_title TEXT,
  year INTEGER,
  tmdb_id INTEGER,
  tmdb_media_type TEXT,
  overview TEXT,
  poster_path TEXT,
  extras_json TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS items_type_sort ON items (type_id, sort_title);
CREATE INDEX IF NOT EXISTS items_tmdb ON items (tmdb_id);
CREATE INDEX IF NOT EXISTS items_parent ON items (parent_id);
CREATE TABLE IF NOT EXISTS files (
  id TEXT PRIMARY KEY,
  path TEXT NOT NULL UNIQUE,
  item_id TEXT REFERENCES items(id) ON DELETE SET NULL,
  parsed_title TEXT,
  parsed_year INTEGER,
  size INTEGER,
  mtime INTEGER,
  state TEXT,
  match_source TEXT,
  grok_verified INTEGER NOT NULL DEFAULT 0,
  confidence REAL,
  kind TEXT
);
CREATE INDEX IF NOT EXISTS files_state ON files (state);
CREATE INDEX IF NOT EXISTS files_item ON files (item_id);
CREATE INDEX IF NOT EXISTS files_path ON files (path);
CREATE TABLE IF NOT EXISTS file_candidates (
  file_id TEXT NOT NULL REFERENCES files(id) ON DELETE CASCADE,
  tmdb_id INTEGER NOT NULL,
  title TEXT,
  year INTEGER,
  media_type TEXT,
  score REAL,
  rank INTEGER,
  PRIMARY KEY (file_id, tmdb_id)
);
CREATE TABLE IF NOT EXISTS scan_runs (
  id INTEGER PRIMARY KEY,
  started_at INTEGER,
  finished_at INTEGER,
  files_seen INTEGER,
  matched INTEGER,
  grok_batches INTEGER,
  grok_cost_usd REAL,
  status TEXT
);
SQL;
}

function db_migrate(PDO $pdo, string $catalog): void
{
    $catalog = $catalog === 'music' ? 'music' : 'video';
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
    $row = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    $current = is_array($row) ? (int) $row['version'] : 0;
    if ($current < 1) {
        $pdo->exec(db_schema_sql());
        db_seed_catalog($pdo, $catalog);
        if ($current === 0) {
            $has = $pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
            if ((int) $has < 1) {
                $pdo->exec('INSERT INTO schema_version (version) VALUES (1)');
            } else {
                $pdo->exec('UPDATE schema_version SET version = 1');
            }
        }
    }
}

function db_seed_catalog(PDO $pdo, string $catalog): void
{
    $now = time();
    $name = $catalog === 'music' ? 'Music' : 'Video';
    $root = $catalog === 'music'
        ? (function_exists('settings_music_roots') ? implode('; ', settings_music_roots()) : (defined('MUSIC_ROOT') ? MUSIC_ROOT : ''))
        : (function_exists('settings_video_roots') ? implode('; ', settings_video_roots()) : (defined('VIDEO_ROOT') ? VIDEO_ROOT : ''));
    $meta = $pdo->prepare('INSERT OR IGNORE INTO catalog_meta (catalog, name, root_path, updated_at) VALUES (?, ?, ?, ?)');
    $meta->execute([$catalog, $name, $root, $now]);

    $types = $catalog === 'music'
        ? [
            ['album', 'Album', 1],
            ['track', 'Track', 2],
        ]
        : [
            ['movie', 'Movies', 1],
            ['tv_show', 'TV Shows', 2],
            ['documentary', 'Documentaries', 3],
        ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO item_types (catalog, slug, label, sort) VALUES (?, ?, ?, ?)');
    foreach ($types as $row) {
        $ins->execute([$catalog, $row[0], $row[1], $row[2]]);
    }
}

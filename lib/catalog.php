<?php
declare(strict_types=1);

final class CatalogColumn
{
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public bool $required = false,
        public bool $defaultVisible = true,
    ) {
    }

    public function sortable(): bool
    {
        return $this->kind === 'text';
    }

    public function hideable(): bool
    {
        return !$this->required;
    }
}

final class CatalogSchema
{
    /** @param list<CatalogColumn> $columns */
    public function __construct(
        public string $id,
        public array $columns,
        public array $views = ['list', 'poster', 'genre'],
    ) {
    }

    public function column(string $id): ?CatalogColumn
    {
        foreach ($this->columns as $column) {
            if ($column->id === $id) {
                return $column;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function defaultColumnIds(): array
    {
        $ids = [];
        foreach ($this->columns as $column) {
            if ($column->required || $column->defaultVisible) {
                $ids[] = $column->id;
            }
        }
        return $ids;
    }

    /** @return list<string> */
    public function requiredColumnIds(): array
    {
        $ids = [];
        foreach ($this->columns as $column) {
            if ($column->required) {
                $ids[] = $column->id;
            }
        }
        return $ids;
    }
}

final class CatalogPreferences
{
    public const COOKIE = 'media_catalog';
    public const VIEWS = ['list', 'poster', 'genre'];

    /** @param list<string> $columns */
    /** @param list<string> $kinds */
    /** @param list<string> $categories */
    public function __construct(
        public string $view,
        public array $columns,
        public string $sort,
        public string $dir,
        public array $kinds = ['movie', 'show', 'documentary'],
        public array $categories = [],
    ) {
    }

    public static function load(CatalogSchema $schema, array $categoryIds = []): self
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }

        $view = (string) ($data['view'] ?? 'poster');
        if (!in_array($view, self::VIEWS, true) || !in_array($view, $schema->views, true)) {
            $view = 'poster';
        }

        $columns = $data['columns'] ?? $schema->defaultColumnIds();
        if (!is_array($columns)) {
            $columns = $schema->defaultColumnIds();
        }
        $allowed = [];
        foreach ($schema->columns as $column) {
            $allowed[$column->id] = true;
        }
        $visible = [];
        foreach ($columns as $id) {
            if (is_string($id) && isset($allowed[$id]) && !in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }
        $rawCols = $data['columns'] ?? null;
        $hadMatchCol = is_array($rawCols) && in_array('match', $rawCols, true);
        $knownCols = $data['columns_known'] ?? null;
        foreach ($schema->columns as $column) {
            if (!$column->defaultVisible || in_array($column->id, $visible, true)) {
                continue;
            }
            if (is_array($knownCols) && !in_array($column->id, $knownCols, true)) {
                $visible[] = $column->id;
            }
        }
        if ($hadMatchCol && !in_array('status', $visible, true)) {
            $visible[] = 'status';
        }
        foreach ($schema->requiredColumnIds() as $id) {
            if (!in_array($id, $visible, true)) {
                array_unshift($visible, $id);
            }
        }
        if ($visible === []) {
            $visible = $schema->defaultColumnIds();
        }

        $sort = (string) ($data['sort'] ?? 'title');
        $sortCol = $schema->column($sort);
        if ($sortCol === null || !$sortCol->sortable()) {
            $sort = 'title';
        }

        $dir = strtolower((string) ($data['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $allKinds = ['movie', 'show', 'documentary'];
        $kinds = $data['kinds'] ?? $allKinds;
        if (!is_array($kinds)) {
            $kinds = $allKinds;
        }
        $kindOut = [];
        foreach ($kinds as $kind) {
            if (in_array($kind, $allKinds, true) && !in_array($kind, $kindOut, true)) {
                $kindOut[] = $kind;
            }
        }
        $knownKinds = $data['kinds_known'] ?? null;
        if (is_array($knownKinds)) {
            foreach ($allKinds as $kind) {
                if (!in_array($kind, $knownKinds, true) && !in_array($kind, $kindOut, true)) {
                    $kindOut[] = $kind;
                }
            }
        } elseif (!in_array('documentary', $kindOut, true)) {
            $kindOut[] = 'documentary';
        }
        if ($kindOut === []) {
            $kindOut = $allKinds;
        }

        $allCats = $categoryIds !== [] ? $categoryIds : (function_exists('settings_category_ids') ? settings_category_ids() : []);
        $allCats[] = 'none';
        $allCats = array_values(array_unique($allCats));
        $savedCats = $data['categories'] ?? null;
        $knownCats = $data['categories_known'] ?? null;
        if (!is_array($savedCats)) {
            $catOut = $allCats;
        } else {
            $catOut = [];
            foreach ($savedCats as $id) {
                if (is_string($id) && in_array($id, $allCats, true) && !in_array($id, $catOut, true)) {
                    $catOut[] = $id;
                }
            }
            if (is_array($knownCats)) {
                foreach ($allCats as $id) {
                    if (!in_array($id, $knownCats, true) && !in_array($id, $catOut, true)) {
                        $catOut[] = $id;
                    }
                }
            }
            if ($catOut === []) {
                $catOut = $allCats;
            }
        }

        return new self($view, $visible, $sort, $dir, $kindOut, $catOut);
    }

    public function colsAttr(): string
    {
        return implode(' ', $this->columns);
    }

    public function kindsAttr(): string
    {
        return implode(' ', $this->kinds);
    }

    public function showsKind(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function categoriesAttr(): string
    {
        return implode(' ', $this->categories);
    }

    public function showsCategory(string $id): bool
    {
        return in_array($id, $this->categories, true);
    }

    public function shows(string $columnId): bool
    {
        return in_array($columnId, $this->columns, true);
    }
}

final class CatalogRecord
{
    /** @param array<string, string> $cells */
    /** @param array<string, string> $sortKeys */
    /** @param list<string> $genres */
    public function __construct(
        public string $id,
        public string $href,
        public string $title,
        public string $search,
        public ?string $poster,
        public array $cells,
        public array $sortKeys,
        public array $genres,
        public string $status = 'unmatched',
        public string $kind = 'movie',
        public string $seriesTitle = '',
        public string $groupKey = '',
        public string $episodeLabel = '',
        public ?int $season = null,
        public ?int $episode = null,
        public string $episodeTitle = '',
        public string $partLabel = '',
        public string $episodeGroupKey = '',
        public string $category = '',
        public string $matchSource = 'none',
        public bool $grouped = false,
        public bool $sourceAbsent = false,
    ) {
    }

    public static function fromVideo(array $item): self
    {
        $id = (string) ($item['id'] ?? '');
        $parsed = (string) ($item['title'] ?? '');
        $title = (string) ($item['display_title'] ?? '');
        if ($title === '') {
            $title = $parsed !== '' ? $parsed : 'Untitled';
        }
        $year = $item['year'] ?? null;
        $status = library_item_status($item);
        $genres = cache_string_list($item['genres'] ?? []);
        $posterPath = isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : null;
        $yearLabel = $year ? (string) $year : '';
        $statusLabel = library_status_display($item);
        $matchSource = library_match_source($item);
        $matchLabel = library_match_source_label($matchSource);
        $genreLabel = $genres !== [] ? implode(', ', $genres) : '';
        $season = isset($item['season']) && $item['season'] !== null && $item['season'] !== ''
            ? (int) $item['season']
            : null;
        $episode = isset($item['episode']) && $item['episode'] !== null && $item['episode'] !== ''
            ? (int) $item['episode']
            : null;
        $kind = function_exists('library_item_kind') ? library_item_kind($item) : (string) ($item['kind'] ?? '');
        if ($kind !== 'show' && $kind !== 'movie' && $kind !== 'documentary') {
            $kind = ($season !== null || $episode !== null) ? 'show' : 'movie';
        }
        $grouped = function_exists('library_item_grouped') ? library_item_grouped($item) : ($kind === 'show');
        if ($kind === 'show') {
            $grouped = true;
        }
        $seriesTitle = $parsed !== '' ? $parsed : $title;
        $groupKey = $grouped ? ($kind . ':' . lower($seriesTitle)) : $id;
        $episodeTitle = (string) ($item['episode_title'] ?? '');
        $partLabel = (string) ($item['part'] ?? '');
        if ($partLabel === '' && $episodeTitle !== '') {
            $fn = pathinfo((string) ($item['filename'] ?? ''), PATHINFO_FILENAME);
            if ($fn !== '' && lower($fn) !== lower($episodeTitle)) {
                $partLabel = $fn;
            }
        }
        $episodeLabel = '';
        if ($grouped) {
            if ($episodeTitle !== '') {
                $episodeLabel = ($season !== null ? sprintf('S%02d · ', $season) : '') . $episodeTitle;
            } elseif ($season !== null || $episode !== null) {
                $episodeLabel = sprintf('S%02dE%02d', $season ?? 0, $episode ?? 0);
            } else {
                $fn = (string) ($item['filename'] ?? '');
                $episodeLabel = $fn !== '' ? pathinfo($fn, PATHINFO_FILENAME) : $title;
            }
        }
        $episodeGroupKey = $grouped
            ? sprintf('%02d|%s', $season ?? 0, lower($episodeTitle !== '' ? $episodeTitle : ($episode !== null ? (string) $episode : $id)))
            : $id;
        $cellTitle = $grouped ? $episodeLabel : $title;
        $category = (string) ($item['category'] ?? '');
        if ($category === '' && function_exists('settings_category_for_root')) {
            $category = settings_category_for_root((string) ($item['root'] ?? ''));
        }

        return new self(
            $id,
            'title.php?id=' . $id,
            $title,
            lower($seriesTitle . ' ' . $title . ' ' . $episodeLabel . ' ' . $episodeTitle . ' ' . $partLabel . ' ' . $yearLabel . ' ' . $genreLabel . ' ' . $matchLabel . ' ' . (string) ($item['filename'] ?? '')),
            poster_url($posterPath),
            [
                'title' => $cellTitle,
                'year' => $yearLabel !== '' ? $yearLabel : '—',
                'status' => $statusLabel,
                'genres' => $genreLabel !== '' ? $genreLabel : '—',
            ],
            [
                'title' => $grouped ? lower($seriesTitle) : lower($title),
                'year' => $year ? sprintf('%04d', (int) $year) : '0000',
                'status' => library_status_sort_key($item),
                'genres' => lower(catalog_first_genre_name($genres)),
                'episode' => sprintf('%02d%03d', $season ?? 0, $episode ?? 0),
            ],
            $genres,
            $status,
            $kind,
            $seriesTitle,
            $groupKey,
            $episodeLabel,
            $season,
            $episode,
            $episodeTitle,
            $partLabel,
            $episodeGroupKey,
            $category,
            $matchSource,
            $grouped,
            function_exists('source_is_absent_for_item') && source_is_absent_for_item($item),
        );
    }

    public static function fromMusic(array $item): self
    {
        $id = (string) ($item['id'] ?? '');
        $title = (string) ($item['display_title'] ?? $item['title'] ?? 'Untitled');
        $artist = (string) ($item['artist'] ?? '');
        $album = (string) ($item['album'] ?? '');
        $year = $item['year'] ?? null;
        $genres = cache_string_list($item['genres'] ?? []);
        $posterPath = isset($item['poster_path']) && is_string($item['poster_path']) ? $item['poster_path'] : null;
        $yearLabel = $year ? (string) $year : '';
        $genreLabel = $genres !== [] ? implode(', ', $genres) : '';

        return new self(
            $id,
            'title.php?id=' . $id,
            $title,
            lower($title . ' ' . $artist . ' ' . $album . ' ' . $yearLabel . ' ' . $genreLabel),
            poster_url($posterPath),
            [
                'title' => $title,
                'artist' => $artist !== '' ? $artist : '—',
                'album' => $album !== '' ? $album : '—',
                'year' => $yearLabel !== '' ? $yearLabel : '—',
                'genres' => $genreLabel !== '' ? $genreLabel : '—',
            ],
            [
                'title' => lower($title),
                'artist' => lower($artist),
                'album' => lower($album),
                'year' => $year ? sprintf('%04d', (int) $year) : '0000',
                'genres' => lower(catalog_first_genre_name($genres)),
            ],
            $genres,
            (string) ($item['status'] ?? 'unmatched'),
        );
    }
}

/** @param list<string> $genres */
function catalog_first_genre_name(array $genres): string
{
    foreach ($genres as $genre) {
        $genre = trim((string) $genre);
        if ($genre !== '') {
            return $genre;
        }
    }
    return '';
}

function catalog_title_bucket(string $sortTitle): string
{
    $s = trim($sortTitle);
    if ($s === '') {
        return '#';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            if (preg_match('/^\p{L}$/u', $ch)) {
                return mb_strtoupper($ch, 'UTF-8');
            }
            if (preg_match('/^\p{N}$/u', $ch)) {
                return $ch;
            }
        }
        return '#';
    }
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if (ctype_alpha($ch)) {
            return strtoupper($ch);
        }
        if (ctype_digit($ch)) {
            return $ch;
        }
    }
    return '#';
}

function catalog_poster_bucket(CatalogGroup $group, string $sort): string
{
    $head = $group->head;
    if ($sort === 'year') {
        $year = (string) ($head->sortKeys['year'] ?? '');
        if ($year === '' || $year === '0000') {
            return 'Unknown';
        }
        return $year;
    }
    if ($sort === 'status') {
        $status = trim((string) ($head->cells['status'] ?? ''));
        return ($status !== '' && $status !== '—') ? $status : 'Unidentified';
    }
    if ($sort === 'genres') {
        $genre = catalog_first_genre_name($head->genres);
        return $genre !== '' ? $genre : 'Uncategorized';
    }
    $title = (string) ($head->sortKeys['title'] ?? '');
    if ($title === '') {
        $title = $group->isSeries() ? $head->seriesTitle : $head->title;
    }
    return catalog_title_bucket($title);
}

/** @return array<string, string> */
function catalog_poster_buckets(CatalogGroup $group): array
{
    return [
        'title' => catalog_poster_bucket($group, 'title'),
        'year' => catalog_poster_bucket($group, 'year'),
        'status' => catalog_poster_bucket($group, 'status'),
        'genres' => catalog_poster_bucket($group, 'genres'),
    ];
}

function catalog_poster_bucket_attrs(CatalogGroup $group): string
{
    $out = '';
    foreach (catalog_poster_buckets($group) as $key => $label) {
        $out .= ' data-bucket-' . $key . '="' . h($label) . '"';
    }
    return $out;
}

function catalog_video_schema(): CatalogSchema
{
    return new CatalogSchema('video', [
        new CatalogColumn('poster', 'Poster', 'media', false, true),
        new CatalogColumn('title', 'Title', 'text', true, true),
        new CatalogColumn('year', 'Year', 'text', false, true),
        new CatalogColumn('status', 'Status', 'text', false, false),
        new CatalogColumn('genres', 'Genres', 'text', false, false),
    ]);
}

function catalog_music_schema(): CatalogSchema
{
    return new CatalogSchema('music', [
        new CatalogColumn('poster', 'Art', 'media', false, true),
        new CatalogColumn('title', 'Title', 'text', true, true),
        new CatalogColumn('artist', 'Artist', 'text', false, true),
        new CatalogColumn('album', 'Album', 'text', false, true),
        new CatalogColumn('year', 'Year', 'text', false, false),
        new CatalogColumn('genres', 'Genre', 'text', false, false),
    ]);
}

final class CatalogGroup
{
    /** @param list<CatalogRecord> $members */
    public function __construct(
        public string $id,
        public string $kind,
        public CatalogRecord $head,
        public array $members,
    ) {
    }

    public function isSeries(): bool
    {
        return $this->head->grouped || $this->kind === 'show';
    }

    /** @return list<array{id: string, label: string, parts: list<CatalogRecord>}> */
    public function episodeClusters(): array
    {
        if (!$this->isSeries()) {
            return [];
        }
        $buckets = [];
        $order = [];
        foreach ($this->members as $member) {
            $key = $member->episodeGroupKey !== '' ? $member->episodeGroupKey : $member->id;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
                $order[] = $key;
            }
            $buckets[$key][] = $member;
        }
        $out = [];
        foreach ($order as $key) {
            $parts = $buckets[$key];
            $first = $parts[0];
            $out[] = [
                'id' => $this->id . ':ep:' . rawurlencode($key),
                'label' => $first->episodeLabel !== '' ? $first->episodeLabel : $first->title,
                'parts' => $parts,
            ];
        }
        return $out;
    }

    public function search(): string
    {
        $parts = [];
        foreach ($this->members as $member) {
            $parts[] = $member->search;
        }
        return implode(' ', $parts);
    }

    /** @return list<string> */
    public function genres(): array
    {
        if ($this->isSeries() && $this->head->genres !== []) {
            return $this->head->genres;
        }
        $seen = [];
        $out = [];
        foreach ($this->members as $member) {
            foreach ($member->genres as $genre) {
                if (!isset($seen[$genre])) {
                    $seen[$genre] = true;
                    $out[] = $genre;
                }
            }
        }
        return $out;
    }
}

function catalog_record_thumb_html(CatalogRecord $record, string $size, string $label = ''): string
{
    $class = 'catalog-thumb catalog-thumb-' . $size;
    $initial = $label !== '' ? $label : $record->title;
    if ($record->poster) {
        return '<span class="' . $class . '"><img src="' . h($record->poster) . '" alt="" loading="lazy" decoding="async"></span>';
    }
    return '<span class="' . $class . ' poster-fallback" aria-hidden="true"><span>' . h(initial($initial)) . '</span></span>';
}

function catalog_group_href(CatalogGroup $group): string
{
    if (!$group->isSeries()) {
        return $group->head->href;
    }
    return 'group.php?key=' . rawurlencode($group->id);
}

function catalog_file_href_from_group(CatalogRecord $record, string $groupId): string
{
    return 'title.php?id=' . rawurlencode($record->id) . '&gkey=' . rawurlencode($groupId);
}

/** @param list<CatalogRecord> $records */
function catalog_find_group_by_id(array $records, string $id): ?CatalogGroup
{
    if ($id === '') {
        return null;
    }
    foreach (catalog_collect_groups($records) as $group) {
        if ($group->id === $id) {
            return $group;
        }
    }
    return null;
}

/** @param list<CatalogRecord> $records */
function catalog_collect_groups(array $records): array
{
    $buckets = [];
    $groups = [];
    foreach ($records as $record) {
        if ($record->grouped && $record->groupKey !== '') {
            $buckets[$record->groupKey][] = $record;
            continue;
        }
        $groups[] = new CatalogGroup('item:' . $record->id, $record->kind, $record, [$record]);
    }
    foreach ($buckets as $key => $members) {
        usort($members, static function (CatalogRecord $a, CatalogRecord $b): int {
            return [$a->season ?? -1, $a->episode ?? -1, $a->id]
                <=> [$b->season ?? -1, $b->episode ?? -1, $b->id];
        });
        $groups[] = new CatalogGroup('series:' . $key, $members[0]->kind, $members[0], $members);
    }
    return $groups;
}

/** @param list<CatalogGroup> $groups */
function catalog_sort_groups(array $groups, CatalogPreferences $prefs): array
{
    $col = $prefs->sort;
    $dir = $prefs->dir === 'desc' ? -1 : 1;
    usort($groups, static function (CatalogGroup $a, CatalogGroup $b) use ($col, $dir): int {
        $sa = $a->head->sortKeys[$col] ?? $a->head->sortKeys['title'] ?? '';
        $sb = $b->head->sortKeys[$col] ?? $b->head->sortKeys['title'] ?? '';
        $cmp = strnatcasecmp($sa, $sb);
        if ($cmp === 0) {
            $cmp = strnatcasecmp($a->head->sortKeys['title'] ?? '', $b->head->sortKeys['title'] ?? '');
        }
        return $cmp * $dir;
    });
    return $groups;
}

/** @param list<CatalogRecord> $records */
function catalog_sort_records(array $records, CatalogPreferences $prefs): array
{
    $col = $prefs->sort;
    $dir = $prefs->dir === 'desc' ? -1 : 1;
    usort($records, static function (CatalogRecord $a, CatalogRecord $b) use ($col, $dir): int {
        $sa = $a->sortKeys[$col] ?? $a->sortKeys['title'] ?? '';
        $sb = $b->sortKeys[$col] ?? $b->sortKeys['title'] ?? '';
        $cmp = strnatcasecmp($sa, $sb);
        if ($cmp === 0) {
            $cmp = strnatcasecmp($a->sortKeys['title'] ?? '', $b->sortKeys['title'] ?? '');
        }
        return $cmp * $dir;
    });
    return $records;
}

/** @param list<CatalogGroup> $groups */
function catalog_genre_rails(array $groups): array
{
    $rails = [];
    $uncat = [];
    foreach ($groups as $group) {
        $genres = $group->genres();
        if ($genres === []) {
            $uncat[] = $group;
            continue;
        }
        foreach ($genres as $genre) {
            $rails[$genre][] = $group;
        }
    }
    uksort($rails, static function (string $a, string $b): int {
        return strnatcasecmp($a, $b);
    });
    if ($uncat !== []) {
        $rails['Uncategorized'] = $uncat;
    }
    return $rails;
}

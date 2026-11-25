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
    /** @param list<string> $watched */
    public function __construct(
        public string $view,
        public array $columns,
        public string $sort,
        public string $dir,
        public array $kinds = ['movie', 'show', 'documentary'],
        public array $categories = [],
        public array $watched = ['yes', 'no'],
        public string $posterSort = 'title',
        public string $posterDir = 'asc',
        public string $genreSort = 'alpha',
        public string $browse = 'poster',
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

        $allWatched = ['yes', 'no'];
        $savedWatched = $data['watched'] ?? null;
        if (!is_array($savedWatched)) {
            $watchOut = $allWatched;
        } else {
            $watchOut = [];
            foreach ($savedWatched as $flag) {
                if (in_array($flag, $allWatched, true) && !in_array($flag, $watchOut, true)) {
                    $watchOut[] = $flag;
                }
            }
            $knownWatched = $data['watched_known'] ?? null;
            if (is_array($knownWatched)) {
                foreach ($allWatched as $flag) {
                    if (!in_array($flag, $knownWatched, true) && !in_array($flag, $watchOut, true)) {
                        $watchOut[] = $flag;
                    }
                }
            }
            if ($watchOut === []) {
                $watchOut = $allWatched;
            }
        }

        $posterSort = (string) ($data['poster_sort'] ?? 'title');
        if (!in_array($posterSort, ['title', 'year', 'rating', 'added'], true)) {
            $posterSort = 'title';
        }
        $posterDir = strtolower((string) ($data['poster_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $genreSort = (string) ($data['genre_sort'] ?? 'alpha') === 'count' ? 'count' : 'alpha';
        $browse = (string) ($data['browse'] ?? '');
        if ($browse !== 'list' && $browse !== 'poster') {
            $browse = $view === 'list' ? 'list' : 'poster';
        }

        return new self($view, $visible, $sort, $dir, $kindOut, $catOut, $watchOut, $posterSort, $posterDir, $genreSort, $browse);
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

    public function watchedAttr(): string
    {
        return implode(' ', $this->watched);
    }

    public function showsWatched(string $flag): bool
    {
        return in_array($flag, $this->watched, true);
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
        public ?int $displayEpisode = null,
        public string $sourceFolder = '',
        public string $sourceFolderKey = '',
        public ?int $watchedAt = null,
        public ?float $rating = null,
        public ?int $addedAt = null,
        public string $blurb = '',
    ) {
    }

    public function listEpisodeNumber(): ?int
    {
        if ($this->displayEpisode !== null && $this->displayEpisode > 0) {
            return $this->displayEpisode;
        }
        if ($this->episode !== null && $this->episode > 0) {
            return $this->episode;
        }
        return null;
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
        if (function_exists('season_number_from_path')) {
            $folderSeason = season_number_from_path((string) ($item['path'] ?? ''));
            if ($folderSeason !== null) {
                $season = $folderSeason;
            }
        }
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
        $groupKey = '';
        $episodeTitle = (string) ($item['episode_title'] ?? '');
        $partLabel = (string) ($item['part'] ?? '');
        $filename = (string) ($item['filename'] ?? '');
        if (function_exists('parse_named_part')) {
            $namedPart = parse_named_part($filename !== '' ? $filename : $episodeTitle);
            if ($namedPart !== null) {
                $partLabel = $namedPart['part'];
                $epNamed = parse_named_part($episodeTitle);
                if ($epNamed !== null && $epNamed['stem'] !== '') {
                    $episodeTitle = $epNamed['stem'];
                }
                if ($kind !== 'show') {
                    $titleNamed = parse_named_part($seriesTitle);
                    if ($titleNamed !== null) {
                        $cleanTitle = (string) (parse_name($titleNamed['stem'])['title'] ?? '');
                        if ($cleanTitle !== '') {
                            $seriesTitle = $cleanTitle;
                        }
                    }
                    $grouped = true;
                }
            }
        }
        $groupKey = $grouped ? ($kind . ':' . lower($seriesTitle)) : $id;
        $listEpisode = $episode;
        $listTitle = trim($episodeTitle);
        if ($grouped && $kind === 'show') {
            if (function_exists('episode_display_identity')) {
                $identity = episode_display_identity($item);
                if ($listEpisode === null && isset($identity['episode']) && $identity['episode'] !== null) {
                    $listEpisode = (int) $identity['episode'];
                }
                $identityTitle = trim((string) ($identity['title'] ?? ''));
                if ($listTitle === '' || strcasecmp($listTitle, $seriesTitle) === 0) {
                    $listTitle = $identityTitle;
                }
            }
            $listTitle = catalog_strip_episode_index($listTitle, $listEpisode);
            if ($listTitle !== '' && strcasecmp($listTitle, $seriesTitle) === 0) {
                $listTitle = '';
            }
            if ($listTitle === '' && $filename !== '') {
                $fromFile = catalog_strip_episode_index(pathinfo($filename, PATHINFO_FILENAME), $listEpisode);
                if ($fromFile !== '' && strcasecmp($fromFile, $seriesTitle) !== 0) {
                    $listTitle = $fromFile;
                }
            }
        }
        if ($partLabel === '' && $episodeTitle !== '' && $filename !== '') {
            $fn = pathinfo($filename, PATHINFO_FILENAME);
            $probe = $listEpisode;
            $fnCompare = $grouped && $kind === 'show'
                ? catalog_strip_episode_index($fn, $probe)
                : $fn;
            if ($fn !== '' && lower($fnCompare) !== '' && lower($fnCompare) !== lower($episodeTitle) && lower($fnCompare) !== lower($listTitle)) {
                $partLabel = $fn;
            }
        }
        $episodeLabel = '';
        if ($grouped && $kind === 'show') {
            $episodeLabel = $listTitle;
        } elseif ($grouped) {
            $epName = trim($episodeTitle);
            if ($epName !== '' && strcasecmp($epName, $seriesTitle) === 0) {
                $epName = '';
            }
            if ($episode !== null) {
                $episodeLabel = $epName !== '' ? ($episode . ' ' . $epName) : (string) $episode;
            } elseif ($epName !== '') {
                $episodeLabel = $epName;
            } else {
                $fn = (string) ($item['filename'] ?? '');
                $episodeLabel = $fn !== '' ? pathinfo($fn, PATHINFO_FILENAME) : $title;
            }
        }
        if ($grouped && $kind === 'show') {
            $keyName = $listTitle !== '' ? $listTitle : trim($episodeTitle);
            $episodeGroupKey = sprintf('%02d|%s', $season ?? 0, lower($keyName !== '' ? $keyName : ($episode !== null ? (string) $episode : $id)));
        } else {
            $episodeGroupKey = $grouped
                ? sprintf('%02d|%s', $season ?? 0, lower($episodeTitle !== '' ? $episodeTitle : ($episode !== null ? (string) $episode : $id)))
                : $id;
        }
        $cellTitle = $grouped ? $episodeLabel : $title;
        $category = (string) ($item['category'] ?? '');
        if ($category === '' && function_exists('settings_category_for_root')) {
            $category = settings_category_for_root((string) ($item['root'] ?? ''));
        }
        $sourceFolder = '';
        $sourceFolderKey = '';
        if ($kind === 'movie') {
            $sourceFolder = catalog_movie_folder(
                (string) ($item['root'] ?? ''),
                (string) ($item['path'] ?? ''),
                (string) ($item['filename'] ?? '')
            );
            if ($sourceFolder !== '') {
                $sourceFolderKey = lower((string) ($item['root'] ?? '')) . "\n" . lower($sourceFolder);
            }
        }

        return new self(
            $id,
            'title.php?id=' . $id,
            $title,
            lower($seriesTitle . ' ' . $title . ' ' . $episodeLabel . ' ' . ($listEpisode ?? '') . ' ' . $episodeTitle . ' ' . $partLabel . ' ' . $yearLabel . ' ' . $genreLabel . ' ' . $matchLabel . ' ' . (string) ($item['filename'] ?? '')),
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
            $grouped && $kind === 'show' ? $listEpisode : null,
            $sourceFolder,
            $sourceFolderKey,
            catalog_watched_stamp($item),
            catalog_item_rating($item),
            catalog_item_added($item),
            catalog_item_blurb($item),
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
            watchedAt: catalog_watched_stamp($item),
        );
    }
}

function catalog_watched_stamp(array $item): ?int
{
    $stamp = $item['watched_at'] ?? null;
    if (!is_numeric($stamp)) {
        return null;
    }
    $stamp = (int) $stamp;
    return $stamp > 0 ? $stamp : null;
}

function catalog_item_rating(array $item): ?float
{
    if (!isset($item['vote_average']) || !is_numeric($item['vote_average'])) {
        return null;
    }
    $score = (float) $item['vote_average'];
    if ($score <= 0 || $score > 10) {
        return null;
    }
    if (array_key_exists('vote_count', $item) && $item['vote_count'] !== null && $item['vote_count'] !== '' && (int) $item['vote_count'] < 1) {
        return null;
    }
    return $score;
}

function catalog_item_added(array $item): ?int
{
    $stamp = $item['added_at'] ?? null;
    if (!is_numeric($stamp)) {
        return null;
    }
    $stamp = (int) $stamp;
    return $stamp > 0 ? $stamp : null;
}

function catalog_item_blurb(array $item, int $limit = 180): string
{
    $text = trim((string) ($item['overview'] ?? ''));
    if ($text === '') {
        return '';
    }
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($collapsed)) {
        $collapsed = preg_replace('/\s+/', ' ', $text);
    }
    $text = trim(is_string($collapsed) ? $collapsed : $text);
    if ($text === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $limit) {
        return $text;
    }
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    $space = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
    if ($space !== false && $space > (int) ($limit * 0.6)) {
        $cut = function_exists('mb_substr') ? mb_substr($cut, 0, (int) $space, 'UTF-8') : substr($cut, 0, (int) $space);
    }
    return rtrim((string) $cut, " \t.,;:—-") . '…';
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
    if ($sort === 'rating') {
        $best = catalog_group_best_rating($group);
        if ($best === null) {
            return 'Unrated';
        }
        $band = (int) floor($best);
        if ($band >= 10) {
            return '10';
        }
        if ($band < 1) {
            return 'Under 1';
        }
        return (string) $band;
    }
    if ($sort === 'added') {
        $added = catalog_group_min_added($group);
        if ($added === null) {
            return 'Unknown';
        }
        return date('Y', $added);
    }
    $title = (string) ($head->sortKeys['title'] ?? '');
    if ($title === '') {
        $title = $group->isSeries() ? $head->seriesTitle : $head->title;
    }
    return catalog_title_bucket($title);
}

/**
 * Folder name for a movie that should sit under a List-view parent.
 * The first directory under the video root wins, so RiffTrax/Cool as Ice.mp4
 * and RiffTrax/Shorts/Something.mp4 both use "RiffTrax". A file stored in the
 * root itself has no folder, except a "RiffTrax - Title" filename, which
 * still joins the RiffTrax parent, or an "MST3K - Title" / "MST3K: Title"
 * filename, which joins the MST3K parent.
 */
function catalog_movie_folder(string $root, string $path, string $filename = ''): string
{
    $rel = str_replace('\\', '/', $path);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    if ($rootNorm !== '' && str_starts_with(strtolower($rel), strtolower($rootNorm) . '/')) {
        $rel = substr($rel, strlen($rootNorm) + 1);
    }
    $rel = ltrim($rel, '/');
    $segments = [];
    foreach (explode('/', $rel) as $segment) {
        if ($segment !== '' && $segment !== '.') {
            $segments[] = $segment;
        }
    }
    if (count($segments) >= 2) {
        return $segments[0];
    }
    $base = $filename !== '' ? basename(str_replace('\\', '/', $filename)) : ($segments[0] ?? '');
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if (preg_match('/^rifftrax\s+-\s+/i', $stem) === 1) {
        return 'RiffTrax';
    }
    if (preg_match('/^mst3k\s+-\s+/i', $stem) === 1 || preg_match('/^mst3k\s*:/i', $stem) === 1) {
        return 'MST3K';
    }
    return '';
}

function catalog_group_best_rating(CatalogGroup $group): ?float
{
    $best = null;
    foreach ($group->members as $member) {
        if ($member->rating !== null && ($best === null || $member->rating > $best)) {
            $best = $member->rating;
        }
    }
    return $best;
}

function catalog_group_min_added(CatalogGroup $group): ?int
{
    $min = null;
    foreach ($group->members as $member) {
        if ($member->addedAt !== null && $member->addedAt > 0 && ($min === null || $member->addedAt < $min)) {
            $min = $member->addedAt;
        }
    }
    return $min;
}

function catalog_group_rating_key(CatalogGroup $group): string
{
    $best = catalog_group_best_rating($group);
    if ($best === null) {
        return '0';
    }
    return sprintf('1%08.3f', $best);
}

function catalog_group_added_key(CatalogGroup $group): string
{
    return sprintf('%010d', catalog_group_min_added($group) ?? 0);
}

/** @return array<string, string> */
function catalog_poster_buckets(CatalogGroup $group): array
{
    return [
        'title' => catalog_poster_bucket($group, 'title'),
        'year' => catalog_poster_bucket($group, 'year'),
        'status' => catalog_poster_bucket($group, 'status'),
        'genres' => catalog_poster_bucket($group, 'genres'),
        'rating' => catalog_poster_bucket($group, 'rating'),
        'added' => catalog_poster_bucket($group, 'added'),
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

    public function isPartMovie(): bool
    {
        if ($this->kind === 'show') {
            return false;
        }
        foreach ($this->members as $member) {
            if (catalog_is_named_part($member->partLabel)) {
                return true;
            }
        }
        return false;
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
            usort($parts, static function (CatalogRecord $a, CatalogRecord $b): int {
                return catalog_part_number($a->partLabel) <=> catalog_part_number($b->partLabel);
            });
            $first = $parts[0];
            $label = $first->episodeLabel;
            if ($label === '' && $first->listEpisodeNumber() === null) {
                $label = $first->title;
            }
            $out[] = [
                'id' => $this->id . ':ep:' . rawurlencode($key),
                'label' => $label,
                'parts' => $parts,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $ra = $a['parts'][0];
            $rb = $b['parts'][0];
            $sa = catalog_season_sort($ra->season);
            $sb = catalog_season_sort($rb->season);
            $ea = $ra->listEpisodeNumber() ?? 100000;
            $eb = $rb->listEpisodeNumber() ?? 100000;
            return [$sa, $ea, lower($a['label']), $ra->id] <=> [$sb, $eb, lower($b['label']), $rb->id];
        });
        return $out;
    }

    /** @return list<array{id: string, label: string, season: ?int, clusters: list<array{id: string, label: string, parts: list<CatalogRecord>}>}> */
    public function seasonGroups(): array
    {
        $clusters = $this->episodeClusters();
        $buckets = [];
        $order = [];
        foreach ($clusters as $cluster) {
            $season = $cluster['parts'][0]->season ?? null;
            $key = $season === null ? 'x' : (string) $season;
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['season' => $season, 'clusters' => []];
                $order[] = $key;
            }
            $buckets[$key]['clusters'][] = $cluster;
        }
        usort($order, static function (string $a, string $b) use ($buckets): int {
            $sa = $buckets[$a]['season'];
            $sb = $buckets[$b]['season'];
            return catalog_season_sort($sa) <=> catalog_season_sort($sb);
        });
        $out = [];
        foreach ($order as $key) {
            $season = $buckets[$key]['season'];
            $label = catalog_season_label($season);
            $out[] = [
                'id' => $this->id . ':s:' . $key,
                'label' => $label,
                'season' => $season,
                'clusters' => $buckets[$key]['clusters'],
            ];
        }
        return $out;
    }

    /**
     * Every cluster label is a Series or Season folder name, such as "Series 1".
     *
     * @param list<array{label?: string}> $clusters
     */
    public static function clustersAreSeasonFolders(array $clusters): bool
    {
        if ($clusters === []) {
            return false;
        }
        if (!function_exists('season_folder_number')) {
            require_once __DIR__ . '/parser.php';
        }
        foreach ($clusters as $cluster) {
            $label = trim((string) ($cluster['label'] ?? ''));
            $number = $label === '' ? null : season_folder_number($label);
            // Season 0 is the Special Features group. Only a numbered Series/Season folder replaces that row.
            if ($number === null || $number < 1) {
                return false;
            }
        }
        return true;
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

/**
 * Drop a leading episode index from a list title.
 * "11_Colonial Day", "11 - Colonial Day", "11 Colonial Day", and "03 Title"
 * become the name only. The number is returned through $episode when it was empty.
 */
function catalog_strip_episode_index(string $title, ?int &$episode): string
{
    $title = trim($title);
    if ($title === '') {
        return '';
    }
    $normalized = str_replace(['_', '.'], ' ', $title);
    $normalized = preg_replace('/\s*-\s*/', ' ', $normalized) ?? $normalized;
    $normalized = trim((string) (preg_replace('/\s+/', ' ', $normalized) ?? $normalized));
    if (!preg_match('/^(\d{1,3})(?:\s+(.*))?$/', $normalized, $m)) {
        return $title;
    }
    $n = (int) $m[1];
    if ($n < 1 || $n > 200) {
        return $title;
    }
    if ($episode !== null && $episode !== $n) {
        return $title;
    }
    if ($episode === null) {
        $episode = $n;
    }
    return trim((string) ($m[2] ?? ''));
}

function catalog_season_label(?int $season): string
{
    if ($season === null) {
        return 'Episodes';
    }
    if ($season === 0) {
        return 'Special Features';
    }
    return 'Season ' . $season;
}

/** Numbered seasons first, then specials, then episodes with no season. */
function catalog_season_sort(?int $season): int
{
    if ($season === null) {
        return 10000;
    }
    if ($season < 1) {
        return 9000 + $season;
    }
    return $season;
}

/**
 * Season line for a show detail summary.
 * Folder-style shows omit the episode count: "4 seasons with special features".
 * Shows with real episodes keep that count: "14 seasons · 138 episodes".
 *
 * @return array{label: string, files: int, episodes: ?int}|null
 */
function catalog_season_structure_summary(CatalogGroup $group): ?array
{
    if (!$group->isSeries() || $group->isPartMovie() || str_starts_with($group->id, 'parts::')) {
        return null;
    }
    $numbered = 0;
    $hasSpecials = false;
    $files = 0;
    $seasonClusters = 0;
    $folderSeasons = 0;
    foreach ($group->seasonGroups() as $seasonGroup) {
        $season = $seasonGroup['season'];
        if ($season === null) {
            continue;
        }
        $clusters = $seasonGroup['clusters'];
        $count = 0;
        foreach ($clusters as $cluster) {
            $count += count($cluster['parts']);
        }
        if ($count < 1) {
            continue;
        }
        $seasonClusters += count($clusters);
        if ((int) $season < 1) {
            $hasSpecials = true;
            $files += $count;
            continue;
        }
        $numbered++;
        $files += $count;
        if (CatalogGroup::clustersAreSeasonFolders($clusters)) {
            $folderSeasons++;
        }
    }
    if ($numbered < 1 || $files < 1) {
        return null;
    }
    $label = $numbered === 1 ? '1 season' : ((string) $numbered . ' seasons');
    if ($hasSpecials) {
        $label .= ' with special features';
    }
    // A Series/Season folder is one cluster of many files. That cluster is not an episode.
    $oneClusterPerGroup = $seasonClusters <= ($numbered + ($hasSpecials ? 1 : 0));
    $folderStyle = $folderSeasons === $numbered || ($oneClusterPerGroup && $files > $seasonClusters);
    if ($folderStyle) {
        return ['label' => $label, 'files' => $files, 'episodes' => null];
    }
    return [
        'label' => $label,
        'files' => count($group->members),
        'episodes' => count($group->episodeClusters()),
    ];
}

function catalog_episode_num_html(?int $episode, bool $reserve = true): string
{
    $text = ($episode !== null && $episode > 0) ? (string) $episode : '';
    if ($text === '' && !$reserve) {
        return '';
    }
    return '<span class="ep-num">' . h($text) . '</span>';
}

function catalog_part_number(string $label): int
{
    if (preg_match('/(\d+)/', $label, $m)) {
        return (int) $m[1];
    }
    return 999;
}

function catalog_is_named_part(string $label): bool
{
    return (bool) preg_match('/^Part \d{1,2}$/', trim($label));
}

function catalog_episode_parts_href(string $seriesGroupId, string $episodeGroupKey): string
{
    return 'group.php?key=' . rawurlencode('parts::' . $seriesGroupId . '::ep::' . $episodeGroupKey);
}

function catalog_group_href(CatalogGroup $group): string
{
    if (!$group->isSeries()) {
        return $group->head->href;
    }
    return 'group.php?key=' . rawurlencode($group->id);
}

/**
 * Poster sheet and sort fields for one title card.
 * Rating and date-added keys are group-level so they are not copied from the head row.
 *
 * @return array{
 *   title: string,
 *   year: string,
 *   rating_label: string,
 *   rating_key: string,
 *   added_key: string,
 *   blurb: string,
 *   play_id: string,
 *   watch_ids: string,
 *   seasons: int,
 *   episodes: int,
 *   parts: int,
 *   href: string
 * }
 */
function catalog_group_facts(CatalogGroup $group): array
{
    $head = $group->head;
    $title = $group->isSeries()
        ? ($head->seriesTitle !== '' ? $head->seriesTitle : $head->title)
        : $head->title;
    $best = catalog_group_best_rating($group);
    $blurb = '';
    $playId = '';
    $ids = [];
    foreach ($group->members as $member) {
        if ($blurb === '' && $member->blurb !== '') {
            $blurb = $member->blurb;
        }
        if (preg_match('/^[a-f0-9]{16}$/', $member->id) !== 1) {
            continue;
        }
        $ids[] = $member->id;
        if ($playId === '' && ($member->watchedAt === null || $member->watchedAt <= 0)) {
            $playId = $member->id;
        }
        if (count($ids) >= 500) {
            break;
        }
    }
    if ($playId === '' && $ids !== []) {
        $playId = $ids[0];
    }
    $seasons = 0;
    $episodes = 0;
    $parts = 0;
    if ($group->isPartMovie()) {
        $parts = count($group->members);
    } elseif ($group->isSeries()) {
        foreach ($group->seasonGroups() as $seasonGroup) {
            $season = $seasonGroup['season'] ?? null;
            if ($season !== null && (int) $season >= 1) {
                $seasons++;
            }
        }
        $episodes = count($group->episodeClusters());
    }
    return [
        'title' => $title,
        'year' => (string) ($head->cells['year'] ?? ''),
        'rating_label' => $best !== null ? number_format($best, 1, '.', '') . ' / 10' : '',
        'rating_key' => catalog_group_rating_key($group),
        'added_key' => catalog_group_added_key($group),
        'blurb' => $blurb,
        'play_id' => $playId,
        'watch_ids' => implode(' ', $ids),
        'seasons' => $seasons,
        'episodes' => $episodes,
        'parts' => $parts,
        'href' => $group->isSeries() ? catalog_group_href($group) : $head->href,
    ];
}

function catalog_file_href_from_group(CatalogRecord $record, string $groupId): string
{
    return 'title.php?id=' . rawurlencode($record->id) . '&gkey=' . rawurlencode($groupId);
}

/** @param list<CatalogRecord> $records */
function catalog_find_part_group(array $records, string $id): ?CatalogGroup
{
    $rest = substr($id, strlen('parts::'));
    $marker = '::ep::';
    $pos = strpos($rest, $marker);
    if ($pos === false) {
        return null;
    }
    $seriesId = substr($rest, 0, $pos);
    $episodeKey = substr($rest, $pos + strlen($marker));
    $series = null;
    foreach (catalog_collect_groups($records) as $group) {
        if ($group->id === $seriesId) {
            $series = $group;
            break;
        }
    }
    if ($series === null || $episodeKey === '') {
        return null;
    }
    $members = [];
    foreach ($series->members as $member) {
        if ($member->episodeGroupKey === $episodeKey) {
            $members[] = $member;
        }
    }
    if ($members === []) {
        return null;
    }
    usort($members, static function (CatalogRecord $a, CatalogRecord $b): int {
        return catalog_part_number($a->partLabel) <=> catalog_part_number($b->partLabel);
    });
    return new CatalogGroup($id, $series->kind, $members[0], $members);
}

function catalog_find_group_by_id(array $records, string $id): ?CatalogGroup
{
    if ($id === '') {
        return null;
    }
    if (str_starts_with($id, 'parts::')) {
        return catalog_find_part_group($records, $id);
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
            return [$a->season ?? -1, $a->episode ?? -1, catalog_part_number($a->partLabel), $a->id]
                <=> [$b->season ?? -1, $b->episode ?? -1, catalog_part_number($b->partLabel), $b->id];
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

/** @param list<CatalogGroup> $groups */
function catalog_poster_sort_key(CatalogGroup $group, string $col): string
{
    if ($col === 'rating') {
        return catalog_group_rating_key($group);
    }
    if ($col === 'added') {
        return catalog_group_added_key($group);
    }
    if ($col === 'year') {
        return (string) ($group->head->sortKeys['year'] ?? '0000');
    }
    $title = $group->isSeries()
        ? ($group->head->seriesTitle !== '' ? $group->head->seriesTitle : $group->head->title)
        : $group->head->title;
    return lower($title);
}

/** @param list<CatalogGroup> $groups */
function catalog_sort_poster_groups(array $groups, CatalogPreferences $prefs): array
{
    $col = $prefs->posterSort;
    $dir = $prefs->posterDir === 'desc' ? -1 : 1;
    $decorated = [];
    foreach ($groups as $group) {
        $decorated[] = [
            catalog_poster_sort_key($group, $col),
            (string) ($group->head->sortKeys['title'] ?? ''),
            $group,
        ];
    }
    usort($decorated, static function (array $a, array $b) use ($dir): int {
        $cmp = strnatcasecmp($a[0], $b[0]);
        if ($cmp === 0) {
            $cmp = strnatcasecmp($a[1], $b[1]);
        }
        return $cmp * $dir;
    });
    $out = [];
    foreach ($decorated as $row) {
        $out[] = $row[2];
    }
    return $out;
}

/**
 * List rows with a parent for movies that share a folder under a video root.
 * A TV show stays its own group. Poster and Genre views do not use this.
 *
 * @param list<CatalogGroup> $groups
 * @return list<array<string, mixed>>
 */
function catalog_list_entries(array $groups, CatalogPreferences $prefs): array
{
    $entries = [];
    foreach ($groups as $group) {
        if (catalog_group_folder_key($group) !== '') {
            continue;
        }
        $entries[] = [
            'type' => 'group',
            'group' => $group,
            'sortKeys' => $group->head->sortKeys,
        ];
    }
    foreach (catalog_folder_entries_from_groups($groups) as $entry) {
        $entries[] = $entry;
    }
    $col = $prefs->sort;
    $dir = $prefs->dir === 'desc' ? -1 : 1;
    usort($entries, static function (array $a, array $b) use ($col, $dir): int {
        $sa = (string) ($a['sortKeys'][$col] ?? $a['sortKeys']['title'] ?? '');
        $sb = (string) ($b['sortKeys'][$col] ?? $b['sortKeys']['title'] ?? '');
        $cmp = strnatcasecmp($sa, $sb);
        if ($cmp === 0) {
            $cmp = strnatcasecmp((string) ($a['sortKeys']['title'] ?? ''), (string) ($b['sortKeys']['title'] ?? ''));
        }
        return $cmp * $dir;
    });
    return $entries;
}

function catalog_group_list_title(CatalogGroup $group): string
{
    if ($group->isPartMovie() || $group->isSeries()) {
        $title = $group->head->seriesTitle;
        if ($title !== '') {
            return $title;
        }
    }
    return $group->head->title;
}

/**
 * Movies in one root folder share a key. A show, or a part set split across
 * folders, stays out of the folder parent.
 */
function catalog_group_folder_key(CatalogGroup $group): string
{
    if ($group->kind === 'show' || ($group->isSeries() && !$group->isPartMovie())) {
        return '';
    }
    $key = '';
    foreach ($group->members as $member) {
        if ($member->kind !== 'movie' || $member->sourceFolderKey === '') {
            return '';
        }
        if ($key === '') {
            $key = $member->sourceFolderKey;
            continue;
        }
        if ($key !== $member->sourceFolderKey) {
            return '';
        }
    }
    return $key;
}

function catalog_group_folder_label(CatalogGroup $group): string
{
    foreach ($group->members as $member) {
        if ($member->sourceFolder !== '') {
            return $member->sourceFolder;
        }
    }
    return '';
}

function catalog_folder_id(string $key): string
{
    return 'folder:' . substr(hash('sha256', $key), 0, 16);
}

/**
 * Folder parents built from the same movie groups the List view uses.
 *
 * @param list<CatalogGroup> $groups
 * @return list<array<string, mixed>>
 */
function catalog_folder_entries_from_groups(array $groups): array
{
    $buckets = [];
    foreach ($groups as $group) {
        $key = catalog_group_folder_key($group);
        if ($key === '') {
            continue;
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'label' => catalog_group_folder_label($group),
                'groups' => [],
            ];
        }
        $buckets[$key]['groups'][] = $group;
    }
    $entries = [];
    foreach ($buckets as $key => $bucket) {
        $members = $bucket['groups'];
        usort($members, static function (CatalogGroup $a, CatalogGroup $b): int {
            return strnatcasecmp(catalog_group_list_title($a), catalog_group_list_title($b));
        });
        $entries[] = catalog_folder_entry($key, (string) $bucket['label'], $members);
    }
    return $entries;
}

/**
 * @param list<CatalogRecord> $records
 * @return array<string, mixed>|null
 */
function catalog_find_folder(array $records, string $id): ?array
{
    if ($id === '' || !str_starts_with($id, 'folder:')) {
        return null;
    }
    foreach (catalog_folder_entries_from_groups(catalog_collect_groups($records)) as $entry) {
        if ((string) ($entry['id'] ?? '') === $id) {
            return $entry;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function catalog_folder_file_ids(array $entry): array
{
    $ids = [];
    $groups = $entry['groups'] ?? [];
    if (!is_array($groups)) {
        return [];
    }
    foreach ($groups as $group) {
        if (!$group instanceof CatalogGroup) {
            continue;
        }
        foreach ($group->members as $member) {
            if ($member->id !== '') {
                $ids[] = $member->id;
            }
        }
    }
    return $ids;
}

/**
 * @param list<CatalogGroup> $members
 * @return array<string, mixed>
 */
function catalog_folder_entry(string $key, string $label, array $members): array
{
    $search = [lower($label)];
    $year = '0000';
    $status = '';
    $genres = '';
    $category = '';
    foreach ($members as $group) {
        $search[] = $group->search();
        $head = $group->head;
        $memberYear = (string) ($head->sortKeys['year'] ?? '');
        if ($memberYear !== '' && $memberYear !== '0000' && ($year === '0000' || $memberYear < $year)) {
            $year = $memberYear;
        }
        $memberStatus = (string) ($head->sortKeys['status'] ?? '');
        if ($status === '' || ($memberStatus !== '' && $memberStatus < $status)) {
            $status = $memberStatus;
        }
        $memberGenres = (string) ($head->sortKeys['genres'] ?? '');
        if ($genres === '' || ($memberGenres !== '' && $memberGenres < $genres)) {
            $genres = $memberGenres;
        }
        if ($category === '' && $head->category !== '') {
            $category = $head->category;
        }
    }
    return [
        'type' => 'folder',
        'id' => catalog_folder_id($key),
        'label' => $label,
        'groups' => $members,
        'count' => count($members),
        'search' => implode(' ', $search),
        'category' => $category,
        'sortKeys' => [
            'title' => lower($label),
            'year' => $year,
            'status' => $status,
            'genres' => $genres,
        ],
    ];
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

function catalog_genre_focus_name(string $raw): string
{
    $name = trim(str_replace(["\r", "\n", "\t"], ' ', $raw));
    $name = trim((string) (preg_replace('/\s+/', ' ', $name) ?? $name));
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 80, 'UTF-8');
    }
    return substr($name, 0, 80);
}

/** @param list<CatalogGroup> $groups */
function catalog_groups_for_genre(array $groups, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return $groups;
    }
    $uncat = strcasecmp($name, 'Uncategorized') === 0;
    $out = [];
    foreach ($groups as $group) {
        $genres = $group->genres();
        if ($genres === []) {
            if ($uncat) {
                $out[] = $group;
            }
            continue;
        }
        foreach ($genres as $genre) {
            if (strcasecmp($genre, $name) === 0) {
                $out[] = $group;
                break;
            }
        }
    }
    return $out;
}

/**
 * Up to four posters, preferring titles that already have artwork.
 *
 * @param list<CatalogGroup> $groups
 * @return list<array{poster: string, initial: string}>
 */
function catalog_genre_thumbs(array $groups, int $limit = 4): array
{
    $posters = [];
    $fallbacks = [];
    foreach ($groups as $group) {
        $title = $group->isSeries()
            ? ($group->head->seriesTitle !== '' ? $group->head->seriesTitle : $group->head->title)
            : $group->head->title;
        $poster = (string) ($group->head->poster ?? '');
        $row = [
            'poster' => $poster,
            'initial' => function_exists('initial') ? initial($title) : '',
        ];
        if ($poster !== '') {
            $posters[] = $row;
            if (count($posters) >= $limit) {
                return $posters;
            }
            continue;
        }
        $fallbacks[] = $row;
    }
    foreach ($fallbacks as $row) {
        $posters[] = $row;
        if (count($posters) >= $limit) {
            break;
        }
    }
    return $posters;
}

/**
 * @param list<CatalogGroup> $groups
 * @return array{kinds: string, categories: string, search: string}
 */
function catalog_genre_card_fields(string $name, array $groups): array
{
    $kinds = [];
    $cats = [];
    $search = lower($name);
    $length = strlen($search);
    foreach ($groups as $group) {
        foreach ($group->members as $member) {
            $kind = $member->kind !== '' ? $member->kind : 'movie';
            if (!in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
            $cat = $member->category !== '' ? $member->category : 'none';
            if (!in_array($cat, $cats, true)) {
                $cats[] = $cat;
            }
        }
        if ($length >= 4000) {
            continue;
        }
        $blob = $group->search();
        if ($blob === '') {
            continue;
        }
        $room = 4000 - $length - 1;
        if ($room < 1) {
            continue;
        }
        if (strlen($blob) > $room) {
            $blob = substr($blob, 0, $room);
        }
        $search .= ' ' . $blob;
        $length += 1 + strlen($blob);
    }
    return [
        'kinds' => implode(' ', $kinds),
        'categories' => implode(' ', $cats),
        'search' => $search,
    ];
}

/** @param list<CatalogGroup> $groups */
function catalog_genre_rails(array $groups, string $sort = 'alpha'): array
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
    if ($sort === 'count') {
        uksort($rails, static function (string $a, string $b) use ($rails): int {
            $cmp = count($rails[$b]) <=> count($rails[$a]);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strnatcasecmp($a, $b);
        });
    } else {
        uksort($rails, static function (string $a, string $b): int {
            return strnatcasecmp($a, $b);
        });
    }
    if (isset($rails['Uncategorized'])) {
        $named = $rails['Uncategorized'];
        unset($rails['Uncategorized']);
        $rails['Uncategorized'] = $named;
    }
    if ($uncat !== []) {
        $rails['Uncategorized'] = $uncat;
    }
    return $rails;
}

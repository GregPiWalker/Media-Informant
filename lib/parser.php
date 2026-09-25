<?php
declare(strict_types=1);

final class ParseResult
{
    /** @param array<string, string> $facets */
    public function __construct(
        public string $title = '',
        public ?int $year = null,
        public ?int $season = null,
        public ?int $episode = null,
        public string $kind = 'movie',
        public array $facets = [],
        public string $container = '',
        public string $episodeTitle = '',
        public string $part = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'year' => $this->year,
            'season' => $this->season,
            'episode' => $this->episode,
            'kind' => $this->kind,
            'facets' => $this->facets,
            'container' => $this->container,
            'episode_title' => $this->episodeTitle,
            'part' => $this->part,
        ];
    }
}

final class PathSegment
{
    public string $raw;
    public string $collapsed;
    public bool $isFacet;
    public bool $isSeason;
    public ?int $seasonNumber;
    public ?string $role;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
        $this->collapsed = parse_collapse_spaces($raw);
        $this->isFacet = parse_is_spaced_label($raw);
        $this->seasonNumber = season_folder_number($raw);
        $this->isSeason = $this->seasonNumber !== null;
        $this->role = $this->isFacet ? parse_facet_role($this->collapsed) : null;
    }
}

final class ParseContext
{
    public string $relativePath;
    public string $domain;
    public string $filename;
    /** @var list<PathSegment> */
    public array $segments = [];
    /** @var list<PathSegment> */
    public array $facets = [];
    /** @var list<PathSegment> */
    public array $identity = [];
    /** @var list<PathSegment> */
    public array $afterSeason = [];
    public ?PathSegment $seasonFolder = null;
    public array $fileTokens;
    public string $folderKind = '';

    public static function fromPath(string $relativePath, string $domain = 'video'): self
    {
        $ctx = new self();
        $ctx->relativePath = str_replace('\\', '/', $relativePath);
        $ctx->domain = $domain;
        $parts = explode('/', $ctx->relativePath);
        $ctx->filename = (string) array_pop($parts);
        $seenSeason = false;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $seg = new PathSegment($part);
            $ctx->segments[] = $seg;
            if ($seg->isFacet) {
                $ctx->facets[] = $seg;
                continue;
            }
            if ($seg->isSeason) {
                $ctx->seasonFolder = $seg;
                $seenSeason = true;
                continue;
            }
            if (is_generic_folder($part)) {
                continue;
            }
            if ($seenSeason) {
                $ctx->afterSeason[] = $seg;
            } else {
                $ctx->identity[] = $seg;
            }
        }
        $ctx->promoteTopFacets();
        $ctx->fileTokens = parse_name(strip_extension($ctx->filename));
        return $ctx;
    }

    private function promoteTopFacets(): void
    {
        if ($this->facets === []) {
            return;
        }
        if ($this->domain === 'music') {
            foreach ($this->facets as $facet) {
                if ($facet->role !== 'music') {
                    $facet->role = 'genre';
                }
            }
            return;
        }
        $top = $this->facets[0];
        if ($top->role !== 'movie' && $top->role !== 'show' && $top->role !== 'documentary' && $top->role !== 'music') {
            $top->role = 'format';
        }
        for ($i = 1, $n = count($this->facets); $i < $n; $i++) {
            if ($this->facets[$i]->role === 'movie' || $this->facets[$i]->role === 'show' || $this->facets[$i]->role === 'documentary' || $this->facets[$i]->role === 'music') {
                continue;
            }
            $this->facets[$i]->role = 'genre';
        }
    }

    public function formatRole(): ?string
    {
        if ($this->domain === 'music') {
            return 'music';
        }
        if ($this->facets === []) {
            return null;
        }
        $top = $this->facets[0];
        if ($top->role === 'movie' || $top->role === 'show' || $top->role === 'documentary' || $top->role === 'music' || $top->role === 'format') {
            return $top->role;
        }
        return null;
    }

    public function nearestTitleFolder(): ?PathSegment
    {
        return $this->showFolder();
    }

    public function showFolder(): ?PathSegment
    {
        if ($this->identity === []) {
            return null;
        }
        if ($this->seasonFolder !== null) {
            return $this->identity[count($this->identity) - 1];
        }
        return $this->identity[0];
    }

    public function episodeFolder(): ?PathSegment
    {
        if ($this->afterSeason !== []) {
            return $this->afterSeason[count($this->afterSeason) - 1];
        }
        if ($this->seasonFolder === null && count($this->identity) >= 2) {
            return $this->identity[count($this->identity) - 1];
        }
        return null;
    }

    public function facetMap(): array
    {
        $out = [];
        $genres = [];
        foreach ($this->facets as $i => $facet) {
            $label = lower($facet->collapsed);
            if ($i === 0 || $facet->role === 'movie' || $facet->role === 'show' || $facet->role === 'music' || $facet->role === 'format') {
                if (!isset($out['format'])) {
                    $out['format'] = $label;
                    continue;
                }
            }
            $genres[] = $label;
        }
        if ($genres !== []) {
            $out['genre'] = $genres[0];
            if (count($genres) > 1) {
                $out['subgenre'] = $genres[count($genres) - 1];
            }
        }
        return $out;
    }
}

interface MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool;

    public function parse(ParseContext $ctx): ?ParseResult;
}

function parse_show_result(ParseContext $ctx): ?ParseResult
{
    $show = $ctx->showFolder();
    $file = $ctx->fileTokens;
    $showTokens = $show ? parse_name($show->raw) : ['title' => '', 'year' => null, 'season' => null, 'episode' => null];
    $title = $showTokens['title'] !== '' ? $showTokens['title'] : $file['title'];
    if ($title === '') {
        return null;
    }
    $episodeTitle = '';
    $epFolder = $ctx->episodeFolder();
    if ($epFolder !== null) {
        $epTokens = parse_name($epFolder->raw);
        $episodeTitle = $epTokens['title'] !== '' ? $epTokens['title'] : $epFolder->raw;
    }
    $baseName = strip_extension($ctx->filename);
    $namedPart = parse_named_part($baseName);
    $part = $namedPart['part'] ?? parse_part_label($baseName);
    $episodeSource = $namedPart['stem'] ?? $baseName;
    if ($episodeTitle !== '' && $part === '') {
        $cleaned = $file['title'] !== '' ? $file['title'] : strip_extension($ctx->filename);
        if ($cleaned !== '' && lower($cleaned) !== lower($episodeTitle)) {
            $part = $cleaned;
        }
    }
    $season = $file['season'] ?? ($ctx->seasonFolder->seasonNumber ?? $showTokens['season']);
    $episode = $file['episode'] ?? $showTokens['episode'];
    if ($episode === null) {
        $hint = parse_leading_episode($episodeSource);
        if ($hint !== null) {
            $episode = $hint['episode'];
            if ($episodeTitle === '' && $hint['title'] !== '') {
                $rest = $hint['title'];
                $stripped = trim((string) preg_replace('/^' . preg_quote($title, '/') . '\s*/iu', '', $rest));
                $candidate = $stripped !== '' ? $stripped : $rest;
                if (lower($candidate) !== lower($title)) {
                    $episodeTitle = $candidate;
                }
            }
        }
    }
    if ($namedPart !== null && $episodeTitle === '') {
        $stemHint = parse_leading_episode($episodeSource);
        $candidate = is_array($stemHint) ? (string) ($stemHint['title'] ?? '') : '';
        if ($candidate !== '' && lower($candidate) !== lower($title)) {
            $episodeTitle = $candidate;
        }
    }
    if ($episodeTitle !== '') {
        $epNamed = parse_named_part($episodeTitle);
        if ($epNamed !== null && $epNamed['stem'] !== '') {
            $episodeTitle = $epNamed['stem'];
            if ($part === '') {
                $part = $epNamed['part'];
            }
        }
    }
    return new ParseResult(
        $title,
        $showTokens['year'] ?? $file['year'],
        $season,
        $episode,
        'show',
        $ctx->facetMap(),
        $title,
        $episodeTitle,
        $part,
    );
}

function parse_part_label(string $name): string
{
    if (preg_match('/\b(?:part|pt)\.?\s*([0-9]{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)\b/i', $name, $m)) {
        return 'Part ' . $m[1];
    }
    if (preg_match('/\((\d{1,2})\)\s*$/', $name, $m)) {
        return 'Part ' . $m[1];
    }
    return '';
}

/**
 * "Part" plus a number, including underscores: Castrovalva_Part_1, "Part 2".
 * The stem is the name with that token removed.
 *
 * @return array{part: string, stem: string}|null
 */
function parse_named_part(string $name): ?array
{
    $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', str_replace('\\', '/', $name)) ?? $name;
    $base = basename($base);
    if (!preg_match('/(?:^|[^A-Za-z])part[^A-Za-z]*(\d{1,2})(?!\d)/i', $base, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $num = (int) $m[1][0];
    if ($num < 1) {
        return null;
    }
    $start = $m[0][1];
    $token = $m[0][0];
    if ($start > 0 && preg_match('/[^A-Za-z]/', $token[0])) {
        $start += 1;
        $token = substr($token, 1);
    }
    $stem = substr($base, 0, $start) . substr($base, $start + strlen($token));
    $stem = trim((string) preg_replace('/[\s._-]+/', ' ', str_replace(['.', '_'], ' ', $stem)));
    $stem = trim($stem, " \t.-");
    if ($stem === '') {
        return null;
    }
    return ['part' => 'Part ' . $num, 'stem' => $stem];
}

/** @return array{0: string, 1: string} title, part label */
function parse_movie_title_and_part(string $title, string $filename): array
{
    $named = parse_named_part($filename);
    if ($named === null) {
        return [$title, ''];
    }
    $fileTitle = (string) (parse_name(strip_extension(basename(str_replace('\\', '/', $filename))))['title'] ?? '');
    $stemTitle = (string) (parse_name($named['stem'])['title'] ?? '');
    if ($stemTitle !== '' && ($title === '' || strcasecmp($title, $fileTitle) === 0 || parse_named_part($title) !== null)) {
        $title = $stemTitle;
    }
    return [$title, $named['part']];
}

final class SeasonFolderStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        return $ctx->folderKind !== 'movie' && $ctx->seasonFolder !== null && $ctx->showFolder() !== null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        return parse_show_result($ctx);
    }
}

final class FacetShowStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        if ($ctx->folderKind === 'movie') {
            return false;
        }
        $role = $ctx->formatRole();
        return $ctx->nearestTitleFolder() !== null && ($role === 'show' || $role === 'format');
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        return parse_show_result($ctx);
    }
}

final class FileEpisodeStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        if ($ctx->folderKind === 'movie') {
            return false;
        }
        $file = $ctx->fileTokens;
        return $file['season'] !== null || $file['episode'] !== null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $fromShow = parse_show_result($ctx);
        if ($fromShow !== null) {
            return $fromShow;
        }
        $file = $ctx->fileTokens;
        $title = $file['title'];
        if ($title === '') {
            return null;
        }
        return new ParseResult(
            $title,
            $file['year'],
            $file['season'] ?? ($ctx->seasonFolder->seasonNumber ?? null),
            $file['episode'],
            'show',
            $ctx->facetMap(),
            $title,
            '',
            parse_part_label(strip_extension($ctx->filename)),
        );
    }
}

/**
 * Episode title and number for one file. Saved values win. Otherwise the
 * number and title are read from the filename (S01E05, 1x05, Episode 5, or a
 * leading number such as "05 Title").
 *
 * @return array{title: string, episode: ?int}
 */
function episode_display_identity(array $item): array
{
    $show = trim((string) ($item['title'] ?? ''));
    $storedTitle = trim((string) ($item['episode_title'] ?? ''));
    $storedEpisode = null;
    if (isset($item['episode']) && $item['episode'] !== '' && $item['episode'] !== null) {
        $storedEpisode = (int) $item['episode'];
    }
    $file = trim((string) ($item['filename'] ?? ''));
    if ($file === '') {
        $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
        $file = $path !== '' ? basename($path) : '';
    }
    $parsedEpisode = null;
    $candidate = '';
    if ($file !== '') {
        $base = strip_extension($file);
        $named = parse_named_part($base);
        if ($named !== null && $named['stem'] !== '') {
            $base = $named['stem'];
        }
        $base = str_replace(['.', '_'], ' ', $base);
        $base = trim((string) preg_replace('/\s+/', ' ', $base));
        if (preg_match('/\bS\d{1,2}\s*E(\d{1,3})\b/i', $base, $m)) {
            $parsedEpisode = (int) $m[1];
            $base = preg_replace('/\bS\d{1,2}\s*E\d{1,3}\b/i', ' ', $base) ?? $base;
        } elseif (preg_match('/\b\d{1,2}\s*x\s*(\d{1,3})\b/i', $base, $m)) {
            $parsedEpisode = (int) $m[1];
            $base = preg_replace('/\b\d{1,2}\s*x\s*\d{1,3}\b/i', ' ', $base) ?? $base;
        } elseif (preg_match('/\b(?:e|ep|episode)\s*(\d{1,3})\b/i', $base, $m)) {
            $parsedEpisode = (int) $m[1];
            $base = preg_replace('/\b(?:e|ep|episode)\s*\d{1,3}\b/i', ' ', $base) ?? $base;
        }
        $base = trim((string) preg_replace('/\s+/', ' ', $base));
        $hint = parse_leading_episode($base);
        if (is_array($hint)) {
            if ($parsedEpisode === null) {
                $parsedEpisode = (int) $hint['episode'];
            }
            $candidate = trim((string) ($hint['title'] ?? ''));
        }
        if ($candidate === '') {
            $candidate = $base;
        }
        if ($show !== '') {
            $candidate = trim((string) preg_replace('/^' . preg_quote($show, '/') . '\s*/iu', '', $candidate));
        }
        $candidate = trim($candidate, " \t-._");
        if ($candidate === '' || ($show !== '' && strcasecmp($candidate, $show) === 0)) {
            $candidate = '';
        }
    }
    $title = ($storedTitle !== '' && ($show === '' || strcasecmp($storedTitle, $show) !== 0))
        ? $storedTitle
        : $candidate;
    $episode = $storedEpisode ?? $parsedEpisode;
    if ($episode !== null && $episode < 1) {
        $episode = null;
    }
    return ['title' => $title, 'episode' => $episode];
}

function parse_leading_episode(string $name): ?array
{
    $clean = str_replace(['.', '_', '-'], ' ', $name);
    $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
    $clean = trim($clean);
    if ($clean === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,3})(?:\s+|[.\)\]]+)?(.*)$/', $clean, $m)) {
        return null;
    }
    $n = (int) $m[1];
    if ($n < 1 || $n > 200) {
        return null;
    }
    $rest = trim((string) ($m[2] ?? ''), " \t.)]");
    return ['episode' => $n, 'title' => $rest];
}

final class FolderKindShowStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        return $ctx->folderKind === 'show' && $ctx->showFolder() !== null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $result = parse_show_result($ctx);
        if ($result === null) {
            return null;
        }
        if ($result->episode === null) {
            $hint = parse_leading_episode(strip_extension($ctx->filename));
            if ($hint !== null) {
                $result->episode = $hint['episode'];
                $rest = $hint['title'];
                if ($rest !== '' && $result->episodeTitle === '' && lower($rest) !== lower($result->title)) {
                    $stripped = trim((string) preg_replace('/^' . preg_quote($result->title, '/') . '\s*/iu', '', $rest));
                    $result->episodeTitle = $stripped !== '' ? $stripped : $rest;
                }
            }
        }
        if ($result->season === null) {
            $result->season = 1;
        }
        return $result;
    }
}

final class FacetMovieStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        $role = $ctx->formatRole();
        if ($role === 'movie' || $role === 'documentary') {
            return true;
        }
        return $role === 'format' && $ctx->nearestTitleFolder() === null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $file = $ctx->fileTokens;
        $folder = $ctx->nearestTitleFolder();
        $folderTokens = $folder ? parse_name($folder->raw) : ['title' => '', 'year' => null];
        $title = $folderTokens['title'] !== '' ? $folderTokens['title'] : $file['title'];
        if ($title === '') {
            return null;
        }
        [$title, $part] = parse_movie_title_and_part($title, $ctx->filename);
        $kind = $ctx->formatRole() === 'documentary' ? 'documentary' : 'movie';
        return new ParseResult(
            $title,
            $folderTokens['year'] ?? $file['year'],
            null,
            null,
            $kind,
            $ctx->facetMap(),
            '',
            '',
            $part,
        );
    }
}

final class MovieFolderStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        if ($ctx->formatRole() === 'show' || $ctx->formatRole() === 'music') {
            return false;
        }
        $folder = $ctx->nearestTitleFolder();
        if ($folder === null) {
            return false;
        }
        $tokens = parse_name($folder->raw);
        return $tokens['title'] !== '' && $tokens['season'] === null && $tokens['episode'] === null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $folder = $ctx->nearestTitleFolder();
        if ($folder === null) {
            return null;
        }
        $tokens = parse_name($folder->raw);
        $file = $ctx->fileTokens;
        $title = $tokens['title'] !== '' ? $tokens['title'] : $file['title'];
        if ($title === '') {
            return null;
        }
        [$title, $part] = parse_movie_title_and_part($title, $ctx->filename);
        $kind = $ctx->formatRole() === 'documentary' ? 'documentary' : 'movie';
        return new ParseResult(
            $title,
            $tokens['year'] ?? $file['year'],
            null,
            null,
            $kind,
            $ctx->facetMap(),
            '',
            '',
            $part,
        );
    }
}

final class StandaloneFileStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        return $ctx->identity === [] && $ctx->seasonFolder === null && $ctx->formatRole() !== 'show';
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $file = $ctx->fileTokens;
        if ($file['title'] === '') {
            return null;
        }
        $kind = $ctx->formatRole() === 'music' ? 'track' : 'movie';
        $title = $file['title'];
        $part = '';
        if ($kind === 'movie') {
            [$title, $part] = parse_movie_title_and_part($title, $ctx->filename);
        }
        return new ParseResult(
            $title,
            $file['year'],
            $file['season'],
            $file['episode'],
            $kind,
            $ctx->facetMap(),
            '',
            '',
            $part,
        );
    }
}

final class MusicAlbumStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        if ($ctx->domain !== 'music' && $ctx->formatRole() !== 'music') {
            return false;
        }
        return $ctx->nearestTitleFolder() !== null;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $folder = $ctx->nearestTitleFolder();
        if ($folder === null) {
            return null;
        }
        $album = parse_name($folder->raw);
        $file = $ctx->fileTokens;
        $title = $file['title'] !== '' ? $file['title'] : $album['title'];
        if ($title === '') {
            return null;
        }
        return new ParseResult(
            $title,
            $album['year'] ?? $file['year'],
            null,
            $file['episode'],
            'track',
            $ctx->facetMap(),
            $album['title'] !== '' ? $album['title'] : $folder->raw,
        );
    }
}

final class FilenameFallbackStrategy implements MediaParseStrategy
{
    public function matches(ParseContext $ctx): bool
    {
        return true;
    }

    public function parse(ParseContext $ctx): ?ParseResult
    {
        $file = $ctx->fileTokens;
        $kind = 'movie';
        if ($ctx->formatRole() === 'show' || $file['season'] !== null || $file['episode'] !== null) {
            $kind = 'show';
        } elseif ($ctx->formatRole() === 'documentary') {
            $kind = 'documentary';
        } elseif ($ctx->formatRole() === 'music' || $ctx->domain === 'music') {
            $kind = 'track';
        }
        $title = $file['title'] !== '' ? $file['title'] : strip_extension($ctx->filename);
        return new ParseResult(
            $title,
            $file['year'],
            $file['season'] ?? ($ctx->seasonFolder->seasonNumber ?? null),
            $file['episode'],
            $kind,
            $ctx->facetMap(),
            $kind === 'show' ? $title : '',
        );
    }
}

/** @var array<string, list<MediaParseStrategy>>|null */
function &parse_strategy_registry(): array
{
    static $registry = null;
    if ($registry === null) {
        $registry = [
            'video' => [
                new SeasonFolderStrategy(),
                new FacetShowStrategy(),
                new FileEpisodeStrategy(),
                new FolderKindShowStrategy(),
                new FacetMovieStrategy(),
                new MovieFolderStrategy(),
                new StandaloneFileStrategy(),
                new FilenameFallbackStrategy(),
            ],
            'music' => [
                new MusicAlbumStrategy(),
                new StandaloneFileStrategy(),
                new FilenameFallbackStrategy(),
            ],
        ];
    }
    return $registry;
}

function parse_register_strategy(string $domain, MediaParseStrategy $strategy, bool $prepend = true): void
{
    $registry = &parse_strategy_registry();
    if (!isset($registry[$domain])) {
        $registry[$domain] = [];
    }
    if ($prepend) {
        array_unshift($registry[$domain], $strategy);
        return;
    }
    $fallback = array_pop($registry[$domain]);
    $registry[$domain][] = $strategy;
    if ($fallback instanceof MediaParseStrategy) {
        $registry[$domain][] = $fallback;
    }
}

function parse_media(string $relativePath, string $domain = 'video', string $folderKind = ''): array
{
    $ctx = ParseContext::fromPath($relativePath, $domain);
    $ctx->folderKind = $folderKind === 'show' || $folderKind === 'movie' ? $folderKind : '';
    $registry = parse_strategy_registry();
    $strategies = $registry[$domain] ?? $registry['video'];
    foreach ($strategies as $strategy) {
        if (!$strategy->matches($ctx)) {
            continue;
        }
        $result = $strategy->parse($ctx);
        if ($result instanceof ParseResult && $result->title !== '') {
            return $result->toArray();
        }
    }
    return (new FilenameFallbackStrategy())->parse($ctx)->toArray();
}

function parse_is_spaced_label(string $name): bool
{
    $name = trim($name);
    if ($name === '' || !str_contains($name, ' ')) {
        return false;
    }
    $compact = str_replace(' ', '', $name);
    $chars = preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false || count($chars) < 2) {
        return false;
    }
    $expected = implode(' ', $chars);
    $normalized = preg_replace('/\s+/', ' ', $name) ?? $name;
    return strcasecmp($normalized, $expected) === 0;
}

function parse_collapse_spaces(string $name): string
{
    return str_replace(' ', '', trim($name));
}

function parse_facet_role(string $collapsed): string
{
    $n = lower($collapsed);
    $map = [
        'movies' => 'movie',
        'movie' => 'movie',
        'films' => 'movie',
        'film' => 'movie',
        'documentaries' => 'documentary',
        'documentary' => 'documentary',
        'docs' => 'documentary',
        'television' => 'show',
        'tv' => 'show',
        'tvshows' => 'show',
        'shows' => 'show',
        'series' => 'show',
        'music' => 'music',
        'audio' => 'music',
        'albums' => 'music',
    ];
    return $map[$n] ?? 'genre';
}

function season_folder_number(string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    if (strcasecmp($name, 'specials') === 0) {
        return 0;
    }
    if (preg_match('/^(?:seasons?|series)\s*\.?\s*(\d{1,2})$/i', $name, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^s(?:eason)?\s*\.?\s*(\d{1,2})$/i', $name, $m)) {
        return (int) $m[1];
    }
    return null;
}

function strip_extension(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '') {
        return $filename;
    }
    return substr($filename, 0, -1 * (strlen($ext) + 1));
}

function is_generic_folder(string $name): bool
{
    $n = lower(trim($name));
    if (parse_is_spaced_label($name)) {
        $n = lower(parse_collapse_spaces($name));
    }
    $generic = [
        'movies', 'movie', 'films', 'film', 'video', 'videos', 'tv', 'television',
        'downloads', 'media', 'library', 'collection', 'anime', 'docs',
        'documentaries', 'documentary', 'kids', 'children', '3d', '4k', 'uhd', 'hdr', 'bluray',
        'blu-ray', 'dvd', 'new', 'incoming', 'complete', 'others', 'other',
        'sample', 'samples', 'extras', 'featurettes', 'music', 'audio', 'albums',
    ];
    return in_array($n, $generic, true);
}

function parse_name(string $name): array
{
    $season = null;
    $episode = null;
    $year = null;

    $name = preg_replace('/\[(?:[^\]]*)\]/', ' ', $name) ?? $name;
    $name = preg_replace('/\{(?:[^}]*)\}/', ' ', $name) ?? $name;

    if (preg_match('/\bS(\d{1,2})E(\d{1,3})\b/i', $name, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
        $name = str_replace($m[0], ' ', $name);
    } elseif (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $name, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
        $name = str_replace($m[0], ' ', $name);
    } elseif (preg_match('/\b(?:e|ep|episode)\.?\s*(\d{1,3})\b/i', $name, $m)) {
        $episode = (int) $m[1];
        $name = str_replace($m[0], ' ', $name);
    }

    if (preg_match('/\(((?:19|20)\d{2})\)/', $name, $m)) {
        $year = (int) $m[1];
        $name = str_replace($m[0], ' ', $name);
    } elseif (preg_match('/(?:^|[.\s_\-])((?:19|20)\d{2})(?:[.\s_\-]|$)/', $name, $m)) {
        $year = (int) $m[1];
        $name = preg_replace('/(?:^|[.\s_\-])' . $year . '(?:[.\s_\-]|$)/', ' ', $name, 1) ?? $name;
    }

    $tags = [
        '1080p', '720p', '2160p', '480p', '1080i', '720i', '4K', 'UHD',
        'BluRay', 'Blu-ray', 'BRRip', 'BDRip', 'WEB-DL', 'WEBRip', 'WEB',
        'H264', 'H265', 'x264', 'x265', 'HEVC', 'AAC', 'DTS', 'HDR', 'DV',
        'REMUX', 'HDR10', 'HDR10Plus', 'Atmos', 'TrueHD', '10bit', '8bit',
        'AC3', 'EAC3', 'FLAC', 'DDP', 'DTS-HD', 'HDRip', 'DVDRip', 'HDTV',
        'PROPER', 'REPACK', 'EXTENDED', 'UNRATED', 'MULTI', 'AMZN', 'DSNP',
        'ATVP', 'HMAX',
    ];
    $tagAlt = array_map(static function (string $tag): string {
        return preg_quote($tag, '/');
    }, $tags);
    $tagPattern = '/(?:^|[.\s_\-])(?:' . implode('|', $tagAlt) . ')(?=[.\s_\-]|$)/i';
    for ($i = 0; $i < 12; $i++) {
        $next = preg_replace($tagPattern, ' ', $name);
        if ($next === null || $next === $name) {
            break;
        }
        $name = $next;
    }

    $name = preg_replace('/-[A-Z0-9]{2,}$/', ' ', $name) ?? $name;
    $name = str_replace(['.', '_', '-'], ' ', $name);
    $name = preg_replace('/\b(?:5 1|7 1|2 0|6ch|8ch)\b/i', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $title = trim($name, " \t.+");

    return [
        'title' => $title,
        'year' => $year,
        'season' => $season,
        'episode' => $episode,
    ];
}

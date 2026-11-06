<?php
declare(strict_types=1);

/**
 * Video lookup abstraction.
 *
 * Resolution talks to VideoLookupService. TMDB, RiffTrax, and MST3K each
 * implement that interface and are registered in lookup_services(). A later
 * source is a new class plus one lookup_register() call. TV episode lists
 * stay on the TMDB season APIs; those are not a second catalog.
 *
 * RiffTrax detail is the JSON document at /api/dante/product_display/{nid}.
 * A title is turned into a few slug variants (apostrophes removed, then
 * apostrophes as hyphens) and each official page is tried until one matches.
 * HTML is used only to resolve that slug to a node id. If every slug misses,
 * lookup falls through to the catalog search. The catalog page is a client
 * rendered shell, so product links are read when the HTML has them and the
 * same /api/dante/product_search index the page searches is used otherwise.
 * search() records that path as slug or catalog. The scanner asks Grok to
 * choose among catalog hits only when Grok assistance is enabled.
 * The catalog index is scored the way the site scores it: a Fuse-style
 * partial match on title, description, and collections, threshold 0.15.
 * The equal-length check stays on direct slug pages.
 * The site average_rating is a 0–100 score; it is stored on the 0–10 catalog
 * scale. The payload has no vote count, so vote_count is left unset.
 *
 * MST3K episodes live on the same site. A direct guess tries
 * /mst3k-{slug} for each RiffTrax slug variant before the plain /{slug}.
 * The first page with a node id and a close title wins. If every direct URL
 * misses, lookup uses /catalog/mst3k?search= and then the same product index,
 * limited to MST3K rows. Detail JSON is read with the RiffTrax extractor and
 * stored with source MST3K. A file is MST3K when its first folder under the
 * video root is "MST3K" or "Mystery Science Theater 3000", or its filename
 * starts with "MST3K - " or "MST3K:".
 */

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/tmdb.php';

/**
 * Fields shared by every catalog source.
 */
final class VideoMetadata
{
    public string $source;
    public string $title;
    /** @var list<string> */
    public array $genres;
    public ?int $releaseYear;
    public ?int $originalYear;
    public ?float $averageRating;
    public ?int $voteCount;
    public ?string $posterUrl;
    public string $description;
    public ?string $detailUrl;
    public ?int $runtime;
    public string $mediaType;
    public string $lookupId;
    /** RiffTrax product type: "riff" (official) or "iriff". Empty for other sources. */
    public string $productKind;

    /**
     * @param list<string> $genres
     */
    public function __construct(
        string $source,
        string $title,
        array $genres = [],
        ?int $releaseYear = null,
        ?int $originalYear = null,
        ?float $averageRating = null,
        ?int $voteCount = null,
        ?string $posterUrl = null,
        string $description = '',
        ?string $detailUrl = null,
        ?int $runtime = null,
        string $mediaType = 'movie',
        string $lookupId = '',
        string $productKind = ''
    ) {
        $this->source = $source;
        $this->title = $title;
        $this->genres = $genres;
        $this->releaseYear = $releaseYear;
        $this->originalYear = $originalYear;
        $this->averageRating = $averageRating;
        $this->voteCount = $voteCount;
        $this->posterUrl = $posterUrl;
        $this->description = $description;
        $this->detailUrl = $detailUrl;
        $this->runtime = $runtime;
        $this->mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $this->lookupId = $lookupId;
        $this->productKind = $productKind;
    }

    /**
     * Shape stored on an item and shown in Change Match.
     *
     * @return array<string, mixed>
     */
    public function toCandidate(): array
    {
        $row = [
            'source' => $this->source,
            'id' => ctype_digit($this->lookupId) ? (int) $this->lookupId : $this->lookupId,
            'lookup_id' => $this->lookupId,
            'tmdb_id' => $this->source === 'TMDB' && ctype_digit($this->lookupId) ? (int) $this->lookupId : 0,
            'title' => $this->title,
            'year' => $this->releaseYear,
            'media_type' => $this->mediaType,
            'overview' => $this->description,
            'poster_path' => $this->posterUrl,
            'genres' => $this->genres,
            'detail_url' => $this->detailUrl,
            'product_kind' => $this->productKind,
        ];
        if ($this->averageRating !== null) {
            $row['vote_average'] = $this->averageRating;
        }
        if ($this->voteCount !== null) {
            $row['vote_count'] = $this->voteCount;
        }
        if ($this->runtime !== null) {
            $row['runtime'] = $this->runtime;
        }
        if ($this->originalYear !== null) {
            $row['original_year'] = $this->originalYear;
        }
        return $row;
    }

    /**
     * Title-cache shape used by cache_apply_tmdb_match.
     *
     * @return array<string, mixed>
     */
    public function toTmdbMeta(): array
    {
        $meta = [
            'tmdb_id' => ctype_digit($this->lookupId) ? (int) $this->lookupId : 0,
            'title' => $this->title,
            'year' => $this->releaseYear,
            'overview' => $this->description,
            'poster_path' => $this->posterUrl,
            'genres' => $this->genres,
            'media_type' => $this->mediaType,
        ];
        if ($this->averageRating !== null) {
            $meta['vote_average'] = $this->averageRating;
            $meta['vote_count'] = $this->voteCount ?? 0;
        }
        if ($this->runtime !== null) {
            $meta['runtime'] = $this->runtime;
        }
        return $meta;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function fromTmdbDetails(array $details): ?self
    {
        $id = (int) ($details['tmdb_id'] ?? $details['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $year = isset($details['year']) && is_numeric($details['year']) ? (int) $details['year'] : null;
        $rating = isset($details['vote_average']) && is_numeric($details['vote_average'])
            ? (float) $details['vote_average']
            : null;
        $votes = $rating === null ? null : max(0, (int) ($details['vote_count'] ?? 0));
        $runtime = function_exists('tmdb_minutes_value') ? tmdb_minutes_value($details['runtime'] ?? null) : null;
        $poster = isset($details['poster_path']) && is_string($details['poster_path']) && $details['poster_path'] !== ''
            ? $details['poster_path']
            : null;
        return new self(
            'TMDB',
            (string) ($details['title'] ?? ''),
            function_exists('cache_string_list') ? cache_string_list($details['genres'] ?? []) : [],
            $year,
            null,
            $rating,
            $votes,
            $poster,
            trim((string) ($details['overview'] ?? '')),
            null,
            $runtime,
            (string) ($details['media_type'] ?? 'movie'),
            (string) $id
        );
    }
}

interface VideoLookupService
{
    public function source(): string;

    /**
     * One confident match, or null when the title is missing or ambiguous.
     * $context may include filename, path, hits, and official_only.
     */
    public function lookup(string $title, ?int $year = null, array $context = []): ?VideoMetadata;

    /**
     * Partial matches for a person or for Grok to choose among.
     *
     * @return list<VideoMetadata>
     */
    public function search(string $query, ?int $year = null, int $limit = 6, array $context = []): array;

    /**
     * Load one record by the source's own id (TMDB id or RiffTrax nid).
     */
    public function fetch(string $id, array $context = []): ?VideoMetadata;
}

/**
 * Register a source. Call this before the first lookup_service() if you add one.
 */
function lookup_register(VideoLookupService $service): void
{
    if (!isset($GLOBALS['lookup_services']) || !is_array($GLOBALS['lookup_services'])) {
        $GLOBALS['lookup_services'] = [];
    }
    $GLOBALS['lookup_services'][$service->source()] = $service;
}

/**
 * Built-in sources, in the order Configure Scan and Match title list them.
 * Add another by implementing VideoLookupService and calling lookup_register()
 * here.
 *
 * @return array<string, VideoLookupService>
 */
function lookup_services(): array
{
    if (!isset($GLOBALS['lookup_services']) || !is_array($GLOBALS['lookup_services']) || $GLOBALS['lookup_services'] === []) {
        $GLOBALS['lookup_services'] = [];
        lookup_register(new TmdbVideoLookupService());
        lookup_register(new RiffTraxVideoLookupService());
        lookup_register(new Mst3kVideoLookupService());
    }
    return $GLOBALS['lookup_services'];
}

function lookup_service(string $source): ?VideoLookupService
{
    $wanted = strtolower(trim($source));
    foreach (lookup_services() as $name => $service) {
        if (strtolower($name) === $wanted) {
            return $service;
        }
    }
    return null;
}

/**
 * @param list<VideoMetadata> $hits
 * @return list<array<string, mixed>>
 */
function lookup_candidate_rows(array $hits): array
{
    $out = [];
    foreach ($hits as $hit) {
        if ($hit instanceof VideoMetadata) {
            $out[] = $hit->toCandidate();
        }
    }
    return $out;
}

/**
 * Write a confident match onto a library item.
 * A RiffTrax auto match uses match_source "rifftrax". An MST3K auto match
 * uses "mst3k". Grok and manual keep those sources and still record
 * lookup_source so the page can name the site. A site year replaces a
 * previous year the same way a TMDB match does.
 */
function lookup_apply_metadata(array $item, VideoMetadata $meta, string $matchSource = 'direct'): array
{
    if ($meta->source === 'TMDB') {
        $source = $matchSource === 'rifftrax' ? 'direct' : $matchSource;
        return cache_apply_tmdb_match($item, $meta->toTmdbMeta(), $source);
    }

    $filenameYear = isset($item['year']) && is_numeric($item['year']) ? (int) $item['year'] : null;
    $item['tmdb_id'] = null;
    $item['tmdb_candidates'] = [];
    $item['status'] = 'matched';
    $item['match_source'] = function_exists('library_normalize_match_source')
        ? library_normalize_match_source($matchSource)
        : $matchSource;
    if ($item['match_source'] === 'none' || $item['match_source'] === 'direct') {
        $item['match_source'] = strcasecmp($meta->source, 'MST3K') === 0 ? 'mst3k' : 'rifftrax';
    }
    $item['lookup_source'] = $meta->source;
    $item['lookup_id'] = $meta->lookupId;
    $item['detail_url'] = $meta->detailUrl;
    $item['lookup_candidates'] = [$meta->toCandidate()];
    if ($meta->posterUrl !== null && $meta->posterUrl !== '') {
        $item['poster_path'] = $meta->posterUrl;
        unset($item['poster_cleared']);
    }
    if (function_exists('library_set_auto_title')) {
        $item = library_set_auto_title($item, $meta->title !== '' ? $meta->title : (string) ($item['title'] ?? 'Untitled'));
    } elseif ($meta->title !== '') {
        $item['display_title'] = $meta->title;
    }
    if ($meta->releaseYear !== null) {
        $item['year'] = $meta->releaseYear;
        unset($item['year_source']);
    }
    $original = $meta->originalYear;
    if ($original === null && $filenameYear !== null && $filenameYear !== $meta->releaseYear) {
        $original = $filenameYear;
    }
    if ($original !== null && $original !== $meta->releaseYear) {
        $item['original_year'] = $original;
    } else {
        unset($item['original_year']);
    }
    $item['genres'] = function_exists('cache_string_list') ? cache_string_list($meta->genres) : $meta->genres;
    $item['overview'] = trim($meta->description);
    if ($meta->averageRating !== null) {
        $item['vote_average'] = $meta->averageRating;
        if ($meta->voteCount === null) {
            unset($item['vote_count']);
        } else {
            $item['vote_count'] = $meta->voteCount;
        }
    }
    if ($meta->runtime !== null && $meta->runtime > 0) {
        $item['runtime'] = $meta->runtime;
    }
    $item['media_type'] = $meta->mediaType;
    if (!function_exists('library_has_user_kind') || !library_has_user_kind($item)) {
        $item['kind'] = 'movie';
    }
    if (function_exists('library_item_kind') && library_item_kind($item) !== 'show'
        && (!function_exists('library_has_user_grouped') || !library_has_user_grouped($item))) {
        $item['grouped'] = false;
    }
    return $item;
}

/**
 * Copy a RiffTrax match already stored on another file. No network.
 *
 * @param array<string, mixed> $source
 */
function lookup_copy_rifftrax_item(array $item, array $source): array
{
    $item['tmdb_id'] = null;
    $item['status'] = 'matched';
    $src = function_exists('library_normalize_match_source')
        ? library_normalize_match_source((string) ($source['match_source'] ?? ''))
        : (string) ($source['match_source'] ?? '');
    $item['match_source'] = $src === 'grok' ? 'grok' : 'rifftrax';
    $item['lookup_source'] = 'RiffTrax';
    $item['lookup_id'] = (string) ($source['lookup_id'] ?? '');
    $item['detail_url'] = (string) ($source['detail_url'] ?? '');
    $item['poster_path'] = $source['poster_path'] ?? null;
    $item['genres'] = function_exists('cache_string_list') ? cache_string_list($source['genres'] ?? []) : ($source['genres'] ?? []);
    $item['overview'] = trim((string) ($source['overview'] ?? ''));
    $item['media_type'] = 'movie';
    $title = (string) ($source['display_title'] ?? $source['title'] ?? $item['title'] ?? '');
    if (function_exists('library_set_auto_title') && $title !== '') {
        $item = library_set_auto_title($item, $title);
    }
    if (!empty($source['year'])) {
        $item['year'] = (int) $source['year'];
        unset($item['year_source']);
    }
    if (isset($source['original_year']) && is_numeric($source['original_year'])) {
        $item['original_year'] = (int) $source['original_year'];
    }
    if (isset($source['vote_average']) && is_numeric($source['vote_average'])) {
        $item['vote_average'] = (float) $source['vote_average'];
        if (isset($source['vote_count']) && is_numeric($source['vote_count'])) {
            $item['vote_count'] = (int) $source['vote_count'];
        } else {
            unset($item['vote_count']);
        }
    }
    if (isset($source['runtime']) && is_numeric($source['runtime']) && (int) $source['runtime'] > 0) {
        $item['runtime'] = (int) $source['runtime'];
    }
    return $item;
}

/**
 * Copy a RiffTrax or MST3K match already stored on another file. No network.
 * The stored lookup_source decides which catalog the copy belongs to.
 *
 * @param array<string, mixed> $source
 */
function lookup_copy_site_item(array $item, array $source): array
{
    $lookup = (string) ($source['lookup_source'] ?? '');
    $item = lookup_copy_rifftrax_item($item, $source);
    if (strcasecmp($lookup, 'MST3K') === 0) {
        $item['lookup_source'] = 'MST3K';
        if (($item['match_source'] ?? '') === 'rifftrax') {
            $item['match_source'] = 'mst3k';
        }
    }
    return $item;
}

/**
 * Search RiffTrax and classify the outcome.
 *
 * via is slug, catalog, or none. It says where search() found the hits.
 * This function does not call Grok.
 *
 * @param array<string, mixed> $context
 * @return array{status:string, metadata:?VideoMetadata, candidates:list<array<string, mixed>>, via:string}
 */
function lookup_rifftrax_resolve(string $title, array $context = []): array
{
    $empty = ['status' => 'miss', 'metadata' => null, 'candidates' => [], 'via' => 'none'];
    $service = lookup_service('RiffTrax');
    if (!$service instanceof RiffTraxVideoLookupService) {
        return $empty;
    }
    $hits = $service->search($title, null, 6, $context);
    $via = $service->lastVia();
    $context['hits'] = $hits;
    $match = $service->lookup($title, null, $context);
    if ($match !== null) {
        return [
            'status' => 'matched',
            'metadata' => $match,
            'candidates' => lookup_candidate_rows($hits),
            'via' => $via,
        ];
    }
    if ($hits === []) {
        $empty['via'] = $via;
        return $empty;
    }
    return [
        'status' => 'ambiguous',
        'metadata' => null,
        'candidates' => lookup_candidate_rows($hits),
        'via' => $via,
    ];
}

/**
 * Search MST3K and classify the outcome. Same statuses as the RiffTrax helper.
 * This function does not call Grok.
 *
 * @param array<string, mixed> $context
 * @return array{status:string, metadata:?VideoMetadata, candidates:list<array<string, mixed>>, via:string}
 */
function lookup_mst3k_resolve(string $title, array $context = []): array
{
    $empty = ['status' => 'miss', 'metadata' => null, 'candidates' => [], 'via' => 'none'];
    $service = lookup_service('MST3K');
    if (!$service instanceof Mst3kVideoLookupService) {
        return $empty;
    }
    $hits = $service->search($title, null, 6, $context);
    $via = $service->lastVia();
    $context['hits'] = $hits;
    $match = $service->lookup($title, null, $context);
    if ($match !== null) {
        return [
            'status' => 'matched',
            'metadata' => $match,
            'candidates' => lookup_candidate_rows($hits),
            'via' => $via,
        ];
    }
    if ($hits === []) {
        $empty['via'] = $via;
        return $empty;
    }
    return [
        'status' => 'ambiguous',
        'metadata' => null,
        'candidates' => lookup_candidate_rows($hits),
        'via' => $via,
    ];
}

final class TmdbVideoLookupService implements VideoLookupService
{
    public function source(): string
    {
        return 'TMDB';
    }

    public function lookup(string $title, ?int $year = null, array $context = []): ?VideoMetadata
    {
        if (!function_exists('tmdb_lookup')) {
            return null;
        }
        $details = tmdb_lookup(
            $title,
            $year,
            (string) ($context['filename'] ?? ''),
            is_array($context['meta'] ?? null) ? $context['meta'] : []
        );
        return is_array($details) ? VideoMetadata::fromTmdbDetails($details) : null;
    }

    public function search(string $query, ?int $year = null, int $limit = 6, array $context = []): array
    {
        if (!function_exists('tmdb_search_candidates')) {
            return [];
        }
        $rows = tmdb_search_candidates(
            $query,
            $year,
            $limit,
            (string) ($context['filename'] ?? ''),
            is_array($context['meta'] ?? null) ? $context['meta'] : []
        );
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = VideoMetadata::fromTmdbDetails([
                'tmdb_id' => (int) ($row['id'] ?? $row['tmdb_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'year' => $row['year'] ?? null,
                'media_type' => (string) ($row['media_type'] ?? 'movie'),
            ]);
            if ($meta !== null) {
                $out[] = $meta;
            }
        }
        return $out;
    }

    public function fetch(string $id, array $context = []): ?VideoMetadata
    {
        $tmdbId = (int) $id;
        if ($tmdbId < 1 || !function_exists('tmdb_fetch_details')) {
            return null;
        }
        $media = (string) ($context['media_type'] ?? 'movie');
        if ($media !== 'tv') {
            $media = 'movie';
        }
        $details = tmdb_fetch_details($tmdbId, $media);
        return is_array($details) ? VideoMetadata::fromTmdbDetails($details) : null;
    }

    /**
     * Scan shortlist used for movies and TV show identity.
     * Episode season JSON is still loaded by tmdb_fetch_season.
     *
     * @param array<string, mixed> $meta
     * @return array{results: list<array>, page: int, total_pages: int, query?: string, source?: string}
     */
    public function firstResults(string $title, ?int $year, string $filename = '', array $meta = []): array
    {
        if (!function_exists('tmdb_search_first_results')) {
            return ['results' => [], 'page' => 1, 'total_pages' => 1];
        }
        return tmdb_search_first_results($title, $year, $filename, $meta);
    }
}

final class RiffTraxVideoLookupService implements VideoLookupService
{
    /** slug, catalog, or none. Set by the latest search(). */
    private string $lastVia = 'none';

    public function source(): string
    {
        return 'RiffTrax';
    }

    public function lastVia(): string
    {
        return $this->lastVia;
    }

    public function lookup(string $title, ?int $year = null, array $context = []): ?VideoMetadata
    {
        unset($year);
        $hits = isset($context['hits']) && is_array($context['hits'])
            ? $context['hits']
            : $this->search($title, null, 6, $context);
        $norm = lookup_norm($title);
        $exactOfficial = [];
        $exact = [];
        $all = [];
        foreach ($hits as $hit) {
            if (!$hit instanceof VideoMetadata) {
                continue;
            }
            $all[] = $hit;
            if ($norm === '' || lookup_norm($hit->title) !== $norm) {
                continue;
            }
            $exact[] = $hit;
            if ($hit->productKind === 'riff' || $hit->productKind === '') {
                $exactOfficial[] = $hit;
            }
        }
        if (count($exactOfficial) === 1) {
            return $exactOfficial[0];
        }
        if (count($exact) === 1) {
            return $exact[0];
        }
        // One partial catalog title is not an automatic match.
        if (count($all) === 1 && rifftrax_title_close($title, $all[0]->title)) {
            return $all[0];
        }
        return null;
    }

    public function search(string $query, ?int $year = null, int $limit = 6, array $context = []): array
    {
        unset($year);
        $this->lastVia = 'none';
        $query = trim($query);
        if ($query === '' || $limit < 1) {
            return [];
        }
        if (!function_exists('curl_init')) {
            rifftrax_log('RiffTrax lookup skipped: curl is not available.');
            return [];
        }
        $officialOnly = !empty($context['official_only']);
        $found = [];
        $seen = [];
        $slugs = rifftrax_slug_variants($query);
        $official = $this->firstSlugHit($slugs, false, $query);
        if ($official !== null) {
            $found[] = $official;
            $seen[$official->lookupId] = true;
        }
        // A scan skips iRiff once an official page hits. A miss still checks it.
        if (!$officialOnly || $official === null) {
            $fan = $this->firstSlugHit($slugs, true, $query);
            if ($fan !== null && !isset($seen[$fan->lookupId])) {
                $found[] = $fan;
                $seen[$fan->lookupId] = true;
            }
        }
        if ($found === []) {
            $list = $slugs === [] ? '(none)' : implode(', ', $slugs);
            rifftrax_log('RiffTrax slugs tried for "' . $query . '": ' . $list . '. No matching detail page; falling back to catalog search.');
            foreach ($this->productsFromCatalog($query, $slugs, $limit) as $hit) {
                if (isset($seen[$hit->lookupId])) {
                    continue;
                }
                $seen[$hit->lookupId] = true;
                $found[] = $hit;
            }
            if ($found !== []) {
                $this->lastVia = 'catalog';
            }
        } else {
            $this->lastVia = 'slug';
        }
        usort($found, static function (VideoMetadata $a, VideoMetadata $b): int {
            $ao = $a->productKind === 'iriff' ? 1 : 0;
            $bo = $b->productKind === 'iriff' ? 1 : 0;
            return $ao <=> $bo;
        });
        return array_slice($found, 0, $limit);
    }

    public function fetch(string $id, array $context = []): ?VideoMetadata
    {
        unset($context);
        if (!preg_match('/^\d+$/', $id)) {
            return null;
        }
        $data = rifftrax_product($id);
        return is_array($data) ? rifftrax_metadata_from_product($data) : null;
    }

    private function productFromSlug(string $slug, bool $iriff): ?VideoMetadata
    {
        $url = rifftrax_base() . ($iriff ? '/iriff/' : '/') . rawurlencode($slug);
        $page = rifftrax_http($url, false);
        if ($page['code'] !== 200 || $page['body'] === '') {
            return null;
        }
        if (!preg_match('/node-type-(riff|iriff)/i', $page['body'], $kindMatch)) {
            rifftrax_log('RiffTrax page has no product node: ' . $url);
            return null;
        }
        $nid = rifftrax_node_id($page['body']);
        if ($nid === null) {
            rifftrax_log('RiffTrax page has no node id: ' . $url);
            return null;
        }
        $data = rifftrax_product($nid);
        if (!is_array($data)) {
            return null;
        }
        $meta = rifftrax_metadata_from_product($data);
        if ($meta === null) {
            return null;
        }
        $pageKind = strtolower($kindMatch[1]);
        if ($meta->productKind === '' || ($pageKind === 'iriff' && $meta->productKind !== 'iriff')) {
            $meta->productKind = $pageKind;
        }
        return $meta;
    }

    /**
     * First slug whose detail page is a product and whose title is close.
     *
     * @param list<string> $slugs
     */
    private function firstSlugHit(array $slugs, bool $iriff, string $query): ?VideoMetadata
    {
        foreach ($slugs as $slug) {
            $meta = $this->productFromSlug($slug, $iriff);
            if ($meta === null) {
                continue;
            }
            if (!rifftrax_title_close($query, $meta->title)) {
                rifftrax_log('RiffTrax slug ' . $slug . ' is "' . $meta->title . '", not "' . $query . '".');
                continue;
            }
            return $meta;
        }
        return null;
    }

    /**
     * Catalog search after every direct slug missed.
     *
     * The catalog URL is fetched because that is the public search. Its body
     * is a React shell with no result links, so a hit list in the HTML is
     * used when present and the cached product_search index is the fallback.
     * That index is what the catalog page filters in the browser.
     *
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromCatalog(string $query, array $slugs, int $limit): array
    {
        $url = rifftrax_base() . '/catalog?search=' . rawurlencode(rifftrax_slug_source($query));
        $page = rifftrax_http($url, false);
        $fromPage = [];
        if ($page['code'] === 200 && $page['body'] !== '') {
            $fromPage = $this->productsFromCatalogHtml($page['body'], $query, $slugs, $limit);
        } else {
            rifftrax_log('RiffTrax catalog search failed for "' . $query . '".');
        }
        if ($fromPage !== []) {
            return $fromPage;
        }
        if ($page['code'] === 200) {
            rifftrax_log('RiffTrax catalog page has no matching product links for "' . $query . '"; searching the catalog index.');
        }
        return $this->productsFromIndex($query, $slugs, $limit);
    }

    /**
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromCatalogHtml(string $html, string $query, array $slugs, int $limit): array
    {
        $out = [];
        $seen = [];
        foreach (rifftrax_catalog_choices($html, $query, $slugs) as $choice) {
            $meta = $this->productFromSlug($choice['slug'], $choice['iriff']);
            if ($meta === null || isset($seen[$meta->lookupId])) {
                continue;
            }
            $seen[$meta->lookupId] = true;
            $out[] = $meta;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * High-confidence rows from the catalog index. Official riffs sort first.
     *
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromIndex(string $query, array $slugs, int $limit): array
    {
        if (rifftrax_match_key($query) === '' && $slugs === []) {
            return [];
        }
        $index = rifftrax_index();
        $out = [];
        $seen = [];
        foreach (rifftrax_index_picks($index, $query, $slugs) as $pick) {
            if (isset($seen[$pick['nid']])) {
                continue;
            }
            $seen[$pick['nid']] = true;
            $data = rifftrax_product($pick['nid']);
            $meta = is_array($data) ? rifftrax_metadata_from_product($data) : null;
            if ($meta === null) {
                continue;
            }
            $out[] = $meta;
            if (count($out) >= $limit) {
                break;
            }
        }
        if ($out === [] && $index !== []) {
            rifftrax_log('RiffTrax catalog index has no match for "' . $query . '".');
        } elseif ($out !== []) {
            rifftrax_log('RiffTrax catalog index returned ' . count($out) . ' title(s) for "' . $query . '".');
        }
        return $out;
    }
}

/**
 * MST3K episodes on www.rifftrax.com.
 *
 * Direct URLs use the same slug list as RiffTrax. Every /mst3k-{slug} is
 * tried before any plain /{slug}, and the first valid detail page stops the
 * list. A miss falls through to /catalog/mst3k?search=, which filters like
 * the main catalog. Product JSON is the RiffTrax detail document with
 * source set to MST3K.
 */
final class Mst3kVideoLookupService implements VideoLookupService
{
    /** slug, catalog, or none. Set by the latest search(). */
    private string $lastVia = 'none';

    public function source(): string
    {
        return 'MST3K';
    }

    public function lastVia(): string
    {
        return $this->lastVia;
    }

    public function lookup(string $title, ?int $year = null, array $context = []): ?VideoMetadata
    {
        unset($year);
        $hits = isset($context['hits']) && is_array($context['hits'])
            ? $context['hits']
            : $this->search($title, null, 6, $context);
        $norm = mst3k_match_key($title);
        $exact = [];
        $all = [];
        foreach ($hits as $hit) {
            if (!$hit instanceof VideoMetadata) {
                continue;
            }
            $all[] = $hit;
            if ($norm === '' || mst3k_match_key($hit->title) !== $norm) {
                continue;
            }
            $exact[] = $hit;
        }
        if (count($exact) === 1) {
            return $exact[0];
        }
        // One partial catalog title is not an automatic match.
        if (count($all) === 1 && mst3k_title_close($title, $all[0]->title)) {
            return $all[0];
        }
        return null;
    }

    public function search(string $query, ?int $year = null, int $limit = 6, array $context = []): array
    {
        unset($year, $context);
        $this->lastVia = 'none';
        $query = trim($query);
        if ($query === '' || $limit < 1) {
            return [];
        }
        if (!function_exists('curl_init')) {
            rifftrax_log('MST3K lookup skipped: curl is not available.');
            return [];
        }
        $slugs = rifftrax_slug_variants($query);
        foreach (mst3k_direct_urls($query) as $url) {
            $meta = mst3k_detail_from_url($url, $query);
            if ($meta === null) {
                continue;
            }
            $this->lastVia = 'slug';
            return [$meta];
        }
        $catalogUrl = mst3k_catalog_url($query);
        $tried = mst3k_direct_urls($query);
        $list = $tried === [] ? '(none)' : implode(', ', $tried);
        rifftrax_log('MST3K slugs tried for "' . $query . '": ' . $list . '. No matching detail page; falling back to ' . $catalogUrl . '.');
        $found = [];
        $seen = [];
        foreach ($this->productsFromCatalog($query, $slugs, $limit, $catalogUrl) as $hit) {
            if (isset($seen[$hit->lookupId])) {
                continue;
            }
            $seen[$hit->lookupId] = true;
            $found[] = $hit;
        }
        if ($found === []) {
            rifftrax_log('MST3K has no match for "' . $query . '" after direct URLs and ' . $catalogUrl . '.');
            return [];
        }
        $this->lastVia = 'catalog';
        return array_slice($found, 0, $limit);
    }

    public function fetch(string $id, array $context = []): ?VideoMetadata
    {
        unset($context);
        if (!preg_match('/^\d+$/', $id)) {
            return null;
        }
        $data = rifftrax_product($id);
        return is_array($data) ? mst3k_metadata_from_product($data) : null;
    }

    /**
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromCatalog(string $query, array $slugs, int $limit, string $catalogUrl): array
    {
        $page = rifftrax_http($catalogUrl, false);
        $fromPage = [];
        if ($page['code'] === 200 && $page['body'] !== '') {
            $fromPage = $this->productsFromCatalogHtml($page['body'], $query, $slugs, $limit);
        } else {
            rifftrax_log('MST3K catalog search failed for "' . $query . '" at ' . $catalogUrl . '.');
        }
        if ($fromPage !== []) {
            return $fromPage;
        }
        if ($page['code'] === 200) {
            rifftrax_log('MST3K catalog page has no matching product links for "' . $query . '"; searching the MST3K catalog index.');
        }
        return $this->productsFromIndex($query, $slugs, $limit);
    }

    /**
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromCatalogHtml(string $html, string $query, array $slugs, int $limit): array
    {
        $out = [];
        $seen = [];
        foreach (mst3k_catalog_picks($html, $query, $slugs) as $choice) {
            $url = rifftrax_base() . '/' . rawurlencode($choice['slug']);
            $meta = mst3k_detail_from_url($url, $query, false);
            if ($meta === null || isset($seen[$meta->lookupId])) {
                continue;
            }
            $seen[$meta->lookupId] = true;
            $out[] = $meta;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $slugs
     * @return list<VideoMetadata>
     */
    private function productsFromIndex(string $query, array $slugs, int $limit): array
    {
        if (rifftrax_match_key($query) === '' && $slugs === []) {
            return [];
        }
        $index = mst3k_index_rows(rifftrax_index());
        $out = [];
        $seen = [];
        foreach (rifftrax_index_picks($index, $query, $slugs) as $pick) {
            if (isset($seen[$pick['nid']])) {
                continue;
            }
            $seen[$pick['nid']] = true;
            $data = rifftrax_product($pick['nid']);
            $meta = is_array($data) ? mst3k_metadata_from_product($data) : null;
            if ($meta === null) {
                continue;
            }
            $out[] = $meta;
            if (count($out) >= $limit) {
                break;
            }
        }
        if ($out === [] && $index !== []) {
            rifftrax_log('MST3K catalog index has no match for "' . $query . '".');
        } elseif ($out !== []) {
            rifftrax_log('MST3K catalog index returned ' . count($out) . ' title(s) for "' . $query . '".');
        }
        return $out;
    }
}

/**
 * Movie title with the "RiffTrax - " prefix and extension removed.
 * Returns null when the filename does not use that convention.
 */
function rifftrax_filename_title(string $filename): ?string
{
    $base = basename(str_replace('\\', '/', $filename));
    $stem = function_exists('strip_extension') ? strip_extension($base) : $base;
    if (preg_match('/^rifftrax\s+-\s+(.+)$/i', $stem, $match) !== 1) {
        return null;
    }
    $title = rifftrax_clean_title($match[1]);
    return $title !== '' ? $title : null;
}

/**
 * Drop a leading RiffTrax label, a trailing (year), and a trailing "Part N".
 */
function rifftrax_clean_title(string $raw): string
{
    $title = trim($raw);
    $title = preg_replace('/^rifftrax\s*-\s+/i', '', $title) ?? $title;
    $title = preg_replace('/^rifftrax\s+/i', '', $title) ?? $title;
    $title = trim($title);
    $title = trim((string) preg_replace('/\s*[\(\[](?:19|20)\d{2}[\)\]]\s*$/', '', $title));
    if (function_exists('parse_named_part')) {
        $named = parse_named_part($title . '.mp4');
        if ($named !== null && trim((string) $named['stem']) !== '') {
            $title = trim((string) preg_replace('/^rifftrax\s+/i', '', (string) $named['stem']));
        }
    }
    return trim($title);
}

/**
 * Decide whether a video file is RiffTrax before any catalog request.
 *
 * A file matches when either rule is true:
 * - the first folder under its video root is named "RiffTrax" (any case), or
 * - the filename starts with "RiffTrax - " (any case).
 * Both may be true. The title then comes from the filename prefix.
 *
 * fallback is true for a looser hint (the word appears deeper in the path,
 * or the name contains "iriff") so TMDB can run first and RiffTrax is only
 * the backup. Another naming rule can be added in this function.
 *
 * @return array{match:bool, title:string, via:string, fallback:bool}
 */
function rifftrax_identify(string $root, string $path, string $filename = ''): array
{
    $none = ['match' => false, 'title' => '', 'via' => '', 'fallback' => false];
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
    $base = $filename !== '' ? basename(str_replace('\\', '/', $filename)) : '';
    if ($base === '' && $segments !== []) {
        $base = (string) $segments[count($segments) - 1];
    }
    $inFolder = $segments !== [] && strcasecmp($segments[0], 'RiffTrax') === 0 && count($segments) >= 2;
    $fromName = rifftrax_filename_title($base);
    $deeper = false;
    foreach ($segments as $i => $segment) {
        if ($i === 0) {
            continue;
        }
        if (strcasecmp($segment, 'RiffTrax') === 0) {
            $deeper = true;
            break;
        }
    }
    $hay = strtolower($base . ' ' . implode(' ', $segments));
    $word = str_contains($hay, 'rifftrax') || str_contains($hay, 'iriff');
    if (!$inFolder && $fromName === null) {
        $none['fallback'] = $deeper || $word;
        return $none;
    }
    $via = $inFolder && $fromName !== null ? 'both' : ($inFolder ? 'folder' : 'filename');
    $title = $fromName ?? '';
    if ($title === '' && $base !== '') {
        $stem = function_exists('strip_extension') ? strip_extension($base) : $base;
        $parsed = function_exists('parse_name') ? parse_name($stem) : ['title' => $stem];
        $title = rifftrax_clean_title((string) ($parsed['title'] ?? $stem));
    }
    return [
        'match' => true,
        'title' => $title,
        'via' => $via,
        'fallback' => false,
    ];
}

/**
 * True for the folder labels that mean MST3K when no scan service is saved.
 * Comparison is case-insensitive and exact.
 */
function mst3k_folder_name(string $label): bool
{
    $label = trim($label);
    return strcasecmp($label, 'MST3K') === 0
        || strcasecmp($label, 'Mystery Science Theater 3000') === 0;
}

/**
 * Episode title with an "MST3K - " or "MST3K:" filename prefix and the
 * extension removed. Returns null when the filename does not use that
 * convention. "MST3K:The Crawling Hand" is accepted; the colon may have a
 * space after it.
 */
function mst3k_filename_title(string $filename): ?string
{
    $base = basename(str_replace('\\', '/', $filename));
    $stem = function_exists('strip_extension') ? strip_extension($base) : $base;
    if (preg_match('/^mst3k\s+-\s+(.+)$/i', $stem, $match) !== 1
        && preg_match('/^mst3k\s*:\s*(.+)$/i', $stem, $match) !== 1) {
        return null;
    }
    $title = mst3k_clean_title($match[1]);
    return $title !== '' ? $title : null;
}

/**
 * Drop a leading MST3K label, then the same year and "Part N" cleanup used
 * for RiffTrax titles.
 */
function mst3k_clean_title(string $raw): string
{
    $title = trim($raw);
    $title = preg_replace('/^(?:mst3k|mystery science theater 3000)\s*[:\-]\s*/i', '', $title) ?? $title;
    $title = trim($title);
    return rifftrax_clean_title($title);
}

/**
 * Decide whether a video file is MST3K before any catalog request.
 *
 * A file matches when either rule is true:
 * - the first folder under its video root is "MST3K", "mst3k", or
 *   "Mystery Science Theater 3000" (any case), or
 * - the filename starts with "MST3K - " or "MST3K:" (any case).
 * Both may be true. The lookup title then comes from the filename prefix.
 *
 * fallback is true when a deeper folder uses one of those names, or the path
 * mentions MST3K, so another source can run first. A saved scan service still
 * wins over this match.
 *
 * @return array{match:bool, title:string, via:string, fallback:bool}
 */
function mst3k_identify(string $root, string $path, string $filename = ''): array
{
    $none = ['match' => false, 'title' => '', 'via' => '', 'fallback' => false];
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
    $base = $filename !== '' ? basename(str_replace('\\', '/', $filename)) : '';
    if ($base === '' && $segments !== []) {
        $base = (string) $segments[count($segments) - 1];
    }
    $inFolder = $segments !== [] && mst3k_folder_name($segments[0]) && count($segments) >= 2;
    $fromName = mst3k_filename_title($base);
    $deeper = false;
    foreach ($segments as $i => $segment) {
        if ($i === 0) {
            continue;
        }
        if (mst3k_folder_name($segment)) {
            $deeper = true;
            break;
        }
    }
    $hay = strtolower($base . ' ' . implode(' ', $segments));
    $word = str_contains($hay, 'mst3k') || str_contains($hay, 'mystery science theater');
    if (!$inFolder && $fromName === null) {
        $none['fallback'] = $deeper || $word;
        return $none;
    }
    $via = $inFolder && $fromName !== null ? 'both' : ($inFolder ? 'folder' : 'filename');
    $title = $fromName ?? '';
    if ($title === '' && $base !== '') {
        $stem = function_exists('strip_extension') ? strip_extension($base) : $base;
        $parsed = function_exists('parse_name') ? parse_name($stem) : ['title' => $stem];
        $title = mst3k_clean_title((string) ($parsed['title'] ?? $stem));
    }
    return [
        'match' => true,
        'title' => $title,
        'via' => $via,
        'fallback' => false,
    ];
}

/**
 * Direct detail URLs for one title. Prefixed /mst3k-{slug} forms come first,
 * in the same order as rifftrax_slug_variants(), then the plain /{slug} forms.
 *
 * @return list<string>
 */
function mst3k_direct_urls(string $title): array
{
    $urls = [];
    $slugs = rifftrax_slug_variants($title);
    foreach ($slugs as $slug) {
        $urls[] = rifftrax_base() . '/mst3k-' . rawurlencode($slug);
    }
    foreach ($slugs as $slug) {
        $urls[] = rifftrax_base() . '/' . rawurlencode($slug);
    }
    return $urls;
}

/**
 * Catalog search scoped to MST3K. The page filters the same way as
 * /catalog?search=, limited to the MST3K list.
 */
function mst3k_catalog_url(string $title): string
{
    return rifftrax_base() . '/catalog/mst3k?search=' . rawurlencode(rifftrax_slug_source($title));
}

/**
 * Title used when comparing an MST3K product with a filename title.
 * A leading "MST3K:" or "MST3K -" on the product is not part of the movie name.
 */
function mst3k_title_for_match(string $title): string
{
    $title = trim($title);
    $title = preg_replace('/^(?:mst3k|mystery science theater 3000)\s*[:\-]\s*/i', '', $title) ?? $title;
    return trim($title);
}

function mst3k_match_key(string $title): string
{
    return lookup_norm(mst3k_title_for_match($title));
}

/**
 * Close title for a direct MST3K page. The product title may keep an MST3K
 * prefix that the filename lookup already stripped.
 */
function mst3k_title_close(string $query, string $title): bool
{
    if (rifftrax_title_close($query, $title) || rifftrax_title_close($query, mst3k_title_for_match($title))) {
        return true;
    }
    $bareQuery = mst3k_title_for_match($query);
    if ($bareQuery === $query) {
        return false;
    }
    return rifftrax_title_close($bareQuery, $title) || rifftrax_title_close($bareQuery, mst3k_title_for_match($title));
}

/**
 * True when an index row or catalog link is an MST3K episode.
 * Alias slugs use the mst3k- prefix. Some titles keep an MST3K label instead.
 */
function mst3k_record_is_episode(string $slug, string $title, string $alias = ''): bool
{
    $slug = strtolower(trim($slug, '/'));
    $alias = strtolower(trim($alias, '/'));
    if (str_starts_with($slug, 'mst3k-') || str_starts_with($alias, 'mst3k-') || str_starts_with($alias, 'mst3k/')) {
        return true;
    }
    $title = trim($title);
    return preg_match('/^(?:mst3k|mystery science theater 3000)\b/i', $title) === 1;
}

/**
 * Catalog links that belong to MST3K and are close to the query.
 *
 * @param list<string> $slugs
 * @return list<array{slug:string, iriff:bool, distance:float}>
 */
function mst3k_catalog_picks(string $html, string $query, array $slugs): array
{
    $picks = [];
    foreach (rifftrax_catalog_links($html) as $link) {
        if (!mst3k_record_is_episode($link['slug'], $link['title'], $link['path'])) {
            continue;
        }
        $distance = rifftrax_catalog_distance($query, $link['title'], $slugs, $link['slug']);
        if ($distance === null) {
            continue;
        }
        $picks[] = [
            'slug' => $link['slug'],
            'iriff' => $link['iriff'],
            'distance' => $distance,
        ];
    }
    usort($picks, static function (array $a, array $b): int {
        $byDistance = $a['distance'] <=> $b['distance'];
        if ($byDistance !== 0) {
            return $byDistance;
        }
        return ((int) $a['iriff']) <=> ((int) $b['iriff']);
    });
    return $picks;
}

/**
 * Index rows the MST3K catalog searches. Other RiffTrax titles are left out
 * so a shared word cannot choose a non-MST3K product.
 *
 * @param list<mixed> $index
 * @return list<array<string, mixed>>
 */
function mst3k_index_rows(array $index): array
{
    $out = [];
    foreach ($index as $row) {
        if (!is_array($row)) {
            continue;
        }
        $alias = (string) ($row['alias'] ?? '');
        $title = (string) ($row['title'] ?? '');
        if (mst3k_record_is_episode('', $title, $alias)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Load one detail page. Direct guesses require a close title. Catalog links
 * have already been scored, so they can keep a partial title.
 */
function mst3k_detail_from_url(string $url, string $query, bool $requireClose = true): ?VideoMetadata
{
    $page = rifftrax_http($url, false);
    if ($page['code'] !== 200 || $page['body'] === '') {
        return null;
    }
    if (preg_match('/node-type-([a-z0-9_-]+)/i', $page['body'], $kindMatch) === 1) {
        $pageKind = strtolower($kindMatch[1]);
        // RiffTrax pages use node-type-riff. An MST3K page may use that class
        // or node-type-mst3k. Another node type is not a detail page.
        if (!in_array($pageKind, ['riff', 'iriff', 'mst3k'], true)) {
            rifftrax_log('MST3K page is not a product: ' . $url);
            return null;
        }
    }
    $nid = rifftrax_node_id($page['body']);
    if ($nid === null) {
        rifftrax_log('MST3K page has no node id: ' . $url);
        return null;
    }
    $data = rifftrax_product($nid);
    if (!is_array($data)) {
        return null;
    }
    $meta = mst3k_metadata_from_product($data);
    if ($meta === null) {
        return null;
    }
    if ($requireClose && !mst3k_title_close($query, $meta->title)) {
        rifftrax_log('MST3K URL ' . $url . ' is "' . $meta->title . '", not "' . $query . '".');
        return null;
    }
    return $meta;
}

function rifftrax_base(): string
{
    $base = defined('RIFFTRAX_BASE') ? (string) RIFFTRAX_BASE : 'https://www.rifftrax.com';
    return rtrim($base, '/');
}

function rifftrax_log(string $message): void
{
    if (function_exists('app_log')) {
        app_log('rifftrax', $message);
    }
}

function lookup_norm(string $title): string
{
    $title = function_exists('lower') ? lower($title) : strtolower($title);
    $title = preg_replace('/[^a-z0-9]+/', '', $title) ?? $title;
    return $title;
}

function rifftrax_slug(string $title): string
{
    $slug = function_exists('lower') ? lower(trim($title)) : strtolower(trim($title));
    $slug = str_replace('&', ' and ', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    return trim($slug, '-');
}

/**
 * Ordered direct-URL slugs. Apostrophes are removed first so "It's a
 * Wonderful Life" becomes its-a-wonderful-life, which is the live slug.
 * The older conversion (apostrophe to a hyphen) is tried next. A leading
 * "The" and an ampersand spelled without "and" are extra attempts when
 * they produce a different slug. Spaces and other punctuation collapse
 * to single hyphens.
 *
 * @return list<string>
 */
function rifftrax_slug_variants(string $title): array
{
    $source = rifftrax_slug_source($title);
    $variants = [];
    $push = static function (string $slug) use (&$variants): void {
        if ($slug !== '' && !in_array($slug, $variants, true)) {
            $variants[] = $slug;
        }
    };
    $joined = rifftrax_strip_apostrophes($source);
    $push(rifftrax_slug($joined));
    $push(rifftrax_slug($source));
    if (preg_match('/^the\s+(.+)$/i', $joined, $match) === 1) {
        $push(rifftrax_slug($match[1]));
    }
    $withoutAnd = str_replace('&', ' ', $joined);
    if ($withoutAnd !== $joined) {
        $push(rifftrax_slug($withoutAnd));
    }
    return $variants;
}

/**
 * Title prepared for a slug or a catalog query. Curly quotes become plain
 * quotes, and trailing parentheticals are dropped. That includes a year
 * and a qualifier such as "(Special RiffTrax Edit)".
 */
function rifftrax_slug_source(string $title): string
{
    $title = str_replace(
        ["\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"],
        ["'", "'", "'", "'", '"', '"', '-', '-'],
        trim($title)
    );
    $previous = '';
    while ($previous !== $title) {
        $previous = $title;
        $title = trim((string) (preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*$/', '', $title) ?? $title));
    }
    return $title;
}

function rifftrax_strip_apostrophes(string $title): string
{
    return str_replace(["'", "\u{2019}", "\u{2018}", "\u{02BC}", '`'], '', $title);
}

/**
 * Letters and digits only, after the same cleanup as a slug.
 */
function rifftrax_match_key(string $title): string
{
    $title = rifftrax_slug_source($title);
    $title = preg_replace('/\s*[:\-]\s*special\s+rifftrax\s+edit\s*$/i', '', $title) ?? $title;
    return lookup_norm($title);
}

/**
 * Exact title, or the same title with a leading "The" ignored, or a very
 * small edit distance on two titles of the same length. Direct slug pages
 * use this. Catalog search uses rifftrax_catalog_match_score instead, so a
 * shorter query can match inside a longer title.
 */
function rifftrax_title_close(string $query, string $title): bool
{
    return rifftrax_title_distance($query, $title) !== null;
}

function rifftrax_title_distance(string $query, string $title): ?float
{
    $left = rifftrax_match_key($query);
    $right = rifftrax_match_key($title);
    if ($left === '' || $right === '') {
        return null;
    }
    $leftBare = preg_replace('/^the/', '', $left) ?? $left;
    $rightBare = preg_replace('/^the/', '', $right) ?? $right;
    if ($left === $right || ($leftBare !== '' && $leftBare === $rightBare)) {
        return 0.0;
    }
    if (strlen($leftBare) !== strlen($rightBare) || strlen($leftBare) < 6) {
        return null;
    }
    $distance = levenshtein($leftBare, $rightBare);
    $ratio = $distance / strlen($leftBare);
    return $ratio <= 0.15 ? $ratio : null;
}

/**
 * Case-insensitive edit score for one field. An exact substring scores 0,
 * matching Fuse with ignoreLocation. A short typo is allowed only inside a
 * title-length field; long descriptions match on a literal substring.
 */
function rifftrax_bitap_score(string $pattern, string $text): ?float
{
    $pattern = strtolower($pattern);
    $text = strtolower($text);
    if ($pattern === '' || $text === '') {
        return null;
    }
    if (str_contains($text, $pattern)) {
        return 0.0;
    }
    $length = strlen($pattern);
    $maxErrors = (int) floor($length * 0.15);
    $textLength = strlen($text);
    if ($maxErrors < 1 || $textLength > 160 || $textLength > 255 || $length > 255) {
        return null;
    }
    $best = null;
    $startLen = max(1, $length - $maxErrors);
    $endLen = min($textLength, $length + $maxErrors);
    for ($window = $startLen; $window <= $endLen; $window++) {
        $last = $textLength - $window;
        for ($i = 0; $i <= $last; $i++) {
            $distance = levenshtein($pattern, substr($text, $i, $window));
            if ($distance < 0 || $distance > $maxErrors) {
                continue;
            }
            $score = $distance / $length;
            if ($best === null || $score < $best) {
                $best = $score;
            }
            if ($best === 0.0) {
                return 0.0;
            }
        }
    }
    return $best;
}

/**
 * Fuse field-length norm: 1/sqrt(word count), rounded to 3 decimals.
 */
function rifftrax_field_norm(string $text): float
{
    $words = preg_match_all('/[^ ]+/', $text);
    $count = $words > 0 ? $words : 1;
    $norm = round(1 / sqrt($count), 3);
    return $norm > 0 ? $norm : 1.0;
}

function rifftrax_fuse_field_score(float $bitap, float $weight, string $text): float
{
    $base = $bitap === 0.0 ? PHP_FLOAT_EPSILON : $bitap;
    return $base ** ($weight * rifftrax_field_norm($text));
}

/**
 * Catalog-page score. Lower is better. Null means the row is not a hit.
 * Title weight 3, description 1, each collection name 2. Threshold 0.15.
 * This is the Fuse 7.1 setup the catalog page uses, with ignoreLocation.
 *
 * @param list<string> $collections
 */
function rifftrax_catalog_match_score(string $query, string $title, string $description = '', array $collections = []): ?float
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }
    $fields = [['text' => $title, 'weight' => 3.0]];
    if (trim($description) !== '') {
        $fields[] = ['text' => $description, 'weight' => 1.0];
    }
    foreach ($collections as $name) {
        if (!is_string($name)) {
            continue;
        }
        $name = trim($name);
        if ($name !== '') {
            $fields[] = ['text' => $name, 'weight' => 2.0];
        }
    }
    $score = 1.0;
    $matched = false;
    foreach ($fields as $field) {
        $bitap = rifftrax_bitap_score($query, $field['text']);
        if ($bitap === null) {
            continue;
        }
        $score *= rifftrax_fuse_field_score($bitap, $field['weight'], $field['text']);
        $matched = true;
    }
    if (!$matched || $score > 0.15) {
        return null;
    }
    return $score;
}

/**
 * Distance used to sort a catalog hit. 0 is an alias hit or an exact title.
 *
 * @param list<string> $slugs
 * @param list<string> $collections
 */
function rifftrax_catalog_distance(string $query, string $title, array $slugs, string $aliasSlug = '', string $description = '', array $collections = []): ?float
{
    if ($aliasSlug !== '' && in_array($aliasSlug, $slugs, true)) {
        return 0.0;
    }
    $exact = $title !== '' ? rifftrax_title_distance($query, $title) : null;
    if ($exact === 0.0) {
        return 0.0;
    }
    $fuzzy = rifftrax_catalog_match_score($query, $title, $description, $collections);
    if ($exact !== null && ($fuzzy === null || $exact < $fuzzy)) {
        return $exact;
    }
    return $fuzzy;
}

/**
 * Product links inside the catalog page's main content.
 * Footer and header links are ignored. The live catalog shell has none.
 *
 * @return list<array{path:string, title:string, iriff:bool, slug:string}>
 */
function rifftrax_catalog_links(string $html): array
{
    $main = $html;
    if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $mainMatch) === 1) {
        $main = $mainMatch[1];
    }
    $matches = [];
    if (preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $main, $matches, PREG_SET_ORDER) < 1) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($matches as $match) {
        $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = rifftrax_product_path($href);
        if ($path === null || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $iriff = str_starts_with($path, 'iriff/');
        $out[] = [
            'path' => $path,
            'title' => $text,
            'iriff' => $iriff,
            'slug' => $iriff ? substr($path, strlen('iriff/')) : $path,
        ];
    }
    return $out;
}

/**
 * Catalog links that are close to the query. Official pages sort first.
 *
 * @param list<string> $slugs
 * @return list<array{slug:string, iriff:bool, distance:float}>
 */
function rifftrax_catalog_choices(string $html, string $query, array $slugs): array
{
    $picks = [];
    foreach (rifftrax_catalog_links($html) as $link) {
        $distance = rifftrax_catalog_distance($query, $link['title'], $slugs, $link['slug']);
        if ($distance === null) {
            continue;
        }
        $picks[] = [
            'slug' => $link['slug'],
            'iriff' => $link['iriff'],
            'distance' => $distance,
        ];
    }
    usort($picks, static function (array $a, array $b): int {
        $byDistance = $a['distance'] <=> $b['distance'];
        if ($byDistance !== 0) {
            return $byDistance;
        }
        return ((int) $a['iriff']) <=> ((int) $b['iriff']);
    });
    return $picks;
}

function rifftrax_product_path(string $href): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
        return null;
    }
    $parts = parse_url($href);
    if ($parts === false) {
        return null;
    }
    if (isset($parts['host'])) {
        $host = strtolower((string) $parts['host']);
        if ($host !== 'www.rifftrax.com' && $host !== 'rifftrax.com') {
            return null;
        }
    }
    $path = strtolower(trim((string) ($parts['path'] ?? ''), '/'));
    if ($path === '' || str_contains($path, '.')) {
        return null;
    }
    $segments = explode('/', $path);
    $blocked = [
        'catalog', 'cart', 'login', 'user', 'about', 'contact', 'friends', 'search',
        'api', 'privacy', 'terms', 'live', 'offers', 'donate', 'discover', 'community',
        'requests', 'forum', 'mst3k', 'gift-cards', 'bestfriends', 'node',
    ];
    if (count($segments) === 1 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segments[0]) === 1) {
        if (in_array($segments[0], $blocked, true)) {
            return null;
        }
        return $segments[0];
    }
    if (count($segments) === 2
        && $segments[0] === 'iriff'
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segments[1]) === 1) {
        return 'iriff/' . $segments[1];
    }
    return null;
}

/**
 * Catalog-index rows the site's search would keep. Official aliases sort
 * ahead of iRiff. Distance 0 is an exact title or a slug-variant alias.
 * A partial title, such as "outlaw" in "Alien Outlaw", is a hit too.
 *
 * @param list<mixed> $index
 * @param list<string> $slugs
 * @return list<array{nid:string, official:bool, distance:float}>
 */
function rifftrax_index_picks(array $index, string $query, array $slugs): array
{
    $picks = [];
    foreach ($index as $row) {
        if (!is_array($row)) {
            continue;
        }
        $nid = (string) ($row['nid'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $alias = strtolower(trim((string) ($row['alias'] ?? ''), '/'));
        if (!preg_match('/^\d+$/', $nid) || $title === '') {
            continue;
        }
        $official = !str_starts_with($alias, 'iriff/');
        $aliasSlug = $official ? $alias : substr($alias, strlen('iriff/'));
        $collections = [];
        if (is_array($row['collections'] ?? null)) {
            foreach ($row['collections'] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $collections[] = trim($name);
                }
            }
        }
        $distance = rifftrax_catalog_distance(
            $query,
            $title,
            $slugs,
            $aliasSlug,
            trim((string) ($row['description'] ?? '')),
            $collections
        );
        if ($distance === null) {
            continue;
        }
        $picks[] = [
            'nid' => $nid,
            'official' => $official,
            'distance' => $distance,
        ];
    }
    usort($picks, static function (array $a, array $b): int {
        $byDistance = $a['distance'] <=> $b['distance'];
        if ($byDistance !== 0) {
            return $byDistance;
        }
        return ((int) $b['official']) <=> ((int) $a['official']);
    });
    return $picks;
}

/**
 * @return array{code:int, body:string, data:?array}
 */
function rifftrax_http(string $url, bool $json): array
{
    $empty = ['code' => 0, 'body' => '', 'data' => null];
    if (!function_exists('curl_init')) {
        return $empty;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return $empty;
    }
    $timeout = defined('RIFFTRAX_TIMEOUT') ? (int) RIFFTRAX_TIMEOUT : 20;
    if ($timeout < 5) {
        $timeout = 5;
    }
    $agent = defined('RIFFTRAX_USER_AGENT') ? (string) RIFFTRAX_USER_AGENT : 'MediaInformant/1.0';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: ' . ($json ? 'application/json' : 'text/html')],
        CURLOPT_USERAGENT => $agent,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $sleep = defined('RIFFTRAX_REQUEST_SLEEP_US') ? (int) RIFFTRAX_REQUEST_SLEEP_US : 0;
    if ($sleep > 0) {
        usleep($sleep);
    }
    if ($body === false) {
        rifftrax_log('RiffTrax request failed: ' . $url);
        return $empty;
    }
    $text = (string) $body;
    if ($code !== 200) {
        rifftrax_log('RiffTrax HTTP ' . $code . ' for ' . $url);
        return ['code' => $code, 'body' => $text, 'data' => null];
    }
    if (!$json) {
        return ['code' => 200, 'body' => $text, 'data' => null];
    }
    $data = json_decode($text, true);
    if (!is_array($data)) {
        rifftrax_log('RiffTrax JSON was not an object: ' . $url);
        return ['code' => 200, 'body' => $text, 'data' => null];
    }
    return ['code' => 200, 'body' => $text, 'data' => $data];
}

function rifftrax_node_id(string $html): ?string
{
    if (preg_match('/rel="shortlink"[^>]*href="[^"]*\/node\/(\d+)"/i', $html, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/\/node\/(\d+)/', $html, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/\bnode-(\d+)\b/', $html, $match) === 1) {
        return $match[1];
    }
    return null;
}

/**
 * @return array<string, mixed>|null
 */
function rifftrax_product(string $nid): ?array
{
    if (!preg_match('/^\d+$/', $nid)) {
        return null;
    }
    $cachePath = (defined('CACHE_DIR') ? CACHE_DIR : '') . '/rifftrax/' . $nid . '.json';
    $ttl = defined('RIFFTRAX_INDEX_TTL') ? (int) RIFFTRAX_INDEX_TTL : 604800;
    if ($cachePath !== '/rifftrax/' . $nid . '.json' && function_exists('cache_read_json')) {
        $cached = cache_read_json($cachePath);
        $at = is_array($cached) ? (int) ($cached['fetched_at'] ?? 0) : 0;
        if ($at > 0 && (time() - $at) < $ttl && is_array($cached['product'] ?? null)) {
            return $cached['product'];
        }
    }
    $res = rifftrax_http(rifftrax_base() . '/api/dante/product_display/' . $nid, true);
    $data = $res['data'];
    if (!is_array($data) || trim((string) ($data['title'] ?? '')) === '') {
        rifftrax_log('RiffTrax product ' . $nid . ' had no title.');
        return null;
    }
    if (function_exists('cache_write_atomic') && defined('CACHE_DIR')) {
        cache_write_atomic($cachePath, ['fetched_at' => time(), 'product' => $data]);
    }
    return $data;
}

/**
 * Copy collection names onto the index rows they apply to.
 * The catalog page searches those names with weight 2.
 *
 * @param list<array<string, mixed>> $rows
 * @param list<mixed> $collections
 * @return list<array<string, mixed>>
 */
function rifftrax_index_apply_collections(array $rows, array $collections): array
{
    $names = [];
    foreach ($collections as $col) {
        if (!is_array($col)) {
            continue;
        }
        $value = trim((string) ($col['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $apply = is_array($col['applyTo'] ?? null) ? $col['applyTo'] : [];
        foreach ($apply as $nid) {
            $key = (string) $nid;
            if ($key !== '') {
                $names[$key][] = $value;
            }
        }
    }
    if ($names === []) {
        return $rows;
    }
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $nid = (string) ($row['nid'] ?? '');
        if ($nid !== '' && isset($names[$nid])) {
            $row['collections'] = $names[$nid];
            $rows[$i] = $row;
        }
    }
    return $rows;
}

/**
 * Cached copy of /api/dante/product_search. Empty when the site cannot be reached
 * and nothing is cached yet. A failed refresh keeps the previous index.
 *
 * @return list<array<string, mixed>>
 */
function rifftrax_index(): array
{
    $path = (defined('CACHE_DIR') ? CACHE_DIR : '') . '/rifftrax-index.json';
    $ttl = defined('RIFFTRAX_INDEX_TTL') ? (int) RIFFTRAX_INDEX_TTL : 604800;
    $cached = ($path !== '' && function_exists('cache_read_json')) ? cache_read_json($path) : null;
    $at = is_array($cached) ? (int) ($cached['fetched_at'] ?? 0) : 0;
    $rows = is_array($cached) && is_array($cached['index'] ?? null) ? $cached['index'] : [];
    if ($at > 0 && (time() - $at) < $ttl && $rows !== []) {
        return $rows;
    }
    $res = rifftrax_http(rifftrax_base() . '/api/dante/product_search', true);
    $fresh = is_array($res['data']) && is_array($res['data']['index'] ?? null) ? $res['data']['index'] : [];
    if ($fresh !== []) {
        $collections = is_array($res['data']['collections'] ?? null) ? $res['data']['collections'] : [];
        $fresh = rifftrax_index_apply_collections($fresh, $collections);
    }
    if ($fresh === []) {
        if ($rows === []) {
            rifftrax_log('RiffTrax search index was empty.');
        }
        return $rows;
    }
    if (function_exists('cache_write_atomic') && defined('CACHE_DIR')) {
        cache_write_atomic($path, ['fetched_at' => time(), 'index' => $fresh]);
    }
    return $fresh;
}

/**
 * @param array<string, mixed> $data
 */
function rifftrax_metadata_from_product(array $data): ?VideoMetadata
{
    $title = trim((string) ($data['title'] ?? ''));
    $nid = (string) ($data['nid'] ?? '');
    if ($title === '' || !preg_match('/^\d+$/', $nid)) {
        return null;
    }
    $genres = [];
    $source = is_array($data['source'] ?? null) ? $data['source'] : [];
    $genreRows = is_array($source['genre'] ?? null) ? $source['genre'] : [];
    foreach ($genreRows as $genre) {
        if (is_string($genre) && trim($genre) !== '') {
            $genres[] = trim($genre);
            continue;
        }
        if (is_array($genre)) {
            $name = trim((string) ($genre['name'] ?? ''));
            if ($name !== '') {
                $genres[] = $name;
            }
        }
    }
    $released = (string) ($data['released'] ?? '');
    $year = null;
    if (preg_match('/^(\d{4})/', $released, $match) === 1) {
        $candidate = (int) $match[1];
        if ($candidate >= 1870 && $candidate <= 2100) {
            $year = $candidate;
        }
    }
    $original = null;
    foreach (['year', 'original_year', 'film_year'] as $key) {
        if (isset($source[$key]) && is_numeric($source[$key])) {
            $candidate = (int) $source[$key];
            if ($candidate >= 1870 && $candidate <= 2100 && $candidate !== $year) {
                $original = $candidate;
                break;
            }
        }
    }
    $rating = null;
    if (isset($data['average_rating']) && is_numeric($data['average_rating'])) {
        $rating = (float) $data['average_rating'];
        if ($rating > 10) {
            $rating = $rating / 10;
        }
        if ($rating < 0) {
            $rating = null;
        } elseif ($rating > 10) {
            $rating = 10.0;
        } else {
            $rating = round($rating, 1);
        }
    }
    $votes = null;
    foreach (['vote_count', 'rating_count', 'review_count'] as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            $votes = max(0, (int) $data[$key]);
            break;
        }
    }
    $poster = '';
    if (is_array($data['poster_full'] ?? null)) {
        $poster = trim((string) ($data['poster_full']['uri'] ?? ''));
    }
    if ($poster === '' && is_array($data['poster'] ?? null)) {
        $poster = trim((string) ($data['poster']['uri'] ?? ''));
    }
    $posterUrl = $poster !== '' ? rifftrax_absolute_url($poster) : null;
    $html = trim((string) ($data['field_description'] ?? ''));
    if ($html === '') {
        $html = trim((string) ($data['short_description'] ?? ''));
    }
    $runtime = null;
    if (isset($data['runtime']) && is_numeric($data['runtime'])) {
        $mins = (int) $data['runtime'];
        $runtime = $mins > 0 ? $mins : null;
    }
    $path = trim((string) ($data['path'] ?? ''));
    $detail = $path !== '' ? rifftrax_absolute_url($path) : null;
    $kind = strtolower(trim((string) ($data['type'] ?? '')));
    if ($kind !== 'riff' && $kind !== 'iriff') {
        $kind = '';
    }
    return new VideoMetadata(
        'RiffTrax',
        $title,
        $genres,
        $year,
        $original,
        $rating,
        $votes,
        $posterUrl,
        lookup_plain_paragraphs($html),
        $detail,
        $runtime,
        'movie',
        $nid,
        $kind
    );
}

/**
 * RiffTrax product JSON stored as an MST3K record.
 * Genres, year, rating, poster, description, and runtime stay on the shared extractor.
 *
 * @param array<string, mixed> $data
 */
function mst3k_metadata_from_product(array $data): ?VideoMetadata
{
    $meta = rifftrax_metadata_from_product($data);
    if ($meta === null) {
        return null;
    }
    $meta->source = 'MST3K';
    return $meta;
}

function rifftrax_absolute_url(string $uri): string
{
    if ($uri === '') {
        return '';
    }
    if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
        return $uri;
    }
    if (str_starts_with($uri, '//')) {
        return 'https:' . $uri;
    }
    return rifftrax_base() . '/' . ltrim($uri, '/');
}

function lookup_plain_paragraphs(string $html): string
{
    if ($html === '') {
        return '';
    }
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(p|div|h[1-6]|li|blockquote)>/i', "\n\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    $text = trim($text);
    if (strlen($text) > 8000) {
        $text = substr($text, 0, 8000);
    }
    return $text;
}

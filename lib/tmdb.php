<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/search_queries.php';

function tmdb_has_key(): bool
{
    return settings_tmdb_key() !== '';
}

function tmdb_norm(string $title): string
{
    $title = lower($title);
    $title = preg_replace('/[^a-z0-9]+/i', '', $title) ?? $title;
    return $title;
}

function tmdb_verbose_log(string $message): void
{
    if (!function_exists('app_log') || !function_exists('app_log_verbose') || !app_log_verbose()) {
        return;
    }
    app_log('tmdb', $message, [], 'debug');
}

function tmdb_scan_item_begin(int $n, string $file, string $path = ''): void
{
    $GLOBALS['tmdb_scan_item'] = [
        'n' => $n,
        'file' => $file,
        'path' => $path,
    ];
}

function tmdb_scan_item_end(): void
{
    unset($GLOBALS['tmdb_scan_item']);
}

function tmdb_scan_item(): array
{
    $state = $GLOBALS['tmdb_scan_item'] ?? null;
    return is_array($state) ? $state : [];
}

function tmdb_get(string $path, array $query = [], int $retries = 1): ?array
{
    if (!tmdb_has_key() || !function_exists('curl_init')) {
        return null;
    }

    $query['api_key'] = settings_tmdb_key();
    $query['language'] = settings_tmdb_language();
    $url = 'https://api.themoviedb.org/3' . $path . '?' . http_build_query($query);
    $loggedQuery = $query;
    unset($loggedQuery['api_key']);
    $params = (string) json_encode($loggedQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $item = tmdb_scan_item();
    $itemN = (int) ($item['n'] ?? 0);
    $itemFile = (string) ($item['file'] ?? '');
    if ($itemN > 0 && function_exists('app_log')) {
        $msg = 'TMDB item ' . $itemN . ' request: ' . $path . ' ' . $params;
        if ($itemFile !== '') {
            $msg .= ' file=' . $itemFile;
        }
        app_log('tmdb', $msg);
    } else {
        tmdb_verbose_log('TMDB request ' . $path . ' params=' . $params);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'MediaInformant/1.0',
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $raw = $body === false ? '(empty)' : (string) $body;
    if ($itemN > 0 && function_exists('app_log')) {
        $hits = '';
        if ($body !== false && $code === 200) {
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])) {
                $hits = ' hits=' . count($decoded['results']);
            } elseif (is_array($decoded) && !empty($decoded['id'])) {
                $hits = ' id=' . (int) $decoded['id'];
            }
        }
        $msg = 'TMDB item ' . $itemN . ' response: HTTP ' . $code . ' ' . $path . $hits;
        if ($itemFile !== '') {
            $msg .= ' file=' . $itemFile;
        }
        app_log('tmdb', $msg);
    }
    tmdb_verbose_log('TMDB response HTTP ' . $code . ' for ' . $path . ': ' . $raw);

    if ($code === 429 && $retries > 0) {
        tmdb_verbose_log('TMDB HTTP 429 for ' . $path . ' — retrying.');
        usleep(1100000);
        return tmdb_get($path, $query, $retries - 1);
    }

    if ($body === false || $code !== 200) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function tmdb_confident_hit(array $results, string $title, ?int $year): ?array
{
    if ($results === []) {
        return null;
    }

    $want = tmdb_norm($title);
    if ($want === '' || strlen($want) < 2) {
        return null;
    }

    $best = null;
    $bestScore = -1;

    foreach ($results as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $got = tmdb_norm((string) ($row['title'] ?? ''));
        if ($got === '') {
            $got = tmdb_norm((string) ($row['original_title'] ?? ''));
        }
        $release = (string) ($row['release_date'] ?? '');
        $gotYear = strlen($release) >= 4 ? (int) substr($release, 0, 4) : null;

        $score = 0;
        if ($got === $want) {
            $score += 8;
        } elseif ($got !== '' && (str_contains($got, $want) || str_contains($want, $got))) {
            $score += 3;
        }

        if ($year !== null && $gotYear === $year) {
            $score += 6;
        } elseif ($year !== null && $gotYear !== null && abs($gotYear - $year) === 1) {
            $score += 2;
        } elseif ($year === null) {
            $score += 1;
        }

        if ($i === 0) {
            $score += 1;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    if ($best === null) {
        return null;
    }

    $min = $year !== null ? 10 : 8;
    if ($bestScore >= $min) {
        return $best;
    }
    if (count($results) === 1 && $bestScore >= ($year !== null ? 6 : 8)) {
        return $best;
    }

    return null;
}

function tmdb_map_result(array $row, string $defaultMedia = 'movie'): ?array
{
    $media = (string) ($row['media_type'] ?? $defaultMedia);
    if ($media === 'person') {
        return null;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $release = (string) ($row['release_date'] ?? $row['first_air_date'] ?? '');
    $poster = $row['poster_path'] ?? null;
    $title = (string) ($row['title'] ?? $row['name'] ?? $row['original_title'] ?? $row['original_name'] ?? '');
    $original = (string) ($row['original_title'] ?? $row['original_name'] ?? $title);
    return [
        'tmdb_id' => $id,
        'title' => $title,
        'original_title' => $original,
        'year' => strlen($release) >= 4 ? (int) substr($release, 0, 4) : null,
        'overview' => trim((string) ($row['overview'] ?? '')),
        'poster_path' => is_string($poster) && $poster !== '' ? $poster : null,
        'media_type' => $media === 'tv' ? 'tv' : 'movie',
        'popularity' => isset($row['popularity']) ? (float) $row['popularity'] : 0.0,
        'vote_count' => (int) ($row['vote_count'] ?? 0),
    ];
}

function tmdb_map_results(array $rows, string $defaultMedia = 'movie'): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = tmdb_map_result($row, $defaultMedia);
        if ($mapped !== null) {
            $out[] = $mapped;
        }
    }
    return $out;
}

function tmdb_list_payload(?array $data, string $defaultMedia = 'movie'): array
{
    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    return [
        'results' => tmdb_map_results($results, $defaultMedia),
        'page' => (int) ($data['page'] ?? 1),
        'total_pages' => max(1, (int) ($data['total_pages'] ?? 1)),
    ];
}

function tmdb_search_results(string $query, ?int $year, int $page = 1, string $mediaType = 'movie'): array
{
    $query = trim($query);
    if ($query === '') {
        return ['results' => [], 'page' => 1, 'total_pages' => 1];
    }
    $isTv = $mediaType === 'tv';
    $endpoint = $isTv ? '/search/tv' : '/search/movie';
    $yearKey = $isTv ? 'first_air_date_year' : 'year';
    $params = [
        'query' => $query,
        'include_adult' => 'false',
        'page' => max(1, $page),
    ];
    if ($year !== null && $year >= 1870) {
        $params[$yearKey] = (string) $year;
    }
    $data = tmdb_get($endpoint, $params);
    $payload = tmdb_list_payload($data, $isTv ? 'tv' : 'movie');
    if ($payload['results'] === [] && isset($params[$yearKey])) {
        unset($params[$yearKey]);
        $payload = tmdb_list_payload(tmdb_get($endpoint, $params), $isTv ? 'tv' : 'movie');
        $payload['relaxed_year'] = true;
    }
    return $payload;
}

function tmdb_popular_results(int $page = 1, string $mediaType = 'movie'): array
{
    $isTv = $mediaType === 'tv';
    $path = $isTv ? '/tv/popular' : '/movie/popular';
    return tmdb_list_payload(tmdb_get($path, ['page' => max(1, $page)]), $isTv ? 'tv' : 'movie');
}

/**
 * Path/kind/season/episode context for TV-aware query construction.
 *
 * @param array<string, mixed> $item
 * @return array{kind:string,path:string,season:?int,episode:?int,episode_title:string}
 */
function tmdb_search_meta_from_item(array $item): array
{
    $path = (string) ($item['path'] ?? '');
    $filename = (string) ($item['filename'] ?? '');
    $season = $item['season'] ?? null;
    $episode = $item['episode'] ?? null;
    return [
        'kind' => (string) ($item['kind'] ?? 'movie'),
        'path' => $path !== '' ? $path : $filename,
        'season' => is_numeric($season) ? (int) $season : null,
        'episode' => is_numeric($episode) ? (int) $episode : null,
        'episode_title' => (string) ($item['episode_title'] ?? ''),
    ];
}

function tmdb_shortlist_limit(): int
{
    return 6;
}

function tmdb_rank_key(string $s): string
{
    if (function_exists('search_query_clean')) {
        $s = search_query_clean($s);
    }
    if (function_exists('search_query_drop_article')) {
        $s = search_query_drop_article($s);
    }
    return tmdb_norm($s);
}

function tmdb_rank_content_words(string $s): array
{
    if (function_exists('search_query_clean')) {
        $s = search_query_clean($s);
    }
    $words = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) {
        if (function_exists('search_query_is_content_word') && !search_query_is_content_word($w)) {
            continue;
        }
        $out[] = $w;
    }
    return $out;
}

function tmdb_rank_want_keys(string $title, string $filename, string $query): array
{
    $keys = [];
    $sources = [$title, $filename, $query];
    foreach ($sources as $src) {
        $src = trim($src);
        if ($src === '') {
            continue;
        }
        $clean = function_exists('search_query_clean') ? search_query_clean($src) : $src;
        $k = tmdb_rank_key($clean);
        if ($k !== '') {
            $keys[$k] = true;
        }
        if (function_exists('search_query_prefix_remainder')) {
            foreach (search_query_prefix_remainder($clean) as $rest) {
                $rk = tmdb_rank_key($rest);
                if ($rk !== '') {
                    $keys[$rk] = true;
                }
            }
        }
        $words = tmdb_rank_content_words($clean);
        $n = count($words);
        if ($n >= 1) {
            $last = tmdb_norm($words[$n - 1]);
            if ($last !== '') {
                $keys[$last] = true;
            }
        }
        if ($n >= 2) {
            $last2 = tmdb_norm($words[$n - 2] . $words[$n - 1]);
            if ($last2 !== '') {
                $keys[$last2] = true;
            }
        }
    }
    $out = [];
    foreach (array_keys($keys) as $key) {
        $out[] = (string) $key;
    }
    return $out;
}

function tmdb_rank_spinoff_blob(string $title, string $overview): bool
{
    $blob = $title . ' ' . $overview;
    return (bool) preg_match(
        '/\b(?:documentary|soundtrack|making\s+of|behind\s+the\s+scenes|in\s+forks|ai[\'’]?s\s+guide|concert|music\s+videos?|fan\s+film|unauthorized)\b/iu',
        $blob
    );
}

function tmdb_rank_file_is_feature(string $title, string $filename): bool
{
    $blob = $title . ' ' . $filename;
    return !preg_match(
        '/\b(?:documentary|soundtrack|making\s+of|concert|music\s+videos?)\b/iu',
        $blob
    );
}

/**
 * Rank TMDB hits by filename/title relevance, then keep at most N.
 * Exact/near-exact title matches are forced into the shortlist even if
 * TMDB returned them last. Never cap by raw TMDB order.
 *
 * @param list<array<string, mixed>> $results
 * @return list<array<string, mixed>>
 */
function tmdb_shortlist_results(array $results, string $title, string $filename, ?int $year, string $query = ''): array
{
    $limit = tmdb_shortlist_limit();
    if ($results === [] || $limit < 1) {
        return [];
    }
    $wantKeys = tmdb_rank_want_keys($title, $filename, $query);
    $fileIsFeature = tmdb_rank_file_is_feature($title, $filename);
    $coreKeys = [];
    $cleanFile = function_exists('search_query_clean') ? search_query_clean($filename !== '' ? $filename : $title) : $title;
    if (function_exists('search_query_prefix_remainder')) {
        foreach (search_query_prefix_remainder($cleanFile) as $rest) {
            $ck = tmdb_rank_key($rest);
            if ($ck !== '') {
                $coreKeys[$ck] = true;
            }
        }
    }
    $words = tmdb_rank_content_words($cleanFile);
    if ($words !== []) {
        $last = tmdb_norm($words[count($words) - 1]);
        if ($last !== '') {
            $coreKeys[$last] = true;
        }
    }

    $scored = [];
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['tmdb_id'] ?? $row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $candTitle = (string) ($row['title'] ?? '');
        $candOrig = (string) ($row['original_title'] ?? $candTitle);
        $candKey = tmdb_rank_key($candTitle);
        $origKey = tmdb_rank_key($candOrig);
        $overview = (string) ($row['overview'] ?? '');
        $candYear = isset($row['year']) && $row['year'] !== null && $row['year'] !== ''
            ? (int) $row['year']
            : null;
        $score = 0;
        $exact = false;

        foreach ($wantKeys as $want) {
            $want = (string) $want;
            if ($want === '') {
                continue;
            }
            if ($candKey === $want || $origKey === $want) {
                $score += 100;
                $exact = true;
                break;
            }
        }
        if (!$exact && ($candKey !== '' || $origKey !== '')) {
            foreach ($coreKeys as $core => $_) {
                $core = (string) $core;
                if ($core !== '' && ($candKey === $core || $origKey === $core)) {
                    $score += 80;
                    $exact = true;
                    break;
                }
            }
        }
        foreach ($wantKeys as $want) {
            $want = (string) $want;
            if ($want === '' || strlen($want) < 3) {
                continue;
            }
            if ($candKey === $want || $origKey === $want) {
                continue;
            }
            if (str_contains($candKey, $want) || str_contains($origKey, $want)) {
                $score += strlen($want) >= 8 ? 25 : 15;
                break;
            }
        }
        if ($year !== null && $year >= 1870 && $candYear !== null) {
            if ($candYear === $year) {
                $score += 20;
            } elseif (abs($candYear - $year) === 1) {
                $score += 6;
            }
        }
        if ($fileIsFeature && tmdb_rank_spinoff_blob($candTitle . ' ' . $candOrig, $overview)) {
            $score -= 60;
        }

        $scored[] = [
            'row' => $row,
            'id' => $id,
            'key' => $candKey,
            'score' => $score,
            'exact' => $exact,
            'pop' => (float) ($row['popularity'] ?? 0),
            'votes' => (int) ($row['vote_count'] ?? 0),
        ];
    }

    $hasExactCore = false;
    foreach ($scored as $item) {
        if ($item['exact']) {
            $hasExactCore = true;
            break;
        }
    }
    if ($hasExactCore) {
        foreach ($scored as $i => $item) {
            if (!$item['exact']) {
                $scored[$i]['score'] -= 40;
            }
        }
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        if ($a['pop'] !== $b['pop']) {
            return $a['pop'] < $b['pop'] ? 1 : -1;
        }
        return $b['votes'] <=> $a['votes'];
    });

    $kept = array_slice($scored, 0, $limit);
    $keptIds = [];
    foreach ($kept as $item) {
        $keptIds[$item['id']] = true;
    }
    foreach ($scored as $item) {
        if (!$item['exact'] || isset($keptIds[$item['id']])) {
            continue;
        }
        for ($i = count($kept) - 1; $i >= 0; $i--) {
            if (empty($kept[$i]['exact'])) {
                unset($keptIds[$kept[$i]['id']]);
                array_splice($kept, $i, 1);
                break;
            }
        }
        if (count($kept) < $limit) {
            $kept[] = $item;
            $keptIds[$item['id']] = true;
        }
    }
    $kept = array_values($kept);

    $out = [];
    $labels = [];
    foreach ($kept as $item) {
        $row = $item['row'];
        $out[] = $row;
        $lab = (string) ($row['title'] ?? '');
        if (!empty($row['year'])) {
            $lab .= ' (' . (int) $row['year'] . ')';
        }
        $labels[] = (int) $item['id'] . ' ' . $lab;
        tmdb_verbose_log(
            'TMDB rank "' . $lab . '" score=' . (int) $item['score']
            . ($item['exact'] ? ' exact' : '')
        );
    }

    $itemCtx = tmdb_scan_item();
    $prefix = !empty($itemCtx['n'])
        ? 'TMDB item ' . (int) $itemCtx['n'] . ' shortlist: '
        : 'TMDB shortlist: ';
    $fileBit = !empty($itemCtx['file']) ? ' file=' . (string) $itemCtx['file'] : '';
    $msg = $prefix . ($labels === [] ? '(none)' : implode('; ', $labels)) . $fileBit;
    if (!empty($itemCtx['n']) && function_exists('app_log')) {
        app_log('tmdb', $msg);
    } else {
        tmdb_verbose_log($msg);
    }

    return $out;
}

function tmdb_movie_search_params(string $query, ?int $year, string $yearKey = 'primary_release_year'): array
{
    $params = [
        'query' => $query,
        'include_adult' => 'false',
        'page' => 1,
    ];
    if ($year !== null && $year >= 1870) {
        $params[$yearKey] = (string) $year;
    }
    return $params;
}

/**
 * Try shared query variants. TV paths prefer /search/tv (show title from
 * grandparent/parent folders, episode name from the filename). Movies use
 * /search/movie. Year filter is first-attempt only and is skipped for TV
 * (episode years are not first_air_date_year). Empty movie/tv lists fall
 * back to /search/multi with the cleanest title.
 *
 * @return array{results: list<array>, page: int, total_pages: int, query?: string, source?: string}
 */
function tmdb_search_first_results(string $title, ?int $year, string $filename = '', array $meta = []): array
{
    $empty = ['results' => [], 'page' => 1, 'total_pages' => 1];
    $path = (string) ($meta['path'] ?? $filename);
    $queries = tmdb_search_queries($title, $filename, $year, $meta + ['path' => $path]);
    if ($queries === []) {
        tmdb_verbose_log('TMDB query list empty for title=' . $title);
        return $empty;
    }
    $bestClean = $queries[0];
    $isTv = function_exists('search_query_path_is_tv') && search_query_path_is_tv($path, $meta);
    tmdb_verbose_log(
        'TMDB queries (' . ($isTv ? 'tv' : 'movie')
        . ', show=' . (string) ($meta['kind'] ?? '')
        . ' s=' . (string) ($meta['season'] ?? '')
        . ' e=' . (string) ($meta['episode'] ?? '')
        . '): ' . (string) json_encode($queries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $endpoints = $isTv
        ? [
            ['path' => '/search/tv', 'yearKey' => 'first_air_date_year', 'source' => 'tv'],
        ]
        : [
            ['path' => '/search/movie', 'yearKey' => 'primary_release_year', 'source' => 'movie'],
        ];
    $first = true;
    foreach ($queries as $q) {
        if ($isTv && function_exists('search_query_tv_series_skip_reason')) {
            $tvSkip = search_query_tv_series_skip_reason($q);
            if ($tvSkip !== '' && $tvSkip !== 'empty series name') {
                if (function_exists('app_log')) {
                    $fileBit = '';
                    $itemCtx = tmdb_scan_item();
                    if (!empty($itemCtx['file'])) {
                        $fileBit = ' file=' . (string) $itemCtx['file'];
                    }
                    app_log('tmdb', 'TMDB tv series query skipped: query="' . $q . '" (' . $tvSkip . ')' . $fileBit);
                }
                continue;
            }
        }
        $attempts = [];
        // Episode air years are not first_air_date_year; skip year on TV searches.
        if ($first && !$isTv && $year !== null && $year >= 1870) {
            $attempts[] = ['year' => $year, 'label' => 'year'];
            $attempts[] = ['year' => null, 'label' => 'no-year'];
        } else {
            $attempts[] = ['year' => null, 'label' => 'no-year'];
        }
        $first = false;
        foreach ($endpoints as $ep) {
            foreach ($attempts as $attempt) {
                $params = tmdb_movie_search_params($q, $attempt['year'], $ep['yearKey']);
                $defaultMedia = $ep['source'] === 'tv' ? 'tv' : 'movie';
                $payload = tmdb_list_payload(tmdb_get($ep['path'], $params), $defaultMedia);
                $hits = count($payload['results']);
                $weak = function_exists('search_query_skip_as_weak')
                    ? search_query_skip_as_weak($q, $isTv)
                    : (!$isTv && function_exists('search_query_is_weak') && search_query_is_weak($q));
                tmdb_verbose_log('TMDB ' . $ep['source'] . ' query "' . $q . '" (' . $attempt['label'] . ($weak ? ', weak' : '') . ') hits=' . $hits);
                if ($hits > 0 && $weak && !$isTv) {
                    tmdb_verbose_log('TMDB weak query ignored: "' . $q . '"');
                    usleep(TMDB_REQUEST_SLEEP_US);
                    continue;
                }
                if ($hits > 0) {
                    tmdb_verbose_log('TMDB query hit: "' . $q . '" (' . $ep['source'] . ')');
                    $rankTitle = $isTv ? $q : $title;
                    $rankFile = $isTv ? '' : $filename;
                    $payload['results'] = tmdb_shortlist_results($payload['results'], $rankTitle, $rankFile, $year, $q);
                    $payload['query'] = $q;
                    $payload['source'] = $ep['source'];
                    return $payload;
                }
                usleep(TMDB_REQUEST_SLEEP_US);
            }
        }
    }

    if ($isTv) {
        return $empty;
    }

    tmdb_verbose_log('TMDB movie/tv queries empty; trying /search/multi "' . $bestClean . '"');
    $multi = tmdb_get('/search/multi', [
        'query' => $bestClean,
        'include_adult' => 'false',
        'page' => 1,
    ]);
    $rows = is_array($multi['results'] ?? null) ? $multi['results'] : [];
    $mapped = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = (string) ($row['media_type'] ?? '');
        if ($type !== 'movie' && $type !== 'tv') {
            continue;
        }
        $one = tmdb_map_result($row);
        if ($one !== null) {
            $mapped[] = $one;
        }
    }
    usleep(TMDB_REQUEST_SLEEP_US);
    tmdb_verbose_log('TMDB multi query "' . $bestClean . '" hits=' . count($mapped));
    if ($mapped === []) {
        return $empty;
    }
    tmdb_verbose_log('TMDB query hit: "' . $bestClean . '" (multi)');
    return [
        'results' => tmdb_shortlist_results($mapped, $title, $filename, $year, $bestClean),
        'page' => 1,
        'total_pages' => 1,
        'query' => $bestClean,
        'source' => 'multi',
    ];
}

function tmdb_search_candidates(string $title, ?int $year, int $limit = 6, string $filename = '', array $meta = []): array
{
    $title = trim($title);
    if ($title === '' && trim($filename) === '' && (string) ($meta['path'] ?? '') === '') {
        return [];
    }
    if (!tmdb_has_key()) {
        return [];
    }
    $payload = tmdb_search_first_results($title, $year, $filename, $meta);
    $out = [];
    $seen = [];
    foreach ($payload['results'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['tmdb_id'] ?? 0);
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'year' => $row['year'] ?? null,
            'media_type' => (string) ($row['media_type'] ?? 'movie'),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function tmdb_search_movie(string $title, ?int $year, string $filename = '', array $meta = []): ?array
{
    $payload = tmdb_search_first_results($title, $year, $filename, $meta);
    $results = [];
    foreach ($payload['results'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $results[] = [
            'id' => (int) ($row['tmdb_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'original_title' => (string) ($row['title'] ?? ''),
            'release_date' => !empty($row['year']) ? ((int) $row['year'] . '-01-01') : '',
            'media_type' => (string) ($row['media_type'] ?? 'movie'),
        ];
    }
    $hit = tmdb_confident_hit($results, $title, $year);
    if ($hit === null && ($payload['query'] ?? '') !== '' && strcasecmp((string) $payload['query'], $title) !== 0) {
        $hit = tmdb_confident_hit($results, (string) $payload['query'], $year);
    }
    return $hit;
}

function tmdb_details_from_data(array $data, string $mediaType): ?array
{
    if (empty($data['id'])) {
        return null;
    }
    $isTv = $mediaType === 'tv';
    $release = (string) ($data[$isTv ? 'first_air_date' : 'release_date'] ?? $data['first_air_date'] ?? $data['release_date'] ?? '');
    $year = strlen($release) >= 4 ? (int) substr($release, 0, 4) : null;
    $cast = [];
    $credits = is_array($data['credits']['cast'] ?? null) ? $data['credits']['cast'] : [];
    foreach ($credits as $person) {
        if (!is_array($person)) {
            continue;
        }
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $cast[] = $name;
        if (count($cast) >= 8) {
            break;
        }
    }

    $poster = $data['poster_path'] ?? null;
    $genres = [];
    foreach (is_array($data['genres'] ?? null) ? $data['genres'] : [] as $genre) {
        if (!is_array($genre)) {
            continue;
        }
        $name = trim((string) ($genre['name'] ?? ''));
        if ($name !== '') {
            $genres[] = $name;
        }
    }

    $title = $isTv
        ? (string) ($data['name'] ?? $data['original_name'] ?? $data['title'] ?? '')
        : (string) ($data['title'] ?? $data['original_title'] ?? $data['name'] ?? '');

    return [
        'tmdb_id' => (int) $data['id'],
        'title' => $title,
        'year' => $year,
        'overview' => trim((string) ($data['overview'] ?? '')),
        'poster_path' => is_string($poster) && $poster !== '' ? $poster : null,
        'cast' => $cast,
        'genres' => $genres,
        'media_type' => $isTv ? 'tv' : 'movie',
        'seasons' => $isTv ? tmdb_season_rows($data['seasons'] ?? []) : [],
    ];
}

/** @return list<array{id:int,season_number:int,name:string,episode_count:int}> */
function tmdb_season_rows($rows): array
{
    $out = [];
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['season_number'])) {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'season_number' => (int) $row['season_number'],
            'name' => (string) ($row['name'] ?? ''),
            'episode_count' => (int) ($row['episode_count'] ?? 0),
        ];
    }
    return $out;
}

/** @return list<array{id:int,season_number:int,name:string,episode_count:int}> */
function tmdb_show_seasons(int $seriesId): array
{
    if ($seriesId < 1) {
        return [];
    }
    $meta = function_exists('cache_read_title') ? cache_read_title($seriesId) : null;
    if (is_array($meta) && !empty($meta['seasons']) && is_array($meta['seasons'])) {
        return tmdb_season_rows($meta['seasons']);
    }
    $data = tmdb_get('/tv/' . $seriesId);
    usleep(TMDB_REQUEST_SLEEP_US);
    $seasons = tmdb_season_rows(is_array($data) ? ($data['seasons'] ?? []) : []);
    if ($seasons !== [] && is_array($meta)) {
        $meta['seasons'] = $seasons;
        cache_write_title($seriesId, $meta);
    }
    return $seasons;
}

function tmdb_fetch_details(int $tmdbId, string $mediaType = ''): ?array
{
    $primary = $mediaType === 'tv' ? 'tv' : 'movie';
    $path = $primary === 'tv' ? '/tv/' . $tmdbId : '/movie/' . $tmdbId;
    $data = tmdb_get($path, ['append_to_response' => 'credits']);
    $details = is_array($data) ? tmdb_details_from_data($data, $primary) : null;
    if ($details !== null) {
        return $details;
    }
    // Movie and TV ids are separate namespaces; only try the other type on a miss.
    $other = $primary === 'tv' ? 'movie' : 'tv';
    $otherPath = $other === 'tv' ? '/tv/' . $tmdbId : '/movie/' . $tmdbId;
    $otherData = tmdb_get($otherPath, ['append_to_response' => 'credits']);
    return is_array($otherData) ? tmdb_details_from_data($otherData, $other) : null;
}

function tmdb_season_cache_path(int $seriesId, int $season): string
{
    return CACHE_DIR . '/titles/tv-' . $seriesId . '-s' . $season . '.json';
}

function tmdb_season_cache_payload(array $data): array
{
    $episodes = [];
    foreach (is_array($data['episodes'] ?? null) ? $data['episodes'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $still = $row['still_path'] ?? null;
        $episodes[] = [
            'id' => (int) ($row['id'] ?? 0),
            'episode_number' => (int) ($row['episode_number'] ?? 0),
            'season_number' => (int) ($row['season_number'] ?? ($data['season_number'] ?? 0)),
            'name' => (string) ($row['name'] ?? ''),
            'overview' => (string) ($row['overview'] ?? ''),
            'still_path' => is_string($still) && $still !== '' ? $still : null,
        ];
    }
    return [
        'id' => (int) ($data['id'] ?? 0),
        'season_number' => (int) ($data['season_number'] ?? 0),
        'name' => (string) ($data['name'] ?? ''),
        'episodes' => $episodes,
    ];
}

function tmdb_remember_season(int $seriesId, int $season, array $data): void
{
    if ($seriesId < 1 || !function_exists('catalog_store_show_season_write')) {
        return;
    }
    $payload = tmdb_season_cache_payload($data);
    if ($payload['episodes'] === []) {
        return;
    }
    catalog_store_show_season_write($seriesId, $season, $payload);
}

function tmdb_fetch_season(int $seriesId, int $season): ?array
{
    static $mem = [];
    $key = $seriesId . ':' . $season;
    if (isset($mem[$key]) && is_array($mem[$key])) {
        return $mem[$key];
    }
    if (function_exists('catalog_store_show_season_read')) {
        $fromShow = catalog_store_show_season_read($seriesId, $season);
        if (is_array($fromShow) && is_array($fromShow['episodes'] ?? null) && $fromShow['episodes'] !== []) {
            tmdb_verbose_log(
                'TMDB season cache hit series=' . $seriesId
                . ' season=' . $season
                . ' source=show'
            );
            return $mem[$key] = $fromShow;
        }
    }
    $path = tmdb_season_cache_path($seriesId, $season);
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($cached) && isset($cached['episodes']) && is_array($cached['episodes'])) {
            tmdb_verbose_log(
                'TMDB season cache hit series=' . $seriesId
                . ' season=' . $season
                . ' id=' . (int) ($cached['id'] ?? 0)
            );
            tmdb_remember_season($seriesId, $season, $cached);
            return $mem[$key] = $cached;
        }
    }
    tmdb_verbose_log('TMDB season request series=' . $seriesId . ' season=' . $season);
    $data = tmdb_get('/tv/' . $seriesId . '/season/' . $season);
    usleep(TMDB_REQUEST_SLEEP_US);
    if (!is_array($data) || !isset($data['episodes']) || !is_array($data['episodes'])) {
        return null;
    }
    if (function_exists('cache_init')) {
        cache_init();
    }
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    tmdb_remember_season($seriesId, $season, $data);
    return $mem[$key] = $data;
}

function tmdb_norm_episode_name(string $s): string
{
    $s = function_exists('search_query_clean') ? search_query_clean($s) : trim($s);
    $s = function_exists('search_query_drop_article') ? search_query_drop_article($s) : $s;
    $s = preg_replace('/^episode\s+\d+\s*/i', '', $s) ?? $s;
    return tmdb_norm($s);
}

/**
 * Episode-title variants for season matching.
 * "01_Mole Hunt (Aka Pilot).mp4" → "Mole Hunt (Aka Pilot)" and "Mole Hunt".
 *
 * @return list<string>
 */
function tmdb_episode_title_variants(string $raw): array
{
    $name = str_replace('\\', '/', trim($raw));
    $name = basename($name);
    $name = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $name) ?? $name;
    $name = str_replace(['_', '.'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = trim($name, " \t-");
    $noLead = preg_replace('/^\d{1,3}\s+/', '', $name) ?? $name;
    $noLead = trim($noLead);
    $pieces = [];
    foreach ([$noLead, $name] as $piece) {
        if ($piece === '') {
            continue;
        }
        $pieces[] = $piece;
        $bare = trim((string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $piece));
        $bare = trim((string) preg_replace('/\s+/', ' ', $bare));
        if ($bare !== '') {
            $pieces[] = $bare;
        }
    }
    $out = [];
    $seen = [];
    foreach ($pieces as $piece) {
        $key = function_exists('lower') ? lower($piece) : strtolower($piece);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $piece;
    }
    return $out;
}

/**
 * @return array{episode_id:int,episode_title:string,overview:string,still_path:?string,season:int,episode:int}|null
 */
function tmdb_pick_season_episode(array $seasonData, ?int $episode, string $episodeTitle = ''): ?array
{
    $episodes = is_array($seasonData['episodes'] ?? null) ? $seasonData['episodes'] : [];
    $hit = null;
    if ($episode !== null && $episode >= 0) {
        foreach ($episodes as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['episode_number'] ?? -1) === $episode) {
                $hit = $row;
                break;
            }
        }
    }
    if ($hit === null && $episodeTitle !== '') {
        $wants = [];
        foreach (tmdb_episode_title_variants($episodeTitle) as $variant) {
            $want = tmdb_norm_episode_name($variant);
            if ($want !== '') {
                $wants[$want] = true;
            }
        }
        $direct = tmdb_norm_episode_name($episodeTitle);
        if ($direct !== '') {
            $wants[$direct] = true;
        }
        if ($wants !== []) {
            foreach ($episodes as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = tmdb_norm_episode_name((string) ($row['name'] ?? ''));
                if ($name !== '' && isset($wants[$name])) {
                    $hit = $row;
                    break;
                }
            }
        }
    }
    if (!is_array($hit) || empty($hit['id'])) {
        return null;
    }
    $still = $hit['still_path'] ?? null;
    return [
        'episode_id' => (int) $hit['id'],
        'episode_title' => trim((string) ($hit['name'] ?? '')),
        'overview' => trim((string) ($hit['overview'] ?? '')),
        'still_path' => is_string($still) && $still !== '' ? $still : null,
        'season' => (int) ($hit['season_number'] ?? ($seasonData['season_number'] ?? 0)),
        'episode' => (int) ($hit['episode_number'] ?? 0),
    ];
}

/**
 * After a TV series is identified, load that season and attach the episode
 * name/overview using season + episode numbers (or episode title).
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function tmdb_enrich_item_episode(array $item, int $seriesId): array
{
    if ($seriesId < 1) {
        return $item;
    }
    $season = isset($item['season']) && $item['season'] !== null && $item['season'] !== ''
        ? (int) $item['season']
        : null;
    $episode = isset($item['episode']) && $item['episode'] !== null && $item['episode'] !== ''
        ? (int) $item['episode']
        : null;
    $episodeTitle = trim((string) ($item['episode_title'] ?? ''));
    if ($season === null && ($episode !== null || $episodeTitle !== '')) {
        $season = 1;
    }
    if ($season === null || ($episode === null && $episodeTitle === '')) {
        return $item;
    }
    tmdb_verbose_log(
        'TMDB episode lookup series=' . $seriesId
        . ' season=' . $season
        . ' episode=' . (string) ($episode ?? '')
        . ' title=' . $episodeTitle
    );
    $seasonData = tmdb_fetch_season($seriesId, $season);
    if ($seasonData === null) {
        return $item;
    }
    $ep = tmdb_pick_season_episode($seasonData, $episode, $episodeTitle);
    if ($ep === null) {
        tmdb_verbose_log('TMDB episode miss series=' . $seriesId . ' s=' . $season . ' e=' . (string) ($episode ?? ''));
        return $item;
    }
    if ($ep['episode_title'] !== '') {
        $item['episode_title'] = $ep['episode_title'];
    }
    if ($ep['overview'] !== '') {
        $item['overview'] = $ep['overview'];
    }
    $item['season'] = $ep['season'];
    $item['episode'] = $ep['episode'];
    tmdb_verbose_log(
        'TMDB episode hit s' . $ep['season'] . 'e' . $ep['episode']
        . ' "' . $ep['episode_title'] . '"'
    );
    return $item;
}

function tmdb_lookup(string $title, ?int $year, string $filename = '', array $meta = []): ?array
{
    $title = trim($title);
    if (($title === '' && trim($filename) === '') || !tmdb_has_key() || !function_exists('curl_init')) {
        return null;
    }

    $hit = tmdb_search_movie($title, $year, $filename, $meta);
    if ($hit === null || empty($hit['id'])) {
        usleep(TMDB_REQUEST_SLEEP_US);
        return null;
    }

    $details = tmdb_fetch_details((int) $hit['id'], (string) ($hit['media_type'] ?? 'movie'));
    usleep(TMDB_REQUEST_SLEEP_US);
    return $details;
}

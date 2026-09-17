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
    tmdb_verbose_log('TMDB request ' . $path . ' params=' . (string) json_encode($loggedQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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

function tmdb_map_result(array $row): ?array
{
    $media = (string) ($row['media_type'] ?? 'movie');
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
    return [
        'tmdb_id' => $id,
        'title' => $title,
        'year' => strlen($release) >= 4 ? (int) substr($release, 0, 4) : null,
        'overview' => trim((string) ($row['overview'] ?? '')),
        'poster_path' => is_string($poster) && $poster !== '' ? $poster : null,
        'media_type' => $media === 'tv' ? 'tv' : 'movie',
    ];
}

function tmdb_map_results(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = tmdb_map_result($row);
        if ($mapped !== null) {
            $out[] = $mapped;
        }
    }
    return $out;
}

function tmdb_list_payload(?array $data): array
{
    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    return [
        'results' => tmdb_map_results($results),
        'page' => (int) ($data['page'] ?? 1),
        'total_pages' => max(1, (int) ($data['total_pages'] ?? 1)),
    ];
}

function tmdb_search_results(string $query, ?int $year, int $page = 1): array
{
    $query = trim($query);
    if ($query === '') {
        return ['results' => [], 'page' => 1, 'total_pages' => 1];
    }
    $params = [
        'query' => $query,
        'include_adult' => 'false',
        'page' => max(1, $page),
    ];
    if ($year !== null && $year >= 1870) {
        $params['year'] = (string) $year;
    }
    $data = tmdb_get('/search/movie', $params);
    $payload = tmdb_list_payload($data);
    if ($payload['results'] === [] && isset($params['year'])) {
        unset($params['year']);
        $payload = tmdb_list_payload(tmdb_get('/search/movie', $params));
        $payload['relaxed_year'] = true;
    }
    return $payload;
}

function tmdb_popular_results(int $page = 1): array
{
    return tmdb_list_payload(tmdb_get('/movie/popular', ['page' => max(1, $page)]));
}

function tmdb_movie_search_params(string $query, ?int $year): array
{
    $params = [
        'query' => $query,
        'include_adult' => 'false',
        'page' => 1,
    ];
    if ($year !== null && $year >= 1870) {
        $params['primary_release_year'] = (string) $year;
    }
    return $params;
}

/**
 * Try shared query variants against /search/movie. First non-empty result wins.
 * Year filter is used on the first attempt only. If every movie search is empty,
 * try /search/multi once with the cleanest title.
 *
 * @return array{results: list<array>, page: int, total_pages: int, query?: string, source?: string}
 */
function tmdb_search_first_results(string $title, ?int $year, string $filename = ''): array
{
    $empty = ['results' => [], 'page' => 1, 'total_pages' => 1];
    $queries = tmdb_search_queries($title, $filename, $year);
    if ($queries === []) {
        tmdb_verbose_log('TMDB query list empty for title=' . $title);
        return $empty;
    }
    $bestClean = $queries[0];
    $first = true;
    foreach ($queries as $q) {
        $attempts = [];
        if ($first && $year !== null && $year >= 1870) {
            $attempts[] = ['year' => $year, 'label' => 'year'];
            $attempts[] = ['year' => null, 'label' => 'no-year'];
        } else {
            $attempts[] = ['year' => null, 'label' => 'no-year'];
        }
        $first = false;
        foreach ($attempts as $attempt) {
            $params = tmdb_movie_search_params($q, $attempt['year']);
            $payload = tmdb_list_payload(tmdb_get('/search/movie', $params));
            $hits = count($payload['results']);
            $weak = function_exists('search_query_is_weak') && search_query_is_weak($q);
            tmdb_verbose_log('TMDB query "' . $q . '" (' . $attempt['label'] . ($weak ? ', weak' : '') . ') hits=' . $hits);
            if ($hits > 0 && $weak) {
                tmdb_verbose_log('TMDB weak query ignored: "' . $q . '"');
                usleep(TMDB_REQUEST_SLEEP_US);
                continue;
            }
            if ($hits > 0) {
                tmdb_verbose_log('TMDB query hit: "' . $q . '"');
                $payload['query'] = $q;
                $payload['source'] = 'movie';
                return $payload;
            }
            usleep(TMDB_REQUEST_SLEEP_US);
        }
    }

    tmdb_verbose_log('TMDB movie queries empty; trying /search/multi "' . $bestClean . '"');
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
        'results' => $mapped,
        'page' => 1,
        'total_pages' => 1,
        'query' => $bestClean,
        'source' => 'multi',
    ];
}

function tmdb_search_candidates(string $title, ?int $year, int $limit = 5, string $filename = ''): array
{
    $title = trim($title);
    if ($title === '' && trim($filename) === '') {
        return [];
    }
    if (!tmdb_has_key()) {
        return [];
    }
    $payload = tmdb_search_first_results($title, $year, $filename);
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

function tmdb_search_movie(string $title, ?int $year, string $filename = ''): ?array
{
    $payload = tmdb_search_first_results($title, $year, $filename);
    $results = [];
    foreach ($payload['results'] as $row) {
        if (!is_array($row) || (string) ($row['media_type'] ?? 'movie') === 'tv') {
            continue;
        }
        $results[] = [
            'id' => (int) ($row['tmdb_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'original_title' => (string) ($row['title'] ?? ''),
            'release_date' => !empty($row['year']) ? ((int) $row['year'] . '-01-01') : '',
        ];
    }
    $hit = tmdb_confident_hit($results, $title, $year);
    if ($hit === null && ($payload['query'] ?? '') !== '' && strcasecmp((string) $payload['query'], $title) !== 0) {
        $hit = tmdb_confident_hit($results, (string) $payload['query'], $year);
    }
    return $hit;
}

function tmdb_fetch_details(int $tmdbId): ?array
{
    $data = tmdb_get('/movie/' . $tmdbId, ['append_to_response' => 'credits']);
    if ($data === null || empty($data['id'])) {
        return null;
    }

    $release = (string) ($data['release_date'] ?? '');
    $year = strlen($release) >= 4 ? (int) substr($release, 0, 4) : null;
    $cast = [];
    $credits = is_array($data['credits']['cast'] ?? null) ? $data['credits']['cast'] : [];
    foreach ($credits as $person) {
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

    return [
        'tmdb_id' => (int) $data['id'],
        'title' => (string) ($data['title'] ?? $data['original_title'] ?? ''),
        'year' => $year,
        'overview' => trim((string) ($data['overview'] ?? '')),
        'poster_path' => is_string($poster) && $poster !== '' ? $poster : null,
        'cast' => $cast,
        'genres' => $genres,
    ];
}

function tmdb_lookup(string $title, ?int $year, string $filename = ''): ?array
{
    $title = trim($title);
    if (($title === '' && trim($filename) === '') || !tmdb_has_key() || !function_exists('curl_init')) {
        return null;
    }

    $hit = tmdb_search_movie($title, $year, $filename);
    if ($hit === null || empty($hit['id'])) {
        usleep(TMDB_REQUEST_SLEEP_US);
        return null;
    }

    $details = tmdb_fetch_details((int) $hit['id']);
    usleep(TMDB_REQUEST_SLEEP_US);
    return $details;
}

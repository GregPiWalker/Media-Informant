<?php
declare(strict_types=1);

require_once __DIR__ . '/parser.php';

/**
 * Shared TMDB query variants for scan auto-match and Grok candidate fetch.
 *
 * Shortest/cleanest strings are produced by stripping junk, not by sending
 * the raw filename. Year is never stuffed into the query text.
 *
 * Acceptance examples (expected variants must appear; raw junk must not):
 * - The Twilight Saga Breaking Dawn 1 (purchased)
 *     includes Breaking Dawn and a Twilight + Breaking Dawn variant;
 *     must not use the raw string with (purchased) as the only query.
 * - Star Wars Ep III Revenge of the Sith → includes Revenge of the Sith
 * - Resident Evil (4K) → Resident Evil
 * - Ghost in the Shell remastered → Ghost in the Shell
 * - Reno 911 Miami (Unrated Version) → Reno 911 Miami
 * - Wall-E.m4v → includes WALL-E or Wall-E, not only Wall E
 * - The Naked Gun 33 and a Third → includes The Naked Gun, Naked Gun, and a
 *     33 1/3 variant; must not win on “and a Third” / “33 and a Third”.
 * - TV: Show/Season 1/Rose.mkv → includes the show title from the grandparent
 *     folder, not only the episode filename.
 */

function search_query_junk_pattern(): string
{
    $words = [
        'purchased',
        'unrated',
        'remastered',
        'remaster',
        'extended',
        "director'?s?\s+cut",
        'directors\s+cut',
        'theatrical',
        'limited',
        'special\s+edition',
        'criterion',
        'proper',
        'repack',
        'internal',
        'multi',
        'complete',
        '4k',
        'uhd',
        'hdr10',
        'hdr',
        'dolby\s+vision',
        'dolby',
        '2160p',
        '1080p',
        '720p',
        'bluray',
        'blu-?ray',
        'webrip',
        'web-?dl',
        'hdtv',
        'dvdrip',
        'x264',
        'x265',
        'hevc',
        'aac',
        'ac3',
        'dts',
        'truehd',
        'hdr10\+?',
        'dv',
        'vision',
    ];
    return '/\b(?:' . implode('|', $words) . ')\b/iu';
}

function search_query_clean(string $raw): string
{
    $s = str_replace('\\', '/', $raw);
    $s = basename($s);
    $s = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $s) ?? $s;
    $s = preg_replace('/\([^)]*\)/', ' ', $s) ?? $s;
    $s = preg_replace('/\[[^\]]*\]/', ' ', $s) ?? $s;
    $s = str_replace(['.', '_'], ' ', $s);
    $s = preg_replace(search_query_junk_pattern(), ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s, " \t-");
}

function search_query_strip_episode_markers(string $s): string
{
    $s = preg_replace('/\bEp(?:isode)?\s*(?:[IVXLCDM]{1,6}|[1-9])\b/iu', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function search_query_strip_trailing_part(string $s): string
{
    $s = preg_replace('/\s+(?:part\s+)?(?:[1-9]|[IVX]{1,4})$/iu', '', $s) ?? $s;
    return trim($s);
}

function search_query_prefix_remainder(string $s): array
{
    $patterns = [
        '/^The\s+Twilight\s+Saga\s+/iu',
        '/^Twilight\s+Saga\s+/iu',
        '/^Star\s+Wars\s+/iu',
        '/^(?:The\s+)?Marvel(?:\s+Studios)?\s+/iu',
        '/^DC(?:\s+Comics|\s+Extended\s+Universe)?\s+/iu',
    ];
    $out = [];
    foreach ($patterns as $pattern) {
        $rest = preg_replace($pattern, '', $s, 1);
        if (!is_string($rest) || strcasecmp(trim($rest), $s) === 0) {
            continue;
        }
        $rest = trim($rest);
        $words = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 1) {
            continue;
        }
        $out[] = $rest;
    }
    return $out;
}

function search_query_stopword(string $w): bool
{
    return (bool) preg_match('/^(and|a|an|the|of|to|or|in|on|at|for)$/i', $w);
}

function search_query_number_word(string $w): bool
{
    $w = trim($w, '#.');
    if (preg_match('/^\d+$/', $w) || preg_match('/^[ivx]+$/i', $w) || preg_match('/^\d+\s*\/\s*\d+$/', $w)) {
        return true;
    }
    return (bool) preg_match('/^(one|two|three|four|five|six|seven|eight|nine|ten|first|second|third|fourth|fifth|half|part|pt|vol|volume|chapter|disc|disk|movie|film)$/i', $w);
}

function search_query_is_content_word(string $w): bool
{
    if (search_query_stopword($w) || search_query_number_word($w)) {
        return false;
    }
    $letters = preg_replace('/[^a-z]/i', '', $w) ?? '';
    return strlen($letters) >= 2;
}

function search_query_content_count(string $q): int
{
    $words = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = 0;
    foreach ($words as $w) {
        if (search_query_is_content_word($w)) {
            $n++;
        }
    }
    return $n;
}

function search_query_is_weak(string $q): bool
{
    $q = trim($q);
    if ($q === '') {
        return true;
    }
    if (preg_match('/[a-z]{2,}-[a-z]/i', $q)) {
        return false;
    }
    return search_query_content_count($q) < 2;
}

function search_query_peel_trailing(string $s): ?string
{
    $pattern = '/\s+(?:'
        . '\d+\s+and\s+a\s+(?:third|half)'
        . '|\d+\s*1\s*\/\s*[234]'
        . '|(?:part|pt\.?|vol(?:ume)?|chapter|disc|disk|movie|film|#)\s*(?:\d+|[ivx]{1,4})'
        . '|(?:\d+|[ivx]{1,4})'
        . ')\s*$/iu';
    $head = preg_replace($pattern, '', $s, 1);
    if (!is_string($head)) {
        return null;
    }
    $head = trim($head);
    if ($head === '' || strcasecmp($head, $s) === 0) {
        return null;
    }
    if (search_query_content_count($head) < 2) {
        return null;
    }
    return $head;
}

function search_query_drop_article(string $s): string
{
    return trim((string) preg_replace('/^(the|a|an)\s+/iu', '', $s));
}

function search_query_fraction_aliases(string $s): array
{
    $out = [];
    $third = preg_replace('/\b(\d+)\s+and\s+a\s+third\b/iu', '$1 1/3', $s);
    if (is_string($third) && strcasecmp($third, $s) !== 0) {
        $out[] = $third;
    }
    $half = preg_replace('/\b(\d+)\s+and\s+a\s+half\b/iu', '$1 1/2', $s);
    if (is_string($half) && strcasecmp($half, $s) !== 0) {
        $out[] = $half;
    }
    return $out;
}

function search_query_last_words(string $s): array
{
    $words = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($words !== [] && preg_match('/^(the|a|an)$/i', $words[0])) {
        array_shift($words);
    }
    $n = count($words);
    if ($n < 2) {
        return [];
    }
    $out = [];
    for ($k = min(4, $n); $k >= 2; $k--) {
        $tail = implode(' ', array_slice($words, -$k));
        if (!search_query_is_weak($tail)) {
            $out[] = $tail;
        }
    }
    return $out;
}

function search_query_hyphen_variants(string $title, string $raw): array
{
    $out = [];
    $sources = [$title, $raw];
    foreach ($sources as $src) {
        if (preg_match('/\b([A-Za-z]{2,})-([A-Za-z])\b/', $src, $m)) {
            $out[] = $m[1] . '-' . $m[2];
            $out[] = strtoupper($m[1]) . '-' . strtoupper($m[2]);
            $out[] = ucfirst(strtolower($m[1])) . '-' . strtoupper($m[2]);
        }
        if (preg_match('/(?:^|\s)([A-Za-z]{2,})\s+([A-Za-z])(?:\s|$)/', $src, $m)) {
            $out[] = $m[1] . '-' . $m[2];
            $out[] = strtoupper($m[1]) . '-' . strtoupper($m[2]);
            $out[] = ucfirst(strtolower($m[1])) . '-' . strtoupper($m[2]);
        }
    }
    return $out;
}

function search_query_path_parts(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $out = [];
    foreach ($parts as $part) {
        if ($part !== '' && $part !== '.') {
            $out[] = $part;
        }
    }
    return $out;
}

function search_query_is_season_folder(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (function_exists('season_folder_number') && season_folder_number($name) !== null) {
        return true;
    }
    return (bool) preg_match('/^(?:(?:seasons?|series)\s*\.?\s*\d{1,2}|specials|s\s*\.?\s*\d{1,2})$/i', $name);
}

function search_query_is_episode_code(string $s): bool
{
    $s = strtolower(str_replace(' ', '', search_query_clean($s)));
    return $s !== '' && (bool) preg_match('/^(?:s\d{1,2}e\d{1,3}|e\d{1,3}|\d{1,2}x\d{1,3}|\d{1,3})$/', $s);
}

function search_query_strip_episode_codes(string $s): string
{
    $s = preg_replace('/\bS\d{1,2}\s*E\d{1,3}\b/i', ' ', $s) ?? $s;
    $s = preg_replace('/\b\d{1,2}\s*x\s*\d{1,3}\b/i', ' ', $s) ?? $s;
    $s = preg_replace('/\b(?:e|ep|episode)\s*\d{1,3}\b/i', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function search_query_filename_looks_like_episode(string $name): bool
{
    $name = search_query_clean($name);
    if ($name === '') {
        return false;
    }
    if (preg_match('/\bS\d{1,2}\s*E\d{1,3}\b/i', $name) || preg_match('/\b\d{1,2}\s*x\s*\d{1,3}\b/i', $name)) {
        return true;
    }
    if (preg_match('/\b(?:e|ep|episode)\s*\d{1,3}\b/i', $name)) {
        return true;
    }
    return search_query_is_episode_code($name);
}

function search_query_folder_is_title(string $name): bool
{
    $name = trim($name);
    if ($name === '' || search_query_is_season_folder($name)) {
        return false;
    }
    if (function_exists('is_generic_folder') && is_generic_folder($name)) {
        return false;
    }
    $clean = search_query_clean($name);
    return $clean !== '' && !search_query_is_episode_code($clean);
}

/**
 * Show title from grandparent when parent is a season folder
 * (Show/Season 1/file.mkv). Season folder names are never returned.
 */
function search_query_tv_show_from_path(string $path): string
{
    $parts = search_query_path_parts($path);
    if ($parts === []) {
        return '';
    }
    $last = (string) array_pop($parts);
    if (!str_contains($last, '.')) {
        $parts[] = $last;
    }
    if ($parts === []) {
        return '';
    }
    $n = count($parts);
    $parent = $parts[$n - 1];
    $grand = $n >= 2 ? $parts[$n - 2] : '';
    $great = $n >= 3 ? $parts[$n - 3] : '';
    if (search_query_is_season_folder($parent) && search_query_folder_is_title($grand)) {
        return search_query_clean($grand);
    }
    if (search_query_is_season_folder($grand) && search_query_folder_is_title($great)) {
        return search_query_clean($great);
    }
    return '';
}

/**
 * Parent folder as show when there is no season directory
 * (Show/S01E05.mkv). Only used after the path is already known to be TV.
 */
function search_query_parent_show_from_path(string $path): string
{
    $fromSeason = search_query_tv_show_from_path($path);
    if ($fromSeason !== '') {
        return $fromSeason;
    }
    $parts = search_query_path_parts($path);
    if ($parts === []) {
        return '';
    }
    $last = (string) array_pop($parts);
    if (!str_contains($last, '.')) {
        $parts[] = $last;
    }
    if ($parts === []) {
        return '';
    }
    $parent = $parts[count($parts) - 1];
    if (!search_query_folder_is_title($parent)) {
        return '';
    }
    return search_query_clean($parent);
}

function search_query_path_is_tv(string $path, array $meta): bool
{
    if (($meta['kind'] ?? '') === 'show') {
        return true;
    }
    if (($meta['season'] ?? null) !== null || ($meta['episode'] ?? null) !== null) {
        return true;
    }
    if (search_query_tv_show_from_path($path) !== '') {
        return true;
    }
    $base = basename(str_replace('\\', '/', $path));
    return search_query_filename_looks_like_episode($base);
}

function search_query_episode_title_from_filename(string $filename, string $show = ''): string
{
    $s = search_query_strip_episode_codes(search_query_clean($filename));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s, " \t-");
    if ($show !== '') {
        $s = trim((string) preg_replace('/^' . preg_quote($show, '/') . '\s*/iu', '', $s));
        $noArt = search_query_drop_article($show);
        if ($noArt !== '' && strcasecmp($noArt, $show) !== 0) {
            $s = trim((string) preg_replace('/^' . preg_quote($noArt, '/') . '\s*/iu', '', $s));
        }
    }
    if ($s === '' || search_query_is_episode_code($s) || search_query_is_season_folder($s)) {
        return '';
    }
    return $s;
}

function search_query_push(array &$out, string $q, array &$seen, bool $allowWeak = false): void
{
    $q = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
    if ($q === '' || strlen($q) < 2) {
        return;
    }
    if (!$allowWeak && search_query_is_weak($q)) {
        return;
    }
    $key = lower($q);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $out[] = $q;
}

/**
 * @param array{kind?:string,path?:string,season?:?int,episode?:?int,episode_title?:string} $meta
 * @return list<string> unique queries, cascade order, max 6
 */
function tmdb_search_queries(string $parsedTitle, string $rawFilename = '', ?int $year = null, array $meta = []): array
{
    unset($year);
    $out = [];
    $seen = [];
    $path = (string) ($meta['path'] ?? $rawFilename);
    $isTv = search_query_path_is_tv($path, $meta);
    $showFromPath = $isTv ? search_query_parent_show_from_path($path) : search_query_tv_show_from_path($path);
    $episodeTitle = search_query_clean((string) ($meta['episode_title'] ?? ''));
    $cleanTitle = search_query_clean($parsedTitle);
    $cleanFile = search_query_strip_episode_codes(search_query_clean($rawFilename));

    if ($isTv) {
        $show = $cleanTitle;
        if ($showFromPath !== '' && ($show === '' || search_query_is_episode_code($show) || ($episodeTitle !== '' && strcasecmp($show, $episodeTitle) === 0))) {
            $show = $showFromPath;
        } elseif ($showFromPath !== '' && $show !== '' && search_query_is_weak($show)) {
            $show = $showFromPath;
        }
        if ($show === '' && $showFromPath !== '') {
            $show = $showFromPath;
        }
        if ($show !== '' && search_query_is_season_folder($show)) {
            $show = $showFromPath;
        }
        if ($episodeTitle === '' || search_query_is_episode_code($episodeTitle)) {
            $fromFile = search_query_episode_title_from_filename($rawFilename, $show);
            if ($fromFile !== '') {
                $episodeTitle = $fromFile;
            }
        }
        // Season folder names are detection-only, never sent as a query.
        search_query_push($out, $show, $seen, true);
        $noArticle = search_query_drop_article($show);
        search_query_push($out, $noArticle, $seen);
        if ($episodeTitle !== '' && strcasecmp($episodeTitle, $show) !== 0 && !search_query_is_episode_code($episodeTitle)) {
            search_query_push($out, trim($show . ' ' . $episodeTitle), $seen);
            search_query_push($out, $episodeTitle, $seen);
        }
        if ($cleanFile !== '' && !search_query_is_episode_code($cleanFile) && strcasecmp($cleanFile, $show) !== 0 && strcasecmp($cleanFile, $episodeTitle) !== 0) {
            $remainder = search_query_episode_title_from_filename($cleanFile, $show);
            if ($remainder !== '' && strcasecmp($remainder, $episodeTitle) !== 0) {
                search_query_push($out, trim($show . ' ' . $remainder), $seen);
            }
        }
        $primary = $show !== '' ? $show : $cleanTitle;
        if ($primary === '') {
            $primary = $cleanFile;
        }
    } else {
        $primary = $cleanTitle !== '' ? $cleanTitle : $cleanFile;
        if ($primary === '' && $cleanFile !== '') {
            $primary = $cleanFile;
        }
        search_query_push($out, $primary, $seen, true);
    }
    if (!$isTv && $cleanFile !== '' && strcasecmp($cleanFile, $primary) !== 0) {
        search_query_push($out, $cleanFile, $seen, true);
    }

    $peeled = search_query_peel_trailing($primary);
    if ($peeled !== null) {
        search_query_push($out, $peeled, $seen);
        $noArticle = search_query_drop_article($peeled);
        search_query_push($out, $noArticle, $seen);
        foreach (search_query_fraction_aliases($primary) as $frac) {
            search_query_push($out, $frac, $seen, true);
            $fracHead = search_query_drop_article($frac);
            search_query_push($out, $fracHead, $seen, true);
        }
    }

    $epStripped = search_query_strip_episode_markers($primary);
    search_query_push($out, $epStripped, $seen);
    foreach (search_query_prefix_remainder($primary) as $rest) {
        search_query_push($out, $rest, $seen);
        $restEp = search_query_strip_episode_markers($rest);
        search_query_push($out, $restEp, $seen);
        $noPart = search_query_strip_trailing_part($restEp !== '' ? $restEp : $rest);
        search_query_push($out, $noPart, $seen);
        $lead = trim(preg_replace('/' . preg_quote($rest, '/') . '$/iu', '', $primary) ?? '');
        $leadWords = preg_split('/\s+/', $lead, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $leadWords = array_values(array_filter($leadWords, static function ($w) {
            return !preg_match('/^(the|a|an)$/i', $w);
        }));
        $subWords = preg_split('/\s+/', $noPart, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($leadWords !== [] && $noPart !== '' && count($subWords) <= 4) {
            search_query_push($out, $leadWords[0] . ' ' . $noPart, $seen);
        }
    }
    foreach (search_query_prefix_remainder($epStripped) as $rest) {
        search_query_push($out, $rest, $seen);
        search_query_push($out, search_query_strip_trailing_part($rest), $seen);
    }

    $noPartPrimary = search_query_strip_trailing_part($epStripped !== '' ? $epStripped : $primary);
    search_query_push($out, $noPartPrimary, $seen);

    foreach (search_query_last_words($primary) as $tail) {
        search_query_push($out, $tail, $seen);
        search_query_push($out, search_query_strip_trailing_part($tail), $seen);
    }

    foreach (search_query_hyphen_variants($primary, $rawFilename . ' ' . $parsedTitle) as $hyph) {
        search_query_push($out, $hyph, $seen);
    }

    if (count($out) > 6) {
        $out = array_slice($out, 0, 6);
    }
    return $out;
}

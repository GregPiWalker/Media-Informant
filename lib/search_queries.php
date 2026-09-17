<?php
declare(strict_types=1);

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
 * @return list<string> unique queries, cascade order, max 6
 */
function tmdb_search_queries(string $parsedTitle, string $rawFilename = '', ?int $year = null): array
{
    unset($year);
    $out = [];
    $seen = [];
    $cleanTitle = search_query_clean($parsedTitle);
    $cleanFile = search_query_clean($rawFilename);
    $primary = $cleanTitle !== '' ? $cleanTitle : $cleanFile;
    if ($primary === '' && $cleanFile !== '') {
        $primary = $cleanFile;
    }

    search_query_push($out, $primary, $seen, true);
    if ($cleanFile !== '' && strcasecmp($cleanFile, $primary) !== 0) {
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

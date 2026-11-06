<?php
declare(strict_types=1);

/**
 * Checks MST3K file detection, direct URL order, catalog fallback picking,
 * and registration beside TMDB and RiffTrax.
 * Run from the project root: php tools/mst3k-lookup-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib/lookup.php';
require dirname(__DIR__) . '/lib/catalog.php';

$failed = 0;

function mst3k_test(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        echo "ok  {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

$names = array_keys(lookup_services());
mst3k_test($names === ['TMDB', 'RiffTrax', 'MST3K'], 'MST3K is the third selectable lookup service');
$service = lookup_service('mst3k');
mst3k_test($service instanceof Mst3kVideoLookupService && $service->source() === 'MST3K', 'lookup_service resolves MST3K');

$hand = mst3k_direct_urls('The Crawling Hand');
mst3k_test(
    ($hand[0] ?? '') === 'https://www.rifftrax.com/mst3k-the-crawling-hand',
    'The Crawling Hand tries the mst3k- slug first'
);
$plainAt = null;
$prefixed = true;
foreach ($hand as $i => $url) {
    if (!str_contains($url, '/mst3k-')) {
        $plainAt = $i;
        break;
    }
}
foreach ($hand as $i => $url) {
    if ($plainAt !== null && $i > $plainAt && str_contains($url, '/mst3k-')) {
        $prefixed = false;
    }
}
mst3k_test($plainAt !== null && $prefixed && in_array('https://www.rifftrax.com/the-crawling-hand', $hand, true), 'plain slugs are tried only after every mst3k- slug');
mst3k_test(in_array('https://www.rifftrax.com/mst3k-crawling-hand', $hand, true), 'a leading The is still an extra mst3k- slug');

$life = mst3k_direct_urls("It's a Wonderful Life");
$joined = array_search('https://www.rifftrax.com/mst3k-its-a-wonderful-life', $life, true);
$split = array_search('https://www.rifftrax.com/mst3k-it-s-a-wonderful-life', $life, true);
$plainLife = array_search('https://www.rifftrax.com/its-a-wonderful-life', $life, true);
mst3k_test($joined === 0 && $split !== false && $plainLife !== false && $joined < $split && $split < $plainLife, 'apostrophe slugs keep RiffTrax order inside the mst3k- prefix');

mst3k_test(
    mst3k_catalog_url('The Crawling Hand') === 'https://www.rifftrax.com/catalog/mst3k?search=The%20Crawling%20Hand',
    'catalog fallback is the MST3K search'
);
mst3k_test(
    mst3k_catalog_url("It's a Wonderful Life") === 'https://www.rifftrax.com/catalog/mst3k?search=It%27s%20a%20Wonderful%20Life',
    'catalog search keeps the apostrophe and encodes it'
);

$catalog = <<<'HTML'
<main>
  <a href="/alien-outlaw">Alien Outlaw</a>
  <a href="/mst3k-the-crawling-hand">The Crawling Hand</a>
  <a href="/the-crawling-hand">MST3K: The Crawling Hand</a>
  <a href="/gift-cards">Gift Cards</a>
</main>
HTML;
$picks = mst3k_catalog_picks($catalog, 'The Crawling Hand', ['the-crawling-hand', 'crawling-hand']);
$pickSlugs = array_column($picks, 'slug');
mst3k_test(in_array('mst3k-the-crawling-hand', $pickSlugs, true), 'catalog HTML keeps the mst3k- link');
mst3k_test(in_array('the-crawling-hand', $pickSlugs, true), 'a plain slug whose title is labeled MST3K is kept');
mst3k_test(!in_array('alien-outlaw', $pickSlugs, true), 'a non-MST3K catalog link is ignored');
mst3k_test(!in_array('gift-cards', $pickSlugs, true), 'footer-style links are ignored');

$index = [
    ['nid' => '10', 'title' => 'Alien Outlaw', 'alias' => 'alien-outlaw'],
    ['nid' => '11', 'title' => 'MST3K: Outlaw of Gor', 'alias' => 'mst3k-outlaw-of-gor'],
    ['nid' => '12', 'title' => 'Planet Outlaws', 'alias' => 'planet-outlaws'],
];
$scoped = mst3k_index_rows($index);
mst3k_test(array_column($scoped, 'nid') === ['11'], 'the index fallback keeps only MST3K rows');
$outlaw = rifftrax_index_picks($scoped, 'outlaw', ['outlaw']);
mst3k_test(array_column($outlaw, 'nid') === ['11'], 'scoped catalog search can still score a partial MST3K title');

$lookup = new Mst3kVideoLookupService();
$partial = new VideoMetadata('MST3K', 'MST3K: Outlaw of Gor', [], null, null, null, null, null, '', null, null, 'movie', '11');
mst3k_test($lookup->lookup('outlaw', null, ['hits' => [$partial]]) === null, 'one partial catalog title is not stored automatically');
$exact = new VideoMetadata('MST3K', 'MST3K: The Crawling Hand', [], null, null, null, null, null, '', null, null, 'movie', '99');
$kept = $lookup->lookup('The Crawling Hand', null, ['hits' => [$exact]]);
mst3k_test($kept !== null && $kept->lookupId === '99' && $kept->source === 'MST3K', 'MST3K: The Crawling Hand matches the stripped title');
$other = new VideoMetadata('MST3K', 'The Crawling Hand', [], null, null, null, null, null, '', null, null, 'movie', '100');
mst3k_test($lookup->lookup('The Crawling Hand', null, ['hits' => [$exact, $other]]) === null, 'two MST3K titles for one name stay ambiguous');

$product = mst3k_metadata_from_product([
    'title' => 'MST3K: The Crawling Hand',
    'nid' => '4242',
    'released' => '2016-08-05',
    'average_rating' => 80,
    'runtime' => 92,
    'field_description' => '<p>Joel and the bots watch.</p><p>Second paragraph.</p>',
    'source' => ['genre' => ['Science Fiction', 'Comedy']],
    'poster' => ['uri' => '/sites/default/files/hand.jpg'],
    'path' => '/mst3k-the-crawling-hand',
    'type' => 'riff',
]);
mst3k_test(
    $product !== null
        && $product->source === 'MST3K'
        && $product->releaseYear === 2016
        && $product->averageRating === 8.0
        && $product->runtime === 92
        && $product->genres === ['Science Fiction', 'Comedy']
        && str_contains($product->description, 'Joel and the bots watch.')
        && str_contains($product->description, 'Second paragraph.')
        && $product->posterUrl === 'https://www.rifftrax.com/sites/default/files/hand.jpg'
        && $product->detailUrl === 'https://www.rifftrax.com/mst3k-the-crawling-hand',
    'detail JSON reuses the RiffTrax fields and is labeled MST3K'
);

$root = '/volume1/video';
$folder = mst3k_identify($root, 'MST3K/The Crawling Hand.mp4');
mst3k_test(!empty($folder['match']) && ($folder['via'] ?? '') === 'folder' && ($folder['title'] ?? '') === 'The Crawling Hand', 'MST3K folder is detected');
$lower = mst3k_identify($root, 'mst3k/The Crawling Hand.mkv');
mst3k_test(!empty($lower['match']) && ($lower['via'] ?? '') === 'folder', 'mst3k folder case is ignored');
$long = mst3k_identify($root, 'Mystery Science Theater 3000/The Crawling Hand.mp4');
mst3k_test(!empty($long['match']) && ($long['via'] ?? '') === 'folder', 'Mystery Science Theater 3000 folder is detected');

$dash = mst3k_identify($root, 'MST3K - The Crawling Hand.mp4');
mst3k_test(!empty($dash['match']) && ($dash['via'] ?? '') === 'filename' && ($dash['title'] ?? '') === 'The Crawling Hand', 'MST3K - prefix is stripped');
$colon = mst3k_identify($root, 'Movies/MST3K: The Crawling Hand.mkv');
mst3k_test(!empty($colon['match']) && ($colon['via'] ?? '') === 'filename' && ($colon['title'] ?? '') === 'The Crawling Hand', 'MST3K: prefix is stripped');
$tight = mst3k_identify($root, 'MST3K:The Crawling Hand.mkv');
mst3k_test(!empty($tight['match']) && ($tight['title'] ?? '') === 'The Crawling Hand', 'MST3K: without a space is stripped');

$both = mst3k_identify($root, 'MST3K/MST3K - The Crawling Hand.mp4');
mst3k_test(!empty($both['match']) && ($both['via'] ?? '') === 'both', 'folder and filename together match');

$plain = mst3k_identify($root, 'Movies/Cool as Ice.mp4');
mst3k_test(empty($plain['match']) && empty($plain['fallback']), 'a normal movie is not MST3K');
$riff = mst3k_identify($root, 'RiffTrax/Cool as Ice.mp4');
mst3k_test(empty($riff['match']), 'a RiffTrax folder is not an MST3K match');
$deeper = mst3k_identify($root, 'Movies/MST3K/The Crawling Hand.mp4');
mst3k_test(empty($deeper['match']) && !empty($deeper['fallback']), 'a deeper MST3K folder is only a fallback');

$overlap = mst3k_identify($root, 'RiffTrax/MST3K - The Crawling Hand.mp4');
$overlapRiff = rifftrax_identify($root, 'RiffTrax/MST3K - The Crawling Hand.mp4');
mst3k_test(!empty($overlap['match']) && !empty($overlapRiff['match']), 'a prefixed file inside RiffTrax matches both detectors');

mst3k_test(catalog_movie_folder($root, 'MST3K - The Crawling Hand.mp4') === 'MST3K', 'a root MST3K - file joins the MST3K parent');
mst3k_test(catalog_movie_folder($root, 'MST3K: The Crawling Hand.mkv') === 'MST3K', 'a root MST3K: file joins the MST3K parent');
mst3k_test(catalog_movie_folder($root, 'RiffTrax - Cool as Ice.mp4') === 'RiffTrax', 'a root RiffTrax file still joins RiffTrax');
mst3k_test(
    catalog_movie_folder($root, 'Mystery Science Theater 3000/The Crawling Hand.mp4') === 'Mystery Science Theater 3000',
    'the on-disk folder name stays the list parent'
);
mst3k_test(mst3k_folder_name('mystery science theater 3000') && mst3k_folder_name('MST3K'), 'those folder labels select MST3K when nothing is saved');

if ($failed > 0) {
    echo "{$failed} failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);

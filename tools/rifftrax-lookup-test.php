<?php
declare(strict_types=1);

/**
 * Checks RiffTrax slug variants, catalog-result picking, and file detection.
 * Run from the project root: php tools/rifftrax-lookup-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib/lookup.php';

$failed = 0;

function rifftrax_test(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        echo "ok  {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

$life = rifftrax_slug_variants("It's a Wonderful Life");
rifftrax_test(($life[0] ?? '') === 'its-a-wonderful-life', 'apostrophe is removed for the first slug');
rifftrax_test(in_array('it-s-a-wonderful-life', $life, true), 'apostrophe-to-hyphen slug is still tried');
$joinedAt = array_search('its-a-wonderful-life', $life, true);
$splitAt = array_search('it-s-a-wonderful-life', $life, true);
rifftrax_test($joinedAt !== false && $splitAt !== false && $joinedAt < $splitAt, 'joined slug is tried before the hyphenated one');

$curly = rifftrax_slug_variants("It\u{2019}s a Wonderful Life");
rifftrax_test(($curly[0] ?? '') === 'its-a-wonderful-life', 'curly apostrophe joins the letters');

$withYear = rifftrax_slug_variants("It's a Wonderful Life (1946)");
rifftrax_test(($withYear[0] ?? '') === 'its-a-wonderful-life', 'trailing year is not part of the slug');

$qualified = rifftrax_slug_variants("It's a Wonderful Life (Special RiffTrax Edit)");
rifftrax_test(($qualified[0] ?? '') === 'its-a-wonderful-life', 'a trailing parenthetical is not part of the slug');
rifftrax_test(
    rifftrax_title_close("It's a Wonderful Life", "It's a Wonderful Life (Special RiffTrax Edit)"),
    'Special RiffTrax Edit still matches the movie title'
);

rifftrax_test(rifftrax_slug_variants('Cool as Ice') === ['cool-as-ice'], 'Cool as Ice keeps a single slug');

$thing = rifftrax_slug_variants('The Thing');
rifftrax_test(($thing[0] ?? '') === 'the-thing' && in_array('thing', $thing, true), 'a leading The is an extra slug');

$bill = rifftrax_slug_variants('Bill & Ted');
rifftrax_test(($bill[0] ?? '') === 'bill-and-ted' && in_array('bill-ted', $bill, true), 'ampersand becomes and, then is dropped');

$catalog = <<<'HTML'
<main>
  <p>Showing 2 Result(s)</p>
  <a href="/iriff/its-a-wonderful-life">It's a Wonderful Life</a>
  <a href="/its-a-wonderful-life">It's a Wonderful Life</a>
  <a href="/cool-as-ice">Cool as Ice</a>
</main>
<footer><a href="/gift-cards">Gift Cards</a></footer>
HTML;
$choices = rifftrax_catalog_choices($catalog, "It's a Wonderful Life", ['it-s-a-wonderful-life']);
rifftrax_test(
    ($choices[0]['slug'] ?? '') === 'its-a-wonderful-life' && ($choices[0]['iriff'] ?? true) === false,
    'catalog links prefer the official page when the direct slug missed'
);
$choiceSlugs = array_column($choices, 'slug');
rifftrax_test(!in_array('cool-as-ice', $choiceSlugs, true), 'an unrelated catalog link is ignored');
rifftrax_test(!in_array('gift-cards', $choiceSlugs, true), 'footer links are ignored');

rifftrax_test(
    rifftrax_catalog_choices('<main><p>Showing 0 Result(s)</p></main>', 'No Such Movie', ['no-such-movie']) === [],
    'catalog page with no product links is a miss'
);

$index = [
    ['nid' => '2', 'title' => "It's a Wonderful Life", 'alias' => 'iriff/its-a-wonderful-life'],
    ['nid' => '1', 'title' => "It's a Wonderful Life", 'alias' => 'its-a-wonderful-life'],
    ['nid' => '3', 'title' => 'Cool as Ice', 'alias' => 'cool-as-ice'],
    ['nid' => '4', 'title' => 'Night of the Living Dead 2', 'alias' => 'night-of-the-living-dead-2'],
];
$picks = rifftrax_index_picks($index, "It's a Wonderful Life", ['it-s-a-wonderful-life']);
rifftrax_test(($picks[0]['nid'] ?? '') === '1' && ($picks[0]['official'] ?? false) === true, 'index prefers the official title');
rifftrax_test(($picks[0]['distance'] ?? 1) === 0.0, 'exact catalog title has distance 0');
$pickIds = array_column($picks, 'nid');
rifftrax_test(!in_array('3', $pickIds, true), 'index does not return a different movie');

$sequel = rifftrax_index_picks($index, 'Night of the Living Dead', ['night-of-the-living-dead']);
rifftrax_test(($sequel[0]['nid'] ?? '') === '4', 'a longer title that contains the query is a catalog hit');
rifftrax_test(($sequel[0]['distance'] ?? 0.0) > 0.0, 'a numbered sequel is not an exact title');

$outlaw = [
    ['nid' => '10', 'title' => 'Alien Outlaw', 'alias' => 'alien-outlaw', 'description' => 'rural gunfighting'],
    ['nid' => '11', 'title' => 'MST3K: Outlaw of Gor', 'alias' => 'mst3k-outlaw-of-gor', 'description' => ''],
    ['nid' => '12', 'title' => 'Planet Outlaws (with Matthew J. Elliott and Ian Potter)', 'alias' => 'planet-outlaws', 'description' => ''],
    ['nid' => '13', 'title' => 'The Galaxy Invader', 'alias' => 'the-galaxy-invader', 'description' => 'backwoods town rednecks hygiene outlawed mayor'],
    ['nid' => '14', 'title' => 'Cool as Ice', 'alias' => 'cool-as-ice', 'description' => 'skating'],
];
$outlawPicks = rifftrax_index_picks($outlaw, 'outlaw', ['outlaw']);
rifftrax_test(array_column($outlawPicks, 'nid') === ['10', '11', '12', '13'], 'outlaw matches the partial catalog titles and a description');
rifftrax_test(!in_array('14', array_column($outlawPicks, 'nid'), true), 'an unrelated title stays out of the outlaw search');

$service = new RiffTraxVideoLookupService();
$partial = new VideoMetadata(source: 'RiffTrax', title: 'Alien Outlaw', lookupId: '10', productKind: 'riff');
rifftrax_test(
    $service->lookup('outlaw', null, ['hits' => [$partial]]) === null,
    'one partial catalog title is not stored automatically'
);
$exact = new VideoMetadata(source: 'RiffTrax', title: 'Alien Outlaw', lookupId: '10', productKind: 'riff');
$kept = $service->lookup('Alien Outlaw', null, ['hits' => [$exact]]);
rifftrax_test($kept !== null && $kept->lookupId === '10', 'one exact catalog title is kept');

$typoIndex = [
    ['nid' => '8', 'title' => 'Cool as Ice', 'alias' => 'cool-as-ice'],
];
$typo = rifftrax_index_picks($typoIndex, 'Cool as Icx', ['cool-as-icx']);
rifftrax_test(($typo[0]['nid'] ?? '') === '8', 'one changed letter still matches the catalog index');

rifftrax_test(
    rifftrax_index_picks($index, 'Zzzzz Not A Movie', ['zzzzz-not-a-movie']) === [],
    'unknown title is a complete miss'
);

$folder = rifftrax_identify('/volume1/video', 'RiffTrax/Cool as Ice.mp4');
rifftrax_test(!empty($folder['match']) && ($folder['via'] ?? '') === 'folder', 'RiffTrax folder is detected');

$named = rifftrax_identify('/volume1/video', "RiffTrax - It's a Wonderful Life.mp4");
rifftrax_test(!empty($named['match']) && ($named['via'] ?? '') === 'filename', 'RiffTrax filename is detected');
rifftrax_test(str_contains((string) ($named['title'] ?? ''), 'Wonderful'), 'filename prefix is stripped');

$both = rifftrax_identify('/volume1/video', "RiffTrax/RiffTrax - It's a Wonderful Life.mkv");
rifftrax_test(!empty($both['match']) && ($both['via'] ?? '') === 'both', 'folder and filename together match');

$plain = rifftrax_identify('/volume1/video', 'Movies/Cool as Ice.mp4');
rifftrax_test(empty($plain['match']), 'a normal movie folder is not RiffTrax');

if ($failed > 0) {
    echo "{$failed} failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);

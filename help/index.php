<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/help.php';

$topics = help_topics();
$requested = isset($_GET['topic']) && is_string($_GET['topic']) ? $_GET['topic'] : '';
$topic = $requested !== '' ? help_topic($requested) : null;
$missing = $requested !== '' && $topic === null;
$bodyPath = is_array($topic) ? help_topic_body_path($topic) : '';
if (is_array($topic) && $bodyPath === '') {
    $topic = null;
    $missing = true;
}
$pageTitle = is_array($topic)
    ? ((string) $topic['title'] . ' · Help · Media Informant')
    : 'Help · Media Informant';

render_start($pageTitle);
render_header(['section' => 'help']);
require app_view('help');
render_end();

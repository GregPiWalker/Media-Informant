<?php
declare(strict_types=1);

/** @var list<array{id: string, title: string, summary: string, body: string}> $topics */
/** @var array{id: string, title: string, summary: string, body: string}|null $topic */
/** @var bool $missing */
/** @var string $bodyPath */

$topics = is_array($topics ?? null) ? $topics : [];
$topic = is_array($topic ?? null) ? $topic : null;
$missing = !empty($missing);
$bodyPath = (string) ($bodyPath ?? '');
?>
<main class="page help-page">
  <div class="help-layout">
    <nav class="help-index" id="help-topics" aria-labelledby="help-index-title">
      <p class="help-kicker">Manual</p>
      <h1 id="help-index-title">Help</h1>
      <p class="help-index-lead">Pick a topic. New sections are added to this list.</p>
      <?php if ($topics === []): ?>
      <p class="hint">No topics yet.</p>
      <?php else: ?>
      <ol class="help-topic-list">
        <?php foreach ($topics as $entry):
            $id = (string) ($entry['id'] ?? '');
            $current = $topic !== null && (string) ($topic['id'] ?? '') === $id;
            ?>
        <li>
          <a class="help-topic-link<?= $current ? ' is-current' : '' ?>" href="<?= h(app_href('help/index.php?topic=' . rawurlencode($id))) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
            <span class="help-topic-title"><?= h((string) ($entry['title'] ?? 'Topic')) ?></span>
            <span class="help-topic-summary"><?= h((string) ($entry['summary'] ?? '')) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </nav>
    <article class="help-article">
      <?php if ($missing): ?>
      <p class="help-kicker">Help</p>
      <h1>Topic not found</h1>
      <p>That section is not in the manual. Choose one from the topic list.</p>
      <?php elseif ($topic === null): ?>
      <p class="help-kicker">Help</p>
      <h1>Choose a topic</h1>
      <p>The list names each section of the manual. Open one to read it. On a wide screen the list stays beside the article.</p>
      <?php else: ?>
      <p class="help-article-jump"><a href="#help-topics">All topics</a></p>
      <?php require $bodyPath; ?>
      <?php endif; ?>
    </article>
  </div>
</main>

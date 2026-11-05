<?php
declare(strict_types=1);

/**
 * Help topics, in index order.
 *
 * Add a section by appending one entry and a view at views/mobile/help/{body}.php.
 *
 * @return list<array{id: string, title: string, summary: string, body: string}>
 */
function help_topics(): array
{
    return [
        [
            'id' => 'scan',
            'title' => 'Scan',
            'summary' => 'How a video scan chooses files, looks them up, and asks Grok.',
            'body' => 'scan',
        ],
    ];
}

/**
 * @return array{id: string, title: string, summary: string, body: string}|null
 */
function help_topic(string $id): ?array
{
    foreach (help_topics() as $topic) {
        if ($topic['id'] === $id) {
            return $topic;
        }
    }
    return null;
}

function help_topic_body_path(array $topic): string
{
    $body = (string) ($topic['body'] ?? '');
    if ($body === '' || !preg_match('/^[a-z0-9-]+$/', $body)) {
        return '';
    }
    $path = APP_ROOT . '/views/mobile/help/' . $body . '.php';
    return is_file($path) ? $path : '';
}

<?php
declare(strict_types=1);

/**
 * One active multi-turn chat, stored in cache/chat.sqlite.
 * Feature code (Smart Search and later tools) owns the prompt and the job payload.
 */

require_once __DIR__ . '/config.php';

function chat_db_path(): string
{
    return CACHE_DIR . '/chat.sqlite';
}

function chat_pdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . chat_db_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=4000');
        chat_migrate($pdo);
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
    return $pdo;
}

function chat_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
    $row = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    $current = is_array($row) ? (int) ($row['version'] ?? 0) : 0;
    if ($current >= 1) {
        return;
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS chats (
  id TEXT PRIMARY KEY,
  feature TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'idle',
  progress TEXT NOT NULL DEFAULT '',
  error TEXT NOT NULL DEFAULT '',
  cost_usd REAL NOT NULL DEFAULT 0,
  turn_cost_usd REAL NOT NULL DEFAULT 0,
  prompt_tokens INTEGER NOT NULL DEFAULT 0,
  completion_tokens INTEGER NOT NULL DEFAULT 0,
  cached_tokens INTEGER NOT NULL DEFAULT 0,
  context_json TEXT
);
CREATE TABLE IF NOT EXISTS chat_messages (
  id INTEGER PRIMARY KEY,
  chat_id TEXT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
  role TEXT NOT NULL,
  body TEXT NOT NULL,
  results_json TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS chat_messages_chat ON chat_messages (chat_id, id);
CREATE TABLE IF NOT EXISTS chat_jobs (
  chat_id TEXT PRIMARY KEY REFERENCES chats(id) ON DELETE CASCADE,
  phase TEXT NOT NULL,
  batch_index INTEGER NOT NULL DEFAULT 0,
  batch_total INTEGER NOT NULL DEFAULT 0,
  payload_json TEXT NOT NULL DEFAULT '{}'
);
SQL);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
    if ($count < 1) {
        $pdo->prepare('INSERT INTO schema_version (version) VALUES (1)')->execute();
    } else {
        $pdo->prepare('UPDATE schema_version SET version = 1')->execute();
    }
}

function chat_id_ok(string $id): bool
{
    return preg_match('/^[a-f0-9]{16}$/', $id) === 1;
}

function chat_new_id(): string
{
    return bin2hex(random_bytes(8));
}

/** @return array<string, mixed>|null */
function chat_load(string $id): ?array
{
    if (!chat_id_ok($id)) {
        return null;
    }
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM chats WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $messages = [];
    $msg = $pdo->prepare('SELECT role, body, results_json, created_at FROM chat_messages WHERE chat_id = ? ORDER BY id ASC');
    $msg->execute([$id]);
    foreach ($msg->fetchAll() as $item) {
        if (!is_array($item)) {
            continue;
        }
        $results = [];
        $raw = $item['results_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $results = $decoded;
            }
        }
        $messages[] = [
            'role' => (string) ($item['role'] ?? ''),
            'body' => (string) ($item['body'] ?? ''),
            'results' => $results,
            'created_at' => (int) ($item['created_at'] ?? 0),
        ];
    }
    $job = null;
    $jobStmt = $pdo->prepare('SELECT phase, batch_index, batch_total, payload_json FROM chat_jobs WHERE chat_id = ?');
    $jobStmt->execute([$id]);
    $jobRow = $jobStmt->fetch();
    if (is_array($jobRow)) {
        $payload = json_decode((string) ($jobRow['payload_json'] ?? ''), true);
        $job = [
            'phase' => (string) ($jobRow['phase'] ?? ''),
            'batch_index' => (int) ($jobRow['batch_index'] ?? 0),
            'batch_total' => (int) ($jobRow['batch_total'] ?? 0),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }
    $context = json_decode((string) ($row['context_json'] ?? ''), true);
    return [
        'id' => (string) $row['id'],
        'feature' => (string) ($row['feature'] ?? ''),
        'status' => (string) ($row['status'] ?? 'idle'),
        'progress' => (string) ($row['progress'] ?? ''),
        'error' => (string) ($row['error'] ?? ''),
        'cost_usd' => (float) ($row['cost_usd'] ?? 0),
        'turn_cost_usd' => (float) ($row['turn_cost_usd'] ?? 0),
        'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
        'cached_tokens' => (int) ($row['cached_tokens'] ?? 0),
        'context' => is_array($context) ? $context : [],
        'messages' => $messages,
        'job' => $job,
    ];
}

function chat_create(string $feature): ?array
{
    $feature = strtolower(trim($feature));
    if (preg_match('/^[a-z0-9-]{1,40}$/', $feature) !== 1) {
        return null;
    }
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    $id = chat_new_id();
    $now = time();
    $pdo->prepare('INSERT INTO chats (id, feature, created_at, updated_at, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $feature, $now, $now, 'idle']);
    return chat_load($id);
}

function chat_touch(string $id): void
{
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO || !chat_id_ok($id)) {
        return;
    }
    $pdo->prepare('UPDATE chats SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
}

/** @param array<string, scalar|null> $fields */
function chat_set(string $id, array $fields): void
{
    if (!chat_id_ok($id)) {
        return;
    }
    $allowed = ['status' => 's', 'progress' => 's', 'error' => 's', 'context_json' => 's'];
    $sets = [];
    $values = [];
    foreach ($allowed as $name => $kind) {
        if (!array_key_exists($name, $fields)) {
            continue;
        }
        $sets[] = $name . ' = ?';
        $values[] = $fields[$name] === null ? null : (string) $fields[$name];
    }
    if ($sets === []) {
        return;
    }
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $sets[] = 'updated_at = ?';
    $values[] = time();
    $values[] = $id;
    $pdo->prepare('UPDATE chats SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
}

/** @param list<array<string, mixed>> $context */
function chat_set_context(string $id, array $context): void
{
    $json = json_encode(array_values($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    chat_set($id, ['context_json' => $json === false ? '[]' : $json]);
}

/**
 * @param list<array<string, mixed>>|null $results
 */
function chat_add_message(string $id, string $role, string $body, ?array $results = null): bool
{
    if (!chat_id_ok($id) || ($role !== 'user' && $role !== 'assistant')) {
        return false;
    }
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $exists = $pdo->prepare('SELECT 1 FROM chats WHERE id = ?');
    $exists->execute([$id]);
    if ($exists->fetchColumn() === false) {
        return false;
    }
    $json = null;
    if ($results !== null) {
        $encoded = json_encode(array_values($results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $encoded === false ? '[]' : $encoded;
    }
    $pdo->prepare('INSERT INTO chat_messages (chat_id, role, body, results_json, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $role, $body, $json, time()]);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE chat_id = ?');
    $countStmt->execute([$id]);
    $count = (int) $countStmt->fetchColumn();
    $drop = $count - 80;
    if ($drop > 0) {
        $pdo->prepare('DELETE FROM chat_messages WHERE chat_id = ? AND id IN (SELECT id FROM chat_messages WHERE chat_id = ? ORDER BY id ASC LIMIT ' . $drop . ')')
            ->execute([$id, $id]);
    }
    chat_touch($id);
    return true;
}

/** @param array<string, mixed> $payload */
function chat_save_job(string $id, string $phase, int $index, int $total, array $payload): void
{
    if (!chat_id_ok($id)) {
        return;
    }
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{}';
    }
    $pdo->prepare('INSERT INTO chat_jobs (chat_id, phase, batch_index, batch_total, payload_json) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(chat_id) DO UPDATE SET phase = excluded.phase, batch_index = excluded.batch_index, batch_total = excluded.batch_total, payload_json = excluded.payload_json')
        ->execute([$id, $phase, $index, $total, $json]);
    chat_touch($id);
}

function chat_clear_job(string $id): void
{
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO || !chat_id_ok($id)) {
        return;
    }
    $pdo->prepare('DELETE FROM chat_jobs WHERE chat_id = ?')->execute([$id]);
}

function chat_reset_turn_cost(string $id): void
{
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO || !chat_id_ok($id)) {
        return;
    }
    $pdo->prepare('UPDATE chats SET turn_cost_usd = 0, updated_at = ? WHERE id = ?')->execute([time(), $id]);
}

/** @param array<string, mixed> $usage */
function chat_add_cost(string $id, float $usd, array $usage): void
{
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO || !chat_id_ok($id)) {
        return;
    }
    if ($usd < 0) {
        $usd = 0;
    }
    $pdo->prepare('UPDATE chats SET
        cost_usd = cost_usd + ?,
        turn_cost_usd = turn_cost_usd + ?,
        prompt_tokens = prompt_tokens + ?,
        completion_tokens = completion_tokens + ?,
        cached_tokens = cached_tokens + ?,
        updated_at = ?
        WHERE id = ?')->execute([
        $usd,
        $usd,
        max(0, (int) ($usage['prompt'] ?? 0)),
        max(0, (int) ($usage['completion'] ?? 0)),
        max(0, (int) ($usage['cached'] ?? 0)),
        time(),
        $id,
    ]);
}

function chat_delete(string $id): void
{
    $pdo = chat_pdo();
    if (!$pdo instanceof PDO || !chat_id_ok($id)) {
        return;
    }
    $pdo->prepare('DELETE FROM chats WHERE id = ?')->execute([$id]);
}

function chat_cookie_name(): string
{
    return 'media_smart_chat';
}

function chat_cookie_id(): string
{
    $raw = $_COOKIE[chat_cookie_name()] ?? '';
    $id = is_string($raw) ? strtolower(trim($raw)) : '';
    return chat_id_ok($id) ? $id : '';
}

function chat_cookie_set(string $id): void
{
    if (!chat_id_ok($id)) {
        return;
    }
    setcookie(chat_cookie_name(), $id, [
        'expires' => time() + 2592000,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[chat_cookie_name()] = $id;
}

function chat_discard_path(string $id): string
{
    return CACHE_DIR . '/chat-discard-' . $id;
}

function chat_mark_discard(string $id): void
{
    if (!chat_id_ok($id)) {
        return;
    }
    if (is_dir(CACHE_DIR) || @mkdir(CACHE_DIR, 0775, true)) {
        @file_put_contents(chat_discard_path($id), '1', LOCK_EX);
    }
    chat_set($id, ['status' => 'discard', 'progress' => '']);
}

function chat_discard_marked(string $id): bool
{
    return chat_id_ok($id) && is_file(chat_discard_path($id));
}

function chat_clear_discard_mark(string $id): void
{
    $path = chat_discard_path($id);
    if (is_file($path)) {
        @unlink($path);
    }
}

function chat_cookie_clear(): void
{
    setcookie(chat_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[chat_cookie_name()]);
}

function chat_format_usd(float $usd): string
{
    if (function_exists('grok_format_usd')) {
        return grok_format_usd($usd);
    }
    if ($usd <= 0) {
        return '$0.0000';
    }
    $places = $usd < 0.0001 ? 6 : 4;
    return '$' . number_format($usd, $places, '.', '');
}

/** @return array{fh: resource|null, held: bool} */
function &chat_lock_slot(): array
{
    static $slot = ['fh' => null, 'held' => false];
    return $slot;
}

function chat_lock_begin(): bool
{
    $slot = &chat_lock_slot();
    if ($slot['held']) {
        return true;
    }
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
        return false;
    }
    $fh = fopen(CACHE_DIR . '/chat.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    $slot['fh'] = $fh;
    $slot['held'] = true;
    return true;
}

function chat_lock_end(): void
{
    $slot = &chat_lock_slot();
    if (is_resource($slot['fh'])) {
        flock($slot['fh'], LOCK_UN);
        fclose($slot['fh']);
    }
    $slot['fh'] = null;
    $slot['held'] = false;
}

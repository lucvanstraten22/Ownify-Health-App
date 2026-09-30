<?php
/**
 * A stand-in for the Gemini API, for tools/ai-test.php only:
 *
 *     FAKE_GEMINI_DIR=/tmp/x php -S 127.0.0.1:8400 tools/ai-fake-gemini.php
 *
 * Answers each request with the next answer the test queued in
 * $FAKE_GEMINI_DIR/queue.json — a status, a body, and optionally a delay —
 * and writes down what it was sent in $FAKE_GEMINI_DIR/requests.jsonl: the
 * path, the API key header and the request body. That is how the test sees
 * what Ownify would have sent to Google, without Google and without a key.
 *
 * Only the built-in server runs this, and only with FAKE_GEMINI_DIR set.
 * Anywhere else it answers 404, and tools/.htaccess refuses the directory.
 */
declare(strict_types=1);

$dir = getenv('FAKE_GEMINI_DIR');

if (PHP_SAPI !== 'cli-server' || !is_string($dir) || $dir === '' || !is_dir($dir)) {
    http_response_code(404);
    exit;
}

$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

$queueFile = $dir . '/queue.json';
/* Decoded as objects, so an empty object the test queued stays {}. */
$queue     = is_file($queueFile) ? (array) json_decode((string) file_get_contents($queueFile)) : [];
$next      = array_shift($queue);
file_put_contents($queueFile, json_encode($queue));

file_put_contents($dir . '/requests.jsonl', json_encode([
    'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'key'  => (string) ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? ''),
    'body' => json_decode((string) file_get_contents('php://input')),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

flock($lock, LOCK_UN);

$next ??= json_decode(json_encode([
    'status' => 200,
    'body'   => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Standaardantwoord van de testserver.']]], 'finishReason' => 'STOP']]],
]));

if (isset($next->sleep)) {
    sleep((int) $next->sleep);
}

http_response_code((int) ($next->status ?? 200));
header('Content-Type: application/json');
echo isset($next->raw) ? (string) $next->raw : json_encode($next->body ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

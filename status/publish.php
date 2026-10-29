<?php
declare(strict_types=1);

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, 'POST required');
}

$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
if ($https !== 'on' && $https !== '1' && (string) ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
    $respond(403, 'HTTPS required');
}

$configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private'
    . DIRECTORY_SEPARATOR . 'status-publisher.php';
if (!is_file($configPath)) {
    $respond(503, 'Publisher is not configured');
}

try {
    $config = require $configPath;
} catch (Throwable) {
    $respond(503, 'Publisher is not configured');
}
$expectedToken = is_array($config) ? ($config['token'] ?? null) : null;
if (!is_string($expectedToken) || !preg_match('/^[a-f0-9]{64}$/D', $expectedToken)) {
    $respond(503, 'Publisher is not configured');
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $headers['Authorization']
    ?? $headers['authorization']
    ?? '');
if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $matches)
    || !hash_equals($expectedToken, $matches[1])) {
    $respond(401, 'Unauthorized');
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) {
    $respond(413, 'Payload too large');
}
$body = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($body) || strlen($body) > 4096) {
    $respond(413, 'Payload too large');
}

try {
    $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $respond(400, 'Invalid JSON');
}
if (!is_array($payload)
    || array_diff(array_keys($payload), ['updatedAt', 'online', 'players'])
    || count($payload) !== 3
    || ($payload['online'] ?? null) !== true
    || !is_string($payload['updatedAt'] ?? null)
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $payload['updatedAt'])) {
    $respond(422, 'Invalid status snapshot');
}

try {
    $updatedAt = new DateTimeImmutable($payload['updatedAt']);
} catch (Throwable) {
    $respond(422, 'Invalid status timestamp');
}
$ageSeconds = time() - $updatedAt->getTimestamp();
if ($ageSeconds < -60 || $ageSeconds > 300) {
    $respond(422, 'Status timestamp is outside the accepted window');
}

$players = $payload['players'] ?? null;
if (!is_array($players) || !array_is_list($players) || count($players) > 20) {
    $respond(422, 'Invalid player list');
}
foreach ($players as $index => $player) {
    $expectedAlias = sprintf('Sobreviviente %02d', $index + 1);
    if (!is_array($player)
        || array_keys($player) !== ['alias']
        || ($player['alias'] ?? null) !== $expectedAlias) {
        $respond(422, 'Invalid player list');
    }
}

$publicSnapshot = json_encode([
    'updatedAt' => $payload['updatedAt'],
    'online' => true,
    'players' => $players,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$destination = __DIR__ . DIRECTORY_SEPARATOR . 'public-status.json';
$temporary = tempnam(__DIR__, '.status-');
if ($temporary === false) {
    $respond(500, 'Could not store status snapshot');
}

try {
    if (file_put_contents($temporary, $publicSnapshot, LOCK_EX) === false) {
        $respond(500, 'Could not store status snapshot');
    }
    @chmod($temporary, 0644);
    if (!rename($temporary, $destination)) {
        $respond(500, 'Could not store status snapshot');
    }
} finally {
    if (is_file($temporary)) {
        @unlink($temporary);
    }
}

$respond(200, 'Status updated');

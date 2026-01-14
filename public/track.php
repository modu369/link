<?php
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    exit;
}

$trackingId = trim((string) ($_GET['sid'] ?? ''));
if ($trackingId === '' || !preg_match('/^[a-f0-9]{16}$/i', $trackingId)) {
    http_response_code(204);
    exit;
}

$sanitizeText = static function (?string $value, int $maxLen): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $value);
    if ($value === null) {
        $value = '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen, 'UTF-8');
    }

    return substr($value, 0, $maxLen);
};

$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_TRUE_CLIENT_IP']
    ?? $_SERVER['HTTP_X_REAL_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? null;

$duration = max(0, (int) ($_GET['dur'] ?? 0));
$duration = min($duration, 86400);
$pageCount = max(1, (int) ($_GET['pc'] ?? 1));
$pageCount = min($pageCount, 1000);

$payload = [
    'path' => $sanitizeText($_GET['p'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 2048),
    'referrer' => $sanitizeText($_GET['r'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 2048),
    'user_agent' => $sanitizeText($_SERVER['HTTP_USER_AGENT'] ?? '', 512),
    'ip' => $clientIp,
    'session_id' => $sanitizeText($_GET['sid2'] ?? null, 64),
    'duration' => $duration,
    'page_count' => $pageCount,
];

$pathLower = strtolower($payload['path'] ?? '');
$refLower = strtolower($payload['referrer'] ?? '');
if (str_starts_with($pathLower, 'file://') || str_starts_with($refLower, 'file://')) {
    http_response_code(204);
    exit;
}

$ingestMode = strtolower($config['ingest']['mode'] ?? 'direct');

if ($ingestMode === 'queue') {
    try {
        $redis = RedisClient::connection($config['redis']);
        $queueKey = $config['ingest']['queue_key'] ?? 'tracker:ingest:pageviews';
        $maxLen = max(0, (int) ($config['ingest']['max_queue_length'] ?? 100000));
        $record = json_encode([
            'tracking_id' => $trackingId,
            'payload' => $payload,
            'received_at' => time(),
        ], JSON_UNESCAPED_UNICODE);

        $redis->lPush($queueKey, $record);
        if ($maxLen > 0) {
            $redis->lTrim($queueKey, 0, $maxLen - 1);
        }
    } catch (Throwable $e) {
        http_response_code(204);
        exit;
    }
} else {
    try {
        $db = Database::connection($config['db']);
        $redis = RedisClient::connection($config['redis']);
        $tracker = new Tracker($db, $redis, $config);
        $tracker->recordPageview($trackingId, $payload);
    } catch (Throwable $e) {
        http_response_code(204);
        exit;
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

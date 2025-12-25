<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
} catch (Throwable $e) {
    header('Access-Control-Allow-Origin: *');
    http_response_code(500);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$trackingId = $_GET['sid'] ?? '';
if (!$trackingId) {
    http_response_code(204);
    exit;
}

$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_TRUE_CLIENT_IP']
    ?? $_SERVER['HTTP_X_REAL_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? null;

$payload = [
    'path' => $_GET['p'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
    'referrer' => $_GET['r'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ip' => $clientIp,
    'session_id' => $_GET['sid2'] ?? null,
    'duration' => $_GET['dur'] ?? null,
    'page_count' => $_GET['pc'] ?? null,
];

$tracker->recordPageview($trackingId, $payload);

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

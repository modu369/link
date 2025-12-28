<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';
require __DIR__ . '/../src/PageviewQueue.php';

$config = require __DIR__ . '/../config/config.php';

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

$trackingId = $_GET['sid'] ?? '';
if (!$trackingId) {
    http_response_code(204);
    exit;
}

$payload = [
    'path' => $_GET['p'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
    'referrer' => $_GET['r'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
    'session_id' => $_GET['sid2'] ?? null,
    'duration' => $_GET['dur'] ?? null,
    'page_count' => $_GET['pc'] ?? null,
    'occurred_at' => date('Y-m-d H:i:s'),
];

$queueConfig = $config['queue'] ?? [];
$queueEnabled = (bool) ($queueConfig['enabled'] ?? false);

if ($queueEnabled) {
    $queue = new PageviewQueue($redis, $queueConfig);
    $siteId = null;
    $strategy = $queueConfig['sharding']['strategy'] ?? 'time';
    if ($strategy === 'site_id') {
        $site = $tracker->getSiteByTrackingId($trackingId);
        if (!$site) {
            http_response_code(204);
            exit;
        }
        $siteId = (int) $site['id'];
    }
    $queue->enqueue($trackingId, $payload, $siteId);
} else {
    $tracker->recordPageview($trackingId, $payload);
}

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

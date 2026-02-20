<?php
$trackingId = trim((string) ($_GET['sid'] ?? ''));
if ($trackingId === '' || !preg_match('/^[a-f0-9]{16}$/i', $trackingId)) {
    http_response_code(204);
    exit;
}

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

$extractIp = static function (?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!str_contains($value, ',')) {
        $candidate = trim($value);
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }
    foreach (explode(',', $value) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return null;
};

$clientIp = $extractIp($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null)
    ?? $extractIp($_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null)
    ?? $extractIp($_SERVER['HTTP_X_REAL_IP'] ?? null)
    ?? $extractIp($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null)
    ?? $extractIp($_SERVER['REMOTE_ADDR'] ?? null);

$userAgent = $sanitizeText($_SERVER['HTTP_USER_AGENT'] ?? '', 512);
$userAgentLower = strtolower($userAgent);
$spiderRules = [
    'baiduspider',
    'bingbot',
    'googlebot',
    'sogouspider',
    'sogou web spider',
    'yisouspider',
    'bytespider',
    '360spider',
];
foreach ($spiderRules as $needle) {
    if (str_contains($userAgentLower, $needle)) {
        http_response_code(204);
        exit;
    }
}

$cookieParam = (string) ($_GET['ckv'] ?? '');
$cookieParam = trim($cookieParam);
if ($cookieParam === '' || !preg_match('/^[a-f0-9]{16,128}$/i', $cookieParam)) {
    http_response_code(204);
    exit;
}

$cookieName = 'tracker_ck_' . strtolower($trackingId);
$cookieValue = isset($_COOKIE[$cookieName]) ? trim((string) $_COOKIE[$cookieName]) : '';
if ($cookieValue === '') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $cookieOptions = [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => $isHttps,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ];
    setcookie($cookieName, $cookieParam, $cookieOptions);
} elseif (!hash_equals($cookieValue, $cookieParam)) {
    http_response_code(204);
    exit;
}

$duration = max(0, (int) ($_GET['dur'] ?? 0));
$duration = min($duration, 86400);
$pageCount = max(1, (int) ($_GET['pc'] ?? 1));
$pageCount = min($pageCount, 1000);

$payload = [
    'path' => $sanitizeText($_GET['p'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 2048),
    'referrer' => $sanitizeText($_GET['r'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 2048),
    'user_agent' => $userAgent,
    'language' => $sanitizeText($_GET['lg'] ?? null, 32),
    'showp' => $sanitizeText($_GET['showp'] ?? null, 32),
    'ntime' => $sanitizeText($_GET['ntime'] ?? null, 16),
    'accept_language' => $sanitizeText($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 128),
    'accept_encoding' => $sanitizeText($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 128),
    'sec_ch_ua' => $sanitizeText($_SERVER['HTTP_SEC_CH_UA'] ?? '', 256),
    'sec_ch_ua_mobile' => $sanitizeText($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '', 32),
    'sec_ch_ua_platform' => $sanitizeText($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '', 64),
    'sec_fetch_site' => $sanitizeText($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', 32),
    'sec_fetch_mode' => $sanitizeText($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '', 32),
    'sec_fetch_dest' => $sanitizeText($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '', 32),
    'ip' => $clientIp,
    'session_id' => $sanitizeText($_GET['sid2'] ?? null, 64),
    'fingerprint' => $sanitizeText($_GET['fp'] ?? null, 128),
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
header_remove('Set-Cookie');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

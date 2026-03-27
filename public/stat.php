<?php
$outputGifAndExit = static function () {
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    exit;
};
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

$clientIp = $extractIp($_SERVER['HTTP_X_REAL_IP'] ?? null)
    ?? $extractIp($_SERVER['REMOTE_ADDR'] ?? null);

$userAgent = $sanitizeText($_SERVER['HTTP_USER_AGENT'] ?? '', 512);
$userAgentLower = strtolower($userAgent);

// 统一的蜘蛛规则库
$trackSpiders = [
    'baiduspider'      => ['crawl.baidu.com', '百度'],
    'googlebot'        => ['googlebot.com', '谷歌'],
    'bingbot'          => ['search.msn.com', '必应'],
    'sogou web spider' => ['crawl.sogou.com', '搜狗'],
    'sogouspider'      => ['crawl.sogou.com', '搜狗'],
    'yisouspider'      => ['crawl.sm.cn', '神马'],
    'bytespider'       => ['crawl.bytedance.com', '头条'],
    '360spider'        => ['360', '360'],
    'petalbot'         => ['aspiegel.com', '华为'],
    'yahoo'            => ['yahoo', '雅虎'],
];

$matchedSpider = null;
foreach ($trackSpiders as $needle => $rule) {
    if (str_contains($userAgentLower, $needle)) {
        $matchedSpider = [$needle, $rule[0], $rule[1]];
        break;
    }
}

if ($matchedSpider) {
    $resolveRdns = static function (string $ip): string {
        $ip = trim($ip);
        if ($ip === '') return '';
        $host = @gethostbyaddr($ip);
        if (is_string($host) && $host !== $ip) {
            return strtolower($host);
        }
        return '';
    };

    $siteIdCache = null;
    $getSiteByTrackingId = static function (string $tid) use ($config, &$siteIdCache): ?int {
        if ($tid === '') return null;
        if ($siteIdCache !== null) return $siteIdCache;
        try {
            $redis = RedisClient::connection($config['redis']);
            $cached = $redis->get("site:{$tid}");
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['id'])) {
                    return $siteIdCache = (int) $decoded['id'];
                }
            }
        } catch (Throwable $e) {}

        try {
            $db = Database::connection($config['db']);
            $stmt = $db->prepare('SELECT id FROM sites WHERE tracking_id = :tracking_id LIMIT 1');
            $stmt->execute([':tracking_id' => $tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['id'])) {
                $siteIdCache = (int) $row['id'];
                try {
                    $redis = RedisClient::connection($config['redis']);
                    $redis->setex("site:{$tid}", 3600, json_encode(['id' => $siteIdCache, 'tracking_id' => $tid]));
                } catch (Throwable $e) {}
                return $siteIdCache;
            }
        } catch (Throwable $e) {}
        return null;
    };

    [$spiderKey, $spiderRule, $spiderEngine] = $matchedSpider;
    
    $isVerifiedSpider = false;
    $cacheKey = null;
    
    // 动态缓存策略：必应/谷歌使用 C 段缓存，其他使用精确 IP 缓存
    if (in_array($spiderKey, ['googlebot', 'bingbot'], true)) {
        $ipLong = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($clientIp) : false;
        if ($ipLong !== false) {
            $cacheKey = sprintf('bot:rdns:%s:%s', $spiderKey, long2ip($ipLong & -256));
        } else {
            $cacheKey = sprintf('bot:rdns:%s:%s', $spiderKey, $clientIp);
        }
    } else {
        $cacheKey = sprintf('bot:rdns:%s:%s', $spiderKey, $clientIp);
    }

    $redis = null;
    try {
        $redis = RedisClient::connection($config['redis']);
    } catch (Throwable $e) {}

    // 检查 Redis 缓存
    if ($cacheKey && $redis) {
        $cached = $redis->get($cacheKey);
        if ($cached === 'ok') {
            $isVerifiedSpider = true;
        } elseif ($cached === 'bad') {
            $isVerifiedSpider = false;
        }
    }

    // 缓存未命中，发起 RDNS 查验
    if (!$isVerifiedSpider && (!isset($cached) || $cached !== 'bad')) {
        if ($spiderRule === '360') {
            $ranges = [
                '123.6.49.', '1.192.192.', '1.192.195.', '42.236.10.', '42.236.12.',
                '42.236.17.', '42.236.101.', '27.115.124.', '180.153.236.', '180.163.220.',
            ];
            foreach ($ranges as $prefix) {
                if (str_starts_with((string) $clientIp, $prefix)) {
                    $isVerifiedSpider = true;
                    break;
                }
            }
        } elseif ($spiderKey === 'yisouspider') {
            $isVerifiedSpider = true; // 神马直接放行
        } else {
            $hostLower = $resolveRdns($clientIp);
            if ($hostLower !== '' && str_contains($hostLower, $spiderRule)) {
                $isVerifiedSpider = true;
            }
        }

        if ($cacheKey && $redis) {
            $ttl = $isVerifiedSpider ? 60 * 60 * 24 * 30 : 60 * 60 * 12;
            $redis->setex($cacheKey, $ttl, $isVerifiedSpider ? 'ok' : 'bad');
        }
    }

    // 如果确认为真蜘蛛，写入队列
    if ($isVerifiedSpider) {
        $siteId = $getSiteByTrackingId($trackingId);
        if ($siteId && $redis) {
            $pageUrl = trim((string) ($_GET['p'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
            $referrerUrl = trim((string) ($_GET['r'] ?? ''));
            $parsed = $pageUrl !== '' ? parse_url($pageUrl) : [];
            $path = '';
            $domain = '';
            
            if (is_array($parsed)) {
                $path = (string) ($parsed['path'] ?? '');
                if (isset($parsed['query']) && $parsed['query'] !== '') {
                    $path .= '?' . $parsed['query'];
                }
                $domain = (string) ($parsed['host'] ?? '');
            }

            $botPayload = [
                'site_id' => $siteId,
                'path' => $path,
                'referrer' => $referrerUrl,
                'user_agent' => $userAgent,
                'ip_address' => $clientIp,
                'domain' => $domain,
                'engine' => $spiderEngine,
            ];

            $record = [
                'payload' => $botPayload,
                'received_at' => time(),
            ];

            $queueKey = $config['ingest']['bot_queue_key'] ?? 'tracker:ingest:bot_logs';
            $maxLen = max(0, (int) ($config['ingest']['bot_max_queue_length'] ?? 50000));
            
            $redis->lPush($queueKey, json_encode($record));
            if ($maxLen > 0) {
                $redis->lTrim($queueKey, 0, $maxLen - 1);
            }
        }
    }

    // 只要它自称是蜘蛛（无论真假），处理完毕后强制阻断，绝对不允许进入普通PV统计！
    $outputGifAndExit();
}

$cookieParam = (string) ($_GET['ckv'] ?? '');
$cookieParam = trim($cookieParam);

// 1. 服务端兜底机制：即使前端传来的标识异常，也不直接 exit，而是服务端生成随机标识放行
if ($cookieParam === '' || !preg_match('/^[a-f0-9]{16,128}$/i', $cookieParam)) {
    $cookieParam = bin2hex(random_bytes(16));
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
    @setcookie($cookieName, $cookieParam, $cookieOptions);
} else {
    // 2. 移除原有的 !hash_equals 强制 exit 拦截！解决多级缓存不同步丢数据问题
    if (preg_match('/^[a-f0-9]{16,128}$/i', $cookieValue)) {
        $cookieParam = $cookieValue;
    }
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
    'visitor_id' => $cookieParam,
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
$outputGifAndExit();

<?php
$trackingId = trim((string) ($_GET['id'] ?? ''));
if ($trackingId === '' || !preg_match('/^[a-f0-9]{16}$/i', $trackingId)) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../src/RedisClient.php';
require __DIR__ . '/../../src/Database.php';

$config = require __DIR__ . '/../../config/config.php';

header('Content-Type: application/javascript; charset=UTF-8');

$siteId = null;

$getSiteByTrackingId = static function (string $trackingId) use ($config, &$siteId): ?int {
    if ($trackingId === '') {
        return null;
    }
    if ($siteId !== null) {
        return $siteId;
    }
    try {
        $redis = RedisClient::connection($config['redis']);
        $cached = $redis->get("site:{$trackingId}");
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['id'])) {
                $siteId = (int) $decoded['id'];
                return $siteId;
            }
        }
    } catch (Throwable $e) {
        // Ignore cache failures.
    }

    try {
        $db = Database::connection($config['db']);
        $statement = $db->prepare('SELECT id, tracking_id FROM sites WHERE tracking_id = :tracking_id LIMIT 1');
        $statement->execute([':tracking_id' => $trackingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && isset($row['id'])) {
            $siteId = (int) $row['id'];
            try {
                $redis = RedisClient::connection($config['redis']);
                $redis->setex("site:{$trackingId}", 3600, json_encode(['id' => $siteId, 'tracking_id' => $trackingId]));
            } catch (Throwable $e) {
                // Ignore cache failures.
            }
            return $siteId;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
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

$userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$userAgentLower = strtolower($userAgent);
$spiderRules = [
    'baiduspider' => ['crawl.baidu.com', '百度'],
    'bingbot' => ['search.msn.com', '必应'],
    'googlebot' => ['googlebot.com', '谷歌'],
    'sogou web spider' => ['crawl.sogou.com', '搜狗'],
    'sogouspider' => ['crawl.sogou.com', '搜狗'],
    'yisouspider' => ['crawl.sm.cn', '神马'],
    'bytespider' => ['crawl.bytedance.com', '头条'],
    '360spider' => ['360', '360'],
];

$matchedSpider = null;
foreach ($spiderRules as $needle => $rule) {
    if ($userAgentLower !== '' && str_contains($userAgentLower, $needle)) {
        $matchedSpider = [$needle, $rule[0], $rule[1]];
        break;
    }
}

$resolveRdns = static function (string $ip): string {
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }
    $host = @gethostbyaddr($ip);
    if (is_string($host) && $host !== $ip) {
        return strtolower($host);
    }
    return '';
};

?>
(function () {
  var script = document.currentScript;
  if (!script) {
    var scripts = document.getElementsByTagName('script');
    script = scripts[scripts.length - 1];
  }
  if (!script) return;

  var siteId = script.getAttribute('data-site');
  if (!siteId && script.src) {
    try {
      var srcUrl = new URL(script.src);
      siteId = srcUrl.searchParams.get('id');
    } catch (e) {
      siteId = '';
    }
  }
  if (!siteId) return;

  var screenWidth = (window.screen && window.screen.width) ? window.screen.width : 0;
  var screenHeight = (window.screen && window.screen.height) ? window.screen.height : 0;
  var language = (navigator.language || '').toLowerCase();
  var params = new URLSearchParams({
    sid: siteId,
    p: window.location.href,
    r: document.referrer || '',
    lg: language,
    showp: (screenWidth && screenHeight) ? (screenWidth + 'x' + screenHeight) : '',
    ntime: Math.floor(Date.now() / 1000).toString()
  });

  try {
    var storageKey = 'tracker_' + siteId;
    var sessionData = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    if (!sessionData.id) {
      sessionData.id = Math.random().toString(16).slice(2);
      sessionData.started = Date.now();
      sessionData.pages = 0;
    }
    sessionData.pages += 1;
    sessionData.duration = Math.max(0, Math.round((Date.now() - sessionData.started) / 1000));
    sessionStorage.setItem(storageKey, JSON.stringify(sessionData));

    params.set('sid2', sessionData.id);
    params.set('dur', sessionData.duration);
    params.set('pc', sessionData.pages);

    var fpKey = 'tracker_fp_' + siteId;
    var fp = sessionStorage.getItem(fpKey);
    if (!fp) {
      var canvas = document.createElement('canvas');
      canvas.width = 200;
      canvas.height = 50;
      var ctx = canvas.getContext('2d');
      ctx.textBaseline = 'top';
      ctx.font = "14px 'Arial'";
      ctx.fillStyle = '#f60';
      ctx.fillRect(0, 0, 200, 50);
      ctx.fillStyle = '#069';
      ctx.fillText('tracker-' + siteId, 2, 2);
      ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
      ctx.fillText(navigator.userAgent || '', 2, 20);
      var data = canvas.toDataURL();
      var hash = 0;
      for (var i = 0; i < data.length; i++) {
        hash = ((hash << 5) - hash) + data.charCodeAt(i);
        hash |= 0;
      }
      fp = 'fp_' + Math.abs(hash);
      sessionStorage.setItem(fpKey, fp);
    }
    if (fp) {
      params.set('fp', fp);
    }
  } catch (e) {
    // 忽略无痕模式或禁用 sessionStorage 的异常
  }

  var customEndpoint = script.getAttribute('data-endpoint');
  var base = customEndpoint || script.src.replace(/\/js\/[^/]+$/, '/track.php');
  var separator = base.indexOf('?') === -1 ? '?' : '&';

  var sendBeacon = function (query) {
    var img = new Image();
    img.referrerPolicy = 'no-referrer-when-downgrade';
    img.src = base + separator + query;
  };

  if (!navigator.cookieEnabled) {
    return;
  }

  var cookieName = 'tracker_ck_' + siteId;
  try {
    var cookieValue = null;
    var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + cookieName + '=([^;]*)'));
    if (cookieMatch && cookieMatch[1]) {
      cookieValue = decodeURIComponent(cookieMatch[1]);
    }
    var cookieJustSet = false;
    if (!cookieValue) {
      if (window.crypto && window.crypto.getRandomValues) {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        cookieValue = Array.from(bytes).map(function (b) {
          return b.toString(16).padStart(2, '0');
        }).join('');
      } else {
        cookieValue = Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
      }
      var sameSite = (window.location && window.location.protocol === 'https:') ? 'SameSite=None; Secure' : 'SameSite=Lax';
      document.cookie = cookieName + '=' + encodeURIComponent(cookieValue) + '; path=/; max-age=31536000; ' + sameSite;
      cookieJustSet = true;
    }
  } catch (e) {
    return;
  }
  if (!cookieValue) {
    return;
  }

  params.set('ckv', cookieValue);
  sendBeacon(params.toString());
  if (cookieJustSet) {
    return;
  }
})();
<?php
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$isVerifiedSpider = false;
if ($matchedSpider && $clientIp) {
    [$spiderKey, $spiderRule, $spiderEngine] = $matchedSpider;
    $cacheKey = null;
    $cacheType = $spiderKey;
    if (in_array($spiderKey, ['googlebot', 'bingbot'], true)) {
        $ipLong = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($clientIp) : false;
        if ($ipLong !== false) {
            $cacheKey = sprintf('bot:rdns:%s:%s', $cacheType, long2ip($ipLong & -256));
        }
    } else {
        $cacheKey = sprintf('bot:rdns:%s:%s', $cacheType, $clientIp);
    }

    $redis = null;
    try {
        $redis = RedisClient::connection($config['redis']);
    } catch (Throwable $e) {
        $redis = null;
    }

    $cached = null;
    if ($cacheKey && $redis) {
        $cached = $redis->get($cacheKey);
        if ($cached === 'ok') {
            $isVerifiedSpider = true;
        } elseif ($cached === 'bad') {
            $isVerifiedSpider = false;
        }
    }

    if (!$isVerifiedSpider && $cached !== 'bad') {
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

    if ($isVerifiedSpider) {
        $siteId = $getSiteByTrackingId($trackingId);
        if ($siteId && $redis) {
            $referrer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
            $parsed = $referrer !== '' ? parse_url($referrer) : [];
            $path = '';
            if (is_array($parsed)) {
                $path = (string) ($parsed['path'] ?? '');
                if (isset($parsed['query']) && $parsed['query'] !== '') {
                    $path .= '?' . $parsed['query'];
                }
            }
            $domain = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
            $payload = [
                'site_id' => $siteId,
                'path' => $path,
                'referrer' => $referrer,
                'user_agent' => $userAgent,
                'ip_address' => $clientIp,
                'domain' => $domain,
                'engine' => $spiderEngine,
            ];
            $record = [
                'payload' => $payload,
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
}
?>

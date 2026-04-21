<?php
$trackingId = trim((string) ($_GET['id'] ?? ''));
if ($trackingId === '' || !preg_match('/^[a-f0-9]{16}$/i', $trackingId)) {
    http_response_code(404);
    exit;
}

// === 新增：极速原生 Redis 蜘蛛捕捉 (0 网络延迟，耗时 1ms) ===
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isSpider = false; // 增加蜘蛛状态标记

if (preg_match('/(baiduspider|googlebot|bingbot|sogou web spider|360spider|yisouspider|bytespider|petalbot|yahoo)/i', $userAgent, $matches)) {
    $isSpider = true; // 确认为蜘蛛
    try {
        // 注意文件层级：js 文件夹需要回退两层才能访问到 config 和 src
        $config = require __DIR__ . '/../../config/config.php';
        require_once __DIR__ . '/../../src/RedisClient.php';
        require_once __DIR__ . '/../../src/Database.php';

        $redis = RedisClient::connection($config['redis']);
        $clientIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? ''; // 蜘蛛抓取 JS 时，Referer 通常是客户网页

        // 统一引擎名称
        $spiderNameRaw = $matches[1];
        $engineMap = [
            'baiduspider' => '百度', 'googlebot' => '谷歌', 'bingbot' => '必应', 
            'sogou' => '搜狗', '360spider' => '360', 'yisouspider' => '神马', 
            'bytespider' => '头条', 'petalbot' => '华为', 'yahoo' => '雅虎'
        ];
        $spiderEngine = $engineMap[$spiderNameRaw] ?? $spiderNameRaw;

        // 1. 极速获取 Site ID (优先查 Redis 缓存)
        $siteId = null;
        $cachedSite = $redis->get("site:{$trackingId}");
        if ($cachedSite) {
            $siteId = json_decode($cachedSite, true)['id'] ?? null;
        } else {
            $db = Database::connection($config['db']);
            $stmt = $db->prepare('SELECT id FROM sites WHERE tracking_id = :tid LIMIT 1');
            $stmt->execute([':tid' => $trackingId]);
            $siteId = $stmt->fetchColumn();
            if ($siteId) {
                $redis->setex("site:{$trackingId}", 3600, json_encode(['id' => $siteId]));
            }
        }

        // 2. 去重并直接压入统计队列
        if ($siteId) {
            $dedupKey = "bot_dedup:{$siteId}:" . md5($clientIp . $spiderEngine . $referer);
            
            // setnx 加锁：60秒内同一只蜘蛛只记录一次
            if ($redis->setnx($dedupKey, '1')) {
                $redis->expire($dedupKey, 60);

                $domain = '';
                if ($referer !== '') {
                    $domain = parse_url($referer, PHP_URL_HOST) ?? '';
                }

                $botPayload = [
                    'site_id' => $siteId,
                    'path' => $referer,
                    'referrer' => '', 
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'ip_address' => $clientIp,
                    'domain' => $domain,
                    'engine' => $spiderEngine,
                ];

                $record = ['payload' => $botPayload, 'received_at' => time()];
                $queueKey = $config['ingest']['bot_queue_key'] ?? 'tracker:ingest:bot_logs';
                $maxLen = max(0, (int) ($config['ingest']['bot_max_queue_length'] ?? 50000));
                
                $redis->lPush($queueKey, json_encode($record));
                if ($maxLen > 0) {
                    $redis->lTrim($queueKey, 0, $maxLen - 1);
                }
            }
        }
    } catch (Throwable $e) {
        // 遇到任何波动都静默忽略，保证下方 JS 正常输出
    }
}
// =================================================

// 必须输出的内容类型
header('Content-Type: application/javascript; charset=UTF-8');

// === 核心逻辑：头部信息智能分流 ===
if ($isSpider) {
    // 蜘蛛专属：强制不缓存
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
} else {
    // 普通人类访客：正常缓存 6 小时
    $maxAge = 21600; 
    $lastModified = filemtime(__FILE__);
    $etag = md5('v8_tracker_' . $lastModified);

    header('Cache-Control: public, max-age=' . $maxAge);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('Etag: "' . $etag . '"');

    $httpIfNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';
    $httpIfModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

    if ($httpIfNoneMatch === $etag || $httpIfModifiedSince >= $lastModified) {
        http_response_code(304);
        exit;
    }
}
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
  }

  var customEndpoint = script.getAttribute('data-endpoint');
  var base = customEndpoint || script.src.replace(/\/js\/[^/]+$/, '/stat.php');
  var separator = base.indexOf('?') === -1 ? '?' : '&';

  var sendBeacon = function (query) {
    var url = base + separator + query;
    if (window.fetch) {
        fetch(url, {
            method: 'GET',
            keepalive: true
        }).catch(function(err) {});
    } else {
        var img = new Image();
        img.referrerPolicy = 'no-referrer-when-downgrade';
        img.src = url;
    }
  };

  var identifierName = 'tracker_ck_' + siteId;
  var visitorId = null;

  var generateId = function () {
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes).map(function (b) {
        return b.toString(16).padStart(2, '0');
      }).join('');
    }
    return Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
  };

  try {
    var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + identifierName + '=([^;]*)'));
    if (cookieMatch && cookieMatch[1]) {
      visitorId = decodeURIComponent(cookieMatch[1]);
    }
    if (!visitorId && window.localStorage) {
      visitorId = localStorage.getItem(identifierName);
    }
    if (!visitorId && window.sessionStorage) {
      visitorId = sessionStorage.getItem(identifierName);
    }
  } catch (e) {}

  var isNewId = false;
  if (!visitorId) {
    visitorId = generateId();
    isNewId = true;
  }

  if (isNewId) {
    try {
      var sameSite = (window.location && window.location.protocol === 'https:') ? 'SameSite=None; Secure' : 'SameSite=Lax';
      document.cookie = identifierName + '=' + encodeURIComponent(visitorId) + '; path=/; max-age=31536000; ' + sameSite;
    } catch (e) {}
    try {
      if (window.localStorage) { localStorage.setItem(identifierName, visitorId); }
    } catch (e) {}
    try {
      if (window.sessionStorage) { sessionStorage.setItem(identifierName, visitorId); }
    } catch (e) {}
  }
  if (!visitorId || !/^[a-f0-9]{16,128}$/i.test(visitorId)) {
    visitorId = generateId();
  }

  params.set('ckv', visitorId);
  
  var triggerTracker = function() {
    if (!window._tracker_sent) {
      sendBeacon(params.toString());
      window._tracker_sent = true;
    }
  };

  triggerTracker();
})();

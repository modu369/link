<?php
$trackingId = trim((string) ($_GET['id'] ?? ''));
if ($trackingId === '' || !preg_match('/^[a-f0-9]{16}$/i', $trackingId)) {
    http_response_code(404);
    exit;
}
// === 新增：服务器端蜘蛛拦截器 (终极蜘蛛捕获)
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

// 只要带有这些特征，立刻移交 stat.php 记录，跳过 JS 下发
if (preg_match('/(baiduspider|googlebot|bingbot|sogou web spider|sogouspider|yisouspider|bytespider|360spider|petalbot|yahoo)/i', $userAgent)) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $_GET['sid'] = $trackingId;
    require __DIR__ . '/../stat.php';
    // stat.php 处理完毕后会自动输出 1x1 GIF 并 exit，
    // 蜘蛛收到 GIF 会直接丢弃，不影响它的正常爬取，但我们已经成功记录了它！
    exit;
}
// ==========================================
// 允许浏览器缓存此 JS 探针 6 小时 (21600秒)，极大提升真实访客二次访问的加载速度
$maxAge = 21600; 
$lastModified = filemtime(__FILE__);
$etag = md5('v8_tracker_' . $lastModified);

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=' . $maxAge);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('Etag: "' . $etag . '"');

$httpIfNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';
$httpIfModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

if ($httpIfNoneMatch === $etag || $httpIfModifiedSince >= $lastModified) {
    http_response_code(304);
    exit;
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
    // 忽略无痕模式或禁用 sessionStorage 的异常
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
        }).catch(function(err) {
        });
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

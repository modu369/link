(function () {
  var script = document.currentScript;
  if (!script) return;

  var siteId = script.getAttribute('data-site');
  if (!siteId) return;

  var params = new URLSearchParams({
    sid: siteId,
    p: window.location.href,
    r: document.referrer || ''
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
  } catch (e) {
    // 忽略无痕模式或禁用 sessionStorage 的异常
  }

  var customEndpoint = script.getAttribute('data-endpoint');
  var base = customEndpoint || script.src.replace(/\/js\/[^/]+$/, '/track.php');
  var separator = base.indexOf('?') === -1 ? '?' : '&';

  var img = new Image();
  img.referrerPolicy = 'no-referrer-when-downgrade';
  img.src = base + separator + params.toString();
})();

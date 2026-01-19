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

  var img = new Image();
  img.referrerPolicy = 'no-referrer-when-downgrade';
  img.src = base + separator + params.toString();
})();

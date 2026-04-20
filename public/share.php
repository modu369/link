<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';
$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);

$token = $_GET['token'] ?? '';
$allowedRanges = ['today', 'yesterday', 'day_before', '7d'];
$range = $_GET['range'] ?? 'today';
if (!in_array($range, $allowedRanges, true) && !str_starts_with($range, 'custom:')) {
    $range = 'today';
}

$data = $token ? $tracker->getShareReport($token, $range) : null;
$window = $tracker->rangeWindow($range);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($data['share']['name'], ENT_QUOTES, 'UTF-8') ?> - 分享统计面板</title>
    <link rel="stylesheet" href="/t_statics/css/modern-normalize.css">
    <style>
        body { font-family: 'Inter','PingFang SC',sans-serif; background:#f8fafc; margin:0; color:#0f172a; }
        .wrap { max-width: 1100px; margin: 40px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; box-shadow:0 12px 30px rgba(15,23,42,0.04); }
        h1 { margin:0 0 6px; text-align:center; }
        .muted { color:#64748b; }
        table { width:100%; border-collapse: collapse; margin-top:12px; }
        th,td { padding:10px 8px; border-bottom:1px solid #e2e8f0; text-align:left; }
        th { color:#64748b; font-weight:600; }
        .filters { display:flex; gap:8px; justify-content:flex-end; margin-top:8px; }
        .filter-btn { padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-weight:600; text-decoration:none; color:#0f172a; }
        .filter-btn.active { background:#0f172a; color:#fff; border-color:#0f172a; }
        .pill { display:inline-block; padding:4px 8px; background:#f1f5f9; border-radius:999px; border:1px solid #e2e8f0; font-size:12px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
    <h1 style="text-align:center;">📊 网站统计面板（分享版）</h1>
        <?php if (!$data): ?>
            <p class="muted">链接无效或数据暂不可用。</p>
        <?php else: ?>
            <p class="muted" style="margin:6px 0 12px;">时间范围：<?= htmlspecialchars($window['start'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($window['end'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php
            $isCustom = str_starts_with($range, 'custom:');
            $customStart = '';
            $customEnd = '';
            if ($isCustom) {
                $parts = explode(':', $range);
                $customStart = $parts[1] ?? '';
                $customEnd = $parts[2] ?? '';
            }
            ?>
            <div class="filters">
                <?php foreach ($allowedRanges as $r): ?>
                    <a class="filter-btn <?= $range === $r ? 'active' : '' ?>" href="/share.php?token=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>&range=<?= $r ?>"><?= ['today'=>'今日','yesterday'=>'昨日','day_before'=>'前天','7d'=>'近7天'][$r] ?></a>
                <?php endforeach; ?>
                <form method="get" action="/share.php" onsubmit="return applyShareRange(this);" style="display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="range" value="">
                    <input type="date" name="start" value="<?= htmlspecialchars($customStart, ENT_QUOTES, 'UTF-8') ?>" required>
                    <span class="muted">-</span>
                    <input type="date" name="end" value="<?= htmlspecialchars($customEnd, ENT_QUOTES, 'UTF-8') ?>" required>
                    <button type="submit" class="filter-btn <?= $isCustom ? 'active' : '' ?>">自定义</button>
                </form>
            </div>
            <table>
                <thead>
                    <tr><th>受访域名</th><th>PV</th><th>IP</th><th>移动PV</th><th>移动IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($data['rows'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $row['views'] ?></td>
                        <td><?= (int) $row['ips'] ?></td>
                        <td><?= (int) $row['mobile_views'] ?></td>
                        <td><?= (int) $row['mobile_ips'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:12px;" class="muted">分享名：<?= htmlspecialchars($data['share']['name'], ENT_QUOTES, 'UTF-8') ?> | Token: <span class="pill"><?= htmlspecialchars($data['share']['token'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <?php endif; ?>
    </div>
</div>
</body>
<script>
    function applyShareRange(form) {
        var start = form.querySelector('input[name="start"]').value;
        var end = form.querySelector('input[name="end"]').value;
        if (!start || !end) {
            return false;
        }
        form.querySelector('input[name="range"]').value = 'custom:' + start + ':' + end;
        return true;
    }
</script>
</html>

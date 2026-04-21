<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';
// ==================================
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

// === 提取汇总行与明细行，确保所有汇总行始终在表格最顶部 ===
$summaryRows = [];
$normalRows = [];

if ($data && !empty($data['rows'])) {
    foreach ($data['rows'] as $row) {
        if ($row['domain'] === '全局汇总') {
            $summaryRows[] = $row;
        } elseif ($row['domain'] === '汇总') {
            // 将名称修改为“汇总（累加）”
            $row['domain'] = '汇总（累加）';
            $summaryRows[] = $row;
        } else {
            $normalRows[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $data ? htmlspecialchars($data['share']['name'], ENT_QUOTES, 'UTF-8') : '无效链接' ?> - 统计数据分享面板</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { brand: { 50: '#eff6ff', 100: '#dbeafe', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 900: '#1e3a8a' } },
                    fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'PingFang SC', 'Microsoft YaHei', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        body { background-color: #f8fafc; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col">

    <div class="absolute top-0 left-0 w-full h-64 bg-gradient-to-br from-brand-900 to-brand-600 -z-10"></div>

    <div class="max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1">
        
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight flex items-center gap-2">
                    <svg class="w-7 h-7 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    实时数据看板
                </h1>
                <?php if ($data): ?>
                <p class="text-brand-50 mt-1 text-sm opacity-90">
                    当前项目：<span class="font-medium"><?= htmlspecialchars($data['share']['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <?php endif; ?>
            </div>
            
            <?php if ($data): ?>
            <div class="mt-4 md:mt-0 bg-white/10 backdrop-blur-md rounded-xl p-1 inline-flex items-center shadow-sm border border-white/20 text-sm">
                <span class="text-white/80 px-3 font-medium">数据窗口：</span>
                <span class="bg-white text-brand-900 px-3 py-1 rounded-lg font-bold shadow-sm">
                    <?= htmlspecialchars($window['start'], ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($window['end'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$data): ?>
            <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-16 text-center mt-10">
                <div class="w-20 h-20 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <h3 class="text-xl font-bold text-slate-800">链接失效</h3>
                <p class="text-slate-500 mt-2">Token 无效或对应的分享统计已被管理员撤回。</p>
            </div>
        <?php else: ?>

            <div class="bg-white rounded-2xl shadow-xl shadow-slate-200/50 border border-slate-200 overflow-hidden">
                
                <div class="border-b border-slate-100 p-5 bg-slate-50/50 flex flex-col xl:flex-row xl:items-center justify-between gap-5">
                    <h2 class="text-lg font-extrabold text-slate-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                        受访数据明细
                    </h2>
                    
                    <?php
                    $isCustom = str_starts_with($range, 'custom:');
                    $customStart = $isCustom ? (explode(':', $range)[1] ?? '') : '';
                    $customEnd = $isCustom ? (explode(':', $range)[2] ?? '') : '';
                    $rangesMap = ['today'=>'今日', 'yesterday'=>'昨日', 'day_before'=>'前天', '7d'=>'近7天'];
                    ?>
                    
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex bg-slate-200/50 p-1 rounded-xl">
                            <?php foreach ($allowedRanges as $r): ?>
                                <a href="/share.php?token=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>&range=<?= $r ?>" 
                                   class="px-5 py-2 rounded-lg text-sm font-bold transition-all duration-300 <?= $range === $r ? 'bg-white text-brand-600 shadow-md' : 'text-slate-500 hover:text-slate-800' ?>">
                                    <?= $rangesMap[$r] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <form method="get" action="/share.php" onsubmit="return applyShareRange(this);" class="flex items-center gap-2 bg-white border border-slate-200 p-1 rounded-xl shadow-sm">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="range" value="">
                            <div class="flex items-center px-3">
                                <input type="date" name="start" value="<?= htmlspecialchars($customStart, ENT_QUOTES, 'UTF-8') ?>" required class="text-sm font-medium text-slate-600 outline-none">
                                <span class="mx-2 text-slate-300">至</span>
                                <input type="date" name="end" value="<?= htmlspecialchars($customEnd, ENT_QUOTES, 'UTF-8') ?>" required class="text-sm font-medium text-slate-600 outline-none">
                            </div>
                            <button type="submit" class="px-4 py-2 text-sm font-bold rounded-lg transition-all <?= $isCustom ? 'bg-brand-600 text-white shadow-lg' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                                筛选
                            </button>
                        </form>
                    </div>
                </div>

                <div class="w-full overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse whitespace-nowrap">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200 text-[12px] uppercase tracking-widest text-slate-500 font-bold">
                                <th class="py-4 px-8">域名</th>
                                <th class="py-4 px-8 text-right">浏览量 (PV)</th>
                                <th class="py-4 px-8 text-right">独立访客 (IP)</th>
                                <th class="py-4 px-8 text-right">移动端 PV</th>
                                <th class="py-4 px-8 text-right">移动端 IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            
                            <?php foreach ($summaryRows as $sRow): ?>
                            <tr class="bg-brand-50 border-b-2 border-brand-100">
                                <td class="py-5 px-8">
                                    <div class="font-black text-brand-700 flex items-center gap-3">
                                        <svg class="w-5 h-5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                                        <?= htmlspecialchars($sRow['domain'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td class="py-5 px-8 text-right text-brand-700 font-black tracking-tight text-lg"><?= number_format((int) $sRow['views']) ?></td>
                                <td class="py-5 px-8 text-right text-brand-700 font-black tracking-tight text-lg"><?= number_format((int) $sRow['ips']) ?></td>
                                <td class="py-5 px-8 text-right text-brand-600 font-bold"><?= number_format((int) $sRow['mobile_views']) ?></td>
                                <td class="py-5 px-8 text-right text-brand-600 font-bold"><?= number_format((int) $sRow['mobile_ips']) ?></td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if (empty($normalRows) && empty($summaryRows)): ?>
                                <tr>
                                    <td colspan="5" class="py-20 text-center">
                                        <div class="text-slate-400 font-medium">该时段暂无数据记录</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($normalRows as $row): ?>
                                    <tr class="hover:bg-slate-50 transition-all duration-200 group">
                                        <td class="py-4 px-8">
                                            <div class="font-bold text-slate-700 flex items-center gap-3">
                                                <div class="w-1.5 h-1.5 rounded-full bg-slate-300 group-hover:bg-brand-400 transition-colors"></div>
                                                <?= htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-8 text-right text-slate-900 font-black"><?= number_format((int) $row['views']) ?></td>
                                        <td class="py-4 px-8 text-right text-slate-900 font-black"><?= number_format((int) $row['ips']) ?></td>
                                        <td class="py-4 px-8 text-right text-slate-500 font-medium"><?= number_format((int) $row['mobile_views']) ?></td>
                                        <td class="py-4 px-8 text-right text-slate-500 font-medium"><?= number_format((int) $row['mobile_ips']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-between text-[11px] text-slate-400 px-2 font-bold tracking-widest uppercase">
                <div class="flex items-center gap-3">
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    Engine Version V6.0 Stable
                </div>
                <div class="mt-3 sm:mt-0 opacity-60">
                    Secure Token: <?= htmlspecialchars($data['share']['token'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

        <?php endif; ?>
    </div>

<script>
    function applyShareRange(form) {
        var start = form.querySelector('input[name="start"]').value;
        var end = form.querySelector('input[name="end"]').value;
        if (!start || !end) {
            alert('请完整选择起止日期');
            return false;
        }
        form.querySelector('input[name="range"]').value = 'custom:' + start + ':' + end;
        return true;
    }
</script>
</body>
</html>

<?php
require __DIR__ . '/init.php';

// === 新增：拦截 AJAX 批量静默操作请求 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && $input['action'] === 'mute_domains' && !empty($input['domain_ids'])) {
        header('Content-Type: application/json');
        
        // 1. 校验用户鉴权和站点 ID
        if (!isset($siteId) || !$siteId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        // 2. 严格校验传入的参数是否为数组（防止报错）
        if (!is_array($input['domain_ids'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        try {
            // 3. 强制把数组里的所有内容转换为整数，彻底杜绝任何形式的注入风险
            $domainIds = array_map('intval', $input['domain_ids']);
            
            $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
            $params = $domainIds;
            $params[] = $siteId; // 防止水平越权
            
            global $db;
            $stmt = $db->prepare("UPDATE site_domains SET mute_until = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id IN ($placeholders) AND site_id = ?");
            $stmt->execute($params);
            
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            // 生产环境不暴露具体 SQL 错误，统一返回
            echo json_encode(['success' => false, 'message' => 'DB Error']);
        }
        exit; // 接口处理完毕，停止渲染页面
    }
}
// =====================================

require __DIR__ . '/layout.php';

// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================

$data = $selectedSite ? $tracker->getOverview($siteId, $range) : null;
$avgMinutes = $data ? round(($data['totals']['averages']['duration'] ?? 0) / 60, 1) : 0;
$trend = $data ? ($data['trend'] ?? null) : null;
$showTrend = $trend && in_array($range, ['today', 'yesterday', 'day_before'], true);

$topReferrers = $data ? array_slice($data['top_referrers'], 0, 20) : [];
$topPages = $data ? array_slice($data['top_pages'], 0, 20) : [];
$entryPages = $data ? array_slice($data['entry_pages'], 0, 20) : [];
$regions = $data ? array_slice($data['regions'], 0, 20) : [];

$yesterdayTotals = $data ? ($data['yesterday_totals'] ?? null) : null;
$yesterdayDevices = ($data && $range === 'today') ? $tracker->getDeviceBreakdown($siteId, 'yesterday') : null;

render_head('总览 - 统计后台');
render_topbar($branding);
?>
<style>
    .overview-hero { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 18px; box-shadow: 0 16px 40px rgba(22, 144, 255, 0.18); display: flex; flex-direction: column; gap: 12px; }
    .hero-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .hero-title { display: flex; align-items: center; gap: 12px; }
    .hero-dot { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #1690ff, #73c1ff); display: grid; place-items: center; color: #fff; font-size: 18px; font-weight: 700; }
    .hero-meta { color: var(--muted); font-size: 13px; }
    .metric-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: stretch; }
    .metric-tile { background: linear-gradient(135deg, #deedfb 0%, #f7fbff 100%); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: inline-flex; align-items: center; gap: 10px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.6); flex: 0 1 auto; min-width: 185px; max-width: 100%; }
    .metric-icon { width: 48px; height: 48px; border-radius: 12px; background: #fff; display: grid; place-items: center; color: #1690ff; font-size: 22px; box-shadow: 0 10px 22px rgba(22,144,255,0.16); flex-shrink: 0; }
    .metric-icon.yesterday { background: linear-gradient(135deg, #ffe6c7 0%, #fff6e9 100%); color: #d97706; box-shadow: 0 10px 22px rgba(217, 119, 6, 0.16); }
    .metric-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .metric-info .label { color: var(--muted); font-size: 12px; font-weight: 400; word-break: break-word; }
    .metric-info .val { font-size: 12px; font-weight: 400; color: #0f172a; word-break: break-word; }
    .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 12px; align-items: stretch; }
    .pill-tag { background: #deedfb; color: #1690ff; padding: 4px 10px; border-radius: 999px; font-weight: 700; border: 1px solid var(--border); }
    .chart-wrap { position: relative; width: 100%; }
    .trend-wrap canvas { max-height: 430px; height: 400px; }
    .table-wrap { max-height: 320px; overflow: auto; }
    .trend-controls { display:flex; gap:8px; align-items:center; }
    .trend-toggle button { border:1px solid var(--border); background:#deedfb; color:#1690ff; padding:6px 10px; border-radius:8px; cursor:pointer; font-weight:700; }
    .trend-toggle button.active { background:#1690ff; color:#fff; }
    .browser-pie { max-width: 420px; margin: 0 auto; display: flex; justify-content: center; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'overview', $range); ?>
    <main class="content">
        <?php 
        // === 新增：获取异常域名并渲染 UI ===
        $abnormalDomains = [];
        $abnormalText = '';
        $abnormalIdsJson = '[]';
        if ($selectedSite) {
            global $db;
            $stmt = $db->prepare("SELECT id, domain FROM site_domains WHERE site_id = ? AND status = 0 AND (mute_until IS NULL OR mute_until < NOW())");
            $stmt->execute([$siteId]);
            $abnormalDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($abnormalDomains)) {
                $domainNames = array_map(function($d) { return htmlspecialchars($d['domain'], ENT_QUOTES, 'UTF-8'); }, $abnormalDomains);
                $abnormalText = implode('，', $domainNames);
                $abnormalIdsJson = json_encode(array_column($abnormalDomains, 'id'));
            }
        }
        ?>

        <?php if (!empty($abnormalDomains)): ?>
        <div id="domain-alert-box" style="background: linear-gradient(135deg, #fff1f0 0%, #fff2f0 100%); border: 1px solid #ffccc7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 8px 24px rgba(245, 34, 45, 0.08); transition: opacity 0.3s ease; flex-wrap: wrap; gap: 15px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="background: #ff4d4f; color: #fff; width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(255, 77, 79, 0.35);">
                    <svg style="width: 20px; height: 20px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <div style="color: #cf1322;">
                    <strong style="font-size: 15px; display: block; margin-bottom: 2px;">域名连通性异常</strong>
                    <span style="font-size: 13px; opacity: 0.9;">你的域名 <?= $abnormalText ?> 可能已被 GFW 阻断或 SNI 阻断，建议立即前往检测并更换！</span>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="/domain_check.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" style="background: #fff; border: 1px solid #ff4d4f; color: #ff4d4f; padding: 7px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none; transition: all 0.2s;">查看</a>
                <button onclick="muteAllDomains(<?= htmlspecialchars($abnormalIdsJson, ENT_QUOTES, 'UTF-8') ?>)" style="background: #ff4d4f; border: 1px solid #ff4d4f; color: #fff; padding: 8px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.2s; white-space: nowrap; box-shadow: 0 2px 0 rgba(0,0,0,0.05);">知道了</button>
            </div>
        </div>
        <script>
        function muteAllDomains(domainIds) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'mute_domains', domain_ids: domainIds })
            }).then(res => res.json()).then(res => {
                if (res.success) {
                    const alertBox = document.getElementById('domain-alert-box');
                    alertBox.style.opacity = '0';
                    setTimeout(() => alertBox.style.display = 'none', 300);
                } else {
                    alert('操作失败，请重试');
                }
            }).catch(err => console.error(err));
        }
        </script>
        <?php endif; ?>

        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="overview-hero">
                <div class="hero-header">
                    <div class="hero-title">
                        <div class="hero-dot">∞</div>
                        <div>
                            <h2 style="margin:0;">站点总览 · <?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="hero-meta">根域名 <?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?> · 所选范围 <?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'overview', (int) $selectedSite['id']); ?>
                </div>
                <div class="metric-grid">
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></div>
                        <div class="metric-info"><div class="label">PV</div><div class="val"><?= $data['totals']['views'] ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                        <div class="metric-info"><div class="label">UV</div><div class="val"><?= $data['totals']['uniques'] ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></div>
                        <div class="metric-info"><div class="label">IP</div><div class="val"><?= $data['totals']['ip_count'] ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg></div>
                        <div class="metric-info"><div class="label">移动 PV</div><div class="val"><?= (int)($data['devices']['mobile']['views'] ?? 0) ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><circle cx="12" cy="18" r="1"></circle><path d="M12 13a4 4 0 0 0-4-4"></path><path d="M12 9a8 8 0 0 0-8-8"></path></svg></div>
                        <div class="metric-info"><div class="label">移动 IP</div><div class="val"><?= (int)($data['devices']['mobile']['ips'] ?? 0) ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                        <div class="metric-info"><div class="label">平均访问时长</div><div class="val"><?= $avgMinutes ?> min</div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></div>
                        <div class="metric-info"><div class="label">平均访问页数</div><div class="val"><?= $data['totals']['averages']['pages'] ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 10 4 15 9 20"></polyline><path d="M20 4v7a4 4 0 0 1-4 4H4"></path></svg></div>
                        <div class="metric-info"><div class="label">跳出率</div><div class="val"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div></div>
                    </div>

                    <div class="metric-tile">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%); color: #16a34a; box-shadow: 0 10px 22px rgba(22, 163, 74, 0.16);"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></div>
                        <div class="metric-info"><div class="label">预计今日 PV</div><div class="val"><?= $data['predictions']['views']?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%); color: #16a34a; box-shadow: 0 10px 22px rgba(22, 163, 74, 0.16);"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></div>
                        <div class="metric-info">
                            <div class="label">预计今日 IP</div>
                            <div class="val" style="display: flex; align-items: center; gap: 4px;">
                                <?= $data['predictions']['ips'] ?>
                                <?php 
                                if ($range === 'today' && $yesterdayTotals) {
                                    $predIps = (int)($data['predictions']['ips'] ?? 0);
                                    $yestIps = (int)($yesterdayTotals['ip_count'] ?? 0);
                                    if ($predIps > $yestIps) {
                                        echo '<svg style="width:14px;height:14px;color:#ef4444;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>';
                                    } elseif ($predIps < $yestIps) {
                                        echo '<svg style="width:14px;height:14px;color:#10b981;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%); color: #16a34a; box-shadow: 0 10px 22px rgba(22, 163, 74, 0.16);"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg></div>
                        <div class="metric-info"><div class="label">预计今日移动 PV</div><div class="val"><?= $data['predictions']['mobile_views'] ?? 0 ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%); color: #16a34a; box-shadow: 0 10px 22px rgba(22, 163, 74, 0.16);"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><circle cx="12" cy="18" r="1"></circle><path d="M12 13a4 4 0 0 0-4-4"></path><path d="M12 9a8 8 0 0 0-8-8"></path></svg></div>
                        <div class="metric-info">
                            <div class="label">预计今日移动 IP</div>
                            <div class="val" style="display: flex; align-items: center; gap: 4px;">
                                <?= $data['predictions']['mobile_ips'] ?? 0 ?>
                                <?php 
                                if ($range === 'today' && $yesterdayDevices) {
                                    $predMobIps = (int)($data['predictions']['mobile_ips'] ?? 0);
                                    $yestMobIps = (int)($yesterdayDevices['mobile']['ips'] ?? 0);
                                    if ($predMobIps > $yestMobIps) {
                                        echo '<svg style="width:14px;height:14px;color:#ef4444;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>';
                                    } elseif ($predMobIps < $yestMobIps) {
                                        echo '<svg style="width:14px;height:14px;color:#10b981;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($range === 'today' && $yesterdayTotals): ?>
                        <div class="metric-tile">
                            <div class="metric-icon yesterday"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></div>
                            <div class="metric-info"><div class="label">昨日 PV</div><div class="val"><?= $yesterdayTotals['views'] ?? 0 ?></div></div>
                        </div>
                        <div class="metric-tile">
                            <div class="metric-icon yesterday"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></div>
                            <div class="metric-info"><div class="label">昨日 IP</div><div class="val"><?= $yesterdayTotals['ip_count'] ?? 0 ?></div></div>
                        </div>
                        <div class="metric-tile">
                            <div class="metric-icon yesterday"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg></div>
                            <div class="metric-info"><div class="label">昨日移动 PV</div><div class="val"><?= (int)($yesterdayDevices['mobile']['views'] ?? 0) ?></div></div>
                        </div>
                        <div class="metric-tile">
                            <div class="metric-icon yesterday"><svg style="width:24px;height:24px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><circle cx="12" cy="18" r="1"></circle><path d="M12 13a4 4 0 0 0-4-4"></path><path d="M12 9a8 8 0 0 0-8-8"></path></svg></div>
                            <div class="metric-info"><div class="label">昨日移动 IP</div><div class="val"><?= (int)($yesterdayDevices['mobile']['ips'] ?? 0) ?></div></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($showTrend): ?>
                <section class="card">
                    <div class="section-title" style="justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div class="trend-controls">
                            <h3 style="margin:0;">趋势热力</h3>
                            <div class="trend-toggle main-type-toggle" style="display:flex; gap:8px;">
                                <button class="active" data-type="total">总趋势</button>
                                <button data-type="mobile">移动趋势</button>
                            </div>
                        </div>
                        <div class="trend-toggle metric-toggle">
                            <button class="active" data-metric="ips">IP</button>
                            <button data-metric="uniques">UV</button>
                            <button data-metric="views">PV</button>
                        </div>
                    </div>
                    <div class="chart-wrap trend-wrap"><div id="dailyTrendChart" style="width: 100%; height: 350px;"></div></div>
                </section>
            <?php endif; ?>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title"><h3>访问终端设备（IP）</h3><a class="filter-btn" href="/env.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
                        <div style="flex:1;min-width:240px;max-width:240px;margin:0 auto;">
                            <canvas id="devicePie" height="130"></canvas>
                        </div>
                        <div style="flex:1;min-width:220px;" class="metric-row">
                            <div class="metric"><div class="muted">电脑端 IP</div><div class="value"><?= (int) $data['devices']['desktop']['ips'] ?></div></div>
                            <div class="metric"><div class="muted">移动端 IP</div><div class="value"><?= (int) $data['devices']['mobile']['ips'] ?></div></div>
                        </div>
                    </div>
                    <div class="section-title" style="margin-top:12px;"><h4 style="margin:0;">浏览器分布（IP）</h4></div>
                    <div class="chart-wrap browser-pie"><canvas id="browserBar" height="576" style="display: block; box-sizing: border-box; height: 384px; width: 384px;" width="576"></canvas></div>
                </section>

                <section class="card">
                    <div class="section-title"><h3>新老访客</h3><a class="filter-btn" href="/audience.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div class="metric-row">
                        <div class="metric"><div class="muted">新访客 (IP)</div><div class="value"><?= (int) $data['new_vs_returning']['new_ips'] ?></div></div>
                        <div class="metric"><div class="muted">回访访客 (IP)</div><div class="value"><?= (int) $data['new_vs_returning']['returning_ips'] ?></div></div>
                    </div>
                    <div class="section-title" style="margin-top:12px;"><h4 style="margin:0;">入口页（前15名）</h4><a class="filter-btn" href="/entry.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div class="table-wrap" style="max-height: 580px;">
                        <table>
                            <thead><tr><th>入口页</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($entryPages as $row): ?>
                                <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['ips'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title"><h3>小时分布</h3><span class="muted">按选择的日期范围聚合</span></div>
                    <div class="table-wrap" style="max-height: 680px;">
                        <table>
                            <thead><tr><th>小时</th><th>PV</th><th>UV</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['hourly'] as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['hour'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title">
                        <h3>地域分布</h3>
                        <a class="filter-btn" href="/region.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                    </div>
                    <div id="chinaMap" style="width:100%;height:360px;margin-bottom:12px;"></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>地域</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($regions as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title">
                        <h3>来路</h3>
                        <div style="display:flex;gap:8px;">
                            <a class="filter-btn" href="/search_engine.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">搜索引擎</a>
                            <a class="filter-btn" href="/external.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">外部链接</a>
                            <a class="filter-btn" href="/referrer.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>来源</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($topReferrers as $row): ?>
                                <tr><td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['ips'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title">
                        <h3>受访页</h3>
                        <a class="filter-btn" href="/pages.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>页面</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($topPages as $row): ?>
                                <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['ips'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <script src="/t_statics/js/echarts.min.js"></script>
            <script src="/t_statics/js/china.js"></script>
            <script>
                <?php if ($showTrend): ?>
                const trendData = <?= json_encode($trend, JSON_UNESCAPED_UNICODE) ?>;
                const ctxDaily = document.getElementById('dailyTrendChart');
                let trendChart = null;
                let currentType = 'total';
                let currentMetric = 'ips';

                const renderTrend = () => {
                    if (!ctxDaily || !window.echarts || !trendData) return;
                    
                    if (trendChart) trendChart.dispose();
                    trendChart = echarts.init(ctxDaily);

                    // 核心逻辑：如果是移动趋势，则强制读取 mobile_ips 数据
                    let actualMetric = currentType === 'mobile' ? 'mobile_ips' : currentMetric;

                    const series = [];
                    const legends = [];

                    // 1. 今日数据 (纯正蓝实线 + 底部渐变)
                    if (trendData.primary) {
                        legends.push(trendData.primary_label);
                        series.push({
                            name: trendData.primary_label,
                            data: trendData.primary[actualMetric] || new Array(trendData.labels.length).fill(0),
                            type: 'line',
                            smooth: false, // <-- 核心修改：关闭平滑，改为直接折下（硬拐角直线）
                            symbol: 'circle',
                            symbolSize: 8,
                            showSymbol: false,
                            itemStyle: { color: '#1890ff', borderColor: '#fff', borderWidth: 2 },
                            lineStyle: { width: 2, type: 'solid' },
                            areaStyle: {
                                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                    { offset: 0, color: 'rgba(24, 144, 255, 0.25)' },
                                    { offset: 1, color: 'rgba(24, 144, 255, 0)' }
                                ])
                            }
                        });
                    }

                    // 2. 昨日数据 (纯正黄实线 + 底部渐变)
                    if (trendData.compare) {
                        legends.push(trendData.compare_label);
                        series.push({
                            name: trendData.compare_label,
                            data: trendData.compare[actualMetric] || new Array(trendData.labels.length).fill(0),
                            type: 'line',
                            smooth: false, // <-- 核心修改：关闭平滑，改为直接折下（硬拐角直线）
                            symbol: 'circle',
                            symbolSize: 8,
                            showSymbol: false,
                            itemStyle: { color: '#faad14', borderColor: '#fff', borderWidth: 2 },
                            lineStyle: { width: 2, type: 'solid' },
                            areaStyle: {
                                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                    { offset: 0, color: 'rgba(250, 173, 20, 0.25)' },
                                    { offset: 1, color: 'rgba(250, 173, 20, 0)' }
                                ])
                            }
                        });
                    }

                    trendChart.setOption({
                        tooltip: {
                            trigger: 'axis',
                            backgroundColor: 'rgba(23, 35, 61, 0.9)',
                            borderColor: 'transparent',
                            padding: [12, 16],
                            textStyle: { color: '#fff' },
                            axisPointer: { type: 'line', lineStyle: { color: '#d9d9d9', type: 'solid' } },
                            
                            // ==========================================
                            // 时间段格式化与比对保持完美状态
                            // ==========================================
                            formatter: function (params) {
                                if (!params || !params.length) return '';

                                let rawVal = String(params[0].axisValueLabel || params[0].axisValue).trim();
                                let displayTitle = rawVal;
                                
                                let isHour = (trendData.granularity === 'hour') || /^\d{1,2}(:\d{2})?$/.test(rawVal);
                                if (isHour) {
                                    let hNum = parseInt(rawVal, 10);
                                    if (!isNaN(hNum)) {
                                        let hStr = hNum.toString().padStart(2, '0');
                                        displayTitle = '时间：' + hStr + ':00 - ' + hStr + ':59';
                                    }
                                } else {
                                    displayTitle = '日期：' + rawVal;
                                }

                                let pData = params.find(p => p.seriesName === trendData.primary_label);
                                let cData = params.find(p => p.seriesName === trendData.compare_label);
                                
                                let pVal = pData ? Number(pData.value || 0) : 0;
                                let cVal = cData ? Number(cData.value || 0) : 0;

                                let diffHtml = '';
                                if (pData && cData) {
                                    let diff = pVal - cVal;
                                    if (cVal === 0) {
                                        diffHtml = pVal > 0 ? '<span style="color: #ed4014; font-weight: bold;">↑ 100.00%</span>' : '<span style="color: #808695; font-weight: bold;">0.00%</span>';
                                    } else {
                                        let pct = (diff / cVal) * 100;
                                        if (diff > 0) {
                                            diffHtml = '<span style="color: #ed4014; font-weight: bold;">↑ ' + pct.toFixed(2) + '%</span>';
                                        } else if (diff < 0) {
                                            diffHtml = '<span style="color: #19be6b; font-weight: bold;">↓ ' + Math.abs(pct).toFixed(2) + '%</span>';
                                        } else {
                                            diffHtml = '<span style="color: #808695; font-weight: bold;">0.00%</span>';
                                        }
                                    }
                                }

                                let html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; color: #808695;">' + 
                                           '<span>' + displayTitle + '</span>' + 
                                           '<span style="margin-left: 24px;">' + diffHtml + '</span>' + 
                                           '</div>';
                                
                                params.forEach(p => {
                                    let safeVal = (p.value !== undefined && p.value !== null && !isNaN(p.value)) ? Number(p.value).toLocaleString() : '0';
                                    html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">' +
                                            '<div style="display: flex; align-items: center; color: #c5c8ce; font-size: 13px;">' + p.marker + p.seriesName + '：</div>' +
                                            '<div style="color: #fff; font-weight: 600; font-size: 14px; margin-left: 36px;">' + safeVal + '</div>' +
                                            '</div>';
                                });
                                return html;
                            }
                        },
                        legend: {
                            data: legends,
                            top: 0,
                            right: 0,
                            icon: 'rect',
                            itemWidth: 16,
                            itemHeight: 4,
                            textStyle: { color: '#8c8c8c', fontSize: 12 }
                        },
                        grid: { left: '0%', right: '1%', bottom: '0%', top: '15%', containLabel: true },
                        xAxis: {
                            type: 'category',
                            data: trendData.labels,
                            boundaryGap: false,
                            axisLine: { show: false },
                            axisTick: { show: false },
                            axisLabel: { color: '#8c8c8c', margin: 12 }
                        },
                        yAxis: {
                            type: 'value',
                            axisLine: { show: false },
                            axisTick: { show: false },
                            splitLine: { lineStyle: { color: '#f0f0f0', type: 'solid' } },
                            axisLabel: { color: '#8c8c8c' }
                        },
                        series: series
                    });

                    window.addEventListener('resize', () => trendChart.resize());
                };

                // 绑定主趋势切换事件
                document.querySelectorAll('.main-type-toggle button').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.main-type-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        currentType = btn.dataset.type;
                        
                        // 当选择“移动趋势”时，隐藏右侧的 IP/UV/PV 选项
                        const metricToggle = document.querySelector('.metric-toggle');
                        if (metricToggle) {
                            metricToggle.style.display = currentType === 'mobile' ? 'none' : 'flex';
                        }
                        renderTrend();
                    });
                });

                // 绑定右侧指标切换事件
                document.querySelectorAll('.metric-toggle button').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.metric-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        currentMetric = btn.dataset.metric;
                        renderTrend();
                    });
                });

                renderTrend();
                <?php endif; ?>

                // 饼图通用配置
                const pieOptions = {
                    plugins: {
                        legend: { position: 'right', labels: { color: '#595959', usePointStyle: true, boxWidth: 8 } },
                        tooltip: {
                            backgroundColor: 'rgba(23, 35, 61, 0.85)', padding: 12,
                            callbacks: {
                                label: (ctx) => ` ${ctx.label}: ${ctx.parsed} IP`,
                                afterLabel: (ctx) => {
                                    const total = (ctx.dataset?.data || []).reduce((s, v) => s + Number(v || 0), 0) || 1;
                                    const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                    return ` 占比 ${pct}%`;
                                }
                            }
                        }
                    },
                    borderWidth: 0, cutout: '65%'
                };

                const deviceData = [
                    {label:'电脑端', value: <?= (int) $data['devices']['desktop']['ips'] ?>, color:'#1890ff'},
                    {label:'移动端', value: <?= (int) $data['devices']['mobile']['ips'] ?>, color:'#2fc25b'}
                ];
                const ctxDevice = document.getElementById('devicePie');
                if (ctxDevice && window.Chart) {
                    new Chart(ctxDevice, {
                        type:'doughnut',
                        data:{
                            labels: deviceData.map(d=>d.label),
                            datasets:[{data: deviceData.map(d=>d.value), backgroundColor: deviceData.map(d=>d.color), borderWidth: 2, borderColor: '#fff'}]
                        },
                        options: pieOptions
                    });
                }

                const browserRows = <?= json_encode($data['browsers'], JSON_UNESCAPED_UNICODE) ?>;
                const ctxBrowser = document.getElementById('browserBar');
                if (ctxBrowser && window.Chart) {
                    const palette = ['#1890ff','#2fc25b','#facc14','#f04864','#8543e0','#13c2c2','#3436c7','#223273'];
                    new Chart(ctxBrowser, {
                        type:'doughnut',
                        data:{
                            labels: browserRows.map(r=>r.browser || '未知'),
                            datasets:[{data: browserRows.map(r=>Number(r.ips)), backgroundColor: browserRows.map((_,i)=>palette[i % palette.length]), borderWidth: 2, borderColor: '#fff'}]
                        },
                        options: { ...pieOptions, cutout: '55%' }
                    });
                }
                
                // 地图初始化
                const chinaRows = <?= json_encode($data['china_map'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
                const chinaEl = document.getElementById('chinaMap');
                if (chinaEl && window.echarts) {
                    const chinaChart = echarts.init(chinaEl);
                    const mapData = (chinaRows || []).map(row => ({
                        name: row.region || '未知',
                        value: Number(row.ips || 0),
                    }));
                    const maxVal = mapData.reduce((m, r) => Math.max(m, r.value || 0), 0) || 1;
                    chinaChart.setOption({
                        tooltip: { trigger: 'item', backgroundColor: 'rgba(23, 35, 61, 0.85)', textStyle: { color: '#fff' }, padding: [12, 16], formatter: '{b}<br/>IP: {c}' },
                        visualMap: { min: 0, max: maxVal, left: 'left', bottom: '0', text: ['多', '少'], inRange: { color: ['#e6f7ff', '#1890ff'] }, calculable: true, textStyle: {color: '#8c8c8c'} },
                        series: [{ name: '地域分布', type: 'map', map: 'china', roam: false, data: mapData, emphasis: { label: { show: true }, itemStyle: { areaColor: '#69c0ff' } }, itemStyle: { borderColor: '#fff', borderWidth: 1 } }]
                    });
                    window.addEventListener('resize', () => chinaChart.resize());
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

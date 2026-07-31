<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}
// ==================================

// 获取当前用户ID，确保屏蔽配置与 external.php 互通
$userId = $_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? ($selectedSite['user_id'] ?? 0));

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$settingKey = "user_{$userId}_blocked_external_refs"; // 完全互通的 Key

$device = $_GET['device'] ?? 'all';
$visitorType = $_GET['visitor'] ?? 'all';
$rangeParam = htmlspecialchars($range, ENT_QUOTES, 'UTF-8');

// ==================================
// 处理屏蔽/解除屏蔽的 POST 请求
// ==================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$settingKey]);
    $existing = json_decode($stmt->fetchColumn() ?: '[]', true);
    
    $domain = trim($_POST['domain'] ?? '');
    if ($domain !== '') {
        if ($_POST['action'] === 'block') {
            if (!in_array($domain, $existing)) {
                $existing[] = $domain;
            }
        } elseif ($_POST['action'] === 'unblock') {
            $existing = array_values(array_filter($existing, fn($d) => $d !== $domain));
        }
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $json = json_encode($existing, JSON_UNESCAPED_UNICODE);
        $stmt->execute([$settingKey, $json, $json]);
    }
    
    // 动作完成后刷新页面，携带原有的筛选参数
    $qs = http_build_query(['site' => $siteId, 'range' => $range, 'device' => $device, 'visitor' => $visitorType]);
    header("Location: /referrer.php?{$qs}");
    exit;
}

// ==================================
// 获取屏蔽列表并构建过滤闭包
// ==================================
$stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute([$settingKey]);
$blockedDomains = json_decode($stmt->fetchColumn() ?: '[]', true);

$isBlocked = function($host) use ($blockedDomains) {
    if (!$host) return false;
    foreach ($blockedDomains as $bd) {
        if ($host === $bd) return true;
        // 支持通配符匹配 (如 *.spam.com 匹配 sub.spam.com)
        if (str_starts_with($bd, '*.') && str_ends_with($host, substr($bd, 1))) return true;
    }
    return false;
};

// ==================================
// 获取数据、过滤及分页计算
// ==================================
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$filters = ['device' => $device, 'visitor' => $visitorType];

$data = $selectedSite ? $tracker->getReferrerData($siteId, $range, $filters) : null;
$allReferrers = $data['referrers'] ?? [];
$summary = $data['ref_summary'] ?? [];

// 如果存在屏蔽规则，利用 parse_url 提取 host 进行实时数据过滤
if (!empty($blockedDomains) && !empty($allReferrers)) {
    $allReferrers = array_values(array_filter($allReferrers, function($row) use ($isBlocked) {
        $host = parse_url($row['referrer'], PHP_URL_HOST) ?? '';
        return !$isBlocked($host);
    }));
}

$totalReferrers = count($allReferrers);
$totalPages = max(1, (int) ceil($totalReferrers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$referrerPage = array_slice($allReferrers, ($page - 1) * $perPage, $perPage);

// ==========================================
// 新增：计算渠道贡献度的分母（列表中所有渠道 IP 之和）
// ==========================================
$totalAttributionIps = 0;
if (!empty($allReferrers)) {
    $totalAttributionIps = array_sum(array_column($allReferrers, 'ips'));
}

function ref_duration_format($seconds): string {
    $seconds = (int) round($seconds);
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

render_head('来路详情 - 统计后台');
render_topbar($branding);
?>
<style>
    .pill-tag { background:#e0f2fe; color:#0284c7; padding:4px 10px; border-radius:999px; font-weight:600; font-size:12px; }
    
    /* 屏蔽列表标签及按钮样式 (与 external 互通) */
    .blocked-tags-container { padding: 12px 16px; background: #fff1f0; border-radius: 6px; margin-bottom: 16px; border: 1px solid #ffccc7; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .blocked-tag { display: inline-flex; align-items: center; background: #fff; color: #cf1322; border: 1px solid #ffa39e; padding: 3px 8px; border-radius: 4px; font-size: 13px; font-weight: 500; }
    .blocked-tag-btn { background: none; border: none; color: #cf1322; cursor: pointer; padding: 0 0 0 6px; font-size: 16px; line-height: 1; }
    .blocked-tag-btn:hover { color: #a8071a; }
    
    .action-btn { background: none; border: none; cursor: pointer; color: #808695; font-size: 13px; text-decoration: underline; margin-right: 12px; transition: color 0.2s; padding: 0; }
    .action-btn:hover { color: #cf1322; }

    /* 防止超长 URL 撑破表格导致布局错乱 */
    .url-ellipsis {
        display: inline-block;
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
        color: #1e293b;
        font-weight: 500;
    }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'referrer', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路详情</h2>
                        <p class="muted" style="margin:2px 0 0;">支持设备 / 访客类型筛选，默认剔除自有域名</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'referrer', (int) $selectedSite['id'], ['device' => $device, 'visitor' => $visitorType]); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="gap:12px; flex-wrap:wrap;">
                    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="site" value="<?= (int) $siteId ?>">
                        <input type="hidden" name="range" value="<?= $rangeParam ?>">
                        <input type="hidden" name="page" value="1">
                        <label class="muted">设备类型</label>
                        <select name="device" style="padding:8px 10px;border-radius:8px;border:1px solid var(--border);">
                            <option value="all" <?= $device === 'all' ? 'selected' : '' ?>>全部</option>
                            <option value="desktop" <?= $device === 'desktop' ? 'selected' : '' ?>>电脑端</option>
                            <option value="mobile" <?= $device === 'mobile' ? 'selected' : '' ?>>移动端</option>
                        </select>
                        <label class="muted">访客类型</label>
                        <select name="visitor" style="padding:8px 10px;border-radius:8px;border:1px solid var(--border);">
                            <option value="all" <?= $visitorType === 'all' ? 'selected' : '' ?>>全部</option>
                            <option value="new" <?= $visitorType === 'new' ? 'selected' : '' ?>>新访客</option>
                            <option value="return" <?= $visitorType === 'return' ? 'selected' : '' ?>>老访客</option>
                        </select>
                        <button type="submit" class="filter-btn active" style="padding:8px 16px;">筛选</button>
                    </form>
                </div>
                <?php if ($data): ?>
                <div class="metric-row" style="gap:12px; margin-top: 16px;">
                    <div class="metric"><div class="muted">IP数</div><div class="value"><?= (int) ($summary['ips'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">浏览量 (PV)</div><div class="value"><?= (int) ($summary['views'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">访客数 (UV)</div><div class="value"><?= (int) ($summary['uv'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">新访客数</div><div class="value"><?= (int) ($summary['new'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">会话数</div><div class="value"><?= (int) ($summary['sessions'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</div></div>
                    <div class="metric"><div class="muted">平均浏览页数</div><div class="value"><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></div></div>
                    <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= ref_duration_format($summary['avg_duration'] ?? 0) ?></div></div>
                </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <div class="section-title" style="margin-bottom:12px;">
                    <h3 style="margin:0;">来路列表</h3>
                    <span class="muted">每页 <?= $perPage ?> 条</span>
                </div>

                <?php if (!empty($blockedDomains)): ?>
                    <div class="blocked-tags-container">
                        <span style="font-size:13px; color:#cf1322; font-weight:600; margin-right:4px;">已屏蔽来源 (与外部链接互通)：</span>
                        <?php foreach ($blockedDomains as $bd): ?>
                            <span class="blocked-tag">
                                <?= htmlspecialchars($bd, ENT_QUOTES, 'UTF-8') ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="unblock">
                                    <input type="hidden" name="domain" value="<?= htmlspecialchars($bd, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="blocked-tag-btn" title="解除屏蔽">&times;</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th style="width: 30%">来源 URL</th>
                            <th>IP数</th>
                            <th>占比 (贡献度)</th>
                            <th>访客数</th>
                            <th>新访客数</th>
                            <th>贡献浏览量</th>
                            <th>平均浏览页数</th>
                            <th>平均访问时长</th>
                            <th>跳出率</th>
                            <th style="text-align:right; width: 150px;">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($referrerPage)): ?>
                            <tr><td colspan="10" class="muted" style="text-align:center;">暂无来路数据或数据已被全部屏蔽</td></tr>
                        <?php else: ?>
                            <tr style="font-weight:700; background-color: #fafafa;">
                                <td>全站独立 IP <span class="muted" style="font-weight:normal;font-size:12px;">(屏蔽前总计)</span></td>
                                <td><?= (int) ($summary['ips'] ?? 0) ?></td>
                                <td></td>
                                <td><?= (int) ($summary['uv'] ?? 0) ?></td>
                                <td><?= (int) ($summary['new'] ?? 0) ?></td>
                                <td><?= (int) ($summary['views'] ?? 0) ?></td>
                                <td><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></td>
                                <td><?= ref_duration_format($summary['avg_duration'] ?? 0) ?></td>
                                <td><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                                <td></td>
                            </tr>
                            <?php foreach ($referrerPage as $row): ?>
                                <?php 
                                    $refUrl = htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8'); 
                                    $host = parse_url($row['referrer'], PHP_URL_HOST) ?? '';
                                ?>
                                <tr>
                                    <td>
                                        <span class="url-ellipsis" title="<?= $refUrl ?>">
                                            <?= $refUrl ?>
                                        </span>
                                    </td>
                                    <td><?= (int) $row['ips'] ?></td>
                                    <td style="width: 130px;">
                                        <?php 
                                            $currentIps = (int) ($row['ips'] ?? 0);
                                            $percent = $totalAttributionIps > 0 ? round(($currentIps / $totalAttributionIps) * 100, 2) : 0;
                                        ?>
                                        <div style="font-size: 13px; font-weight: 600; color: #475569;">
                                            <?= $percent ?>%
                                        </div>
                                        <div style="height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 4px; width: 100%;">
                                            <div style="height: 100%; background: #1690ff; border-radius: 3px; width: <?= $percent ?>%;"></div>
                                        </div>
                                    </td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) ($row['new'] ?? 0) ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= number_format((float) $row['avg_pages'], 2) ?></td>
                                    <td><?= ref_duration_format($row['avg_duration']) ?></td>
                                    <td>
                                        <?php $bounce = round(($row['bounce_rate'] ?? 0) * 100, 2); ?>
                                        <span style="color: <?= $bounce > 80 ? '#ef4444' : 'inherit' ?>;">
                                            <?= $bounce ?>%
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($host): ?>
                                            <button type="button" class="action-btn" onclick="blockExact('<?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?>')">屏蔽精准</button>
                                            <button type="button" class="action-btn" style="margin-right:0;" onclick="blockRoot('<?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?>')">屏蔽根域</button>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:12px;">无域名</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                    render_pagination(
                        $page,
                        $totalPages,
                        '/referrer.php',
                        ['site' => (int) $siteId, 'range' => $range, 'device' => $device, 'visitor' => $visitorType]
                    );
                ?>
            </section>

            <script>
                function submitBlockForm(domain) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="action" value="block"><input type="hidden" name="domain" value="${domain}">`;
                    document.body.appendChild(form);
                    form.submit();
                }

                function blockExact(domain) {
                    if (confirm('确定要将该确切的域名加入屏蔽名单吗？\n\n' + domain + '\n\n加入后，来自该域名的流量将不再显示在此列表以及“外部链接”列表中。')) {
                        submitBlockForm(domain);
                    }
                }

                function blockRoot(domain) {
                    // 智能推导根域名 (处理普通域名和 .com.cn 等复合后缀)
                    let parts = domain.split('.');
                    let defaultRoot = '*.' + domain;
                    
                    if (parts.length > 2) {
                        if (parts[parts.length - 1].length === 2 && parts[parts.length - 2].length <= 3) {
                            defaultRoot = '*.' + parts.slice(-3).join('.');
                        } else {
                            defaultRoot = '*.' + parts.slice(-2).join('.');
                        }
                    }

                    let userConfirmedRule = prompt('请确认要屏蔽的通配符规则：\n这将屏蔽该规则下的所有子域名。', defaultRoot);
                    if (userConfirmedRule && userConfirmedRule.trim() !== '') {
                        submitBlockForm(userConfirmedRule.trim());
                    }
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

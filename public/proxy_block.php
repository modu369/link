<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 安全拦截：普通用户禁止访问系统级日志（仅限管理员或站点所有者） ===
if (!isset($is_admin) || !$is_admin) {
    if (!$selectedSite) {
        die('无权访问系统级风控拦截日志。');
    }
}

// ==================================
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// 1. 获取物理条数与历史总数
$rawQueueTotal = $tracker->getBlockedProxyIpCount();   // 队列中的原始物理条数（最大1000，用于UI展示）
$absoluteTotal = $tracker->getTotalBlockedCount();     // 历史总拦截计数

// ==========================================
// = 核心修复：先取出所有数据进行全量聚合，再进行切片分页
// ==========================================
// 一次性取出最多 1000 条记录（Redis 内存操作，极快）
$allRawRows = $tracker->getBlockedProxyIps(1000, 0);

$groupedRows = [];
$unnamedIndex = 0;

// 对这 1000 条记录进行全量 UID 合并
foreach ($allRawRows as $row) {
    $uid = trim($row['uid'] ?? '');
    if ($uid !== '') {
        if (!isset($groupedRows[$uid])) {
            $row['ips'] = [$row['ip']];
            $groupedRows[$uid] = $row;
        } else {
            if (!in_array($row['ip'], $groupedRows[$uid]['ips'])) {
                $groupedRows[$uid]['ips'][] = $row['ip'];
            }
        }
    } else {
        $row['ips'] = [$row['ip']];
        $groupedRows['unnamed_' . $unnamedIndex++] = $row;
    }
}

// 重置数组索引，得到合并后的总纯净数据
$allGroupedRows = array_values($groupedRows);

// 基于合并后的真实行数计算分页
$groupedTotal = count($allGroupedRows);
$totalPages = max(1, (int) ceil($groupedTotal / $perPage));
$page = min($page, $totalPages); // 防止页码越界

// 使用 array_slice 截取当前页需要展示的 50 条合并后数据
$offset = ($page - 1) * $perPage;
$rows = array_slice($allGroupedRows, $offset, $perPage);
// ==========================================

// 2. 获取今日实时拦截统计
$blockedStats = $tracker->getBlockedStats($siteId, 'today');

render_head('风控拦截明细 - 统计后台');
render_topbar($branding);
?>
<style>
    .metric-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .metric-card { background: #fff; padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
    .metric-card .label { font-size: 13px; color: var(--muted); margin-bottom: 8px; }
    .metric-card .value { font-size: 24px; font-weight: bold; color: var(--text); }
    .metric-card .sub-value { font-size: 12px; color: #ff4d4f; margin-top: 4px; font-weight: bold; }
    .reason-tag { background: #fff1f0; color: #ff4d4f; padding: 2px 8px; border-radius: 4px; font-size: 12px; border: 1px solid #ffccc7; font-weight: bold; }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'proxy_block', $range); ?>
    <main class="content">
        <section class="card">
            <div class="section-title" style="margin-bottom: 20px;">
                <div>
                    <h2 style="margin:0;">实时风控拦截明细</h2>
                    <p class="muted" style="margin:4px 0 0;">
                        展示系统最近拦截的刷量日志。多IP同设备的攻击已被智能折叠。<br>
                        <i>注：IDC机房/海外节点采用 <b>IP全局封禁</b>；国内网络采用 <b>设备级精准封禁</b>。</i>
                    </p>
                </div>
                <div style="text-align: right;">
                    <?php if ($absoluteTotal > 1000): ?>
                        <span class="pill">历史总拦截 <?= number_format($absoluteTotal) ?> 次</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="metric-row">
                <div class="metric-card">
                    <div class="label">今日拦截总 IP</div>
                    <div class="value"><?= number_format($blockedStats['ip']) ?></div>
                    <div class="sub-value">移动端: <?= number_format($blockedStats['mobile_ip']) ?></div>
                </div>
                <div class="metric-card">
                    <div class="label">今日拦截总 PV</div>
                    <div class="value"><?= number_format($blockedStats['pv']) ?></div>
                    <div class="sub-value">移动端: <?= number_format($blockedStats['mobile_pv']) ?></div>
                </div>
                <div class="metric-card" style="background: #fafafa; border-style: dashed;">
                    <div class="label">当前风控队列 (独立设备)</div>
                    <div class="value"><?= $groupedTotal ?> <small style="font-size: 12px; font-weight: normal; color:var(--muted);">/ 原始队列 <?= $rawQueueTotal ?></small></div>
                    <div class="sub-value" style="color: var(--muted);">队列满1000后将自动滚动覆盖</div>
                </div>
            </div>
        </section>

        <section class="card">
            <?php if (empty($rows)): ?>
                <div class="empty">暂无拦截记录。系统运行安全。</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th width="110">拦截类型</th>
                            <th width="150">风险 IP</th>
                            <th width="210">拦截诱因</th> 
                            <th width="100">风险设备 (UID)</th>
                            <th width="80">评分</th>
                            <th width="160">拦截时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (str_contains($row['type'], 'IP全局')): ?>
                                        <span class="badge" style="background: #ffebee; color: #d32f2f; padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($row['type']) ?></span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #e3f2fd; color: #1976d2; padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($row['type']) ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php
                                        $ipCount = count($row['ips']);
                                        $firstIp = htmlspecialchars($row['ips'][0], ENT_QUOTES, 'UTF-8');
                                        
                                        if ($ipCount > 1) {
                                            $hoverIps = array_slice($row['ips'], 0, 3);
                                            // 使用 \n 让悬停的提示框里的 IP 也换行显示，更加整洁
                                            $hoverText = implode("\n", $hoverIps);
                                            if ($ipCount > 3) {
                                                $hoverText .= "\n...";
                                            }
                                    ?>
                                        <span style="font-family: monospace; font-size: 14px; font-weight: bold; cursor: help; border-bottom: 1px dashed #ccc;" title="<?= htmlspecialchars($hoverText, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= $firstIp ?> <span style="font-size:12px; color:#d32f2f; font-weight:normal;">[<?= $ipCount ?>个独立IP]</span>
                                        </span>
                                    <?php } else { ?>
                                        <span style="font-family: monospace; font-size: 14px; font-weight: bold;">
                                            <?= $firstIp ?>
                                        </span>
                                    <?php } ?>
                                </td>
                                
                                <td>
                                    <span class="reason-tag">
                                        <?= htmlspecialchars($row['reason'] ?? '未知特征') ?>
                                    </span>
                                </td>

                                <?php 
                                    $fullUid = htmlspecialchars($row['uid'], ENT_QUOTES, 'UTF-8');
                                    $displayUid = mb_strlen($row['uid'], 'UTF-8') > 20 
                                        ? mb_substr($row['uid'], 0, 8, 'UTF-8') . '...' . mb_substr($row['uid'], -8, null, 'UTF-8') 
                                        : $fullUid;
                                ?>
                                <td title="<?= $fullUid ?>">
                                    <span style="font-family: monospace; font-size: 12px; color: #666; cursor: help; border-bottom: 1px dashed #ccc;">
                                        <?= $displayUid ?>
                                    </span>
                                </td>
                                
                                <td><span style="color: #d32f2f; font-weight: bold;"><?= (int)$row['score'] ?></span></td>
                                <td class="muted" style="font-size: 12px;"><?= htmlspecialchars($row['detected_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_pagination($page, $totalPages, '/proxy_block.php', ['site' => (int) $siteId]); ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}
// ==================================
$data = $selectedSite ? $tracker->getMobileData($siteId, $range) : null;

// === 提取汇总行与明细行 ===
$summaryRows = [];
$normalRows = [];

if ($data && !empty($data['breakdown'])) {
    foreach ($data['breakdown'] as $row) {
        if ($row['domain'] === '全局预测' || $row['domain'] === '全局汇总') {
            $summaryRows[] = $row;
        } else {
            $normalRows[] = $row;
        }
    }
}

render_head('移动端 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'mobile', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">移动端数据</h2>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'mobile', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>移动端与总量拆分</h3><span class="muted">受访域名对比</span></div>
                <table>
                    <thead><tr><th>受访域名</th><th>PV</th><th>IP</th><th>移动PV</th><th>移动IP</th></tr></thead>
                    <tbody>
                    
                    <?php foreach ($summaryRows as $row): ?>
                        <tr style="background-color: #f8f9fa; font-weight: bold;">
                            <td><?= htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td>
                                <?= (int) $row['ips'] ?>
                                <?php if (!empty($row['has_sum_comparison'])): ?>
                                    <span style="font-size: 0.85em; color: #888; font-weight: normal; margin-left: 4px;">
                                        (累加: <?= (int) $row['sum_ips'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $row['mobile_views'] ?></td>
                            <td>
                                <?= (int) $row['mobile_ips'] ?>
                                <?php if (!empty($row['has_sum_comparison'])): ?>
                                    <span style="font-size: 0.85em; color: #888; font-weight: normal; margin-left: 4px;">
                                        (累加: <?= (int) $row['sum_mobile_ips'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php foreach ($normalRows as $row): ?>
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
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

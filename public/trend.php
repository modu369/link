<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getTrendData($siteId, $range) : null;

render_head('趋势分析 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'trend', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">趋势分析</h2>
                        <p class="muted" style="margin:2px 0 0;">按天/小时对 PV、UV、IP 做时间序列展示</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'trend', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>日趋势</h3><span class="muted">所选时间范围</span></div>
                <table>
                    <thead><tr><th>日期</th><th>PV</th><th>UV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['daily'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['day'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['uniques'] ?></td>
                            <td><?= (int) $row['ip_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>小时分布</h3><span class="muted">按所选范围聚合</span></div>
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
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

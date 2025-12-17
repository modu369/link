<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getIspData($siteId, $range) : null;

render_head('运营商分布 - 统计后台');
render_topbar($config);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'isp', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">网络服务运营商</h2>
                        <p class="muted" style="margin:2px 0 0;">基于常见网段推测，按 IP 聚合</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'isp', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>运营商列表</h3><span class="muted">前 50</span></div>
                <table>
                    <thead><tr><th>运营商</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['isps'])): ?>
                        <tr><td colspan="3" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['isps'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['isp'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

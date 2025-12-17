<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getRegionData($siteId, $range) : null;

render_head('地域分布 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'region', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">地域分布</h2>
                        <p class="muted" style="margin:2px 0 0;">按省市/网段聚合，基于 IP</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'region', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>地域列表</h3><span class="muted">前 50</span></div>
                <table>
                    <thead><tr><th>地域</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['regions'])): ?>
                        <tr><td colspan="3" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['regions'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
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

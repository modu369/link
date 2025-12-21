<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getMobileData($siteId, $range) : null;

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
                        <p class="muted" style="margin:2px 0 0;">按域名合并（含 www.），移动 IP 指在区间内出现过移动访问的 IP，即便也访问过电脑端。</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'mobile', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>移动端与总量拆分</h3><span class="muted">受访域名对比</span></div>
                <table>
                    <thead><tr><th>受访域名</th><th>PV</th><th>IP</th><th>移动PV</th><th>移动IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['breakdown'] as $row): ?>
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

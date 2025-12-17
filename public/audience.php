<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getAudienceData($siteId, $range) : null;

render_head('访客画像 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'audience', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">新老访客</h2>
                        <p class="muted" style="margin:2px 0 0;">以 IP 维度区分新老访客</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'audience', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>新老访客分布</h3><span class="muted">按 PV / IP</span></div>
                <table>
                    <thead><tr><th>类型</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>新访客</td><td><?= (int) $data['new_vs_returning']['new'] ?></td><td><?= (int) $data['new_vs_returning']['new_ips'] ?></td></tr>
                        <tr><td>回访访客</td><td><?= (int) $data['new_vs_returning']['returning'] ?></td><td><?= (int) $data['new_vs_returning']['returning_ips'] ?></td></tr>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

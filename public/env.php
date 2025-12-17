<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getVisitorEnv($siteId, $range) : null;

render_head('系统环境概览 - 统计后台');
render_topbar($config);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'env', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">系统环境概览</h2>
                        <p class="muted" style="margin:2px 0 0;">设备类别与浏览器类型按 IP 聚合</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'env', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>设备类别</h3><span class="muted">按 PV / IP</span></div>
                <table>
                    <thead><tr><th>类别</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>电脑端</td><td><?= (int) $data['devices']['desktop']['views'] ?></td><td><?= (int) $data['devices']['desktop']['ips'] ?></td></tr>
                        <tr><td>移动端</td><td><?= (int) $data['devices']['mobile']['views'] ?></td><td><?= (int) $data['devices']['mobile']['ips'] ?></td></tr>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>浏览器类型</h3><span class="muted">前 10</span></div>
                <table>
                    <thead><tr><th>浏览器</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['browsers'])): ?>
                        <tr><td colspan="3" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['browsers'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['browser'], ENT_QUOTES, 'UTF-8') ?></td>
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

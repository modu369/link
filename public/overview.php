<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getOverview($siteId, $range) : null;

render_head('总览 - 统计后台');
render_topbar($config);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'overview', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">总览</h2>
                        <p class="muted" style="margin:2px 0 0;">全部功能分屏展示，避免数据堆叠</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'overview', (int) $selectedSite['id']); ?>
                </div>
                <code class="inline">&lt;script src="<?= rtrim($config['app']['base_url'], '/') ?>/js/tracker.js" data-site="<?= htmlspecialchars($selectedSite['tracking_id'], ENT_QUOTES, 'UTF-8') ?>"&gt;&lt;/script&gt;</code>
            </section>

            <section class="metric-row">
                <div class="metric"><div class="muted">PV</div><div class="value"><?= $data['totals']['views'] ?></div></div>
                <div class="metric"><div class="muted">UV</div><div class="value"><?= $data['totals']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">IP</div><div class="value"><?= $data['totals']['ip_count'] ?></div></div>
                <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= $data['totals']['averages']['duration'] ?>s</div></div>
                <div class="metric"><div class="muted">平均访问页数</div><div class="value"><?= $data['totals']['averages']['pages'] ?></div></div>
                <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div></div>
                <div class="metric"><div class="muted">预计今日 PV</div><div class="value"><?= $data['predictions']['views'] ?></div></div>
                <div class="metric"><div class="muted">预计今日 UV</div><div class="value"><?= $data['predictions']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">预计今日 IP</div><div class="value"><?= $data['predictions']['ips'] ?></div></div>
            </section>

            <section class="card">
                <div class="section-title"><h3>趋势（按所选范围）</h3><span class="muted">PV / UV / IP</span></div>
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
                <div class="section-title"><h3>小时分布</h3><span class="muted">按选择的日期范围聚合</span></div>
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

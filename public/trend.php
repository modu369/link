<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getTrendData($siteId, $range) : null;
$overview = $selectedSite ? $tracker->getOverview($siteId, $range) : null;
$trendLines = $selectedSite ? $tracker->getTrendLines($siteId, $range) : null;

render_head('趋势分析 - 统计后台');
render_topbar($branding);
?>
<style>
    .trend-metrics { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
    .trend-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:12px; box-shadow:0 10px 28px rgba(22,144,255,0.1); display:flex; gap:10px; align-items:center; }
    .trend-icon { width:44px; height:44px; border-radius:12px; background:#deedfb; display:grid; place-items:center; color:#1690ff; font-weight:800; }
    .trend-info { display:flex; flex-direction:column; gap:2px; }
    .trend-info .label { color:var(--muted); font-size:13px; }
    .trend-info .val { font-size:20px; font-weight:800; }
    .trend-canvas { width:100%; height:320px; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'trend', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <?php render_rollup_notice(!empty($data['rollup_pending'])); ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">趋势分析</h2>
                        <p class="muted" style="margin:2px 0 0;">按天/小时对 PV、UV、IP 做时间序列展示</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'trend', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <?php if ($overview): ?>
                <section class="card">
                    <div class="section-title" style="justify-content:space-between; align-items:center;">
                        <h3 style="margin:0;">数据概况</h3>
                        <div style="display:flex;gap:6px;">
                            <span class="pill-tag">范围：<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="pill-tag">站点：<?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="trend-metrics">
                        <div class="trend-card"><div class="trend-icon">PV</div><div class="trend-info"><div class="label">访问量</div><div class="val"><?= (int)$overview['totals']['views'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">UV</div><div class="trend-info"><div class="label">访客数</div><div class="val"><?= (int)$overview['totals']['uniques'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">IP</div><div class="trend-info"><div class="label">IP</div><div class="val"><?= (int)$overview['totals']['ip_count'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">⏱</div><div class="trend-info"><div class="label">平均访问时长</div><div class="val"><?= round(($overview['totals']['averages']['duration'] ?? 0)/60,1) ?> min</div></div></div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($trendLines): ?>
                <section class="card">
                    <div class="section-title" style="justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div class="trend-controls">
                            <h3 style="margin:0;">趋势折线</h3>
                            <span class="pill-tag"><?= $trendLines['granularity'] === 'hour' ? '小时对比' : '按天走势' ?></span>
                        </div>
                        <div class="trend-toggle">
                            <button class="active" data-metric="ips">IP</button>
                            <button data-metric="uniques">UV</button>
                            <button data-metric="views">PV</button>
                        </div>
                    </div>
                    <div class="chart-wrap trend-wrap"><canvas id="trendLineCanvas" class="trend-canvas"></canvas></div>
                </section>
            <?php endif; ?>

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
<script>
    const trendSource = <?= json_encode($trendLines, JSON_UNESCAPED_UNICODE) ?>;
    const trendCanvas = document.getElementById('trendLineCanvas');
    let trendLineChart = null;

    const renderTrendLine = (metric = 'ips') => {
        if (!trendCanvas || !window.Chart || !trendSource) return;
        const ctx = trendCanvas.getContext('2d');
        const grad1 = ctx.createLinearGradient(0, 0, 0, 200);
        grad1.addColorStop(0, '#1690ff');
        grad1.addColorStop(1, 'rgba(22,144,255,0.08)');
        const grad2 = ctx.createLinearGradient(0, 0, 0, 200);
        grad2.addColorStop(0, '#73c1ff');
        grad2.addColorStop(1, 'rgba(115,193,255,0.08)');

        const datasets = [
            {
                label: trendSource.primary_label,
                data: trendSource.primary?.[metric] || [],
                borderColor: '#1690ff',
                backgroundColor: grad1,
                tension: 0.35,
                fill: true,
            }
        ];

        if (trendSource.compare) {
            datasets.push({
                label: trendSource.compare_label,
                data: trendSource.compare?.[metric] || [],
                borderColor: '#73c1ff',
                backgroundColor: grad2,
                tension: 0.35,
                fill: true,
            });
        }

        if (trendLineChart) trendLineChart.destroy();
        trendLineChart = new Chart(trendCanvas, {
            type: 'line',
            data: { labels: trendSource.labels, datasets },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
                scales: {
                    x: { ticks: { maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    };

    document.querySelectorAll('.trend-toggle button').forEach(btn => {
        btn?.addEventListener('click', () => {
            document.querySelectorAll('.trend-toggle button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderTrendLine(btn.dataset.metric);
        });
    });

    renderTrendLine('ips');
</script>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getOverview($siteId, $range) : null;
$avgMinutes = $data ? round(($data['totals']['averages']['duration'] ?? 0) / 60, 1) : 0;
$trend = $data['trend'] ?? null;
$topReferrers = $data ? array_slice($data['top_referrers'], 0, 20) : [];
$topPages = $data ? array_slice($data['top_pages'], 0, 20) : [];
$entryPages = $data ? array_slice($data['entry_pages'], 0, 20) : [];
$regions = $data ? array_slice($data['regions'], 0, 20) : [];

render_head('总览 - 统计后台');
render_topbar($branding);
?>
<style>
    .overview-hero { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 18px; box-shadow: 0 16px 40px rgba(22, 144, 255, 0.18); display: flex; flex-direction: column; gap: 12px; }
    .hero-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .hero-title { display: flex; align-items: center; gap: 12px; }
    .hero-dot { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #1690ff, #73c1ff); display: grid; place-items: center; color: #fff; font-size: 18px; font-weight: 700; }
    .hero-meta { color: var(--muted); font-size: 13px; }
    .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
    .metric-tile { background: linear-gradient(135deg, #deedfb 0%, #f7fbff 100%); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: grid; grid-template-columns: 48px 1fr; gap: 10px; align-items: center; box-shadow: inset 0 1px 0 rgba(255,255,255,0.6); }
    .metric-icon { width: 48px; height: 48px; border-radius: 12px; background: #fff; display: grid; place-items: center; color: #1690ff; font-size: 22px; box-shadow: 0 10px 22px rgba(22,144,255,0.16); }
    .metric-info { display: flex; flex-direction: column; gap: 2px; }
    .metric-info .label { color: var(--muted); font-size: 13px; }
    .metric-info .val { font-size: 22px; font-weight: 800; color: #0f172a; }
    .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 12px; align-items: stretch; }
    .pill-tag { background: #deedfb; color: #1690ff; padding: 4px 10px; border-radius: 999px; font-weight: 700; border: 1px solid var(--border); }
    .chart-wrap { position: relative; width: 100%; }
    .trend-wrap canvas

    {
        max-height: 430px;
    }
    .table-wrap { max-height: 320px; overflow: auto; }
    .trend-controls { display:flex; gap:8px; align-items:center; }
    .trend-toggle button { border:1px solid var(--border); background:#deedfb; color:#1690ff; padding:6px 10px; border-radius:8px; cursor:pointer; font-weight:700; }
    .trend-toggle button.active { background:#1690ff; color:#fff; }
    .browser-pie {
        max-width: 320px;
        margin: 0 auto;
        display: flex;
        justify-content: center;
    }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'overview', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="overview-hero">
                <div class="hero-header">
                    <div class="hero-title">
                        <div class="hero-dot">∞</div>
                        <div>
                            <h2 style="margin:0;">站点总览 · <?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="hero-meta">根域名 <?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?> · 所选范围 <?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'overview', (int) $selectedSite['id']); ?>
                </div>
                <div class="metric-grid">
                    <div class="metric-tile"><div class="metric-icon">📈</div><div class="metric-info"><div class="label">PV</div><div class="val"><?= $data['totals']['views'] ?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">👥</div><div class="metric-info"><div class="label">UV</div><div class="val"><?= $data['totals']['uniques'] ?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">🌐</div><div class="metric-info"><div class="label">IP</div><div class="val"><?= $data['totals']['ip_count'] ?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">⏱️</div><div class="metric-info"><div class="label">平均访问时长</div><div class="val"><?= $avgMinutes ?> min</div></div></div>
                    <div class="metric-tile"><div class="metric-icon">📄</div><div class="metric-info"><div class="label">平均访问页数</div><div class="val"><?= $data['totals']['averages']['pages'] ?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">↩️</div><div class="metric-info"><div class="label">跳出率</div><div class="val"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div></div></div>
                    <div class="metric-tile"><div class="metric-icon">🔮</div><div class="metric-info"><div class="label">预计今日 PV</div><div class="val"><?= $data['predictions']['views']?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">🔮</div><div class="metric-info"><div class="label">预计今日 UV</div><div class="val"><?= $data['predictions']['uniques'] ?></div></div></div>
                    <div class="metric-tile"><div class="metric-icon">🔮</div><div class="metric-info"><div class="label">预计今日 IP</div><div class="val"><?= $data['predictions']['ips'] ?></div></div></div>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div class="trend-controls">
                        <h3 style="margin:0;">趋势热力</h3>
                        <span class="pill-tag"><?= $trend['granularity'] === 'hour' ? '小时对比' : '按天走势' ?></span>
                    </div>
                    <div class="trend-toggle">
                        <button class="active" data-metric="ips">IP</button>
                        <button data-metric="uniques">UV</button>
                        <button data-metric="views">PV</button>
                    </div>
                </div>
                <div class="chart-wrap trend-wrap"><canvas id="dailyTrendChart"></canvas></div>
            </section>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title"><h3>访问终端设备（IP）</h3><a class="filter-btn" href="/env.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
                        <div style="flex:1;min-width:240px;">
                            <canvas id="devicePie" height="180"></canvas>
                        </div>
                        <div style="flex:1;min-width:220px;" class="metric-row">
                            <div class="metric"><div class="muted">电脑端 IP</div><div class="value"><?= (int) $data['devices']['desktop']['ips'] ?></div></div>
                            <div class="metric"><div class="muted">移动端 IP</div><div class="value"><?= (int) $data['devices']['mobile']['ips'] ?></div></div>
                        </div>
                    </div>
                    <div class="section-title" style="margin-top:12px;"><h4 style="margin:0;">浏览器分布（IP）</h4></div>
                    <div class="chart-wrap browser-pie"><canvas id="browserBar" height="480" style="display: block; box-sizing: border-box; height: 320px; width: 320px;" width="480"></canvas></div>
                </section>

                <section class="card">
                    <div class="section-title"><h3>新老访客</h3><a class="filter-btn" href="/audience.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div class="metric-row">
                        <div class="metric"><div class="muted">新访客</div><div class="value"><?= (int) $data['new_vs_returning']['new'] ?></div></div>
                        <div class="metric"><div class="muted">回访访客</div><div class="value"><?= (int) $data['new_vs_returning']['returning'] ?></div></div>
                    </div>
                    <div class="section-title" style="margin-top:12px;"><h4 style="margin:0;">入口页（前10名）</h4><a class="filter-btn" href="/entry.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>入口页</th><th>PV</th></tr></thead>
                            <tbody>
                            <?php foreach ($entryPages as $row): ?>
                                <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title"><h3>小时分布</h3><span class="muted">按选择的日期范围聚合</span></div>
                    <div class="table-wrap">
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
                    </div>
                </section>

                <section class="card">
                    <div class="section-title">
                        <h3>地域分布</h3>
                        <a class="filter-btn" href="/region.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>地域</th><th>PV</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($regions as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid-2">
                <section class="card">
                    <div class="section-title">
                        <h3>来路</h3>
                        <div style="display:flex;gap:8px;">
                            <a class="filter-btn" href="/search_engine.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">搜索引擎</a>
                            <a class="filter-btn" href="/external.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">外部链接</a>
                            <a class="filter-btn" href="/referrer.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>来源</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($topReferrers as $row): ?>
                                <tr><td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['ips'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title">
                        <h3>受访页</h3>
                        <a class="filter-btn" href="/pages.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>页面</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($topPages as $row): ?>
                                <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['ips'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <script>
                const trendData = <?= json_encode($trend, JSON_UNESCAPED_UNICODE) ?>;
                const ctxDaily = document.getElementById('dailyTrendChart');
                let trendChart = null;

                const renderTrend = (metric = 'ips') => {
                    if (!ctxDaily || !window.Chart || !trendData) return;
                    const ctx = ctxDaily.getContext('2d');
                    const primaryGrad = ctx.createLinearGradient(0, 0, 0, 160);
                    primaryGrad.addColorStop(0, '#1690ff');
                    primaryGrad.addColorStop(1, 'rgba(22,144,255,0.08)');
                    const compareGrad = ctx.createLinearGradient(0, 0, 0, 160);
                    compareGrad.addColorStop(0, '#73c1ff');
                    compareGrad.addColorStop(1, 'rgba(115,193,255,0.08)');

                    const datasets = [
                        {
                            label: trendData.primary_label,
                            data: trendData.primary?.[metric] || [],
                            borderColor: '#1690ff',
                            backgroundColor: primaryGrad,
                            tension: 0.35,
                            fill: true,
                        }
                    ];

                    if (trendData.compare) {
                        datasets.push({
                            label: trendData.compare_label,
                            data: trendData.compare?.[metric] || [],
                            borderColor: '#73c1ff',
                            backgroundColor: compareGrad,
                            tension: 0.35,
                            fill: true,
                        });
                    }

                    if (trendChart) trendChart.destroy();
                    trendChart = new Chart(ctxDaily, {
                        type: 'line',
                        data: { labels: trendData.labels, datasets },
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
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.trend-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        renderTrend(btn.dataset.metric);
                    });
                });

                renderTrend('ips');

                const pieOptions = {
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.parsed} IP`,
                                afterLabel: (ctx) => {
                                    const total = (ctx.dataset?.data || []).reduce((s, v) => s + Number(v || 0), 0) || 1;
                                    const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                    return `占比 ${pct}%`;
                                }
                            }
                        }
                    }
                };

                const deviceData = [
                    {label:'电脑端', value: <?= (int) $data['devices']['desktop']['ips'] ?>, color:'#1690ff'},
                    {label:'移动端', value: <?= (int) $data['devices']['mobile']['ips'] ?>, color:'#73c1ff'}
                ];
                const ctxDevice = document.getElementById('devicePie');
                if (ctxDevice && window.Chart) {
                    new Chart(ctxDevice, {
                        type:'pie',
                        data:{
                            labels: deviceData.map(d=>d.label),
                            datasets:[{data: deviceData.map(d=>d.value), backgroundColor: deviceData.map(d=>d.color)}]
                        },
                        options: pieOptions
                    });
                }

                const browserRows = <?= json_encode($data['browsers'], JSON_UNESCAPED_UNICODE) ?>;
                const ctxBrowser = document.getElementById('browserBar');
                if (ctxBrowser && window.Chart) {
                    const palette = ['#1690ff','#73c1ff','#4dd0e1','#7c4dff','#ff8a65','#ffd166','#06d6a0','#ef476f','#9c27b0','#26c6da'];
                    new Chart(ctxBrowser, {
                        type:'pie',
                        data:{
                            labels: browserRows.map(r=>r.browser || '未知'),
                            datasets:[{label:'IP', data: browserRows.map(r=>Number(r.ips)), backgroundColor: browserRows.map((_,i)=>palette[i % palette.length])}]
                        },
                        options: pieOptions
                    });
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

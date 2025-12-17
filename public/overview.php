<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getOverview($siteId, $range) : null;

render_head('总览 - 统计后台');
render_topbar($branding);
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
            </section>

            <section class="metric-row">
                <div class="metric"><div class="muted">📈 PV</div><div class="value"><?= $data['totals']['views'] ?></div></div>
                <div class="metric"><div class="muted">👥 UV</div><div class="value"><?= $data['totals']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">🌐 IP</div><div class="value"><?= $data['totals']['ip_count'] ?></div></div>
                <div class="metric"><div class="muted">⏱️ 平均访问时长</div><div class="value"><?= $data['totals']['averages']['duration'] ?>s</div></div>
                <div class="metric"><div class="muted">📄 平均访问页数</div><div class="value"><?= $data['totals']['averages']['pages'] ?></div></div>
                <div class="metric"><div class="muted">↩️ 跳出率</div><div class="value"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div></div>
                <div class="metric"><div class="muted">🔮 预计今日 PV</div><div class="value"><?= $data['predictions']['views'] ?></div></div>
                <div class="metric"><div class="muted">🔮 预计今日 UV</div><div class="value"><?= $data['predictions']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">🔮 预计今日 IP</div><div class="value"><?= $data['predictions']['ips'] ?></div></div>
            </section>

            <section class="card">
                <div class="section-title"><h3>趋势（按所选范围）</h3><span class="muted">PV / UV / IP</span></div>
                <canvas id="dailyTrendChart" height="120"></canvas>
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

            <section class="card">
                <div class="section-title">
                    <h3>地域分布</h3>
                    <a class="filter-btn" href="/region.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                </div>
                <table>
                    <thead><tr><th>地域</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['regions'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['ips'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>访问终端设备</h3>
                    <a class="filter-btn" href="/env.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                </div>
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                    <div style="flex:1;min-width:220px;">
                        <canvas id="devicePie" height="180"></canvas>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <div class="metric-row">
                            <div class="metric"><div class="muted">电脑端 PV</div><div class="value"><?= (int) $data['devices']['desktop']['views'] ?></div></div>
                            <div class="metric"><div class="muted">电脑端 IP</div><div class="value"><?= (int) $data['devices']['desktop']['ips'] ?></div></div>
                            <div class="metric"><div class="muted">移动端 PV</div><div class="value"><?= (int) $data['devices']['mobile']['views'] ?></div></div>
                            <div class="metric"><div class="muted">移动端 IP</div><div class="value"><?= (int) $data['devices']['mobile']['ips'] ?></div></div>
                        </div>
                    </div>
                </div>
                <div class="section-title" style="margin-top:12px;"><h4 style="margin:0;">浏览器 TOP</h4></div>
                <canvas id="browserBar" height="140"></canvas>
                <table>
                    <thead><tr><th>浏览器</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['browsers'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['browser'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['ips'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>新老访客</h3>
                    <a class="filter-btn" href="/audience.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                </div>
                <div class="metric-row">
                    <div class="metric"><div class="muted">新访客</div><div class="value"><?= (int) $data['new_vs_returning']['new'] ?></div></div>
                    <div class="metric"><div class="muted">回访访客</div><div class="value"><?= (int) $data['new_vs_returning']['returning'] ?></div></div>
                </div>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>来路</h3>
                    <div style="display:flex;gap:8px;">
                        <a class="filter-btn" href="/search_engine.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">搜索引擎</a>
                        <a class="filter-btn" href="/external.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">外部链接</a>
                    </div>
                </div>
                <table>
                    <thead><tr><th>来源</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['top_referrers'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>受访页</h3>
                    <a class="filter-btn" href="/pages.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                </div>
                <table>
                    <thead><tr><th>页面</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['top_pages'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>入口页（前10名）</h3>
                    <a class="filter-btn" href="/entry.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">详情</a>
                </div>
                <table>
                    <thead><tr><th>入口页</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['entry_pages'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <script>
                const dailyData = <?= json_encode($data['daily'], JSON_UNESCAPED_UNICODE) ?>;
                const ctxDaily = document.getElementById('dailyTrendChart');
                if (ctxDaily && window.Chart) {
                    const gPV = ctxDaily.getContext('2d').createLinearGradient(0, 0, 0, 200);
                    gPV.addColorStop(0, '#1690ff');
                    gPV.addColorStop(1, '#73c1ff');
                    const gUV = ctxDaily.getContext('2d').createLinearGradient(0, 0, 0, 200);
                    gUV.addColorStop(0, '#4dadff');
                    gUV.addColorStop(1, '#b6e0ff');
                    const gIP = ctxDaily.getContext('2d').createLinearGradient(0, 0, 0, 200);
                    gIP.addColorStop(0, '#73c1ff');
                    gIP.addColorStop(1, '#d1ecff');
                    new Chart(ctxDaily, {
                        type: 'bar',
                        data: {
                            labels: dailyData.map(d => d.day),
                            datasets: [
                                {label:'PV', data: dailyData.map(d => Number(d.views)), backgroundColor:gPV},
                                {label:'UV', data: dailyData.map(d => Number(d.uniques)), backgroundColor:gUV},
                                {label:'IP', data: dailyData.map(d => Number(d.ip_count)), backgroundColor:gIP}
                            ]
                        },
                        options: {responsive:true, plugins:{legend:{position:'top'}, tooltip:{mode:'index', intersect:false}}, scales:{x:{stacked:false}, y:{beginAtZero:true}}}
                    });
                }

                const deviceData = [
                    {label:'电脑端', value: <?= (int) $data['devices']['desktop']['views'] ?>, color:'#1690ff'},
                    {label:'移动端', value: <?= (int) $data['devices']['mobile']['views'] ?>, color:'#73c1ff'}
                ];
                const ctxDevice = document.getElementById('devicePie');
                if (ctxDevice && window.Chart) {
                    new Chart(ctxDevice, {
                        type:'pie',
                        data:{
                            labels: deviceData.map(d=>d.label),
                            datasets:[{data: deviceData.map(d=>d.value), backgroundColor: deviceData.map(d=>d.color)}]
                        },
                        options:{plugins:{legend:{position:'bottom'}}}
                    });
                }

                const browserRows = <?= json_encode($data['browsers'], JSON_UNESCAPED_UNICODE) ?>;
                const ctxBrowser = document.getElementById('browserBar');
                if (ctxBrowser && window.Chart) {
                    const gBrowser = ctxBrowser.getContext('2d').createLinearGradient(0, 0, 0, 160);
                    gBrowser.addColorStop(0, '#1690ff');
                    gBrowser.addColorStop(1, '#b6e0ff');
                    new Chart(ctxBrowser, {
                        type:'bar',
                        data:{
                            labels: browserRows.map(r=>r.browser || '未知'),
                            datasets:[{label:'PV', data: browserRows.map(r=>Number(r.views)), backgroundColor:gBrowser}]
                        },
                        options:{plugins:{legend:{display:false}, datalabels:{display:false}}, scales:{y:{beginAtZero:true}}}
                    });
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

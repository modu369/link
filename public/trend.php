<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$data = $selectedSite ? $tracker->getTrendData($siteId, $range) : null;
$totals = $selectedSite ? $tracker->getTotals($siteId, $range) : null;
$trendLines = $selectedSite ? $tracker->getTrendLines($siteId, $range) : null;

render_head('趋势分析 - 统计后台');
render_topbar($branding);
?>
<style>
    .trend-metrics { display:flex; flex-wrap:wrap; gap:12px; align-items:stretch; }
    .trend-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:12px; box-shadow:0 10px 28px rgba(22,144,255,0.1); display:flex; gap:10px; align-items:center; flex:0 1 auto; min-width:180px; }
    .trend-icon { width:44px; height:44px; border-radius:12px; background:#deedfb; display:grid; place-items:center; color:#1690ff; font-weight:800; flex-shrink:0; }
    .trend-info { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .trend-info .label { color:var(--muted); font-size:12px; font-weight:400; word-break:break-word; }
    .trend-info .val { font-size:12px; font-weight:400; word-break:break-word; }
    .trend-canvas { width:100%; height:350px; }
</style>
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

            <?php if ($totals): ?>
                <section class="card">
                    <div class="section-title" style="justify-content:space-between; align-items:center;">
                        <h3 style="margin:0;">数据概况</h3>
                        <div style="display:flex;gap:6px;">
                            <span class="pill-tag">范围：<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="pill-tag">站点：<?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="trend-metrics">
                        <div class="trend-card"><div class="trend-icon">PV</div><div class="trend-info"><div class="label">访问量</div><div class="val"><?= (int) $totals['views'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">UV</div><div class="trend-info"><div class="label">访客数</div><div class="val"><?= (int) $totals['uniques'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">IP</div><div class="trend-info"><div class="label">IP</div><div class="val"><?= (int) $totals['ip_count'] ?></div></div></div>
                        <div class="trend-card"><div class="trend-icon">⏱</div><div class="trend-info"><div class="label">平均访问时长</div><div class="val"><?= round(($totals['averages']['duration'] ?? 0) / 60, 1) ?> min</div></div></div>
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
                    <div class="chart-wrap trend-wrap"><div id="trendLineChart" class="trend-canvas"></div></div>
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

<script src="/t_statics/js/echarts.min.js"></script>
<script>
    const trendSource = <?= json_encode($trendLines, JSON_UNESCAPED_UNICODE) ?>;
    const chartEl = document.getElementById('trendLineChart');
    let trendLineChart = null;

    const renderTrendLine = (metric = 'ips') => {
        if (!chartEl || !window.echarts || !trendSource) return;

        if (trendLineChart) trendLineChart.dispose();
        trendLineChart = echarts.init(chartEl);

        const series = [];
        const legends = [];

        // 1. 蓝色线 (通常为今日/本期数据)
        if (trendSource.primary) {
            legends.push(trendSource.primary_label);
            series.push({
                name: trendSource.primary_label,
                data: trendSource.primary[metric] || [],
                type: 'line',
                smooth: true,
                symbol: 'circle',
                symbolSize: 8,
                showSymbol: false,
                itemStyle: { color: '#1890ff', borderColor: '#fff', borderWidth: 2 },
                lineStyle: { width: 2, type: 'solid' },
                areaStyle: {
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(24, 144, 255, 0.25)' },
                        { offset: 1, color: 'rgba(24, 144, 255, 0)' }
                    ])
                }
            });
        }

        // 2. 黄色线 (通常为昨日/对比数据)
        if (trendSource.compare) {
            legends.push(trendSource.compare_label);
            series.push({
                name: trendSource.compare_label,
                data: trendSource.compare[metric] || [],
                type: 'line',
                smooth: true,
                symbol: 'circle',
                symbolSize: 8,
                showSymbol: false,
                itemStyle: { color: '#faad14', borderColor: '#fff', borderWidth: 2 },
                lineStyle: { width: 2, type: 'solid' },
                areaStyle: {
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(250, 173, 20, 0.25)' },
                        { offset: 1, color: 'rgba(250, 173, 20, 0)' }
                    ])
                }
            });
        }

        trendLineChart.setOption({
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(23, 35, 61, 0.9)',
                borderColor: 'transparent',
                padding: [12, 16],
                textStyle: { color: '#fff' },
                axisPointer: { type: 'line', lineStyle: { color: '#d9d9d9', type: 'solid' } },
                formatter: function (params) {
                    if (!params || !params.length) return '';

                    let rawVal = String(params[0].axisValueLabel || params[0].axisValue).trim();
                    let displayTitle = rawVal;
                    
                    // 时间段强制格式化 00:00 - 00:59
                    let isHour = (trendSource.granularity === 'hour') || /^\d{1,2}(:\d{2})?$/.test(rawVal);
                    if (isHour) {
                        let hNum = parseInt(rawVal, 10);
                        if (!isNaN(hNum)) {
                            let hStr = hNum.toString().padStart(2, '0');
                            displayTitle = '时间：' + hStr + ':00 - ' + hStr + ':59';
                        }
                    } else {
                        displayTitle = '日期：' + rawVal;
                    }

                    let pData = params.find(p => p.seriesName === trendSource.primary_label);
                    let cData = params.find(p => p.seriesName === trendSource.compare_label);
                    
                    let pVal = pData ? Number(pData.value || 0) : 0;
                    let cVal = cData ? Number(cData.value || 0) : 0;

                    let diffHtml = '';
                    if (pData && cData) {
                        let diff = pVal - cVal;
                        if (cVal === 0) {
                            diffHtml = pVal > 0 ? '<span style="color: #ed4014; font-weight: bold;">↑ 100.00%</span>' : '<span style="color: #808695; font-weight: bold;">0.00%</span>';
                        } else {
                            let pct = (diff / cVal) * 100;
                            if (diff > 0) {
                                diffHtml = '<span style="color: #ed4014; font-weight: bold;">↑ ' + pct.toFixed(2) + '%</span>';
                            } else if (diff < 0) {
                                diffHtml = '<span style="color: #19be6b; font-weight: bold;">↓ ' + Math.abs(pct).toFixed(2) + '%</span>';
                            } else {
                                diffHtml = '<span style="color: #808695; font-weight: bold;">0.00%</span>';
                            }
                        }
                    }

                    let html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; color: #808695;">' + 
                               '<span>' + displayTitle + '</span>' + 
                               '<span style="margin-left: 24px;">' + diffHtml + '</span>' + 
                               '</div>';
                    
                    params.forEach(p => {
                        let safeVal = (p.value !== undefined && p.value !== null && !isNaN(p.value)) ? Number(p.value).toLocaleString() : '0';
                        html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">' +
                                // 此处追加了中文冒号 “：”
                                '<div style="display: flex; align-items: center; color: #c5c8ce; font-size: 13px;">' + p.marker + p.seriesName + '：</div>' +
                                '<div style="color: #fff; font-weight: 600; font-size: 14px; margin-left: 36px;">' + safeVal + '</div>' +
                                '</div>';
                    });
                    return html;
                }
            },
            legend: {
                data: legends,
                top: 0,
                right: 0,
                icon: 'rect',
                itemWidth: 16,
                itemHeight: 4,
                textStyle: { color: '#8c8c8c', fontSize: 12 }
            },
            grid: { left: '0%', right: '1%', bottom: '0%', top: '15%', containLabel: true },
            xAxis: {
                type: 'category',
                data: trendSource.labels,
                boundaryGap: false,
                axisLine: { show: false },
                axisTick: { show: false },
                axisLabel: { color: '#8c8c8c', margin: 12 }
            },
            yAxis: {
                type: 'value',
                axisLine: { show: false },
                axisTick: { show: false },
                splitLine: { lineStyle: { color: '#f0f0f0', type: 'solid' } },
                axisLabel: { color: '#8c8c8c' }
            },
            series: series
        });

        window.addEventListener('resize', () => trendLineChart.resize());
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

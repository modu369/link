<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}

// 提取趋势数据
$trendSource = $selectedSite ? $tracker->getTrendLines($siteId, $range) : null;

render_head('趋势走势 - 统计后台');
render_topbar($branding);
?>
<style>
    .trend-controls { display:flex; gap:8px; align-items:center; }
    .trend-toggle button { border:1px solid var(--border); background:#deedfb; color:#1690ff; padding:6px 10px; border-radius:8px; cursor:pointer; font-weight:700; }
    .trend-toggle button.active { background:#1690ff; color:#fff; }
    .chart-wrap { position: relative; width: 100%; }
    .trend-wrap canvas { max-height: 500px; height: 450px; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'trend', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$trendSource): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h2 style="margin:0;">趋势分析 · <?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <?php render_range_filters($allowedRanges, $range, 'trend', (int) $selectedSite['id']); ?>
            </div>

            <section class="card">
                <div class="section-title" style="justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div class="trend-controls">
                        <h3 style="margin:0;">趋势折线</h3>
                        <div class="trend-toggle main-type-toggle" style="display:flex; gap:8px;">
                            <button class="active" data-type="total">总趋势</button>
                            <button data-type="mobile">移动趋势</button>
                        </div>
                    </div>
                    <div class="trend-toggle metric-toggle">
                        <button class="active" data-metric="ips">IP</button>
                        <button data-metric="uniques">UV</button>
                        <button data-metric="views">PV</button>
                    </div>
                </div>
                <div class="chart-wrap trend-wrap"><div id="mainTrendChart" style="width: 100%; height: 450px;"></div></div>
            </section>
            
            <section class="card" style="margin-top: 20px;">
                <div class="section-title"><h3>数据明细</h3></div>
                <div class="table-wrap" style="max-height: 600px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>时间范围</th>
                                <th>PV</th>
                                <th>UV</th>
                                <th>IP</th>
                                <th>移动端 IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $labels = $trendSource['labels'] ?? [];
                                $primary = $trendSource['primary'] ?? [];
                                // 正序显示：从最早的时间点开始（如 00:00）
                                $count = count($labels);
                                for ($i = 0; $i < $count; $i++):
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($labels[$i], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)($primary['views'][$i] ?? 0) ?></td>
                                <td><?= (int)($primary['uniques'][$i] ?? 0) ?></td>
                                <td><?= (int)($primary['ips'][$i] ?? 0) ?></td>
                                <td><?= (int)($primary['mobile_ips'][$i] ?? 0) ?></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <script src="/t_statics/js/echarts.min.js"></script>
            <script>
                const trendSource = <?= json_encode($trendSource, JSON_UNESCAPED_UNICODE) ?>;
                const chartEl = document.getElementById('mainTrendChart');
                let trendLineChart = null;
                let currentTrendType = 'total';
                let currentTrendMetric = 'ips';

                const renderTrendLine = () => {
                    if (!chartEl || !window.echarts || !trendSource) return;

                    let actualMetric = currentTrendType === 'mobile' ? 'mobile_ips' : currentTrendMetric;

                    if (trendLineChart) trendLineChart.dispose();
                    trendLineChart = echarts.init(chartEl);

                    const series = [];
                    const legends = [];
                    // 获取 X 轴标签长度，用于生成默认零数组
                    const labelsLength = trendSource.labels ? trendSource.labels.length : 0;

                    if (trendSource.primary) {
                        legends.push(trendSource.primary_label);
                        series.push({
                            name: trendSource.primary_label,
                            // 核心：若取不到数据，则注入等长的 [0, 0, ...] 数组，防止 ECharts 错位
                            data: trendSource.primary[actualMetric] || new Array(labelsLength).fill(0),
                            type: 'line',
                            smooth: false,
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

                    if (trendSource.compare) {
                        legends.push(trendSource.compare_label);
                        series.push({
                            name: trendSource.compare_label,
                            // 同理：防错位数组
                            data: trendSource.compare[actualMetric] || new Array(labelsLength).fill(0),
                            type: 'line',
                            smooth: false,
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
                                            '<div style="display: flex; align-items: center; color: #c5c8ce; font-size: 13px;">' + p.marker + p.seriesName + '：</div>' +
                                            '<div style="color: #fff; font-weight: 600; font-size: 14px; margin-left: 36px;">' + safeVal + '</div>' +
                                            '</div>';
                                });
                                return html;
                            }
                        },
                        legend: { data: legends, top: 0, right: 0, icon: 'rect', itemWidth: 16, itemHeight: 4, textStyle: { color: '#8c8c8c', fontSize: 12 } },
                        grid: { left: '0%', right: '1%', bottom: '0%', top: '15%', containLabel: true },
                        xAxis: { type: 'category', data: trendSource.labels, boundaryGap: false, axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: '#8c8c8c', margin: 12 } },
                        yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false }, splitLine: { lineStyle: { color: '#f0f0f0', type: 'solid' } }, axisLabel: { color: '#8c8c8c' } },
                        series: series
                    });

                    window.addEventListener('resize', () => trendLineChart.resize());
                };

                // 主趋势切换：总趋势 / 移动趋势
                document.querySelectorAll('.main-type-toggle button').forEach(btn => {
                    btn?.addEventListener('click', () => {
                        document.querySelectorAll('.main-type-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        currentTrendType = btn.dataset.type;
                        
                        // 当选择“移动趋势”时，隐藏右侧的 IP/UV/PV 选项
                        const metricToggle = document.querySelector('.metric-toggle');
                        if (metricToggle) {
                            metricToggle.style.display = currentTrendType === 'mobile' ? 'none' : 'flex';
                        }
                        
                        renderTrendLine();
                    });
                });

                // 指标切换：PV / UV / IP
                document.querySelectorAll('.metric-toggle button').forEach(btn => {
                    btn?.addEventListener('click', () => {
                        document.querySelectorAll('.metric-toggle button').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        currentTrendMetric = btn.dataset.metric;
                        renderTrendLine();
                    });
                });

                renderTrendLine();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$data = $selectedSite ? $tracker->getEntryData($siteId, $range) : null;
$entries = $data['entries'] ?? [];
$summary = $data['entry_summary'] ?? [];
$totalEntries = count($entries);
$totalPages = max(1, (int) ceil($totalEntries / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$entryRowsForTable = array_slice($entries, ($page - 1) * $perPage, $perPage);

function entry_duration_format($seconds): string {
    $seconds = (int) round($seconds);
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

render_head('入口页详情 - 统计后台');
render_topbar($branding);
?>
<style>
    /* 现代化标签与分段控制器 UI */
    .pill-tag { background: #e0f2fe; color: #0284c7; padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 12px; }
    
    .trend-toggle { 
        display: inline-flex; 
        background: #f1f5f9; 
        border-radius: 6px; 
        padding: 3px; 
    }
    .trend-toggle button { 
        background: transparent; 
        border: none; 
        padding: 6px 14px; 
        border-radius: 4px; 
        color: #64748b; 
        font-weight: 600; 
        cursor: pointer; 
        transition: all 0.2s ease; 
        font-size: 13px; 
    }
    .trend-toggle button:hover { color: #0f172a; }
    .trend-toggle button.active { 
        background: #fff; 
        color: #0ea5e9; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
    }
    
    /* 防止超长 URL 撑破表格导致布局错乱 */
    .url-ellipsis {
        display: inline-block;
        max-width: 350px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
        color: #1e293b;
        font-weight: 500;
    }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'entry', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">入口页</h2>
                        <p class="muted" style="margin:2px 0 0;">基于会话首跳，按 IP 为主的入口质量概览</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'entry', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <?php if (empty($entries)): ?>
                <div class="card empty">该时间段内暂无入口数据。</div>
            <?php else: ?>
                <section class="card">
                    <div class="metric-row">
                        <div class="metric"><div class="muted">IP 数</div><div class="value"><?= (int) ($summary['ips'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">浏览量 (PV)</div><div class="value"><?= (int) ($summary['views'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">访客数 (UV)</div><div class="value"><?= (int) ($summary['uv'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">新访客</div><div class="value"><?= (int) ($summary['new'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">平均浏览页数</div><div class="value"><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></div></div>
                        <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= entry_duration_format($summary['avg_duration'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</div></div>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title" style="justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                        <div class="trend-controls" style="display: flex; align-items: center; gap: 12px;">
                            <h3 style="margin:0;">入口质量柱状图</h3>
                            <span class="pill-tag">TOP 20 入口</span>
                        </div>
                        <div class="trend-toggle">
                            <button class="active" data-metric="ips">IP</button>
                            <button data-metric="views">PV</button>
                            <button data-metric="uv">UV</button>
                            <button data-metric="bounce_rate">跳出率</button>
                        </div>
                    </div>
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="entryBar"></canvas>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title" style="margin-bottom:0;">
                        <h3 style="margin:0;">入口列表</h3>
                        <span class="muted">每页 <?= $perPage ?> 条</span>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th style="width: 35%">页面路径</th>
                                <th>IP数</th>
                                <th>访客数</th>
                                <th>贡献浏览量</th>
                                <th>平均浏览页数</th>
                                <th>平均访问时长</th>
                                <th>跳出率</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr style="font-weight:700; background-color: #fafafa;">
                                <td>合计</td>
                                <td><?= (int) ($summary['ips'] ?? 0) ?></td>
                                <td><?= (int) ($summary['uv'] ?? 0) ?></td>
                                <td><?= (int) ($summary['views'] ?? 0) ?></td>
                                <td><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></td>
                                <td><?= entry_duration_format($summary['avg_duration'] ?? 0) ?></td>
                                <td><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                            </tr>
                            <?php foreach ($entryRowsForTable as $row): ?>
                                <?php $entryPath = htmlspecialchars($row['path'] ?: '/', ENT_QUOTES, 'UTF-8'); ?>
                                <tr>
                                    <td>
                                        <span class="url-ellipsis" title="<?= $entryPath ?>">
                                            <?= $entryPath ?>
                                        </span>
                                    </td>
                                    <td><?= (int) $row['ips'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= number_format((float) $row['avg_pages'], 2) ?></td>
                                    <td><?= entry_duration_format($row['avg_duration']) ?></td>
                                    <td>
                                        <?php $bounce = round(($row['bounce_rate'] ?? 0) * 100, 2); ?>
                                        <span style="color: <?= $bounce > 80 ? '#ef4444' : 'inherit' ?>;">
                                            <?= $bounce ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php render_pagination($page, $totalPages, '/entry.php', ['site' => (int) $siteId, 'range' => $range]); ?>
                </section>

                <script>
                    const entryRows = <?= json_encode(array_slice($entries, 0, 20), JSON_UNESCAPED_UNICODE) ?>;
                    const entryLabels = entryRows.map(r => r.path || '/');
                    const entryDatasets = {
                        ips: entryRows.map(r => Number(r.ips || 0)),
                        views: entryRows.map(r => Number(r.views || 0)),
                        uv: entryRows.map(r => Number(r.uniques || 0)),
                        bounce_rate: entryRows.map(r => Number((r.bounce_rate || 0) * 100))
                    };
                    
                    let entryChart = null;
                    const entryCtx = document.getElementById('entryBar');
                    
                    const renderEntryChart = (metric='ips') => {
                        if (!entryCtx || !window.Chart) return;
                        if (entryChart) entryChart.destroy();
                        
                        entryChart = new Chart(entryCtx, {
                            type: 'bar',
                            data: {
                                labels: entryLabels,
                                datasets: [{
                                    label: metric === 'bounce_rate' ? '跳出率(%)' : metric.toUpperCase(),
                                    data: entryDatasets[metric] || [],
                                    backgroundColor: '#1690ff', // 保持原有颜色
                                    borderRadius: 4,
                                    barPercentage: 0.6
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => {
                                                const val = ctx.parsed.y;
                                                if (metric === 'bounce_rate') {
                                                    return `跳出率: ${val.toFixed(2)}%`;
                                                }
                                                return `${ctx.dataset.label}: ${val}`;
                                            }
                                        }
                                    }
                                },
                                scales: { 
                                    x: { 
                                        grid: { display: false },
                                        ticks: { maxRotation: 30, minRotation: 0 }
                                    }, 
                                    y: { 
                                        beginAtZero: true,
                                        grid: { color: '#f1f5f9' }
                                    } 
                                }
                            }
                        });
                    };

                    document.querySelectorAll('.trend-toggle button').forEach(btn => {
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.trend-toggle button').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            renderEntryChart(btn.dataset.metric);
                        });
                    });

                    renderEntryChart('ips');
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

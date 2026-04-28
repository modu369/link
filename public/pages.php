<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}
// ==================================

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$data = $selectedSite ? $tracker->getPageData($siteId, $range) : null;
$pages = $data['pages'] ?? [];
$summary = $data['page_summary'] ?? [];
$totalPagesCount = count($pages);
$totalPages = max(1, (int) ceil($totalPagesCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$pageRowsForTable = array_slice($pages, ($page - 1) * $perPage, $perPage);

function page_duration_format($seconds): string {
    $seconds = (int) round($seconds);
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

render_head('受访页详情 - 统计后台');
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
    <?php render_sidebar($sites, $siteId, $selectedSite, 'pages', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">受访页</h2>
                        <p class="muted" style="margin:2px 0 0;">基于 IP 的受访质量，默认展示前 100</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'pages', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <?php if (empty($pages)): ?>
                <div class="card empty">该时间段内暂无受访页数据。</div>
            <?php else: ?>
                <section class="card">
                    <div class="metric-row">
                        <div class="metric"><div class="muted">IP 数</div><div class="value"><?= (int) ($summary['ips'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">浏览量 (PV)</div><div class="value"><?= (int) ($summary['views'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">访客数 (UV)</div><div class="value"><?= (int) ($summary['uv'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">新访客</div><div class="value"><?= (int) ($summary['new'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">平均浏览页数</div><div class="value"><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></div></div>
                        <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= page_duration_format($summary['avg_duration'] ?? 0) ?></div></div>
                        <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</div></div>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title" style="justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                        <div class="trend-controls" style="display: flex; align-items: center; gap: 12px;">
                            <h3 style="margin:0;">受访页柱状图</h3>
                            <span class="pill-tag">TOP 20 页面</span>
                        </div>
                        <div class="trend-toggle">
                            <button class="active" data-metric="ips">IP</button>
                            <button data-metric="views">PV</button>
                            <button data-metric="uv">UV</button>
                            <button data-metric="bounce_rate">跳出率</button>
                        </div>
                    </div>
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="pageBar"></canvas>
                    </div>
                </section>

                <section class="card">
                    <div class="section-title" style="margin-bottom:0;">
                        <h3 style="margin:0;">受访页列表</h3>
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
                                <td><?= page_duration_format($summary['avg_duration'] ?? 0) ?></td>
                                <td><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                            </tr>
                            <?php foreach ($pageRowsForTable as $row): ?>
                                <?php $pagePath = htmlspecialchars($row['path'] ?: '/', ENT_QUOTES, 'UTF-8'); ?>
                                <tr>
                                    <td>
                                        <span class="url-ellipsis" title="<?= $pagePath ?>">
                                            <?= $pagePath ?>
                                        </span>
                                    </td>
                                    <td><?= (int) $row['ips'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= number_format((float) $row['avg_pages'], 2) ?></td>
                                    <td><?= page_duration_format($row['avg_duration']) ?></td>
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
                    <?php render_pagination($page, $totalPages, '/pages.php', ['site' => (int) $siteId, 'range' => $range]); ?>
                </section>

                <script>
                    const pageRows = <?= json_encode(array_slice($pages, 0, 20), JSON_UNESCAPED_UNICODE) ?>;
                    const pageLabels = pageRows.map(r => r.path || '/');
                    const pageDatasets = {
                        ips: pageRows.map(r => Number(r.ips || 0)),
                        views: pageRows.map(r => Number(r.views || 0)),
                        uv: pageRows.map(r => Number(r.uniques || 0)),
                        bounce_rate: pageRows.map(r => Number((r.bounce_rate || 0) * 100))
                    };
                    
                    let pageChart = null;
                    const pageCtx = document.getElementById('pageBar');
                    
                    const renderPageChart = (metric='ips') => {
                        if (!pageCtx || !window.Chart) return;
                        if (pageChart) pageChart.destroy();
                        
                        pageChart = new Chart(pageCtx, {
                            type: 'bar',
                            data: {
                                labels: pageLabels,
                                datasets: [{
                                    label: metric === 'bounce_rate' ? '跳出率(%)' : metric.toUpperCase(),
                                    data: pageDatasets[metric] || [],
                                    backgroundColor: '#1690ff', // 恢复为原来的品牌蓝
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
                                                if (metric === 'bounce_rate') return `跳出率: ${val.toFixed(2)}%`;
                                                return `${ctx.dataset.label}: ${val}`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: { grid: { display: false } },
                                    y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
                                }
                            }
                        });
                    };

                    document.querySelectorAll('.trend-toggle button').forEach(btn => {
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.trend-toggle button').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            renderPageChart(btn.dataset.metric);
                        });
                    });

                    renderPageChart('ips');
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

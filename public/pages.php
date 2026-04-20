<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
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
    .pill-tag { background:#deedfb; color:#1690ff; padding:4px 10px; border-radius:999px; font-weight:700; border:1px solid var(--border); }
    .trend-toggle button { border:1px solid var(--border); background:#deedfb; color:#1690ff; padding:6px 10px; border-radius:8px; cursor:pointer; font-weight:700; }
    .trend-toggle button.active { background:#1690ff; color:#fff; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'pages', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
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
                <div class="section-title" style="justify-content: space-between;">
                    <div class="trend-controls">
                        <h3 style="margin:0;">受访页柱状图</h3>
                        <span class="pill-tag">默认 IP，可切换 PV / UV / 新访客 / 跳出率</span>
                    </div>
                    <div class="trend-toggle">
                        <button class="active" data-metric="ips">IP</button>
                        <button data-metric="views">PV</button>
                        <button data-metric="uv">UV</button>
                        <button data-metric="new">新访客</button>
                        <button data-metric="bounce_rate">跳出率</button>
                    </div>
                </div>
                <canvas id="pageBar" height="260" style="max-height:500px;"></canvas>
            </section>

            <section class="card">
                <div class="section-title" style="margin-bottom:0;"><h3 style="margin:0;">受访页列表</h3><span class="muted">每页 <?= $perPage ?> 条</span></div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>页面 URL</th>
                            <th>IP数</th>
                            <th>访客数</th>
                            <th>新访客数</th>
                            <th>贡献浏览量</th>
                            <th>平均浏览页数</th>
                            <th>平均访问时长</th>
                            <th>跳出率</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($pages)): ?>
                            <tr><td colspan="8" class="muted">暂无页面数据</td></tr>
                        <?php else: ?>
                            <tr style="font-weight:700;">
                                <td>合计</td>
                                <td><?= (int) ($summary['ips'] ?? 0) ?></td>
                                <td><?= (int) ($summary['uv'] ?? 0) ?></td>
                                <td><?= (int) ($summary['new'] ?? 0) ?></td>
                                <td><?= (int) ($summary['views'] ?? 0) ?></td>
                                <td><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></td>
                                <td><?= page_duration_format($summary['avg_duration'] ?? 0) ?></td>
                                <td><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                            </tr>
                            <?php foreach ($pageRowsForTable as $row): ?>
                                <?php $pagePath = htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8'); ?>
                                <tr>
                                    <td><span class="url-ellipsis" title="<?= $pagePath ?>"><?= $pagePath ?></span></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                    <td><?= number_format((float) $row['avg_pages'], 2) ?></td>
                                    <td><?= page_duration_format($row['avg_duration']) ?></td>
                                    <td><?= round(($row['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                    new: pageRows.map(r => Number(r.uniques || 0)),
                    bounce_rate: pageRows.map(r => Number((r.bounce_rate || 0) * 100))
                };
                let pageChart = null;
                const pageCtx = document.getElementById('pageBar');
                const renderPageChart = (metric='ips') => {
                    if (!pageCtx || !window.Chart) return;
                    if (pageChart) pageChart.destroy();
                    pageChart = new Chart(pageCtx, {
                        type:'bar',
                        data:{
                            labels: pageLabels,
                            datasets:[{
                                label: metric === 'bounce_rate' ? '跳出率(%)' : metric.toUpperCase(),
                                data: pageDatasets[metric] || [],
                                backgroundColor: '#1690ff'
                            }]
                        },
                        options:{
                            responsive:true,
                            plugins:{
                                legend:{display:false},
                                tooltip:{
                                    callbacks:{
                                        label:(ctx)=>{
                                            const val = ctx.parsed.y;
                                            if (metric === 'bounce_rate') return `跳出率: ${val.toFixed(2)}%`;
                                            return `${ctx.label}: ${val}`;
                                        }
                                    }
                                }
                            },
                            scales:{y:{beginAtZero:true}}
                        }
                    });
                };
                document.querySelectorAll('.trend-toggle button').forEach(btn=>{
                    btn.addEventListener('click', ()=>{
                        document.querySelectorAll('.trend-toggle button').forEach(b=>b.classList.remove('active'));
                        btn.classList.add('active');
                        renderPageChart(btn.dataset.metric);
                    });
                });
                renderPageChart('ips');
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getSearchEngineData($siteId, $range) : null;

render_head('搜索引擎 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'search_engine', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路分析 · 搜索引擎</h2>
                        <p class="muted" style="margin:2px 0 0;">按搜索引擎聚合 PV / IP</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'search_engine', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>搜索引擎列表</h3><span class="muted">按 PV 排序</span></div>
                <table>
                    <thead><tr><th>搜索引擎</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['engines'])): ?>
                        <tr><td colspan="3" class="muted">暂无搜索引擎来路</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['engines'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['engine'], ENT_QUOTES, 'UTF-8') ?></td>
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

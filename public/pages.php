<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getPageData($siteId, $range) : null;

render_head('受访页详情 - 统计后台');
render_topbar($config);
?>
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
                        <p class="muted" style="margin:2px 0 0;">按 PV 排序，前 100</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'pages', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <table>
                    <thead><tr><th>页面</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['pages'])): ?>
                        <tr><td colspan="2" class="muted">暂无页面数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['pages'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
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

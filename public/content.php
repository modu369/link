<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getContentData($siteId, $range) : null;

render_head('内容 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'content', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">内容分析</h2>
                        <p class="muted" style="margin:2px 0 0;">热门页面与来源拆分到独立页面</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'content', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>热门页面（TOP 50）</h3><span class="muted">按 PV</span></div>
                <table>
                    <thead><tr><th>页面路径</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['top_pages'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>来源网站（TOP 50）</h3><span class="muted">Referrer</span></div>
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
                <div class="section-title"><h3>实时访问</h3><span class="muted">最新 20 条</span></div>
                <table>
                    <thead><tr><th>页面</th><th>来源</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['recent'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

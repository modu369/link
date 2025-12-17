<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getEntryData($siteId, $range) : null;

render_head('入口页详情 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'entry', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">入口页</h2>
                        <p class="muted" style="margin:2px 0 0;">基于会话首跳，前 100 名</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'entry', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <table>
                    <thead><tr><th>入口页面</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['entries'])): ?>
                        <tr><td colspan="3" class="muted">暂无入口数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['entries'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
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

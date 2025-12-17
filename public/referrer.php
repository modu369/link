<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getReferrerData($siteId, $range) : null;

render_head('来路详情 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'referrer', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路详情</h2>
                        <p class="muted" style="margin:2px 0 0;">前 50 名外部来源</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'referrer', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <table>
                    <thead><tr><th>来源</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['referrers'])): ?>
                        <tr><td colspan="3" class="muted">暂无来路数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['referrers'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
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

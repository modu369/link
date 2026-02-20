<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$data = $selectedSite ? $tracker->getExternalLinkData($siteId, $range) : null;
$totalLinks = $data ? count($data['links'] ?? []) : 0;
$totalPages = max(1, (int) ceil($totalLinks / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$links = $data ? array_slice($data['links'], ($page - 1) * $perPage, $perPage) : [];

render_head('外部链接 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'external', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路分析 · 外部链接</h2>
                        <p class="muted" style="margin:2px 0 0;">剔除搜索引擎与自身域名的外部来源</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'external', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>外部链接列表</h3><span class="muted">按 PV 排序 · 每页 <?= $perPage ?> 条</span></div>
                <table>
                    <thead><tr><th>来源域名</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($links)): ?>
                        <tr><td colspan="3" class="muted">暂无外部链接</td></tr>
                    <?php else: ?>
                        <?php foreach ($links as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['host'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php render_pagination($page, $totalPages, '/external.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

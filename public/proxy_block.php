<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = $tracker->getBlockedProxyIpCount();
$rows = $tracker->getBlockedProxyIps($page, $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $rows = $tracker->getBlockedProxyIps($page, $perPage);
}

render_head('刷量风险IP - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'proxy_block', $range); ?>
    <main class="content">
        <section class="card">
            <div class="section-title">
                <div>
                    <h2 style="margin:0;">刷量风险IP</h2>
                    <p class="muted" style="margin:4px 0 0;">展示当前本地风险识别已拦截的IP（自动过期）。</p>
                </div>
            </div>
            <?php if (empty($rows)): ?>
                <div class="empty">暂无风险IP记录</div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>IP</th>
                        <th>拦截时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($row['blocked_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                $query = ['site' => $siteId, 'range' => $range];
                render_pagination($page, $totalPages, '/proxy_block.php', $query);
                ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php render_footer(); ?>

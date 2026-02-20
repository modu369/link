<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = $tracker->getBlockedProxyIpCount();
$totalPages = (int) ceil($total / $perPage);
$rows = $tracker->getBlockedProxyIps($perPage, ($page - 1) * $perPage);

render_head('刷量风险IP - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'proxy_block', $range); ?>
    <main class="content">
        <section class="card">
            <div class="section-title">
                <div>
                    <h2 style="margin:0;">刷量风险IP记录</h2>
                    <p class="muted" style="margin:4px 0 0;">系统会记录触发风险判定的 IP，优先展示 Redis 封禁记录。</p>
                </div>
                <span class="pill">总计 <?= (int) $total ?> 条</span>
            </div>
            <?php if (empty($rows)): ?>
                <div class="empty">暂无封禁记录。</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>IP</th>
                            <th>检测时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['detected_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_pagination($page, $totalPages, '/proxy_block.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = $tracker->getBlockedProxyIpCount();
$totalPages = (int) ceil($total / $perPage);
$rows = $tracker->getBlockedProxyIps($perPage, ($page - 1) * $perPage);

render_head('全网封禁IP黑名单 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'proxy_block', $range); ?>
    <main class="content">
        <section class="card">
            <div class="section-title">
                <div>
                    <h2 style="margin:0;">全网封禁 IP 黑名单 (机房 / 海外)</h2>
                    <p class="muted" style="margin:4px 0 0;">
                        基于精准防误杀策略，此处仅展示被执行 <b>IP 级全网封禁</b> 的恶劣机房及海外节点。<br>
                        <i>注：国内普通基站/宽带触发风控时，系统已在底层实施 <b>设备级 (UID) 精准拦截</b>，为保护同基站真实用户，这些 IP 不会列入此全网黑名单。</i>
                    </p>
                </div>
                <span class="pill">总计 <?= (int) $total ?> 条</span>
            </div>
            <?php if (empty($rows)): ?>
                <div class="empty">暂无全局 IP 封禁记录。</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>恶意节点 IP</th>
                            <th>执行封禁时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <span style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="badge" style="margin-left: 8px; background: #ffebee; color: #d32f2f; padding: 2px 6px; border-radius: 4px; font-size: 12px;">高危节点</span>
                                </td>
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

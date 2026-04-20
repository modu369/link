<?php
if (!$GLOBALS['is_admin']) {
    die('无权访问系统级风控拦截日志。');
}
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// ==================================
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = $tracker->getBlockedProxyIpCount();
$totalPages = (int) ceil($total / $perPage);
$rows = $tracker->getBlockedProxyIps($perPage, ($page - 1) * $perPage);

render_head('风控拦截明细 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'proxy_block', $range); ?>
    <main class="content">
        <section class="card">
            <div class="section-title">
                <div>
                    <h2 style="margin:0;">实时风控拦截明细</h2>
                    <p class="muted" style="margin:4px 0 0;">
                        展示系统最近拦截的最多 1000 条刷量日志。<br>
                        <i>注：机房/海外节点采用 <b>IP全局封禁</b>；国内网络采用 <b>设备级精准封禁</b>，绝不牵连误杀同基站其他用户。</i>
                    </p>
                </div>
                <span class="pill">最近 <?= (int) $total ?> 条</span>
            </div>
            <?php if (empty($rows)): ?>
                <div class="empty">暂无拦截记录。</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>拦截类型</th>
                            <th>风险 IP</th>
                            <th>风险设备指纹 (UID)</th>
                            <th>风险总分</th>
                            <th>执行拦截时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (str_contains($row['type'], 'IP全局')): ?>
                                        <span class="badge" style="background: #ffebee; color: #d32f2f; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: normal;"><?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #e3f2fd; color: #1976d2; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: normal;"><?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                
                                <?php 
                                    // 处理 UID 截断与悬停
                                    $fullUid = htmlspecialchars($row['uid'], ENT_QUOTES, 'UTF-8');
                                    // 如果长度超过 20，则截取前 8 位和后 8 位，中间用 ... 代替，视觉效果更好
                                    $displayUid = mb_strlen($row['uid'], 'UTF-8') > 20 
                                        ? mb_substr($row['uid'], 0, 8, 'UTF-8') . '...' . mb_substr($row['uid'], -8, null, 'UTF-8') 
                                        : $fullUid;
                                ?>
                                <td title="<?= $fullUid ?>">
                                    <span style="font-family: monospace; font-size: 12px; color: #666; cursor: pointer;">
                                        <?= $displayUid ?>
                                    </span>
                                </td>
                                
                                <td><span style="color: #d32f2f; font-weight: bold;"><?= (int)$row['score'] ?></span></td>
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

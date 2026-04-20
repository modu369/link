<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$blockedRange = in_array($range, ['today', 'yesterday'], true) ? $range : 'today';
$blockedRows = $selectedSite ? $tracker->getBlockedDomains($siteId, $blockedRange) : [];

render_head('被拦截域名 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'blocked_domains', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择站点后查看被拦截域名。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title" style="gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <h2 style="margin:0;">被拦截域名</h2>
                        <p class="muted" style="margin:2px 0 0;">记录统计代码来源域名不在可统计域名列表中的访问（仅保留今天与昨天）</p>
                    </div>
                    <div class="filters">
                        <span class="muted" style="font-size:13px;">日期</span>
                        <a class="filter-btn <?= $blockedRange === 'today' ? 'active' : '' ?>" href="/blocked_domains.php?site=<?= (int) $siteId ?>&range=today">今日</a>
                        <a class="filter-btn <?= $blockedRange === 'yesterday' ? 'active' : '' ?>" href="/blocked_domains.php?site=<?= (int) $siteId ?>&range=yesterday">昨日</a>
                    </div>
                </div>
                <table>
                    <thead><tr><th>域名</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php if (empty($blockedRows)): ?>
                        <tr><td colspan="2" class="muted">暂无被拦截域名记录</td></tr>
                    <?php else: ?>
                        <?php foreach ($blockedRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['pv'] ?></td>
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

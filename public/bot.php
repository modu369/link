<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$data = $selectedSite ? $tracker->getBotData($siteId, $range) : null;
$totalBot = $data ? count($data['bot'] ?? []) : 0;
$totalPages = max(1, (int) ceil($totalBot / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$botRows = $data ? array_slice($data['bot'], ($page - 1) * $perPage, $perPage) : [];

render_head('蜘蛛 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'bot', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">蜘蛛流量</h2>
                        <p class="muted" style="margin:2px 0 0;">单独展示，不入核心数据</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'bot', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>抓取记录</h3><span class="muted">最新 200 条 · 每页 <?= $perPage ?> 条</span></div>
                <table>
                    <thead><tr><th>抓取页面</th><th>搜索引擎</th><th>UA</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php if (empty($botRows)): ?>
                        <tr><td colspan="4" class="muted">暂无蜘蛛抓取</td></tr>
                    <?php else: ?>
                        <?php foreach ($botRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['engine'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="muted" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php render_pagination($page, $totalPages, '/bot.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

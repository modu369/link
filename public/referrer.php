<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$device = $_GET['device'] ?? 'all';
$visitorType = $_GET['visitor'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$filters = ['device' => $device, 'visitor' => $visitorType];
$data = $selectedSite ? $tracker->getReferrerData($siteId, $range, $filters) : null;
$referrers = $data['referrers'] ?? [];
$summary = $data['ref_summary'] ?? [];
$totalReferrers = count($referrers);
$totalPages = max(1, (int) ceil($totalReferrers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$referrerPage = array_slice($referrers, ($page - 1) * $perPage, $perPage);

function ref_duration_format($seconds): string {
    $seconds = (int) round($seconds);
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

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
                        <p class="muted" style="margin:2px 0 0;">支持设备 / 访客类型筛选，默认剔除自有域名</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'referrer', (int) $selectedSite['id'], ['device' => $device, 'visitor' => $visitorType]); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="gap:12px; flex-wrap:wrap;">
                    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="site" value="<?= (int) $siteId ?>">
                        <input type="hidden" name="range" value="<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="page" value="1">
                        <label class="muted">设备类型</label>
                        <select name="device" style="padding:8px 10px;border-radius:8px;border:1px solid var(--border);">
                            <option value="all" <?= $device === 'all' ? 'selected' : '' ?>>全部</option>
                            <option value="desktop" <?= $device === 'desktop' ? 'selected' : '' ?>>电脑端</option>
                            <option value="mobile" <?= $device === 'mobile' ? 'selected' : '' ?>>移动端</option>
                        </select>
                        <label class="muted">访客类型</label>
                        <select name="visitor" style="padding:8px 10px;border-radius:8px;border:1px solid var(--border);">
                            <option value="all" <?= $visitorType === 'all' ? 'selected' : '' ?>>全部</option>
                            <option value="new" <?= $visitorType === 'new' ? 'selected' : '' ?>>新访客</option>
                            <option value="return" <?= $visitorType === 'return' ? 'selected' : '' ?>>老访客</option>
                        </select>
                        <button type="submit">筛选</button>
                    </form>
                </div>
                <div class="metric-row" style="gap:12px;">
                    <div class="metric"><div class="muted">IP数</div><div class="value"><?= (int) ($summary['ips'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">浏览量 (PV)</div><div class="value"><?= (int) ($summary['views'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">访客数 (UV)</div><div class="value"><?= (int) ($summary['uv'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">新访客数</div><div class="value"><?= (int) ($summary['new'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">会话数</div><div class="value"><?= (int) ($summary['sessions'] ?? 0) ?></div></div>
                    <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</div></div>
                    <div class="metric"><div class="muted">平均浏览页数</div><div class="value"><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></div></div>
                    <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= ref_duration_format($summary['avg_duration'] ?? 0) ?></div></div>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="margin-bottom:0;"><h3 style="margin:0;">来路列表</h3><span class="muted">每页 <?= $perPage ?> 条</span></div>
                <table>
                    <thead>
                    <tr>
                        <th>来源 URL</th>
                        <th>IP数</th>
                        <th>访客数</th>
                        <th>新访客数</th>
                        <th>贡献浏览量</th>
                        <th>平均浏览页数</th>
                        <th>平均访问时长</th>
                        <th>跳出率</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($referrers)): ?>
                        <tr><td colspan="8" class="muted">暂无来路数据</td></tr>
                    <?php else: ?>
                        <tr style="font-weight:700;">
                            <td>合计</td>
                            <td><?= (int) ($summary['ips'] ?? 0) ?></td>
                            <td><?= (int) ($summary['uv'] ?? 0) ?></td>
                            <td><?= (int) ($summary['new'] ?? 0) ?></td>
                            <td><?= (int) ($summary['views'] ?? 0) ?></td>
                            <td><?= number_format((float) ($summary['avg_pages'] ?? 0), 2) ?></td>
                            <td><?= ref_duration_format($summary['avg_duration'] ?? 0) ?></td>
                            <td><?= round(($summary['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                        </tr>
                        <?php foreach ($referrerPage as $row): ?>
                            <tr>
                                <td title="<?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?>" style="max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td><?= (int) $row['ips'] ?></td>
                                <td><?= (int) $row['uniques'] ?></td>
                                <td><?= (int) $row['uniques'] ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= number_format((float) $row['avg_pages'], 2) ?></td>
                                <td><?= ref_duration_format($row['avg_duration']) ?></td>
                                <td><?= round(($row['bounce_rate'] ?? 0) * 100, 2) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php
                    render_pagination(
                        $page,
                        $totalPages,
                        '/referrer.php',
                        ['site' => (int) $siteId, 'range' => $range, 'device' => $device, 'visitor' => $visitorType]
                    );
                ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

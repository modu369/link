<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$engine = $_GET['engine'] ?? 'all';
$engineOptions = [
    '百度',
    '谷歌',
    '必应',
    '360',
    '头条',
    '搜狗',
    '神马',
    '夸克',
    '其他',
];
$engineLabels = array_merge(['all'], $engineOptions);
if (!in_array($engine, $engineLabels, true)) {
    $engine = 'all';
}
$data = $selectedSite ? $tracker->getBotData($siteId, $range, $engine === 'all' ? null : $engine, $page, $perPage) : null;
$totalBot = $data ? (int) ($data['total'] ?? 0) : 0;
$totalPages = max(1, (int) ceil($totalBot / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$botRows = $data ? ($data['bot'] ?? []) : [];

render_head('蜘蛛 - 统计后台');
render_topbar($branding);
?>
<style>
    .bot-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .bot-filters label { display:flex; flex-direction:column; gap:6px; font-size:12px; color:var(--muted); }
    .bot-filters select { padding:8px 10px; border:1px solid var(--border); border-radius:8px; min-width:180px; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'bot', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title" style="gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <h2 style="margin:0;">蜘蛛流量</h2>
                        <p class="muted" style="margin:2px 0 0;">单独展示，不入核心数据</p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <form method="get" class="bot-filters">
                            <input type="hidden" name="site" value="<?= (int) $siteId ?>" />
                            <input type="hidden" name="range" value="<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" />
                            <label for="engine">蜘蛛类别
                                <select id="engine" name="engine" onchange="this.form.submit()">
                                    <option value="all" <?= $engine === 'all' ? 'selected' : '' ?>>全部</option>
                                    <?php foreach ($engineOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $engine === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                        <?php render_range_filters($allowedRanges, $range, 'bot', (int) $selectedSite['id']); ?>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>抓取记录</h3><span class="muted">每页 <?= $perPage ?> 条</span></div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>抓取页面</th><th>蜘蛛类别</th><th>IP</th><th>UA</th><th>时间</th></tr></thead>
                        <tbody>
                        <?php if (empty($botRows)): ?>
                            <tr><td colspan="5" class="muted">暂无蜘蛛抓取</td></tr>
                        <?php else: ?>
                            <?php foreach ($botRows as $row): ?>
                                <?php
                                    $domain = trim((string) ($row['domain'] ?? ''));
                                    $path = trim((string) ($row['path'] ?? ''));
                                    $pathLabel = $path !== '' ? $path : '/';
                                    if ($domain !== '' && !str_starts_with($pathLabel, 'http://') && !str_starts_with($pathLabel, 'https://')) {
                                        $pathLabel = $domain . '/' . ltrim($pathLabel, '/');
                                    }
                                    $botPath = htmlspecialchars($pathLabel, ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td><span class="url-ellipsis" title="<?= $botPath ?>"><?= $botPath ?></span></td>
                                    <td><?= htmlspecialchars($row['engine'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="muted" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_pagination($page, $totalPages, '/bot.php', [
                    'site' => (int) $siteId,
                    'range' => $range,
                    'engine' => $engine,
                ]); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

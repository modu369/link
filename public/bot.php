<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$engine = $_GET['engine'] ?? 'all';
$domainFilter = $_GET['domain'] ?? 'all';
$engineOptions = [
    '百度',
    '谷歌',
    '必应',
    '360',
    '头条',
    '搜狗',
    '神马',
    '华为',
    '其他',
];
$engineLabels = array_merge(['all'], $engineOptions);
if (!in_array($engine, $engineLabels, true)) {
    $engine = 'all';
}
$siteDomains = $selectedSite ? $tracker->getSiteDomains($siteId) : [];
$domainOptions = [];
if ($selectedSite) {
    $domainOptions[] = $selectedSite['domain'];
}
foreach ($siteDomains as $domainRow) {
    if (!empty($domainRow['domain'])) {
        $domainOptions[] = $domainRow['domain'];
    }
}
$domainOptions = array_values(array_unique(array_filter($domainOptions)));
if ($domainFilter !== 'all' && !in_array($domainFilter, $domainOptions, true)) {
    $domainFilter = 'all';
}
$data = $selectedSite ? $tracker->getBotData($siteId, $range, $engine === 'all' ? null : $engine, $domainFilter === 'all' ? null : $domainFilter, $page, $perPage) : null;
$totalBot = $data ? (int) ($data['total'] ?? 0) : 0;
$totalPages = max(1, (int) ceil($totalBot / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$botRows = $data ? ($data['bot'] ?? []) : [];
$botEngines = $data ? ($data['engines'] ?? []) : [];

render_head('蜘蛛 - 统计后台');
render_topbar($branding);
?>
<style>
    .bot-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .bot-filter-item { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); }
    .bot-filters select { padding:8px 10px; border:1px solid var(--border); border-radius:8px; min-width:180px; }
    .bot-engine-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:13px; color:var(--muted); }
    .bot-engine-item { display:flex; gap:6px; align-items:center; }
    .bot-engine-sep { color:var(--border); }
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
                            <div class="bot-filter-item">
                                <span>域名</span>
                                <select id="domain" name="domain" onchange="this.form.submit()">
                                    <option value="all" <?= $domainFilter === 'all' ? 'selected' : '' ?>>全部</option>
                                    <?php foreach ($domainOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $domainFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bot-filter-item">
                                <span>蜘蛛类别</span>
                                <select id="engine" name="engine" onchange="this.form.submit()">
                                    <option value="all" <?= $engine === 'all' ? 'selected' : '' ?>>全部</option>
                                    <?php foreach ($engineOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $engine === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <?php render_range_filters($allowedRanges, $range, 'bot', (int) $selectedSite['id'], [
                            'engine' => $engine,
                            'domain' => $domainFilter,
                        ]); ?>
                    </div>
                </div>
            </section>

            <?php if (!empty($botEngines)): ?>
                <section class="card">
                    <div class="section-title"><h3>蜘蛛抓取统计</h3><span class="muted">按所选日期范围</span></div>
                    <div class="bot-engine-row">
                        <?php
                            $engineChunks = [];
                            foreach ($botEngines as $engineRow) {
                                $engineLabel = trim((string) ($engineRow['engine'] ?? ''));
                                $engineCount = (int) ($engineRow['total'] ?? 0);
                                if ($engineLabel === '' || $engineCount === 0) {
                                    continue;
                                }
                                $engineChunks[] = sprintf(
                                    '<span class="bot-engine-item"><span>%s</span><strong>%s</strong></span>',
                                    htmlspecialchars($engineLabel, ENT_QUOTES, 'UTF-8'),
                                    number_format($engineCount)
                                );
                            }
                            echo implode('<span class="bot-engine-sep">|</span>', $engineChunks);
                        ?>
                    </div>
                </section>
            <?php endif; ?>

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
                                    <?php $uaLabel = htmlspecialchars($row['user_agent'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                    <td class="muted" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= $uaLabel ?>">
                                        <?= $uaLabel ?>
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
                    'domain' => $domainFilter,
                ]); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

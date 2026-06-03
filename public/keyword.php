<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
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
    '夸克',
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

$data = $selectedSite ? $tracker->getKeywordData($siteId, $range, $domainFilter === 'all' ? null : $domainFilter) : null;
$keywordRows = $data ? ($data['keywords'] ?? []) : [];

// === 1. 过滤掉纯小写英文和纯小写英文加数字的垃圾词 ===
$keywordRows = array_values(array_filter($keywordRows, function (array $row) {
    $keyword = trim((string)($row['keyword'] ?? ''));
    if (preg_match('/^[a-z0-9]+$/', $keyword) && preg_match('/[a-z]/', $keyword)) {
        return false;
    }
    return true;
}));

// === 2. 域名过滤（必须提前执行，确保统计的基数准确） ===
if ($domainFilter !== 'all') {
    $keywordRows = array_values(array_filter($keywordRows, function (array $row) use ($domainFilter) {
        $entryRaw = trim((string) ($row['entry'] ?? ''));
        if ($entryRaw === '') {
            return false;
        }
        $entryRaw = ltrim($entryRaw, '/');
        $host = strtolower(explode('/', $entryRaw)[0] ?? '');
        return $host === strtolower($domainFilter);
    }));
}

// === 3. 重新计算各搜索引擎的词数（替代原有的 $engineCounts） ===
$newEngineCounts = [];
foreach ($keywordRows as $row) {
    // 按 "/" 拆分出所有的搜索引擎 (比如 "百度 / 谷歌")
    $enginesList = array_map('trim', explode('/', (string)($row['engines'] ?? '')));
    foreach ($enginesList as $eng) {
        if ($eng === '') continue;
        if (!isset($newEngineCounts[$eng])) {
            $newEngineCounts[$eng] = 0;
        }
        $newEngineCounts[$eng]++;
    }
}
$engineCounts = [];
foreach ($newEngineCounts as $eng => $count) {
    $engineCounts[] = ['engine' => $eng, 'total' => $count];
}
// 按词数从大到小降序排列
usort($engineCounts, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

// === 4. 引擎类别过滤（仅作用于下方表格显示，不影响上方统计栏） ===
if ($engine !== 'all') {
    $keywordRows = array_values(array_filter($keywordRows, function (array $row) use ($engine) {
        $enginesList = array_map('trim', explode('/', (string) ($row['engines'] ?? '')));
        return in_array($engine, $enginesList, true);
    }));
}
$totalKeywords = count($keywordRows);
$totalPages = max(1, (int) ceil($totalKeywords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$keywords = array_slice($keywordRows, ($page - 1) * $perPage, $perPage);

render_head('关键词 - 统计后台');
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
    <?php render_sidebar($sites, $siteId, $selectedSite, 'keyword', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title" style="gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <h2 style="margin:0;">关键词</h2>
                        <p class="muted" style="margin:2px 0 0;">根据来路提取，单页维护</p>
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
                                <span>搜索引擎</span>
                                <select id="engine" name="engine" onchange="this.form.submit()">
                                    <option value="all" <?= $engine === 'all' ? 'selected' : '' ?>>全部</option>
                                    <?php foreach ($engineOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $engine === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <?php render_range_filters($allowedRanges, $range, 'keyword', (int) $selectedSite['id'], [
                            'engine' => $engine,
                            'domain' => $domainFilter,
                        ]); ?>
                    </div>
                </div>
            </section>

            <?php if (!empty($engineCounts)): ?>
                <section class="card">
                    <div class="section-title"><h3>搜索引擎词数</h3><span class="muted">按所选日期范围</span></div>
                    <div class="bot-engine-row">
                        <?php
                            $engineChunks = [];
                            foreach ($engineCounts as $engineRow) {
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
                <div class="section-title"><h3>关键词列表</h3><span class="muted">按 PV 排序 · 每页 <?= $perPage ?> 条</span></div>
                <table>
                    <thead><tr><th>关键词</th><th>搜索引擎</th><th>次数</th><th>进入页面</th></tr></thead>
                    <tbody>
                    <?php if (empty($keywords)): ?>
                        <tr><td colspan="4" class="muted">暂无关键词数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($keywords as $row): ?>
                            <?php
                                $entryRaw = trim((string) ($row['entry'] ?? ''));
                                $entryDisplay = $entryRaw !== '' ? $entryRaw : '/';
                                $entryEscaped = htmlspecialchars($entryDisplay, ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['engines'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><span class="url-ellipsis" title="<?= $entryEscaped ?>"><?= $entryEscaped ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php render_pagination($page, $totalPages, '/keyword.php', [
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

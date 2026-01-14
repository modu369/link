<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$data = $selectedSite ? $tracker->getKeywordData($siteId, $range) : null;
$totalKeywords = $data ? count($data['keywords'] ?? []) : 0;
$totalPages = max(1, (int) ceil($totalKeywords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$keywords = $data ? array_slice($data['keywords'], ($page - 1) * $perPage, $perPage) : [];

render_head('关键词 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'keyword', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">关键词</h2>
                        <p class="muted" style="margin:2px 0 0;">根据来路提取，单页维护</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'keyword', (int) $selectedSite['id']); ?>
                </div>
            </section>

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
                                $entry = trim((string) ($row['entry'] ?? '/'));
                                if ($entry === '') {
                                    $entry = '/';
                                }
                                $entryEscaped = htmlspecialchars($entry, ENT_QUOTES, 'UTF-8');
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
                <?php render_pagination($page, $totalPages, '/keyword.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

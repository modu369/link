<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getKeywordData($siteId, $range) : null;

render_head('关键词 - 统计后台');
render_topbar($config);
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
                <div class="section-title"><h3>关键词列表</h3><span class="muted">按 PV 排序</span></div>
                <table>
                    <thead><tr><th>关键词</th><th>搜索引擎</th><th>次数</th><th>进入页面</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['keywords'])): ?>
                        <tr><td colspan="4" class="muted">暂无关键词数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['keywords'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['engines'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= htmlspecialchars($row['entry'], ENT_QUOTES, 'UTF-8') ?></td>
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

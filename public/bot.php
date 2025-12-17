<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getBotData($siteId, $range) : null;

render_head('蜘蛛 - 统计后台');
render_topbar($config);
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
                <div class="section-title"><h3>抓取记录</h3><span class="muted">最新 100 条</span></div>
                <table>
                    <thead><tr><th>页面</th><th>UA</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['bot'])): ?>
                        <tr><td colspan="3" class="muted">暂无蜘蛛抓取</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['bot'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="muted" style="max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
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

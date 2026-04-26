<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}

$limit = 50;
$data = $selectedSite ? $tracker->getContentAnalysisData($siteId, $range, $limit) : [];

render_head('内容分析 - 统计后台');
render_topbar($branding);
?>
<style>
    .pill-tag { background:#deedfb; color:#1690ff; padding:4px 10px; border-radius:999px; font-weight:700; border:1px solid var(--border); }
    .heat-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 6px; }
    .heat-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #ef4444); border-radius: 3px; }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'content_analysis', $range); ?>
<main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请先选择一个站点。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">内容分析与热度</h2>
                        <p class="muted" style="margin:2px 0 0;">基于标题及复合权重的热门内容排名</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'content_analysis', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <?php if (empty($data)): ?>
                <div class="card empty">暂无内容分析数据。</div>
            <?php else: ?>
                <section class="card">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th>网页标题</th>
                                <th style="width: 18%">综合热度分</th>
                                <th style="width: 12%">IP 数</th>
                                <th style="width: 12%">独立访客 (UV)</th>
                                <th style="width: 12%">浏览量 (PV)</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $maxHeat = max(array_column($data, 'heat_score')) ?: 1; 
                            foreach ($data as $row): 
                                $percent = round(($row['heat_score'] / $maxHeat) * 100, 1);
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b; max-width: 600px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['title']) ?>">
                                            <?= htmlspecialchars($row['title']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #ef4444;"><?= number_format($row['heat_score']) ?></div>
                                        <div class="heat-bar"><div class="heat-fill" style="width: <?= $percent ?>%;"></div></div>
                                    </td>
                                    <td><?= (int) $row['ips'] ?></td>
                                    <td><?= (int) $row['uniques'] ?></td>
                                    <td><?= (int) $row['views'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

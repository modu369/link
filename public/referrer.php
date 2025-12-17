<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getReferrerData($siteId, $range) : null;

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
                        <p class="muted" style="margin:2px 0 0;">前 50 名外部来源</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'referrer', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <table>
                    <thead><tr><th>来源</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['referrers'])): ?>
                        <tr><td colspan="3" class="muted">暂无来路数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['referrers'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:12px;">
                    <div class="muted" style="margin-bottom:6px;">PV 饼图（前 8 项）</div>
                    <canvas id="refPie" width="240" height="240"></canvas>
                </div>
            </section>
            <script>
                (function(){
                    const data = <?= json_encode(array_slice($data['referrers'] ?? [],0,8)); ?>;
                    const colors = ['#0f172a','#1e293b','#334155','#475569','#64748b','#94a3b8','#cbd5e1','#e2e8f0'];
                    const total = data.reduce((s,i)=>s + (parseInt(i.views,10)||0),0) || 1;
                    const canvas = document.getElementById('refPie');
                    if(!canvas) return;
                    const ctx = canvas.getContext('2d');
                    let start = -Math.PI/2;
                    data.forEach((item,idx)=>{
                        const angle = ((parseInt(item.views,10)||0)/total) * Math.PI*2;
                        ctx.beginPath();
                        ctx.moveTo(120,120);
                        ctx.arc(120,120,110,start,start+angle);
                        ctx.closePath();
                        ctx.fillStyle = colors[idx % colors.length];
                        ctx.fill();
                        start += angle;
                    });
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

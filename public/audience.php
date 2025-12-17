<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getAudienceData($siteId, $range) : null;

render_head('访客画像 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'audience', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">新老访客</h2>
                        <p class="muted" style="margin:2px 0 0;">以 IP 维度区分新老访客</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'audience', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>新老访客分布</h3><span class="muted">按 PV / IP</span></div>
                <table>
                    <thead><tr><th>类型</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>新访客</td><td><?= (int) $data['new_vs_returning']['new'] ?></td><td><?= (int) $data['new_vs_returning']['new_ips'] ?></td></tr>
                        <tr><td>回访访客</td><td><?= (int) $data['new_vs_returning']['returning'] ?></td><td><?= (int) $data['new_vs_returning']['returning_ips'] ?></td></tr>
                    </tbody>
                </table>
                <div style="display:flex;gap:20px;align-items:center;margin-top:12px;">
                    <div>
                        <div class="muted" style="margin-bottom:6px;">PV 饼图</div>
                        <canvas id="audiencePiePv" width="160" height="160"></canvas>
                    </div>
                    <div>
                        <div class="muted" style="margin-bottom:6px;">IP 饼图</div>
                        <canvas id="audiencePieIp" width="160" height="160"></canvas>
                    </div>
                </div>
            </section>
            <script>
                (function(){
                    const pvData = [
                        {label:'新访客', value: <?= (int) $data['new_vs_returning']['new'] ?>, color:'#0f172a'},
                        {label:'回访访客', value: <?= (int) $data['new_vs_returning']['returning'] ?>, color:'#94a3b8'}
                    ];
                    const ipData = [
                        {label:'新访客', value: <?= (int) $data['new_vs_returning']['new_ips'] ?>, color:'#0f172a'},
                        {label:'回访访客', value: <?= (int) $data['new_vs_returning']['returning_ips'] ?>, color:'#94a3b8'}
                    ];
                    function drawPie(canvasId, data){
                        const canvas = document.getElementById(canvasId);
                        if(!canvas) return;
                        const ctx = canvas.getContext('2d');
                        const total = data.reduce((s,i)=>s+(i.value||0),0) || 1;
                        let start = -Math.PI/2;
                        data.forEach(item => {
                            const angle = (item.value/total) * Math.PI*2;
                            ctx.beginPath();
                            ctx.moveTo(80,80);
                            ctx.arc(80,80,70,start,start+angle);
                            ctx.closePath();
                            ctx.fillStyle = item.color;
                            ctx.fill();
                            start += angle;
                        });
                    }
                    drawPie('audiencePiePv', pvData);
                    drawPie('audiencePieIp', ipData);
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

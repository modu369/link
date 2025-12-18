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
                <div class="section-title"><h3>新老访客分布</h3><span class="muted">按 IP 计</span></div>
                <table>
                    <thead><tr><th>类型</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>新访客</td><td><?= (int) $data['new_vs_returning']['new_ips'] ?></td></tr>
                        <tr><td>回访访客</td><td><?= (int) $data['new_vs_returning']['returning_ips'] ?></td></tr>
                    </tbody>
                </table>
                <div style="margin-top:14px; display:flex; justify-content:center;">
                    <div style="max-width:400px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比</div>
                        <canvas id="audiencePie" height="220"></canvas>
                    </div>
                </div>
            </section>
            <script>
                (function(){
                    const items = [
                        {label:'新访客', value:Number(<?= (int) $data['new_vs_returning']['new_ips'] ?>)},
                        {label:'回访访客', value:Number(<?= (int) $data['new_vs_returning']['returning_ips'] ?>)}
                    ];
                    const palette = ['#1690ff','#ffd166'];
                    const el = document.getElementById('audiencePie');
                    if(!el || !window.Chart) return;
                    const total = items.reduce((s,i)=>s+Number(i.value||0),0) || 1;
                    new Chart(el, {
                        type:'pie',
                        data:{
                            labels: items.map(i=>{
                                const pct = ((Number(i.value||0)/total)*100).toFixed(1);
                                return `${i.label} ${pct}%`;
                            }),
                            datasets:[{data:items.map(i=>Number(i.value||0)),backgroundColor:items.map((_,i)=>palette[i%palette.length]),borderWidth:0}]
                        },
                        options:{
                            plugins:{
                                legend:{position:'bottom'},
                                tooltip:{
                                    callbacks:{
                                        label:(ctx)=>`${ctx.label}: ${ctx.parsed} IP`,
                                        afterLabel:(ctx)=>{
                                            const totalVal = (ctx.dataset?.data || []).reduce((s,v)=>s+Number(v||0),0) || 1;
                                            const pct = ((ctx.parsed/totalVal)*100).toFixed(1);
                                            return `占比 ${pct}%`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

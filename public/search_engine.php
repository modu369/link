<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getSearchEngineData($siteId, $range) : null;

render_head('搜索引擎 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'search_engine', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路分析 · 搜索引擎</h2>
                        <p class="muted" style="margin:2px 0 0;">按搜索引擎聚合 PV / IP</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'search_engine', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>搜索引擎列表</h3><span class="muted">按 PV 排序</span></div>
                <table>
                    <thead><tr><th>搜索引擎</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['engines'])): ?>
                        <tr><td colspan="3" class="muted">暂无搜索引擎来路</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['engines'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['engine'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px; display:flex; gap:20px; align-items:center; flex-wrap:wrap; justify-content:center;">
                    <div style="max-width:400px; flex:1; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比（前 8 项）</div>
                        <canvas id="enginePie" height="220"></canvas>
                    </div>
                </div>
            </section>
            <script>
                (function(){
                    const engines = <?= json_encode(array_slice($data['engines'] ?? [],0,8), JSON_UNESCAPED_UNICODE) ?>;
                    const palette = ['#1690ff','#73c1ff','#4dd0e1','#7c4dff','#ff8a65','#ffd166','#06d6a0','#ef476f'];
                    const ctx = document.getElementById('enginePie');
                    if(ctx && window.Chart){
                        new Chart(ctx, {
                            type:'pie',
                            data:{
                                labels: engines.map(e=>e.engine || '未知'),
                                datasets:[{
                                    data: engines.map(e=>Number(e.ips || 0)),
                                    backgroundColor: engines.map((_,i)=>palette[i % palette.length]),
                                    borderWidth: 0
                                }]
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
                    }
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

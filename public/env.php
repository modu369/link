<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getVisitorEnv($siteId, $range) : null;

render_head('系统环境概览 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'env', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">系统环境概览</h2>
                        <p class="muted" style="margin:2px 0 0;">设备类别与浏览器类型按 IP 聚合</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'env', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>设备类别</h3><span class="muted">按 PV / IP</span></div>
                <table>
                    <thead><tr><th>类别</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>电脑端</td><td><?= (int) $data['devices']['desktop']['views'] ?></td><td><?= (int) $data['devices']['desktop']['ips'] ?></td></tr>
                        <tr><td>移动端</td><td><?= (int) $data['devices']['mobile']['views'] ?></td><td><?= (int) $data['devices']['mobile']['ips'] ?></td></tr>
                    </tbody>
                </table>
                <div style="margin-top:14px; display:flex; justify-content:center;">
                    <div style="max-width:400px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比</div>
                        <canvas id="devicePie" height="220"></canvas>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>浏览器类型</h3><span class="muted">前 10</span></div>
                <table>
                    <thead><tr><th>浏览器</th><th>PV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['browsers'])): ?>
                        <tr><td colspan="3" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['browsers'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['browser'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px; display:flex; justify-content:center;">
                    <div style="max-width:400px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比（前 8 项）</div>
                        <canvas id="browserPie" height="220"></canvas>
                    </div>
                </div>
            </section>
            <script>
                (function(){
                    const deviceData = [
                        {label:'电脑端', value: Number(<?= (int) $data['devices']['desktop']['ips'] ?>)},
                        {label:'移动端', value: Number(<?= (int) $data['devices']['mobile']['ips'] ?>)}
                    ];
                    const browserData = <?= json_encode(array_slice($data['browsers'] ?? [],0,8), JSON_UNESCAPED_UNICODE) ?>;
                    const palette = ['#1690ff','#73c1ff','#4dd0e1','#7c4dff','#ff8a65','#ffd166','#06d6a0','#ef476f'];

                    function buildPie(canvasId, items){
                        const el = document.getElementById(canvasId);
                        if(!el || !window.Chart) return;
                        const total = items.reduce((s,i)=>s+Number(i.value||i.ips||0),0) || 1;
                        new Chart(el, {
                            type:'pie',
                            data:{
                                labels: items.map((i,idx)=>{
                                    const val = Number(i.value ?? i.ips ?? 0);
                                    const pct = ((val/total)*100).toFixed(1);
                                    return `${i.label || i.browser || '未知'} ${pct}%`;
                                }),
                                datasets:[{
                                    data: items.map(i=>Number(i.value ?? i.ips ?? 0)),
                                    backgroundColor: items.map((_,i)=>palette[i%palette.length]),
                                    borderWidth:0
                                }]
                            },
                            options:{plugins:{legend:{position:'bottom'}}}
                        });
                    }

                    buildPie('devicePie', deviceData);
                    buildPie('browserPie', browserData.map(b=>({label:b.browser, value:Number(b.ips||0)})));
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$data = $selectedSite ? $tracker->getVisitorEnv($siteId, $range) : null;
$view = $_GET['view'] ?? 'device'; // 默认显示设备类别

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
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'env', (int) $selectedSite['id'], ['view' => $view]); ?>
                </div>
</section>
            <div class="view-tabs">
                <button class="<?= $view === 'device' ? 'active' : '' ?>" onclick="switchEnvView('device', this)">设备类别</button>
                <button class="<?= $view === 'browser' ? 'active' : '' ?>" onclick="switchEnvView('browser', this)">浏览器类型</button>
            </div>

            <script>
                function switchEnvView(view, btn) {
                    // 更新按钮状态
                    const buttons = btn.parentElement.querySelectorAll('button');
                    buttons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    
                    // 切换显示板块
                    document.getElementById('view-device').style.display = view === 'device' ? 'block' : 'none';
                    document.getElementById('view-browser').style.display = view === 'browser' ? 'block' : 'none';

                    // --- 新增：同步更新 URL 和日期筛选器中的 view 参数 ---
                    // 更新当前浏览器地址栏，不刷新页面
                    const url = new URL(window.location.href);
                    url.searchParams.set('view', view);
                    window.history.replaceState(null, '', url);

                    // 更新快速筛选按钮 (今日、昨日等) 的 href 参数
                    document.querySelectorAll('.filter-btn').forEach(el => {
                        if (el.tagName === 'A') {
                            const elUrl = new URL(el.href);
                            elUrl.searchParams.set('view', view);
                            el.href = elUrl.href;
                        }
                    });
                    
                    // 更新自定义日期表单中的隐藏 input
                    document.querySelectorAll('.date-range-form').forEach(f => {
                        let viewInput = f.querySelector('input[name="view"]');
                        if (!viewInput) {
                            viewInput = document.createElement('input');
                            viewInput.type = 'hidden';
                            viewInput.name = 'view';
                            f.appendChild(viewInput);
                        }
                        viewInput.value = view;
                    });
                }
            </script>

            <section class="card" id="view-device" style="display: <?= $view === 'device' ? 'block' : 'none' ?>;">
                <div class="section-title"><h3>设备类别</h3><span class="muted">按 IP 聚合</span></div>
                
                <div style="margin-bottom:14px; display:flex; justify-content:center;">
                    <div style="max-width:350px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比</div>
                        <canvas id="devicePie" height="220"></canvas>
                    </div>
                </div>

                <table>
                    <thead><tr><th>类别</th><th>IP</th></tr></thead>
                    <tbody>
                        <tr><td>电脑端</td><td><?= (int) $data['devices']['desktop']['ips'] ?></td></tr>
                        <tr><td>移动端</td><td><?= (int) $data['devices']['mobile']['ips'] ?></td></tr>
                    </tbody>
                </table>
</section>

            <section class="card" id="view-browser" style="display: <?= $view === 'browser' ? 'block' : 'none' ?>;">
                <div class="section-title"><h3>浏览器类型</h3><span class="muted">前 50</span></div>
                
                <div style="margin-bottom:14px; display:flex; justify-content:center;">
                    <div style="max-width:400px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">IP 占比（前 8 项）</div>
                        <canvas id="browserPie" height="220"></canvas>
                    </div>
                </div>

                <table>
                    <thead><tr><th>浏览器</th><th>IP</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['browsers'])): ?>
                        <tr><td colspan="3" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php 
                        // 限制列表最大显示 50 条
                        $displayBrowsers = array_slice($data['browsers'], 0, 50);
                        foreach ($displayBrowsers as $row): 
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($row['browser'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                                <td><?= (int) $row['views'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
            <script>
                (function(){
                    const deviceData = [
                        {label:'电脑端', value: Number(<?= (int) $data['devices']['desktop']['ips'] ?>)},
                        {label:'移动端', value: Number(<?= (int) $data['devices']['mobile']['ips'] ?>)}
                    ];
                    // 饼图依然只取前 8 项展示，避免图表过于拥挤
                    const browserData = <?= json_encode(array_slice($data['browsers'] ?? [], 0, 8), JSON_UNESCAPED_UNICODE) ?>;
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
                            options:{
                                plugins:{
                                    legend:{position:'bottom'},
                                    tooltip:{
                                        callbacks:{
                                            label:(ctx)=>`${ctx.label}: ${ctx.parsed} IP`,
                                            afterLabel:(ctx)=>{
                                                const totalVal = (ctx.dataset?.data || []).reduce((s,v)=>s+Number(v||0),0) || 1;
                                                const pct = ((ctx.parsed/totalVal)*100).toFixed(1);
                                            }
                                        }
                                    }
                                }
                            }
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

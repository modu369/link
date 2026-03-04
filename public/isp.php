<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// 【修改点】：直接将分页参数传递给 Tracker，从数据库层面利用 LIMIT 和 OFFSET 获取数据，极大降低内存损耗
$data = $selectedSite ? $tracker->getIspData($siteId, $range, $page, $perPage) : null;

// 从返回的数据结构中分离出当前页的列表和总数
$ispPage = $data['isps'] ?? [];
$totalIsps = $data['total'] ?? 0;
$totalPages = max(1, (int) ceil($totalIsps / $perPage));

if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}

render_head('运营商分布 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'isp', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">网络服务运营商</h2>
                        <p class="muted" style="margin:2px 0 0;">基于 QQWry IPIP.ipdb 解析，按 IP 聚合</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'isp', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title">
                    <h3>运营商列表</h3>
                    <span class="muted">共 <?= $totalIsps ?> 个运营商，当前第 <?= $page ?> / <?= $totalPages ?> 页</span>
                </div>
                <div style="margin-bottom:14px; display:flex; justify-content:center;">
                    <div style="max-width:400px; width:100%; text-align:center;">
                        <div class="muted" style="margin-bottom:6px;">本页 IP 占比（前 8 项）</div>
                        <canvas id="ispPie" height="220"></canvas>
                    </div>
                </div>
                <table>
                    <thead><tr><th>运营商</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($ispPage)): ?>
                        <tr><td colspan="2" class="muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($ispPage as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['isp'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php render_pagination($page, $totalPages, '/isp.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            </section>
            <script>
                (function(){
                    // 取当前页的前8项渲染饼图
                    const isps = <?= json_encode(array_slice($ispPage, 0, 8), JSON_UNESCAPED_UNICODE) ?>;
                    const palette = ['#1690ff','#73c1ff','#4dd0e1','#7c4dff','#ff8a65','#ffd166','#06d6a0','#ef476f'];
                    const el = document.getElementById('ispPie');
                    if(!el || !window.Chart) return;
                    const total = isps.reduce((s,i)=>s+Number(i.ips||0),0) || 1;
                    new Chart(el, {
                        type:'pie',
                        data:{
                            labels:isps.map(i=>{
                                const val=Number(i.ips||0);
                                const pct=((val/total)*100).toFixed(1);
                                return `${i.isp || '未知'} ${pct}%`;
                            }),
                            datasets:[{data:isps.map(i=>Number(i.ips||0)),backgroundColor:isps.map((_,i)=>palette[i%palette.length]),borderWidth:0}]
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
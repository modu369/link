<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================

// === 新增：域名筛选逻辑 ===
$siteDomains = $selectedSite ? $tracker->getSiteDomains($siteId) : [];
$domainOptions = [];
if ($selectedSite) {
    $domainOptions[] = $selectedSite['domain'];
}
foreach ($siteDomains as $domainRow) {
    if (!empty($domainRow['domain'])) {
        $domainOptions[] = $domainRow['domain'];
    }
}
$domainOptions = array_values(array_unique(array_filter($domainOptions)));

$domainFilter = $_GET['domain'] ?? 'all';
if ($domainFilter !== 'all' && !in_array($domainFilter, $domainOptions, true)) {
    $domainFilter = 'all';
}

// 获取数据，传入域名过滤器
$data = $selectedSite ? $tracker->getSearchEngineData($siteId, $range, $domainFilter === 'all' ? null : $domainFilter) : null;

render_head('搜索引擎 - 统计后台');
render_topbar($branding);
?>
<style>
    .bot-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .bot-filter-item { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); }
    .bot-filters select { padding:8px 10px; border:1px solid var(--border); border-radius:8px; min-width:180px; }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'search_engine', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title" style="gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <h2 style="margin:0;">来路分析 · 搜索引擎</h2>
                        <p class="muted" style="margin:2px 0 0;">按搜索引擎聚合 IP</p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <form method="get" class="bot-filters">
                            <input type="hidden" name="site" value="<?= (int) $siteId ?>" />
                            <input type="hidden" name="range" value="<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" />
                            <div class="bot-filter-item">
                                <span>域名</span>
                                <select id="domain" name="domain" onchange="this.form.submit()">
                                    <option value="all" <?= $domainFilter === 'all' ? 'selected' : '' ?>>全部</option>
                                    <?php foreach ($domainOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $domainFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <?php render_range_filters($allowedRanges, $range, 'search_engine', (int) $selectedSite['id'], ['domain' => $domainFilter]); ?>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>搜索引擎列表</h3><span class="muted">按 IP 排序</span></div>
                <table>
                    <thead><tr><th>搜索引擎</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['engines'])): ?>
                        <tr><td colspan="2" class="muted">暂无搜索引擎来路</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['engines'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['engine'], ENT_QUOTES, 'UTF-8') ?></td>
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

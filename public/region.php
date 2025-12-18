<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$data = $selectedSite ? $tracker->getRegionData($siteId, $range) : null;

render_head('地域分布 - 统计后台');
render_topbar($branding);
?>
<script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
<script src="https://fastly.jsdelivr.net/npm/echarts@5.0.0-alpha.2/map/js/world.js"></script>
<script src="https://fastly.jsdelivr.net/npm/echarts@5.0.0-alpha.2/map/js/china.js"></script>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'region', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">地域分布</h2>
                        <p class="muted" style="margin:2px 0 0;">基于 IP，支持国家/地区与省份映射</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'region', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>全球热力</h3><span class="muted">可缩放，按 IP 计</span></div>
                <div id="worldMap" style="width:100%;height:520px;margin-bottom:12px;"></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>国家 / 地区</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php if (empty($data['countries'])): ?>
                            <tr><td colspan="2" class="muted">暂无数据</td></tr>
                        <?php else: ?>
                            <?php foreach ($data['countries'] as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['country'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="section-title"><h3>中国区域</h3><span class="muted">省级视图，按 IP 计</span></div>
                <div id="chinaRegionMap" style="width:100%;height:420px;margin-bottom:12px;"></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>省份</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php if (empty($data['regions'])): ?>
                            <tr><td colspan="2" class="muted">暂无数据</td></tr>
                        <?php else: ?>
                            <?php foreach ($data['regions'] as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $row['ips'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <script>
                const countries = <?= json_encode($data['countries'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
                const provinces = <?= json_encode($data['regions'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
                if (window.echarts) {
                    const worldEl = document.getElementById('worldMap');
                    if (worldEl) {
                        const worldChart = echarts.init(worldEl);
                        const normalizeCountry = (r) => {
                            const code = String(r.country_code || '').toUpperCase();
                            const name = r.country || '';
                            if (code === 'CN' || name.includes('中国')) return 'China';
                            if (code === 'US' || name.includes('美国')) return 'United States';
                            if (code === 'RU' || name.includes('俄罗斯')) return 'Russia';
                            if (code === 'JP' || name.includes('日本')) return 'Japan';
                            if (code === 'KR' || name.includes('韩国')) return 'South Korea';
                            if (code === 'HK' || name.includes('香港')) return 'Hong Kong';
                            if (code === 'TW' || name.includes('台湾')) return 'Taiwan';
                            return name || code || '未知';
                        };
                        const worldData = (countries || []).map(r => ({
                            name: normalizeCountry(r),
                            value: Number(r.ips || 0)
                        }));
                        const maxWorld = worldData.reduce((m, r) => Math.max(m, r.value || 0), 0) || 1;
                        worldChart.setOption({
                            tooltip: {
                                trigger: 'item',
                                formatter: (p) => `${p.name}<br/>IP: ${Number(p.value || 0)}`
                            },
                            visualMap: {
                                min: 0,
                                max: maxWorld,
                                text: ['多','少'],
                                left: 'left',
                                bottom: '5%',
                                inRange: { color: ['#deedfb', '#1690ff'] },
                                calculable: true
                            },
                            series: [{
                                type: 'map',
                                map: 'world',
                                roam: true,
                                emphasis: { label: { show: false } },
                                data: worldData
                            }]
                        });
                        window.addEventListener('resize', () => worldChart.resize());
                    }

                    const cnEl = document.getElementById('chinaRegionMap');
                    if (cnEl) {
                        const cnChart = echarts.init(cnEl);
                        const cnData = (provinces || []).map(r => ({ name: r.region || '未知', value: Number(r.ips || 0) }));
                        const maxCn = cnData.reduce((m, r) => Math.max(m, r.value || 0), 0) || 1;
                        cnChart.setOption({
                            tooltip: { trigger: 'item', formatter: '{b}<br/>IP: {c}' },
                            visualMap: {
                                min: 0,
                                max: maxCn,
                                left: 'left',
                                bottom: '5%',
                                text: ['多','少'],
                                inRange: { color: ['#deedfb', '#1690ff'] },
                                calculable: true
                            },
                            series: [{
                                type: 'map',
                                map: 'china',
                                roam: true,
                                emphasis: { label: { show: true } },
                                data: cnData
                            }]
                        });
                        window.addEventListener('resize', () => cnChart.resize());
                    }
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$data = $selectedSite ? $tracker->getRegionData($siteId, $range) : null;
$view = $_GET['view'] ?? 'world'; // 默认显示全球热力

render_head('地域分布 - 统计后台');
render_topbar($branding);
?>
<script src="/t_statics/js/echarts.min.js"></script>
<script src="/t_statics/js/world.js"></script>
<script src="/t_statics/js/china.js"></script>
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
                    <?php render_range_filters($allowedRanges, $range, 'region', (int) $selectedSite['id'], ['view' => $view]); ?>
                </div>
</section>
            <div class="view-tabs">
                <button class="<?= $view === 'world' ? 'active' : '' ?>" onclick="switchRegionView('world', this)">全球热力</button>
                <button class="<?= $view === 'china' ? 'active' : '' ?>" onclick="switchRegionView('china', this)">中国区域</button>
            </div>

            <script>
                function switchRegionView(view, btn) {
                    const buttons = btn.parentElement.querySelectorAll('button');
                    buttons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    
                    document.getElementById('view-world').style.display = view === 'world' ? 'block' : 'none';
                    document.getElementById('view-china').style.display = view === 'china' ? 'block' : 'none';
                    
                    // 关键修复：ECharts 所在容器恢复显示时，必须触发 resize 事件，否则地图宽度会变成 0
                    setTimeout(() => {
                        window.dispatchEvent(new Event('resize'));
                    }, 50);

                    // --- 新增：同步更新 URL 和日期筛选器中的 view 参数 ---
                    const url = new URL(window.location.href);
                    url.searchParams.set('view', view);
                    window.history.replaceState(null, '', url);

                    document.querySelectorAll('.filter-btn').forEach(el => {
                        if (el.tagName === 'A') {
                            const elUrl = new URL(el.href);
                            elUrl.searchParams.set('view', view);
                            el.href = elUrl.href;
                        }
                    });
                    
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

            <section class="card" id="view-world" style="display: <?= $view === 'world' ? 'block' : 'none' ?>;">
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

            <section class="card" id="view-china" style="display: <?= $view === 'china' ? 'block' : 'none' ?>;">
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
                        const worldNameMap = {
                            CN:'China','中国':'China',
                            US:'United States of America','USA':'United States of America','United States':'United States of America','美国':'United States of America',
                            RU:'Russia','Russian Federation':'Russia','俄罗斯':'Russia',
                            JP:'Japan','日本':'Japan',
                            KR:'Korea','South Korea':'Korea','韩国':'Korea',
                            KP:'North Korea','朝鲜':'North Korea',
                            DE:'Germany','德国':'Germany',
                            FR:'France','法国':'France',
                            GB:'United Kingdom','UK':'United Kingdom','United Kingdom':'United Kingdom','英国':'United Kingdom',
                            IT:'Italy','意大利':'Italy',
                            ES:'Spain','西班牙':'Spain',
                            CA:'Canada','加拿大':'Canada',
                            AU:'Australia','澳大利亚':'Australia',
                            BR:'Brazil','巴西':'Brazil',
                            IN:'India','印度':'India',
                            MX:'Mexico','墨西哥':'Mexico',
                            ID:'Indonesia','印尼':'Indonesia',
                            TH:'Thailand','泰国':'Thailand',
                            SG:'Singapore','新加坡':'Singapore',
                            MY:'Malaysia','马来西亚':'Malaysia',
                            PH:'Philippines','菲律宾':'Philippines',
                            VN:'Vietnam','越南':'Vietnam',
                            SA:'Saudi Arabia','沙特阿拉伯':'Saudi Arabia',
                            AE:'United Arab Emirates','阿联酋':'United Arab Emirates',
                            TR:'Turkey','土耳其':'Turkey',
                            IR:'Iran','伊朗':'Iran',
                            ZA:'South Africa','南非':'South Africa',
                            NG:'Nigeria','尼日利亚':'Nigeria',
                            EG:'Egypt','埃及':'Egypt',
                            AR:'Argentina','阿根廷':'Argentina',
                            CO:'Colombia','哥伦比亚':'Colombia',
                            CL:'Chile','智利':'Chile',
                            PE:'Peru','秘鲁':'Peru',
                            NL:'Netherlands','荷兰':'Netherlands',
                            BE:'Belgium','比利时':'Belgium',
                            CH:'Switzerland','瑞士':'Switzerland',
                            SE:'Sweden','瑞典':'Sweden',
                            NO:'Norway','挪威':'Norway',
                            DK:'Denmark','丹麦':'Denmark',
                            FI:'Finland','芬兰':'Finland',
                            PL:'Poland','波兰':'Poland',
                            UA:'Ukraine','乌克兰':'Ukraine',
                            CZ:'Czech Republic','Czechia':'Czech Republic','捷克':'Czech Republic',
                            AT:'Austria','奥地利':'Austria',
                            IE:'Ireland','爱尔兰':'Ireland',
                            IL:'Israel','以色列':'Israel',
                            NZ:'New Zealand','新西兰':'New Zealand',
                            QA:'Qatar','卡塔尔':'Qatar',
                            KW:'Kuwait','科威特':'Kuwait',
                            HK:'Hong Kong','香港':'Hong Kong',
                            TW:'Taiwan','台湾':'Taiwan'
                        };
                        const mapCountryName = (r) => {
                            const code = String(r.country_code || '').toUpperCase();
                            const raw = (r.country || '').trim();
                            if (code && worldNameMap[code]) return worldNameMap[code];
                            if (raw && worldNameMap[raw]) return worldNameMap[raw];
                            if (code) return code;
                            return raw || 'Unknown';
                        };
                        const worldData = (countries || []).map(r => ({
                            name: mapCountryName(r),
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
                                nameMap: worldNameMap,
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

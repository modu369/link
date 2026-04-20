<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$filters = [
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'visitor' => $_GET['visitor'] ?? 'all',
    'engine' => $_GET['engine'] ?? '',
    'city' => $_GET['city'] ?? '',
    'entry' => $_GET['entry'] ?? '',
    'session' => $_GET['session'] ?? '',
    'ip' => $_GET['ip'] ?? '',
    'keyword' => $_GET['keyword'] ?? '',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$detailRange = in_array($range, ['today', 'yesterday', 'day_before', '7d'], true)
    ? $range
    : (str_starts_with($range, 'custom:') ? $range : '7d');
$data = $selectedSite ? $tracker->getContentData($siteId, $detailRange, $filters, $page, $perPage) : null;
if ($selectedSite && $data) {
    $totalSessions = (int) ($data['total_sessions'] ?? 0);
    $totalPages = max(1, (int) ceil($totalSessions / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
} else {
    $totalPages = 1;
    $totalSessions = 0;
}

render_head('访问明细 - 统计后台');
render_topbar($branding);
?>
<style>
    /* 核心网格与卡片布局 */
    .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px; }
    .metric-tile { background: linear-gradient(135deg, #deedfb 0%, #f7fbff 100%); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.6); }
    .metric-icon { width: 48px; height: 48px; border-radius: 12px; background: #fff; display: grid; place-items: center; color: #1690ff; font-size: 22px; box-shadow: 0 10px 22px rgba(22,144,255,0.12); flex-shrink: 0; }
    .metric-info { display: flex; flex-direction: column; gap: 4px; }
    .metric-info .label { color: var(--muted); font-size: 13px; font-weight: 500; }
    .metric-info .val { font-size: 20px; font-weight: 700; color: #0f172a; }
    
    /* 筛选表单布局 */
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: end; }
    .form-control { display: flex; flex-direction: column; gap: 6px; margin: 0; }
    .form-control label { font-size: 13px; color: #0f172a; font-weight: 600; }
    .form-control input, .form-control select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 13px; background: #fff; outline: none; transition: border-color 0.2s; box-sizing: border-box; }
    .form-control input:focus, .form-control select:focus { border-color: #1690ff; }
    
    /* 按钮样式 */
    .btn-primary { padding: 10px 18px; background: #1690ff; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-align: center; transition: background 0.2s; }
    .btn-primary:hover { background: #0f7ae5; }
    .btn-reset { padding: 9px 18px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; text-align: center; }
    .btn-reset:hover { background: #e2e8f0; color: #0f172a; }
    
    /* 表格布局优化 */
    .table-wrap { overflow-x: auto; width: 100%; border-radius: 10px; border: 1px solid var(--border); background: #fff; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
    th, td { padding: 12px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
    th { background: #f8fbff; color: #4a6480; font-weight: 600; position: sticky; top: 0; z-index: 1; }
    tbody tr:hover { background: #f1f5f9; }
    
    /* 状态与标签 */
    .badge { padding: 4px 8px; background: #deedfb; color: #1690ff; border-radius: 6px; font-size: 12px; font-weight: 600; }
    .status-live { color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .status-live::before { content: ''; width: 8px; height: 8px; background: #10b981; border-radius: 50%; display: inline-block; box-shadow: 0 0 6px rgba(16,185,129,0.6); animation: pulse 2s infinite; }
    .status-done { color: var(--muted); display: flex; align-items: center; gap: 6px; }
    .status-done::before { content: ''; width: 8px; height: 8px; background: #cbd5e1; border-radius: 50%; display: inline-block; }
    @keyframes pulse { 0% { opacity: 1; box-shadow: 0 0 0 0 rgba(16,185,129,0.4); } 70% { opacity: 0.6; box-shadow: 0 0 0 4px rgba(16,185,129,0); } 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
    
    /* 文本截断，防止表格被撑爆 */
    .path-truncate { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
    .pill-tag { background: #deedfb; color: #1690ff; padding: 4px 10px; border-radius: 999px; font-weight: 700; border: 1px solid var(--border); font-size: 12px; }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'content', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card" style="padding-bottom: 24px;">
                <div class="section-title">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <h2 style="margin:0;">访问明细</h2>
                        <span class="pill-tag">会话级追踪</span>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'content', (int) $selectedSite['id']); ?>
                </div>
                
                <div class="metric-grid">
                    <div class="metric-tile">
                        <div class="metric-icon">⚡</div>
                        <div class="metric-info"><div class="label">近 5 分钟活跃</div><div class="val"><?= (int)($data['active'][5] ?? 0) ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon">⏱</div>
                        <div class="metric-info"><div class="label">近 15 分钟活跃</div><div class="val"><?= (int)($data['active'][15] ?? 0) ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon">🕒</div>
                        <div class="metric-info"><div class="label">近 30 分钟活跃</div><div class="val"><?= (int)($data['active'][30] ?? 0) ?></div></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-icon">📄</div>
                        <div class="metric-info"><div class="label">当前结果总数</div><div class="val"><?= (int)$data['total_sessions'] ?></div></div>
                    </div>
                </div>

                <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border);">
                    <form method="get" class="form-grid">
                        <input type="hidden" name="site" value="<?= (int)$siteId ?>" />
                        <input type="hidden" name="page" value="1" />
                        <input type="hidden" name="range" value="<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" />
                        
                        <div class="form-control">
                            <label>日期</label>
                            <input type="date" name="date" value="<?= htmlspecialchars($filters['date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-control">
                            <label>访客类型</label>
                            <select name="visitor">
                                <option value="all" <?= $filters['visitor']==='all'?'selected':''; ?>>全部访客</option>
                                <option value="new" <?= $filters['visitor']==='new'?'selected':''; ?>>新访客</option>
                                <option value="return" <?= $filters['visitor']==='return'?'selected':''; ?>>老访客</option>
                            </select>
                        </div>
                        <div class="form-control">
                            <label>搜索引擎</label>
                            <select name="engine">
                                <option value="" <?= empty($filters['engine'])?'selected':''; ?>>全部引擎</option>
                                <option value="baidu" <?= $filters['engine']==='baidu'?'selected':''; ?>>百度</option>
                                <option value="sm" <?= $filters['engine']==='sm'?'selected':''; ?>>神马/夸克</option>
                                <option value="so" <?= $filters['engine']==='so'?'selected':''; ?>>360</option>
                                <option value="bing" <?= $filters['engine']==='bing'?'selected':''; ?>>必应</option>
                                <option value="sogou" <?= $filters['engine']==='sogou'?'selected':''; ?>>搜狗</option>
                            </select>
                        </div>
                        <div class="form-control">
                            <label>IP 地址</label>
                            <input type="text" name="ip" placeholder="输入搜索 IP" value="<?= htmlspecialchars($filters['ip'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-control">
                            <label>搜索关键词</label>
                            <input type="text" name="keyword" placeholder="输入关键词" value="<?= htmlspecialchars($filters['keyword'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-control">
                            <label>入口路径</label>
                            <input type="text" name="entry" placeholder="例如 /about" value="<?= htmlspecialchars($filters['entry'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-control">
                            <label>唯一标识码</label>
                            <input type="text" name="session" placeholder="Session ID" value="<?= htmlspecialchars($filters['session'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-control">
                            <label>地域城市</label>
                            <input type="text" name="city" placeholder="例如 北京" value="<?= htmlspecialchars($filters['city'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        
                        <div style="display:flex; gap:10px; margin-top: 6px;">
                            <button class="btn-primary" style="flex:1;" type="submit">筛选检索</button>
                            <a class="btn-reset" href="/content.php?site=<?= (int)$siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">重置</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="justify-content: space-between;">
                    <h3 style="margin:0;">数据列表</h3>
                    <span class="muted" style="font-size:13px;">仅展示近15天数据 · 当前第 <?= $page ?>/<?= $totalPages ?> 页</span>
                </div>
                
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>时间</th>
                            <th>标识码</th>
                            <th>访客</th>
                            <th>地域</th>
                            <th>IP</th>
                            <th>浏览器</th>
                            <th>入口页</th>
                            <th>当前页</th>
                            <th>关键词</th>
                            <th>页数</th>
                            <th>时长</th>
                            <th>状态</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['details'])): ?>
                            <tr><td colspan="12" style="text-align:center; padding: 30px; color: var(--muted);">暂无符合条件的数据</td></tr>
                        <?php endif; ?>
                        <?php foreach ($data['details'] as $row): ?>
                            <?php
                                $ua = $row['user_agent'] ?? '';
                                $browser = '未知';
                                if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) $browser = 'Chrome';
                                elseif (stripos($ua, 'Edg') !== false) $browser = 'Edge';
                                elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
                                elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';
                                elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) $browser = 'Opera';
                                elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident') !== false) $browser = 'IE';
                                elseif (stripos($ua, 'micromessenger') !== false) $browser = 'WeChat';
                                
                                $status = (strtotime($row['updated_at'] ?? $row['occurred_at']) > time() - 180) ? 'live' : 'done';
                                $entryPath = $row['entry_path'] ?? '-';
                                $currentPath = $row['path'] ?? '-';
                                $keyword = $row['keyword'] ?? '-';
                                if ($keyword === '') $keyword = '-';
                            ?>
                            <tr>
                                <td style="color:#4a6480; font-weight:500;"><?= htmlspecialchars(substr($row['occurred_at'], 11, 8), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="path-truncate" style="max-width: 80px; font-family: monospace;" title="<?= htmlspecialchars($row['session_id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($row['session_id'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td><span class="badge"><?= $row['is_unique'] ? '新访客' : '老访客' ?></span></td>
                                <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($browser, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><div class="path-truncate" title="<?= htmlspecialchars($entryPath, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($entryPath, ENT_QUOTES, 'UTF-8') ?></div></td>
                                <td><div class="path-truncate" title="<?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?></div></td>
                                <td><div class="path-truncate" style="max-width: 100px;" title="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?></div></td>
                                <td style="font-weight: 600; text-align: center;"><?= (int)($row['page_count'] ?? 1) ?></td>
                                <td style="font-family: monospace;"><?= gmdate('i:s', max(0, (int)($row['duration_seconds'] ?? 0))) ?></td>
                                <td>
                                    <?php if ($status === 'live'): ?>
                                        <span class="status-live">活跃</span>
                                    <?php else: ?>
                                        <span class="status-done">离开</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 16px;">
                    <?php
                        $paginationParams = array_merge(
                            ['site' => (int) $siteId, 'range' => $range],
                            $filters
                        );
                        render_pagination($page, $totalPages, '/content.php', $paginationParams);
                    ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

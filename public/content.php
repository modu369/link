<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

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
    .content-grid { display:flex; flex-wrap:wrap; gap:12px; }
    .summary-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:14px; display:flex; gap:10px; align-items:center; box-shadow:0 10px 24px rgba(22,144,255,0.08); flex:0 1 auto; min-width:200px; }
    .summary-icon { width:44px; height:44px; border-radius:12px; background:#deedfb; display:grid; place-items:center; color:#1690ff; font-size:18px; flex-shrink:0; }
    .summary-info { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .summary-info .label { color:var(--muted); font-size:12px; font-weight:400; word-break:break-word; }
    .summary-info .val { font-size:12px; font-weight:400; word-break:break-word; }
    .filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; width:100%; }
    .filters label { font-size:12px; color:var(--muted); display:flex; flex-direction:column; gap:4px; min-width:160px; flex:1 1 200px; max-width:240px; }
    .filters input, .filters select { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; box-sizing:border-box; }
    .visit-table-wrapper { overflow:auto; max-height:700px; width:100%; }
    .visit-table { width:100%; border-collapse: collapse; table-layout: fixed; font-size:12px; }
    .visit-table th, .visit-table td { border-bottom:1px solid var(--border); padding:6px 4px; text-align:left; word-break:break-all; }
    .visit-table th { background:#f8fbff; color:#0f172a; position:sticky; top:0; }
    .visit-table tbody tr:hover { background:#f4f8ff; }
    .badge { display:inline-block; padding:2px 8px; border-radius:10px; background:#deedfb; color:#1690ff; font-weight:700; font-size:12px; }
    .status-live { color:#15a655; font-weight:700; }
    .status-done { color:#999; }
    .note { color:var(--muted); font-size:13px; }
    @media (max-width: 1280px) {
        .visit-table-wrapper { transform: scale(0.96); transform-origin: top left; width: calc(100% / 0.96); }
    }
</style>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'content', $range); ?>
    <main class="content">
        <?php if (!$selectedSite || !$data): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">访问明细</h2>
                        <p class="muted" style="margin:2px 0 0;">访问明细按会话数记录，一个会话一条记录。仅支持查询近15天，每次最多展示 50,000 条。</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'content', (int) $selectedSite['id']); ?>
                </div>
                <div class="content-grid">
                    <div class="summary-card"><div class="summary-icon">⚡</div><div class="summary-info"><div class="label">实时活跃访客数</div><div class="val">近5分钟 <?= (int)($data['active'][5] ?? 0) ?></div></div></div>
                    <div class="summary-card"><div class="summary-icon">⏱</div><div class="summary-info"><div class="label">最近 15 分钟</div><div class="val"><?= (int)($data['active'][15] ?? 0) ?></div></div></div>
                    <div class="summary-card"><div class="summary-icon">🕒</div><div class="summary-info"><div class="label">最近 30 分钟</div><div class="val"><?= (int)($data['active'][30] ?? 0) ?></div></div></div>
                    <div class="summary-card"><div class="summary-icon">📄</div><div class="summary-info"><div class="label">当前结果数</div><div class="val"><?= (int)$data['total_sessions'] ?></div></div></div>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="align-items:flex-start;">
                    <div>
                        <h3 style="margin:0;">筛选</h3>
                        <p class="note">启用垃圾广告信息屏蔽</p>
                    </div>
                    <form method="get" class="filters">
                        <input type="hidden" name="site" value="<?= (int)$siteId ?>" />
                        <input type="hidden" name="page" value="1" />
                        <label>时间
                            <input type="date" name="date" value="<?= htmlspecialchars($filters['date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>">
                        </label>
                        <label>访客
                            <select name="visitor">
                                <option value="all" <?= $filters['visitor']==='all'?'selected':''; ?>>全部访客</option>
                                <option value="new" <?= $filters['visitor']==='new'?'selected':''; ?>>新访客</option>
                                <option value="return" <?= $filters['visitor']==='return'?'selected':''; ?>>老访客</option>
                            </select>
                        </label>
                        <label>搜索引擎
                            <select name="engine">
                                <option value="" <?= empty($filters['engine'])?'selected':''; ?>>请选择搜索引擎</option>
                                <option value="baidu" <?= $filters['engine']==='baidu'?'selected':''; ?>>百度</option>
                                <option value="sm" <?= $filters['engine']==='sm'?'selected':''; ?>>神马/Quark</option>
                                <option value="so" <?= $filters['engine']==='so'?'selected':''; ?>>360</option>
                                <option value="bing" <?= $filters['engine']==='bing'?'selected':''; ?>>必应</option>
                                <option value="sogou" <?= $filters['engine']==='sogou'?'selected':''; ?>>搜狗</option>
                            </select>
                        </label>
                        <label>城市
                            <input type="text" name="city" placeholder="请选择" value="<?= htmlspecialchars($filters['city'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>入口页
                            <input type="text" name="entry" placeholder="请输入入口页网址" value="<?= htmlspecialchars($filters['entry'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>标识码
                            <input type="text" name="session" placeholder="请输入访客唯一标识码" value="<?= htmlspecialchars($filters['session'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>IP地址
                            <input type="text" name="ip" placeholder="请输入IP" value="<?= htmlspecialchars($filters['ip'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>关键词
                            <input type="text" name="keyword" placeholder="请输入搜索引擎关键词" value="<?= htmlspecialchars($filters['keyword'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <div style="display:flex;align-items:flex-end; gap:8px;">
                            <button class="primary" type="submit">筛选</button>
                            <a class="filter-btn" href="/content.php?site=<?= (int)$siteId ?>">重置</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="justify-content:space-between;">
                    <h3 style="margin:0;">访问明细</h3>
                    <span class="note">共 <?= (int)$data['total_sessions'] ?> 条 · 近15天数据 · 每页 <?= $perPage ?> 条</span>
                </div>
                <div class="visit-table-wrapper">
                    <table class="visit-table">
                        <thead>
                        <tr>
                            <th>时间</th>
                            <th>标识码</th>
                            <th>访客类型</th>
                            <th>地域</th>
                            <th>IP</th>
                            <th>浏览器</th>
                            <th>入口页</th>
                            <th>当前页</th>
                            <th>关键词</th>
                            <th>会话页数</th>
                            <th>时长</th>
                            <th>状态</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['details'])): ?>
                            <tr><td colspan="12" class="muted">暂无数据</td></tr>
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
                                $status = (strtotime($row['occurred_at']) > time() - 180) ? '正在访问' : '已结束';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(substr($row['occurred_at'], 11, 8), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['session_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge"><?= $row['is_unique'] ? '新访客' : '老访客' ?></span></td>
                                <td><?= htmlspecialchars($row['region'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($browser, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['entry_path'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['path'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['keyword'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)($row['page_count'] ?? 1) ?></td>
                                <td><?= gmdate('i:s', max(0, (int)($row['duration_seconds'] ?? 0))) ?></td>
                                <td><?= $status === '正在访问' ? '<span class="status-live">正在访问</span>' : '<span class="status-done">结束</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                    $paginationParams = array_merge(
                        ['site' => (int) $siteId, 'range' => $range],
                        $filters
                    );
                    render_pagination($page, $totalPages, '/content.php', $paginationParams);
                ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>

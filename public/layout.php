<?php
function render_head(string $title = '统计后台'): void
{
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/modern-normalize/modern-normalize.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <style>
            :root {
                --primary: #1690ff;
                --primary-2: #4dadff;
                --primary-3: #73c1ff;
                --muted: #4a6480;
                --border: #c5dcf5;
                --bg: #deedfb;
                --card-gradient: linear-gradient(135deg, #deedfb 0%, #f5f9ff 100%);
            }
            body { margin: 0; font-family: "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", Arial, sans-serif; background: var(--bg); color: #0f172a; }
            header { background: #fff; padding: 18px 28px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 5; box-shadow: 0 8px 24px rgba(37,99,235,0.06); }
            .brand { font-size: 20px; font-weight: 700; }
            .muted { color: var(--muted); }
            .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(22, 144, 255, 0.12); }
            h1, h2, h3 { margin: 0 0 12px; color: #1690ff; }
            .form-control { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
            input[type="text"], input[type="password"] { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 14px; }
            button { padding: 10px 14px; border: none; border-radius: 8px; cursor: pointer; background: #deedfb; color: #1690ff; font-weight: 700; box-shadow: 0 10px 24px rgba(22, 144, 255, 0.18); border: 1px solid var(--border); }
            button.ghost { background: #fff; color: #1690ff; border: 1px solid var(--border); }
            .top-bar { display: flex; gap: 10px; align-items: center; }
            .logout { color: #ef4444; text-decoration: none; font-weight: 600; }
            .sites-layout { padding: 22px 24px 32px; display: grid; gap: 16px; }
            .site-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
            .site-card { border: 1px solid var(--border); border-radius: 12px; padding: 14px; background: #fff; position: relative; }
            .site-card .name { font-weight: 700; margin-bottom: 6px; }
            .site-card .meta { color: var(--muted); font-size: 12px; }
            .site-card code { background: #0f172a; color: #e2e8f0; padding: 10px; display: block; border-radius: 8px; margin: 10px 0; font-size: 12px; word-break: break-all; }
            .site-card .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
            .site-card .enter { text-decoration: none; color: #1690ff; font-weight: 700; }
            .data-layout { display: grid; grid-template-columns: minmax(220px, 260px) minmax(0, 1fr); gap: 16px; padding: 22px 24px 32px; align-items: start; width: 100%; box-sizing: border-box; }
            .nav { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(22, 144, 255, 0.12); position: sticky; top: 90px; }
            .nav .site-name { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
            .nav .site-domain { color: var(--muted); font-size: 12px; margin-bottom: 12px; }
            .nav select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 12px; }
            .nav-section { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
            .nav-toggle { width: 100%; text-align: left; background: #deedfb; border: none; padding: 10px 12px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; cursor: pointer; color: #1690ff; }
            .nav-toggle span { color: #1690ff; font-weight: 600; font-size: 13px; }
            .nav-links { display: none; padding: 6px 0; }
            .nav-section.open .nav-links { display: block; }
            .nav a { display: block; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: #1690ff; font-weight: 600; border: 1px solid transparent; margin: 4px 8px; }
            .nav a.active { background: #1690ff; color: #fff; border-color: #1690ff; box-shadow: 0 8px 18px rgba(22,144,255,0.18); }
            .nav .return-link { display: block; width: 100%; margin-top: 12px; text-align: center; padding: 10px 12px; border-radius: 10px; background: #deedfb; font-weight: 700; color: #1690ff; text-decoration: none; border: 1px dashed var(--border); box-shadow: 0 12px 30px rgba(22, 144, 255, 0.12); }
            .content { display: grid; gap: 12px; }
            .filters { display: flex; gap: 10px; align-items: center; justify-content: flex-end; }
            .filter-btn { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); background: #deedfb; cursor: pointer; font-weight: 600; color: #1690ff; text-decoration: none; }
            .filter-btn.active { background: #1690ff; color: #fff; border-color: #1690ff; box-shadow: 0 8px 18px rgba(22,144,255,0.18); }
            .metric-row { display: flex; flex-wrap: wrap; gap: 10px; }
            .metric { padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--card-gradient); color: #0f172a; box-shadow: inset 0 1px 0 rgba(255,255,255,0.6); flex: 1 1 170px; min-width: 160px; box-sizing: border-box; }
            .metric .value { font-size: 12px; font-weight: 400; word-break: break-word; }
            .card-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; align-items: start; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; }
            th { color: var(--muted); font-weight: 600; }
            .table-wrapper { width: 100%; overflow-x: auto; }
            .url-ellipsis { max-width: 320px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
            @media (max-width: 768px) {
                .url-ellipsis { max-width: 220px; }
            }
            code.inline { background: #0f172a; color: #e2e8f0; padding: 12px; display: block; border-radius: 8px; word-break: break-all; }
            .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
            .pill { padding: 4px 8px; background: #f1f5f9; border-radius: 999px; color: #0f172a; border: 1px solid var(--border); font-size: 12px; }
            .empty { padding: 24px; text-align: center; color: var(--muted); }
            .pagination { display: flex; gap: 8px; align-items: center; justify-content: flex-end; padding: 8px 0; flex-wrap: wrap; }
            .pagination a, .pagination span { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: #1690ff; font-weight: 600; background: #fff; min-width: 36px; text-align: center; }
            .pagination .current { background: #1690ff; color: #fff; box-shadow: 0 8px 18px rgba(22,144,255,0.18); }
            .pagination .disabled { color: var(--muted); border-style: dashed; background: #f8fbff; }
            @media (max-width: 1100px) {
                .data-layout { grid-template-columns: 1fr; }
                .nav { position: static; top: auto; }
            }
        </style>
    </head>
    <body>
    <?php
}

function render_topbar(array $branding): void
{
    ?>
    <header>
        <a href="/sites.php" style="text-decoration:none; color:inherit;">
            <div class="brand"><?= htmlspecialchars($branding['brand_title'] ?? 'V6统计后台', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="muted"><?= htmlspecialchars($branding['brand_subtitle'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </a>
        <div class="top-bar">
            <a class="logout" style="color:#0f172a;text-decoration:none;font-weight:700;" href="/user.php"><?= htmlspecialchars($_SESSION['admin_user'] ?? '管理员', ENT_QUOTES, 'UTF-8') ?></a>
            <a class="logout" href="?action=logout">退出</a>
        </div>
    </header>
    <?php
}

function render_sidebar(array $sites, ?int $siteId, ?array $selectedSite, string $active, string $range): void
{
    ?>
    <aside class="nav">
        <?php if ($selectedSite): ?>
            <div class="site-name"><?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="site-domain">域名 <?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?>（含 www）</div>
        <?php endif; ?>
        <select onchange="location.href=this.value;">
            <?php foreach ($sites as $site): ?>
                <option value="<?= htmlspecialchars($active, ENT_QUOTES, 'UTF-8') ?>.php?site=<?= (int) $site['id'] ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" <?= ($siteId === (int) $site['id']) ? 'selected' : '' ?>><?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        $sections = [
            'core' => [
                'title' => '概况',
                'items' => [
                    ['key' => 'overview', 'label' => '总览', 'href' => "/overview.php?site={$siteId}&range={$range}"],
                    ['key' => 'trend', 'label' => '趋势分析', 'href' => "/trend.php?site={$siteId}&range={$range}"],
                    ['key' => 'content', 'label' => '访问明细', 'href' => "/content.php?site={$siteId}&range={$range}"],
                    ['key' => 'mobile', 'label' => '移动端', 'href' => "/mobile.php?site={$siteId}&range={$range}"],
                    ['key' => 'bot', 'label' => '蜘蛛', 'href' => "/bot.php?site={$siteId}&range={$range}"],
                ],
            ],
            'refer' => [
                'title' => '来路分析',
                'items' => [
                    ['key' => 'search_engine', 'label' => '搜索引擎', 'href' => "/search_engine.php?site={$siteId}&range={$range}"],
                    ['key' => 'keyword', 'label' => '关键词', 'href' => "/keyword.php?site={$siteId}&range={$range}"],
                    ['key' => 'external', 'label' => '外部链接', 'href' => "/external.php?site={$siteId}&range={$range}"],
                ],
            ],
            'visitor' => [
                'title' => '访问者信息',
                'items' => [
                    ['key' => 'env', 'label' => '系统环境概览', 'href' => "/env.php?site={$siteId}&range={$range}"],
                    ['key' => 'region', 'label' => '地域分布', 'href' => "/region.php?site={$siteId}&range={$range}"],
                    ['key' => 'isp', 'label' => '运营商', 'href' => "/isp.php?site={$siteId}&range={$range}"],
                    ['key' => 'audience', 'label' => '新老访客', 'href' => "/audience.php?site={$siteId}&range={$range}"],
                    ['key' => 'referrer', 'label' => '来路详情', 'href' => "/referrer.php?site={$siteId}&range={$range}"],
                    ['key' => 'pages', 'label' => '受访页', 'href' => "/pages.php?site={$siteId}&range={$range}"],
                    ['key' => 'entry', 'label' => '入口页', 'href' => "/entry.php?site={$siteId}&range={$range}"],
                ],
            ],
            'config' => [
                'title' => '配置',
                'items' => [
                    ['key' => 'config', 'label' => '配置修改', 'href' => "/config.php?site={$siteId}&range={$range}"],
                ],
            ],
        ];

        foreach ($sections as $sectionKey => $section):
            $open = in_array($active, array_column($section['items'], 'key'), true);
            ?>
            <div class="nav-section <?= $open ? 'open' : '' ?>" data-section="<?= $sectionKey ?>">
                <button class="nav-toggle" type="button" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?>
                    <span>▼</span>
                </button>
                <div class="nav-links">
                    <?php foreach ($section['items'] as $item): ?>
                        <a class="<?= $active === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <a class="return-link" href="/sites.php">⏎ 返回域名列表</a>
        <script>
            document.querySelectorAll('.nav-section .nav-toggle').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var section = btn.closest('.nav-section');
                    var open = section.classList.toggle('open');
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });
        </script>
    </aside>
    <?php
}

function render_range_filters(array $allowedRanges, string $range, string $page, int $siteId, array $extra = []): void
{
    ?>
    <div class="filters">
        <span class="muted" style="font-size:13px;">时间范围</span>
        <?php foreach ($allowedRanges as $r): ?>
            <?php $qs = http_build_query(array_merge(['site' => (int)$siteId, 'range' => $r], $extra)); ?>
            <a class="filter-btn <?= $range === $r ? 'active' : '' ?>" href="/<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>.php?<?= $qs ?>">
                <?= ['today' => '今日', 'yesterday' => '昨日', '7d' => '近7天', '30d' => '近30天'][$r] ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_pagination(int $page, int $totalPages, string $path, array $params = []): void
{
    if ($totalPages <= 1) {
        return;
    }
    $page = max(1, $page);
    $totalPages = max(1, $totalPages);
    $prevPage = max(1, $page - 1);
    $nextPage = min($totalPages, $page + 1);
    $renderLink = function(int $p, string $label, bool $disabled = false, bool $current = false) use ($path, $params) {
        $query = http_build_query(array_merge($params, ['page' => $p]));
        $href = $path . '?' . $query;
        $class = $current ? 'current' : '';
        if ($disabled) {
            echo '<span class="disabled">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            echo '<a class="' . $class . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    };
    ?>
    <nav class="pagination" aria-label="分页">
        <?php
        $renderLink($prevPage, '上一页', $page === 1);
        $window = 2;
        $start = max(1, $page - $window);
        $end = min($totalPages, $page + $window);
        if ($start > 1) {
            $renderLink(1, '1', false, $page === 1);
            if ($start > 2) {
                echo '<span class="disabled">...</span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $renderLink($i, (string) $i, false, $i === $page);
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="disabled">...</span>';
            }
            $renderLink($totalPages, (string) $totalPages, false, $page === $totalPages);
        }
        $renderLink($nextPage, '下一页', $page === $totalPages);
        ?>
    </nav>
    <?php
}

function render_footer(): void
{
    echo "</body></html>";
}

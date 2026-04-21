<?php
function render_head(string $title = '统计后台'): void
{
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <link rel="stylesheet" href="/t_statics/css/modern-normalize.css">
        <link rel="stylesheet" href="/t_statics/css/v6-default.css">
        <script src="/t_statics/js/chart.js"></script>
    </head>
    <body>
    <?php
}

function render_topbar(array $branding): void
{
    $isAdmin = $GLOBALS['is_admin'] ?? false;
    $displayName = $isAdmin 
        ? ($_SESSION['admin_user'] ?? '管理员') 
        : (!empty($_SESSION['nickname']) ? $_SESSION['nickname'] : ($_SESSION['username'] ?? '用户'));
    ?>
    <header>
        <a href="/sites.php" style="text-decoration:none; color:inherit;">
            <div class="brand"><?= htmlspecialchars($branding['brand_title'] ?? 'V6统计后台', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="muted"><?= htmlspecialchars($branding['brand_subtitle'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </a>
        <div class="top-bar">
            <div class="user-menu">
                <div class="user-trigger"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="dropdown-content">
                    <?php if ($isAdmin): ?>
                        <a href="/user.php">系统设置</a>
                        <a href="/admin_users.php">用户管理</a>
                    <?php else: ?>
                        <a href="/user_settings.php">个人设置</a>
                    <?php endif; ?>
                    <div class="divider"></div>
                    <a href="?action=logout" class="logout-link">退出登录</a>
                </div>
            </div>
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
    'items' => array_values(array_filter([
        ['key' => 'config', 'label' => '配置修改', 'href' => "/config.php?site={$siteId}&range={$range}"],
        ['key' => 'blocked_domains', 'label' => '拦截域名', 'href' => "/blocked_domains.php?site={$siteId}&range={$range}"],
        ['key' => 'code', 'label' => '获取代码', 'href' => "/code.php?site={$siteId}&range={$range}"],
        // 仅限管理员显示
        $GLOBALS['is_admin'] ? ['key' => 'proxy_block', 'label' => '风控拦截', 'href' => "/proxy_block.php?site={$siteId}&range={$range}"] : null,
    ])),
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
    $isCustom = str_starts_with($range, 'custom:');
    $customStart = '';
    $customEnd = '';
    if ($isCustom) {
        $parts = explode(':', $range);
        $customStartRaw = $parts[1] ?? '';
        $customEndRaw = $parts[2] ?? '';
        $customStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStartRaw) ? $customStartRaw : '';
        $customEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEndRaw) ? $customEndRaw : '';
    }
    ?>
    <div class="filters">
        <span class="muted" style="font-size:13px;">时间范围</span>
        <?php foreach ($allowedRanges as $r): ?>
            <?php $qs = http_build_query(array_merge(['site' => (int)$siteId, 'range' => $r], $extra)); ?>
            <a class="filter-btn <?= $range === $r ? 'active' : '' ?>" href="/<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>.php?<?= $qs ?>">
                <?= ['today' => '今日', 'yesterday' => '昨日', 'day_before' => '前天', '7d' => '近7天'][$r] ?>
            </a>
        <?php endforeach; ?>
        <form method="get" action="/<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>.php" class="date-range-form" onsubmit="return applyCustomRange(this);">
            <?php foreach ($extra as $key => $value): ?>
                <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
            <input type="hidden" name="site" value="<?= (int) $siteId ?>">
            <input type="hidden" name="range" value="">
            <input type="date" name="start" value="<?= htmlspecialchars($customStart, ENT_QUOTES, 'UTF-8') ?>" required>
            <span class="muted">-</span>
            <input type="date" name="end" value="<?= htmlspecialchars($customEnd, ENT_QUOTES, 'UTF-8') ?>" required>
            <button type="submit" class="filter-btn <?= $isCustom ? 'active' : '' ?>">自定义</button>
        </form>
    </div>
    <script>
        function applyCustomRange(form) {
            var start = form.querySelector('input[name="start"]').value;
            var end = form.querySelector('input[name="end"]').value;
            if (!start || !end) {
                return false;
            }
            form.querySelector('input[name="range"]').value = 'custom:' + start + ':' + end;
            return true;
        }
    </script>
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

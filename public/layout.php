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
        <style>
            /* ========================================================
               1. 全局字体渲染引擎优化 (核心质感来源)
               ======================================================== */
            body, html, button, input, select, textarea, a {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif !important;
                -webkit-font-smoothing: antialiased !important;
                -moz-osx-font-smoothing: grayscale !important;
                text-rendering: optimizeLegibility !important;
            }

            /* ========================================================
               2. 顶部通栏 & 侧边栏 UI 精调
               ======================================================== */
            header {
                padding: 0 24px 0 0 !important;
                height: 60px !important;
                background: #fff !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                box-shadow: 0 1px 4px rgba(0,21,41,.08) !important;
                position: sticky !important;
                top: 0 !important;
                z-index: 1000 !important;
                border: none !important;
            }
            .header-left { display: flex; align-items: center; gap: 24px; height: 100%; }
            .header-right { display: flex; align-items: center; gap: 24px; }
            
            .brand-link { 
                text-decoration: none; background: #fff; width: 220px; height: 60px;
                display: flex; align-items: center; padding-left: 24px; border-right: 1px solid #f0f0f0;
            }
            .brand { 
                font-size: 20px !important; font-weight: 900 !important; 
                color: #17233d !important; font-style: italic !important; 
                margin: 0 !important; letter-spacing: 0.5px !important;
            }
            .brand span { color: #f9a123; font-size: 14px; margin-left: 2px; font-style: normal; font-weight: 800;}
            
            .site-switcher select {
                padding: 6px 28px 6px 14px !important; border: 1px solid #dcdee2 !important; border-radius: 4px !important;
                background: #f8f8f9 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23808695'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 6px center / 16px !important;
                font-size: 14px !important; font-weight: 500 !important; color: #515a6e !important;
                outline: none !important; appearance: none !important; min-width: 160px; cursor: pointer;
                transition: border 0.2s, box-shadow 0.2s;
            }
            .site-switcher select:hover { border-color: #57a3f3 !important; }
            .site-switcher select:focus { border-color: #2d8cf0 !important; box-shadow: 0 0 0 2px rgba(45,140,240,.2) !important; }

            .top-nav-links { display: flex; gap: 24px; margin-right: 8px; }
            .top-nav-links a { 
                text-decoration: none; color: #515a6e; font-size: 14px; font-weight: 500;
                display: flex; align-items: center; gap: 6px; transition: color 0.2s;
            }
            .top-nav-links a:hover { color: #2d8cf0; }

            /* ========================================================
               3. 侧边栏 (彻底优化卡顿与动画)
               ======================================================== */
            .nav {
                background: #fff !important; border: none !important; border-right: 1px solid #f0f0f0 !important;
                border-radius: 0 !important; box-shadow: none !important; padding: 12px 0 !important;
                position: sticky !important; top: 60px !important; height: calc(100vh - 60px) !important;
                width: 220px !important; overflow-y: auto !important;
                overscroll-behavior: contain; /* 优化滚动性能 */
            }

            /* 美化侧边栏滚动条，告别原生丑陋滚动条 */
            .nav::-webkit-scrollbar { width: 5px; }
            .nav::-webkit-scrollbar-track { background: transparent; }
            .nav::-webkit-scrollbar-thumb { background: #e8eaec; border-radius: 4px; }
            .nav::-webkit-scrollbar-thumb:hover { background: #c5c8ce; }

            .stat-info-box { padding: 0 20px 12px; margin-bottom: 8px; }
            .stat-id-badge {
                display: flex; align-items: center; gap: 8px; background: #f8f8f9; padding: 7px 12px; 
                border-radius: 4px; font-size: 12px; color: #515a6e; font-weight: 600; letter-spacing: 0.3px;
            }
            .stat-dot { width: 6px; height: 6px; background: #19be6b; border-radius: 50%; box-shadow: 0 0 0 2px #e3f9ed; }

            .nav-section { border: none !important; margin: 0 !important; }
            .nav-toggle { 
                background: transparent !important; padding: 12px 20px !important; color: #515a6e !important; 
                font-size: 14px !important; font-weight: 600 !important; letter-spacing: 0.2px !important;
                border: none !important; width: 100% !important; text-align: left !important;
                display: flex !important; justify-content: space-between !important; align-items: center !important;
                cursor: pointer; transition: color 0.25s ease;
            }
            .nav-toggle-left { display: flex; align-items: center; gap: 10px; }
            
            .nav-toggle-left svg { 
                width: 16px; height: 16px; stroke: #808695; fill: none; 
                stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
                transition: stroke 0.25s ease;
            }
            
            .nav-toggle:hover { color: #2d8cf0 !important; }
            .nav-toggle:hover .nav-toggle-left svg { stroke: #2d8cf0 !important; }
            
            /* 箭头旋转动画优化 */
            .nav-toggle-chevron { color: #c5c8ce; font-size: 16px; font-family: consolas, monospace; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
            .nav-section.open .nav-toggle-chevron { transform: rotate(90deg); }

            /* 核心：修复菜单卡顿，引入丝滑的 CSS Grid 高度动画 */
            .nav-links { 
                display: grid !important; 
                grid-template-rows: 0fr; 
                transition: grid-template-rows 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                padding: 0 !important;
            }
            .nav-section.open .nav-links { 
                grid-template-rows: 1fr; 
            }
            .nav-links-inner { 
                overflow: hidden !important; 
            }
            /* 使用伪元素制造留白，避免直接 padding 导致的动画跳跃 */
            .nav-links-inner::before { content: ''; display: block; height: 2px; }
            .nav-links-inner::after { content: ''; display: block; height: 6px; }

            .nav a { 
                padding: 10px 20px 10px 46px !important; margin: 0 !important; border-radius: 0 !important;
                color: #515a6e !important; font-size: 14px !important; font-weight: 500 !important;
                border: none !important; display: block !important; text-decoration: none !important;
                /* 修复卡顿：彻底干掉 transition: all，只过渡背景和颜色 */
                transition: background-color 0.2s ease-out, color 0.2s ease-out !important;
                position: relative;
            }
            .nav a:hover { color: #2d8cf0 !important; background: #f8f8f9 !important; }
            
            .nav a.active { 
                background: #f0faff !important; color: #2d8cf0 !important; font-weight: 600 !important; box-shadow: none !important;
            }
            .nav a.active::after {
                content: ''; position: absolute; right: 0; top: 0; bottom: 0; width: 3px; background: #2d8cf0;
            }
            
            .data-layout { display: flex !important; flex-direction: row !important; padding: 0 !important; align-items: stretch !important; }
            .content { flex: 1; padding: 24px !important; min-width: 0 !important; background: #f5f7f9 !important; }

            @media (max-width: 1100px) {
                .data-layout { flex-direction: column !important; }
                .nav { width: 100% !important; height: auto !important; position: static !important; border-right: none !important; }
                .brand-link { width: auto; border-right: none; }
                .header-right .top-nav-links { display: none !important; }
            }
        </style>
    </head>
    <body style="background: #f5f7f9;">
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
        <div class="header-left" id="header-mount">
            <a href="/sites.php" class="brand-link">
                <div class="brand">
                    <?= htmlspecialchars(explode(' ', $branding['brand_title'] ?? '51.LA V6')[0] ?? '51.LA', ENT_QUOTES, 'UTF-8') ?>
                    <span><?= htmlspecialchars(explode(' ', $branding['brand_title'] ?? '51.LA V6')[1] ?? 'V6', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </a>
        </div>

        <div class="header-right">
            <div class="top-nav-links">
                <a href="#"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg> 大数据指数</a>
                <a href="#"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> SEO建议</a>
                <a href="#"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg> API接口</a>
            </div>
            <div class="user-menu" style="padding-right:24px;">
                <div class="user-trigger" style="font-size:14px;color:#515a6e;font-weight:500;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#808695" stroke-width="2" style="vertical-align:-3px;margin-right:4px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                </div>
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
    $icons = [
        'core' => '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
        'refer' => '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
        'visitor' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
        'config' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>'
    ];
    ?>
    <template id="switcher-tpl">
        <?php if (!empty($sites)): ?>
        <div class="site-switcher">
            <select onchange="location.href=this.value;">
                <?php foreach ($sites as $site): ?>
                    <option value="<?= htmlspecialchars($active, ENT_QUOTES, 'UTF-8') ?>.php?site=<?= (int) $site['id'] ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>" <?= ($siteId === (int) $site['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </template>

    <aside class="nav">
        <div class="stat-info-box">
            <div class="stat-id-badge">
                <span class="stat-dot"></span>
                统计 ID: <?= $siteId ? htmlspecialchars((string)$siteId, ENT_QUOTES, 'UTF-8') : '---' ?>
            </div>
        </div>

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
                    ['key' => 'env', 'label' => '系统环境', 'href' => "/env.php?site={$siteId}&range={$range}"],
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
                    $GLOBALS['is_admin'] ? ['key' => 'proxy_block', 'label' => '风控拦截', 'href' => "/proxy_block.php?site={$siteId}&range={$range}"] : null,
                ])),
            ],
        ];

        foreach ($sections as $sectionKey => $section):
            $open = in_array($active, array_column($section['items'], 'key'), true);
            ?>
            <div class="nav-section <?= $open ? 'open' : '' ?>">
                <button class="nav-toggle" type="button">
                    <div class="nav-toggle-left">
                        <?= $icons[$sectionKey] ?? '' ?>
                        <span><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <span class="nav-toggle-chevron">›</span>
                </button>
                <div class="nav-links">
                    <div class="nav-links-inner">
                        <?php foreach ($section['items'] as $item): ?>
                            <a class="<?= $active === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <script>
            (function(){
                var mount = document.getElementById('header-mount');
                var tpl = document.getElementById('switcher-tpl');
                if (mount && tpl) { mount.appendChild(tpl.content.cloneNode(true)); }
                
                document.querySelectorAll('.nav-toggle').forEach(function(btn){
                    btn.onclick = function(){
                        var section = btn.parentElement;
                        section.classList.toggle('open');
                    };
                });
            })();
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
            if (!start || !end) return false;
            form.querySelector('input[name="range"]').value = 'custom:' + start + ':' + end;
            return true;
        }
    </script>
    <?php
}

function render_pagination(int $page, int $totalPages, string $path, array $params = []): void
{
    if ($totalPages <= 1) return;
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
            if ($start > 2) echo '<span class="disabled">...</span>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $renderLink($i, (string) $i, false, $i === $page);
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="disabled">...</span>';
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
?>

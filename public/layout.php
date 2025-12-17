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
        <style>
            :root {
                --primary: #0f172a;
                --muted: #64748b;
                --border: #e2e8f0;
                --bg: #f8fafc;
            }
            body { margin: 0; font-family: 'Inter','PingFang SC',sans-serif; background: var(--bg); color: #0f172a; }
            header { background: #fff; padding: 18px 28px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 5; }
            .brand { font-size: 20px; font-weight: 700; }
            .muted { color: var(--muted); }
            .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04); }
            h2, h3 { margin: 0 0 12px; }
            .form-control { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
            input[type="text"], input[type="password"] { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 14px; }
            button { padding: 10px 14px; border: none; border-radius: 8px; cursor: pointer; background: var(--primary); color: #fff; font-weight: 700; }
            button.ghost { background: #fff; color: #0f172a; border: 1px solid var(--border); }
            .top-bar { display: flex; gap: 10px; align-items: center; }
            .logout { color: #ef4444; text-decoration: none; font-weight: 600; }
            .sites-layout { padding: 22px 24px 32px; display: grid; gap: 16px; }
            .site-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
            .site-card { border: 1px solid var(--border); border-radius: 12px; padding: 14px; background: #fff; position: relative; }
            .site-card .name { font-weight: 700; margin-bottom: 6px; }
            .site-card .meta { color: var(--muted); font-size: 12px; }
            .site-card code { background: #0f172a; color: #e2e8f0; padding: 10px; display: block; border-radius: 8px; margin: 10px 0; font-size: 12px; word-break: break-all; }
            .site-card .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
            .site-card .enter { text-decoration: none; color: #0f172a; font-weight: 700; }
            .data-layout { display: grid; grid-template-columns: 240px 1fr; gap: 16px; padding: 22px 24px 32px; align-items: start; }
            .nav { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04); position: sticky; top: 90px; }
            .nav .site-name { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
            .nav .site-domain { color: var(--muted); font-size: 12px; margin-bottom: 12px; }
            .nav select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 12px; }
            .nav .group-title { margin: 14px 0 8px; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px; }
            .nav a { display: block; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: #0f172a; font-weight: 600; border: 1px solid transparent; }
            .nav a.active { background: #0f172a; color: #fff; border-color: #0f172a; }
            .content { display: grid; gap: 12px; }
            .filters { display: flex; gap: 10px; align-items: center; justify-content: flex-end; }
            .filter-btn { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); background: #fff; cursor: pointer; font-weight: 600; color: #0f172a; text-decoration: none; }
            .filter-btn.active { background: #0f172a; color: #fff; border-color: #0f172a; }
            .metric-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
            .metric { padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
            .metric .value { font-size: 22px; font-weight: 700; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; }
            th { color: var(--muted); font-weight: 600; }
            code.inline { background: #0f172a; color: #e2e8f0; padding: 12px; display: block; border-radius: 8px; word-break: break-all; }
            .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
            .pill { padding: 4px 8px; background: #f1f5f9; border-radius: 999px; color: #0f172a; border: 1px solid var(--border); font-size: 12px; }
            .empty { padding: 24px; text-align: center; color: var(--muted); }
        </style>
    </head>
    <body>
    <?php
}

function render_topbar(array $config): void
{
    ?>
    <header>
        <div>
            <div class="brand">简约白 · 统计后台</div>
            <div class="muted">多站点切换 / www 自动兼容 / 亿级数据索引优化</div>
        </div>
        <div class="top-bar">
            <span class="muted">基址 <?= htmlspecialchars($config['app']['base_url'], ENT_QUOTES, 'UTF-8') ?></span>
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
        <div class="group-title">功能</div>
        <a class="<?= $active === 'overview' ? 'active' : '' ?>" href="/overview.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">总览</a>
        <a class="<?= $active === 'content' ? 'active' : '' ?>" href="/content.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">内容</a>
        <a class="<?= $active === 'keyword' ? 'active' : '' ?>" href="/keyword.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">关键词</a>
        <a class="<?= $active === 'mobile' ? 'active' : '' ?>" href="/mobile.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">移动端</a>
        <a class="<?= $active === 'bot' ? 'active' : '' ?>" href="/bot.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">蜘蛛</a>
        <div class="group-title">访问者信息</div>
        <a class="<?= $active === 'env' ? 'active' : '' ?>" href="/env.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">系统环境概览</a>
        <a class="<?= $active === 'region' ? 'active' : '' ?>" href="/region.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">地域分布</a>
        <a class="<?= $active === 'isp' ? 'active' : '' ?>" href="/isp.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">运营商</a>
        <a class="<?= $active === 'audience' ? 'active' : '' ?>" href="/audience.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">新老访客</a>
        <a class="<?= $active === 'referrer' ? 'active' : '' ?>" href="/referrer.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">来路详情</a>
        <a class="<?= $active === 'pages' ? 'active' : '' ?>" href="/pages.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">受访页</a>
        <a class="<?= $active === 'entry' ? 'active' : '' ?>" href="/entry.php?site=<?= (int) $siteId ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">入口页</a>
        <div class="group-title">返回</div>
        <a href="/sites.php">域名列表</a>
    </aside>
    <?php
}

function render_range_filters(array $allowedRanges, string $range, string $page, int $siteId): void
{
    ?>
    <div class="filters">
        <span class="muted" style="font-size:13px;">时间范围</span>
        <?php foreach ($allowedRanges as $r): ?>
            <a class="filter-btn <?= $range === $r ? 'active' : '' ?>" href="/<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>.php?site=<?= (int) $siteId ?>&range=<?= $r ?>">
                <?= ['today' => '今日', 'yesterday' => '昨日', '7d' => '近7天', '30d' => '近30天'][$r] ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_footer(): void
{
    echo "</body></html>";
}

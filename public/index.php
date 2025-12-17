<?php
session_start();

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis);

$auth = $config['app']['admin'];
$loginError = null;

if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: /index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');
    if ($user === $auth['user'] && $pass === $auth['pass']) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: /index.php');
        exit;
    }
    $loginError = '账号或密码错误';
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <title>统计后台登录</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/modern-normalize/modern-normalize.css">
        <style>
            body { background: #f6f7fb; font-family: 'Inter', 'PingFang SC', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
            .login-box { width: 380px; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12); }
            h1 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
            p { margin: 0 0 24px; color: #475569; }
            label { display: block; margin-bottom: 6px; color: #0f172a; font-weight: 600; }
            input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; }
            button { margin-top: 14px; width: 100%; padding: 12px; border: none; background: #0f172a; color: #fff; border-radius: 8px; font-weight: 700; cursor: pointer; }
            .error { color: #ef4444; margin-bottom: 8px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>统计后台登录</h1>
            <p>请输入管理账号与密码</p>
            <?php if ($loginError): ?><div class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label>账号</label>
                <input type="text" name="user" required placeholder="admin">
                <label style="margin-top:12px;">密码</label>
                <input type="password" name="pass" required placeholder="••••••">
                <button type="submit">登录</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name && $domain) {
            $tracker->createSite($name, $domain);
        } else {
            $error = '请输入网站名称和主域名';
        }
    }

    if ($action === 'delete') {
        $siteIdToDelete = (int) ($_POST['site_id'] ?? 0);
        if ($siteIdToDelete > 0) {
            $tracker->deleteSite($siteIdToDelete);
            if (($_SESSION['current_site'] ?? null) === $siteIdToDelete) {
                unset($_SESSION['current_site']);
            }
        }
    }
}

$sites = $tracker->getSites();
$siteId = null;
if (isset($_GET['site'])) {
    $siteId = (int) $_GET['site'];
    $_SESSION['current_site'] = $siteId;
} elseif (isset($_SESSION['current_site'])) {
    $siteId = (int) $_SESSION['current_site'];
} elseif (count($sites) > 0) {
    $siteId = (int) $sites[0]['id'];
}

$selectedSite = null;
foreach ($sites as $site) {
    if ((int) $site['id'] === $siteId) {
        $selectedSite = $site;
        break;
    }
}
if (!$selectedSite && !empty($sites)) {
    $selectedSite = $sites[0];
    $siteId = (int) $selectedSite['id'];
}

$view = $_GET['view'] ?? 'overview';
$allowedViews = ['overview', 'content', 'keyword', 'bot', 'mobile'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'overview';
}

$range = $_GET['range'] ?? '7d';
$allowedRanges = ['today', 'yesterday', '7d', '30d'];
if (!in_array($range, $allowedRanges, true)) {
    $range = '7d';
}

$data = null;
if ($selectedSite) {
    switch ($view) {
        case 'content':
            $data = $tracker->getContentData($siteId, $range);
            break;
        case 'keyword':
            $data = $tracker->getKeywordData($siteId, $range);
            break;
        case 'bot':
            $data = $tracker->getBotData($siteId, $range);
            break;
        case 'mobile':
            $data = $tracker->getMobileData($siteId, $range);
            break;
        default:
            $data = $tracker->getOverview($siteId, $range);
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>统计后台</title>
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
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 16px; padding: 20px 24px 32px; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04); }
        h2, h3 { margin: 0 0 12px; }
        .form-control { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        input[type="text"] { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 14px; }
        button { padding: 10px 14px; border: none; border-radius: 8px; cursor: pointer; background: var(--primary); color: #fff; font-weight: 700; }
        button.ghost { background: #fff; color: #0f172a; border: 1px solid var(--border); }
        .site-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; max-height: 520px; overflow: auto; }
        .site-item { padding: 10px; border-radius: 10px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #fefefe; }
        .site-item a { color: #0f172a; text-decoration: none; font-weight: 600; }
        .tabs { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
        .tab { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); color: #0f172a; text-decoration: none; font-weight: 600; background: #fff; }
        .tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }
        .filters { display: flex; gap: 10px; align-items: center; justify-content: flex-end; }
        .filter-btn { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); background: #fff; cursor: pointer; font-weight: 600; color: #0f172a; }
        .filter-btn.active { background: #0f172a; color: #fff; border-color: #0f172a; }
        .grid { display: grid; gap: 12px; }
        .metrics { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .metric { padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
        .metric .value { font-size: 22px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; }
        th { color: var(--muted); font-weight: 600; }
        code { background: #0f172a; color: #e2e8f0; padding: 12px; display: block; border-radius: 8px; }
        .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .pill { padding: 4px 8px; background: #f1f5f9; border-radius: 999px; color: #0f172a; border: 1px solid var(--border); font-size: 12px; }
        .top-bar { display: flex; gap: 10px; align-items: center; }
        .logout { color: #ef4444; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
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

<div class="layout">
    <aside class="card">
        <h2>站点管理</h2>
        <?php if ($error): ?><p style="color:#ef4444; margin-top:0;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-control">
                <label>网站名称</label>
                <input type="text" name="name" placeholder="我的网站" required>
            </div>
            <div class="form-control">
                <label>主域名（自动包含 www.）</label>
                <input type="text" name="domain" placeholder="example.com" required>
            </div>
            <button type="submit">添加站点</button>
        </form>

        <h3 style="margin-top:16px;">已添加</h3>
        <?php if (empty($sites)): ?>
            <p class="muted">暂无站点，先创建一个。</p>
        <?php else: ?>
            <ul class="site-list">
                <?php foreach ($sites as $site): ?>
                    <li class="site-item">
                        <div>
                            <a href="?site=<?= (int) $site['id'] ?>&view=<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            <div class="muted" style="font-size:12px;">根域名：<?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?>（含 www）</div>
                            <div class="muted" style="font-size:12px;">Tracking ID: <?= htmlspecialchars($site['tracking_id'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <button type="submit" class="ghost">删除</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>

    <main class="grid" style="gap:16px;">
        <section class="card">
            <div class="section-title">
                <h2>站点埋点</h2>
                <span class="pill">复制脚本至页面 &lt;head&gt; 尾部</span>
            </div>
            <?php if ($selectedSite): ?>
                <p class="muted">根域名和 www. 域名均计入本站点数据。</p>
                <code>&lt;script src="<?= rtrim($config['app']['base_url'], '/') ?>/js/tracker.js" data-site="<?= htmlspecialchars($selectedSite['tracking_id'], ENT_QUOTES, 'UTF-8') ?>"&gt;&lt;/script&gt;</code>
            <?php else: ?>
                <p class="muted">添加站点后自动生成跟踪代码。</p>
            <?php endif; ?>
        </section>

        <?php if ($selectedSite && $data): ?>
        <section class="card">
            <div class="tabs">
                <a class="tab <?= $view === 'overview' ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=overview&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">总览</a>
                <a class="tab <?= $view === 'content' ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=content&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">内容</a>
                <a class="tab <?= $view === 'keyword' ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=keyword&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">关键词</a>
                <a class="tab <?= $view === 'mobile' ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=mobile&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">移动端</a>
                <a class="tab <?= $view === 'bot' ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=bot&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">蜘蛛</a>
            </div>
            <h2 style="margin-top:0;"><?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="muted" style="margin-top:-4px;">域名 <?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?>（www 自动合并）</p>
            <div class="filters">
                <span class="muted" style="font-size:13px;">时间范围</span>
                <?php foreach ($allowedRanges as $r): ?>
                    <a class="filter-btn <?= $range === $r ? 'active' : '' ?>" href="?site=<?= (int) $selectedSite['id'] ?>&view=<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>&range=<?= $r ?>">
                        <?= ['today' => '今日', 'yesterday' => '昨日', '7d' => '近7天', '30d' => '近30天'][$r] ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($view === 'overview'): ?>
            <section class="grid metrics card" style="grid-template-columns: repeat(auto-fit, minmax(170px,1fr));">
                <div class="metric"><div class="muted">PV</div><div class="value"><?= $data['totals']['views'] ?></div></div>
                <div class="metric"><div class="muted">UV</div><div class="value"><?= $data['totals']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">IP</div><div class="value"><?= $data['totals']['ip_count'] ?></div></div>
                <div class="metric"><div class="muted">平均访问时长</div><div class="value"><?= $data['totals']['averages']['duration'] ?>s</div></div>
                <div class="metric"><div class="muted">平均访问页数</div><div class="value"><?= $data['totals']['averages']['pages'] ?></div></div>
                <div class="metric"><div class="muted">跳出率</div><div class="value"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div></div>
                <div class="metric"><div class="muted">预计今日 PV</div><div class="value"><?= $data['predictions']['views'] ?></div></div>
                <div class="metric"><div class="muted">预计今日 UV</div><div class="value"><?= $data['predictions']['uniques'] ?></div></div>
                <div class="metric"><div class="muted">预计今日 IP</div><div class="value"><?= $data['predictions']['ips'] ?></div></div>
            </section>

            <section class="card">
                <div class="section-title"><h3>趋势（按所选范围）</h3><span class="muted">PV / UV / IP</span></div>
                <table>
                    <thead><tr><th>日期</th><th>PV</th><th>UV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['daily'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['day'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['uniques'] ?></td>
                            <td><?= (int) $row['ip_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>小时分布</h3><span class="muted">按选择的日期范围聚合</span></div>
                <table>
                    <thead><tr><th>小时</th><th>PV</th><th>UV</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['hourly'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['hour'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['uniques'] ?></td>
                            <td><?= (int) $row['ips'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'content'): ?>
            <section class="card">
                <div class="section-title"><h3>热门页面（TOP 50）</h3><span class="muted">按 PV</span></div>
                <table>
                    <thead><tr><th>页面路径</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['top_pages'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>来源网站（TOP 50）</h3><span class="muted">Referrer</span></div>
                <table>
                    <thead><tr><th>来源</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['top_referrers'] as $row): ?>
                        <tr><td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="section-title"><h3>实时访问</h3><span class="muted">最新 20 条</span></div>
                <table>
                    <thead><tr><th>页面</th><th>来源</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['recent'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'keyword'): ?>
            <section class="card">
                <div class="section-title"><h3>关键词</h3><span class="muted">根据来路提取</span></div>
                <table>
                    <thead><tr><th>关键词</th><th>PV</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['keywords'])): ?>
                        <tr><td colspan="2" class="muted">暂无关键词数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['keywords'] as $row): ?>
                            <tr><td><?= htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['views'] ?></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'mobile'): ?>
            <section class="card">
                <div class="section-title"><h3>移动端数据</h3><span class="muted">按域名合并（含 www.）</span></div>
                <table>
                    <thead><tr><th>受访域名</th><th>PV</th><th>IP</th><th>移动PV</th><th>移动IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['breakdown'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                            <td><?= (int) $row['ips'] ?></td>
                            <td><?= (int) $row['mobile_views'] ?></td>
                            <td><?= (int) $row['mobile_ips'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php elseif ($view === 'bot'): ?>
            <section class="card">
                <div class="section-title"><h3>蜘蛛流量</h3><span class="muted">单独展示，不入核心数据</span></div>
                <table>
                    <thead><tr><th>页面</th><th>UA</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php if (empty($data['bot'])): ?>
                        <tr><td colspan="3" class="muted">暂无蜘蛛抓取</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['bot'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="muted" style="max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

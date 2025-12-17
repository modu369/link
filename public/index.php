<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name && $domain) {
            $tracker->createSite($name, $domain);
        } else {
            $error = '请输入网站名称和域名';
        }
    }

    if ($action === 'delete') {
        $siteIdToDelete = (int) ($_POST['site_id'] ?? 0);
        if ($siteIdToDelete > 0) {
            $tracker->deleteSite($siteIdToDelete);
        }
    }
}

$sites = $tracker->getSites();
$siteId = isset($_GET['site']) ? (int) $_GET['site'] : ((count($sites) > 0) ? (int) $sites[0]['id'] : null);
$data = $siteId ? $tracker->getDashboardData($siteId) : null;
$selectedSite = null;
foreach ($sites as $site) {
    if ((int) $site['id'] === $siteId) {
        $selectedSite = $site;
        break;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>51LA 风格访客统计后台</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/modern-normalize/modern-normalize.css">
    <style>
        :root {
            --primary: #2563eb;
            --card: #0b172a;
            --bg: #0f172a;
            --muted: #94a3b8;
            --accent: #22c55e;
        }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; background: var(--bg); color: #e2e8f0; }
        header { padding: 24px 32px; background: linear-gradient(120deg, #1f2937, #0f172a); display: flex; justify-content: space-between; align-items: center; }
        h1 { margin: 0; font-size: 26px; letter-spacing: 0.3px; }
        .muted { color: var(--muted); }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 16px; padding: 16px 32px 32px; }
        .card { background: var(--card); border: 1px solid #1f2937; border-radius: 12px; padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
        .card h2, .card h3 { margin-top: 0; }
        .form-control { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
        input[type="text"] { padding: 10px; border-radius: 8px; border: 1px solid #1f2937; background: #111827; color: #e2e8f0; }
        button { padding: 10px 14px; border: none; border-radius: 8px; cursor: pointer; background: var(--primary); color: #fff; }
        button.ghost { background: transparent; color: #e2e8f0; border: 1px solid #1f2937; }
        .site-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .site-item { padding: 10px; border-radius: 8px; background: #111827; border: 1px solid #1f2937; display: flex; justify-content: space-between; align-items: center; }
        .site-item a { color: #e5e7eb; text-decoration: none; font-weight: 600; }
        .grid { display: grid; gap: 12px; }
        .grid.metrics { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .metric { padding: 12px; background: #111827; border-radius: 10px; border: 1px solid #1f2937; }
        .metric .value { font-size: 24px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; color: #e2e8f0; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #1f2937; }
        th { text-align: left; color: var(--muted); font-weight: 600; }
        code { background: #111827; padding: 10px; border-radius: 8px; display: block; border: 1px solid #1f2937; color: #a5b4fc; }
        .pill { padding: 4px 8px; background: #111827; border-radius: 999px; border: 1px solid #1f2937; color: #e2e8f0; font-size: 12px; }
        .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .badge { background: var(--accent); color: #052e16; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; }
    </style>
</head>
<body>
<header>
    <div>
        <div class="badge">演示后台</div>
        <h1>51LA v6 风格网站统计</h1>
        <p class="muted">快速添加域名、获取埋点代码、查看 PV/UV/IP、时长、跳出率与蜘蛛流量。</p>
    </div>
    <div class="muted">Base URL: <?= htmlspecialchars($config['app']['base_url'], ENT_QUOTES, 'UTF-8') ?></div>
</header>

<div class="layout">
    <aside class="card">
        <h2>站点管理</h2>
        <?php if ($error): ?>
            <p style="color: #fbbf24;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-control">
                <label>网站名称</label>
                <input type="text" name="name" placeholder="我的博客" required>
            </div>
            <div class="form-control">
                <label>主域名</label>
                <input type="text" name="domain" placeholder="example.com" required>
            </div>
            <button type="submit">添加站点</button>
        </form>

        <h3 style="margin-top: 16px;">已添加</h3>
        <?php if (empty($sites)): ?>
            <p class="muted">还没有站点，先创建一个。</p>
        <?php else: ?>
            <ul class="site-list">
                <?php foreach ($sites as $site): ?>
                    <li class="site-item">
                        <div>
                            <a href="?site=<?= (int) $site['id'] ?>"><?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            <div class="muted" style="font-size: 12px;">域名：<?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="muted" style="font-size: 12px;">Tracking ID: <?= htmlspecialchars($site['tracking_id'], ENT_QUOTES, 'UTF-8') ?></div>
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
                <h2>埋点代码</h2>
                <span class="muted">复制到要统计的站点</span>
            </div>
            <?php if ($selectedSite): ?>
                <p class="muted">将以下脚本放入 &lt;head&gt; 尾部，系统会自动剔除蜘蛛访问。</p>
                <code>&lt;script src="<?= rtrim($config['app']['base_url'], '/') ?>/js/tracker.js" data-site="<?= htmlspecialchars($selectedSite['tracking_id'], ENT_QUOTES, 'UTF-8') ?>"&gt;&lt;/script&gt;</code>
            <?php else: ?>
                <p class="muted">创建站点后自动生成嵌入脚本。</p>
            <?php endif; ?>
        </section>

        <?php if ($selectedSite && $data): ?>
        <section class="card">
            <div class="section-title">
                <h2><?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?> 概览</h2>
                <span class="pill">域名 <?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="grid metrics">
                <div class="metric">
                    <div class="muted">PV</div>
                    <div class="value"><?= $data['totals']['views'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">UV</div>
                    <div class="value"><?= $data['totals']['uniques'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">IP</div>
                    <div class="value"><?= $data['totals']['ip_count'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">平均访问时长</div>
                    <div class="value"><?= $data['totals']['averages']['duration'] ?>s</div>
                </div>
                <div class="metric">
                    <div class="muted">平均访问页数</div>
                    <div class="value"><?= $data['totals']['averages']['pages'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">跳出率</div>
                    <div class="value"><?= round($data['totals']['bounce_rate'] * 100, 1) ?>%</div>
                </div>
                <div class="metric">
                    <div class="muted">预计今日 PV</div>
                    <div class="value"><?= $data['predictions']['views'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">预计今日 UV</div>
                    <div class="value"><?= $data['predictions']['uniques'] ?></div>
                </div>
                <div class="metric">
                    <div class="muted">预计今日 IP</div>
                    <div class="value"><?= $data['predictions']['ips'] ?></div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="section-title">
                <h3>趋势（近 7 天）</h3>
                <span class="muted">PV / UV / IP</span>
            </div>
            <table>
                <thead>
                    <tr><th>日期</th><th>PV</th><th>UV</th><th>IP</th></tr>
                </thead>
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
            <div class="section-title"><h3>热门页面</h3><span class="muted">Top 10</span></div>
            <table>
                <thead><tr><th>页面</th><th>PV</th></tr></thead>
                <tbody>
                <?php foreach ($data['top_pages'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $row['views'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="card">
            <div class="section-title"><h3>来源分析</h3><span class="muted">TOP Referrer</span></div>
            <table>
                <thead><tr><th>来源</th><th>PV</th></tr></thead>
                <tbody>
                <?php foreach ($data['top_referrers'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $row['views'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="card">
            <div class="section-title"><h3>关键词</h3><span class="muted">根据来路提取</span></div>
            <table>
                <thead><tr><th>关键词</th><th>PV</th></tr></thead>
                <tbody>
                <?php if (empty($data['keywords'])): ?>
                    <tr><td colspan="2" class="muted">暂无关键词数据</td></tr>
                <?php else: ?>
                    <?php foreach ($data['keywords'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['views'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
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

        <section class="card">
            <div class="section-title"><h3>蜘蛛流量</h3><span class="muted">与正常数据隔离</span></div>
            <table>
                <thead><tr><th>页面</th><th>UA</th><th>时间</th></tr></thead>
                <tbody>
                <?php if (empty($data['bot'])): ?>
                    <tr><td colspan="3" class="muted">暂无蜘蛛抓取。</td></tr>
                <?php else: ?>
                    <?php foreach ($data['bot'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="muted" style="max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= htmlspecialchars($row['user_agent'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

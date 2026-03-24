<?php
require __DIR__ . '/init.php';

$error = null;
$shareError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name && $domain) {
            $tracker->createSite($name, $domain);
            session_write_close(); 
            header('Location: /sites.php', true, 303);
            exit;
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
            session_write_close(); 
            header('Location: /sites.php', true, 303);
            exit;
        }
    }

    if ($action === 'share_create') {
        $name = trim($_POST['share_name'] ?? '');
        $selectedSites = $_POST['share_sites'] ?? [];
        try {
            $tracker->createSharePage($name ?: '分享页', $selectedSites);
            session_write_close(); 
            header('Location: /sites.php', true, 303);
            exit;
        } catch (Throwable $e) {
            $shareError = $e->getMessage();
        }
    }

    if ($action === 'share_delete') {
        $shareId = (int) ($_POST['share_id'] ?? 0);
        if ($shareId > 0) {
            $tracker->deleteSharePage($shareId);
            session_write_close(); 
            header('Location: /sites.php', true, 303);
            exit;
        }
    }
}

$sites = $tracker->getSites();
$sharePages = $tracker->getSharePages();
$baseUrl = rtrim($branding['base_url'] ?? $config['app']['base_url'] ?? 'http://localhost', '/');

$buildPayload = static function (string $baseUrl, string $trackingId): string {
    return sprintf(
        '<script src="%s/js/?id=%s" async defer></script>',
        $baseUrl,
        $trackingId
    );
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>域名列表 - 统计后台</title>
    <link rel="stylesheet" href="/t_statics/css/modern-normalize.css">
    <style>
        :root {
            --primary: #1690ff;
            --muted: #4a6480;
            --border: #c5dcf5;
            --bg: #deedfb;
        }
        body { margin: 0; font-family: "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", Arial, sans-serif; background: var(--bg); color: #0f172a; }
        header { background: #fff; padding: 18px 28px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 5; box-shadow: 0 8px 24px rgba(37,99,235,0.06); }
        .brand { font-size: 20px; font-weight: 700; }
        .muted { color: var(--muted); }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(22, 144, 255, 0.12); }
        h1, h2, h3 { margin: 0 0 12px; color: #1690ff;display:inline-block;}
        .form-control { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        input[type="text"] { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 14px; }
        button { padding: 10px 14px; border: none; border-radius: 8px; cursor: pointer; background: #deedfb; color: #1690ff; font-weight: 700; box-shadow: 0 10px 24px rgba(22, 144, 255, 0.18); border: 1px solid var(--border); }
        button.ghost { background: #fff; color: #1690ff; border: 1px solid var(--border); }
        .top-bar { display: flex; gap: 10px; align-items: center; }
        .logout { color: #ef4444; text-decoration: none; font-weight: 600; }
        .sites-layout { padding: 22px 24px 32px; display: grid; gap: 16px; }
        .site-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
        .site-card { border: 1px solid var(--border); border-radius: 12px; padding: 14px; background: #fff; position: relative; display: flex; flex-direction: column; }
        .site-card .name { font-weight: 700; margin-bottom: 6px; }
        .site-card .meta { color: var(--muted); font-size: 12px; }
        .site-card code { background: #0f172a; color: #e2e8f0; padding: 10px; display: block; border-radius: 8px; margin: 10px 0; font-size: 12px; word-break: break-all; }
        .site-card .actions { display: flex; justify-content: space-between; align-items: center; margin-top: auto; }
        .site-card .enter { text-decoration: none; color: #1690ff; font-weight: 700; }
        .filter-btn { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); background: #deedfb; cursor: pointer; font-weight: 600; color: #1690ff; text-decoration: none; }
        .pill { padding: 4px 8px; background: #f1f5f9; border-radius: 999px; color: #0f172a; border: 1px solid var(--border); font-size: 12px; }
        @media (min-width: 1200px) {
            .site-card { grid-column: span 2; }
        }
    </style>
</head>
<body>
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
<div class="sites-layout">
    <section class="card">
        <div class="section-title">
            <h2>站点列表</h2>
            <span class="pill">登录后默认进入此页，可快速添加与管理</span>
        </div>
        <?php if ($error): ?><p style="color:#ef4444; margin-top:0;">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="create">
            <div class="form-control" style="margin:0;">
                <label>网站名称</label>
                <input type="text" name="name" placeholder="我的网站" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>主域名（自动包含 www.）</label>
                <input type="text" name="domain" placeholder="example.com" required>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="submit">添加域名</button>
                <?php if (!empty($sites)): ?><a class="filter-btn" href="/overview.php?site=<?= (int) $sites[0]['id'] ?>">进入数据</a><?php endif; ?>
            </div>
        </form>
    </section>

    <div class="site-grid">
        <?php if (empty($sites)): ?>
            <div class="card" style="grid-column:1/-1;">
                <p class="muted" style="margin:0;">暂无域名，添加后即可生成跟踪代码并跳转查看数据。</p>
            </div>
        <?php else: ?>
            <?php foreach ($sites as $site): ?>
                <div class="site-card">
                    <div class="name"><?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="meta">根域名：<?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?>（含 www）</div>
                    <div class="meta">Tracking ID：<?= htmlspecialchars($site['tracking_id'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php
                        $embedScript = $buildPayload($baseUrl, $site['tracking_id'] ?? '');
                    ?>
                    <code><?= htmlspecialchars($embedScript, ENT_QUOTES, 'UTF-8') ?></code>
                    <div class="actions">
                        <a class="enter" href="/overview.php?site=<?= (int) $site['id'] ?>">进入数据</a>
                        <form method="post" style="margin:0;" onsubmit="return confirm('您确定要删除该（<?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>）站点吗？此操作不可恢复。');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <button type="submit" class="ghost">删除</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <section class="card">
        <div class="section-title">
            <h2>统计分享列表</h2>
            <span class="pill">生成分享链接，选择要汇总的域名</span>
        </div>
        <?php if ($shareError): ?><p style="color:#ef4444; margin-top:0;"><?= htmlspecialchars($shareError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if (empty($sites)): ?>
            <p class="muted" style="margin:0;">请先添加至少一个域名后再生成分享页。</p>
        <?php else: ?>
            <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
                <input type="hidden" name="action" value="share_create">
                <div class="form-control" style="margin:0;">
                    <label>分享页名称</label>
                    <input type="text" name="share_name" placeholder="我的分享页" />
                </div>
                <div class="form-control" style="margin:0;">
                    <label>选择域名（可多选）</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($sites as $site): ?>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                <input type="checkbox" name="share_sites[]" value="<?= (int) $site['id'] ?>">
                                <?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div><button type="submit">创建分享页</button></div>
            </form>
        <?php endif; ?>

        <div style="margin-top:16px;" class="site-grid">
            <?php if (empty($sharePages)): ?>
                <div class="card" style="grid-column:1/-1;">
                    <p class="muted" style="margin:0;">暂无分享页，创建后可复制链接对外展示数据。</p>
                </div>
            <?php else: ?>
                <?php foreach ($sharePages as $page): ?>
                    <div class="site-card">
                        <div class="name">分享：<?= htmlspecialchars($page['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="meta">包含站点：<?= htmlspecialchars(implode('、', $page['site_names'] ?? []), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="meta">链接：<a href="/share.php?token=<?= htmlspecialchars($page['token'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">点击查看</a></div>
                        <div class="actions">
                            <code style="margin:0;">/share.php?token=<?= htmlspecialchars($page['token'], ENT_QUOTES, 'UTF-8') ?></code>
                            <form method="post" style="margin:0;" onsubmit="return confirm('您确定要删除该（<?= htmlspecialchars($page['name'], ENT_QUOTES, 'UTF-8') ?>）分享页吗？');">
                                <input type="hidden" name="action" value="share_delete">
                                <input type="hidden" name="share_id" value="<?= (int) $page['id'] ?>">
                                <button type="submit" class="ghost">删除</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>

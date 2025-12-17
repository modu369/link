<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name && $domain) {
            $tracker->createSite($name, $domain);
            header('Location: /sites.php');
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
            header('Location: /sites.php');
            exit;
        }
    }
}

$sites = $tracker->getSites();

render_head('域名列表 - 统计后台');
render_topbar($config);
?>
<div class="sites-layout">
    <section class="card">
        <div class="section-title">
            <h2>域名列表</h2>
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
                    <code>&lt;script src="<?= rtrim($config['app']['base_url'], '/') ?>/js/tracker.js" data-site="<?= htmlspecialchars($site['tracking_id'], ENT_QUOTES, 'UTF-8') ?>"&gt;&lt;/script&gt;</code>
                    <div class="actions">
                        <a class="enter" href="/overview.php?site=<?= (int) $site['id'] ?>">进入数据</a>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <button type="submit" class="ghost">删除</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>

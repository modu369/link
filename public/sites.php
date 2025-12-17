<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$error = null;
$shareError = null;

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

    if ($action === 'share_create') {
        $name = trim($_POST['share_name'] ?? '');
        $selectedSites = $_POST['share_sites'] ?? [];
        try {
            $tracker->createSharePage($name ?: '分享页', $selectedSites);
            header('Location: /sites.php');
            exit;
        } catch (Throwable $e) {
            $shareError = $e->getMessage();
        }
    }

    if ($action === 'share_delete') {
        $shareId = (int) ($_POST['share_id'] ?? 0);
        if ($shareId > 0) {
            $tracker->deleteSharePage($shareId);
            header('Location: /sites.php');
            exit;
        }
    }
}

$sites = $tracker->getSites();
$sharePages = $tracker->getSharePages();

render_head('域名列表 - 统计后台');
render_topbar($branding);
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
                    <code>&lt;script src="<?= rtrim($branding['base_url'], '/') ?>/js/tracker.js" data-site="<?= htmlspecialchars($site['tracking_id'], ENT_QUOTES, 'UTF-8') ?>"&gt;&lt;/script&gt;</code>
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

    <section class="card">
        <div class="section-title">
            <h2>统计分享页</h2>
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
                            <form method="post" style="margin:0;">
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
<?php render_footer(); ?>

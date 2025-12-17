<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_site' && $selectedSite) {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name !== '' && $domain !== '') {
            $tracker->updateSite($siteId, $name, $domain);
            $message = '站点信息已更新';
            $sites = $tracker->getSites();
            foreach ($sites as $site) {
                if ((int) $site['id'] === (int) $siteId) {
                    $selectedSite = $site;
                    break;
                }
            }
        } else {
            $error = '请填写站点名称和域名';
        }
    } elseif ($action === 'add_site') {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name !== '' && $domain !== '') {
            $tracker->createSite($name, $domain);
            $message = '已创建新的站点';
            $sites = $tracker->getSites();
        } else {
            $error = '请填写站点名称和域名';
        }
    } elseif ($action === 'delete_site') {
        $targetId = (int) ($_POST['site_id'] ?? 0);
        if ($targetId > 0) {
            $tracker->deleteSite($targetId);
            if ($targetId === (int) $siteId) {
                header('Location: /sites.php');
                exit;
            }
            $message = '站点已删除';
            $sites = $tracker->getSites();
        }
    }
}

render_head('配置修改 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'config', $range); ?>
    <main class="content">
        <?php if ($message): ?><div class="card" style="border:1px solid #22c55e;color:#16a34a;">✅ <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="card" style="border:1px solid #ef4444;color:#b91c1c;">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="card">
            <div class="section-title">
                <h2 style="margin:0;">配置修改</h2>
                <span class="muted">调整当前站点的名称与根域名</span>
            </div>
            <?php if ($selectedSite): ?>
                <form method="post" style="display:grid;gap:12px;max-width:520px;">
                    <input type="hidden" name="action" value="update_site">
                    <div class="form-control">
                        <label>站点名称</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($selectedSite['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="form-control">
                        <label>根域名（默认包含 www）</label>
                        <input type="text" name="domain" value="<?= htmlspecialchars($selectedSite['domain'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit">保存修改</button>
                        <span class="muted">保存后左上角站点信息与统计逻辑会同步更新</span>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty">暂无可配置的站点，请先添加站点。</div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-title">
                <h3 style="margin:0;">被统计的域名</h3>
                <span class="muted">可快速添加或删除站点</span>
            </div>
            <form method="post" style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:end;">
                <input type="hidden" name="action" value="add_site">
                <div class="form-control">
                    <label>站点名称</label>
                    <input type="text" name="name" placeholder="如：官网" required>
                </div>
                <div class="form-control">
                    <label>根域名</label>
                    <input type="text" name="domain" placeholder="example.com" required>
                </div>
                <div>
                    <button type="submit">添加站点</button>
                </div>
            </form>

            <table style="margin-top:14px;">
                <thead><tr><th>名称</th><th>域名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($sites as $site): ?>
                    <tr>
                        <td><?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="display:flex;gap:8px;align-items:center;">
                            <a class="pill" style="text-decoration:none;" href="/config.php?site=<?= (int) $site['id'] ?>&range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">配置</a>
                            <form method="post" onsubmit="return confirm('确定删除该站点及其所有数据吗？');">
                                <input type="hidden" name="action" value="delete_site">
                                <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                                <button type="submit" class="ghost" style="color:#b91c1c;border-color:#fca5a5;">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
<?php render_footer();

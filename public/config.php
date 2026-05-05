<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$message = '';
$error = '';
$siteDomains = $selectedSite ? $tracker->getSiteDomains($siteId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_site' && $selectedSite) {
        $name = trim($_POST['name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        if ($name !== '' && $domain !== '') {
            $tracker->updateSite($siteId, $name, $domain);
            $message = '站点信息已更新';
            $sites = $tracker->getSites();
            $siteDomains = $tracker->getSiteDomains($siteId);
            foreach ($sites as $site) {
                if ((int) $site['id'] === (int) $siteId) {
                    $selectedSite = $site;
                    break;
                }
            }
        } else {
            $error = '请填写站点名称和域名';
        }
    } elseif ($action === 'add_domain' && $selectedSite) {
        $domain = trim($_POST['domain'] ?? '');
        if ($domain !== '') {
            $tracker->addSiteDomain($siteId, $domain);
            $message = '已添加新的可统计域名';
            $siteDomains = $tracker->getSiteDomains($siteId);
        } else {
            $error = '请填写域名';
        }
    } elseif ($action === 'delete_domain' && $selectedSite) {
        $domainId = (int) ($_POST['domain_id'] ?? 0);
        if ($domainId > 0) {
            $tracker->deleteSiteDomain($siteId, $domainId);
            $message = '域名已删除';
            $siteDomains = $tracker->getSiteDomains($siteId);
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
                <h3 style="margin:0;">当前站点可统计域名</h3>
                <span class="muted">可添加根域名、子域名或其他域名用于此站点统计</span>
            </div>
            <?php if ($selectedSite): ?>
                <form method="post" style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));align-items:end;">
                    <input type="hidden" name="action" value="add_domain">
                    <div class="form-control">
                        <label>域名</label>
                        <input type="text" name="domain" placeholder="例如：blog.example.com" required>
                    </div>
                    <div>
                        <button type="submit">添加域名</button>
                    </div>
                </form>

                <table style="margin-top:14px;">
                    <thead><tr><th>域名</th><th style="width:120px;">操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($siteDomains as $domain): ?>
                        <tr>
                            <td><?= htmlspecialchars($domain['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('确定删除域名（ <?= htmlspecialchars($domain['domain'], ENT_QUOTES, 'UTF-8') ?>） 吗？');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_domain">
                                    <input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>">
                                    <button type="submit" class="ghost" style="color:#b91c1c;border-color:#fca5a5;">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">请选择站点后管理域名。</div>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php render_footer();

<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

if (!$GLOBALS['is_admin']) { header('Location: /sites.php'); exit; }

$targetUid = (int)($_GET['uid'] ?? 0);
$stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
$stmt->execute([$targetUid]);
$targetUser = $stmt->fetch();
if (!$targetUser) die('用户不存在');

// 获取该用户下的站点
$userSites = $tracker->getSites($targetUid);

render_head('用户站点查看 - ' . htmlspecialchars($targetUser['username']));
render_topbar($branding);
?>
<div class="sites-layout">
    <div style="margin-bottom: 15px;"><a href="/admin_users.php" class="filter-btn">← 返回用户管理</a></div>
    <section class="card">
        <div class="section-title">
            <h2>用户 [<?= htmlspecialchars($targetUser['username']) ?>] 的站点列表</h2>
        </div>
        <div class="site-grid">
            <?php if (empty($userSites)): ?>
                <p class="muted">该用户暂无站点。</p>
            <?php else: foreach ($userSites as $site): ?>
                <div class="site-card">
                    <div class="name"><?= htmlspecialchars($site['name']) ?></div>
                    <div class="meta">根域名：<?= htmlspecialchars($site['domain']) ?></div>
                    <div class="actions">
                        <a class="enter" href="/overview.php?site=<?= (int)$site['id'] ?>">查看该站点统计数据</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>
</div>
<?php render_footer(); ?>

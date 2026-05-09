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

// 建立站点 ID => 名称的映射表，用于分享列表显示
$siteNameMap = [];
foreach ($userSites as $s) {
    $siteNameMap[$s['id']] = $s['name'];
}

// 获取该用户下的分享列表
$stmtShare = $db->prepare('SELECT id, name, token, site_ids, created_at FROM share_pages WHERE user_id = ? ORDER BY id DESC');
$stmtShare->execute([$targetUid]);
$sharePages = $stmtShare->fetchAll(PDO::FETCH_ASSOC);

render_head('用户详情查看 - ' . htmlspecialchars($targetUser['username']));
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
                        <a class="enter" href="/overview.php?site=<?= (int)$site['id'] ?>">查看统计数据</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>

    <section class="card" style="margin-top:20px;">
        <div class="section-title">
            <h2>用户 [<?= htmlspecialchars($targetUser['username']) ?>] 的分享列表</h2>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>分享名称</th>
                        <th>包含站点</th> <th>访问链接</th>
                        <th>创建时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sharePages)): ?>
                        <tr><td colspan="4" class="empty">该用户暂无分享页。</td></tr>
                    <?php else: foreach ($sharePages as $share): ?>
                        <tr>
                            <td><?= htmlspecialchars($share['name']) ?></td>
                            <td>
<?php 
                                // 1. 先去除可能存在的 JSON 括号和引号
                                $clean_ids_str = str_replace(['[', ']', '"', "'"], '', $share['site_ids']);
                                
                                // 2. 再按逗号分割
                                $ids = array_filter(explode(',', $clean_ids_str));
                                
                                // 3. 映射为站点名称
                                $names = array_map(function($id) use ($siteNameMap) {
                                    $trimId = trim($id);
                                    // 如果依然是"未知"，说明该站点可能已被单独删除，但在分享列表中依然残留着ID
                                    return htmlspecialchars($siteNameMap[$trimId] ?? "未知($trimId)");
                                }, $ids);
                                
                                echo implode(', ', $names);
                                ?>
                            </td>
                            <td>
                                <a href="/share.php?token=<?= htmlspecialchars($share['token']) ?>" target="_blank" class="enter" style="padding:4px 8px; font-size:12px;">查看</a>
                            </td>
                            <td><?= htmlspecialchars($share['created_at']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_footer(); ?>

<?php
// public/admin_users.php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// 权限检查：仅限管理员
if (!$GLOBALS['is_admin']) {
    header('Location: /sites.php');
    exit;
}

$message = null;
$error = null;

// 处理添加/删除用户逻辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = '用户名和密码不能为空';
        } else {
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = '用户名已存在';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                $stmt->execute([$username, $hash]);
                $message = "用户 {$username} 创建成功";
            }
        }
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            // 1. 获取该用户所有的站点 ID，用于清理配置类关联数据
            $stmt = $db->prepare('SELECT id FROM sites WHERE user_id = ?');
            $stmt->execute([$uid]);
            $siteIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($siteIds)) {
                $inQuery = implode(',', array_fill(0, count($siteIds), '?'));
                
                // 删除站点的域名配置
                $db->prepare("DELETE FROM site_domains WHERE site_id IN ($inQuery)")->execute($siteIds);
                
                // 删除站点的屏蔽域名配置
                $db->prepare("DELETE FROM site_blocked_domains WHERE site_id IN ($inQuery)")->execute($siteIds);
            }

            // 2. 删除用户的分享页列表
            $db->prepare('DELETE FROM share_pages WHERE user_id = ?')->execute([$uid]);
            
            // 3. 删除用户的所有站点记录
            $db->prepare('DELETE FROM sites WHERE user_id = ?')->execute([$uid]);
            
            // 4. 最后删除用户本身
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            
            $message = "用户及其关联的站点、分享、域名配置已成功删除。原始统计数据将由系统自动清理。";
        }
    }
}

// 使用子查询统计每个用户的站点数量
$users = $db->query('
    SELECT u.id, u.username, u.nickname, u.created_at, 
           (SELECT COUNT(*) FROM sites WHERE user_id = u.id) as site_count 
    FROM users u 
    ORDER BY u.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

render_head('用户管理 - 统计后台');
render_topbar($branding);
?>
<div class="sites-layout">
    <section class="card">
        <div class="section-title">
            <h2>创建普通用户</h2>
        </div>
        <?php if ($message): ?><p style="color:green;">✅ <?= htmlspecialchars($message) ?></p><?php endif; ?>
        <?php if ($error): ?><p style="color:red;">⚠️ <?= htmlspecialchars($error) ?></p><?php endif; ?>
        
        <form method="post" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_user">
            <div class="form-control" style="margin:0;">
                <label>用户名</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>初始密码</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">添加用户</button>
        </form>
    </section>

    <section class="card" style="margin-top:20px;">
        <div class="section-title">
            <h2>用户列表</h2>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>昵称</th>
                        <th>站点数</th>
                        <th>创建时间</th>
                        <th style="text-align:right;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="empty">暂无普通用户</td></tr>
                    <?php else: foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['nickname'] ?? '-') ?></td>
                            <td><span class="pill"><?= (int)$u['site_count'] ?></span></td>
                            <td><?= $u['created_at'] ?></td>
                            <td style="text-align:right; display:flex; gap:8px; justify-content:flex-end;">
                                <a href="/admin_view_user_sites.php?uid=<?= $u['id'] ?>" class="filter-btn" style="padding:4px 10px; font-size:12px; text-decoration:none;">查看详情</a>
                                
                                <form method="post" style="margin:0;" onsubmit="return confirm('确定要彻底删除该用户及其关联的站点和分享页吗？');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                    <button type="submit" class="ghost" style="padding:4px 8px; font-size:12px; color:#ef4444; border-color:#fca5a5;">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_footer(); ?>

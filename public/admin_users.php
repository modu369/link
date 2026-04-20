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

// 处理添加用户逻辑
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
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $message = "用户已删除";
        }
    }
}

// 获取用户列表
$users = $db->query('SELECT id, username, nickname, created_at FROM users ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

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
                        <th>创建时间</th>
                        <th style="text-align:right;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" class="empty">暂无普通用户</td></tr>
                    <?php else: foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['nickname'] ?? '-') ?></td>
                            <td><?= $u['created_at'] ?></td>
                            <td style="text-align:right;">
                                <form method="post" style="margin:0;" onsubmit="return confirm('确定删除该用户吗？');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                    <button type="submit" class="ghost" style="padding:4px 8px;">删除</button>
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

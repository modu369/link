<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// 鉴权检查
if (($_SESSION['role'] ?? '') !== 'user') {
    die('无权限访问');
}

$userId = $_SESSION['user_id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $nickname = trim($_POST['nickname'] ?? '');
    
    if (!empty($newPassword)) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = :hash, nickname = :nickname WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':nickname' => $nickname, ':id' => $userId]);
        $msg = '资料及密码更新成功';
    } else {
        $stmt = $db->prepare('UPDATE users SET nickname = :nickname WHERE id = :id');
        $stmt->execute([':nickname' => $nickname, ':id' => $userId]);
        $_SESSION['nickname'] = $nickname;
        $msg = '资料更新成功';
    }
}

$stmt = $db->prepare('SELECT username, nickname FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

render_head('个人设置');
render_topbar($branding); // 根据系统现有的 layout 结构调整
?>
<div class="data-layout">
    <main class="content" style="padding: 20px;">
        <h2>个人设置</h2>
        <?php if ($msg): ?><div style="color: green; margin-bottom: 15px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        
        <form method="post" style="max-width: 400px;">
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px;">用户名 (不可修改)</label>
                <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled style="width:100%; padding:8px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px;">昵称</label>
                <input type="text" name="nickname" value="<?= htmlspecialchars($user['nickname'] ?? '') ?>" style="width:100%; padding:8px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px;">新密码 (留空则不修改)</label>
                <input type="password" name="new_password" style="width:100%; padding:8px;">
            </div>
            <button type="submit" style="padding: 10px 20px; background: #1690ff; color: #fff; border: none; border-radius: 4px;">保存修改</button>
        </form>
    </main>
</div>
<?php render_footer(); ?>

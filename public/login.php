<?php
$lifetime = 604800; 
session_set_cookie_params($lifetime);
ini_set('session.gc_maxlifetime', $lifetime);
// public/login.php
session_start();
$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../src/Database.php';

$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    try {
        $db = Database::connection($config['db']);
// 加入 nickname 字段
        $stmt = $db->prepare('SELECT id, username, nickname, password_hash FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // 登录成功，写入 Session
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nickname'] = $user['nickname']; // <--- 新增：保存昵称到 Session
            $_SESSION['role'] = 'user';
            
            session_write_close();
            header('Location: /sites.php', true, 303);
            exit;
        } else {
            $loginError = '账号或密码错误';
        }
    } catch (Throwable $e) {
        $loginError = '系统异常，请稍后再试';
    }
}

// 如果已登录，直接跳转
if ($_SESSION['user_logged_in'] ?? false) {
    header('Location: /sites.php', true, 303);
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>用户登录 - 统计系统</title>
    <link rel="stylesheet" href="/t_statics/css/modern-normalize.css">
    <style>
        body { background: #f6f7fb; font-family: 'Inter', 'PingFang SC', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin:0; }
        .login-box { width: 380px; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12); }
        h1 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
        label { display: block; margin-bottom: 6px; color: #0f172a; font-weight: 600; }
        input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; }
        button { margin-top: 14px; width: 100%; padding: 12px; border: none; background: #1690ff; color: #fff; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .error { color: #ef4444; margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="login-box">
    <h1>用户登录</h1>
    <?php if ($loginError): ?><div class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <label>用户名</label>
        <input type="text" name="username" required>
        <label style="margin-top:12px;">密码</label>
        <input type="password" name="password" required>
        <button type="submit">登录</button>
    </form>
</div>
</body>
</html>

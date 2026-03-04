<?php
$candidate = trim($_GET['entry'] ?? '');
if ($candidate === '') {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><h1 style="text-align:center;font-family:Helvetica Neue, Helvetica, PingFang SC, Hiragino Sans GB, Microsoft YaHei, 微软雅黑, Arial, sans-serif;">403 Forbidden</h1></body></html>';
    exit;
}

$config = require __DIR__ . '/../config/config.php';
session_start();

require __DIR__ . '/../src/Database.php';

$loginError = null;
$entryVerified = false;
$loginEntry = trim($config['security']['login_entry'] ?? 'admin') ?: 'admin';
$adminSetting = null;
$db = null;

$loadSetting = function (PDO $db, string $key): ?array {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!$value) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
};

try {
    $db = Database::connection($config['db']);
    $loginSetting = $loadSetting($db, 'login_entry');
    if ($loginSetting && isset($loginSetting['entry'])) {
        $storedEntry = trim((string) $loginSetting['entry']);
        if ($storedEntry !== '') {
            $loginEntry = $storedEntry;
        }
    }
    $adminSetting = $loadSetting($db, 'admin');
} catch (Throwable $e) {
    $loginError = '服务暂不可用，请稍后再试';
}

if ($candidate !== '' && hash_equals($loginEntry, $candidate)) {
    $entryVerified = true;
}

if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: /index.php?entry=' . urlencode($loginEntry));
    exit;
}

if (!$entryVerified) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><h1 style="text-align:center;font-family:Helvetica Neue, Helvetica, PingFang SC, Hiragino Sans GB, Microsoft YaHei, 微软雅黑, Arial, sans-serif;">403 Forbidden</h1></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');
    $fallbackUser = $config['app']['admin']['user'] ?? 'admin';
    $fallbackPass = $config['app']['admin']['pass'] ?? 'admin123';
    $storedUser = $adminSetting['user'] ?? $fallbackUser;
    $storedHash = $adminSetting['pass_hash'] ?? null;

    $verified = false;
    if ($storedHash) {
        $verified = ($user === $storedUser) && password_verify($pass, $storedHash);
    } else {
        $verified = ($user === $fallbackUser && $pass === $fallbackPass);
    }

    if ($verified) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $storedUser;
        header('Location: /sites.php');
        exit;
    }
    $loginError = '账号或密码错误';
}

if ($_SESSION['admin_logged_in'] ?? false) {
    header('Location: /sites.php');
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>统计后台登录</title>
    <link rel="stylesheet" href="t_static/css/modern-normalize.css">
    <style>
        body { background: #f6f7fb; font-family: 'Inter', 'PingFang SC', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin:0; }
        .login-box { width: 380px; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12); }
        h1 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
        p { margin: 0 0 24px; color: #475569; }
        label { display: block; margin-bottom: 6px; color: #0f172a; font-weight: 600; }
        input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; }
        button { margin-top: 14px; width: 100%; padding: 12px; border: none; background: #1690ff; color: #fff; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .error { color: #ef4444; margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="login-box">
    <h1>统计后台登录</h1>
    <p>请输入管理账号与密码</p>
    <?php if ($loginError): ?><div class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <label>账号</label>
        <input type="text" name="user" required placeholder="admin">
        <label style="margin-top:12px;">密码</label>
        <input type="password" name="pass" required placeholder="••••••">
        <button type="submit">登录</button>
    </form>
</div>
</body>
</html>

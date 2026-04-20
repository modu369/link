<?php
$lifetime = 604800; 
session_set_cookie_params($lifetime);
ini_set('session.gc_maxlifetime', $lifetime);
session_start();

$config = require __DIR__ . '/../config/config.php';
$GLOBALS['rollup_only'] = (bool) ($config['rollup_only'] ?? true);

if (($_GET['action'] ?? '') === 'logout') {
    // 【新增】：在销毁 session 前，先记录当前是否为管理员
    $wasAdmin = $_SESSION['admin_logged_in'] ?? false; 

    $entry = $config['security']['login_entry'] ?? 'admin';
    try {
        require_once __DIR__ . '/../src/Database.php';
        $db = Database::connection($config['db']);
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'login_entry' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) {
            $decoded = json_decode($val, true);
            if (isset($decoded['entry']) && trim((string)$decoded['entry']) !== '') {
                $entry = trim((string)$decoded['entry']);
            }
        }
    } catch (Throwable $e) {
    }
    
    session_destroy();
    
    // 【新增】：根据之前的身份决定跳转去哪里
    if ($wasAdmin) {
        $redirectEntry = $entry !== '' ? '?entry=' . urlencode($entry) : '';
        header('Location: /index.php' . $redirectEntry);
    } else {
        // 普通用户直接跳回普通登录页
        header('Location: /login.php');
    }
    exit;
}

// --- 修改后 ---
$isAdmin = $_SESSION['admin_logged_in'] ?? false;
$isUser = $_SESSION['user_logged_in'] ?? false;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (!$isAdmin && !$isUser) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><h1 style="text-align:center;font-family:Helvetica Neue, Helvetica, PingFang SC, Hiragino Sans GB, Microsoft YaHei, 微软雅黑, Arial, sans-serif;">403 Forbidden</h1></body></html>';
    exit;
}

// 注入全局变量供后续查询使用
$GLOBALS['is_admin'] = $isAdmin;
$GLOBALS['is_user'] = $isUser;
$GLOBALS['current_user_id'] = $currentUserId;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);
$brandingFallback = $config['branding'] ?? [
    'base_url' => $config['app']['base_url'] ?? 'http://localhost',
    'brand_title' => 'V6统计后台',
    'brand_subtitle' => '亿级数据索引优化',
];
$branding = $tracker->getBrandingSettings($brandingFallback);

// 1. 先默认获取当前登录者（或管理员自己）的站点，用来给没传 site 参数时做兜底
$sites = $tracker->getSites();
$siteId = null;

if (isset($_GET['site'])) {
    $siteId = (int) $_GET['site'];
    $_SESSION['current_site'] = $siteId;
} elseif (isset($_SESSION['current_site'])) {
    $siteId = (int) $_SESSION['current_site'];
} elseif (!empty($sites)) {
    $siteId = (int) $sites[0]['id'];
}

// 2. 根据获取到的 siteId，查询出当前选中的站点详情
$selectedSite = $siteId ? $tracker->getSite($siteId) : null;

// 3. 【核心修复位置】现在 $selectedSite 已经有值了，再去判断它并更新侧边栏 $sites
// 如果是管理员，且正在查看某个普通用户的站点，则将 $sites 切换为该用户的站点列表
if ($isAdmin && $selectedSite && isset($selectedSite['user_id'])) {
    $sites = $tracker->getSites((int)$selectedSite['user_id']);
}


$allowedRanges = ['today', 'yesterday', 'day_before', '7d'];
$range = $_GET['range'] ?? 'today';
if (!in_array($range, $allowedRanges, true) && !str_starts_with($range, 'custom:')) {
    $range = 'today';
}

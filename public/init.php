<?php
session_start();

$config = require __DIR__ . '/../config/config.php';
$GLOBALS['rollup_only'] = (bool) ($config['rollup_only'] ?? true);

if (($_GET['action'] ?? '') === 'logout') {
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
    $redirectEntry = $entry !== '' ? '?entry=' . urlencode($entry) : '';
    header('Location: /index.php' . $redirectEntry);
    exit;
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><h1 style="text-align:center;font-family:Helvetica Neue, Helvetica, PingFang SC, Hiragino Sans GB, Microsoft YaHei, 微软雅黑, Arial, sans-serif;">403 Forbidden</h1></body></html>';
    exit;
}

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

$selectedSite = null;
foreach ($sites as $site) {
    if ((int) $site['id'] === $siteId) {
        $selectedSite = $site;
        break;
    }
}

$allowedRanges = ['today', 'yesterday', 'day_before', '7d'];
$range = $_GET['range'] ?? 'today';
if (!in_array($range, $allowedRanges, true) && !str_starts_with($range, 'custom:')) {
    $range = 'today';
}

<?php
session_start();

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config['retention'] ?? []);

if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: /index.php');
    exit;
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: /index.php');
    exit;
}

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

$allowedRanges = ['today', 'yesterday', '7d', '30d'];
$range = $_GET['range'] ?? 'today';
if (!in_array($range, $allowedRanges, true)) {
    $range = 'today';
}

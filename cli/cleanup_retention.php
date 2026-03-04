<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';

$options = getopt('', ['days::', 'pageviews-days::', 'batch-size::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php cli/cleanup_retention.php [--days=90] [--pageviews-days=30] [--batch-size=50000]\n";
    echo "If arguments are omitted, configured retention settings are used.\n";
    exit(0);
}

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
    echo "[Maintenance] Started at " . date('Y-m-d H:i:s') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[Maintenance] Failed to initialize tracker: {$e->getMessage()}\n");
    exit(1);
}

// ==========================================
// 1. 离线 IP 库与 ASN 库自动下载更新
// ==========================================
function downloadDatabaseIfNeeded(string $url, string $path, int $refreshHours) {
    if (empty($url) || empty($path)) return;
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    
    $isStale = !is_file($path) || filesize($path) <= 0 || (time() - filemtime($path)) >= ($refreshHours * 3600);
    if ($isStale) {
        echo "[Maintenance] Downloading/Updating DB: {$path}\n";
        $tmp = $path . '.tmp';
        $cmd = str_ends_with($url, '.gz') && str_ends_with($path, '.tsv')
            ? "curl -fsSL " . escapeshellarg($url) . " | gzip -dc > " . escapeshellarg($tmp) . " && mv " . escapeshellarg($tmp) . " " . escapeshellarg($path)
            : "curl -fsSL " . escapeshellarg($url) . " -o " . escapeshellarg($tmp) . " && mv " . escapeshellarg($tmp) . " " . escapeshellarg($path);
        exec($cmd);
    }
}

echo "[Maintenance] Checking IP/ASN database updates...\n";
// 更新 IP 库
$ipdbPath = $config['ipdb']['path'] ?? (__DIR__ . '/../data/qqwry.ipdb');
$ipdbUrl = trim((string) ($config['ipdb']['url'] ?? 'https://raw.githubusercontent.com/nmgliangwei/qqwry.ipdb/main/qqwry.ipdb'));
downloadDatabaseIfNeeded($ipdbUrl, $ipdbPath, max(1, (int) ($config['ipdb']['refresh_hours'] ?? 168)));

// 更新 ASN 库
if (!empty($config['asn']['enabled'])) {
    $asn = $config['asn'];
    $defaultPath = __DIR__ . '/../data/asn.ipdb';
    downloadDatabaseIfNeeded($asn['url'] ?? '', $asn['path'] ?? $defaultPath, $asn['refresh_hours'] ?? 168);
    downloadDatabaseIfNeeded($asn['url_v4'] ?? '', $asn['path_v4'] ?? $defaultPath, $asn['refresh_hours'] ?? 168);
    downloadDatabaseIfNeeded($asn['url_v6'] ?? '', $asn['path_v6'] ?? '', $asn['refresh_hours'] ?? 168);
}

// ==========================================
// 2. 清理过期日志与聚合数据 (保留策略)
// ==========================================
echo "[Maintenance] Cleaning retention data...\n";
$retention = $tracker->getRetentionSettings($config['retention'] ?? []);
$rollupDays = isset($options['days']) ? max(0, (int) $options['days']) : (int) ($retention['days'] ?? 0);
$pageviewsDays = isset($options['pageviews-days'])
    ? max(0, (int) $options['pageviews-days'])
    : (int) ($retention['pageviews_days'] ?? 0);
$batchSize = isset($options['batch-size']) ? max(1000, (int) $options['batch-size']) : null;

if ($rollupDays > 0 || $pageviewsDays > 0) {
    $tracker->manualCleanup($rollupDays, $pageviewsDays, $batchSize);
    echo "[Maintenance] Cleanup completed. Rollups: {$rollupDays} days, Pageviews: {$pageviewsDays} days.\n";
} else {
    echo "[Maintenance] No retention days configured; skipped data cleanup.\n";
}

// ==========================================
// 3. 清理 Blocked Domains (每天清空昨日数据)
// ==========================================
echo "[Maintenance] Cleaning old blocked domains...\n";
try {
    $db->exec("DELETE FROM site_blocked_domains WHERE log_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
} catch (Throwable $e) {
    echo "[Maintenance] Blocked domains cleanup skipped/failed: {$e->getMessage()}\n";
}

echo "[Maintenance] All tasks finished successfully at " . date('Y-m-d H:i:s') . "\n";
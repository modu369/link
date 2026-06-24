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
        
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            echo "[Maintenance] Error: Failed to download {$url}. Curl return code: {$returnCode}\n";
            @unlink($tmp); // 清理可能损坏的临时文件
        } else {
            echo "[Maintenance] Success: Updated {$path}\n";
        }
    }
}

echo "[Maintenance] Checking IP/ASN database updates...\n";
// 更新 IP 库
$ipdbPath = $config['ipdb']['path'] ?? (__DIR__ . '/../data/qqwry.ipdb');
$ipdbUrl = trim((string) ($config['ipdb']['url'] ?? 'https://raw.githubusercontent.com/nmgliangwei/qqwry.ipdb/main/qqwry.ipdb'));
downloadDatabaseIfNeeded($ipdbUrl, $ipdbPath, max(1, (int) ($config['ipdb']['refresh_hours'] ?? 72)));

// 更新 ASN 库
$asnEnabled = (bool) ($config['asn']['enabled'] ?? true); // 修复：默认开启
if ($asnEnabled) {
    $asn = $config['asn'] ?? [];
    $refreshHours = max(1, (int) ($asn['refresh_hours'] ?? 72)); // 默认 3 天
    
    // v4 库 (补充了默认的官方下载地址和路径)
    $pathV4 = $asn['path_v4'] ?? (__DIR__ . '/../data/ip2asn-v4.tsv');
    $urlV4 = $asn['url_v4'] ?? 'https://iptoasn.com/data/ip2asn-v4.tsv.gz';
    downloadDatabaseIfNeeded($urlV4, $pathV4, $refreshHours);

    // v6 库 (补充了默认的官方下载地址和路径)
    $pathV6 = $asn['path_v6'] ?? (__DIR__ . '/../data/ip2asn-v6.tsv');
    $urlV6 = $asn['url_v6'] ?? 'https://iptoasn.com/data/ip2asn-v6.tsv.gz';
    downloadDatabaseIfNeeded($urlV6, $pathV6, $refreshHours);
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
// ==========================================
// 4. 定时批量检测站点域名连通性
// ==========================================
echo "[Domain Check] Starting batch domain check...\n";

// 引入 domain_check.php 并只使用它刚才封装好的检测函数
require_once __DIR__ . '/../public/domain_check.php';

try {
    $batchSize = 200;
    $offset = 0;
    $totalChecked = 0;
    $totalAbnormal = 0;

    while (true) {
        // 取出所有站点绑定的域名
        $domains = $db->query("SELECT d.id, d.domain FROM site_domains d JOIN sites s ON d.site_id = s.id LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($domains)) break;

        foreach ($domains as $row) {
            $domainId = $row['id'];
            $domain = $row['domain'];
            
            // 使用完全一致的外部接口进行检测
            $checkRes = check_domain_health($domain);
            
            // UI逻辑里只要不是 0/3，就说明还有救。这里设 status=1 (正常), 0=异常阻断
            $status = ($checkRes['successCount'] > 0) ? 1 : 0;
            
            // 更新数据库
            $stmt = $db->prepare("UPDATE site_domains SET status = ?, last_check_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $domainId]);
            
            $totalChecked++;
            if ($status === 0) $totalAbnormal++;
        }
        $offset += $batchSize;
        sleep(2); // 限制速度
    }
    echo "[Domain Check] Completed. Checked: {$totalChecked}, Abnormal: {$totalAbnormal}.\n";
} catch (Throwable $e) {
    echo "[Domain Check] Error: {$e->getMessage()}\n";
}

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
// 4. 定时批量检测站点域名连通性 (纯血UDP防丢包重试版)
// ==========================================
echo "[Domain Check] Starting batch domain GFW check...\n";

// 引入网页端的文件，直接使用里面已经封装好的纯血UDP探针
require_once __DIR__ . '/../public/domain_check.php';

try {
    $batchSize = 200;
    $offset = 0;
    $totalChecked = 0;
    $totalAbnormal = 0;

    $siteResultsBuffer = []; 

    while (true) {
        $domains = $db->query("SELECT d.id, d.site_id, d.domain FROM site_domains d JOIN sites s ON d.site_id = s.id LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($domains)) break;

        foreach ($domains as $row) {
            $domainId = $row['id'];
            $siteId = $row['site_id'];
            $domain = $row['domain'];
            
            // 调用重构后的最精准的方法
            $res = check_mainland_accessibility($domain);
            
            // 组装用于刷新网页端缓存的数据包
            $siteResultsBuffer[$siteId][$domain] = [
                'status' => $res['status'],
                'ip'     => $res['ip'],
                'msg'    => $res['msg']
            ];

            // 入库状态映射: clean 为 1(正常)，其他 (污染/阻断) 均为 0
            $dbStatus = ($res['status'] === 'clean') ? 1 : 0;
            
            $stmt = $db->prepare("UPDATE site_domains SET status = ?, last_check_at = NOW() WHERE id = ?");
            $stmt->execute([$dbStatus, $domainId]);
            
            $totalChecked++;
            if ($dbStatus === 0) $totalAbnormal++;
        }
        $offset += $batchSize;
        sleep(1);
    }

    // 自动刷新网页端缓存，用户打开后台立刻就能看到定时任务检测出的最新“大陆区IP”和“诊断结果”
    if (!empty($siteResultsBuffer)) {
        foreach ($siteResultsBuffer as $sId => $domResults) {
            $resultKey = "domain_check_results:{$sId}";
            $redis->setex($resultKey, 86400, json_encode($domResults, JSON_UNESCAPED_UNICODE));
        }
        echo "[Domain Check] Redis cache synchronized successfully.\n";
    }

    echo "[Domain Check] Completed. Checked: {$totalChecked}, Abnormal: {$totalAbnormal}.\n";
} catch (Throwable $e) {
    echo "[Domain Check] Error: {$e->getMessage()}\n";
}

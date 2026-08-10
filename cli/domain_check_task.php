<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';

$config = require __DIR__ . '/../config/config.php';

try {
    // 域名检测不需要 Tracker，只需连接 DB 和 Redis 即可
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    echo "[Domain Check] Started at " . date('Y-m-d H:i:s') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[Domain Check] Failed to initialize connections: {$e->getMessage()}\n");
    exit(1);
}

// ==========================================
// 定时批量检测站点域名连通性 (纯血UDP防丢包重试版)
// ==========================================
echo "[Domain Check] Starting batch domain GFW check...\n";

// 引入网页端的文件，直接使用里面已经封装好的纯血UDP探针
require_once __DIR__ . '/../public/domain_check.php';

// === 【新增】配置不需要检测的域名白名单 ===
$skipDomains = [
    '123.cc'
];

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
            
            // === 【新增】判断域名是否在不检测的白名单中 ===
            // 提示：如果你希望涵盖所有子域名(如 www.fby8.cc)，可以将判断改为 strpos($domain, 'fby8.cc') !== false
            if (in_array($domain, $skipDomains)) {
                continue; // 直接跳过，不进行探测和数据库状态更新
            }
            
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

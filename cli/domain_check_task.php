<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';

$config = require __DIR__ . '/../config/config.php';

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    echo "[Domain Check] Started at " . date('Y-m-d H:i:s') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[Domain Check] Failed to initialize connections: {$e->getMessage()}\n");
    exit(1);
}

// 引入网页端的文件，直接使用里面已经封装好的纯血UDP探针
require_once __DIR__ . '/../public/domain_check.php';

// === 配置不需要检测的域名白名单 ===
$skipDomains = [
    'fby8.cc'
];

try {
    echo "[Domain Check] Fetching domains priority queue...\n";
    
    // 【核心改动】：直接查出所有域名，并按照 last_check_at 升序排列。
    // COALESCE 确保从未检测过 (NULL) 的域名排在最绝对的最前面，优先分配资源。
    $sql = "SELECT d.id, d.site_id, d.domain 
            FROM site_domains d 
            JOIN sites s ON d.site_id = s.id 
            ORDER BY COALESCE(d.last_check_at, '1970-01-01') ASC";
            
    $domains = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    $totalChecked = 0;
    $totalAbnormal = 0;
    $siteResultsBuffer = []; 

    foreach ($domains as $row) {
        $domainId = $row['id'];
        $siteId = $row['site_id'];
        $domain = $row['domain'];
        
        // 白名单跳过
        if (in_array($domain, $skipDomains)) {
            continue; 
        }
        
        // 调用重构后的最精准的方法
        $res = check_mainland_accessibility($domain);
        
        $siteResultsBuffer[$siteId][$domain] = [
            'status' => $res['status'],
            'ip'     => $res['ip'],
            'msg'    => $res['msg']
        ];

        $dbStatus = ($res['status'] === 'clean') ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE site_domains SET status = ?, last_check_at = NOW() WHERE id = ?");
        $stmt->execute([$dbStatus, $domainId]);
        
        $totalChecked++;
        if ($dbStatus === 0) $totalAbnormal++;
        
        // 适当休眠，避免 UDP 和 API 并发过高
        usleep(500000); // 0.5秒
    }

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

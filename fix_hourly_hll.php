<?php
// 强制统一下时区，防止查询偏差
date_default_timezone_set('Asia/Shanghai');
set_time_limit(0);
ini_set('memory_limit', '1024M');

$dbHost = '127.0.0.1';
$dbName = 'your_database_name'; // 请替换
$dbUser = 'your_username';      // 请替换
$dbPass = 'your_password';      // 请替换

try {
    $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

$redis = new Redis();
try {
    $redis->connect('127.0.0.1', 6379);
    // ==========================================
    // 核心修复：强制加上 tracker: 前缀，与系统对齐！
    // ==========================================
    $redis->setOption(Redis::OPT_PREFIX, 'tracker:');
} catch (Exception $e) {
    die("Redis 连接失败: " . $e->getMessage() . "\n");
}

$stmt = $db->query("SELECT id FROM sites");
$sites = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt->closeCursor();

if (empty($sites)) die("没有找到任何站点。\n");

$now = new DateTimeImmutable('now');
$yesterday = $now->modify('-1 day')->setTime(0, 0, 0);
$currentHour = $now->setTime((int)$now->format('H'), 0, 0);

$hoursToProcess = [];
$iter = clone $yesterday;
while ($iter <= $currentHour) {
    $hoursToProcess[] = $iter;
    $iter = $iter->modify('+1 hour');
}

echo "开始写入带前缀的精确 HLL 数据...\n";

foreach ($sites as $siteId) {
    $siteId = (int)$siteId;
    echo "\n>>> 正在处理站点 Site ID: {$siteId} <<<\n";

    foreach ($hoursToProcess as $hourDt) {
        $hourStr = $hourDt->format('YmdH');
        $startTime = $hourDt->format('Y-m-d H:i:s');
        $endTime = $hourDt->modify('+1 hour')->format('Y-m-d H:i:s');

        // 这里不用写 tracker:，因为上面已经 setOption 自动加了
        $keyIp = "site:{$siteId}:hll_ip:{$hourStr}";
        $keyMobileIp = "site:{$siteId}:hll_ip_mobile:{$hourStr}";
        $redis->del($keyIp, $keyMobileIp);

        $sql = "SELECT ip_hash, is_mobile FROM pageviews 
                WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND is_proxy_risk = 0 AND ip_hash IS NOT NULL AND ip_hash != ''";
        $stmt = $db->prepare($sql);
        $stmt->execute([$siteId, $startTime, $endTime]);

        $totalIpCount = 0; $mobileIpCount = 0;
        $ipBatch = []; $mobileIpBatch = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ipHash = $row['ip_hash'];
            $ipBatch[] = $ipHash;
            $totalIpCount++;

            if ((int)$row['is_mobile'] === 1) {
                $mobileIpBatch[] = $ipHash;
                $mobileIpCount++;
            }

            if (count($ipBatch) >= 2000) { $redis->pfAdd($keyIp, $ipBatch); $ipBatch = []; }
            if (count($mobileIpBatch) >= 2000) { $redis->pfAdd($keyMobileIp, $mobileIpBatch); $mobileIpBatch = []; }
        }
        $stmt->closeCursor();

        if (!empty($ipBatch)) $redis->pfAdd($keyIp, $ipBatch);
        if (!empty($mobileIpBatch)) $redis->pfAdd($keyMobileIp, $mobileIpBatch);

        if ($totalIpCount > 0) {
            $redis->expire($keyIp, 86400 * 3);
            $redis->expire($keyMobileIp, 86400 * 3);
            echo " - {$startTime}: 写入 {$totalIpCount} 条 IP\n";
        }
    }
}
echo "\n搞定！前缀已对齐！\n";

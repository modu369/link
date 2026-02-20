#!/usr/bin/env php
<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RedisClient.php';
require_once __DIR__ . '/../src/Tracker.php';
require_once __DIR__ . '/../src/IpResolver.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);
$ipResolver = new IpResolver($config['ipdb']['path'] ?? null);

$options = getopt('', [
    'loop::',
    'sleep::',
    'hours::',
    'worker::',
    'workers::',
]);

$loop = array_key_exists('loop', $options);
$sleepSeconds = max(0, (int) ($options['sleep'] ?? 5));
$hoursBack = max(1, (int) ($options['hours'] ?? 2));
$workerIndex = max(1, (int) ($options['worker'] ?? 1));
$workerCount = max(1, (int) ($options['workers'] ?? 1));

ob_implicit_flush(true);

function logLine(string $message): void
{
    echo $message . PHP_EOL;
    flush();
}

function logError(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    flush();
}

function truncateBucketStart(DateTimeImmutable $time): DateTimeImmutable
{
    return $time->setTime((int) $time->format('H'), 0, 0);
}

function engineCase(string $alias): string
{
    return "CASE
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%baidu.com%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%baiduspider%' THEN '百度'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%google%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%googlebot%' THEN '谷歌'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%bing.com%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%bingbot%' THEN '必应'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%so.com%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%360spider%' THEN '360'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%toutiao.com%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%bytespider%' THEN '头条'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%sogou.com%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%sogouspider%' THEN '搜狗'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%sm.cn%' OR LOWER(COALESCE({$alias}.user_agent,'')) LIKE '%yisouspider%' THEN '神马'
        WHEN LOWER(COALESCE({$alias}.referrer,'')) LIKE '%quark.cn%' THEN '夸克'
        ELSE '其他'
    END";
}

function browserCase(string $alias): string
{
    return "CASE
        WHEN LOWER({$alias}.user_agent) REGEXP 'micromessenger' THEN 'WeChat'
        WHEN LOWER({$alias}.user_agent) REGEXP 'bytedancewebview|aweme' THEN 'Douyin'
        WHEN LOWER({$alias}.user_agent) REGEXP 'baiduboxapp' THEN 'Baidu'
        WHEN LOWER({$alias}.user_agent) REGEXP 'mqqbrowser|qqbrowser' THEN 'QQ'
        WHEN LOWER({$alias}.user_agent) REGEXP 'ucbrowser' THEN 'UC'
        WHEN LOWER({$alias}.user_agent) REGEXP 'quark' THEN 'Quark'
        WHEN LOWER({$alias}.user_agent) REGEXP 'xiaomi|miuibrowser' THEN 'Mi'
        WHEN LOWER({$alias}.user_agent) REGEXP 'huawei' THEN 'Huawei'
        WHEN LOWER({$alias}.user_agent) REGEXP 'vivobrowser' THEN 'Vivo'
        WHEN LOWER({$alias}.user_agent) REGEXP 'heytapbrowser|oppobrowser' THEN 'OPPO'
        WHEN LOWER({$alias}.user_agent) REGEXP 'edg(a|ios)' THEN 'Edge'
        WHEN LOWER({$alias}.user_agent) REGEXP 'chrome|crios' THEN 'Chrome'
        WHEN LOWER({$alias}.user_agent) REGEXP 'firefox|fxios' THEN 'Firefox'
        WHEN LOWER({$alias}.user_agent) REGEXP 'safari' AND LOWER({$alias}.user_agent) NOT REGEXP 'chrome|crios|edg' THEN 'Safari'
        WHEN LOWER({$alias}.user_agent) REGEXP '360se|360ee' THEN '360'
        WHEN LOWER({$alias}.user_agent) REGEXP 'msie|trident' THEN 'IE'
        ELSE '其他浏览器'
    END";
}

function regionLabelCase(string $alias): string
{
    return "CASE
        WHEN COALESCE({$alias}.country_name,'') LIKE '中国%' THEN COALESCE(NULLIF({$alias}.region_name,''), '未知')
        WHEN COALESCE({$alias}.country_name,'') = '' THEN '未知'
        ELSE COALESCE({$alias}.country_name, '未知')
    END";
}

function regionLabel(string $country, string $region): string
{
    if ($country === '') {
        return '未知';
    }
    if (str_starts_with($country, '中国')) {
        return $region !== '' ? $region : '未知';
    }

    return $country;
}

function referrerHostExpr(string $alias): string
{
    return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX({$alias}.referrer, '/', 3), '//', -1), ''), '直接访问')";
}

function processRiskRecoveries(PDO $db, Redis $redis, int $hoursBack, int $batch = 200): void
{
    $hoursBack = max(1, $hoursBack);
    $batch = max(1, $batch);
    $queueKey = 'proxy:risk_recover';
    try {
        $targets = $redis->zRange($queueKey, 0, $batch - 1, true);
    } catch (Throwable $e) {
        return;
    }

    if (empty($targets)) {
        return;
    }

    $since = (new DateTimeImmutable('now'))->modify("-{$hoursBack} hours")->format('Y-m-d H:i:s');
    
    // 提取所有的 ip_hash
    $ipHashes = array_keys($targets);
    
    // 构建批量 IN 的占位符 (?, ?, ?)
    $placeholders = implode(',', array_fill(0, count($ipHashes), '?'));
    
    // 将单一变量 $since 放在数组开头，拼接上所有的 ipHashes 构成绑定参数
    $params = array_merge([$since], $ipHashes);
    
    // 使用 IN (...) 批量更新，极大减少锁竞争次数和通信开销
    $sql = "UPDATE pageviews SET is_proxy_risk = 0 WHERE occurred_at >= ? AND ip_hash IN ($placeholders)";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // 数据库更新成功后，再批量从 Redis 队列中移除
        foreach ($ipHashes as $ipHash) {
            try {
                $redis->zRem($queueKey, (string) $ipHash);
            } catch (Throwable $e) {
                // ignore
            }
        }
    } catch (PDOException $e) {
        // 捕获锁超时或死锁异常，只记录日志不中断进程，等待下一轮 loop 自动重试
        if (function_exists('logError')) {
            logError("[processRiskRecoveries] Batch update failed (Lock timeout?): " . $e->getMessage());
        }
    }
}

processRiskRecoveries($db, $redis, $hoursBack);

function rollupSiteHour(PDO $db, IpResolver $ipResolver, int $siteId, DateTimeImmutable $bucketStart, DateTimeImmutable $bucketEnd): array
{
    $bucketKey = $bucketStart->format('Y-m-d H:i:s');
    $start = $bucketStart->format('Y-m-d H:i:s');
    $end = $bucketEnd->format('Y-m-d H:i:s');
    $dayStart = $bucketStart->setTime(0, 0, 0);
    $dayEnd = $dayStart->modify('+1 day');
    $dayStartKey = $dayStart->format('Y-m-d H:i:s');
    $dayEndKey = $dayEnd->format('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM pageview_rollups WHERE site_id = ? AND bucket_start = ?')
            ->execute([$siteId, $bucketKey]);
        $db->prepare('DELETE FROM pageview_dimension_rollups WHERE site_id = ? AND bucket_start = ?')
            ->execute([$siteId, $bucketKey]);
        $db->prepare('DELETE FROM pageview_page_rollups WHERE site_id = ? AND bucket_start = ?')
            ->execute([$siteId, $bucketKey]);
        $db->prepare('DELETE FROM pageview_entry_rollups WHERE site_id = ? AND bucket_start = ?')
            ->execute([$siteId, $bucketKey]);

        $totalsStmt = $db->prepare(
            "SELECT COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ips
             FROM pageviews
             WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?"
        );
        $totalsStmt->execute([$siteId, $start, $end]);
        $totals = $totalsStmt->fetch() ?: [];

        $sessionStmt = $db->prepare(
            "SELECT COUNT(*) as sessions, SUM(duration_seconds) as duration_sum, SUM(page_count) as page_sum, SUM(bounce) as bounce_count
             FROM (
                SELECT MAX(duration_seconds) as duration_seconds,
                       MAX(page_count) as page_count,
                       CASE WHEN MAX(page_count) <= 1 THEN 1 ELSE 0 END as bounce
                FROM pageviews
                WHERE site_id = ? AND is_proxy_risk = 0 AND session_id IS NOT NULL
                  AND occurred_at >= ? AND occurred_at < ?
                GROUP BY session_id
             ) t"
        );
        $sessionStmt->execute([$siteId, $start, $end]);
        $sessions = $sessionStmt->fetch() ?: [];

        $insertRollup = $db->prepare(
            'INSERT INTO pageview_rollups (site_id, bucket_start, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertRollup->execute([
            $siteId,
            $bucketKey,
            (int) ($totals['views'] ?? 0),
            (int) ($totals['uniques'] ?? 0),
            (int) ($totals['ips'] ?? 0),
            (int) ($sessions['sessions'] ?? 0),
            (int) ($sessions['duration_sum'] ?? 0),
            (int) ($sessions['page_sum'] ?? 0),
            (int) ($sessions['bounce_count'] ?? 0),
        ]);

        $dimensionInserts = [
            'host' => "SELECT LEFT(COALESCE(canonical_host, '未知域名'), 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'host_device' => "SELECT LEFT(CONCAT(COALESCE(canonical_host, '未知域名'), '|', IF(is_mobile = 1, 'mobile', 'desktop')), 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'device' => "SELECT IF(is_mobile = 1, 'mobile', 'desktop') as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'browser' => "SELECT LEFT(" . browserCase('p') . ", 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'referrer_host' => "SELECT dimension_value, pv, uv, ips, sessions, duration_sum, page_sum, bounce_count
                FROM (
                    SELECT LEFT(" . referrerHostExpr('p') . ", 255) as dimension_value,
                        SUM(p.page_count) as pv,
                        SUM(p.is_unique) as uv,
                        COUNT(DISTINCT p.ip_hash) as ips,
                        COUNT(*) as sessions,
                        SUM(p.duration_seconds) as duration_sum,
                        SUM(p.page_count) as page_sum,
                        SUM(CASE WHEN p.page_count <= 1 THEN 1 ELSE 0 END) as bounce_count
                    FROM (
                        SELECT MIN(id) as first_id, session_id
                        FROM pageviews
                        WHERE site_id = ? AND is_proxy_risk = 0 AND session_id IS NOT NULL
                          AND occurred_at >= ? AND occurred_at < ?
                        GROUP BY session_id
                    ) s
                    JOIN pageviews p ON p.id = s.first_id
                    GROUP BY dimension_value
                    ORDER BY ips DESC
                    LIMIT 500
                ) t",
            'search_engine' => "SELECT LEFT(" . engineCase('p') . ", 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'keyword_engine' => "SELECT LEFT(CONCAT(COALESCE(keyword,''), '|', " . engineCase('p') . ", '|', COALESCE(canonical_host, host, s.domain, ''), COALESCE(NULLIF(path,''), '/')), 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p
                JOIN sites s ON s.id = p.site_id
                WHERE p.site_id = ? AND p.is_proxy_risk = 0 AND p.keyword IS NOT NULL AND keyword != '' AND occurred_at >= ? AND occurred_at < ?
                GROUP BY dimension_value",
            'audience' => "SELECT LEFT(CASE WHEN a.first_seen >= ? AND a.first_seen < ? THEN 'new' ELSE 'returning' END, 255) as dimension_value,
                COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT p.ip_hash) as ips
                FROM pageviews p
                LEFT JOIN site_ip_audience a ON a.site_id = p.site_id AND a.ip_hash = p.ip_hash
                WHERE p.site_id = ? AND p.is_proxy_risk = 0 AND p.occurred_at >= ? AND p.occurred_at < ?
                GROUP BY dimension_value",
        ];

        foreach ($dimensionInserts as $dimension => $sql) {
            $useSessionMetrics = $dimension === 'referrer_host';
            $insert = $db->prepare(
                'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 SELECT ?, ?, ?, dimension_value, pv, uv, ips, ' .
                ($useSessionMetrics ? 'sessions, duration_sum, page_sum, bounce_count' : '0, 0, 0, 0') .
                ' FROM (' . $sql . ') t'
            );
            if ($dimension === 'audience') {
                $params = [$dayStartKey, $dayEndKey, $siteId, $start, $end];
            } else {
                $params = [$siteId, $start, $end];
            }
            $insert->execute(array_merge([$siteId, $bucketKey, $dimension], $params));
        }

        $geoStmt = $db->prepare(
            "SELECT ip_address, ip_hash, COUNT(*) as pv, SUM(is_unique) as uv
             FROM pageviews
             WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
               AND ip_address IS NOT NULL AND ip_address != ''
             GROUP BY ip_hash, ip_address"
        );
        $geoStmt->execute([$siteId, $start, $end]);
        $geoRows = $geoStmt->fetchAll();

        $regionAgg = [];
        $countryAgg = [];
        $ispAgg = [];

        foreach ($geoRows as $row) {
            $meta = $ipResolver->resolve($row['ip_address'] ?? '');
            $country = trim($meta['country_name'] ?? '');
            $region = trim($meta['region_name'] ?? '');
            $isp = trim($meta['isp_domain'] ?? '');

            $regionKey = regionLabel($country, $region);
            $countryKey = $country !== '' ? $country : '未知';
            $ispKey = $isp !== '' ? $isp : '未知运营商';

            $pv = (int) ($row['pv'] ?? 0);
            $uv = (int) ($row['uv'] ?? 0);
            $ips = 1;

            $regionAgg[$regionKey]['pv'] = ($regionAgg[$regionKey]['pv'] ?? 0) + $pv;
            $regionAgg[$regionKey]['uv'] = ($regionAgg[$regionKey]['uv'] ?? 0) + $uv;
            $regionAgg[$regionKey]['ips'] = ($regionAgg[$regionKey]['ips'] ?? 0) + $ips;

            $countryAgg[$countryKey]['pv'] = ($countryAgg[$countryKey]['pv'] ?? 0) + $pv;
            $countryAgg[$countryKey]['uv'] = ($countryAgg[$countryKey]['uv'] ?? 0) + $uv;
            $countryAgg[$countryKey]['ips'] = ($countryAgg[$countryKey]['ips'] ?? 0) + $ips;

            $ispAgg[$ispKey]['pv'] = ($ispAgg[$ispKey]['pv'] ?? 0) + $pv;
            $ispAgg[$ispKey]['uv'] = ($ispAgg[$ispKey]['uv'] ?? 0) + $uv;
            $ispAgg[$ispKey]['ips'] = ($ispAgg[$ispKey]['ips'] ?? 0) + $ips;
        }

        $geoInsert = $db->prepare(
            'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)'
        );

        foreach ($regionAgg as $label => $data) {
            $geoInsert->execute([
                $siteId,
                $bucketKey,
                'region',
                mb_substr($label, 0, 255),
                $data['pv'],
                $data['uv'],
                $data['ips'],
            ]);
        }

        foreach ($countryAgg as $label => $data) {
            $geoInsert->execute([
                $siteId,
                $bucketKey,
                'country',
                mb_substr($label, 0, 255),
                $data['pv'],
                $data['uv'],
                $data['ips'],
            ]);
        }

        foreach ($ispAgg as $label => $data) {
            $geoInsert->execute([
                $siteId,
                $bucketKey,
                'isp',
                mb_substr($label, 0, 255),
                $data['pv'],
                $data['uv'],
                $data['ips'],
            ]);
        }

        $pageStmt = $db->prepare(
            "INSERT INTO pageview_page_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             SELECT ?, ?, path, pv, uv, ips, 0, 0, 0, 0
             FROM (
                SELECT LEFT(COALESCE(path,'/'), 512) as path,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                FROM pageviews p
                WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                GROUP BY path
                ORDER BY ips DESC
                LIMIT 500
             ) t"
        );
        $pageStmt->execute([$siteId, $bucketKey, $siteId, $start, $end]);

        $entryStmt = $db->prepare(
            "INSERT INTO pageview_entry_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             SELECT ?, ?, path, pv, uv, ips, session_count, duration_sum, page_sum, bounce_count
             FROM (
                SELECT LEFT(entry.path, 512) as path,
                    COUNT(*) as pv, SUM(entry.is_unique) as uv, COUNT(DISTINCT entry.ip_hash) as ips,
                    COUNT(*) as session_count,
                    SUM(entry.duration_seconds) as duration_sum,
                    SUM(entry.page_count) as page_sum,
                    SUM(CASE WHEN entry.page_count <= 1 THEN 1 ELSE 0 END) as bounce_count
                FROM (
                    SELECT MIN(id) as first_id, session_id
                    FROM pageviews
                    WHERE site_id = ? AND is_proxy_risk = 0 AND session_id IS NOT NULL
                      AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY session_id
                ) s
                JOIN pageviews entry ON entry.id = s.first_id
                GROUP BY entry.path
                ORDER BY ips DESC
                LIMIT 500
             ) t"
        );
        $entryStmt->execute([$siteId, $bucketKey, $siteId, $start, $end]);

        $db->commit();

        return [
            'site_id' => $siteId,
            'bucket' => $bucketKey,
            'pv' => (int) ($totals['views'] ?? 0),
            'uv' => (int) ($totals['uniques'] ?? 0),
            'ips' => (int) ($totals['ips'] ?? 0),
            'sessions' => (int) ($sessions['sessions'] ?? 0),
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        return [
            'site_id' => $siteId,
            'bucket' => $bucketKey,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
    }
}

do {
    $loopStarted = microtime(true);
    $siteStmt = $db->query('SELECT id FROM sites ORDER BY id ASC');
    $siteIds = array_map('intval', $siteStmt->fetchAll(PDO::FETCH_COLUMN));

    $now = new DateTimeImmutable('now');
    $cutoff = $now->modify('-5 minutes');
    $endHour = truncateBucketStart($cutoff)->modify('+1 hour');

    logLine(sprintf(
        "[rollup worker %d/%d] sites=%d window_end=%s",
        $workerIndex,
        $workerCount,
        count($siteIds),
        $endHour->format('Y-m-d H:i:s')
    ));

    if (empty($siteIds)) {
        if (!$loop) {
            break;
        }
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }
        continue;
    }

    $processedBuckets = 0;
    $processedSites = 0;
    $skippedSites = 0;

    foreach ($siteIds as $siteId) {
        if ($workerCount > 1 && ((($siteId - 1) % $workerCount) !== ($workerIndex - 1))) {
            $skippedSites++;
            continue;
        }
        $processedSites++;

        $jobStmt = $db->prepare('SELECT last_rolled_at FROM rollup_jobs WHERE site_id = :site_id');
        $jobStmt->execute([':site_id' => $siteId]);
        $last = $jobStmt->fetchColumn();
        $lastRolled = $last ? new DateTimeImmutable($last) : truncateBucketStart($now->modify("-{$hoursBack} hours"));
        $minStart = truncateBucketStart($endHour->modify("-{$hoursBack} hours"));
        if ($lastRolled > $minStart) {
            $lastRolled = $minStart;
        }

        $current = truncateBucketStart($lastRolled);
        while ($current < $endHour) {
            $bucketStart = $current;
            $bucketEnd = $bucketStart->modify('+1 hour');
            $summary = rollupSiteHour($db, $ipResolver, $siteId, $bucketStart, $bucketEnd);
            if (!empty($summary['error'])) {
                $errorMessage = sprintf(
                    "[rollup worker %d/%d] site=%d bucket=%s error=%s",
                    $workerIndex,
                    $workerCount,
                    $siteId,
                    $summary['bucket'] ?? $bucketStart->format('Y-m-d H:i:s'),
                    $summary['error']
                );
                logLine($errorMessage);
                if (!empty($summary['trace'])) {
                    logError($errorMessage . PHP_EOL . $summary['trace']);
                } else {
                    logError($errorMessage);
                }
            } else {
                logLine(sprintf(
                    "[rollup worker %d/%d] site=%d bucket=%s pv=%d uv=%d ip=%d sessions=%d",
                    $workerIndex,
                    $workerCount,
                    $summary['site_id'],
                    $summary['bucket'],
                    $summary['pv'],
                    $summary['uv'],
                    $summary['ips'],
                    $summary['sessions']
                ));
            }
            $processedBuckets++;
            $current = $bucketEnd;
        }

        $upsert = $db->prepare(
            'INSERT INTO rollup_jobs (site_id, last_rolled_at) VALUES (:site_id, :last)
             ON DUPLICATE KEY UPDATE last_rolled_at = VALUES(last_rolled_at)'
        );
        $upsert->execute([':site_id' => $siteId, ':last' => $endHour->format('Y-m-d H:i:s')]);
    }

    if ($processedBuckets === 0) {
        logLine(sprintf(
            "[rollup worker %d/%d] no buckets processed (sites=%d)",
            $workerIndex,
            $workerCount,
            count($siteIds)
        ));
    }
    $elapsed = microtime(true) - $loopStarted;
    logLine(sprintf(
        "[rollup worker %d/%d] summary sites=%d processed_sites=%d skipped_sites=%d buckets=%d elapsed=%.2fs",
        $workerIndex,
        $workerCount,
        count($siteIds),
        $processedSites,
        $skippedSites,
        $processedBuckets,
        $elapsed
    ));
    if ($workerCount === 1 && count($siteIds) > 1) {
        logLine(sprintf(
            "[rollup worker %d/%d] hint: multiple sites detected; consider increasing --workers for faster coverage",
            $workerIndex,
            $workerCount
        ));
    }

    if (!$loop) {
        break;
    }

    if ($sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
} while (true);

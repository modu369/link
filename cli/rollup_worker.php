#!/usr/bin/env php
<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RedisClient.php';
require_once __DIR__ . '/../src/Tracker.php';
require_once __DIR__ . '/../src/IpResolver.php';

$config = require __DIR__ . '/../config/config.php';

// 【修改点1】服务初始化包裹在 try-catch 中，连接失败立刻让守护进程接管重启
try {
    $db = Database::connection($config['db']);
    
    // 【核心修复 1】将会话降级为读已提交，彻底消灭间隙锁导致的并发交叉死锁
    $db->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
    
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
    $ipResolver = new IpResolver($config['ipdb']['path'] ?? null);
} catch (Throwable $e) {
    echo "[rollup worker] Initialization failed: " . $e->getMessage() . "\n";
    exit(1); 
}

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
$dynamicTtl = max(3600, ($sleepSeconds * 2) + 60);
$tracker->setCacheTtl($dynamicTtl);
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

// ==========================================
// = 生命周期管理初始化 (防止 PHP 内存泄漏) =
// ==========================================
$workerStartTime = time(); // 记录 Worker 启动时间

do {
    $loopStarted = microtime(true);

    // 【修改点2】主循环开头统一探活
    try {
        $db->query("SELECT 1");
        $redis->ping();
    } catch (Throwable $e) {
        logError(sprintf("[rollup worker %d/%d] Fatal: Connection lost (%s). Exiting for restart...", $workerIndex, $workerCount, $e->getMessage()));
        exit(1); 
    }

    // 【修改点3】将整个核心聚合逻辑包裹进异常捕获中
    try {
        processRiskRecoveries($db, $redis, $hoursBack);
        try {
            $cleanedProxies = $tracker->cleanupProxyHistory(10);
            if ($cleanedProxies > 0) {
                logLine(sprintf("[rollup worker %d/%d] 🧹 Purged historical dirty data for %d proxy IPs.", $workerIndex, $workerCount, $cleanedProxies));
            }
        } catch (Throwable $e) {
            logError("[rollup worker] Proxy history cleanup failed: " . $e->getMessage());
        }
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

        if (!empty($siteIds)) {
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
                // 【核心修复 2】新增：记录真实成功的高水位线
                $successfullyRolled = $current; 

                while ($current < $endHour) {
                    $bucketStart = $current;
                    $bucketEnd = $bucketStart->modify('+1 hour');
                    $summary = $tracker->rebuildRollupBucket($siteId, $bucketStart, $bucketEnd);
                    
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
                        
                        // 【核心修复 3】发生致命 SQL 错误，立刻中断该站点的时间推进！防数据丢失。
                        break; 
                    } elseif (isset($summary['status']) && $summary['status'] === 'skipped_due_to_lock') {
                        // 【核心修复 4】静默处理锁竞争，同样中断推进，交由抢到锁的进程推进或者下一轮重试
                        logLine(sprintf(
                            "[rollup worker %d/%d] site=%d bucket=%s skipped (locked by another process)",
                            $workerIndex,
                            $workerCount,
                            $siteId,
                            $bucketStart->format('Y-m-d H:i:s')
                        ));
                        break;
                    } else {
                        // 正常成功的输出，加入 ?? 0 避免 Undefined Warning
                        logLine(sprintf(
                            "[rollup worker %d/%d] site=%d bucket=%s pv=%d uv=%d ip=%d sessions=%d",
                            $workerIndex,
                            $workerCount,
                            $summary['site_id'],
                            $summary['bucket'],
                            $summary['pv'] ?? 0,
                            $summary['uv'] ?? 0,
                            $summary['ips'] ?? 0,
                            $summary['sessions'] ?? 0
                        ));
                    }
                    
                    $processedBuckets++;
                    // 只有成功走完一切，才将水位线前进到这个桶的末尾
                    $successfullyRolled = $bucketEnd;
                    $current = $bucketEnd;
                }

                // 【核心修复 5】只将真正成功跑完的水位线 ($successfullyRolled) 写回数据库
                if ($successfullyRolled > truncateBucketStart($lastRolled)) {
                    $upsert = $db->prepare(
                        'INSERT INTO rollup_jobs (site_id, last_rolled_at) VALUES (:site_id, :last)
                         ON DUPLICATE KEY UPDATE last_rolled_at = VALUES(last_rolled_at)'
                    );
                    $upsert->execute([':site_id' => $siteId, ':last' => $successfullyRolled->format('Y-m-d H:i:s')]);
                }
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

            // ================== 主动预热面板数据 ==================
            $warmupStarted = microtime(true);
            $warmedSites = 0;
            foreach ($siteIds as $siteId) {
                if ($workerCount > 1 && ((($siteId - 1) % $workerCount) !== ($workerIndex - 1))) {
                    continue;
                }
                try {
                    $tracker->warmupDashboardCache($siteId);
                    $warmedSites++;
                } catch (Throwable $e) {
                    logError("[warmup error] site={$siteId}: " . $e->getMessage());
                }
            }

            try {
                $tracker->warmupShareCache($workerIndex, $workerCount);
            } catch (Throwable $e) {
                logError("[warmup error] share_pages: " . $e->getMessage());
            }

            $warmupElapsed = microtime(true) - $warmupStarted;
            logLine(sprintf("[rollup worker %d/%d] active cache warmup finished for %d sites (and mapped shares), elapsed=%.2fs", $workerIndex, $workerCount, $warmedSites, $warmupElapsed));
            // =======================================================
        }
        
        // ==========================================
        // = 生命周期管理：优雅退出，防 PHP 内存溢出 =
        // ==========================================
        // 运行满 1 小时后，主动退出当前循环。由外部守护进程在一秒内重新拉起干净的进程。
        if ($loop && (time() - $workerStartTime) > 3600) {
            logLine(sprintf("[rollup worker %d/%d] Lifecycle limit reached (uptime: %ds). Exiting gracefully to free memory...", $workerIndex, $workerCount, time() - $workerStartTime));
            exit(0);
        }

    } catch (Throwable $e) {
        logError(sprintf("[rollup worker %d/%d] Execution Error: %s\n%s", $workerIndex, $workerCount, $e->getMessage(), $e->getTraceAsString()));
        exit(1); // 遭遇致命异常（如大段的数据库崩溃），直接退出等待接管重启
    }

    if (!$loop) {
        break;
    }

    // 【修改点4】采用动态休眠时间，补齐任务开销
    if ($sleepSeconds > 0) {
        $totalElapsed = microtime(true) - $loopStarted;
        $actualSleepSeconds = $sleepSeconds - $totalElapsed;
        if ($actualSleepSeconds > 0) {
            sleep((int)$actualSleepSeconds);
        }
    }

} while (true);
